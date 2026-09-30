<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;

/**
 * A flush whose publishing was swallowed -- a postFlush listener ahead of this one threw -- has
 * committed, and its records are written late, by the next flush at the connection's outermost
 * level. When the operation after it runs inside a frame and a transaction of its own, the late
 * records stay the committed operation's: its rollback and its frame's reset do not take them,
 * the frame does not merge them with its own records of the same object, and they keep the actor
 * and the moment of the flush that made them.
 *
 * Measured, and held: written when this was suspected of losing them (seed 395 of the model test
 * in the ORM 2.19 image), and it does not -- the late records wait for the next outermost flush,
 * which a sequence that ends there does not have.
 */
final class ALatePublicationInsideAnotherFrameTest extends DoctrineTestCase
{
    private const ALICE = '2026-09-21 10:00:00';
    private const BOB = '2026-09-22 15:30:00';

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function howTheOtherOperationEnds(): iterable
    {
        yield 'the other operation, creating another article, rolled back and its frame dropped' => [false, false];
        yield 'the other operation, creating another article, committed and its frame closed' => [true, false];
        yield 'the other operation, changing the same article, rolled back and its frame dropped' => [false, true];
        yield 'the other operation, changing the same article, committed and its frame closed' => [true, true];
    }

    #[DataProvider('howTheOtherOperationEnds')]
    public function testTheLateRecordsOfACommittedFlushAreItsOwnWhateverTheFrameTheyAreWrittenIn(bool $committed, bool $theSameArticle): void
    {
        $who = new class implements ActorResolverInterface {
            public ?string $actor = 'alice';

            public function resolve(): ?string
            {
                return $this->actor;
            }
        };
        $when = new class(self::ALICE) implements ClockInterface {
            public function __construct(public string $now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->now, new \DateTimeZone('UTC'));
            }
        };
        $this->actors = $who;
        $this->clock = $when;
        $buffer = new FrameBuffer();
        $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer, FailurePolicy::Log));

        $article = new Article('One');
        $this->em->persist($article);
        $this->em->flush();
        $written = \count($this->documents());

        // Alice's change, committed, and its publishing swallowed.
        $article->title = 'Two';
        $this->swallowingPostFlush(function (): void {
            $this->em->flush();
        });
        self::assertSame('Two', $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]), 'the premise: it committed');
        self::assertCount($written, $this->documents(), 'the premise: and nothing was written yet');

        // Bob's operation: an atomic frame, a transaction of the application's, a flush in it --
        // where the late records are written -- creating another article, or changing the same.
        $who->actor = 'bob';
        $when->now = self::BOB;
        $connection = $this->em->getConnection();
        $frame->begin(atomic: true);
        $connection->beginTransaction();
        if ($theSameArticle) {
            $article->title = 'Three';
        } else {
            $this->em->persist(new Article('Three'));
        }

        $this->em->flush();

        if ($committed) {
            $connection->commit();
            $frame->end();
        } else {
            // As an application does after it takes its operation back: Doctrine still holds what
            // the rollback undid.
            $connection->rollBack();
            $frame->reset();
            $this->em->clear();
        }

        // And a flush after it, of something else, with nothing in its way.
        $this->em->persist(new Article('Unrelated'));
        $this->em->flush();

        $said = array_values(array_filter(
            array_map(static fn (array $d): array => [$d['changes']['title']['new'] ?? null, $d['source'] ?? null, $d['loggedAt'] ?? null], \array_slice($this->documents(), $written)),
            // Of the two changes; the creation of the unrelated one aside.
            static fn (array $one): bool => \in_array($one[0], ['Two', 'Three'], true),
        ));

        $expected = [['Two', 'alice', self::ALICE]];

        if ($committed) {
            $expected[] = ['Three', 'bob', self::BOB];
        }

        self::assertSame($expected, $said, 'the committed change once, as Alice made it; Bob\'s apart, and only when his operation stood');
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
