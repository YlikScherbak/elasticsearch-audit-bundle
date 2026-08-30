<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

/**
 * Where a record came from, so an enricher or a listener can tell one apart from the
 * other without guessing from the actor or the event name.
 *
 * It is not stored: the document says what happened, and this says which part of the
 * application noticed it — a fact about the write, not about the history.
 */
enum AuditOrigin: string
{
    /** Built by the Doctrine listener from an entity's change set. */
    case Doctrine = 'doctrine';

    /** Handed to AuditWriter::record() or write() by the application. */
    case Manual = 'manual';

    /** A frame merged records of both kinds into one. */
    case Mixed = 'mixed';
}
