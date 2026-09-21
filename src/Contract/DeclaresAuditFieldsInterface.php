<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

/**
 * Something the application registers that adds fields to a record, and therefore owes
 * the index a mapping for them.
 *
 * It exists because there is now more than one kind: {@see AuditEnricherInterface},
 * which is handed a record and returns it enriched, and {@see MomentEnricherInterface},
 * which is asked what the moment looks like before any record exists. They are picked
 * up by the same tag and their fields are folded into the index the same way, and this
 * is the part they have in common — asked of both by `audit:index:create`,
 * `audit:index:sync` and `audit:check`, which care what fields an index needs and not
 * about when somebody decided on them.
 *
 * Implement one of the two rather than this: on its own it declares fields nothing
 * would ever add.
 */
interface DeclaresAuditFieldsInterface
{
    /**
     * Mapping properties for the attributes this adds, e.g.
     * ['salesType' => ['type' => 'integer']]. Return [] if it adds none.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mapping(): array;
}
