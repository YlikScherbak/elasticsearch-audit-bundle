<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Psr\Log\LoggerInterface;

/**
 * Builds the client from the bundle's own configuration (client.hosts).
 * Applications that already have a client register it as client.service instead
 * and this factory is never used.
 */
final class ClientFactory
{
    /**
     * @param list<string> $hosts
     */
    public static function create(array $hosts, bool $sslVerification = true, ?LoggerInterface $logger = null): Client
    {
        if ($hosts === []) {
            throw new NotConfiguredException('No Elasticsearch host configured: set borsche_elasticsearch_audit.client.hosts or client.service.');
        }

        $builder = ClientBuilder::create()
            ->setHosts($hosts)
            ->setSSLVerification($sslVerification);

        if ($logger !== null) {
            $builder->setLogger($logger);
        }

        return $builder->build();
    }
}
