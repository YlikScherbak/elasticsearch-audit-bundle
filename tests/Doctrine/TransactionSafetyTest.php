<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Misdeclared;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Reaction;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * What the audit trail says must have happened. Records are sent once the
 * transaction committed, a rolled-back flush leaves no trace, and a mistake in
 * an audit declaration is the writer's failure to handle — not the flush's.
 */
final class TransactionSafetyTest extends DoctrineTestCase
{
    public function testARolledBackFlushLeavesNoRecord(): void
    {
        // A listener behind ours that fails the flush after the INSERT ran.
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(LifecycleEventArgs $args): void
            {
                throw new \RuntimeException('something else in the flush broke');
            }
        });

        try {
            $this->em->persist(new Article('Hello'));
            $this->em->flush();
            self::fail('the flush should have failed');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->documents(), 'the history must not describe a state the database never had');
    }

    public function testAFlushSomebodyElseAbortedInOnFlushDoesNotSilenceEveryFlushAfterIt(): void
    {
        // UnitOfWork::commit() dispatches onFlush, then beginTransaction(), and only
        // then enters the try whose catch calls close(). A listener behind ours that
        // throws in onFlush — a validation veto, the usual reason — therefore leaves
        // the flush with no onClear, no postFlush and an open manager. Everything this
        // listener collected stays, and so does its idea that a flush is running: every
        // flush after this one looks nested, publishes nothing, and the audit trail is
        // silent for the rest of the process without one line anywhere saying so.
        $veto = new class {
            public bool $angry = true;

            public function onFlush(): void
            {
                if ($this->angry) {
                    throw new \DomainException('this entity may not be saved');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        try {
            $this->em->persist(new Article('Refused'));
            $this->em->flush();
            self::fail('the flush should have failed');
        } catch (\DomainException) {
        }

        // The manager is still open — Doctrine never reached its own failure path — so
        // the application carries on, and so must the history.
        self::assertTrue($this->em->isOpen(), 'Doctrine did not close the manager, and this test is about what happens next');

        $veto->angry = false;
        $this->em->persist(new Article('Saved'));
        $this->em->flush();

        // Two, not one: the manager stayed open, so Doctrine still holds the insert the
        // vetoed flush scheduled and writes it now. Both rows are in the database and
        // both belong in the history — what must not happen is the nothing this
        // produced before.
        $documents = $this->documents();

        self::assertCount(2, $documents, 'the flush after an aborted one is still audited');
        self::assertSame(['Refused', 'Saved'], array_map(static fn (array $d): mixed => $d['changes']['title']['new'], $documents));
    }

    public function testAnInnerFlushSomebodyElseAbortedDoesNotStrandTheOuterFlushesRecords(): void
    {
        // The mirror of the test above, and a hole the fix for it opened. A nested flush
        // pushes a level of its own; if a listener behind this one throws in *its*
        // onFlush and the application catches it, that level is never popped. postFlush
        // popped the top of the stack blindly, saw one left and published nothing — so
        // the outer flush, which did commit, wrote no history at all, and the flush after
        // it read the stack as abandoned and dropped what was collected.
        $veto = new class {
            public int $seen = 0;

            public function onFlush(): void
            {
                // The outer flush passes; the inner one is refused.
                if (++$this->seen === 2) {
                    throw new \DomainException('this entity may not be saved');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        $em = $this->em;
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class($em) {
            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em)
            {
            }

            public function postPersist(): void
            {
                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // The application copes and carries on, which is the whole point.
                }
            }
        });

        $this->em->persist(new Article('Hello'));
        $this->em->flush();

        self::assertCount(1, $this->documents(), 'the outer flush committed, so its record is history');
    }

    public function testRecordsAreSentAfterTheCommit(): void
    {
        $nestingAtWrite = null;
        $connection = $this->em->getConnection();
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(LifecycleEventArgs $args): void
            {
                // still inside the transaction here
            }
        });

        $this->gateway->respondToSearch = null;
        $this->gateway->onIndex = static function () use (&$nestingAtWrite, $connection): void {
            $nestingAtWrite = $connection->getTransactionNestingLevel();
        };

        $this->em->persist(new Article('Hello'));
        $this->em->flush();

        self::assertSame(0, $nestingAtWrite, 'the write happened after the commit, not inside the transaction');
        self::assertCount(1, $this->documents());
    }

    /**
     * The documented boundary, made executable: the listener writes when the flush's
     * own transaction commits, and it cannot know about a wider transaction around it.
     * Roll that one back and the database forgets the row while the index keeps the
     * record — a history entry for a state that never was. This test exists to fail
     * the day that stops being true, and the two after it show the recipe that closes
     * the gap today. A transaction-aware delivery (an outbox) is post-1.0 work.
     */
    public function testAnOuterTransactionRolledBackLeavesTheRecordBehind(): void
    {
        $this->em->getConnection()->beginTransaction();

        try {
            $this->em->persist(new Article('Never committed'));
            $this->em->flush();
        } finally {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
        }

        self::assertCount(1, $this->documents(), 'the limitation, stated as a fact: the record went out when the inner flush committed');
        self::assertSame([], $this->em->getRepository(Article::class)->findAll(), 'while the database kept nothing');
    }

    public function testTheGapIsTheSameWhicheverWayTheTwoAreNested(): void
    {
        // wrapInTransaction() around coalesce(), rather than the other way round. The
        // frame and the transaction are independent of each other, so both orders reach
        // the same place — but "both orders" is exactly the kind of claim that is assumed
        // rather than checked, and an audit trail whose correctness depended on which one
        // an application happened to write would be a trap nobody could see.
        $buffer = new FrameBuffer();
        $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer));

        try {
            $this->em->wrapInTransaction(function () use ($frame): void {
                $frame->coalesce(function (): void {
                    $this->em->persist(new Article('Never committed'));
                    $this->em->flush();
                    throw new \RuntimeException('the business operation failed');
                });
            });
            self::fail('the operation should have failed');
        } catch (\RuntimeException) {
            $this->em->clear();
        }

        // coalesce() closes its frame in a finally, so the records went out when the
        // inner flush committed — the documented limitation, reached from the other
        // direction. The recipe below is what closes it, and it works the same way here:
        // it is reset() that decides, not the nesting.
        self::assertCount(1, $this->documents(), 'the same limitation, whichever way round the two are written');
        self::assertSame([], $this->em->getRepository(Article::class)->findAll());
    }

    public function testTheFrameRecipeClosesThatGap(): void
    {
        // What the README prescribes for an application that owns the wider
        // transaction: hold the records in a frame, and drop them if it rolls back.
        $buffer = new FrameBuffer();
        $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer));

        $frame->begin();
        $this->em->getConnection()->beginTransaction();

        try {
            $this->em->persist(new Article('Never committed'));
            $this->em->flush();
            throw new \RuntimeException('the business operation failed');
        } catch (\RuntimeException) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
            $frame->reset(); // rolled back: the records describe nothing that happened
        }

        self::assertSame([], $this->documents(), 'reset() drops what the rollback undid');
    }

    public function testTheSameRecipeWritesWhenTheOuterTransactionCommits(): void
    {
        $buffer = new FrameBuffer();
        $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer));

        $frame->begin();
        $this->em->getConnection()->beginTransaction();
        $this->em->persist(new Article('Committed'));
        $this->em->flush();
        $this->em->getConnection()->commit();
        $frame->end(); // committed: now the history may speak

        self::assertCount(1, $this->documents());
    }

    public function testAFailedFlushDoesNotLeakAnElementItNeverInsertedIntoTheNextOne(): void
    {
        $shipment = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment('SH-9');
        $this->em->persist($shipment);
        $this->em->flush();
        $this->gateway->documents = [];

        $poison = new class {
            public bool $armed = true;

            public function postPersist(LifecycleEventArgs $args): void
            {
                if ($this->armed) {
                    throw new \RuntimeException('boom');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postPersist], $poison);

        try {
            $shipment->add(new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine('nut', 1)); // the INSERT is rolled back
            $this->em->flush();
        } catch (\RuntimeException) {
        }

        $this->reopen();
        $poison->armed = false;

        $again = $this->em->find(\Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment::class, $shipment->id);
        $again->reference = 'SH-9b';
        $this->em->flush();

        self::assertCount(1, $this->documents());
        self::assertSame(['reference'], array_keys($this->documents()[0]['changes']), 'a line the database never had must not appear as added');
    }

    public function testAFailedFlushDoesNotLeakIntoTheNextOne(): void
    {
        $poison = new class {
            public bool $armed = true;

            public function postPersist(LifecycleEventArgs $args): void
            {
                if ($this->armed) {
                    throw new \RuntimeException('boom');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postPersist], $poison);

        try {
            $this->em->persist(new Article('First'));
            $this->em->flush();
        } catch (\RuntimeException) {
        }

        // Doctrine closed the manager; the application gets a fresh one (resetManager) and flushes again.
        $this->reopen();
        $poison->armed = false;

        $this->em->persist(new Article('Second'));
        $this->em->flush();

        self::assertCount(1, $this->documents());
        self::assertSame(['old' => null, 'new' => 'Second'], $this->documents()[0]['changes']['title']);
    }

    /**
     * ORM 2 only: clearing one entity class while a flush is running leaves that flush
     * to commit the rest, so its records are history and must survive the clear.
     *
     * Driven by hand, because the point is a clear arriving between the lifecycle events
     * and postFlush — a window a normal flush does not expose.
     */
    public function testAPartialClearKeepsTheRecordsOfAFlushThatIsStillRunning(): void
    {
        self::skipUnlessPartialClearsExist();

        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $listener = $this->detachedListener();
        $listener->postPersist(new PostPersistEventArgs($article, $this->em));
        $listener->onClear(new OnClearEventArgs($this->em, Article::class)); // @phpstan-ignore-line ORM 2 signature
        $listener->postFlush(new PostFlushEventArgs($this->em));

        self::assertCount(1, $this->documents(), 'the other classes in that flush still committed');
    }

    /**
     * ORM 2 only: a closed manager means the flush failed. A partial clear does not make
     * its records true, and inventing history is worse than missing it.
     */
    public function testAPartialClearOnAClosedManagerStillDropsThem(): void
    {
        self::skipUnlessPartialClearsExist();

        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $listener = $this->detachedListener();
        $listener->postPersist(new PostPersistEventArgs($article, $this->em));
        $this->em->close();
        $listener->onClear(new OnClearEventArgs($this->em, Article::class)); // @phpstan-ignore-line ORM 2 signature
        $listener->postFlush(new PostFlushEventArgs($this->em));

        self::assertSame([], $this->documents());
    }

    public function testAnEntityIdentifiedByAnAssociationIsAudited(): void
    {
        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $this->em->persist(new Reaction($article, 'like'));
        $this->em->flush();

        $document = $this->lastDocument();

        self::assertSame('reaction', $document['objectType']);
        self::assertSame($article->id.'|like', $document['objectId'], 'a part holding no delimiter is written as it always was');
        self::assertSame(['old' => null, 'new' => 1], $document['changes']['count']);
    }

    public function testTwoCompositeKeysThatUsedToShareOneIdentityNoLongerDo(): void
    {
        // ["a|b", "c"] and ["a", "b|c"] both joined to a|b|c, so two entities answered to
        // one objectId and their histories were one history.
        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $this->em->persist(new Reaction($article, 'a|b'));
        $this->em->flush();

        self::assertSame($article->id.'|a\|b', $this->lastDocument()['objectId']);
    }

    public function testAMistakeInTheAuditDeclarationIsLoggedNotFatal(): void
    {
        $entity = new Misdeclared();
        $this->em->persist($entity);
        $this->em->flush();

        self::assertNotNull($entity->id, 'the business operation went through');
        self::assertSame([], $this->documents());
        self::assertCount(1, $this->logs);
        self::assertStringContainsString('"nope" is listed as always recorded', $this->logs[0]);
    }

    public function testWithTheThrowPolicyAMistakeInTheDeclarationAbortsTheFlush(): void
    {
        $this->em->getEventManager()->removeEventListener(\Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber::EVENTS, ...$this->listeners());
        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('"nope" is listed as always recorded');

        $this->em->persist(new Misdeclared());
        $this->em->flush();
    }

    private static function skipUnlessPartialClearsExist(): void
    {
        if (!method_exists(OnClearEventArgs::class, 'clearsAllEntities')) {
            self::markTestSkipped('Partial clears exist only in ORM 2; the lowest-dependencies CI job covers this.');
        }
    }

    /**
     * A listener of its own, not registered with the event manager, so the test decides
     * which events it sees and in which order.
     */
    private function detachedListener(): AuditSubscriber
    {
        return new AuditSubscriber($this->writer(FailurePolicy::Log), new AuditMetadataFactory(), skipEmptyUpdates: true);
    }

    private function persisted(Article $article): Article
    {
        $this->em->persist($article);
        $this->em->flush();

        return $article;
    }

    /**
     * @return list<object>
     */
    private function listeners(): array
    {
        return array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof \Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber,
        ));
    }
}
