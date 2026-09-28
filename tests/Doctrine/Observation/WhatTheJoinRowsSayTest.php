<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\JoinRowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LinkFacts;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\WatchedRows;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CornerShelf;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MisdeclaredTracking;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shelf;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Stop;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;

/**
 * What the statements of an owning ManyToMany's join rows did (5.3a): a link come or gone, told
 * against what the owner's rows held -- or doubt, never a list made up for want of one. Facts
 * only; nothing writes a record of them yet.
 *
 * Each scenario runs inside a transaction of the application's own, so that nothing is settled
 * before the facts are read, and the accounts are seeded by hand here as the listener will seed
 * them in onFlush.
 */
final class WhatTheJoinRowsSayTest extends DoctrineTestCase
{
    private JoinRowMemory $links;

    /** @var array<string, string> every tag's label, by id */
    private array $labels = [];

    /** @var array<string, string> every owner's name, by id */
    private array $names = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->watchTheConnection(FailurePolicy::Log);
        $this->links = new JoinRowMemory($this->statements);
    }

    public function testALinkAddedAndOneRemovedAreAFactEachOfTheFlushThatWroteThem(): void
    {
        [$article, $php, $db] = $this->anArticle('One', 'php', 'db');
        $article->tags->removeElement($db);
        $this->em->flush();
        $es = $this->aTag('es');

        $this->begin();
        $this->seed($article);
        $article->tags->add($es);
        $article->tags->removeElement($php);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['One -php (row)', 'One +es (row)'], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertCount(1, array_unique(array_column($told->facts(), 'flush')), 'both the flush\'s');
        self::assertNotNull($told->facts()[0]['flush']);
        self::assertSame(['es'], $this->heldBy($told, $article));
        $this->end();
    }

    public function testAnOwnerInsertedStartsFromNothingAndNeedsNoAccount(): void
    {
        $php = $this->aTag('php');

        $this->begin();
        [$article] = $this->anArticle('New', $php);

        $told = $this->told();
        self::assertSame(['New +php (row)'], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertSame(['php'], $this->heldBy($told, $article));
        $this->end();
    }

    public function testALinkOfAnOwnerNothingKnowsOfIsDoubtAndNoFact(): void
    {
        [$article] = $this->anArticle('One', 'php');
        $db = $this->aTag('db');

        $this->begin();
        $article->tags->add($db);
        $this->em->flush();

        $told = $this->told();
        self::assertSame([], $this->said($told));
        self::assertSame(['a link of '.self::tags().' '.$article->id.' added, and what its rows held is not known'], array_column($told->doubts(), 'doubt'));
        self::assertNull($this->heldBy($told, $article));
        $this->end();
    }

    public function testAnOwnersLinksAllTakenAreWhatItsRowsHeldAndDoubtWhereThatIsNotKnown(): void
    {
        // clear(): one DELETE by the owner's key. With its links read, a fact for each, in the
        // order of the targets' keys; without, doubt -- and not a collection emptied of nothing.
        [$read] = $this->anArticle('Read', 'php', 'db');
        [$unread] = $this->anArticle('Unread', 'es');

        $this->begin();
        $this->seed($read);
        $read->tags->clear();
        $unread->tags->clear();
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['Read -php (emptied)', 'Read -db (emptied)'], $this->said($told));
        self::assertSame(['every link of '.self::tags().' '.$unread->id.' taken, and which they were is not known'], array_column($told->doubts(), 'doubt'));
        self::assertSame([[], null], [$this->heldBy($told, $read), $this->heldBy($told, $unread)]);
        $this->end();
    }

    public function testWhatAnAccountDidNotHoldIsDoubtAndWhatNoRowWasThereForIsNothing(): void
    {
        // Rows the log never saw -- another client's, written past this connection -- make an
        // account wrong, and the statements that meet them say so: a link taken that the account
        // did not hold, and all of an owner's links taken, more than it held. A link taken that
        // no row was there for took nothing, and is nothing.
        [$one] = $this->anArticle('One', 'php');
        [$two] = $this->anArticle('Two', 'db');
        $es = $this->aTag('es');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->seed($one);
        $this->seed($two);
        $native = $this->em->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->exec(sprintf('INSERT INTO article_tag (article_id, tag_id) VALUES (%d, %d), (%d, %d)', $one->id, $es->id, $two->id, $es->id));
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM article_tag WHERE article_id = ? AND tag_id = ?', [$one->id, $es->id]);
        $connection->executeStatement('DELETE FROM article_tag WHERE article_id = ? AND tag_id = ?', [$one->id, $es->id]);
        $two->tags->clear();
        $this->em->flush();

        $told = $this->told();
        self::assertSame([], $this->said($told));
        self::assertSame([
            'a link of '.self::tags().' '.$one->id.' taken that what its rows held did not have',
            'every link of '.self::tags().' '.$two->id.' taken: 2 rows, and 1 held',
        ], array_column($told->doubts(), 'doubt'));
        self::assertSame([null, null], [$this->heldBy($told, $one), $this->heldBy($told, $two)], 'and neither is known after');
        $this->end();
    }

    public function testALinkTakenBackIsNoFactAndLeavesWhatTheRowsHeld(): void
    {
        [$article] = $this->anArticle('One', 'php');
        $db = $this->aTag('db');

        $this->begin();
        $this->seed($article);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $article->tags->add($db);
        $this->em->flush();
        $connection->rollBack();

        $told = $this->told();
        self::assertSame([], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertSame(['php'], $this->heldBy($told, $article));
        $this->end();
    }

    public function testAnAccountReadAfterAStatementTakenBackLaterDoesNotKeepIt(): void
    {
        // The account is read while a link the application inserted inside a savepoint is there,
        // and the savepoint is then rolled back. What the account held of that link is undone:
        // the next link is added to a list without it.
        [$article] = $this->anArticle('One', 'php');
        $db = $this->aTag('db');
        $es = $this->aTag('es');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $db->id]);
        $this->seed($article);
        self::assertSame(['db', 'php'], $this->labelsOf(array_keys($this->links->links()[self::tags()][(string) $article->id]['targets'])), 'the premise: the account holds the link');
        $connection->rollBack();

        $article->tags->add($es);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['One +es (row)'], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertSame(['es', 'php'], $this->heldBy($told, $article));
        $this->end();
    }

    public function testALinkTakenFromAnAccountThatIsTakenBackIsGivenBackToIt(): void
    {
        // The mirror of the one above: a link the application deleted inside a savepoint is not
        // in the account read then, and the rollback gives it back to what the rows hold.
        [$article, , $db] = $this->anArticle('One', 'php', 'db');
        $es = $this->aTag('es');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM article_tag WHERE article_id = ? AND tag_id = ?', [$article->id, $db->id]);
        $this->seed($article);
        $connection->rollBack();

        $article->tags->add($es);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['One +es (row)'], $this->said($told));
        self::assertSame(['db', 'es', 'php'], $this->heldBy($told, $article));
        $this->end();
    }

    public function testAnAccountReadAfterATargetWentAndCameBackIsNotKnown(): void
    {
        // A tag's row deleted inside a savepoint -- its links going with it by the database, or
        // left as rows of a tag that is not there -- then the account read, then the rollback.
        // Which of those the account saw is not a statement's to say: not known.
        [$article, $php] = $this->anArticle('One', 'php');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [$php->id]);
        $this->seed($article);
        $connection->rollBack();

        self::assertNull($this->heldBy($this->told(), $article));
        $this->end();
    }

    public function testTheLinksOfACollectionNotAuditedAreNeitherFactsNorDoubt(): void
    {
        $this->em->persist($stop = new Stop('Elm'));
        $this->em->persist($route = new Route('R1'));
        $this->em->flush();

        $this->begin();
        $route->detours->add($stop);
        $this->em->flush();

        $told = $this->told();
        self::assertSame([[], []], [$told->facts(), $told->doubts()]);
        $this->end();
    }

    public function testAnAccountThatCannotBeUndoneIsNotKnown(): void
    {
        // All of an owner's links taken by a statement that does not say which, before the
        // account was read and then taken back: which rows came back is nowhere.
        [$article] = $this->anArticle('One', 'php');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM article_tag WHERE article_id = ?', [$article->id]);
        $this->seed($article);
        $connection->rollBack();

        $told = $this->told();
        self::assertNull($this->heldBy($told, $article));
        $this->end();
    }

    public function testATargetGoneIsTakenOutOfEveryListItWasInWithTheRestOfTheList(): void
    {
        // One holds a and b, two holds a, three holds b. a goes: its holders were read in one
        // question, and each loses a from its whole list -- by the database, for join columns
        // that cascade, with no statement of the join table's at all.
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        [$one] = $this->anArticle('One', $a, $b);
        [$two] = $this->anArticle('Two', $a);
        [$three] = $this->anArticle('Three', $b);

        $this->begin();
        $this->holdersOf($a);
        $this->em->remove($a);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['One -a (target)', 'Two -a (target)'], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertSame([['b'], []], [$this->heldBy($told, $one), $this->heldBy($told, $two)]);
        self::assertArrayNotHasKey((string) $three->id, $told->states()[self::tags()] ?? []);
        $this->end();
    }

    public function testATargetGoneWhoseHoldersWereNeverReadIsDoubt(): void
    {
        // Of every collection it may have been in, the ones no fixture here holds it in too:
        // what was not read is not known, whether or not the answer would have been none.
        $a = $this->aTag('a');
        $this->anArticle('One', $a);
        $id = $a->id;

        $this->begin();
        $this->em->remove($a);
        $this->em->flush();

        $told = $this->told();
        self::assertSame([], $this->said($told));
        self::assertSame(array_map(
            static fn (string $of): string => Tag::class.' '.$id.' went, and which '.$of.' held it is not known',
            [self::tags(), JoinRowMemory::associationOf(MisdeclaredTracking::class, 'tags'), JoinRowMemory::associationOf(Shelf::class, 'labels')],
        ), array_column($told->doubts(), 'doubt'));

        $this->end();
    }

    public function testHoldersReadAfterATargetWentAreNoAccountOfIt(): void
    {
        // Its row deleted by the application, and its holders read after: what they hold is what
        // is left, and what it was taken out of is still not known. And a DELETE that took no row
        // took nothing out of anything.
        $a = $this->aTag('a');
        $this->anArticle('One', $a);
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [999999]);
        self::assertSame([], $this->told()->doubts(), 'no row, nothing taken out');
        $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [$a->id]);
        $this->holdersOf($a);

        $told = $this->told();
        $doubts = array_column($told->doubts(), 'doubt');
        self::assertSame(array_map(
            fn (string $of): string => Tag::class.' '.$a->id.' went, and which '.$of.' held it is not known',
            [self::tags(), JoinRowMemory::associationOf(MisdeclaredTracking::class, 'tags'), JoinRowMemory::associationOf(Shelf::class, 'labels')],
        ), array_values(array_filter($doubts, static fn (string $doubt): bool => str_contains($doubt, 'and which'))));

        // And where its rows are still there to read -- a database that does not enforce the
        // join table's foreign keys, as SQLite does not here -- the holder read has an account
        // that holds a statement it cannot undo: not known either, and no fact.
        self::assertSame([], $told->facts());
        self::assertSame([], array_values(array_filter($doubts, static fn (string $doubt): bool => !str_contains($doubt, 'and which') && !str_contains($doubt, 'and whether'))));
        $this->end();
    }

    public function testATargetTakenOutByTheJoinTablesOwnStatementIsAFactPerHolderAndCounted(): void
    {
        // The statement Doctrine writes when join columns do not cascade: every owner's row of
        // the target. As many facts as rows it took -- and a holder nobody knew of is the
        // difference, said.
        $a = $this->aTag('a');
        [$one] = $this->anArticle('One', $a);
        [$two] = $this->anArticle('Two');
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->holdersOf($a);
        $connection = $this->em->getConnection();
        $connection->insert('article_tag', ['article_id' => $two->id, 'tag_id' => $a->id]);
        $connection->executeStatement('DELETE FROM article_tag WHERE tag_id = ?', [$a->id]);

        $told = $this->told();
        self::assertSame(['One -a (target)'], $this->said($told));
        self::assertSame([
            'a link of '.self::tags().' '.$two->id.' added, and what its rows held is not known',
            Tag::class.' '.$a->id.' taken out of 2 of '.self::tags().', and 1 were known to hold it',
        ], array_column($told->doubts(), 'doubt'));
        self::assertSame([], $this->heldBy($told, $one));
        $this->end();
    }

    public function testAnOwnerGoneHoldsNothingAndItsLinksAreNoFactsOfTheirOwn(): void
    {
        [$article] = $this->anArticle('One', 'php');

        $this->begin();
        $this->seed($article);
        $id = (string) $article->id;
        $this->em->remove($article);
        $this->em->flush();

        $told = $this->told();
        self::assertSame([], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertSame([], $told->states()[self::tags()][$id]);
        $this->end();
    }

    public function testAnInheritedCollectionsLinksAreItsDeclaringClasssCollection(): void
    {
        $php = $this->aTag('php');
        $this->em->persist($shelf = new CornerShelf());
        $this->em->flush();
        $this->names[(string) $shelf->id] = 'Corner';

        $this->begin();
        $this->links->rememberTheLinksOf($this->em, $shelf, 'labels', $this->includes());
        $shelf->labels->add($php);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['Corner +php (row)'], $this->said($told));
        self::assertSame([JoinRowMemory::associationOf(Shelf::class, 'labels')], array_values(array_unique(array_column($told->facts(), 'association'))));
        self::assertSame(Shelf::class, $told->facts()[0]['owner']);
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

    /** Every owner of every watched collection a tag is in, as the listener reads them before it goes. */
    private function holdersOf(Tag $tag): void
    {
        foreach ((new WatchedRows())->linksTo($this->em, $this->em->getClassMetadata(Tag::class)) as [$owner, $association]) {
            $this->links->rememberTheHoldersOf($this->em, $tag, $owner, $association, $this->includes());
        }
    }

    private function seed(Article $article): void
    {
        $this->links->rememberTheLinksOf($this->em, $article, 'tags', $this->includes());
    }

    /** @return \Closure(string, string): list<int> */
    private function includes(): \Closure
    {
        return fn (string $of, string $id): array => $this->memory()->replayed($this->em)->linkPositionsOf($of, $id);
    }

    private function told(): LinkFacts
    {
        return LinkFacts::of($this->em, $this->memory()->replayed($this->em), $this->statements, $this->links);
    }

    /**
     * @return list<string>
     */
    private function said(LinkFacts $told): array
    {
        return array_map(
            fn (array $fact): string => sprintf('%s %s%s (%s)', $this->names[$fact['ownerId']] ?? $fact['ownerId'], $fact['arrived'] ? '+' : '-', $this->labels[$fact['targetId']] ?? $fact['targetId'], $fact['cause']),
            $told->facts(),
        );
    }

    /**
     * @return list<string>|null
     */
    private function heldBy(LinkFacts $told, object $owner): ?array
    {
        $of = $owner instanceof Shelf ? JoinRowMemory::associationOf(Shelf::class, 'labels') : self::tags();
        $state = $told->states()[$of][(string) $owner->id] ?? null;

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
