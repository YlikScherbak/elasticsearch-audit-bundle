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
 * Found by CI on the first run after DBAL 4.5: Messenger's queue there (7.4.20, and 6.4.47)
 * returns a new schema with its table and leaves the one it was given empty, and the check, which
 * read the one it had given, called every queue one on another connection — so every audit
 * transaction was refused and audit:check reported the outbox broken. The installed DBAL decides which of the two the real
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
        if (self::declared() === 'void') {
            self::markTestSkipped('This Symfony declares configureSchema() void, as the oldest 6.4 releases do: its queue cannot answer in a schema of its own.');
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
        if (self::declared() === Schema::class) {
            self::markTestSkipped('This Symfony declares configureSchema() to return a schema, as 8 does: its queue cannot answer with nothing.');
        }

        // As on the oldest Symfony 6.4 releases, where the method is void and fills in what it is
        // given.
        $queue = $this->createStub(DoctrineTransport::class);
        $queue->method('configureSchema')->willReturnCallback(static function (Schema $handedIn): ?Schema {
            $handedIn->createTable('audit_outbox')->addColumn('id', 'integer');

            return null;
        });

        self::assertTrue(WhereTheQueueWrites::isOn($queue, self::connection()));
    }

    public function testAQueueThatAddsNothingAnywhereIsNotOnTheConnection(): void
    {
        // Nothing, in whichever way this Symfony lets it be said: the empty schema it was given
        // back, or no answer at all where the method is void.
        $void = self::declared() === 'void';
        $queue = $this->createStub(DoctrineTransport::class);
        $queue->method('configureSchema')->willReturnCallback(static fn (Schema $handedIn): ?Schema => $void ? null : $handedIn);

        self::assertFalse(WhereTheQueueWrites::isOn($queue, self::connection()));
    }

    /**
     * What this Symfony declares configureSchema() to return: void on the oldest 6.4 releases,
     * nothing on later 6.4 and 7, a Schema on 8. A stub cannot answer outside it.
     */
    private static function declared(): string
    {
        return ltrim((string) (new \ReflectionMethod(DoctrineTransport::class, 'configureSchema'))->getReturnType(), '\\');
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
