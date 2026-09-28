<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Press;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Relay;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Vehicle;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Switchboard;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\SwitchMode;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * An entity's own history, held to what its rows did.
 *
 * Written before step 5 reads an entity's fields from the connection's log instead of
 * Doctrine's change set, so that the step was handed its targets: where the two agreed, the
 * test says it and step 5 had to keep it; where they did not, the test said what was recorded
 * then and what has to be, pinned, and mending it took the pin off. The last pins, an owning
 * collection's, came off when its join rows became the facts it is told from (5.3).
 */
final class WhatTheRowSaysOfAnEntityTest extends DoctrineTestCase
{
    public function testTypedColumnsAreRecordedAsTheObjectHoldsThem(): void
    {
        // A boolean, a backed enum and a moment, created and then changed. Read from the
        // row, a boolean may be 0 or 1, an enum is its string and a moment a string to the
        // second: what the history says is what the object says, as it does today.
        $this->em->persist($board = new Switchboard());
        $this->em->flush();

        self::assertSame([[
            'lit' => ['old' => null, 'new' => false],
            'mode' => ['old' => null, 'new' => 'manual'],
            'checkedAt' => ['old' => null, 'new' => '2026-09-25 10:00:00'],
        ]], $this->changes(), 'the creation');

        $this->gateway->documents = [];
        $board->lit = true;
        $board->mode = SwitchMode::Automatic;
        $this->em->flush();

        self::assertSame([[
            'lit' => ['old' => false, 'new' => true],
            'mode' => ['old' => 'manual', 'new' => 'automatic'],
        ]], $this->changes(), 'the change');
    }

    public function testAChangeThatNeverReachedTheRowIsNoChange(): void
    {
        // The column holds the moment to the second, and the object is given the same second
        // with half of one more. Doctrine sees a new value and writes it; the row holds what it
        // held. A history of the row has nothing to say, and says nothing (5.2c; until then it
        // said what the object said).
        $this->em->persist($board = new Switchboard());
        $this->em->flush();
        $this->gateway->documents = [];

        $board->checkedAt = new \DateTimeImmutable('2026-09-25 10:00:00.500000');
        $this->em->flush();

        $this->pinned(
            expected: [],
            today: null,
        );
    }

    public function testAChangeTakenBackIsNotWhereTheNextChangeStarts(): void
    {
        // The row holds 'One'. An UPDATE to 'title 0' runs inside a savepoint of the
        // application's, rolled back by a listener ahead of this one: no record of it, and
        // Doctrine believes the row took it. The next change, to 'title 1', commits -- from
        // 'One', what the row held. And the one after that, to 'title 2', from 'title 1': the
        // history goes on from the row, not only corrects one side of one record.
        $article = $this->persisted(new Article('One'));
        $connection = $this->em->getConnection();

        $taking = new class($connection) {
            private ?object $inside = null;

            public function __construct(private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function preUpdate(LifecycleEventArgs $args): void
            {
                $this->inside = $args->getObject();
                $this->connection->beginTransaction();
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->inside) {
                    $this->inside = null;
                    $this->connection->rollBack();
                }
            }
        };
        $this->ahead([Events::preUpdate, Events::postUpdate], $taking);

        $article->title = 'title 0';
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::preUpdate, Events::postUpdate], $taking);

        self::assertSame('One', $connection->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]), 'the premise: the change was taken back');
        self::assertSame([], $this->titles(), 'and it has no record');

        $article->title = 'title 1';
        $this->em->flush();
        $article->title = 'title 2';
        $this->em->flush();

        $this->pinned(
            expected: [['One', 'title 1'], ['title 1', 'title 2']],
            today: null,
            said: $this->titles(),
        );
    }

    public function testALinkTakenBackToASavepointIsNotRecorded(): void
    {
        // One flush: an article's title changes, a tag is added to another article, and a
        // third is removed. Doctrine writes updates, then collections, then deletions; the
        // application opens a savepoint in the title's postUpdate and rolls back to it in the
        // removal's postRemove -- so the join row is taken back, and the DELETE with it, which
        // ran inside the savepoint too. The title stands. The article whose only change was
        // the link has no history of it, and the removal none either.
        $php = new Tag('php');
        $this->em->persist($php);
        $titled = $this->persisted(new Article('Titled'));
        $linked = $this->persisted(new Article('Linked'));
        $removed = $this->persisted(new Article('Removed'));
        [$titledId, $removedId] = [$titled->id, $removed->id];
        $connection = $this->em->getConnection();

        $this->em->getEventManager()->addEventListener([Events::postUpdate, Events::postRemove], new class($connection, $titled) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly Article $titled)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                // Once, after the title's UPDATE: the article whose only change is the link is
                // announced too, and a second savepoint would outlive the flush.
                if ($args->getObject() === $this->titled) {
                    $this->connection->beginTransaction();
                }
            }

            public function postRemove(LifecycleEventArgs $args): void
            {
                $this->connection->rollBack();
            }
        });

        $titled->title = 'Titled, again';
        $linked->tags->add($php);
        $this->em->remove($removed);
        $this->em->flush();

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_tag WHERE article_id = ?', [$linked->id]), 'the premise: the link was taken back');
        self::assertSame(['Titled, again', 1], [$connection->fetchOne('SELECT title FROM Article WHERE id = ?', [$titledId]), (int) $connection->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$removedId])], 'the premise: the title committed, and the DELETE was taken back with the link');

        $this->pinned(
            expected: [['article', (string) $titledId, 'update']],
            // The removal: its DELETE was taken back, and the record tied to it goes. The link:
            // its join row was taken back too, and is no fact (5.3; until then the collection's
            // change was read from Doctrine, which believed the row written).
            today: null,
            said: $this->events(),
        );
    }

    public function testALinkANestedFlushCarriedOutForTheOuterOneIsOneChange(): void
    {
        // The outer flush adds a tag to an article and changes its title; a listener ahead of
        // this one runs a nested flush from the article's postUpdate, which carries out what
        // the outer flush still had scheduled -- the join row among it. One link, one change of
        // the collection, however many times it is announced.
        $php = new Tag('php');
        $this->em->persist($php);
        $article = $this->persisted(new Article('One'));

        $nested = new class($this->em, $article) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $article)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->article && !$this->ran) {
                    $this->ran = true;
                    $this->em->flush();
                }
            }
        };
        $this->ahead([Events::postUpdate], $nested);

        $article->tags->add($php);
        $article->title = 'Two';
        $this->em->flush();

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM article_tag WHERE article_id = ?', [$article->id]), 'the premise: one link');
        self::assertSame('Two', $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]), 'the premise: and the title');

        // The join row is in the log, and the collection is read from it (5.3). Until then there
        // was none at all: the nested flush's postCommitCleanup() emptied the outer flush's
        // collection updates, and the collection's change was read from there.
        $this->pinned(
            expected: [['old' => [], 'new' => ['php']]],
            today: null,
            said: array_values(array_filter(array_map(static fn (array $d): mixed => $d['changes']['tags'] ?? null, $this->documents()))),
        );
        $this->logs = []; // the warning it says so with, which is today's too
    }

    public function testTheLinksOfANestedFlushTakenBackAreNotRecordedAndTheOuterChangeIs(): void
    {
        // The outer flush changes an article's title; a listener ahead of this one, after that
        // UPDATE, tags the article and runs a nested flush inside a savepoint of the
        // application's, which it rolls back once the nested flush has written its join row. The
        // title commits and the link does not -- and Doctrine, whose nested flush succeeded,
        // believes it written. A savepoint the application opens around an entity's statement
        // never holds a join row, which Doctrine writes after every UPDATE; one it opens around
        // a nested flush does.
        $php = new Tag('php');
        $this->em->persist($php);
        $article = $this->persisted(new Article('One'));
        $connection = $this->em->getConnection();

        $nested = new class($this->em, $article, $php) {
            public int $linksWritten = -1;
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $article, private readonly Tag $php)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->article || $this->ran) {
                    return;
                }

                $this->ran = true;
                $connection = $this->em->getConnection();
                $connection->beginTransaction();
                $this->article->tags->add($this->php);
                $this->em->flush();
                $this->linksWritten = (int) $connection->fetchOne('SELECT COUNT(*) FROM article_tag WHERE article_id = ?', [$this->article->id]);
                $connection->rollBack();
            }
        };
        $this->ahead([Events::postUpdate], $nested);

        $article->title = 'Two';
        $this->em->flush();

        self::assertSame(1, $nested->linksWritten, 'the premise: the nested flush wrote its join row');
        self::assertSame(
            ['Two', 0],
            [$connection->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]), (int) $connection->fetchOne('SELECT COUNT(*) FROM article_tag WHERE article_id = ?', [$article->id])],
            'the premise: the title committed, and the join row was taken back',
        );

        // Already so, by the collection's snapshot. 5.3 reads the link from the join rows, where
        // the nested flush's INSERT is, and what keeps it out then is the savepoint it ran in
        // being taken back -- the guard is of that.
        $this->pinned(
            expected: [['title' => ['old' => 'One', 'new' => 'Two'], 'status' => ['old' => 'draft', 'new' => 'draft']]],
            today: null,
        );
    }

    public function testAfterALinkTakenBackTheNextOneStartsFromWhatTheRowsHold(): void
    {
        // A tag 'a' is added in a flush whose join row a savepoint of the application's takes
        // back -- opened after a title's UPDATE, rolled back in a removal's postRemove, as
        // above -- and a tag 'b' in the next flush. The collection holds both and the rows only
        // 'b': the second record is of a list that went from none to 'b', not from 'a' to both.
        // Not only is the taken-back link left out; the list the next one starts from is the
        // rows', not Doctrine's.
        $a = new Tag('a');
        $b = new Tag('b');
        $this->em->persist($a);
        $this->em->persist($b);
        $titled = $this->persisted(new Article('Titled'));
        $linked = $this->persisted(new Article('Linked'));
        $removed = $this->persisted(new Article('Removed'));
        $connection = $this->em->getConnection();

        $taking = new class($connection, $titled) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly Article $titled)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->titled) {
                    $this->connection->beginTransaction();
                }
            }

            public function postRemove(LifecycleEventArgs $args): void
            {
                $this->connection->rollBack();
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate, Events::postRemove], $taking);

        $titled->title = 'Titled, again';
        $linked->tags->add($a);
        $this->em->remove($removed);
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate, Events::postRemove], $taking);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_tag WHERE article_id = ?', [$linked->id]), 'the premise: the first link was taken back');
        $this->gateway->documents = [];

        $linked->tags->add($b);
        $this->em->flush();

        self::assertSame(['b'], array_map(
            static fn (mixed $id): mixed => $connection->fetchOne('SELECT label FROM Tag WHERE id = ?', [$id]),
            $connection->fetchFirstColumn('SELECT tag_id FROM article_tag WHERE article_id = ?', [$linked->id]),
        ), 'the premise: the rows hold the second link and only it');

        $this->pinned(
            expected: [['tags' => ['old' => [], 'new' => ['b']], 'status' => ['old' => 'draft', 'new' => 'draft']]],
            // From the join rows (5.3); until then from the collection's snapshot, which held the
            // link the rows did not, and said a -> a, b.
            today: null,
        );
    }

    public function testAReferenceIsShownAsItsRowStoodWhereTheReferenceWasWritten(): void
    {
        // A relay pointed at another, which a listener ahead of this one renames in a nested flush
        // after the first relay's UPDATE: the relay pointed at was called "second" when it was
        // pointed at. An owning ManyToMany's target is shown so (5.3c); a ManyToOne's is shown as
        // the object the manager holds -- the name it was given after -- and the same rule is to
        // come to it, pinned until it does.
        $this->em->persist($first = new Relay('first'));
        $this->em->persist($second = new Relay('second'));
        $this->em->flush();
        $this->gateway->documents = [];

        $this->ahead([Events::postUpdate], new class($this->em, $first, $second) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Relay $first, private readonly Relay $second)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->first || $this->ran) {
                    return;
                }

                $this->ran = true;
                $this->second->name = 'renamed after';
                $this->em->flush();
            }
        });

        $first->next = $second;
        $this->em->flush();

        $this->pinned(
            expected: [['old' => null, 'new' => 'second']],
            today: [['old' => null, 'new' => 'renamed after']],
            said: array_values(array_filter(array_map(static fn (array $d): mixed => $d['changes']['next'] ?? null, $this->documents()))),
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function whetherTheApplicationWritesBetween(): iterable
    {
        yield 'the two changes one after the other' => [false];
        yield 'a statement of the application\'s between them' => [true];
    }

    /**
     * Two changes of one JOINED row, each touching one table of it, are two records.
     *
     * Without savepoints (DBAL 3, nested_flush_provenance: outer) no frame tells a nested
     * flush's statements from the outer flush's: the outer flush changes the root's column, a
     * listener ahead of this one runs a nested flush that changes the subclass's, and in the log
     * the two UPDATEs are of one row, of the hierarchy's two tables, in one frame, under one
     * owner -- measured. What separates them is where the nested flush began. And a statement
     * of the application's own between them, which says nothing of any audited row, does not
     * make them adjacent: which statements make one change is read over the log as it ran, not
     * over the facts that are left once the others are filtered out.
     *
     * Put right by step 5 (5.2c), which reads an entity's records from the log's facts.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('whetherTheApplicationWritesBetween')]
    public function testTwoChangesOfAJoinedRowEachOfOneTableAreTwoRecordsWithoutSavepoints(bool $between): void
    {
        $this->unownedStatementsAreExpected = true;
        $this->watchTheConnection(FailurePolicy::Log, savepoints: false);
        $this->em->persist($vehicle = new Vehicle());
        $this->em->persist($press = new Press('One'));
        $this->em->flush();
        $this->gateway->documents = [];

        $this->ahead([Events::postUpdate], new class($this->em, $press, $between ? $vehicle->id : null) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Press $press, private readonly mixed $vehicle)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->press || $this->ran) {
                    return;
                }

                $this->ran = true;

                if ($this->vehicle !== null) {
                    $this->em->getConnection()->update('Vehicle', ['plate' => 'between'], ['id' => $this->vehicle]);
                }

                $this->press->tonnage = 5;
                $this->em->flush();
            }
        });

        $press->name = 'Two';
        $this->em->flush();

        $said = array_map(static fn (array $d): array => [$d['objectType'], $d['changes']], $this->documents());
        usort($said, static fn (array $a, array $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));

        // Two records, each saying what its own statement did. Until 5.2c the nested one also
        // said the name changed -- which its UPDATE did not write: the name came from Doctrine's
        // change set, and the change was in the history twice.
        $this->pinned(
            expected: [
                ['machine', ['name' => ['old' => 'One', 'new' => 'Two']]],
                ['machine', ['tonnage' => ['old' => 1, 'new' => 5]]],
            ],
            today: null,
            said: $said,
        );
    }

    /**
     * @param list<mixed>      $expected what the history has to say
     * @param list<mixed>|null $today    what it says today, when that is something else; null
     *                                   when it already says what it has to
     * @param list<mixed>|null $said     what it says, if not the changes of every document
     */
    private function pinned(array $expected, ?array $today, ?array $said = null): void
    {
        $said ??= $this->changes();

        if ($today === null) {
            self::assertSame($expected, $said, 'the history says what the rows did');

            return;
        }

        self::assertNotSame($expected, $said, 'this is described correctly now: take the pin off');
        self::assertSame($today, $said, 'what the history says has changed; the pin no longer describes it');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function changes(): array
    {
        return array_map(static fn (array $d): array => $d['changes'], $this->documents());
    }

    /**
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function titles(): array
    {
        return array_values(array_map(
            static fn (array $d): array => [$d['changes']['title']['old'] ?? null, $d['changes']['title']['new'] ?? null],
            array_filter($this->documents(), static fn (array $d): bool => isset($d['changes']['title'])),
        ));
    }

    /**
     * @return list<array{0: mixed, 1: string, 2: mixed}>
     */
    private function events(): array
    {
        return array_map(static fn (array $d): array => [$d['objectType'] ?? null, (string) ($d['objectId'] ?? ''), $d['event'] ?? null], $this->documents());
    }

    private function persisted(Article $article): Article
    {
        $this->em->persist($article);
        $this->em->flush();
        $this->gateway->documents = [];

        return $article;
    }

    /**
     * @param list<string> $events
     */
    private function ahead(array $events, object $listener): void
    {
        $manager = $this->em->getEventManager();

        foreach ($events as $event) {
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
}
