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
 *
 * @internal builds the client from client.hosts; configure client.service to bring your own
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
            // Never the application's logger directly: the client logs each request with
            // its URL, and hosts carrying inline credentials would put the password in
            // the log once per call.
            $builder->setLogger(new UserinfoRedactingLogger($logger));
        }

        return $builder->build();
    }
}
