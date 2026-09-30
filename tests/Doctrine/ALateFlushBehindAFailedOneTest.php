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
 * Held with the flush after it failing with no transaction open, inside the application's, and
 * inside an atomic frame as well, the manager cleared and replaced. Seed 395 of the model test
 * found the last of these in the ORM 2.19 image, whose own failure closes the manager there; it
 * is any ORM's once a flush fails in such a frame.
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

    /**
     * @return iterable<string, array{bool}>
     */
    public static function howTheFailedFlushWasLetGo(): iterable
    {
        yield 'on its own' => [false];
        yield 'inside an atomic frame and a transaction of the application\'s, both let go and the manager cleared' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('howTheFailedFlushWasLetGo')]
    public function testWhatHappenedInsideACollectionIsWrittenThoughTheManagerThatSawItClosed(bool $inAFrame): void
    {
        $frame = null;

        if ($inAFrame) {
            $buffer = new FrameBuffer();
            $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer, FailurePolicy::Log));
        }

        // A change inside a collection is its owner's record, and the owner is looked up to name
        // it. Found late, by a flush after a failed one whose manager the application cleared and
        // replaced, the owner is in no manager: the reading that counts what is owed loads nothing
        // and found no owner, the count was nothing, and a committed change's late records were
        // dropped as nothing collected -- though the reading that writes would have written them.
        // Seed 395 of the model test, in the ORM 2.19 image.
        $crate = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate('C-1');
        $article = new Article('One');
        $tag = new Tag('x');
        $this->em->persist($crate);
        $this->em->persist($article);
        $this->em->persist($tag);
        $this->em->flush();
        $written = \count($this->documents());
        $this->unownedStatementsAreExpected = true;

        // A line added to the crate: committed, and its publishing swallowed.
        $crate->add(new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem('SKU-new'));
        $this->swallowingPostFlush(function (): void {
            $this->em->flush();
        });
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem WHERE sku = ?', ['SKU-new']), 'the premise: it committed');

        // The next flush refused by the database, the manager closed, a fresh one.
        $native = $this->em->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->exec(sprintf('INSERT INTO article_tag (article_id, tag_id) VALUES (%d, %d)', $article->id, $tag->id));
        $article->tags->add($tag);
        $connection = $this->em->getConnection();

        if ($frame !== null) {
            $frame->begin(atomic: true);
            $connection->beginTransaction();
        }

        try {
            $this->em->flush();
            self::fail('the premise: the database refused the flush');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
        } finally {
            if ($frame !== null) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $this->em->clear();
                $frame->reset();
            }
        }

        $this->reopen();
        $this->em->persist(new Article('Unrelated'));
        $this->em->flush();

        $crates = array_values(array_filter(\array_slice($this->documents(), $written), static fn (array $d): bool => $d['objectType'] === 'crate'));
        self::assertCount(1, $crates, 'the committed line is in the crate\'s history');
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
