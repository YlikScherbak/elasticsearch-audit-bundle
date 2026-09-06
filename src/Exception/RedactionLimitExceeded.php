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
 * Its message names a number of levels and nothing else, so it is safe to repeat.
 */
final class RedactionLimitExceeded extends \RuntimeException implements AuditException, SafeExceptionMessage
{
    private function __construct(string $message)
    {
        parent::__construct($message);
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
