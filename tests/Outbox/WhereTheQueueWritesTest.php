<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Outbox;

use Borsche\ElasticsearchAuditBundle\Outbox\WhereTheQueueWrites;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Whether a queue writes on a connection, read off configureSchema() wherever that puts its
 * answer.
 *
 * Found by CI on the first run after DBAL 4.5: Symfony 7's queue there returns a new schema with
 * its table and leaves the one it was given empty, and the check, which read the one it had given,
 * called every queue one on another connection — so every audit transaction was refused and
 * audit:check reported the outbox broken. The installed DBAL decides which of the two the real
 * queue does, so each is also played here by a queue that does it whatever is installed.
 */
final class WhereTheQueueWritesTest extends TestCase
{
    public function testAQueueOnTheConnectionWritesOnIt(): void
    {
        $connection = self::connection();

        self::assertTrue(WhereTheQueueWrites::isOn(self::queueOn($connection), $connection));
    }

    public function testAQueueOnAnotherConnectionDoesNot(): void
    {
        self::assertFalse(WhereTheQueueWrites::isOn(self::queueOn(self::connection()), self::connection()));
    }

    public function testAQueueThatAnswersInANewSchemaIsHeard(): void
    {
        if ((string) (new \ReflectionMethod(DoctrineTransport::class, 'configureSchema'))->getReturnType() === 'void') {
            self::markTestSkipped('Symfony 6.4 declares configureSchema() void: its queue cannot answer in a schema of its own.');
        }

        // As on DBAL 4.5, where a schema is edited into a new one.
        $queue = $this->createStub(DoctrineTransport::class);
        $queue->method('configureSchema')->willReturnCallback(static function (Schema $handedIn): Schema {
            $answer = new Schema();
            $answer->createTable('audit_outbox')->addColumn('id', 'integer');

            return $answer;
        });

        self::assertTrue(WhereTheQueueWrites::isOn($queue, self::connection()));
    }

    public function testAQueueThatAnswersInTheSchemaItWasGivenIsHeard(): void
    {
        // As on Symfony 6.4, where the method is void and fills in what it was given.
        $queue = $this->createStub(DoctrineTransport::class);
        $queue->method('configureSchema')->willReturnCallback(static function (Schema $handedIn): void {
            $handedIn->createTable('audit_outbox')->addColumn('id', 'integer');
        });

        self::assertTrue(WhereTheQueueWrites::isOn($queue, self::connection()));
    }

    public function testAQueueThatAddsNothingAnywhereIsNotOnTheConnection(): void
    {
        $queue = $this->createStub(DoctrineTransport::class);
        $queue->method('configureSchema')->willReturnCallback(static fn (): ?Schema => null);

        self::assertFalse(WhereTheQueueWrites::isOn($queue, self::connection()));
    }

    private static function connection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    private static function queueOn(Connection $connection): DoctrineTransport
    {
        return new DoctrineTransport(new QueueConnection(['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false], $connection), new PhpSerializer());
    }
}
