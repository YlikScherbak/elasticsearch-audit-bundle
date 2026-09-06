<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

/**
 * An enricher that says which object types it is for.
 *
 * Without this every enricher is assumed to be about every record, which is what
 * `supports()` is there to narrow — at write time, one record at a time. It is not
 * something a command can ask: `audit:index:create` has no records, so it folded every
 * enricher's fields into every index. An application routing `auth` to its own index
 * then got `orderCountry` mapped there, `audit:check` reported the field missing on
 * indices it can never appear in, and `audit:index:sync` obligingly added it — a
 * mapping that stops describing what is in the index, and a check that cannot be green
 * without making it worse.
 *
 *     final class OrderAttributesEnricher implements ScopedEnricherInterface
 *     {
 *         public function objectTypes(): array
 *         {
 *             return ['order'];
 *         }
 *
 *         public function supports(AuditRecord $record): bool
 *         {
 *             return $record->changes !== [];  // the type is already answered
 *         }
 *     }
 *
 * The declaration is honoured everywhere, not only by the commands: the writer skips
 * an enricher whose types do not include the record's, before `supports()` is asked.
 * Two answers to the same question could otherwise disagree — a field written into an
 * index whose mapping was told the field would never be there, which under
 * `dynamic: false` means the value is stored and silently unsearchable.
 */
interface ScopedEnricherInterface extends AuditEnricherInterface
{
    /**
     * The object types this enricher takes part in. `[]` means every type, which is
     * what an enricher that does not implement this interface says.
     *
     * @return list<string>
     */
    public function objectTypes(): array;
}
