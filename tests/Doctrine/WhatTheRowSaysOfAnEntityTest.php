<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Switchboard;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\SwitchMode;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * An entity's own history, held to what its rows did.
 *
 * Written before step 5 reads an entity's fields from the connection's log instead of
 * Doctrine's change set, so that the step is handed its targets: where the two already agree,
 * the test says it and step 5 must keep it; where they do not, the test says what is recorded
 * today and what has to be, and fails the day either changes -- today's answer pinned, so
 * that it cannot drift unnoticed, and so that mending it takes the pin off.
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
        // held. A history of the row has nothing to say -- today it says what the object says.
        $this->em->persist($board = new Switchboard());
        $this->em->flush();
        $this->gateway->documents = [];

        $board->checkedAt = new \DateTimeImmutable('2026-09-25 10:00:00.500000');
        $this->em->flush();

        $this->pinned(
            expected: [],
            today: [['checkedAt' => ['old' => '2026-09-25 10:00:00', 'new' => '2026-09-25 10:00:00.500000']]],
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
            today: [['title 0', 'title 1'], ['title 1', 'title 2']],
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
            // The removal is already right: its DELETE was taken back, and the record tied to
            // it goes. The link is not: the collection's change is read from Doctrine, which
            // believes the join row written -- step 5 reads it from the log.
            today: [['article', (string) $titledId, 'update'], ['article', (string) $linked->id, 'update']],
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

        // Today there is none at all: the nested flush's postCommitCleanup() emptied the outer
        // flush's collection updates, and the collection's change was read from there. The
        // join row is in the log -- step 5 reads the collection from it.
        $this->pinned(
            expected: [['old' => [], 'new' => ['php']]],
            today: [],
            said: array_values(array_filter(array_map(static fn (array $d): mixed => $d['changes']['tags'] ?? null, $this->documents()))),
        );
        $this->logs = []; // the warning it says so with, which is today's too
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
