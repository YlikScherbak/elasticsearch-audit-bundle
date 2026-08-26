<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Misdeclared;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Reaction;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
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
