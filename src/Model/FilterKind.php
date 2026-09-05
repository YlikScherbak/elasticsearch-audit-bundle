<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

/**
 * What a Filter says about an attribute. An enum rather than string constants, so a
 * kind the translation does not know cannot be constructed at all — the match in
 * QueryBuilder is exhaustive by the type system rather than by a default arm nobody
 * would notice missing.
 */
enum FilterKind: string
{
    /** Exact match on one value. */
    case Is = 'is';

    /** Any of a list of values. */
    case In = 'in';

    /** The document has the field (Elasticsearch's exists: a null value does not count). */
    case Exists = 'exists';

    /** The document does not have the field — what a backfill goes looking for. */
    case Missing = 'missing';

    /** Inclusive range, either bound optional. */
    case Between = 'between';
}
