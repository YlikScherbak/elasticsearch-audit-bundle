<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A record is about the row its INSERT made: the objectId of every document is the id that row was
 * given, and its values are the row's — whatever died before it, and in whatever order Doctrine
 * announced the rows.
 *
 * The listener once took the key of a row the database handed out from the order postPersist
 * announced rows in, counted against every INSERT without a key since the row memory last settled.
 * A flush that died in its first postPersist announced one row of two, and the next flush's rows
 * were matched one place back: one row's history was written under the other's id, and the other
 * had none (WhereDoctrineAsksForAGeneratedKeyTest pins what the connection says instead).
 */
final class AKeyBelongsToItsInsertTest extends DoctrineTestCase
{
    /**
     * The flushes that die before the one that is looked at, each a list of titles: each dies in
     * its first postPersist, after all its INSERTs ran.
     *
     * @return iterable<string, array{list<list<string>>, list<string>}>
     */
    public static function whatDiedBefore(): iterable
    {
        yield 'two rows died, then one, then one row commits' => [[['One', 'Two'], ['Three']], ['Unrelated']];
        yield 'two rows died, then one, then two rows commit' => [[['One', 'Two'], ['Three']], ['Unrelated', 'Second']];
        yield 'three rows died, then two rows commit' => [[['One', 'Two', 'Three']], ['Unrelated', 'Second']];
        yield 'nothing died, two rows commit' => [[], ['Unrelated', 'Second']];
    }

    /**
     * @param list<list<string>> $died
     * @param list<string>       $committed
     */
    #[DataProvider('whatDiedBefore')]
    public function testEachRecordIsAboutTheRowItsInsertMade(array $died, array $committed): void
    {
        $breaker = new class {
            public bool $on = true;

            public function postPersist(PostPersistEventArgs $args): void
            {
                if ($this->on && $args->getObject() instanceof Article) {
                    throw new \DomainException('the flush dies in its first postPersist');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postPersist], $breaker);

        foreach ($died as $titles) {
            foreach ($titles as $title) {
                $this->em->persist(new Article($title));
            }

            try {
                $this->em->flush();
                self::fail('the premise: the flush died');
            } catch (\DomainException) {
            }

            $this->reopen();
        }

        $breaker->on = false;
        $this->gateway->documents = [];
        $rows = [];

        foreach ($committed as $title) {
            $this->em->persist($rows[] = new Article($title));
        }

        $this->em->flush();

        self::assertSame(
            array_map(static fn (Article $row): array => [(string) $row->id, $row->title], $rows),
            array_map(static fn (array $d): array => [(string) $d['objectId'], $d['changes']['title']['new'] ?? null], $this->documents()),
            'each record under the id its row was given, with that row\'s title',
        );
        $this->assertTheRowsSay($rows);
    }

    /**
     * A flush started from postPersist: the rows are announced A, C, B and their INSERTs run A, B,
     * C. Each record is still its own row's.
     */
    public function testAFlushStartedFromPostPersistDoesNotTradeKeysWithTheOuterOne(): void
    {
        $em = $this->em;
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class($em) {
            private bool $nested = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em)
            {
            }

            public function postPersist(PostPersistEventArgs $args): void
            {
                if (!$this->nested && $args->getObject() instanceof Article && $args->getObject()->title === 'A') {
                    $this->nested = true;
                    $this->em->persist(new Article('C'));
                    $this->em->flush();
                }
            }
        });

        $this->em->persist($a = new Article('A'));
        $this->em->persist($b = new Article('B'));
        $this->em->flush();
        $c = $this->em->getRepository(Article::class)->findOneBy(['title' => 'C']);
        self::assertNotNull($c);

        $said = [];

        foreach ($this->documents() as $document) {
            $said[(string) $document['objectId']] = $document['changes']['title']['new'] ?? null;
        }

        ksort($said);
        $expected = [(string) $a->id => 'A', (string) $b->id => 'B', (string) $c->id => 'C'];
        ksort($expected);

        self::assertSame($expected, $said, 'each record under its own row\'s id');
        $this->assertTheRowsSay([$a, $b, $c]);
    }

    /**
     * A row rolled back and a new one given the same number: the new row's record is the new row's,
     * and nothing of the one taken back is in it.
     */
    public function testARowThatTakesTheNumberOfOneRolledBackIsRecordedAsItself(): void
    {
        $this->em->persist($kept = new Article('Kept'));
        $this->em->flush();
        $this->gateway->documents = [];

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->em->persist($gone = new Article('Taken back'));
        $this->em->flush();
        $number = $gone->id;
        $connection->rollBack();
        $this->reopen();
        $this->gateway->documents = [];

        $this->em->persist($again = new Article('Again'));
        $this->em->flush();

        if (\Borsche\ElasticsearchAuditBundle\Tests\TestConnection::isSqlite()) {
            self::assertSame($number, $again->id, 'the premise: SQLite hands the number out again');
        }

        self::assertSame(
            [[(string) $again->id, 'Again']],
            array_map(static fn (array $d): array => [(string) $d['objectId'], $d['changes']['title']['new'] ?? null], $this->documents()),
        );
        $this->assertTheRowsSay([$kept, $again]);
    }

    /** @param list<Article> $rows */
    private function assertTheRowsSay(array $rows): void
    {
        foreach ($rows as $row) {
            self::assertSame($row->title, $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$row->id]), 'the premise: the row holds what its object does');
        }
    }
}
