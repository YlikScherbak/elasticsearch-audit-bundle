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
            // Never the application's logger directly. The client logs the audited
            // document itself at debug — request body and response body both — and puts
            // the PSR-7 objects in the context of its info lines; a host carrying inline
            // credentials puts the password in every URL it writes. The gate decides what
            // of that an application's log is allowed to receive.
            $builder->setLogger(new ClientLogGate($logger));
        }

        return $builder->build();
    }
}
