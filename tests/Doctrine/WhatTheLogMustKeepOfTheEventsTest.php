<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Kiln;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Relay;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * What the history says today, built from Doctrine's events, that it has to go on saying once
 * it is built from the log's facts (5.2c): the things the events could do that a row's facts
 * alone cannot, or do differently.
 */
final class WhatTheLogMustKeepOfTheEventsTest extends DoctrineTestCase
{
    public function testAnAuthorTheFlushRemovedIsStillNamedByTheArticleThatLeftIt(): void
    {
        [$article, $ada, $bea] = $this->anArticleBy('Ada', 'Bea');

        $article->author = $bea;
        $this->em->remove($ada);
        $this->em->flush();

        self::assertSame([['article', 'update', ['author' => ['old' => 'Ada', 'new' => 'Bea'], 'status' => ['old' => 'draft', 'new' => 'draft']]]], $this->said());
    }

    public function testAnAuthorTheFlushRemovedIsNamedTooWhenTheRecordIsWrittenLateAfterAClear(): void
    {
        [$article, $ada, $bea] = $this->anArticleBy('Ada', 'Bea');
        $listener = $this->silenceOurPostFlush();

        $article->author = $bea;
        $this->em->remove($ada);
        $this->em->flush();
        self::assertSame([], $this->documents(), 'the premise: publishing never ran for that flush');

        $this->em->getEventManager()->addEventListener([Events::postFlush], $listener);
        $this->em->clear();
        unset($article, $ada, $bea);
        gc_collect_cycles();

        $this->em->persist(new Author('Cy'));
        $this->em->flush();

        self::assertSame([['article', 'update', ['author' => ['old' => 'Ada', 'new' => 'Bea'], 'status' => ['old' => 'draft', 'new' => 'draft']]]], $this->said());
    }

    public function testAnAuthorRemovedBeforeAFlushThatWritesAnotherLateIsStillNamed(): void
    {
        // `$em->remove()` fires preRemove where it is called, before the flush that deletes the
        // row: the object is held from there. The flush then finds the last one behind it, its
        // publishing swallowed, writes that one's records late and forgets what it left -- and
        // not what was removed for itself, whose DELETE has yet to run. Forgotten with the rest,
        // the author was named by an identifier, the object the application removed gone.
        [$article, $ada, $bea] = $this->anArticleBy('Ada', 'Bea');
        $listener = $this->silenceOurPostFlush();

        $article->title = 'Hi';
        $this->em->flush();
        self::assertSame([], $this->documents(), 'the premise: publishing never ran for that flush');
        $this->em->getEventManager()->addEventListener([Events::postFlush], $listener);

        $article->author = $bea;
        $this->em->remove($ada);
        $this->em->flush();

        self::assertSame([
            ['article', 'update', ['status' => ['old' => 'draft', 'new' => 'draft'], 'title' => ['old' => 'Hello', 'new' => 'Hi']]],
            ['article', 'update', ['author' => ['old' => 'Ada', 'new' => 'Bea'], 'status' => ['old' => 'draft', 'new' => 'draft']]],
        ], $this->said());
    }

    public function testAnAuthorTheFlushRemovedIsNamedByEveryArticleThatLeftIt(): void
    {
        // One author, the old side of two records: named by both, not only by the first.
        [$first, $ada, $bea] = $this->anArticleBy('Ada', 'Bea');
        $second = new Article('Again');
        $second->author = $ada;
        $this->em->persist($second);
        $this->em->flush();
        $this->gateway->documents = [];

        $first->author = $bea;
        $second->author = $bea;
        $this->em->remove($ada);
        $this->em->flush();

        $change = ['author' => ['old' => 'Ada', 'new' => 'Bea'], 'status' => ['old' => 'draft', 'new' => 'draft']];

        self::assertSame([['article', 'update', $change], ['article', 'update', $change]], $this->said());
    }

    public function testTheContextOfWhatAnElementDidIsItsOwnersRowWhereItRan(): void
    {
        // A line goes from one to two, in a flush whose postFlush somebody swallowed; the
        // application's own SQL moves the crate's status; the line goes to three, and that
        // flush writes both records. The crate never had an UPDATE of Doctrine's. Each record's
        // context is the crate's row when its line's statement ran: "packed", then "raw" --
        // not the object's as each flush began, which never heard of "raw" (what was recorded
        // until 5.2c), and not the rows as they stand when the late one is written, which would
        // lend the first one the future.
        $this->unownedStatementsAreExpected = true;
        $crate = new Crate('C-1');
        $crate->add($line = new CrateItem('apple'));
        $this->em->persist($crate);
        $this->em->flush();
        $this->gateway->documents = [];

        $listener = $this->silenceOurPostFlush();
        $line->quantity = 2;
        $this->em->flush();
        $this->em->getEventManager()->addEventListener([Events::postFlush], $listener);

        $this->em->getConnection()->update('Crate', ['status' => 'raw'], ['code' => 'C-1']);
        $line->quantity = 3;
        $this->em->flush();

        $quantity = 'items.'.$line->id.'.quantity';

        $this->pinned(
            expected: [
                ['crate', 'update', [$quantity => ['old' => 1, 'new' => 2], 'status' => ['old' => 'packed', 'new' => 'packed']]],
                ['crate', 'update', [$quantity => ['old' => 2, 'new' => 3], 'status' => ['old' => 'raw', 'new' => 'raw']]],
            ],
            today: null,
        );
    }

    public function testAChangeOfOneTableOfAJoinedRowHasTheContextOfBoth(): void
    {
        $this->em->persist($kiln = new Kiln());
        $this->em->flush();
        $this->gateway->documents = [];

        $kiln->label = 'two';
        $this->em->flush();
        $kiln->heat = 1000;
        $this->em->flush();
        $kiln->label = 'three';
        $kiln->heat = 1100;
        $this->em->flush();

        $firing = ['old' => 'bisque', 'new' => 'bisque'];
        $site = ['old' => 'north', 'new' => 'north'];

        self::assertSame([
            ['oven', 'update', ['firing' => $firing, 'label' => ['old' => 'one', 'new' => 'two'], 'site' => $site]],
            ['oven', 'update', ['firing' => $firing, 'heat' => ['old' => 900, 'new' => 1000], 'site' => $site]],
            ['oven', 'update', ['firing' => $firing, 'heat' => ['old' => 1000, 'new' => 1100], 'label' => ['old' => 'two', 'new' => 'three'], 'site' => $site]],
        ], $this->said());
    }

    public function testAnAlwaysRecordedFieldAChangeOfTheOtherTableWroteIsAChangeAndNotContext(): void
    {
        // The subclass's always-recorded field moves with the root's column, in one execution
        // written a table at a time: the root's statement runs while the subclass's row still
        // holds the old value. It is a change of the record, not its context.
        $this->em->persist($kiln = new Kiln());
        $this->em->flush();
        $this->gateway->documents = [];

        $kiln->label = 'two';
        $kiln->firing = 'glaze';
        $this->em->flush();

        self::assertSame([
            ['oven', 'update', ['firing' => ['old' => 'bisque', 'new' => 'glaze'], 'label' => ['old' => 'one', 'new' => 'two'], 'site' => ['old' => 'north', 'new' => 'north']]],
        ], $this->said());
    }

    public function testTheContextBesideAChangeWhenTheRowMovedAfterTheFlushBegan(): void
    {
        // A listener ahead of this one, in onFlush, writes the always-recorded column itself
        // before Doctrine's statement runs. When the flush began the row held "bisque"; when
        // the change was written it held "raw". The context is the row beside the change once
        // the change is in the database: "raw" (5.2c; until then, the flush's beginning). The
        // application's SQL is no audited change of its own, and what it left is what the next
        // one is written beside.
        $this->unownedStatementsAreExpected = true;
        $this->em->persist($kiln = new Kiln());
        $this->em->flush();
        $this->gateway->documents = [];

        $this->aheadOfTheAuditListener(Events::onFlush, new class($kiln) {
            public function __construct(private readonly Kiln $kiln)
            {
            }

            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $args): void
            {
                $args->getObjectManager()->getConnection()->update('Kiln', ['firing' => 'raw'], ['id' => $this->kiln->id]);
            }
        });

        $kiln->label = 'two';
        $this->em->flush();

        self::assertSame([
            ['oven', 'update', ['firing' => ['old' => 'raw', 'new' => 'raw'], 'label' => ['old' => 'one', 'new' => 'two'], 'site' => ['old' => 'north', 'new' => 'north']]],
        ], $this->said(), 'the context as the row held it beside the change');
    }

    public function testTwoRelaysCreatedPointingAtEachOtherAreTwoCreations(): void
    {
        // A cycle: one of them is inserted with nothing to point at and given its reference by
        // an UPDATE Doctrine runs afterwards and announces to nobody.
        $one = new Relay('one');
        $two = new Relay('two');
        $one->next = $two;
        $two->next = $one;
        $this->em->persist($one);
        $this->em->persist($two);
        $from = $this->statements->position();
        $this->em->flush();

        $kinds = [];

        for ($at = $from + 1; $at <= $this->statements->position(); ++$at) {
            $shape = StatementShape::read($this->statements->statement($at)['sql'] ?? '');

            if ($shape !== null && $shape->table === 'Relay') {
                $kinds[] = $shape->kind;
            }
        }

        self::assertSame([StatementShape::INSERT, StatementShape::INSERT, StatementShape::UPDATE], $kinds, 'the premise: one of them was given its reference afterwards');

        $said = $this->said();
        usort($said, static fn (array $a, array $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));

        self::assertSame([
            ['relay', 'create', ['name' => ['old' => null, 'new' => 'one'], 'next' => ['old' => null, 'new' => 'two']]],
            ['relay', 'create', ['name' => ['old' => null, 'new' => 'two'], 'next' => ['old' => null, 'new' => 'one']]],
        ], $said);
    }

    public function testAnOwningCollectionAndAColumnChangedTogetherAreOneRecord(): void
    {
        $this->em->persist($php = new Tag('php'));
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();
        $this->gateway->documents = [];

        $article->title = 'Hello again';
        $article->tags->add($php);
        $this->em->flush();

        self::assertSame([
            ['article', 'update', ['status' => ['old' => 'draft', 'new' => 'draft'], 'tags' => ['old' => [], 'new' => ['php']], 'title' => ['old' => 'Hello', 'new' => 'Hello again']]],
        ], $this->said());
    }

    public function testTwoChangesOfARowAndItsCollectionInOneOperationEachHaveTheirOwn(): void
    {
        // The outer flush writes the title and a tag; a listener ahead of this one, once the
        // UPDATE ran, changes the title again and adds another tag, and flushes. Two
        // executions of one row: each record has its own title and its own tags.
        $this->em->persist($php = new Tag('php'));
        $this->em->persist($es = new Tag('elasticsearch'));
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();
        $this->gateway->documents = [];

        $this->aheadOfTheAuditListener(Events::postUpdate, new class($this->em, $article, $es) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $article, private readonly Tag $es)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->article || $this->ran) {
                    return;
                }

                $this->ran = true;
                $this->article->title = 'Third';
                $this->article->tags->add($this->es);
                $this->em->flush();
            }
        });

        $article->title = 'Second';
        $article->tags->add($php);
        $this->em->flush();

        // What the rows went through: the outer UPDATE wrote "Second"; the nested flush wrote
        // "Third", and both join rows -- it carried out the outer flush's collection update with
        // its own, before the outer one got to it. Until 5.2c both records said the title went
        // from "Hello" to "Third", and the first one had both tags. The titles are the log's
        // now; the tags are in the nested record because the collection's snapshot is taken
        // where the nested flush announces the row -- the transitional road until the join
        // rows are facts too (5.3).
        $this->pinned(
            expected: [
                ['article', 'update', ['status' => ['old' => 'draft', 'new' => 'draft'], 'title' => ['old' => 'Hello', 'new' => 'Second']]],
                ['article', 'update', ['status' => ['old' => 'draft', 'new' => 'draft'], 'tags' => ['old' => [], 'new' => ['php', 'elasticsearch']], 'title' => ['old' => 'Second', 'new' => 'Third']]],
            ],
            today: null,
        );
    }

    /**
     * @return array{0: Article, 1: Author, 2: Author}
     */
    private function anArticleBy(string $first, string $then): array
    {
        $this->em->persist($ada = new Author($first));
        $this->em->persist($bea = new Author($then));
        $article = new Article('Hello');
        $article->author = $ada;
        $this->em->persist($article);
        $this->em->flush();
        $this->gateway->documents = [];

        return [$article, $ada, $bea];
    }

    /**
     * Each record as its type, event and changes, the changes by name.
     *
     * @return list<array{0: mixed, 1: mixed, 2: mixed}>
     */
    private function said(): array
    {
        return array_map(static function (array $d): array {
            $changes = $d['changes'];

            if (\is_array($changes)) {
                ksort($changes);
            }

            return [$d['objectType'], $d['event'], $changes];
        }, $this->documents());
    }

    /**
     * @param list<mixed>      $expected what the history has to say
     * @param list<mixed>|null $today    what it says today, when that is something else
     */
    private function pinned(array $expected, ?array $today): void
    {
        $said = $this->said();

        if ($today === null) {
            self::assertSame($expected, $said, 'the history says what the rows did');

            return;
        }

        self::assertNotSame($expected, $said, 'this is described correctly now: take the pin off');
        self::assertSame($today, $said, 'what the history says has changed; the pin no longer describes it');
    }

    private function aheadOfTheAuditListener(string $event, object $listener): void
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

    private function silenceOurPostFlush(): AuditSubscriber
    {
        foreach ($this->em->getEventManager()->getListeners(Events::postFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                $this->em->getEventManager()->removeEventListener([Events::postFlush], $listener);

                return $listener;
            }
        }

        self::fail('no audit listener was attached to postFlush');
    }
}
