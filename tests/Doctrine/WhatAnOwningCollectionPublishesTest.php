<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;

/**
 * What an owning ManyToMany publishes once its history is read from its join rows (5.3c), where
 * what is published is decided and not only what the rows did: an owner removed says only that
 * it went, the application's own statement outside every flush is said as such and is no
 * record, a list that did not move is no record, and what could not be followed is said.
 */
final class WhatAnOwningCollectionPublishesTest extends DoctrineTestCase
{
    public function testATargetsDeleteBeforeItsOwnersKeepsItsFactAndItsPlace(): void
    {
        // The tag's DELETE, then the article's: what was seen right after the tag's is that the
        // article lost it there -- a move of its list, before its removal, in that order. The
        // article's removal takes nothing a statement before it had already taken.
        $this->em->persist($a = new Tag('a'));
        $one = new Article('One');
        $one->tags->add($a);
        $this->em->persist($one);
        $this->em->flush();
        $id = $one->id;
        $this->gateway->documents = [];

        $this->em->remove($a);
        $this->em->remove($one);
        $this->em->flush();

        self::assertSame(
            [['article', $id, 'update', ['old' => ['a'], 'new' => []]], ['article', $id, 'remove', null]],
            array_map(static fn (array $d): array => [$d['objectType'], $d['objectId'], $d['event'], $d['changes']['tags'] ?? null], $this->documents()),
        );
    }

    public function testEachOwnersLinksJoinItsOwnRecordOfTheFlush(): void
    {
        // Two articles retitled and each given a tag in one flush: each list's move is part of its
        // own article's record -- the second owner's is not taken for the first's, nor left out.
        $this->em->persist($tag = new Tag('t'));
        $this->em->persist($one = new Article('One'));
        $this->em->persist($two = new Article('Two'));
        $this->em->flush();
        $this->gateway->documents = [];

        $one->title = 'One!';
        $one->tags->add($tag);
        $two->title = 'Two!';
        $two->tags->add($tag);
        $this->em->flush();

        self::assertSame(
            [[$one->id, 'One!', ['old' => [], 'new' => ['t']]], [$two->id, 'Two!', ['old' => [], 'new' => ['t']]]],
            array_map(static fn (array $d): array => [$d['objectId'], $d['changes']['title']['new'] ?? null, $d['changes']['tags'] ?? null], $this->documents()),
        );
    }

    public function testAnOwnersRecordStandsWhereItsOwnStatementRanNotWhereItsLinksDid(): void
    {
        // Two articles retitled and the first also tagged: Doctrine runs both UPDATEs and then the
        // join row. The tag joins the first article's record, which still begins where that
        // article's UPDATE ran -- before the second's.
        $this->em->persist($tag = new Tag('t'));
        $this->em->persist($one = new Article('One'));
        $this->em->persist($two = new Article('Two'));
        $this->em->flush();
        $this->gateway->documents = [];
        $this->queries = [];

        $one->title = 'One!';
        $one->tags->add($tag);
        $two->title = 'Two!';
        $this->em->flush();

        $writes = array_values(array_filter($this->queries, static fn (string $sql): bool => (bool) preg_match('/^(INSERT|UPDATE)\b/', $sql)));
        self::assertSame(['UPDATE Article', 'UPDATE Article', 'INSERT INTO'], array_map(static fn (string $sql): string => implode(' ', \array_slice(explode(' ', $sql), 0, 2)), $writes), 'the premise: the other article\'s UPDATE between the owner\'s and its link');

        self::assertSame(
            [[$one->id, true], [$two->id, false]],
            array_map(static fn (array $d): array => [$d['objectId'], isset($d['changes']['tags'])], $this->documents()),
        );
    }

    public function testTwoCollectionsOfOneOwnerInOneFlushAreOneRecord(): void
    {
        // A shelf has nothing of its own to change: what its two lists did in one flush is one
        // record of it, the second joining the one the first made -- the key a list joins under
        // is its owner's, its collection's and its flush's, and dropping the collection made the
        // second list a record of its own.
        $this->em->persist($tag = new Tag('t'));
        $this->em->persist($corner = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CornerShelf());
        $this->em->persist($shelf = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shelf());
        $this->em->flush();
        $this->gateway->documents = [];

        $shelf->labels->add($tag);
        $shelf->neighbours->add($corner);
        $this->em->flush();

        $documents = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectId'] === $shelf->id));

        self::assertCount(1, $documents, 'one record of the shelf');
        self::assertSame(['labels', 'neighbours'], array_values(array_intersect(['labels', 'neighbours'], array_keys($documents[0]['changes']))));
    }

    public function testALinkWrittenBeforeItsOwnerWentInOneFlushIsSaidBeforeTheRemoval(): void
    {
        // A tag added and the article removed in one flush: Doctrine does not write the link of
        // an owner it is removing, and there is only the removal. Written by a listener of the
        // application's before the removal ran, the link is told -- one that arrived is not unsaid
        // by its owner going after it.
        $this->em->persist($a = new Tag('a'));
        $this->em->persist($b = new Tag('b'));
        $one = new Article('One');
        $one->tags->add($a);
        $this->em->persist($one);
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $id = $one->id;
        $this->gateway->documents = [];
        $this->queries = [];

        $one->tags->add($b);
        $this->em->remove($one);
        $this->em->flush();

        self::assertSame([], array_values(array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO article_tag'))), 'the premise: Doctrine writes no link of an owner it removes');
        self::assertSame([['remove', null]], array_map(static fn (array $d): array => [$d['event'], $d['changes']['tags'] ?? null], $this->documents()));

        $this->em->persist($two = new Article('Two'));
        $two->tags->add($a);
        $this->em->flush();
        $this->gateway->documents = [];
        $this->unownedStatementsAreExpected = true;
        $this->em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postUpdate], new class($this->em, $other, $two, $b) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $other, private readonly Article $two, private readonly Tag $b)
            {
            }

            public function postUpdate(\Doctrine\Persistence\Event\LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->other || $this->ran) {
                    return;
                }

                $this->ran = true;
                $this->em->getConnection()->insert('article_tag', ['article_id' => $this->two->id, 'tag_id' => $this->b->id]);
            }
        });
        $twoId = $two->id;
        $other->title = 'Other, again';
        $this->em->remove($two);
        $this->em->flush();

        self::assertSame(
            [['update', ['old' => ['a'], 'new' => ['a', 'b']]], ['remove', null]],
            array_map(static fn (array $d): array => [$d['event'], $d['changes']['tags'] ?? null], array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectId'] === $twoId))),
        );
    }

    public function testTheLinksAnOwnersRemovalTakesAreItsRemovals(): void
    {
        // Where join columns do not cascade, Doctrine deletes an owner's join rows by its key
        // right before the owner's row: part of the removal, and said by it -- written here as
        // Doctrine would write it, by a listener inside a flush.
        $this->em->persist($a = new Tag('a'));
        $one = new Article('One');
        $one->tags->add($a);
        $this->em->persist($one);
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $id = $one->id;
        $this->gateway->documents = [];
        $this->unownedStatementsAreExpected = true;

        $this->em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postUpdate], new class($this->em, $other, $id) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $other, private readonly mixed $id)
            {
            }

            public function postUpdate(\Doctrine\Persistence\Event\LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->other || $this->ran) {
                    return;
                }

                $this->ran = true;
                $this->em->getConnection()->executeStatement('DELETE FROM article_tag WHERE article_id = ?', [$this->id]);
                $this->em->getConnection()->executeStatement('DELETE FROM Article WHERE id = ?', [$this->id]);
            }
        });
        $other->title = 'Other, again';
        $this->em->flush();

        self::assertSame([], array_values(array_filter($this->documents(), static fn (array $d): bool => isset($d['changes']['tags']))), 'no move of a list the removal took');
        self::assertContains(['article', $id, 'remove'], array_map(static fn (array $d): array => [$d['objectType'], $d['objectId'], $d['event']], $this->documents()));
    }

    public function testALinkTheApplicationWritesOutsideEveryFlushIsSaidAndIsNoRecord(): void
    {
        $this->em->persist($a = new Tag('a'));
        $this->em->persist($b = new Tag('b'));
        $one = new Article('One');
        $one->tags->add($a);
        $this->em->persist($one);
        $this->em->flush();
        $this->gateway->documents = [];
        $this->unownedStatementsAreExpected = true;

        $this->em->getConnection()->insert('article_tag', ['article_id' => $one->id, 'tag_id' => $b->id]);
        $one->title = 'One, again';
        $this->em->flush();

        self::assertSame([['title', 'status']], array_map(static fn (array $d): array => array_keys($d['changes']), $this->documents()), 'the flush\'s own change, and nothing of the link');
        self::assertNotSame([], array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'tags of a '.Article::class) && str_contains($line, 'outside every flush')), 'said: '.implode(' | ', $this->logs));
        $this->logs = [];
    }

    public function testAListThatDidNotMoveIsNoRecord(): void
    {
        // One tag taken off and another of the same label put on: two join rows, and a list that
        // reads as it did -- no move, as the builder says of any field its comparator calls the same.
        $this->em->persist($x = new Tag('x'));
        $this->em->persist($again = new Tag('x'));
        $one = new Article('One');
        $one->tags->add($x);
        $this->em->persist($one);
        $this->em->flush();
        $this->gateway->documents = [];
        $this->queries = [];

        $one->tags->removeElement($x);
        $one->tags->add($again);
        $this->em->flush();

        self::assertCount(2, array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, 'article_tag') && !str_starts_with($sql, 'SELECT')), 'the premise: a DELETE and an INSERT');
        self::assertSame([], $this->documents());
    }

    public function testWhatTheLinksCouldNotBeFollowedThroughIsSaid(): void
    {
        // An article written past this connection and all its links taken by the application's
        // own statement inside a flush: which they were is not known, and the log says so.
        $native = $this->em->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $this->em->persist($a = new Tag('a'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $native->exec("INSERT INTO Article (id, title, status, views) VALUES (900401, 'Far', 'draft', 0)");
        $native->exec('INSERT INTO article_tag (article_id, tag_id) VALUES (900401, '.$a->id.')');
        $this->unownedStatementsAreExpected = true;

        $this->em->getConnection()->beginTransaction();
        $other->title = 'Other, again';
        $this->em->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM article_tag WHERE article_id = ?', [900401]);
        $other->title = 'Other, once more';
        $this->em->flush();
        $this->em->getConnection()->commit();

        self::assertNotSame([], array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'may be missing what they did') && str_contains($line, Article::class)), 'said: '.implode(' | ', $this->logs));
        $this->logs = [];
    }
}
