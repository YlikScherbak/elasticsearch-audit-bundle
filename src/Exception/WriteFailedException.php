<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * A record could not be written and the failure policy is "throw".
 * With the default "log" policy this is never raised — the failure is logged instead.
 */
final class WriteFailedException extends \RuntimeException implements AuditException
{
    /**
     * @param AuditRecord|null $record null when the failure happened before a record existed
     *                                 (the Doctrine listener could not build one)
     */
    private function __construct(string $message, public readonly ?AuditRecord $record, \Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * This message repeats only what the bundle wrote itself.
     *
     * A declaration mistake is the bundle's own words — a class, a field, a reason —
     * and saying it here is what makes the failure readable where it surfaces. But an
     * exception that wrapped something else (the cluster refusing a document, a
     * listener that failed) carries a message the bundle did not write and cannot
     * redact: it may name the very value just removed from the record, and copying it
     * here would put a secret in every place this exception is logged or shown. Such a
     * cause is named by class only, and `getPrevious()` keeps the full diagnosis one
     * step away for whoever is entitled to it.
     */
    public static function for(?AuditRecord $record, \Throwable $previous): self
    {
        // Only a cause that says so about itself. "It wrapped nothing, therefore we
        // wrote it" was the earlier guess, and it is false for every library that
        // throws directly — an enricher raising RuntimeException("cannot enrich with
        // token $token") has no previous either.
        $cause = $previous instanceof SafeExceptionMessage
            ? $previous->getMessage()
            : $previous::class.' — see the previous exception';

        $message = $record === null
            ? sprintf('An audit record could not be built: %s', $cause)
            : sprintf('Writing the audit record %s#%s (%s) failed: %s', $record->objectType, $record->objectId, $record->event, $cause);

        return new self($message, $record, $previous);
    }
}
