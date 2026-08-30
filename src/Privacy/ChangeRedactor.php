<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Privacy;

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
 * values and knows the field moved, while nothing that leaves the writer (the
 * document, RecordCreatedEvent, RecordFailedEvent, WriteFailedException) carries
 * the value. Only the top-level fields of "changes" are covered: a value hidden
 * inside a free-form array, or an attribute, has to be kept out by the code that
 * puts it there.
 */
final class ChangeRedactor
{
    /**
     * @param list<string> $fields      field names, optionally scoped as "objectType.field"
     * @param string       $placeholder what the value is replaced with
     */
    public function __construct(
        private readonly array $fields,
        private readonly string $placeholder = '***',
    ) {
    }

    /**
     * The record with the named fields' values replaced; the same instance when there
     * is nothing to replace.
     */
    public function redact(AuditRecord $record): AuditRecord
    {
        $changes = [];
        $touched = false;

        foreach ($record->changes as $field => $change) {
            if ($this->redacts($record->objectType, (string) $field)) {
                $changes[$field] = $this->redactValue($change);
                $touched = true;

                continue;
            }

            $changes[$field] = $change;
        }

        $record = $touched ? $record->withChanges($changes) : $record;

        // Attributes are the indexed half of a record, so leaving them out of this would
        // protect what cannot be searched and expose what can. They are dropped rather
        // than masked: see AuditRecord::withoutAttributes().
        $secret = array_values(array_filter(
            array_keys($record->attributes),
            fn (string $name): bool => $this->redacts($record->objectType, $name),
        ));

        return $secret === [] ? $record : $record->withoutAttributes(...$secret);
    }

    private function redacts(string $objectType, string $field): bool
    {
        if (\in_array($field, $this->fields, true) || \in_array($objectType.'.'.$field, $this->fields, true)) {
            return true;
        }

        // A change inside a tracked collection element is named for the path that reached
        // it — "lines.42.password". The rule names a field, not a path, and a secret is
        // no less a secret for sitting one level down.
        $last = strrchr($field, '.');

        return $last !== false && $last !== '.' && $this->redacts($objectType, substr($last, 1));
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
