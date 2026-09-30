<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;

/**
 * A flush whose publishing was swallowed -- a postFlush listener ahead of this one threw -- has
 * committed, and its records wait for the next flush to write them late. When the flush after it
 * fails -- the database refused a statement, the manager closed -- and the application goes on
 * with a fresh manager, the next flush finds two flushes that never came back: one committed, one
 * not. What the committed one collected is its history, whatever became of the one after it.
 *
 * Held with the flush after it failing with no transaction open and inside the application's.
 * Seed 395 of the model test drops such a history in the ORM 2.19 image alone, where the failing
 * flush is ORM 2.19's own (a join row of a collection inserted twice) inside an atomic frame: the
 * next flush finds nothing collected. ORM 2.20 and 3 write it late, as here.
 */
final class ALateFlushBehindAFailedOneTest extends DoctrineTestCase
{
    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function whereTheNextFlushFails(): iterable
    {
        yield 'with no transaction open' => [false, false];
        yield 'inside a transaction of the application\'s, which it then rolls back' => [true, false];
        yield 'inside an atomic frame and a transaction of the application\'s, both let go' => [true, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whereTheNextFlushFails')]
    public function testWhatACommittedFlushCollectedIsWrittenThoughTheFlushAfterItFailed(bool $inATransaction, bool $inAFrame): void
    {
        $frame = null;

        if ($inAFrame) {
            $buffer = new FrameBuffer();
            $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer, FailurePolicy::Log));
        }

        $article = new Article('One');
        $tag = new Tag('x');
        $this->em->persist($article);
        $this->em->persist($tag);
        $this->em->flush();
        $written = \count($this->documents());
        $this->unownedStatementsAreExpected = true;

        // Committed, and its publishing swallowed.
        $article->title = 'Two';
        $this->swallowingPostFlush(function (): void {
            $this->em->flush();
        });
        self::assertSame('Two', $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]), 'the premise: it committed');

        // The next flush refused by the database: the link is there already, behind Doctrine's
        // back, and its INSERT breaks the join table's key. The manager closes.
        $native = $this->em->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->exec(sprintf('INSERT INTO article_tag (article_id, tag_id) VALUES (%d, %d)', $article->id, $tag->id));
        $article->tags->add($tag);

        // And work of its own that runs before the statement it fails on: an audited row
        // inserted, taken back with the rest.
        $this->em->persist(new Article('Taken back'));
        $connection = $this->em->getConnection();

        $frame?->begin(atomic: true);

        if ($inATransaction) {
            $connection->beginTransaction();
        }

        try {
            $this->em->flush();
            self::fail('the premise: the database refused the flush');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
        } finally {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            // As the model test's step does after the application takes its operation back.
            if ($frame !== null) {
                $this->em->clear();
                $frame->reset();
            }
        }

        self::assertFalse($this->em->isOpen(), 'the premise: the manager closed');

        // The application goes on with a fresh manager, as ManagerRegistry::resetManager() gives it.
        $this->reopen();
        $this->em->persist(new Article('Unrelated'));
        $this->em->flush();

        $titles = array_map(static fn (array $d): mixed => $d['changes']['title']['new'] ?? null, \array_slice($this->documents(), $written));
        self::assertContains('Two', $titles, 'the committed change is in the history');
    }

    private function swallowingPostFlush(\Closure $flush): void
    {
        $breaker = new class {
            public function postFlush(): void
            {
                throw new \DomainException('somebody else exploded in postFlush');
            }
        };
        $manager = $this->em->getEventManager();
        $ours = $manager->getListeners(Events::postFlush);

        foreach ($ours as $listener) {
            $manager->removeEventListener([Events::postFlush], $listener);
        }

        $manager->addEventListener([Events::postFlush], $breaker);

        foreach ($ours as $listener) {
            $manager->addEventListener([Events::postFlush], $listener);
        }

        try {
            $flush();
        } catch (\DomainException) {
            // the application copes
        } finally {
            $manager->removeEventListener([Events::postFlush], $breaker);
        }
    }
}
