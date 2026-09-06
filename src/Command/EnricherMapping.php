<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Command;

use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Writer\EnricherScope;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;

/**
 * Folds the mapping every enricher declares into the index definition.
 *
 * @internal
 */
final class EnricherMapping
{
    /**
     * The fields one index should have: the base definition plus the enrichers whose
     * records are routed to it.
     *
     * Every enricher used to go into every index, which is right until an application
     * routes an object type somewhere of its own — and then the order fields are
     * declared on the auth index too, audit:check reports them missing from indices they
     * can never appear in, and audit:index:sync adds them. Enrichers that named no
     * object types still go everywhere; that is what they are saying.
     *
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    public static function forIndex(IndexDefinition $definition, iterable $enrichers, IndexResolver $resolver, string $index): IndexDefinition
    {
        $mine = [];

        foreach ($enrichers as $enricher) {
            if (EnricherScope::reaches($enricher, $resolver, $index)) {
                $mine[] = $enricher;
            }
        }

        return self::apply($definition, $mine);
    }

    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    public static function apply(IndexDefinition $definition, iterable $enrichers): IndexDefinition
    {
        foreach ($enrichers as $enricher) {
            $mapping = $enricher->mapping();

            if ($mapping !== []) {
                $definition = $definition->withProperties($mapping);
            }
        }

        return $definition;
    }
}
