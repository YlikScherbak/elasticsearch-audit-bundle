<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Basket;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\BasketItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * What this bundle believes about Doctrine, tested without this bundle in the way.
 *
 * Every assertion here is an ORM behaviour that `AuditSubscriber` is built on — not a
 * documented contract but an observable fact about how the unit of work behaves, which
 * is exactly the kind of thing a minor release is free to change. When one of these
 * fails after an upgrade, the listener is wrong in a way its own tests will not show:
 * those pass through the listener and would simply record something different, quietly.
 *
 * So there is no AuditSubscriber in this file at all, on purpose. Each canary names
 * the place in the listener that leans on it, and a failure here is the first thing to
 * read when the audit trail starts saying something new after a `composer update`.
 *
 * @see \Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber
 */
final class DoctrineCanariesTest extends TestCase
{
    private EntityManagerInterface $em;
    private \Doctrine\DBAL\Connection $connection;
    private Configuration $config;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the Doctrine canaries.');
        }

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $this->config = $config;
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($this->connection, $config);

        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * Backs `AuditSubscriber::sidesFrom()`, and the reason it exists.
     *
     * `computeChangeSet()` ends by writing the current values into
     * `originalEntityData`. So a preUpdate listener that corrects a field and calls
     * `recomputeSingleEntityChangeSet()` is handed a change set whose "old" is the value
     * that was *planned* a moment ago — a value that was never in the database. Taking
     * the recomputed set whole wrote a record saying the row went from something it
     * never held, which is why the listener keeps the onFlush snapshot and merges only
     * the new sides in.
     */
    public function testRecomputingAChangeSetReportsThePlannedValueAsTheOldOne(): void
    {
        $basket = new Basket('first');
        $this->em->persist($basket);
        $this->em->flush();

        $seen = [];
        $this->em->getEventManager()->addEventListener([Events::preUpdate], new class($seen) {
            /** @param array<string, array{mixed, mixed}> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $entity = $args->getObject();

                if (!$entity instanceof Basket) {
                    return;
                }

                $entity->label = 'corrected';

                $uow = $args->getObjectManager()->getUnitOfWork();
                $uow->recomputeSingleEntityChangeSet($args->getObjectManager()->getClassMetadata(Basket::class), $entity);

                $this->seen = $uow->getEntityChangeSet($entity);
            }
        });

        $basket->label = 'second';
        $this->em->flush();

        self::assertArrayHasKey('label', $seen);
        self::assertSame(
            ['second', 'corrected'],
            [$seen['label'][0], $seen['label'][1]],
            'the recomputed change set no longer reports the planned value as the old one — sidesFrom() may be working around something Doctrine has fixed',
        );

        // And the row really did go from "first": that is what the audit record has to say.
        $this->em->clear();
        self::assertSame('corrected', $this->em->find(Basket::class, $basket->id)?->label);
    }

    /**
     * Backs the snapshot the listener takes in onFlush for emptied collections.
     *
     * `clear()` on an **owning** collection schedules it for deletion and then takes a
     * snapshot, so by the time anything downstream can look, the collection is empty,
     * its snapshot is empty, and `isDirty()` answers false. A record built from that
     * says nothing at all while the join rows are deleted — which is why the listener
     * reads the collection in onFlush and keeps what it held.
     *
     * The condition matters as much as the behaviour: `PersistentCollection::clear()`
     * only does any of this when the association owns the join, so the inverse side of
     * the same relationship behaves in the opposite way. Both halves are pinned here,
     * because a listener that generalised from either one alone would be wrong about
     * half the collections it meets.
     */
    public function testClearingAnOwningCollectionLeavesNothingBehindToReadItFrom(): void
    {
        $article = new Article('first');
        $article->tags->add($one = new Tag('one'));
        $article->tags->add($two = new Tag('two'));

        $this->em->persist($one);
        $this->em->persist($two);
        $this->em->persist($article);
        $this->em->flush();
        $this->em->clear();

        $article = $this->em->find(Article::class, $article->id);

        self::assertInstanceOf(Article::class, $article);

        $collection = $article->tags;

        self::assertInstanceOf(PersistentCollection::class, $collection);
        self::assertCount(2, $collection);

        $collection->clear();

        self::assertCount(0, $collection, 'the collection still holds what it held');
        self::assertSame([], $collection->getSnapshot(), 'the snapshot still knows what was taken away — the listener could read it the ordinary way');
        self::assertFalse($collection->isDirty(), 'clear() now leaves the collection dirty, which would be a second way to notice it');
    }

    public function testWhichCollectionChangesGiveTheirOwnerALifecycleEventAndWhichDoNot(): void
    {
        // Every record this listener builds for an entity is built inside a lifecycle
        // event, so which collection operations produce one decides which of them can be
        // recorded at all — and they do not all produce one. Adding and removing an
        // element dirty the owner and it gets its postUpdate; clear() schedules the
        // collection for deletion instead and the owner is never touched, so an emptied
        // collection has to reach its record by another road.
        //
        // The listener leans on this in publish(), which builds a record for an owner
        // Doctrine raised no event for. If a future ORM starts dispatching postUpdate for
        // a clear(), that record is built twice; if it stops dispatching one for an add,
        // that membership goes unrecorded.
        $article = new Article('first');
        $article->tags->add($one = new Tag('one'));
        $article->tags->add($two = new Tag('two'));
        $spare = new Tag('spare');

        foreach ([$one, $two, $spare] as $tag) {
            $this->em->persist($tag);
        }

        $this->em->persist($article);
        $this->em->flush();

        $seen = [];
        $spy = new class($seen) {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                // Empty, and that is the point: the owner is scheduled for an update
                // because a collection of its went dirty, not because a column moved.
                $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($args->getObject());

                $this->seen[] = 'postUpdate('.\count($changeSet).')';
            }
        };

        $this->em->getEventManager()->addEventListener([Events::postUpdate], $spy);

        $article->tags->add($spare);
        $this->em->flush();

        self::assertSame(['postUpdate(0)'], $seen, 'adding to an owning collection no longer gives its owner an event with an empty change set');

        $seen = [];
        $article->tags->removeElement($spare);
        $this->em->flush();

        self::assertSame(['postUpdate(0)'], $seen, 'removing from an owning collection no longer gives its owner an event with an empty change set');

        $seen = [];
        $article->tags->clear();
        $this->em->flush();

        self::assertSame([], $seen, 'clear() now gives the owner an event, so the record for an emptied collection is built twice');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM article_tag'), 'the premise: the join rows went all the same');
    }

    public function testACollectionDeletionAVetoStoppedIsStillScheduledOnTheNextFlush(): void
    {
        // What tells a flush that emptied a collection and committed from one a veto
        // stopped, when neither leaves an entity event behind to ask about. The unit of
        // work clears its scheduled collection deletions in postCommitCleanup(), so one
        // that never got there is still on the list when the next flush computes its
        // own — and that next flush will carry it out.
        //
        // The listener leans on this in beginFlush(), where an emptied collection still
        // on the list means the flush that scheduled it did not commit, so its history
        // is dropped rather than written late.
        $article = new Article('first');
        $article->tags->add($one = new Tag('one'));

        $this->em->persist($one);
        $this->em->persist($article);
        $this->em->flush();

        $veto = new class {
            public bool $angry = true;

            public function onFlush(): void
            {
                if ($this->angry) {
                    throw new \DomainException('vetoed');
                }
            }
        };

        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        $article->tags->clear();

        try {
            $this->em->flush();
            self::fail('the veto should have stopped the flush');
        } catch (\DomainException) {
        }

        $veto->angry = false;

        $stillScheduled = null;
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class($stillScheduled) {
            public function __construct(private mixed &$stillScheduled)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                $this->stillScheduled = \count($args->getObjectManager()->getUnitOfWork()->getScheduledCollectionDeletions());
            }
        });

        $this->em->persist(new Article('second'));
        $this->em->flush();

        self::assertSame(1, $stillScheduled, 'a collection deletion a veto stopped is no longer waiting on the next flush, so the listener cannot tell that flush from one that committed');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM article_tag'), 'and the next flush is what carried it out');
    }

    public function testClearingAnInverseCollectionDoesNoneOfThat(): void
    {
        // The other side of the same condition. Nothing is scheduled, no snapshot is
        // taken, and no join rows go anywhere — the inverse side owns nothing to delete.
        // A listener treating both sides alike would report a deletion that did not
        // happen.
        $basket = new Basket('first');
        $basket->add(new BasketItem('one'));
        $basket->add(new BasketItem('two'));

        $this->em->persist($basket);
        $this->em->flush();
        $this->em->clear();

        $basket = $this->em->find(Basket::class, $basket->id);

        self::assertInstanceOf(Basket::class, $basket);

        $collection = $basket->items;

        self::assertInstanceOf(PersistentCollection::class, $collection);
        self::assertCount(2, $collection, 'loaded first: an uninitialised collection has no snapshot to speak of either way');

        $collection->clear();

        self::assertCount(2, $collection->getSnapshot(), 'the inverse side started taking snapshots on clear()');
    }

    /**
     * Backs `AuditSubscriber::beginFlush()`, which tells a flush nested inside another
     * from a new one by the transaction depth.
     *
     * The depth is not what one would guess: `onFlush` is dispatched **before** the ORM
     * opens its transaction, so a plain flush sees zero. What sees more is a flush
     * started from inside one — from `postUpdate`, say, where application code often
     * writes something in response to a change — because by then the outer flush's
     * transaction is open. That difference is the whole signal the listener has for
     * telling "I am inside the flush I am already watching" from "the one I was
     * watching ended without telling me".
     */
    public function testAFlushStartedFromInsideAnotherRunsDeeper(): void
    {
        $basket = new Basket('first');
        $this->em->persist($basket);
        $this->em->flush();

        $depths = [];
        $nested = false;

        $this->em->getEventManager()->addEventListener([Events::onFlush], new class($depths) {
            /** @param list<int> $depths */
            public function __construct(private array &$depths)
            {
            }

            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $args): void
            {
                $this->depths[] = $args->getObjectManager()->getConnection()->getTransactionNestingLevel();
            }
        });

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($nested) {
            public function __construct(private bool &$nested)
            {
            }

            public function postUpdate(\Doctrine\ORM\Event\PostUpdateEventArgs $args): void
            {
                if ($this->nested) {
                    return;
                }

                $this->nested = true;

                $em = $args->getObjectManager();
                $em->persist(new Basket('written from inside the flush'));
                $em->flush();
            }
        });

        $basket->label = 'second';
        $this->em->flush();

        self::assertCount(2, $depths, 'the nested flush did not happen');
        self::assertSame(0, $depths[0], 'onFlush is now dispatched inside the transaction — beginFlush() reads a different number');
        self::assertGreaterThan($depths[0], $depths[1], 'a flush started from inside another no longer runs deeper');
    }

    /**
     * Backs the rule that decides which flush is collecting right now.
     *
     * The listener keeps a stack of the flushes it is inside, each entry remembering the
     * transaction level its `onFlush` arrived at. Which entry is the current one is
     * decided by comparing that level with the connection's level at the moment the
     * question is asked — and the whole rule rests on two facts about the ORM that are
     * observable rather than promised:
     *
     * - a flush's `onFlush` is dispatched BEFORE its transaction begins, and every
     *   lifecycle event of that flush arrives one level deeper than its `onFlush` did;
     * - an inner flush that dies in `onFlush` never opened a transaction, so the level
     *   stays where it was — and the outer flush's next event therefore arrives at a
     *   level EQUAL to the one the dead inner pushed at.
     *
     * That second fact is what lets "discard every entry at or below the current level"
     * throw away a dead inner before anything is recorded under its number. If it stops
     * holding, the listener records the outer flush's later work under the moment of a
     * flush that never happened.
     */
    public function testAtWhatDepthEachEventOfAFlushArrives(): void
    {
        foreach ([false, true] as $killTheInner) {
            $trace = [];
            $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $this->config), $this->config);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

            $em->persist($first = new Article('First'));
            $em->persist($second = new Article('Second'));
            $em->persist($aside = new Article('Aside'));
            $em->flush();

            $watcher = new class($trace) {
                /** @param list<string> $trace */
                public function __construct(private array &$trace)
                {
                }

                public function onFlush(OnFlushEventArgs $args): void
                {
                    $this->trace[] = 'onFlush '.$args->getObjectManager()->getConnection()->getTransactionNestingLevel();
                }

                public function postUpdate(PostUpdateEventArgs $args): void
                {
                    $this->trace[] = 'postUpdate '.$args->getObjectManager()->getConnection()->getTransactionNestingLevel();
                }
            };
            $em->getEventManager()->addEventListener([Events::onFlush, Events::postUpdate], $watcher);

            if ($killTheInner) {
                $em->getEventManager()->addEventListener([Events::onFlush], new class {
                    private int $seen = 0;

                    public function onFlush(): void
                    {
                        if (++$this->seen === 2) {
                            throw new \DomainException('the inner flush is refused');
                        }
                    }
                });
            }

            $em->getEventManager()->addEventListener([Events::postUpdate], new class($em, $aside) {
                private bool $ran = false;

                public function __construct(private readonly EntityManagerInterface $em, private readonly Article $aside)
                {
                }

                public function postUpdate(): void
                {
                    if ($this->ran) {
                        return;
                    }

                    $this->ran = true;
                    $this->aside->title = 'changed from inside';

                    try {
                        $this->em->flush();
                    } catch (\DomainException) {
                        // what the application does when a listener refuses its inner flush
                    }
                }
            });

            $first->title = 'first, edited';
            $second->title = 'second, edited';
            $em->flush();

            $where = $killTheInner ? 'with a dead inner flush' : 'with a live one';

            self::assertSame('onFlush 0', $trace[0] ?? null, sprintf('%s: onFlush is dispatched inside the transaction now', $where));
            self::assertSame('postUpdate 1', $trace[1] ?? null, sprintf('%s: a flush event no longer arrives one level below its own onFlush', $where));
            self::assertSame('onFlush 1', $trace[2] ?? null, sprintf('%s: the inner flush did not push at the level its events arrive at', $where));

            if ($killTheInner) {
                // The inner one never opened a transaction, so the outer flush's next
                // event arrives at the level the dead inner pushed at — which is what
                // makes "at or below the current level" enough to throw it away.
                self::assertSame('postUpdate 1', $trace[3] ?? null, 'a dead inner flush left the level somewhere else than where it pushed');
                self::assertCount(4, $trace, 'with a dead inner flush there is nothing else to see');

                continue;
            }

            // The inner flush picks up whatever the outer one had not executed yet, so
            // how many events it runs is its own business; at what level they arrive is
            // the fact being pinned.
            self::assertSame('postUpdate 2', $trace[3] ?? null, 'a live inner flush no longer runs its own events deeper than its push');
            self::assertSame('postUpdate 1', $trace[array_key_last($trace)] ?? null, 'the outer flush came back at a different level than it left');
        }
    }

    /**
     * Backs the listener's handling of a failed flush.
     *
     * `UnitOfWork::commit()` closes the manager when anything inside its own try
     * fails — which is why the bundle's tests reopen one — but an exception raised from
     * `onFlush` happens before that try and leaves the manager usable. The two are
     * different recoveries, and a listener that assumed one of them would be wrong half
     * the time.
     */
    public function testAFailureInOnFlushLeavesTheManagerOpen(): void
    {
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $args): void
            {
                throw new \DomainException('a listener refused the flush');
            }
        });

        try {
            $this->em->persist(new Basket('never written'));
            $this->em->flush();
            self::fail('the listener should have stopped the flush');
        } catch (\DomainException) {
        }

        self::assertTrue($this->em->isOpen(), 'an exception from onFlush now closes the manager — the listener recovers from the wrong place');
    }

    /**
     * Backs the reflection in `AuditSubscriber` that asks whether a clear was partial.
     *
     * ORM 2 could clear one entity class at a time and said so through
     * `OnClearEventArgs::clearsAllEntities()`; ORM 3 removed both the partial clear and
     * the method. The listener asks the class rather than the version, so what matters
     * is that the answer stays consistent with the ORM in use.
     */
    public function testWhetherAPartialClearCanStillBeAskedAbout(): void
    {
        $args = new OnClearEventArgs($this->em);
        $major = (int) explode('.', \Composer\InstalledVersions::getPrettyVersion('doctrine/orm') ?? '3')[0];

        self::assertSame(
            $major < 3,
            method_exists($args, 'clearsAllEntities'),
            'the ORM changed its mind about partial clears; AuditSubscriber decides by whether this method exists',
        );
    }
}
