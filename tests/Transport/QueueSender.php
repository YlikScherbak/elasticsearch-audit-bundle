<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Transport;

use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The smallest thing that turns a Doctrine queue connection into a Messenger sender:
 * what FrameworkBundle's own transport does, without the receiving half.
 *
 * In a file of its own because tests outside the one that first needed it use it, and a class
 * declared beside a test class exists only once that test's file is loaded: a run of the other
 * file alone — Infection's, against a mutant — failed on a missing class and called it a kill.
 */
final class QueueSender implements SenderInterface
{
    public function __construct(private readonly QueueConnection $queue)
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        $encoded = (new PhpSerializer())->encode($envelope);
        $this->queue->send($encoded['body'], $encoded['headers'] ?? []);

        return $envelope;
    }
}
