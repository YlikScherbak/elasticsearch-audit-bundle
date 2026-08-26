<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Command;

use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;

/**
 * Folds the mapping every enricher declares into the index definition.
 *
 * @internal
 */
final class EnricherMapping
{
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
