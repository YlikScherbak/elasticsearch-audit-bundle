<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Command;

use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * What `audit:check` can say about the outbox that the boot cannot.
 *
 * The compiler pass reads the DSN, and the DSN most production applications write is
 * an environment variable — unreadable until it is resolved, which is to say
 * unreadable exactly where the answer matters most. Here the services exist, and the
 * queue can be asked which connection it is holding.
 */
final class CheckCommandOutboxTest extends TestCase
{
    private Connection $audited;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the outbox check.');
        }

        $this->audited = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testAQueueOnTheAuditedConnectionIsReported(): void
    {
        $queue = $this->queueOn($this->audited);
        $queue->setup();

        $output = $this->check($queue);

        self::assertStringContainsString('on the audited connection', $output);
        self::assertStringContainsString('0 record(s) waiting', $output);
    }

    public function testAQueueOnAnotherConnectionFailsTheCheck(): void
    {
        // The one the boot cannot catch behind an environment variable, and the one that
        // is silent in production: everything works, and the record and its change are
        // committed by two different transactions.
        $elsewhere = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $queue = $this->queueOn($elsewhere);
        $queue->setup();

        $output = $this->check($queue, expected: CheckCommand::FAILURE);

        self::assertStringContainsString('different Doctrine connection', $output);
    }

    public function testAQueueWhoseTableNobodyCreatedFailsTheCheck(): void
    {
        // auto_setup is off by requirement, so the table comes from a migration — and
        // when it does not, every record ever written would fail.
        $output = $this->check($this->queueOn($this->audited), expected: CheckCommand::FAILURE);

        self::assertStringContainsString('cannot be read', $output);
        self::assertStringContainsString('migration', $output);
    }

    public function testATransportThatIsNotDoctrineFailsTheCheck(): void
    {
        $output = $this->check(new \stdClass(), expected: CheckCommand::FAILURE);

        self::assertStringContainsString('not a Doctrine transport', $output);
    }

    public function testWithoutAnOutboxThereIsNothingToSay(): void
    {
        $output = $this->check(null);

        self::assertStringNotContainsString('Outbox', $output);
    }

    private function queueOn(Connection $connection): DoctrineTransport
    {
        return new DoctrineTransport(
            new QueueConnection(['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false], $connection),
            new PhpSerializer(),
        );
    }

    private function check(?object $queue, int $expected = CheckCommand::SUCCESS): string
    {
        $gateway = new InMemoryGateway();
        $gateway->indices['audit_log'] = (new IndexDefinition())->toArray();

        $command = new CheckCommand(
            $gateway,
            new IndexResolver('audit_log'),
            new IndexDefinition(),
            [],
            10_000,
            $queue,
            $queue === null ? null : $this->audited,
            $queue === null ? '' : 'audit_outbox',
        );

        $tester = new CommandTester($command);

        self::assertSame($expected, $tester->execute([]));

        return $tester->getDisplay();
    }
}
