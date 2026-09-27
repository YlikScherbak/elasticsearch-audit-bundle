<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
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
        // the change was written it held "raw". Which of the two the context says is the
        // contract 5.2c decides; today it is the flush's beginning.
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
            ['oven', 'update', ['firing' => ['old' => 'bisque', 'new' => 'bisque'], 'label' => ['old' => 'one', 'new' => 'two'], 'site' => ['old' => 'north', 'new' => 'north']]],
        ], $this->said(), 'today: the context as the flush began');
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
        // its own, before the outer one got to it. Today both records say the title went from
        // "Hello" to "Third", and the first one has both tags. The titles are the log's to put
        // right (5.2c); which record the tags are in stays today's until the join rows are
        // facts too (5.3).
        $this->pinned(
            expected: [
                ['article', 'update', ['status' => ['old' => 'draft', 'new' => 'draft'], 'title' => ['old' => 'Hello', 'new' => 'Second']]],
                ['article', 'update', ['status' => ['old' => 'draft', 'new' => 'draft'], 'tags' => ['old' => [], 'new' => ['php', 'elasticsearch']], 'title' => ['old' => 'Second', 'new' => 'Third']]],
            ],
            today: [
                ['article', 'update', ['status' => ['old' => 'draft', 'new' => 'draft'], 'tags' => ['old' => [], 'new' => ['php', 'elasticsearch']], 'title' => ['old' => 'Hello', 'new' => 'Third']]],
                ['article', 'update', ['status' => ['old' => 'draft', 'new' => 'draft'], 'title' => ['old' => 'Hello', 'new' => 'Third']]],
            ],
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
