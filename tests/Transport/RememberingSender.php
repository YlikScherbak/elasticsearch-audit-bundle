<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Transport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * A sender that keeps what it was given. In a file of its own for the reason QueueSender is.
 */
final class RememberingSender implements SenderInterface
{
    /** @var list<object> */
    public array $sent = [];

    public function send(Envelope $envelope): Envelope
    {
        $this->sent[] = $envelope->getMessage();

        return $envelope;
    }
}
