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
 * - it covers the top level of "changes" and the attributes, by name: a value
 *   nested inside a free-form array is not seen, because the rule matches the
 *   field, which there is the array;
 * - an exception raised by somebody else's code may carry a value in its own
 *   message, and that message is not the bundle's to rewrite — it travels as the
 *   `previous` of a WriteFailedException, which is why this one does not repeat it.
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
        foreach ($fields as $rule) {
            $field = str_contains($rule, '.') ? substr($rule, (int) strpos($rule, '.') + 1) : $rule;

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
     */
    private function redacts(string $objectType, string $field): bool
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
