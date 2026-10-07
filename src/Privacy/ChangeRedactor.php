<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Privacy;

use Borsche\ElasticsearchAuditBundle\Exception\RedactionLimitExceeded;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;

/**
 * Replaces the values of named fields at the moment a record leaves the process.
 *
 * An audit log is the one place in an application where every version of every
 * value is kept, on purpose, for years. Some values must not be kept at all —
 * passwords, tokens, card numbers — and some (an email, an address) belong to a
 * person who may later ask for them to be removed. Redaction keeps the *fact*
 * that the field changed, which is what the trail is for, and drops the value.
 *
 * Fields are named plainly (`password`) or per object type (`user.email`). A side
 * that was null or empty stays as it was, so "was not set, now is" remains
 * readable — the placeholder never invents a value where there was none.
 *
 * The writer applies it on the way out — after the enrichers, after a frame has
 * merged its steps, and on the failure path — so a frame still sees the real
 * values and knows the field moved, while nothing the bundle itself writes (the
 * document, RecordCreatedEvent, RecordFailedEvent, WriteFailedException) carries
 * the value.
 *
 * What it does not do, said here because the difference matters more than the
 * feature does:
 *
 * - it runs at write time, so it never reaches records already in the index —
 *   erasing those is a reindex or a delete-by-query, an operational procedure the
 *   bundle deliberately has no button for;
 * - it cannot reach the actor ("source" is a base field, chosen when the record is
 *   built) — a rule naming it is refused rather than silently ignored;
 * - it matches by name, and a name is a name at any depth: a rule sees the fields of
 *   "changes" and the attributes, and also the keys inside whatever structure one of
 *   those holds, because "password" reads as global and a secret one level down is no
 *   less a secret. What it cannot do is name a path — a dot in a rule is an object
 *   type, not a parent key;
 * - an exception raised by somebody else's code may carry a value in its own
 *   message, and that message is not the bundle's to rewrite — it travels as the
 *   `previous` of a WriteFailedException, which is why this one does not repeat it.
 */
final class ChangeRedactor
{
    /**
     * How deep a rule is followed into a value the application built, and how many
     * places it looks on the way.
     *
     * Bounds are needed — this walks data the bundle did not make — and past either of
     * them the record is refused rather than written half-checked. Depth alone was not
     * the whole question: a flat array of a million elements is one level deep and still
     * a walk nobody asked for, on the request's own time, before anything is written.
     *
     * Both are defaults rather than rules: sixteen levels and ten thousand nodes inside
     * one record is not a shape any of this was written for, and a domain that disagrees
     * says so in configuration (redact.max_depth, redact.max_nodes) rather than by
     * losing records or by walking forever.
     */
    public const DEFAULT_MAX_DEPTH = 16;
    public const DEFAULT_MAX_NODES = 10_000;

    /**
     * How many times in a row jsonSerialize() is followed to the object it answers with.
     *
     * Not a property of JSON: json_encode counts a hop as no level at all, and a chain
     * of a hundred thousand wrappers encodes. An endless one — each answering with a new
     * wrapper, so no object is ever seen twice — hung the process on encoding, and
     * nothing but a count stops it. Real wrappers are one to three deep; a thousand is
     * the one bound this places on a value that json_encode would have written.
     */
    public const MAX_HOPS = 1000;

    /**
     * How deeply json_encode nests before it refuses, and where each kind of value
     * stands in the document: the document is one level and `changes` a second, so a
     * value filed under a field opens the third, a side of a Change the fourth (its pair
     * is the third), and an attribute the second.
     */
    private const JSON_DEPTH = 512;
    private const IN_CHANGES = 3;
    private const IN_A_CHANGE = 4;
    private const IN_ATTRIBUTES = 2;

    /**
     * The flags elastic/transport encodes a body with, so a value read here as JSON is
     * the value the client would have written.
     */
    private const JSON_FLAGS = \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR;

    /** null: no bound but JSON's own. */
    private readonly ?int $maxDepth;
    private readonly ?int $maxNodes;

    /**
     * What is left of this record's node budget. Reset by redact(), spent by scrub().
     */
    private int $budget = 0;

    /**
     * Objects on the way down from the value being walked: a circle is an object met
     * again inside itself, not one met twice side by side.
     *
     * @var \SplObjectStorage<object, null>
     */
    private \SplObjectStorage $path;

    /**
     * What each object answered while once() runs, so that one write asks it once —
     * across both passes, a listener's replacement and the failure path. Outside once()
     * nothing is kept: an object answering differently in the next write is the next
     * write's value.
     *
     * @var \SplObjectStorage<object, mixed>|null
     */
    private ?\SplObjectStorage $answered = null;

    /**
     * Rules that name nothing still leave the redactor something to do: every record is
     * turned into plain values on its way out, so with no rule configured the limits
     * default to JSON's own rather than to the ones a rule is followed with.
     *
     * @param list<string> $fields      field names, optionally scoped as "objectType.field"
     * @param string       $placeholder what the value is replaced with
     * @param int|null     $maxDepth    null: 16 with a rule, JSON's own without one
     * @param int|null     $maxNodes    null: 10 000 with a rule, none without one
     */
    public function __construct(
        private readonly array $fields,
        private readonly string $placeholder = '***',
        ?int $maxDepth = null,
        ?int $maxNodes = null,
    ) {
        if (($maxDepth !== null && $maxDepth < 1) || ($maxNodes !== null && $maxNodes < 1)) {
            throw new \InvalidArgumentException(sprintf('Redaction needs room to look: max_depth and max_nodes are at least 1, %s and %s given.', var_export($maxDepth, true), var_export($maxNodes, true)));
        }

        // Each on its own: an explicit depth does not switch the default node budget off.
        $this->maxDepth = $maxDepth ?? ($fields === [] ? null : self::DEFAULT_MAX_DEPTH);
        $this->maxNodes = $maxNodes ?? ($fields === [] ? null : self::DEFAULT_MAX_NODES);
        $this->path = new \SplObjectStorage();

        foreach ($fields as $rule) {
            $field = str_contains($rule, '.') ? substr($rule, (int) strpos($rule, '.') + 1) : $rule;

            // A rule with nothing to match on: an empty entry in a config list, or a
            // scope somebody meant to finish ("user."). It would be accepted and then
            // match nothing, ever, which is the same silence a base-field rule was
            // refused for — and likelier to happen by accident.
            if (trim($field) === '' || (str_contains($rule, '.') && trim(substr($rule, 0, (int) strpos($rule, '.'))) === '')) {
                throw new \InvalidArgumentException(sprintf('"%s" names no field to redact, so it would never match anything. A rule is a field ("password") or a field scoped to an object type ("user.password").', $rule));
            }

            // And a rule that is merely padded. The check above trims before looking,
            // while the matcher compares the rule exactly as written — so " password "
            // was accepted, matched nothing, and the value it existed to remove was
            // written in full. Trimming it here quietly would be the other way to hide
            // the mistake; a privacy rule is better read back as refused.
            if ($rule !== trim($rule) || $field !== trim($field)) {
                throw new \InvalidArgumentException(sprintf('"%s" has whitespace around it, and a rule is matched exactly as written — so it would never match the field it names. Write it without the padding.', $rule));
            }

            // A rule naming a base field could never do anything: redaction covers the
            // fields of "changes" and the attributes, and these are neither. Accepting
            // one and quietly ignoring it is how somebody believes an identifier is
            // being redacted while every record carries it.
            if (\in_array($field, AuditRecord::reservedFields(), true)) {
                throw new \InvalidArgumentException(sprintf('"%s" is a base field of every audit record and cannot be redacted by a rule.%s', $field, match ($field) {
                    'source' => ' The actor is chosen by an ActorResolverInterface: return an internal id there instead of an identifier you must not keep.',
                    'objectId' => ' The object id is how history is addressed: pass an internal id to the writer instead of a personal identifier.',
                    'changes' => ' Name the fields inside it instead — "password", or "user.password" for one object type.',
                    default => ' It is what makes a record findable, and a record nobody can identify is not an audit trail.',
                }));
            }
        }
    }

    /**
     * No rule, and the plain values every record is turned into on its way out — what
     * the writer uses when nothing is configured to be redacted.
     */
    public static function materialisingOnly(): self
    {
        return new self([]);
    }

    /**
     * Runs an operation in which every object is asked for its JSON once.
     *
     * @internal the writer's, around one write
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function once(callable $operation): mixed
    {
        if ($this->answered !== null) {
            return $operation();
        }

        $this->answered = new \SplObjectStorage();

        try {
            return $operation();
        } finally {
            $this->answered = null;
        }
    }

    /**
     * The record with the named fields' values replaced and every value in it made plain:
     * scalars, arrays and stdClass objects built here, never an object the application
     * made. The same instance when there is nothing to replace or to build.
     */
    public function redact(AuditRecord $record): AuditRecord
    {
        // One budget for the whole record rather than one per value: what matters is how
        // much work a single write can ask for, and a record with a thousand small
        // structures costs the same as one with a single large one.
        if ($this->maxNodes !== null) {
            $this->budget = $this->maxNodes;
        }
        $this->path = new \SplObjectStorage();

        // The record's own keys are places to look like any other. Counting only what
        // was nested left the widest records free: fifty thousand change fields, or a
        // pair with fifty thousand keys beside its two sides, walked in full under a
        // budget of ten. The values were masked correctly - this is the bound on the
        // work, which is the half that runs on the request.
        $this->spend(\count($record->changes) + \count($record->attributes));

        $changes = [];
        $touched = false;

        foreach ($record->changes as $field => $change) {
            if ($this->redacts($record->objectType, (string) $field)) {
                $changes[$field] = $this->redactValue($change);
                $touched = true;

                continue;
            }

            // And inside it. A change can hold a structure the application built —
            // `profile` => `['name' => …, 'password' => …]` — where the rule names a key
            // one level down. The rule "password" reads as global, which is exactly how
            // somebody writes it, and it used to see only the field a change is filed
            // under: here that field is "profile", and the secret went to the index in
            // full.
            $scrubbed = $this->scrubChange($record->objectType, $change);

            if ($scrubbed !== $change) {
                $touched = true;
            }

            $changes[$field] = $scrubbed;
        }

        $record = $touched ? $record->withChanges($changes) : $record;

        // Attributes are the indexed half of a record, so leaving them out of this would
        // protect what cannot be searched and expose what can. They are dropped rather
        // than masked: see AuditRecord::withoutAttributes().
        $secret = array_values(array_filter(
            array_keys($record->attributes),
            fn (string $name): bool => $this->redacts($record->objectType, $name),
        ));

        $record = $secret === [] ? $record : $record->withoutAttributes(...$secret);

        // The same reach into an attribute that survived: the attribute itself is not
        // named by a rule, and something inside it is.
        $inside = [];

        foreach ($record->attributes as $name => $value) {
            $scrubbed = $this->scrub($record->objectType, $value, self::IN_ATTRIBUTES);

            if ($scrubbed !== $value) {
                $inside[$name] = $scrubbed;
            }
        }

        return $inside === [] ? $record : $record->withAttributes($inside);
    }

    /**
     * A change's value with the named keys inside it masked, whichever shape the change
     * has: a Change object, the pair it serialises to, or a free-form value.
     */
    private function scrubChange(string $objectType, mixed $change): mixed
    {
        if ($change instanceof Change) {
            $this->spend(2); // the two sides, like the two keys of the pair below

            $old = $this->scrub($objectType, $change->old, self::IN_A_CHANGE);
            $new = $this->scrub($objectType, $change->new, self::IN_A_CHANGE);

            return $old === $change->old && $new === $change->new ? $change : new Change($old, $new);
        }

        if (Change::isPair($change)) {
            return $this->scrubPair($objectType, $change);
        }

        return $this->scrub($objectType, $change, self::IN_CHANGES);
    }

    /**
     * A change stored as its pair, with every key of it read.
     *
     * `+ $change` kept whatever else the caller had put beside old and new - `changes`
     * takes mixed, so a manual record or an enricher may build the pair by hand - and
     * those keys went to the index unread: a rule naming one of them did not apply, and
     * a secret nested inside one was never looked at. The two sides keep their meaning,
     * because a rule named "old" or "new" would otherwise blank every change in the
     * log; everything else is treated exactly as it would be anywhere else in a record.
     *
     * The parameter is typed loosely on purpose. A pair is not a sealed shape, and an
     * analyser told that it is walks this loop and concludes that no other key can
     * exist - which is the belief that let those keys through in the first place.
     *
     * @param array<array-key, mixed> $pair
     *
     * @return array<array-key, mixed>
     */
    private function scrubPair(string $objectType, array $pair): array
    {
        $this->spend(\count($pair));

        $out = [];

        foreach ($pair as $key => $value) {
            if ($key === 'old' || $key === 'new') {
                $out[$key] = $this->scrub($objectType, $value, self::IN_A_CHANGE);

                continue;
            }

            $out[$key] = \is_string($key) && $this->redacts($objectType, $key)
                ? $this->mask($value)
                : $this->scrub($objectType, $value, self::IN_A_CHANGE);
        }

        return $out;
    }

    /**
     * Walks a value the application built, masks every key a rule names however deep it
     * sits, and hands back plain values only.
     *
     * Plain, because what was read has to be what travels. This used to read an object -
     * what jsonSerialize() answered, or its public properties - and hand the object on,
     * and whatever came next asked it again: the client encoding the body called
     * jsonSerialize() a second time and wrote what it said then, and Messenger's
     * PhpSerializer did not ask at all, putting every property in the queue, the private
     * ones included. Now an object leaves as a stdClass built here from what it was read
     * as, and nothing the application made goes past this.
     *
     * Where the value stands - $base, its level in the document - also decides what a
     * date becomes: inside `changes`, which is stored unindexed, the form a Change's sides
     * have always had; in an attribute, indexed by somebody's mapping, what json_encode
     * made of it before. An enum is its value, or a pure one its name, wherever it is.
     *
     * The depth is bounded because this walks data the bundle did not make: an audit
     * record can hold whatever an enricher or a caller put in it, and a privacy pass is
     * not the place to discover how deep that goes. With a rule the bound is sixteen
     * levels unless configured; without one it is JSON's own, so that nothing json_encode
     * would have written is refused here.
     */
    private function scrub(string $objectType, mixed $value, int $base, int $depth = 0): mixed
    {
        // What a wrapper answers with is what is walked, here and not in a call of its own:
        // a walk that came back into this method with the wrapper again would recurse until
        // the stack was gone, and a process that dies is not a refusal anybody can read.
        $chain = null;

        if ($value instanceof \JsonSerializable && !$value instanceof \DateTimeInterface) {
            [$value, $chain] = $this->unwrapped($value);
        }

        if (\is_resource($value) || get_debug_type($value) === 'resource (closed)') {
            // A stream - a blob read from the database, a file handle - is not a value an
            // index can hold, and json_encode refuses it anyway. Said here, by name,
            // before it reaches a queue that would serialise it as a number.
            throw RedactionLimitExceeded::aResource();
        }

        if ($value instanceof \UnitEnum) {
            return Change::plain($value);
        }

        if ($value instanceof \DateTimeInterface) {
            if ($base !== self::IN_ATTRIBUTES) {
                return Change::plain($value);
            }

            // An attribute keeps the form it had, so a mapping built for it still holds -
            // and the form is read from json_encode itself rather than rebuilt by hand:
            // which properties a date shows, and in what order, is json_encode's to say.
            // What comes back is plain, and a public property of it is looked at below
            // like any other.
            $value = $this->asJson($value);
        }

        if (!\is_array($value) && !\is_object($value)) {
            return $value; // a scalar or null: nothing a rule could name
        }

        $isObject = \is_object($value);

        if ($isObject && $this->path->contains($value)) {
            // Fail closed: a value that leads back into itself cannot be seen to the
            // bottom, so nothing can promise that what a rule names is not in it.
            throw RedactionLimitExceeded::goingInCircles($this->fields !== []);
        }

        // An object as json_encode reads it: its public properties.
        $inside = $isObject ? get_object_vars($value) : $value;

        if ($this->maxDepth !== null && $depth >= $this->maxDepth) {
            // Fail closed. Leaving the rest of the structure alone was the DoS-safe
            // choice for a data transformer and the wrong one for this: a rule that reads
            // as "this name, anywhere" would stop applying at a depth nobody thinks
            // about, and the value it exists to remove would be written in full. The
            // record does not go out; the writer's failure policy says so out loud.
            throw RedactionLimitExceeded::deeperThan($this->maxDepth);
        }

        if ($base + $depth > self::JSON_DEPTH) {
            // What json_encode would refuse on the way to the cluster, refused here by
            // name instead: the record was lost either way, and this way somebody is told
            // which value it was.
            throw RedactionLimitExceeded::deeperThanJson(self::JSON_DEPTH);
        }

        // Spent per place to look rather than per value visited: what costs the request
        // is the walk itself, and a flat array of a hundred thousand entries is one
        // value and a hundred thousand places.
        $this->spend(\count($inside));

        $out = [];

        if ($isObject) {
            $this->path->attach($value);
        }

        // The wrappers that answered with this stay on the path while it is walked: an
        // answer holding one of them again is a circle.
        foreach ($chain ?? [] as $hop) {
            $this->path->attach($hop);
        }

        foreach ($inside as $key => $item) {
            $out[$key] = \is_string($key) && $this->redacts($objectType, $key)
                ? $this->mask($item)
                : $this->scrub($objectType, $item, $base, $depth + 1);
        }

        // Not in a finally: a walk that throws is the whole record's refusal, and redact()
        // starts the next record with a path of its own.
        if ($isObject) {
            $this->path->detach($value);
        }

        foreach ($chain ?? [] as $hop) {
            $this->path->detach($hop);
        }

        // An object is always built anew, whatever it holds: handing the application's own on
        // is what this walk exists not to do. It goes on as an object, so `{}` stays `{}` and
        // keys that look like numbers stay keys - json_encode writes a stdClass the way it
        // wrote the object. An array is rebuilt with the same values, and an array with
        // nothing to remove compares equal to the one that came in.
        return $isObject ? (object) $out : $out;
    }

    /**
     * A JsonSerializable followed to what it answers with, hop by hop: the answer, and the
     * wrappers that gave it.
     *
     * The hops are counted - one that builds a new wrapper every time is stopped by the count -
     * and a wrapper met again along the chain, or on the way down to it, is a circle.
     *
     * Kept as objects rather than as spl_object_id(), and that is the whole of it: a
     * wrapper that builds the next one while being serialised is freed the moment the
     * walk moves on, PHP hands its id straight to the object built next, and a finite
     * chain was refused as a circle - which, this being fail-closed, meant the record was
     * not written at all.
     *
     * @return array{mixed, \SplObjectStorage<object, null>}
     */
    private function unwrapped(\JsonSerializable $value): array
    {
        /** @var \SplObjectStorage<object, null> $chain */
        $chain = new \SplObjectStorage();
        $answer = $value;

        while ($answer instanceof \JsonSerializable && !$answer instanceof \DateTimeInterface) {
            if ($this->path->contains($answer) || $chain->contains($answer)) {
                throw RedactionLimitExceeded::goingInCircles($this->fields !== []);
            }

            if (\count($chain) >= self::MAX_HOPS) {
                throw RedactionLimitExceeded::pastHops(self::MAX_HOPS);
            }

            $chain->attach($answer);
            $answer = $this->answerOf($answer);

            if (\is_object($answer)) {
                // A wrapper that serialises to another object is followed rather than
                // trusted - and the hop costs a node, so a long chain runs out of budget
                // like anything else does.
                $this->spend(1);
            }
        }

        return [$answer, $chain];
    }

    private function answerOf(\JsonSerializable $value): mixed
    {
        if ($this->answered !== null && $this->answered->contains($value)) {
            return $this->answered[$value];
        }

        $answer = $value->jsonSerialize();
        $this->answered?->attach($value, $answer);

        return $answer;
    }

    /**
     * What json_encode makes of a date, as plain values. The flags are the client's, so
     * this is what it would have written.
     */
    private function asJson(\DateTimeInterface $date): mixed
    {
        if ($this->answered !== null && $this->answered->contains($date)) {
            return $this->answered[$date];
        }

        try {
            $json = json_decode(json_encode($date, self::JSON_FLAGS), false, self::JSON_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw RedactionLimitExceeded::notEncodable();
        }

        $this->answered?->attach($date, $json);

        return $json;
    }

    /**
     * Takes places to look out of this record's budget, and refuses the record when it
     * runs out.
     *
     * Every key this class reads goes through here - the record's own change fields and
     * attributes, the keys of a pair, and everything nested below them - because the
     * budget is a bound on the walk, and a walk is a walk wherever it starts.
     */
    private function spend(int $places): void
    {
        if ($this->maxNodes === null) {
            return; // no budget: no rule, and none configured
        }

        $this->budget -= $places;

        if ($this->budget < 0) {
            throw RedactionLimitExceeded::pastNodes($this->maxNodes);
        }
    }

    /**
     * The grammar, in one place, because an ambiguous one is not something to freeze:
     *
     * - a rule **without** a dot ("password", "lines") names a field for every object
     *   type;
     * - a rule **with** a dot ("user.email", "shipment.lines") is scoped: the part
     *   before the first dot is the object type it applies to, the rest is the field.
     *   It matches nothing on any other type — "shipment.lines" is a shipment's lines,
     *   not any path on an order that happens to begin with those words.
     *
     * A field name then matches its own path exactly, anything reached **through** it
     * ("lines" covers the membership key "lines.42" and "lines.42.quantity"), and the
     * last segment of a path ("password" covers "lines.42.password": a secret is no
     * less a secret for sitting one level down). Mind that element changes are recorded
     * on the owner, so a scoped rule names the owner's type — "shipment.price" covers
     * "lines.42.price" on a shipment's record.
     *
     * Public so that a test can ask instead of guessing: a redacted value is stored as
     * the placeholder, and comparing against "***" cannot tell a rule that worked from
     * an application that really wrote three asterisks. {@see \Borsche\ElasticsearchAuditBundle\Test\AuditCollector::redacts()}
     */
    public function redacts(string $objectType, string $field): bool
    {
        foreach ($this->fields as $rule) {
            $scope = strpos($rule, '.');

            if ($scope !== false) {
                if (substr($rule, 0, $scope) !== $objectType) {
                    continue; // a rule for another object type
                }

                $rule = substr($rule, $scope + 1);
            }

            if ($rule === '') {
                continue;
            }

            if ($field === $rule || str_starts_with($field, $rule.'.')) {
                return true;
            }

            $last = strrchr($field, '.');

            if ($last !== false && $last !== '.' && substr($last, 1) === $rule) {
                return true;
            }
        }

        return false;
    }

    private function redactValue(mixed $change): mixed
    {
        if ($change instanceof Change) {
            return new Change($this->mask($change->old), $this->mask($change->new));
        }

        if (Change::isPair($change)) {
            return new Change($this->mask($change['old']), $this->mask($change['new']));
        }

        return $this->mask($change);
    }

    /**
     * Nothing stays nothing: a field that was empty and now is not says so without
     * saying what it now holds. false and 0 are values, and are hidden like any other.
     */
    private function mask(mixed $value): mixed
    {
        return $value === null || $value === '' || $value === [] ? $value : $this->placeholder;
    }
}
