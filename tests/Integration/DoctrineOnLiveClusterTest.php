<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Borsche\ElasticsearchAuditBundle\Writer\SystemClock;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use PHPUnit\Framework\Attributes\Group;

/**
 * The whole slice, nothing faked: real entities with their relations, a real flush,
 * the real listener, and a live Elasticsearch index at the end of it — then the
 * reader bringing the history back. This is the only place where "the listener built
 * a document" meets "the mapping accepted it": the in-memory gateway takes anything,
 * the cluster does not, and the failure policy is "throw" so a refusal fails the
 * test loudly instead of settling into a log.
 */
#[Group('integration')]
final class DoctrineOnLiveClusterTest extends ElasticsearchTestCase
{
    private EntityManagerInterface $em;
    private ElasticsearchGateway $gateway;
    private AuditReader $reader;
    private AuditFrame $frame;
    private string $index;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the Doctrine side of this test.');
        }

        $this->index = $this->scratchIndex();
        $this->gateway = new ElasticsearchGateway(self::client());
        $this->gateway->createIndex($this->index, (new IndexDefinition())->toArray());

        // The same EntityManager the Doctrine tests use — built by hand, ORMSetup
        // insists on symfony/cache — but the gateway behind the writer is the cluster.
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $resolver = new IndexResolver($this->index);
        $transport = new SyncTransport($this->gateway);
        $buffer = new FrameBuffer();
        $writer = new AuditWriter($transport, $transport, $resolver, new ChainActorResolver([], 'live-test'), new SystemClock(), [], FailurePolicy::Throw, null, null, $buffer);

        $this->frame = new AuditFrame($buffer, $writer);
        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory()));
        $this->reader = new AuditReader($this->gateway, $resolver);
    }

    protected function tearDown(): void
    {
        $this->dropIndex($this->index);
    }

    public function testTheLifeOfAnEntityWithItsRelationsReadsBackFromTheIndex(): void
    {
        $author = new Author('alice');
        $tag = new Tag('php');
        $article = new Article('First title');
        $article->author = $author;
        $article->tags->add($tag);

        $this->em->persist($author);
        $this->em->persist($tag);
        $this->em->persist($article);
        $this->em->flush();
        $articleId = (int) $article->id;

        $article->title = 'Second title';
        $article->author = null;
        $this->em->flush();

        $this->em->remove($article);
        $this->em->flush();

        $this->refresh();

        $page = $this->reader->find(AuditQuery::for('article')->withObjectId($articleId)->oldestFirst());
        $events = array_map(static fn ($e) => $e->event, $page->entries);
        sort($events);

        self::assertSame(['create', 'remove', 'update'], $events, 'the whole life, read back from the cluster');

        $byEvent = [];
        foreach ($page->entries as $entry) {
            $byEvent[$entry->event] = $entry;
        }

        self::assertSame('alice', $byEvent['create']->changes['author']['new'], 'the association travelled as its representer said');
        self::assertSame(['php'], $byEvent['create']->changes['tags']['new']);
        self::assertSame('Second title', $byEvent['update']->changes['title']['new']);
        self::assertNull($byEvent['update']->changes['author']['new'], 'and letting go of the author is history too');
    }

    public function testElementTrackingFitsTheRealMappingAndDoesNotWidenIt(): void
    {
        $before = $this->gateway->mapping($this->index);

        $shipment = new Shipment('SH-LIVE');
        $shipment->add(new ShipmentLine('widget', 1));
        $this->em->persist($shipment);
        $this->em->flush();

        $line = $shipment->lines->first();
        $line->quantity = 7;
        $this->em->flush();

        $bolt = new ShipmentLine('bolt', 4);
        $shipment->add($bolt);
        $this->em->flush();

        $this->refresh();

        $page = $this->reader->find(AuditQuery::for('shipment')->oldestFirst()->limit(10));
        $keys = array_merge(...array_map(static fn ($e) => array_keys($e->changes), $page->entries));

        self::assertContains('lines.'.$line->id.'.quantity', $keys, 'the dotted key the mapping has never heard of was stored');
        self::assertContains('lines.'.$bolt->id, $keys);

        // dynamic: false is a promise about real documents, not about the test double:
        // whatever the listener wrote, the mapping holds exactly the fields it started with.
        self::assertSame(array_keys($before), array_keys($this->gateway->mapping($this->index)), 'no field crept into the mapping');
    }

    public function testAFrameAcrossSeveralFlushesLandsAsOneDocument(): void
    {
        $article = new Article('Draft');
        $this->em->persist($article);
        $this->em->flush();

        $this->frame->coalesce(function () use ($article): void {
            $article->title = 'Halfway';
            $this->em->flush();

            $article->title = 'Final';
            $this->em->flush();
        });

        $this->refresh();

        $updates = array_values(array_filter(
            $this->reader->find(AuditQuery::for('article')->withObjectId((int) $article->id))->entries,
            static fn ($e) => $e->event === 'update',
        ));

        self::assertCount(1, $updates, 'one business operation, one document in the index');
        self::assertSame(['old' => 'Draft', 'new' => 'Final'], $updates[0]->changes['title'], 'the steps between are nobody to read');
    }

    public function testAFailedFlushLeavesTheIndexEmpty(): void
    {
        // A listener behind ours fails the flush after the INSERT ran: the transaction
        // rolls back, and the index — the real one — must know nothing about it.
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(LifecycleEventArgs $args): void
            {
                throw new \RuntimeException('something else in the flush broke');
            }
        });

        try {
            $this->em->persist(new Article('Never happened'));
            $this->em->flush();
            self::fail('the flush should have failed');
        } catch (\RuntimeException) {
        }

        $this->refresh();

        self::assertSame(0, $this->reader->find(AuditQuery::for('article'))->total, 'the history must not describe a state the database never had');
    }

    public function testAMovedLineIsInTheIndexOnBothSides(): void
    {
        $a = new Shipment('SH-A');
        $a->add(new ShipmentLine('widget', 1));
        $b = new Shipment('SH-B');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $line = $a->lines->first();
        $line->shipment = $b;
        $a->lines->removeElement($line);
        $b->lines->add($line);
        $this->em->flush();

        $this->refresh();

        $changesById = [];
        foreach ($this->reader->find(AuditQuery::for('shipment')->withEvents('update'))->entries as $entry) {
            $changesById[(string) $entry->objectId] = $entry->changes;
        }

        self::assertSame(['old' => 'widget', 'new' => null], $changesById[(string) $a->id]['lines.'.$line->id]);
        self::assertSame(['old' => null, 'new' => 'widget'], $changesById[(string) $b->id]['lines.'.$line->id]);
    }

    private function refresh(): void
    {
        self::client()->indices()->refresh(['index' => $this->index]);
    }
}
