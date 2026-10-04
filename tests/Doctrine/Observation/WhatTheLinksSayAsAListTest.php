<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\DepartedObjects;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LinkFacts;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LinkRuns;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowIdentity;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CornerShelf;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ListFirst;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Relay;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shelf;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Ticket;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\TicketBook;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * An owning ManyToMany's facts as the history says them (5.3b): `old [...] -> new [...]`, one
 * move of the field per contribution, the lists from the join rows, in the order of the
 * targets' keys. Put together and not yet published.
 */
final class WhatTheLinksSayAsAListTest extends DoctrineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->watchTheConnection(FailurePolicy::Log);
    }

    public function testAContributionIsTheWholeListBeforeAndAfterInTheOrderOfTheTargetsKeys(): void
    {
        // Created zeta, then alpha: by key, zeta comes first -- not by label, and not in the
        // order they were added to the collection.
        $zeta = $this->aTag('zeta');
        $alpha = $this->aTag('alpha');
        $mid = $this->aTag('mid');
        $article = $this->anArticle('One', $mid);

        $this->begin();
        $article->tags->add($alpha);
        $article->tags->add($zeta);
        $this->em->flush();

        self::assertSame([['One', ['mid'], ['zeta', 'alpha', 'mid']]], $this->said());
        $this->end();
    }

    public function testKeysAreComparedAsNumbersWhereTheyAreNumbers(): void
    {
        // Ten tags: the ninth before the tenth, where as text "10" comes before "9".
        $tags = [];

        for ($i = 1; $i <= 10; ++$i) {
            $tags[] = $this->aTag('t'.$i);
        }

        $article = $this->anArticle('One');

        $this->begin();
        $article->tags->add($tags[9]);
        $article->tags->add($tags[8]);
        $this->em->flush();

        self::assertSame([['One', [], ['t9', 't10']]], $this->said());
        $this->end();
    }

    public function testKeysOfTextAreComparedAsTextWhereEitherIsNoNumber(): void
    {
        // As numbers where both are -- 9 before 10 -- and as text where one is not: "10" before
        // "A", as "9" is. Not "A" first, which comparing "A" as a number, 0, would make it.
        $this->em->persist($book = new TicketBook());
        $this->em->flush();

        $this->begin();

        foreach (['A', '10', '9'] as $code) {
            $this->em->persist($ticket = new Ticket($code));
            $book->tickets->add($ticket);
        }

        $this->em->flush();

        self::assertSame([[[], ['9', '10', 'A']]], array_map(static fn (array $run): array => [$run['old'], $run['new']], $this->runs()));
        $this->end();
    }

    public function testTheSameLinkMetTwiceInOneFlushIsTwoMovesOfTheList(): void
    {
        // A listener of the application's writes a link and takes it away again, inside the
        // flush: two moves of the field, and not one that says nothing.
        $php = $this->aTag('php');
        $es = $this->aTag('es');
        $article = $this->anArticle('One', $php);
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->afterTheUpdateOf($article, function () use ($article, $es): void {
            $connection = $this->em->getConnection();
            $connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $es->id]);
            $connection->delete('article_tag', ['article_id' => $article->id, 'tag_id' => $es->id]);
        });
        $article->title = 'Two';
        $this->em->flush();

        self::assertSame([['One', ['php'], ['php', 'es']], ['One', ['php', 'es'], ['php']]], $this->said());
        $this->end();
    }

    public function testAFlushBegunBetweenEndsAContribution(): void
    {
        // The outer flush's frame holds a link written before a nested flush and one after it:
        // two contributions, each its own move of the list, both the outer flush's.
        // Created with a tag, so that what its rows hold is known: the application's own INSERTs
        // are told against it.
        $x = $this->aTag('x');
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $article = $this->anArticle('One', $x);
        $other = $this->anArticle('Other');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->afterTheUpdateOf($article, function () use ($article, $other, $a, $b): void {
            $connection = $this->em->getConnection();
            $connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $a->id]);
            $other->title = 'Other, nested';
            $this->em->flush();
            $connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $b->id]);
        });
        $article->title = 'Two';
        $this->em->flush();

        $runs = $this->runs();
        self::assertSame([['One', ['x'], ['x', 'a']], ['One', ['x', 'a'], ['x', 'a', 'b']]], $this->said($runs));
        self::assertSame($runs[0]['flush'], $runs[1]['flush'], 'both the outer flush\'s');
        $this->end();
    }

    public function testContributionsAreSaidInTheOrderTheyBeganAcrossOwners(): void
    {
        // One's link written, Two's, then One's taken away again -- One's second contribution:
        // said where it began, after Two's, and not with One's first.
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $one = $this->anArticle('One', $a);
        $two = $this->anArticle('Two', $a);
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->afterTheUpdateOf($one, function () use ($one, $two, $b): void {
            $connection = $this->em->getConnection();
            $connection->insert('article_tag', ['article_id' => $one->id, 'tag_id' => $b->id]);
            $connection->insert('article_tag', ['article_id' => $two->id, 'tag_id' => $b->id]);
            $connection->delete('article_tag', ['article_id' => $one->id, 'tag_id' => $b->id]);
        });
        $one->title = 'One, again';
        $this->em->flush();

        self::assertSame([['One', ['a'], ['a', 'b']], ['Two', ['a'], ['a', 'b']], ['One', ['a', 'b'], ['a']]], $this->said());
        $this->end();
    }

    public function testAnAccountTakenAfterALinkWasWrittenAndTakenAgainIsUndoneLastFirst(): void
    {
        // The application writes a link and takes it away again, in its transaction, and then a
        // flush touches the list: the rows it reads are after both, and undone from the last
        // back they held neither -- not the link, which undoing the first first would put back.
        $x = $this->aTag('x');
        [$y, $z] = [$this->aTag('y'), $this->aTag('z')];
        [$yId, $zId] = [$y->id, $z->id];
        $id = $this->anArticle('One', $x)->id;
        $this->unownedStatementsAreExpected = true;

        // No account of its links from here: the object it was kept for is gone, and a flush
        // settles that.
        $this->em->clear();
        unset($x, $y, $z);
        gc_collect_cycles();
        $this->aTag('w');
        self::assertArrayNotHasKey((string) $id, $this->links()->links()[\Borsche\ElasticsearchAuditBundle\Doctrine\Observation\JoinRowMemory::associationOf(Article::class, 'tags')] ?? [], 'the premise: no account');

        $this->begin();
        $connection = $this->em->getConnection();
        $connection->insert('article_tag', ['article_id' => $id, 'tag_id' => $yId]);
        $connection->delete('article_tag', ['article_id' => $id, 'tag_id' => $yId]);
        $article = $this->em->find(Article::class, $id);
        self::assertInstanceOf(Article::class, $article);
        $article->tags->add($this->em->getReference(Tag::class, $zId) ?? throw new \LogicException('no tag'));
        $this->em->flush();

        self::assertSame([['One', ['x'], ['x', 'y']], ['One', ['x', 'y'], ['x']], ['One', ['x'], ['x', 'z']]], $this->said());
        $this->end();
    }

    public function testAnAccountTakenAfterTwoLinksWereWrittenIsUndoneOfBoth(): void
    {
        // Two links the application wrote before a flush reads the rows: both are undone, and
        // the list before them holds neither.
        $x = $this->aTag('x');
        [$y, $z, $w] = [$this->aTag('y'), $this->aTag('z'), $this->aTag('w')];
        [$yId, $zId, $wId] = [$y->id, $z->id, $w->id];
        $id = $this->anArticle('One', $x)->id;
        $this->unownedStatementsAreExpected = true;
        $this->em->clear();
        unset($x, $y, $z, $w);
        gc_collect_cycles();
        $this->aTag('v');

        $this->begin();
        $connection = $this->em->getConnection();
        $connection->insert('article_tag', ['article_id' => $id, 'tag_id' => $yId]);
        $connection->insert('article_tag', ['article_id' => $id, 'tag_id' => $zId]);
        $article = $this->em->find(Article::class, $id);
        self::assertInstanceOf(Article::class, $article);
        $article->tags->add($this->em->getReference(Tag::class, $wId) ?? throw new \LogicException('no tag'));
        $this->em->flush();

        self::assertSame([['One', ['x'], ['x', 'y', 'z']], ['One', ['x', 'y', 'z'], ['x', 'y', 'z', 'w']]], $this->said());
        $this->end();
    }

    public function testAnAccountTakenAfterEveryLinkWasTakenCannotBeUndoneAndIsNotKnown(): void
    {
        // Every link of the owner taken by one statement, before a flush reads the rows: which
        // they were the statement does not say, so what the rows held before it is not known --
        // and the flush's own move is not said from a list nobody knows.
        $x = $this->aTag('x');
        $z = $this->aTag('z');
        $zId = $z->id;
        $id = $this->anArticle('One', $x)->id;
        $this->unownedStatementsAreExpected = true;
        $this->em->clear();
        unset($x, $z);
        gc_collect_cycles();
        $this->aTag('w');

        $this->begin();
        $this->em->getConnection()->delete('article_tag', ['article_id' => $id]);
        $article = $this->em->find(Article::class, $id);
        self::assertInstanceOf(Article::class, $article);
        $article->tags->add($this->em->getReference(Tag::class, $zId) ?? throw new \LogicException('no tag'));
        $this->em->flush();

        self::assertSame([], $this->said());
        $this->end();
    }

    public function testAFlushBegunRightAfterAContributionsLastLinkDoesNotSplitIt(): void
    {
        // Two links written back to back, and only then a nested flush: one contribution. What
        // began after its last link is after it, and not between its links.
        $x = $this->aTag('x');
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $article = $this->anArticle('One', $x);
        $other = $this->anArticle('Other');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->afterTheUpdateOf($article, function () use ($article, $other, $a, $b): void {
            $connection = $this->em->getConnection();
            $connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $a->id]);
            $connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $b->id]);
            $other->title = 'Other, nested';
            $this->em->flush();
        });
        $article->title = 'Two';
        $this->em->flush();

        self::assertSame([['One', ['x'], ['x', 'a', 'b']]], $this->said());
        $this->end();
    }

    public function testANestedFlushsLinkAndTheOuterFlushsNextAreTwoContributions(): void
    {
        // A nested flush adds a link and is done; the outer flush's listener then writes one of
        // its own. No flush begins between the two: they are two because two flushes wrote them.
        $x = $this->aTag('x');
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $article = $this->anArticle('One', $x);
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->afterTheUpdateOf($article, function () use ($article, $a, $b): void {
            $article->tags->add($a);
            $this->em->flush();
            $this->em->getConnection()->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $b->id]);
        });
        $article->title = 'Two';
        $this->em->flush();

        $runs = $this->runs();
        self::assertSame([['One', ['x'], ['x', 'a']], ['One', ['x', 'a'], ['x', 'a', 'b']]], $this->said($runs));
        self::assertNotSame($runs[0]['flush'], $runs[1]['flush']);
        $this->end();
    }

    public function testAnOwnerCreatedStartsItsListFromNothing(): void
    {
        $php = $this->aTag('php');

        $this->begin();
        $article = new Article('New');
        $article->tags->add($php);
        $this->em->persist($article);
        $this->em->flush();
        $this->titles[(string) $article->id] = 'New';

        self::assertSame([['New', [], ['php']]], $this->said());
        $this->end();
    }

    public function testAnOwnersRowGoneAndBackInOneFlushStartsItsListAgain(): void
    {
        // Inside one flush, the application takes the article's row away and writes it again
        // under the same key: what its rows held starts again from nothing there, and the link
        // written after is a contribution of its own -- not the rest of the one before.
        $x = $this->aTag('x');
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $article = $this->anArticle('One', $x);
        $other = $this->anArticle('Other');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->afterTheUpdateOf($other, function () use ($article, $a, $b): void {
            $connection = $this->em->getConnection();
            $connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $a->id]);
            $connection->executeStatement('DELETE FROM article_tag WHERE article_id = ?', [$article->id]);
            $connection->executeStatement('DELETE FROM Article WHERE id = ?', [$article->id]);
            $connection->insert('Article', ['id' => $article->id, 'title' => 'One, again', 'status' => 'draft', 'views' => 0]);
            $connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $b->id]);
        });
        $other->title = 'Other, again';
        $this->em->flush();

        // The links deleted by the article's key right before its row's DELETE are that removal's --
        // what Doctrine itself writes where join columns do not cascade -- and no move of a list;
        // the one written after the row came back starts from nothing.
        self::assertSame([['One', ['x'], ['x', 'a']], ['One', [], ['b']]], $this->said());
        $this->end();
    }

    public function testWhatWasTakenBackBetweenTheLinksAndTheOwnersDeleteLeavesThemTheRemovals(): void
    {
        // The article's links deleted by its key, then a statement a savepoint of the
        // application's takes back, then the article's row's DELETE: what stood is the links'
        // DELETE right before the row's, and they are the removal's. Seed 2765 of the removals'
        // world, where the statement between was a relay's.
        $x = $this->aTag('x');
        $article = $this->anArticle('One', $x);
        $other = $this->anArticle('Other');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->afterTheUpdateOf($other, function () use ($article, $other): void {
            $connection = $this->em->getConnection();
            $connection->executeStatement('DELETE FROM article_tag WHERE article_id = ?', [$article->id]);
            $connection->beginTransaction();
            $connection->executeStatement('UPDATE Article SET title = ? WHERE id = ?', ['Other, taken back', $other->id]);
            $connection->rollBack();
            $connection->executeStatement('DELETE FROM Article WHERE id = ?', [$article->id]);
        });
        $other->title = 'Other, again';
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$article->id]), 'the premise: the row went');
        self::assertSame([], $this->said());
        $this->end();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function whatStoodBetween(): iterable
    {
        yield 'a statement of an audited table' => [false];
        yield 'a statement of a table no history is about' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatStoodBetween')]
    public function testWhatStoodBetweenTheLinksAndTheOwnersDeleteLeavesThemAMoveOfTheirOwn(bool $ofNoHistory): void
    {
        // The article's links deleted by its key, then a statement that stands, then its row's
        // DELETE: the links' DELETE is not right before the row's, and is a move of its list.
        // Of a table the history is about or of one the log keeps no text of, the same -- what
        // it keeps of the second is its place and its fate, and "right before" is judged by them.
        $x = $this->aTag('x');
        $article = $this->anArticle('One', $x);
        $other = $this->anArticle('Other');
        $this->unownedStatementsAreExpected = true;
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE TABLE scratch (id INTEGER)');

        $this->begin();
        $this->afterTheUpdateOf($other, function () use ($connection, $article, $other, $ofNoHistory): void {
            $connection->executeStatement('DELETE FROM article_tag WHERE article_id = ?', [$article->id]);

            if ($ofNoHistory) {
                $connection->insert('scratch', ['id' => 1]);
            } else {
                $connection->executeStatement('UPDATE Article SET views = ? WHERE id = ?', [7, $other->id]);
            }

            $connection->executeStatement('DELETE FROM Article WHERE id = ?', [$article->id]);
        });
        $other->title = 'Other, again';
        $this->em->flush();

        self::assertSame([['One', ['x'], []]], $this->said());
        $this->end();
    }

    public function testLinksTakenRightBeforeAnOwnersDeleteTakenBackStayTheirOwnMove(): void
    {
        // The same form as a removal's -- the article's links deleted by its key, its row's DELETE
        // right after -- but a savepoint of the application's takes the row's DELETE back and
        // leaves the links' standing: the article is there and holds nothing, and that is a move
        // of its list. What belongs to a removal is decided by what stood, not by the shape.
        $x = $this->aTag('x');
        $article = $this->anArticle('One', $x);
        $other = $this->anArticle('Other');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->afterTheUpdateOf($other, function () use ($article): void {
            $connection = $this->em->getConnection();
            $connection->executeStatement('DELETE FROM article_tag WHERE article_id = ?', [$article->id]);
            $connection->beginTransaction();
            $connection->executeStatement('DELETE FROM Article WHERE id = ?', [$article->id]);
            $connection->rollBack();
        });
        $other->title = 'Other, again';
        $this->em->flush();

        $connection = $this->em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$article->id]), 'the premise: the row stands');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_tag WHERE article_id = ?', [$article->id]), 'the premise: its links do not');
        self::assertSame([['One', ['x'], []]], $this->said());
        $this->end();
    }

    public function testALinkTakenBackIsInNeitherListOfTheNextContribution(): void
    {
        // a added in a flush whose join row a savepoint of the application's takes back, then b
        // in the next: the move is from none to b -- the fate of each fact decided before the
        // list is put together.
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $article = $this->anArticle('One');
        $titled = $this->anArticle('Titled');
        $removed = $this->anArticle('Removed');
        $connection = $this->em->getConnection();

        $this->begin();
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
        $article->tags->add($a);
        $this->em->remove($removed);
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate, Events::postRemove], $taking);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_tag WHERE article_id = ?', [$article->id]), 'the premise: taken back');

        $article->tags->add($b);
        $this->em->flush();

        self::assertSame([['One', [], ['b']]], $this->said());
        $this->end();
    }

    public function testAllOfAnOwnersLinksTakenAreOneMoveToNothing(): void
    {
        $article = $this->anArticle('One', $this->aTag('a'), $this->aTag('b'));

        $this->begin();
        $article->tags->clear();
        $this->em->flush();

        self::assertSame([['One', ['a', 'b'], []]], $this->said());
        $this->end();
    }

    public function testATargetGoneIsNamedByTheObjectTheApplicationRemovedAndEachListMovesWhole(): void
    {
        // A tag is no class the history watches: what is left of it is the object removed --
        // kept here as the listener keeps it, from preRemove to postRemove, since the listener
        // lets go of its own once the flush is published, and these are read after.
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $this->anArticle('One', $a, $b);
        $this->anArticle('Two', $a);
        $departed = new DepartedObjects();
        $this->em->getEventManager()->addEventListener([Events::preRemove, Events::postRemove], new class($departed, $this->statements) {
            public function __construct(private readonly DepartedObjects $departed, private readonly \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog $log)
            {
            }

            public function preRemove(LifecycleEventArgs $args): void
            {
                $manager = $args->getObjectManager();
                \assert($manager instanceof \Doctrine\ORM\EntityManagerInterface);
                $this->departed->leaving($manager, $args->getObject());
            }

            public function postRemove(LifecycleEventArgs $args): void
            {
                $this->departed->gone($args->getObject(), $this->log->position());
            }
        });

        $this->begin();
        $this->em->remove($a);
        $this->em->flush();

        self::assertNull($a->id, 'the premise: the object no longer says which row it was');
        self::assertSame([['One', ['a', 'b'], ['b']], ['Two', ['a'], []]], $this->said($this->runs($departed)));
        $this->end();
    }

    public function testAWatchedTargetGoneIsShownAsItsRowStoodAndNotAsTheObjectWasLeft(): void
    {
        // A relay is a class the history watches. Its name changed on the object and never
        // written -- Doctrine writes no UPDATE of a row it deletes: the list before the DELETE
        // shows the row as it stood there, not the object the application removed, nor the row
        // once the DELETE ran, which is none.
        $this->em->persist($first = new ListFirst());
        $first->relays->add($a = new Relay('a'));
        $first->relays->add(new Relay('b'));
        $this->em->persist($a);
        $this->em->persist($first->relays[1] ?? throw new \LogicException('no relay'));
        $this->em->flush();
        $departed = new DepartedObjects();
        $this->em->getEventManager()->addEventListener([Events::preRemove, Events::postRemove], new class($departed, $this->statements) {
            public function __construct(private readonly DepartedObjects $departed, private readonly \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog $log)
            {
            }

            public function preRemove(LifecycleEventArgs $args): void
            {
                $manager = $args->getObjectManager();
                \assert($manager instanceof \Doctrine\ORM\EntityManagerInterface);
                $this->departed->leaving($manager, $args->getObject());
            }

            public function postRemove(LifecycleEventArgs $args): void
            {
                $this->departed->gone($args->getObject(), $this->log->position());
            }
        });

        $this->begin();
        $a->name = 'a, renamed and never written';
        $this->em->remove($a);
        $this->em->flush();

        self::assertSame([[['a', 'b'], ['b']]], array_map(static fn (array $run): array => [$run['old'], $run['new']], $this->runs($departed)));
        $this->end();
    }

    public function testAWatchedTargetGoneIsNamedByItsRowAsItStoodBeforeItWent(): void
    {
        // A corner shelf is a shelf the history watches: its row before the DELETE is what it is
        // shown as -- here by the identifier the row held, which Doctrine clears on the object.
        $this->em->persist($shelf = new Shelf());
        $this->em->persist($corner = new CornerShelf());
        $shelf->neighbours->add($corner);
        $this->em->flush();
        $cornerId = $corner->id;

        $this->begin();
        $this->em->remove($corner);
        $this->em->flush();

        self::assertNull($corner->id, 'the premise: the object no longer says which row it was');
        self::assertSame([[[$cornerId], []]], array_map(static fn (array $run): array => [$run['old'], $run['new']], $this->runs()));
        $this->end();
    }

    public function testATargetWhoseRowIsGoneAndWhichNothingHoldsIsNamedByItsIdentifier(): void
    {
        // Its row deleted by the application's own statement, the manager cleared: no row the
        // history watched, no object removed, none managed -- a reference the representer finds
        // no row for, and the identifier. Looked at right after its DELETE as a flush removing
        // it would have had it looked at, so that its links going is a fact and not doubt.
        $php = $this->aTag('php');
        $db = $this->aTag('db');
        $article = $this->anArticle('One', $php, $db);
        [$dbId, $articleId] = [$db->id, $article->id];
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->links()->rememberTheHoldersOf($this->em, [$db], Article::class, 'tags', fn (string $of, string $id): array => $this->memory()->replayed($this->em)->linkPositionsOf($of, $id));
        $this->statements->watch(0, \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\JoinRowMemory::associationOf(Article::class, 'tags'), 'Tag', ['id'], [(string) $dbId => true], 'SELECT article_id FROM article_tag WHERE tag_id = ?');
        $this->em->clear();
        $this->em->getConnection()->executeStatement('DELETE FROM Tag WHERE id = ?', [$dbId]);

        $runs = array_values(array_filter($this->runs(), static fn (array $run): bool => $run['ownerId'] === (string) $articleId));
        self::assertSame([[['php', $dbId], ['php']]], array_map(static fn (array $run): array => [$run['old'], $run['new']], $runs));
        $this->end();
    }

    public function testWhatElseARepresenterThrowsIsTheApplicationsFailureAndTheContributionIsNotWritten(): void
    {
        // The representer reads a property the object has lost -- after the flush, before the
        // list is put together: not a row gone, so no identifier stands in for it. The failure
        // is handed on, and that contribution says nothing; the others are written.
        $php = $this->aTag('php');
        $es = $this->aTag('es');
        $one = $this->anArticle('One');
        $two = $this->anArticle('Two', $php);

        $this->begin();
        $one->tags->add($es);
        $two->tags->add($es);
        $this->em->flush();
        unset($php->label);

        $failures = [];
        $runs = $this->runs(failed: static function (\Throwable $e) use (&$failures): void {
            $failures[] = $e::class;
        });

        self::assertSame([['One', [], ['es']]], $this->said($runs));
        self::assertSame([\Error::class], $failures);
        $this->end();
    }

    /** @var array<string, string> */
    private array $titles = [];

    private function aTag(string $label): Tag
    {
        $this->em->persist($tag = new Tag($label));
        $this->em->flush();

        return $tag;
    }

    private function anArticle(string $title, Tag ...$tags): Article
    {
        $article = new Article($title);

        foreach ($tags as $tag) {
            $article->tags->add($tag);
        }

        $this->em->persist($article);
        $this->em->flush();
        $this->titles[(string) $article->id] = $title;

        return $article;
    }

    private function afterTheUpdateOf(Article $article, \Closure $then): void
    {
        $listener = new class($article, $then) {
            private bool $ran = false;

            public function __construct(private readonly Article $article, private readonly \Closure $then)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->article && !$this->ran) {
                    $this->ran = true;
                    ($this->then)();
                }
            }
        };
        $manager = $this->em->getEventManager();
        $there = $manager->getListeners(Events::postUpdate);

        foreach ($there as $one) {
            $manager->removeEventListener([Events::postUpdate], $one);
        }

        $manager->addEventListener([Events::postUpdate], $listener);

        foreach ($there as $one) {
            $manager->addEventListener([Events::postUpdate], $one);
        }
    }

    /**
     * @return list<array{owner: class-string, ownerId: string, ownerKey: array<string, mixed>, collection: string, flush: int|null, at: list<int>, old: list<mixed>, new: list<mixed>}>
     */
    private function runs(?DepartedObjects $departed = null, ?\Closure $failed = null): array
    {
        $listener = $this->listener();
        $memory = $this->memory();
        $replay = $memory->replayed($this->em);
        $identity = new RowIdentity(
            (new \ReflectionMethod(AuditSubscriber::class, 'identifierOf'))->getClosure($listener),
            (new \ReflectionMethod(AuditSubscriber::class, 'identifierFrom'))->getClosure($listener),
        );

        return (new LinkRuns($identity))->of($this->em, $replay, $this->statements, LinkFacts::of($this->em, $replay, $this->statements, $memory->links()), $departed, $failed);
    }

    /**
     * @param list<array{ownerId: string, old: list<mixed>, new: list<mixed>}>|null $runs
     *
     * @return list<array{0: string, 1: list<mixed>, 2: list<mixed>}>
     */
    private function said(?array $runs = null): array
    {
        return array_map(fn (array $run): array => [$this->titles[$run['ownerId']] ?? $run['ownerId'], $run['old'], $run['new']], $runs ?? $this->runs());
    }

    private function begin(): void
    {
        $this->em->getConnection()->beginTransaction();
    }

    private function end(): void
    {
        $this->em->getConnection()->rollBack();
    }

    private function listener(): AuditSubscriber
    {
        foreach ($this->em->getEventManager()->getListeners(Events::onFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                return $listener;
            }
        }

        self::fail('the premise: the listener is attached');
    }

    private function memory(): RowMemory
    {
        $memory = (new \ReflectionProperty(AuditSubscriber::class, 'rows'))->getValue($this->listener());
        self::assertInstanceOf(RowMemory::class, $memory);

        return $memory;
    }

    private function links(): \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\JoinRowMemory
    {
        return $this->memory()->links();
    }
}
