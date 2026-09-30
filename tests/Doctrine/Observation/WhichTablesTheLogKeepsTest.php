<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\WatchedRows;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Budget\Imported;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Budget\Unaudited;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Which statements the log keeps once the listener has said which tables a history is about:
 * those of the tables, the transactions and savepoints always, and a statement it cannot read,
 * which may be of any table. A flush of rows nobody audits leaves nothing in it.
 *
 * Two managers may share a connection, and so the log and the listener: each maps classes of
 * its own, and what one says of its tables is not what the other says.
 */
final class WhichTablesTheLogKeepsTest extends TestCase
{
    public function testTheLogKeepsTheTablesItIsToldAndWhatItCannotRead(): void
    {
        $log = new StatementLog();
        $log->keepingOnly(static fn (string $table): bool => $table === 'kept');

        $log->began();
        self::assertNotNull($log->executed('INSERT INTO kept (id) VALUES (?)', [1], 1));
        self::assertNull($log->executed('INSERT INTO other (id) VALUES (?)', [1], 1), 'a table nobody keeps');
        self::assertNull($log->executed('UPDATE other SET name = ? WHERE id = ?', ['a', 1], 1));
        self::assertNull($log->executed('DELETE FROM other WHERE id = ?', [1], 1));
        self::assertNull($log->executed('DELETE FROM other WHERE id IN (SELECT id FROM kept)', [], 1), 'what it writes is the other table\'s, whatever it reads');
        self::assertNotNull($log->executed('UPDATE other o JOIN kept k ON k.id = o.id SET o.name = ?', ['a'], 1), 'a statement it cannot read may be of any table');

        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $inside = $log->executed('UPDATE kept SET id = ? WHERE id = ?', [2, 1], 1);
        $log->executed('ROLLBACK TO SAVEPOINT DOCTRINE_2', [], 0);
        $log->committed();

        self::assertNotNull($inside);
        self::assertSame(StatementLog::VOID, $log->fate($inside), 'a savepoint is kept whatever the tables: what ran inside it is rolled back');
        self::assertSame(3, $log->position(), 'and the tables nobody keeps are not in the log: they are not even counted');
    }

    public function testAStatementOfAnotherManagersTablesIsKeptAfterThisOneFlushed(): void
    {
        $statements = new StatementLog();
        [$connection, $budget, $fixtures] = $this->twoManagersOnOneConnection($statements);

        $fixtures->persist(new Author('a'));
        $fixtures->flush();
        $budget->persist(new Imported('b'));
        $budget->flush();

        $before = $statements->position();
        $connection->executeStatement('UPDATE Author SET name = ? WHERE id = ?', ['changed', 1]);
        self::assertSame($before + 1, $statements->position(), 'Author is the other manager\'s history, and the last flush was not that manager\'s');

        $connection->executeStatement('UPDATE budget_unaudited SET name = ? WHERE id = ?', ['changed', 1]);
        self::assertSame($before + 1, $statements->position(), 'and a table neither manager has any history of is not kept');

        // Again, with nothing reset: a manager that flushes a second time is asked as the first.
        $budget->persist(new Imported('c'));
        $budget->flush();
        $again = $statements->position();
        $connection->executeStatement('UPDATE Author SET name = ? WHERE id = ?', ['again', 1]);
        $connection->executeStatement('UPDATE budget_unaudited SET name = ? WHERE id = ?', ['again', 1]);
        self::assertSame($again + 1, $statements->position(), 'the second flush says of the tables what the first said');
    }

    public function testEachManagerIsAskedOfItsOwnMapping(): void
    {
        $statements = new StatementLog();
        [, $budget, $fixtures] = $this->twoManagersOnOneConnection($statements);
        $watched = new WatchedRows();

        self::assertFalse($watched->areShownAsTheyStood($budget, $budget->getClassMetadata(Unaudited::class)));
        self::assertFalse($watched->isAHistoryTable($budget, 'budget_unaudited'));

        self::assertTrue($watched->areShownAsTheyStood($fixtures, $fixtures->getClassMetadata(Author::class)), 'an audited association of the other manager points at it');
        self::assertTrue($watched->isAHistoryTable($fixtures, 'Author'));
        self::assertTrue($watched->isAHistoryTable($budget, 'budget_imported'));
    }

    /**
     * @return array{Connection, EntityManager, EntityManager}
     */
    private function twoManagersOnOneConnection(StatementLog $statements): array
    {
        $configurationOf = static function (string $directory) use ($statements): Configuration {
            $config = new Configuration();
            $config->setMetadataDriverImpl(new AttributeDriver([$directory]));
            $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
            $config->setProxyNamespace('BorscheAuditProxies');
            $config->setAutoGenerateProxyClasses(true);
            $config->setMiddlewares([new ObservingMiddleware($statements)]);

            if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
                $config->enableNativeLazyObjects(true);
            }

            return $config;
        };

        $budgetConfig = $configurationOf(__DIR__.'/../Budget');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $budgetConfig);
        $budget = new EntityManager($connection, $budgetConfig);
        $fixtures = new EntityManager($connection, $configurationOf(__DIR__.'/../../Fixtures'));

        (new SchemaTool($budget))->createSchema($budget->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($fixtures))->createSchema($fixtures->getMetadataFactory()->getAllMetadata());

        $transport = new class implements BatchTransportInterface {
            public function send(string $index, array $document, ?string $id = null): void
            {
            }

            public function sendMany(array $items): BulkResult
            {
                return BulkResult::allSucceeded(\count($items));
            }
        };
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tables'), new FrozenClock(), [], FailurePolicy::Throw);
        $listener = new AuditSubscriber($writer, new AuditMetadataFactory(), $statements);
        $budget->getEventManager()->addEventListener(AuditSubscriber::EVENTS, $listener);
        $fixtures->getEventManager()->addEventListener(AuditSubscriber::EVENTS, $listener);

        return [$connection, $budget, $fixtures];
    }
}
