<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

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
        self::assertSame($article->id.'|like', $document['objectId']);
        self::assertSame(['old' => null, 'new' => 1], $document['changes']['count']);
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
