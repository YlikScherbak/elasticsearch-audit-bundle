<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * A record carried a value nested deeper than redaction follows, so nothing can say
 * whether what a rule names is somewhere inside it.
 *
 * The bound exists because this walks data the bundle did not make. What happens at
 * the bound is the decision: leaving the rest of the structure alone is the safe answer
 * for a data transformer and the wrong one here, because a rule that reads as "this
 * name, anywhere" would quietly stop applying at a depth nobody thinks about. The
 * record is refused instead and the failure policy reports it — a gap in the history
 * that somebody is told about, rather than a value nobody meant to keep.
 *
 * The same refusal covers what makes a value impossible to write as it was read - a
 * circle, a chain of wrappers that does not end, a nesting past what JSON encodes, a
 * resource - with or without a rule, since every record is made plain on its way out.
 *
 * Its message names a number and nothing of the value, so it is safe to repeat.
 */
final class RedactionLimitExceeded extends \RuntimeException implements AuditException, SafeExceptionMessage
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param bool $redacting whether a rule was being followed, or the value only being
     *                        made plain - said differently, because without a rule there
     *                        is no redaction to blame
     */
    public static function goingInCircles(bool $redacting = true): self
    {
        return new self($redacting
            ? 'An audited value leads back into itself, so redaction cannot see the bottom of it and nothing can promise that what a rule names is not somewhere inside. The record was not written. This is a value the application built: an object whose jsonSerialize() answers with itself, or two that answer with each other.'
            : 'An audited value leads back into itself, so it could not be serialised: json_encode would never finish it. The record was not written. This is a value the application built: an object that holds itself, or whose jsonSerialize() answers with itself or with another that answers back.');
    }

    public static function pastHops(int $hops): self
    {
        return new self(sprintf('An audited value answered jsonSerialize() %d times in a row with another object, and it is followed no further: a chain that long is a wrapper building wrappers, and json_encode would never finish it. The record was not written.', $hops));
    }

    public static function deeperThanJson(int $levels): self
    {
        return new self(sprintf('An audited value is nested so deep that the document would pass %d levels, which is as deep as json_encode goes - the cluster would never receive it. The record was not written. Flatten what it carries.', $levels));
    }

    public static function aResource(): self
    {
        return new self('An audited value is a resource - a stream, a file handle - which an index cannot hold and a queue would store as a number. The record was not written. Record what describes it instead: its size, a checksum, the identifier of the file.');
    }

    public static function notEncodable(): self
    {
        return new self('An audited date could not be serialised to JSON, so the record could not be written as the cluster would have received it. The record was not written.');
    }

    public static function pastNodes(int $nodes): self
    {
        return new self(sprintf('An audited value has more than %d places to look inside, and redaction stops there — so nothing can promise that what a rule names is not in the rest of it. The record was not written. Record less in one go, or raise redact.max_nodes if this shape is what the application really keeps.', $nodes));
    }

    public static function deeperThan(int $levels): self
    {
        return new self(sprintf('An audited value is nested more than %d levels deep, and redaction stops looking there — so nothing can promise that what a rule names is not further down. The record was not written. Flatten what it carries, or redact that value before it reaches the writer.', $levels));
    }
}
