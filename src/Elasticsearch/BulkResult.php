<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;

/**
 * What came back from one _bulk request. Elasticsearch answers 200 for the request
 * as a whole and reports each document separately, so a batch can be partly
 * written: the caller gets the positions that failed and why, and decides per
 * record — which is what the failure policy does.
 */
final class BulkResult
{
    /**
     * Statuses below 500 that still mean "not now" rather than "not ever": a full write
     * queue (429), and a missing index (404), which with rollover and the recommended
     * auto_create_index guard is an index mid-rotation, back a moment later. Everything
     * from 500 up is transient too and needs no list — see hasTransientFailures().
     */
    public const TRANSIENT = [404, 429];

    /**
     * @param int                                                    $attempted how many items were sent
     * @param array<int, array{status: int, reason: string}>          $failures  keyed by the item's position in the batch
     */
    public function __construct(
        public readonly int $attempted,
        public readonly array $failures = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(0);
    }

    /**
     * Everything went through — or nothing was sent.
     */
    public static function allSucceeded(int $attempted): self
    {
        return new self($attempted);
    }

    /**
     * Reads Elasticsearch's _bulk response: one entry per item, in order, each under
     * its action name ("index" here), with an "error" object when it failed.
     *
     * @param array<string, mixed> $response
     * @param list<string>         $ids      the document ids that were sent, in the order they
     *                                       were sent. Given them, each answer is checked against
     *                                       the document it claims to be about; left empty, only
     *                                       the count and the shape are. Everything downstream —
     *                                       which record failed, which one to retry, which index
     *                                       to forget — is keyed by position, and position is the
     *                                       only thing in this response that is not stated but
     *                                       assumed
     */
    public static function fromResponse(array $response, int $attempted, array $ids = []): self
    {
        $items = $response['items'] ?? null;

        // One entry per item, in the order they were sent. Anything else — a truncated
        // body, an answer belonging to another request — leaves no way to tell which
        // documents were written, and counting the missing ones as written is the one
        // answer an audit trail must not give.
        if (!\is_array($items) || \count($items) !== $attempted) {
            throw TransportUnavailableException::saying(sprintf(
                'Elasticsearch answered a bulk request of %d document(s) with %d item(s), expected %d.',
                $attempted,
                \is_array($items) ? \count($items) : 0,
                $attempted,
            ));
        }

        $failures = [];

        foreach (array_values($items) as $position => $item) {
            // The action this bundle sent, by name. reset() took whatever the first key
            // held, so an item shaped like {"something_else": {"status": 201}} read as a
            // written document — and this class exists to refuse an answer it cannot
            // account for, which has to include the ones that merely look right.
            $action = \is_array($item) && \count($item) === 1 ? ($item['index'] ?? null) : null;

            // A position nobody can read is not a failed document — it is an answer
            // that cannot be trusted at all, and the difference decides what happens
            // next: a failure is classified (and an unclassifiable one was being
            // classified as permanent, so the batch went to the failure transport),
            // while an unreadable answer means the whole response is untrustworthy and
            // the batch has to be sent again. Which is safe: every document carries
            // its own id and overwrites itself.
            if (!\is_array($action) || !is_numeric($action['status'] ?? null)) {
                throw TransportUnavailableException::saying(sprintf(
                    'Elasticsearch answered position %d of a bulk request with something that could not be read as a result, so whether those documents were written is unknown.',
                    $position,
                ));
            }

            // And the answer is about the document that was sent at this position. The
            // order is Elasticsearch's promise, not an observation, and every decision
            // made from here on — which record the failure policy sees, which one is
            // retried, which index is forgotten — reads the batch by position. An answer
            // naming a different document cannot be mapped back at all, so the batch is
            // re-sent whole rather than reported wrongly; that is safe, because every
            // document carries its id and overwrites itself.
            //
            // Only when it names one. An answer that states no id says nothing about the
            // order either way, and turning "did not mention it" into a permanent write
            // failure would be a new way for the trail to go silent, against a risk this
            // check exists to catch rather than to invent.
            $answeredAbout = \is_string($action['_id'] ?? null) && $action['_id'] !== '' ? $action['_id'] : null;

            if ($ids !== [] && $answeredAbout !== null && $answeredAbout !== ($ids[$position] ?? null)) {
                throw TransportUnavailableException::saying(sprintf(
                    'Elasticsearch answered position %d of a bulk request with a result for document "%s", where "%s" was sent — the answer cannot be matched to the documents it is about.',
                    $position,
                    $answeredAbout,
                    $ids[$position] ?? '',
                ));
            }

            $status = (int) $action['status'];

            if ($status >= 200 && $status < 300) {
                continue;
            }

            $error = \is_array($action['error'] ?? null) ? $action['error'] : [];

            // Described from what the answer states — the error type, and the field name
            // lifted out of the wording — and never from the wording itself. A refused
            // document is quoted in that wording, and this reason travels: into the
            // summary the Messenger handler raises, and from there into the failure
            // transport, which keeps it until somebody removes the message. Cutting the
            // one phrase 8 and 9 happen to use held only for those wordings; the single
            // write path stopped relying on that, and this one was still the way around it.
            $failures[$position] = [
                'status' => $status,
                'reason' => DocumentRefusal::describe($error['type'] ?? null, $error['reason'] ?? null) ?? sprintf('rejected with status %d, and the answer named no error type anyone can read', $status),
            ];
        }

        return new self($attempted, $failures);
    }

    public function succeeded(): int
    {
        return $this->attempted - \count($this->failures);
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    public function failed(int $position): bool
    {
        return isset($this->failures[$position]);
    }

    /**
     * Whether any of the refusals was the cluster asking for that document again rather
     * than refusing it: anything in TRANSIENT — a full write queue (429), an index
     * mid-rollover (404) — or any 5xx, which needs no list since every server error is
     * the same answer.
     *
     * A batch holding one of these has to be sent again as a whole. Re-sending what was
     * already written costs nothing — every document travels with its id and overwrites
     * itself — while dropping a record because the cluster was busy costs the trail the
     * hour it most needed to describe.
     */
    public function hasTransientFailures(): bool
    {
        foreach ($this->failures as $failure) {
            // Every server error, not a list of the ones seen so far: the single-write
            // path already treats any 5xx as "not now", and the same refusal must not
            // mean two different things depending on how many records a flush produced.
            if (\in_array($failure['status'], self::TRANSIENT, true) || $failure['status'] >= 500) {
                return true;
            }
        }

        return false;
    }
}
