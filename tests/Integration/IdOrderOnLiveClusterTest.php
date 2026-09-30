<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;

/**
 * The order the ids promise (1.3 C), read back by the reader as it reads any history: its
 * sort, its cursor, a page of one record at a time, both ways. Records of one millisecond
 * sort by id, and an in-memory gateway sorts nothing -- only the cluster can say whether the
 * order a reader walks is the order the history happened in.
 */
#[Group('integration')]
final class IdOrderOnLiveClusterTest extends ElasticsearchTestCase
{
    private StatementLog $statements;
    private EntityManagerInterface $em;
    private AuditReader $reader;
    private AuditFrame $frame;
    private string $index;

    public function testANestedFlushsChangeReadsAfterTheOuterOnesInOneMillisecond(): void
    {
        // S1a with savepoints: the outer flush writes a title One -> Two, and a listener ahead of
        // the audit one runs a nested flush in its postUpdate that writes Two -> Five. One
        // millisecond for all of it -- the clock does not move -- so the order the reader walks is
        // the order of the ids alone. Twelve articles, one after the other: ids that were random
        // in the millisecond would read one of them backwards all but one time in 4096.
        $this->connect(new FrozenClock(new \DateTimeImmutable('2026-08-26 12:00:00.000', new \DateTimeZone('UTC'))));
        $articles = [];

        for ($i = 0; $i < 12; ++$i) {
            $this->em->persist($articles[] = new Article('One'));
        }

        $this->em->flush();

        $this->ahead(new class($this->em) {
            public function __construct(private readonly EntityManagerInterface $em)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                $article = $args->getObject();

                if (!$article instanceof Article || $article->title !== 'Two') {
                    return;
                }

                $article->title = 'Five';
                $this->em->flush();
            }
        });

        foreach ($articles as $article) {
            $article->title = 'Two';
            $this->em->flush();
        }

        $this->refresh();

        foreach ($articles as $article) {
            $query = AuditQuery::for('article')->withObjectId((int) $article->id)->withEvents('update');
            $oldest = $this->walk($query->oldestFirst());
            $newest = $this->walk($query->newestFirst());

            self::assertCount(1, array_unique(array_map(static fn (AuditEntry $e): string => $e->loggedAt->format('Uv'), $oldest)), 'the premise: one millisecond');
            self::assertSame([['One', 'Two'], ['Two', 'Five']], self::titles($oldest), 'oldest first, a page of one at a time');
            self::assertSame([['Two', 'Five'], ['One', 'Two']], self::titles($newest), 'and newest first, the same walk backwards');
        }
    }

    public function testARecordAFrameMergedReadsWhereItsFirstPartWasBuilt(): void
    {
        // Inside a frame: one flush changes twenty-four articles; a later flush, a millisecond on,
        // changes twelve of them again. The frame merges each of those twelve's two records into
        // one, with the id and the moment of the first part -- so every record reads where the
        // first UPDATE of its article ran in the first flush, merged or not. Doctrine runs one
        // class's UPDATEs in an order of its own (ORM 3.7 by the identifier as text, ORM 2 and 3.6 as they came to be managed), so the order
        // expected is read from the log. Random ids would put some of them out of it nearly
        // always.
        $this->connect(new TickingClock(new \DateTimeImmutable('2026-08-26 12:00:00.000', new \DateTimeZone('UTC'))));
        $firsts = $seconds = [];

        for ($i = 0; $i < 12; ++$i) {
            $this->em->persist($firsts[] = new Article('A'.$i));
        }

        for ($i = 0; $i < 12; ++$i) {
            $this->em->persist($seconds[] = new Article('B'.$i));
        }

        $this->em->flush();
        $from = $this->statements->position();

        $this->frame->coalesce(function () use ($firsts, $seconds): void {
            foreach ([...$firsts, ...$seconds] as $article) {
                $article->title .= '-2';
            }

            $this->em->flush();

            foreach ($firsts as $article) {
                $article->title .= '-3';
            }

            $this->em->flush();
        });
        $this->refresh();

        $updates = [];

        for ($at = $from + 1, $to = $this->statements->position(); $at <= $to; ++$at) {
            $statement = $this->statements->statement($at);

            if ($statement !== null && str_starts_with($statement['sql'], 'UPDATE Article')) {
                $params = array_values($statement['params']);
                $updates[] = end($params);
            }
        }

        self::assertCount(36, $updates, 'the premise: twenty-four UPDATEs, then twelve');
        $titles = [];

        foreach ([...$firsts, ...$seconds] as $article) {
            $titles[$article->id] = [substr($article->title, 0, strpos($article->title, '-') ?: null), $article->title];
        }

        $expected = array_map(static fn (int $id): array => $titles[$id], \array_slice($updates, 0, 24));

        $oldest = $this->walk(AuditQuery::for('article')->withEvents('update')->oldestFirst());
        $newest = $this->walk(AuditQuery::for('article')->withEvents('update')->newestFirst());

        self::assertSame($expected, self::titles($oldest), 'each merged record where its first part was built');
        self::assertSame(array_reverse($expected), self::titles($newest));
        self::assertCount(1, array_unique(array_map(static fn (AuditEntry $e): string => $e->loggedAt->format('Uv'), $oldest)), 'a merged record has the moment of its first part, not of the flush a millisecond on');
    }

    private function connect(ClockInterface $clock): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the Doctrine side of this test.');
        }

        $this->index = $this->scratchIndex();
        $gateway = new ElasticsearchGateway(self::client());
        $gateway->createIndex($this->index, (new IndexDefinition())->toArray());

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        // Kept whole: the test reads the log again on its own, after the listener let go of it.
        $config->setMiddlewares([new ObservingMiddleware($this->statements = new StatementLog(letsGo: false))]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        if (method_exists($connection, 'setNestTransactionsWithSavepoints')) {
            $connection->setNestTransactionsWithSavepoints(true); // DBAL 3; DBAL 4 always nests so
        }

        $this->em = new EntityManager($connection, $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $resolver = new IndexResolver($this->index);
        $transport = new SyncTransport($gateway);
        $buffer = new FrameBuffer();
        $writer = new AuditWriter($transport, $transport, $resolver, new ChainActorResolver([], 'live-test'), $clock, [], FailurePolicy::Throw, null, null, $buffer);

        $this->frame = new AuditFrame($buffer, $writer);
        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory(), $this->statements));
        $this->reader = new AuditReader($gateway, $resolver);
    }

    /**
     * Every entry, a page of one at a time by the reader's own cursor: an entry the order puts
     * twice, or skips, shows as a list that is not the one expected.
     *
     * @return list<AuditEntry>
     */
    private function walk(AuditQuery $query): array
    {
        $entries = [];
        $q = $query->page(1, 1);

        for ($guard = 0; $guard < 100; ++$guard) {
            $page = $this->reader->find($q);

            if ($page->isEmpty()) {
                break;
            }

            array_push($entries, ...$page->entries);
            $cursor = $page->nextCursor();

            if ($cursor === null) {
                break;
            }

            $q = $q->after($cursor);
        }

        return $entries;
    }

    /**
     * @param list<AuditEntry> $entries
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function titles(array $entries): array
    {
        return array_map(static fn (AuditEntry $e): array => [$e->changes['title']['old'] ?? null, $e->changes['title']['new'] ?? null], $entries);
    }

    private function refresh(): void
    {
        self::client()->indices()->refresh(['index' => $this->index]);
    }

    private function ahead(object $listener): void
    {
        $manager = $this->em->getEventManager();
        $there = $manager->getListeners(Events::postUpdate);

        foreach ($there as $one) {
            $manager->removeEventListener([Events::postUpdate], $one);
        }

        $manager->addEventListener([Events::postUpdate], $listener);

        foreach ($there as $one) {
            $manager->addEventListener([Events::postUpdate], $one);
        }
    }
}

/** A clock one millisecond on each time it is asked. */
final class TickingClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        $now = $this->now;
        $this->now = $now->modify('+1 msec');

        return $now;
    }
}
