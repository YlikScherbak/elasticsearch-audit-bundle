<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

/**
 * An enricher that runs on the record as it will be stored, rather than on each step
 * that made it.
 *
 * An ordinary AuditEnricherInterface sees a record the moment it is created — before a
 * frame merges it with the other saves of the same business operation. That is the
 * right moment for a fact about the step (who was authenticated, which request), and
 * the wrong one for a fact about the outcome: a quantity that went 1000 → 1040 → 1000
 * ends up as no change at all, but an enricher that ran on the last step already
 * decided it changed, and the merged record then carries an attribute that contradicts
 * its own changes.
 *
 * This one runs once per record, immediately before it is written, on whatever the
 * frame merged — and on the record itself when no frame was open, so the behaviour does
 * not depend on whether the caller happened to open one. Redaction runs after it, so
 * whatever it adds is redacted like everything else.
 *
 * It is an AuditEnricherInterface: supports(), enrich() and mapping() mean the same
 * things, and audit:index:create still reads the mapping. Only the moment differs.
 */
interface MergedRecordEnricherInterface extends AuditEnricherInterface
{
}
