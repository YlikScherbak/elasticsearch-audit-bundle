<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\JoinRowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LinkFacts;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CornerShelf;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shelf;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * What the listener reads of an owning ManyToMany's join rows, and where (5.3a): the accounts
 * it seeds in each flush's onFlush, and what the facts told against them say -- the position
 * an account is taken at, what becomes of it when the log takes something back, whether a
 * target going is told as the move of each whole list, and doubt where nothing was read.
 * Facts only; nothing writes a record of them yet.
 */
final class WhatTheListenerReadsOfTheJoinRowsTest extends DoctrineTestCase
{
    /** @var array<string, string> */
    private array $labels = [];

    /** @var array<string, string> */
    private array $names = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->watchTheConnection(FailurePolicy::Log);
    }

    public function testAnAccountFirstReadInANestedFlushStandsWhereTheLogDoes(): void
    {
        // The outer flush changes a title; after its UPDATE the application writes a link of its
        // own, and a nested flush adds another. The nested flush's onFlush is the first to read
        // the article's links, and reads them after the application's INSERT: that INSERT is in
        // the account, and is undone from it -- so it is a fact once, and the list is not told
        // as starting from what it already held.
        [$article] = $this->anArticle('One');
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->ahead([Events::postUpdate], new class($this->em, $article, $a, $b) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $article, private readonly Tag $a, private readonly Tag $b)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->article || $this->ran) {
                    return;
                }

                $this->ran = true;
                $this->em->getConnection()->insert('article_tag', ['article_id' => $this->article->id, 'tag_id' => $this->a->id]);
                $this->article->tags->add($this->b);
                $this->em->flush();
            }
        });

        $article->title = 'Two';
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['One +a (row)', 'One +b (row)'], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertSame(['a', 'b'], $this->heldBy($told, $article));
        $this->end();
    }

    public function testALinkANestedFlushWroteAndTheApplicationTookBackIsNotWhereTheNextStarts(): void
    {
        // Read in the nested flush's onFlush, inside a savepoint of the application's that is
        // rolled back once the nested flush has written the link: the next flush's link is added
        // to a list without it -- and Doctrine, whose nested flush succeeded, believes it there.
        [$article] = $this->anArticle('One');
        $a = $this->aTag('a');
        $b = $this->aTag('b');

        $this->begin();
        $this->ahead([Events::postUpdate], new class($this->em, $article, $a) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $article, private readonly Tag $a)
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
                $this->article->tags->add($this->a);
                $this->em->flush();
                $connection->rollBack();
            }
        });

        $article->title = 'Two';
        $this->em->flush();
        $article->tags->add($b);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['One +b (row)'], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertSame(['b'], $this->heldBy($told, $article));
        $this->end();
    }

    public function testAnAccountSettledWithTheRowsIsTheNextFlushsAndCostsNoQuestion(): void
    {
        // Outside any transaction of the application's, every flush settles: the account moves to
        // what its rows hold, and the next flush to touch them asks nothing.
        [$article] = $this->anArticle('One', 'a');
        $b = $this->aTag('b');
        $c = $this->aTag('c');

        $article->tags->add($b);
        $this->em->flush();

        $account = $this->memory()->links()->links()[self::tags()][(string) $article->id] ?? null;
        self::assertNotNull($account, 'the premise: the account is kept');
        self::assertSame(['a', 'b'], $this->labelsOf(array_keys($account['targets'])));
        self::assertSame([], $account['includes'], 'with nothing in it to undo');

        $this->queries = [];
        $article->tags->add($c);
        $this->em->flush();

        self::assertSame([], array_values(array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'article_tag'))), 'no question asked of its rows');
        self::assertSame(['a', 'b', 'c'], $this->labelsOf(array_keys($this->memory()->links()->links()[self::tags()][(string) $article->id]['targets'] ?? [])));
    }

    public function testAnOwnerAFlushCreatedIsAccountedForWithoutAQuestionOnEveryDatabase(): void
    {
        // Its key comes from its INSERT on most databases and before it on some -- PostgreSQL's
        // sequences under DBAL 3 -- and either way what it holds is what the flush that created
        // it wrote: settled as its account, so that the next flush asks nothing.
        $this->queries = [];
        [$article] = $this->anArticle('New', 'a');
        self::assertSame([], $this->selectsOfTheJoinTable(), 'creating it asks nothing');

        $account = $this->memory()->links()->links()[self::tags()][(string) $article->id] ?? null;
        self::assertNotNull($account, 'accounted for');
        self::assertSame(['a'], $this->labelsOf(array_keys($account['targets'])));

        $this->queries = [];
        $article->tags->add($this->aTag('b'));
        $this->em->flush();
        self::assertSame([], $this->selectsOfTheJoinTable(), 'and the next flush asks nothing either');
    }

    /** @return list<string> */
    private function selectsOfTheJoinTable(): array
    {
        return array_values(array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'article_tag')));
    }

    public function testATargetGoingIsTakenOutOfEveryWholeListItWasInWithOneQuestion(): void
    {
        // One holds a and b, two holds a, three holds b. a and c go in one flush: their holders are
        // read in onFlush, before the rows go with them, in one question of the article's join
        // table -- and each list loses what it held of them, and keeps the rest.
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $c = $this->aTag('c');
        [$one] = $this->anArticle('One', $a, $b);
        [$two] = $this->anArticle('Two', $a, $c);
        [$three] = $this->anArticle('Three', $b);

        $this->begin();
        $this->queries = [];
        $this->em->remove($a);
        $this->em->remove($c);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['One -a (target)', 'Two -a (target)', 'Two -c (target)'], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertSame([['b'], []], [$this->heldBy($told, $one), $this->heldBy($told, $two)]);
        self::assertSame(['b'], $this->heldBy($told, $three), 'and a list that held neither is as it was');
        self::assertCount(1, array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM article_tag j')), 'one question of the article\'s join table');
        $this->end();
    }

    public function testAnOwnerLoadedAndClearedIsReadInTheFlushThatClearsIt(): void
    {
        // An article this process never wrote, loaded and its tags cleared: its links are read in
        // the onFlush of the flush that deletes them, and each is a fact.
        $id = $this->anArticleNobodySaw('Loaded', $this->aTag('a'), $this->aTag('b'));
        $article = $this->em->find(Article::class, $id);
        self::assertInstanceOf(Article::class, $article);

        $this->begin();
        $article->tags->clear();
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['Loaded -a (emptied)', 'Loaded -b (emptied)'], $this->said($told));
        self::assertSame([], $told->doubts());
        $this->end();
    }

    public function testAnOwnerGoingWhoseJoinColumnsCascadeCostsNoQuestionOfItsLinks(): void
    {
        // The database takes its rows with it and Doctrine writes nothing for them: there is no
        // statement to tell against an account, so none is read -- of an article nothing had read.
        $article = $this->em->find(Article::class, $this->anArticleNobodySaw('One', $this->aTag('a')));
        self::assertInstanceOf(Article::class, $article);
        $this->queries = [];

        $this->em->remove($article);
        $this->em->flush();

        self::assertSame([], array_values(array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'article_tag'))));
    }

    public function testATargetOfASubclassGoingByItsRootsTableIsTakenOutToo(): void
    {
        // A corner shelf removed is a DELETE of the root's table, bound to the root: the
        // collection of corner shelves is still one it goes from.
        $this->em->persist($shelf = new Shelf());
        $this->em->persist($corner = new CornerShelf());
        $shelf->neighbours->add($corner);
        $this->em->flush();
        $this->names[(string) $shelf->id] = 'Shelf';
        $this->labels[(string) $corner->id] = 'corner';

        $this->begin();
        $this->em->remove($corner);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['Shelf -corner (target)'], $this->said($told));
        self::assertSame([], $told->doubts());
        $this->end();
    }

    public function testWhatNobodyReadIsDoubtAndWhatTheListenerReadIsFacts(): void
    {
        // clear() through Doctrine: the account read in onFlush, a fact for each link. The rows of
        // an article written past this connection -- another client's -- taken by the
        // application's own statement, outside any flush the listener could read them in: doubt,
        // and not a list emptied of nothing.
        [$cleared] = $this->anArticle('Cleared', 'a', 'b');
        $c = $this->aTag('c');
        $this->unownedStatementsAreExpected = true;
        $native = $this->em->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        // Its id given: a sequence with no default -- PostgreSQL's under DBAL 3 -- hands none out.
        $taken = '900001';
        $native->exec("INSERT INTO Article (id, title, status, views) VALUES ($taken, 'Taken', 'draft', 0)"); // one no other test here uses
        $native->exec(sprintf('INSERT INTO article_tag (article_id, tag_id) VALUES (%d, %d)', $taken, $c->id));

        $this->begin();
        $cleared->tags->clear();
        $this->em->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM article_tag WHERE article_id = ?', [$taken]);

        $told = $this->told();
        self::assertSame(['Cleared -a (emptied)', 'Cleared -b (emptied)'], $this->said($told));
        self::assertSame(['every link of '.self::tags().' '.$taken.' taken, and which they were is not known'], array_column($told->doubts(), 'doubt'));
        $this->end();
    }

    private static function tags(): string
    {
        return JoinRowMemory::associationOf(Article::class, 'tags');
    }

    private function aTag(string $label): Tag
    {
        $this->em->persist($tag = new Tag($label));
        $this->em->flush();
        $this->labels[(string) $tag->id] = $label;

        return $tag;
    }

    /**
     * @return array{0: Article, 1?: Tag, 2?: Tag}
     */
    private function anArticle(string $title, string|Tag ...$tags): array
    {
        $article = new Article($title);
        $given = [];

        foreach ($tags as $tag) {
            $article->tags->add($given[] = \is_string($tag) ? $this->aTag($tag) : $tag);
        }

        $this->em->persist($article);
        $this->em->flush();
        $this->names[(string) $article->id] = $title;

        return [$article, ...$given];
    }

    /**
     * An article and its links written past this connection -- another client's -- so that
     * nothing here has an account of it. Its id given: a sequence with no default --
     * PostgreSQL's under DBAL 3 -- hands none out.
     */
    private function anArticleNobodySaw(string $title, Tag ...$tags): string
    {
        static $next = 900100;
        $id = (string) ++$next;
        $native = $this->em->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->exec(sprintf("INSERT INTO Article (id, title, status, views) VALUES (%d, '%s', 'draft', 0)", $id, $title));

        foreach ($tags as $tag) {
            $native->exec(sprintf('INSERT INTO article_tag (article_id, tag_id) VALUES (%d, %d)', $id, $tag->id));
        }

        $this->names[$id] = $title;

        return $id;
    }

    private function told(): LinkFacts
    {
        $memory = $this->memory();

        return LinkFacts::of($this->em, $memory->replayed($this->em), $this->statements, $memory->links());
    }

    /** @return list<string> */
    private function said(LinkFacts $told): array
    {
        return array_map(
            fn (array $fact): string => sprintf('%s %s%s (%s)', $this->names[$fact['ownerId']] ?? $fact['ownerId'], $fact['arrived'] ? '+' : '-', $this->labels[$fact['targetId']] ?? $fact['targetId'], $fact['cause']),
            $told->facts(),
        );
    }

    /** @return list<string>|null */
    private function heldBy(LinkFacts $told, Article $owner): ?array
    {
        $state = $told->states()[self::tags()][(string) $owner->id] ?? null;

        return $state === null ? null : $this->labelsOf(array_keys($state));
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<string>
     */
    private function labelsOf(array $ids): array
    {
        $labels = array_map(fn (int|string $id): string => $this->labels[(string) $id] ?? (string) $id, $ids);
        sort($labels);

        return $labels;
    }

    private function begin(): void
    {
        $this->em->getConnection()->beginTransaction();
    }

    private function end(): void
    {
        $this->em->getConnection()->rollBack();
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

    private function memory(): RowMemory
    {
        foreach ($this->em->getEventManager()->getListeners(Events::onFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                $memory = (new \ReflectionProperty(AuditSubscriber::class, 'rows'))->getValue($listener);
                self::assertInstanceOf(RowMemory::class, $memory);

                return $memory;
            }
        }

        self::fail('the premise: the listener is attached');
    }
}
