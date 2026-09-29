<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Relay;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * The records of a flush reach the writer in the order their facts ran in the connection's
 * log: a record by the first statement it describes, whatever kind of news it is -- an
 * entity's own row, what happened inside its collection, what its join rows went through.
 *
 * What the writer does with that order is its own (the ids it hands out, 1.3 C); this is the
 * order it is handed, and an order that is wrong here is wrong in every id built from it.
 *
 * The order expected is read from the log itself, statement by statement, and not written down:
 * Doctrine runs a flush's UPDATEs class by class, in an order ORM 2 and ORM 3 do not share -- a
 * crate's line before a relay on one, after it on the other. Where the line runs first the
 * record made of it alone is the one whose place is being tested.
 */
final class InWhatOrderTheRecordsAreWrittenTest extends DoctrineTestCase
{
    public function testARecordOfWhatHappenedInsideACollectionGoesWhereItsStatementRan(): void
    {
        // The crate has no row of its own changed: its record is its line's, and it goes where
        // the line's UPDATE ran, before the relay's or after it.
        $crate = new Crate('C-1');
        $crate->add($line = new CrateItem('apple'));
        $this->em->persist($crate);
        $this->em->persist($relay = new Relay('first'));
        $this->em->flush();
        $this->gateway->documents = [];
        $from = $this->statements->position();

        $line->quantity = 2;
        $relay->name = 'second';
        $this->em->flush();

        $ran = $this->recordsInTheOrderTheyRan($from, ['line' => ['C-1' => $line]]);
        self::assertCount(2, $ran, 'the premise: both ran');
        self::assertSame($ran, $this->said());
    }

    public function testEveryKindOfRecordGoesWhereItsFirstFactRanAcrossANestedFlush(): void
    {
        // One flush writes a crate's line and a relay; the relay's postUpdate runs a nested flush
        // that writes another crate's line; then the outer flush writes an article's join row.
        // Four records -- an owner's lines, an entity's row, a nested flush's line, an owner's
        // links -- and three moments: the outer flush's, the nested one's, the outer's again.
        $this->em->persist($first = new Crate('C-1'));
        $first->add($line = new CrateItem('apple'));
        $this->em->persist($second = new Crate('C-2'));
        $second->add($other = new CrateItem('pear'));
        $this->em->persist($relay = new Relay('first'));
        $this->em->persist($tag = new Tag('php'));
        $this->em->persist($article = new Article('One'));
        $this->em->flush();
        $this->gateway->documents = [];

        $this->ahead(Events::postUpdate, new class($this->em, $relay, $other) {
            private bool $ran = false;

            public function __construct(private readonly EntityManagerInterface $em, private readonly Relay $relay, private readonly CrateItem $other)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->relay || $this->ran) {
                    return;
                }

                $this->ran = true;
                $this->other->quantity = 5;
                $this->em->flush();
            }
        });
        $from = $this->statements->position();

        $line->quantity = 2;
        $relay->name = 'second';
        $article->tags->add($tag);
        $this->em->flush();

        $ran = $this->recordsInTheOrderTheyRan($from, ['line' => ['C-1' => $line, 'C-2' => $other]]);
        self::assertCount(4, $ran, 'the premise: all four ran');
        self::assertSame('article', end($ran), 'the premise: the join row ran after the nested flush');
        self::assertSame($ran, $this->said());
    }

    public function testTheRecordsOfAFlushWrittenLateKeepTheOrderTheirFactsRanIn(): void
    {
        // The flush's publishing swallowed: its records are written by the next flush, before
        // that one's own, in the order their own statements ran.
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($line = new CrateItem('apple'));
        $this->em->persist($relay = new Relay('first'));
        $this->em->flush();
        $this->gateway->documents = [];

        $manager = $this->em->getEventManager();
        $ours = array_values(array_filter($manager->getListeners(Events::postFlush), static fn (object $one): bool => $one instanceof AuditSubscriber));
        self::assertCount(1, $ours);
        $manager->removeEventListener([Events::postFlush], $ours[0]);
        $from = $this->statements->position();

        $line->quantity = 2;
        $relay->name = 'second';
        $this->em->flush();
        self::assertSame([], $this->documents(), 'the premise: publishing never ran for that flush');
        $manager->addEventListener([Events::postFlush], $ours[0]);
        $ran = $this->recordsInTheOrderTheyRan($from, ['line' => ['C-1' => $line]]);

        $relay->name = 'third';
        $this->em->flush();

        self::assertCount(2, $ran, 'the premise: both ran in the swallowed flush');
        self::assertSame([...$ran, 'relay'], $this->said());
    }

    /**
     * What the log ran since a position, as the records it should make: each statement named by
     * whose record it is -- a line's by its crate, a join row's by its article -- in the order of
     * the first statement of each.
     *
     * @param array{line: array<string, CrateItem>} $whose
     *
     * @return list<string>
     */
    private function recordsInTheOrderTheyRan(int $from, array $whose): array
    {
        $crateOf = [];

        foreach ($whose['line'] as $code => $line) {
            $crateOf[(string) $line->id] = 'crate '.$code;
        }

        $ran = [];

        for ($at = $from + 1, $to = $this->statements->position(); $at <= $to; ++$at) {
            $statement = $this->statements->statement($at);

            if ($statement === null || !preg_match('/^\s*(UPDATE|INSERT INTO|DELETE FROM)\s+(\w+)/i', $statement['sql'], $m)) {
                continue;
            }

            $params = array_values($statement['params']);
            $name = match (strtolower($m[2])) {
                'crateitem' => $crateOf[(string) end($params)] ?? null,
                'relay' => 'relay',
                'article_tag' => 'article',
                default => null,
            };

            if ($name !== null && !\in_array($name, $ran, true)) {
                $ran[] = $name;
            }
        }

        return $ran;
    }

    /** @return list<string> */
    private function said(): array
    {
        return array_map(static fn (array $d): string => $d['objectType'] === 'crate' ? 'crate '.$d['objectId'] : $d['objectType'], $this->documents());
    }

    private function ahead(string $event, object $listener): void
    {
        $manager = $this->em->getEventManager();
        $there = $manager->getListeners($event);

        foreach ($there as $one) {
            $manager->removeEventListener([$event], $one);
        }

        $manager->addEventListener([$event], $listener);

        foreach ($there as $one) {
            $manager->addEventListener([$event], $one);
        }
    }
}
