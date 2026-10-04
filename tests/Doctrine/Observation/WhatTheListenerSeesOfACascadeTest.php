<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\JoinRowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LinkFacts;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Borsche\ElasticsearchAuditBundle\Tests\TestConnection;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * What a target's DELETE took with it, as seen right after it (5.3, the cascade): a link goes
 * with its target only where the rows show it gone at that moment, its fact that DELETE's; a
 * database that keeps the row keeps the link; and what was not seen is doubt.
 */
final class WhatTheListenerSeesOfACascadeTest extends DoctrineTestCase
{
    /** @var array<string, string> */
    private array $labels = [];

    /** @var array<string, string> */
    private array $titles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->watchTheConnection(FailurePolicy::Log);
    }

    public function testTwoTargetsGoneInOneFlushAreEachTheirOwnDeletes(): void
    {
        // Doctrine runs both DELETEs before announcing either removal: each link is the fact of
        // the DELETE it went with, at that DELETE's position.
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $this->anArticle('One', $a, $b);

        $this->begin();
        $this->em->remove($a);
        $this->em->remove($b);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['One -a (target)', 'One -b (target)'], $this->said($told));
        self::assertSame(['a' => 'DELETE FROM Tag WHERE id = ?', 'b' => 'DELETE FROM Tag WHERE id = ?'], $this->statementsOf($told));
        self::assertNotSame($told->facts()[0]['at'], $told->facts()[1]['at'], 'two DELETEs, two positions');
        $this->end();
    }

    public function testATargetGoneThenItsOwnerIsTheTargetsDeleteAndNotTheOwners(): void
    {
        // The tag's DELETE, then the article's: what was seen right after the tag's -- before the
        // article's ran -- says whether the link went with the tag.
        $a = $this->aTag('a');
        $one = $this->anArticle('One', $a);

        $this->begin();
        $this->em->remove($a);
        $this->em->remove($one);
        $this->em->flush();

        $told = $this->told();
        self::assertSame(['One -a (target)'], $this->said($told));
        self::assertSame(['a' => 'DELETE FROM Tag WHERE id = ?'], $this->statementsOf($told));
        self::assertSame([], $told->doubts());
        $this->end();
    }

    public function testWhatIsPublishedOfATargetGoneIsEachOwnersWholeListOnceAndAsTheFlushs(): void
    {
        // Published, not only told: each article that held the tag has a record of its list
        // moving -- the whole list, before and after -- signed and timed as the flush that removed
        // the tag, as the other record of that flush is; and the next flush publishes nothing of
        // it again.
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $one = $this->anArticle('One', $a, $b);
        $two = $this->anArticle('Two', $a);
        $three = $this->anArticle('Three');
        $this->gateway->documents = [];

        $this->em->remove($a);
        $three->title = 'Three, again';
        $this->em->flush();

        $documents = $this->documents();
        $tags = array_values(array_filter($documents, static fn (array $d): bool => isset($d['changes']['tags'])));
        self::assertSame(
            [[(string) $one->id, ['old' => ['a', 'b'], 'new' => ['b']]], [(string) $two->id, ['old' => ['a'], 'new' => []]]],
            array_map(static fn (array $d): array => [(string) $d['objectId'], $d['changes']['tags']], $tags),
        );
        self::assertSame(['update', 'update'], array_column($tags, 'event'));
        $provenance = static fn (array $d): array => array_diff_key($d, array_flip(['objectId', 'changes', 'id']));
        $title = array_values(array_filter($documents, static fn (array $d): bool => isset($d['changes']['title'])))[0] ?? null;
        self::assertNotNull($title, 'the premise: the flush had another record');
        self::assertSame([$provenance($title), $provenance($title)], array_map($provenance, $tags), 'signed and timed as the flush that removed the tag');

        $this->gateway->documents = [];
        $one->title = 'One, again';
        $this->em->flush();

        self::assertSame([['title', 'status']], array_map(static fn (array $d): array => array_keys($d['changes']), $this->documents()), 'nothing of the tag published again');
    }

    public function testATargetLinkedAndRemovedInOneFlushIsLookedAtAfterItsDelete(): void
    {
        // Seed 250 of the removals' world: the article gains a tag nobody held when the flush
        // began, and the same flush removes the tag. The join row is INSERTed, then the tag's
        // DELETE takes it by the cascade. Who held the tag was read in onFlush, before the INSERT:
        // the INSERT is what says to look after the DELETE, and what was seen there is the fact.
        $a = $this->aTag('a');
        $one = $this->anArticle('One');
        $other = $this->anArticle('Other');
        $this->gateway->documents = [];
        $from = $this->statements->position();

        $one->tags->add($a);
        $this->em->remove($a);
        $other->title = 'Other, again';
        $this->em->flush();

        $ran = [];

        for ($at = $from + 1; $at <= $this->statements->position(); ++$at) {
            $sql = $this->statements->statement($at)['sql'] ?? '';

            if (str_starts_with($sql, 'INSERT INTO article_tag') || str_starts_with($sql, 'DELETE FROM Tag')) {
                $ran[] = strtok($sql, '(');
            }
        }

        self::assertSame(['INSERT INTO article_tag ', 'DELETE FROM Tag WHERE id = ?'], $ran, 'the premise: the link written, then the tag deleted');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM article_tag'), 'the premise: the database took the link with the tag');

        $documents = $this->documents();
        $tags = array_values(array_filter($documents, static fn (array $d): bool => isset($d['changes']['tags'])));
        self::assertSame([['old' => [], 'new' => ['a']], ['old' => ['a'], 'new' => []]], array_column(array_column($tags, 'changes'), 'tags'), 'the link gained, and gone with its target');

        $provenance = static fn (array $d): array => array_diff_key($d, array_flip(['objectId', 'changes', 'id']));
        $title = array_values(array_filter($documents, static fn (array $d): bool => isset($d['changes']['title'])))[0] ?? null;
        self::assertNotNull($title, 'the premise: the flush had another record');
        self::assertSame([$provenance($title), $provenance($title)], array_map($provenance, $tags), 'both the flush\'s');
    }

    public function testALinkWrittenAndTakenBackBeforeItsTargetsDeleteIsNoFactOfEither(): void
    {
        // A join row the application writes inside a savepoint of its own and takes back, then the
        // tag's DELETE: the INSERT says to look, and the look sees nothing held -- no link gained,
        // and none gone. What says to look is never what says what happened.
        $a = $this->aTag('a');
        // Created with a tag, so that what its rows hold is known: the application's own join rows
        // are told against it.
        $one = $this->anArticle('One', $this->aTag('x'));
        $other = $this->anArticle('Other');
        $connection = $this->em->getConnection();
        $this->unownedStatementsAreExpected = true;
        $this->gateway->documents = [];

        $taking = new class($connection, $other, $one, $a) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly Article $other, private readonly Article $one, private readonly Tag $tag)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->other) {
                    $this->connection->beginTransaction();
                    $this->connection->insert('article_tag', ['article_id' => $this->one->id, 'tag_id' => $this->tag->id]);
                    $this->connection->rollBack();
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $taking);
        $other->title = 'Other, again';
        $this->em->remove($a);
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate], $taking);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_tag WHERE tag_id = ?', [$a->id ?? $this->idOf('a')]), 'the premise: nothing held the tag');
        self::assertSame([], array_values(array_filter($this->documents(), static fn (array $d): bool => isset($d['changes']['tags']))), 'no tag gained, none gone');
    }

    public function testALinkWrittenAndTakenAwayBeforeItsTargetsDeleteIsTwoMovesAndNoThird(): void
    {
        // The application writes a join row for the tag and deletes it again, before the flush's
        // DELETE of the tag: two moves of the list, the rows' own; the DELETE is looked at, and
        // what was seen holds nothing, so it took nothing.
        $a = $this->aTag('a');
        // Created with a tag, so that what its rows hold is known: the application's own join rows
        // are told against it.
        $one = $this->anArticle('One', $this->aTag('x'));
        $other = $this->anArticle('Other');
        $connection = $this->em->getConnection();
        $this->unownedStatementsAreExpected = true;
        $this->gateway->documents = [];

        $writing = new class($connection, $other, $one, $a) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly Article $other, private readonly Article $one, private readonly Tag $tag)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->other) {
                    $this->connection->insert('article_tag', ['article_id' => $this->one->id, 'tag_id' => $this->tag->id]);
                    $this->connection->delete('article_tag', ['article_id' => $this->one->id, 'tag_id' => $this->tag->id]);
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $writing);
        $other->title = 'Other, again';
        $this->em->remove($a);
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate], $writing);

        self::assertSame(
            [['old' => ['x'], 'new' => ['a', 'x']], ['old' => ['a', 'x'], 'new' => ['x']]],
            array_column(array_column(array_values(array_filter($this->documents(), static fn (array $d): bool => isset($d['changes']['tags']))), 'changes'), 'tags'),
            'two moves, and nothing of the DELETE',
        );
    }

    public function testAJoinRowThatFailedToBeWrittenSaysNotToLook(): void
    {
        // An INSERT of the join table that failed -- a link to an article that is not there, which
        // the foreign key refuses -- wrote nothing, and is not a row the tag's DELETE may take: no
        // look after it, and nothing said.
        $a = $this->aTag('a');
        $other = $this->anArticle('Other');
        $connection = $this->em->getConnection();
        $this->unownedStatementsAreExpected = true;
        $from = $this->statements->position();

        $failing = new class($connection, $other, $a) {
            public bool $refused = false;

            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly Article $other, private readonly Tag $tag)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->other) {
                    return;
                }

                $this->connection->beginTransaction();

                try {
                    $this->connection->insert('article_tag', ['article_id' => 900001, 'tag_id' => $this->tag->id]);
                } catch (\Doctrine\DBAL\Exception) {
                    $this->refused = true;
                }

                $this->connection->rollBack();
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $failing);
        $other->title = 'Other, again';
        $this->em->remove($a);
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate], $failing);

        self::assertTrue($failing->refused, 'the premise: the INSERT failed');
        $looked = 0;

        for ($at = $from + 1; $at <= $this->statements->position(); ++$at) {
            $looked += \count($this->statements->observationsOf($at));
        }

        self::assertSame(0, $looked, 'no look after the tag\'s DELETE');
    }

    public function testATargetALaterRemovalWatchesDoesNotTakeTheWatchOfOneOnFlushSaw(): void
    {
        // The tag a is removed before the flush, and onFlush watches it; a listener removes the tag
        // b while the flush runs, and its preRemove watches b for the same flush. Doctrine runs
        // every DELETE after the UPDATEs: both are looked at, each its own fact -- b's watch adds
        // to a's, it does not take its place.
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $this->anArticle('One', $a, $b);
        $other = $this->anArticle('Other');

        $removing = new class($other, $b) {
            public function __construct(private readonly Article $other, private readonly Tag $tag)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->other) {
                    $args->getObjectManager()->remove($this->tag);
                }
            }
        };

        $this->begin();
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $removing);
        $other->title = 'Other, again';
        $this->em->remove($a);
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate], $removing);

        $told = $this->told();
        self::assertSame(['One -a (target)', 'One -b (target)'], $this->said($told));
        self::assertSame([], $told->doubts());
        $this->end();
    }

    public function testWhereTheDatabaseKeepsTheRowTheLinkStaysAndNothingIsSaid(): void
    {
        // No cascade: the tag's row goes and its join rows stay, pointing at nothing. What the
        // history says of the article's list is what the rows did -- nothing.
        $this->withoutForeignKeys();
        $a = $this->aTag('a');
        $one = $this->anArticle('One', $a);

        $this->begin();
        $this->em->remove($a);
        $this->em->flush();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM article_tag'), 'the premise: the row stayed');

        $told = $this->told();
        self::assertSame([], $this->said($told));
        self::assertSame([], $told->doubts());
        self::assertSame([$this->idOf('a')], array_map('strval', array_keys($told->states()[self::tags()][(string) $one->id] ?? [])), 'and it still holds it');
        $this->end();
    }

    public function testWhereTheDatabaseKeepsTheRowTheOwnersDeleteLaterIsNoFactOfTheTargets(): void
    {
        $this->withoutForeignKeys();
        $a = $this->aTag('a');
        $one = $this->anArticle('One', $a);

        $this->begin();
        $this->em->remove($a);
        $this->em->remove($one);
        $this->em->flush();

        self::assertSame([], $this->said($this->told()));
        $this->end();
    }

    public function testADeleteTakenBackAfterItWasSeenTakesItsFactsWithIt(): void
    {
        // DELETE, what was seen after it, and then the rollback to a savepoint opened before the
        // deletions -- by an update's postUpdate, since Doctrine writes updates first: the DELETE
        // is void, and the link's going with it; the article still holds the tag.
        $a = $this->aTag('a');
        $one = $this->anArticle('One', $a);
        $other = $this->anArticle('Other');
        $connection = $this->em->getConnection();

        $this->begin();
        $taking = new class($connection, $other) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly Article $other)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->other) {
                    $this->connection->beginTransaction();
                }
            }

            public function postRemove(LifecycleEventArgs $args): void
            {
                if ($args->getObject() instanceof Tag) {
                    $this->connection->rollBack();
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate, Events::postRemove], $taking);
        $other->title = 'Other, again';
        $this->em->remove($a);
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate, Events::postRemove], $taking);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_tag WHERE article_id = ?', [$one->id]), 'the premise: taken back');

        $told = $this->told();
        self::assertSame([], $this->said($told));
        self::assertSame([$this->idOf('a')], array_map('strval', array_keys($told->states()[self::tags()][(string) $one->id] ?? [])));
        $this->end();
    }

    public function testADeleteRunAgainAfterItWasTakenBackIsLookedAtAgain(): void
    {
        // Watched as a flush removing it would watch it, taken back, and run again: the second
        // DELETE is looked at on its own, and the link's going is its fact.
        $a = $this->aTag('a');
        $this->anArticle('One', $a);
        $aId = $a->id;
        $this->unownedStatementsAreExpected = true;
        $connection = $this->em->getConnection();

        $this->begin();
        $this->watchAsAFlushWould($aId, 'SELECT article_id FROM article_tag WHERE tag_id = ?');
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [$aId]);
        $connection->rollBack();
        $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [$aId]);
        $again = $this->statements->position();

        $told = $this->told();
        self::assertSame(['One -a (target)'], $this->said($told));
        self::assertSame($again, $told->facts()[0]['at'], 'the DELETE that stayed done');
        $this->end();
    }

    public function testOnceAListIsNotKnownEveryTargetThatGoesIsDoubtForItsOwner(): void
    {
        // The first look fails: the article's list is not known from there. Its holders read for
        // the second tag do not make that one known either: the article has an account, and an
        // account that stopped being known may or may not have held it.
        $a = $this->aTag('a');
        $b = $this->aTag('b');
        $one = $this->anArticle('One', $a, $b);
        [$aId, $bId] = [$a->id, $b->id];
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->watchAsAFlushWould($aId, 'SELECT article_id FROM no_such_table WHERE tag_id = ?');
        $this->em->getConnection()->executeStatement('DELETE FROM Tag WHERE id = ?', [$aId]);
        $this->watchAsAFlushWould($bId, 'SELECT article_id FROM article_tag WHERE tag_id = ?');
        $this->em->getConnection()->executeStatement('DELETE FROM Tag WHERE id = ?', [$bId]);

        self::assertSame([
            Tag::class.' '.$aId.' went, and whether '.self::tags().' '.$one->id.' still holds it was not seen',
            Tag::class.' '.$bId.' went, and whether '.self::tags().' '.$one->id.' held it is not known',
        ], array_values(array_filter(array_column($this->told()->doubts(), 'doubt'), static fn (string $d): bool => str_contains($d, self::tags()))));
        $this->end();
    }

    public function testWhatCouldNotBeAskedIsDoubtAndTheListStopsBeingKnown(): void
    {
        $a = $this->aTag('a');
        $one = $this->anArticle('One', $a);
        $aId = $a->id;
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->watchAsAFlushWould($aId, 'SELECT article_id FROM no_such_table WHERE tag_id = ?');
        $this->em->getConnection()->executeStatement('DELETE FROM Tag WHERE id = ?', [$aId]);

        $told = $this->told();
        self::assertSame([], $this->said($told));
        // Of the article's list; the other collections a tag may be in are doubt of their own, their
        // holders never read here.
        self::assertSame([Tag::class.' '.$aId.' went, and whether '.self::tags().' '.$one->id.' still holds it was not seen'], array_values(array_filter(array_column($told->doubts(), 'doubt'), static fn (string $d): bool => str_contains($d, self::tags()))));
        self::assertArrayHasKey((string) $one->id, $told->states()[self::tags()] ?? []);
        self::assertNull($told->states()[self::tags()][(string) $one->id], 'not known, and not held nothing');
        $this->end();
    }

    public function testATargetsRowGoneWithNoOneLookingIsDoubtWhereItWasHeld(): void
    {
        // The application's own DELETE, no flush having said to look: whether the rows went with
        // it is not known -- which is not that nothing held it.
        $a = $this->aTag('a');
        $this->anArticle('One', $a);
        $aId = $a->id;
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->links()->rememberTheHoldersOf($this->em, [$a], Article::class, 'tags', $this->includes());
        $this->em->getConnection()->executeStatement('DELETE FROM Tag WHERE id = ?', [$aId]);

        $told = $this->told();
        self::assertSame([], $this->said($told));
        self::assertStringContainsString('was not seen', implode("\n", array_column($told->doubts(), 'doubt')));
        $this->end();
    }

    public function testAWatchIsForgottenWithItsFlushAndOneLeftBehindMakesNoFactOfAFlush(): void
    {
        $a = $this->aTag('a');
        $this->anArticle('One', $a);
        $this->em->remove($a);
        $this->em->flush();

        self::assertSame(0, $this->statements->watches(), 'gone with the flush that set it');

        // One left behind -- a flush that never came back to say it was over: a DELETE of the
        // same key later is looked at, and its fact is nobody's flush's.
        $b = $this->aTag('b');
        $this->anArticle('Two', $b);
        $bId = $b->id;
        $this->unownedStatementsAreExpected = true;

        $this->begin();
        $this->watchAsAFlushWould($bId, 'SELECT article_id FROM article_tag WHERE tag_id = ?');
        $this->em->getConnection()->executeStatement('DELETE FROM Tag WHERE id = ?', [$bId]);

        $facts = $this->told()->facts();
        self::assertSame([null], array_column($facts, 'flush'), 'belonging to no flush -- said as such, not as any flush\'s record');
        $this->end();
    }

    public function testAFlushLooksOnceForEveryTargetItRemovesThatAnOwnerHolds(): void
    {
        // What the look costs: a question for each such DELETE -- not one for all of them,
        // which would not be right after each. None for a target nothing holds.
        $held = [];

        for ($i = 0; $i < 50; ++$i) {
            $held[] = $this->aTag('h'.$i);
        }

        $loose = $this->aTag('loose');
        $this->anArticle('One', ...$held);

        $this->begin();
        $from = $this->statements->position();

        foreach ([...$held, $loose] as $tag) {
            $this->em->remove($tag);
        }

        $this->em->flush();
        $looked = 0;

        for ($at = $from + 1; $at <= $this->statements->position(); ++$at) {
            $looked += \count($this->statements->observationsOf($at));
        }

        self::assertSame(50, $looked);
        self::assertCount(50, $this->told()->facts());
        $this->end();
    }

    private function withoutForeignKeys(): void
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform()::class;

        if (TestConnection::isSqlite()) {
            $connection->executeStatement('PRAGMA foreign_keys = OFF');
        } elseif (str_contains($platform, 'MySQL')) {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        } else {
            self::markTestSkipped('Not measured on PostgreSQL: its foreign keys cannot be switched off for a session without altering the schema.');
        }
    }

    private function watchAsAFlushWould(int|string|null $tag, string $read): void
    {
        $this->statements->watch(0, self::tags(), 'Tag', ['id'], [(string) $tag => true], $read);
        $this->links()->rememberTheHoldersOf($this->em, [$this->em->find(Tag::class, $tag) ?? throw new \LogicException('no tag')], Article::class, 'tags', $this->includes());
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

    private function idOf(string $label): string
    {
        return (string) array_search($label, $this->labels, true);
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

    private function told(): LinkFacts
    {
        $memory = $this->memory();

        return LinkFacts::of($this->em, $memory->replayed($this->em), $this->statements, $memory->links());
    }

    /** @return list<string> */
    private function said(LinkFacts $told): array
    {
        return array_map(
            fn (array $fact): string => sprintf('%s %s%s (%s)', $this->titles[$fact['ownerId']] ?? $fact['ownerId'], $fact['arrived'] ? '+' : '-', $this->labels[$fact['targetId']] ?? $fact['targetId'], $fact['cause']),
            $told->facts(),
        );
    }

    /** @return array<string, string> each fact's target, and the statement at its position */
    private function statementsOf(LinkFacts $told): array
    {
        $at = [];

        foreach ($told->facts() as $fact) {
            $at[$this->labels[$fact['targetId']] ?? $fact['targetId']] = $this->statements->statement($fact['at'])['sql'] ?? '?';
        }

        return $at;
    }

    /** @return \Closure(string, string): list<int> */
    private function includes(): \Closure
    {
        return fn (string $of, string $id): array => $this->memory()->replayed($this->em)->linkPositionsOf($of, $id);
    }

    private function links(): JoinRowMemory
    {
        return $this->memory()->links();
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
