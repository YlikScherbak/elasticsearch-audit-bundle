<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Comment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;

final class DoctrineAuditTest extends DoctrineTestCase
{
    public function testCreateRecordsTheInitialValuesOfAuditedFields(): void
    {
        $article = new Article('Hello');
        $article->author = $author = new Author('alice');

        $this->em->persist($author);
        $this->em->persist($article);
        $this->em->flush();

        $document = $this->lastDocument();

        self::assertSame('article', $document['objectType']);
        self::assertSame($article->id, $document['objectId']);
        self::assertSame('create', $document['event']);
        self::assertSame('tests', $document['source']);
        self::assertSame(['old' => null, 'new' => 'Hello'], $document['changes']['title']);
        self::assertSame(['old' => null, 'new' => 'draft'], $document['changes']['status']);
        self::assertSame(['old' => null, 'new' => 'alice'], $document['changes']['author']);
        self::assertArrayNotHasKey('views', $document['changes']);
        self::assertArrayNotHasKey('publishedAt', $document['changes'], 'null → null is not a change');
    }

    public function testUpdateRecordsOldAndNewPlusAlwaysRecordedFields(): void
    {
        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $article->title = 'Hello, world';
        $article->views = 10;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame('update', $this->lastDocument()['event']);
        self::assertSame(['old' => 'Hello', 'new' => 'Hello, world'], $changes['title']);
        self::assertSame(['old' => 'draft', 'new' => 'draft'], $changes['status'], 'always recorded even when unchanged');
        self::assertArrayNotHasKey('views', $changes);
    }

    public function testAnUpdateTouchingOnlyUnauditedFieldsIsSkipped(): void
    {
        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $article->views = 99;
        $this->em->flush();

        self::assertSame([], $this->documents());
    }

    public function testAssociationsAreRecordedThroughTheirRepresenter(): void
    {
        $alice = new Author('alice');
        $bob = new Author('bob');
        $this->em->persist($alice);
        $this->em->persist($bob);

        $article = new Article('Hello');
        $article->author = $alice;
        $this->persisted($article);
        $this->gateway->documents = [];

        $article->author = $bob;
        $this->em->flush();

        self::assertSame(['old' => 'alice', 'new' => 'bob'], $this->lastDocument()['changes']['author']);

        $this->gateway->documents = [];
        $article->author = null;
        $this->em->flush();

        self::assertSame(['old' => 'bob', 'new' => null], $this->lastDocument()['changes']['author']);
    }

    public function testCollectionsAreRecordedAsSnapshotAgainstCurrent(): void
    {
        $php = new Tag('php');
        $es = new Tag('elasticsearch');
        $this->em->persist($php);
        $this->em->persist($es);

        $article = new Article('Hello');
        $article->tags->add($php);
        $this->persisted($article);
        $this->em->clear();

        /** @var Article $article */
        $article = $this->em->find(Article::class, $article->id);
        $this->gateway->documents = [];

        $article->tags->add($this->em->find(Tag::class, $es->id));
        $this->em->flush();

        self::assertSame(['old' => ['php'], 'new' => ['php', 'elasticsearch']], $this->lastDocument()['changes']['tags']);
    }

    public function testARepresenterDescribesTheObjectAsItStandsWhenTheRecordIsBuilt(): void
    {
        // Written down because it surprises people, and because the alternative is worse
        // than the surprise.
        //
        // Doctrine's collection snapshot holds the *objects* that were in the collection,
        // not a copy of what they looked like. A representer runs when the record is
        // built, at the end of the flush — so if the same flush also renamed one of those
        // objects, both sides of the change show the new name. The history then says the
        // article's tags went from ["php 9"] to ["php 9", "elasticsearch"], and "php" is
        // nowhere.
        //
        // Representing eagerly, field by field, as Doctrine computes each change, would
        // mean running application code inside onFlush for every audited association of
        // every entity in the flush — and a representer that touches the entity manager
        // there is a much worse failure than a label that reads as of today. The rule
        // this leaves the caller is in the README: represent by something that does not
        // move, an id or a reference, and the record is true whenever it is read.
        $php = new Tag('php');
        $es = new Tag('elasticsearch');
        $this->em->persist($php);
        $this->em->persist($es);

        $article = new Article('Hello');
        $article->tags->add($php);
        $this->persisted($article);
        $this->gateway->documents = [];

        // One operation: the tag is renamed and the article gains another tag.
        $php->label = 'php 9';
        $article->tags->add($es);
        $this->em->flush();

        self::assertSame(
            ['old' => ['php 9'], 'new' => ['php 9', 'elasticsearch']],
            $this->lastDocument()['changes']['tags'],
            'the old side is the same objects, described as they are now',
        );
    }

    public function testDatesEqualToTheSecondAreNotAChange(): void
    {
        $article = new Article('Hello');
        $article->publishedAt = new \DateTimeImmutable('2026-08-26 10:00:00');
        $this->persisted($article);
        $this->gateway->documents = [];

        // A new object with the same instant: Doctrine sees a change, the audit must not.
        $article->publishedAt = new \DateTimeImmutable('2026-08-26 10:00:00');
        $this->em->flush();

        self::assertSame([], $this->documents());

        $article->publishedAt = new \DateTimeImmutable('2026-08-27 10:00:00');
        $this->em->flush();

        self::assertSame(['old' => '2026-08-26 10:00:00', 'new' => '2026-08-27 10:00:00'], $this->lastDocument()['changes']['publishedAt']);
    }

    public function testRemoveIsRecordedWithTheIdentifierTheEntityHad(): void
    {
        $article = $this->persisted(new Article('Hello'));
        $id = $article->id;
        $this->gateway->documents = [];

        $this->em->remove($article);
        $this->em->flush();

        $document = $this->lastDocument();

        self::assertSame('remove', $document['event']);
        self::assertSame($id, $document['objectId']);
        self::assertSame([], $document['changes']);
    }

    public function testAttributeDeclaredEntitiesWorkTheSameWay(): void
    {
        $comment = new Comment('c0ffee', 'First!');
        $comment->author = $author = new Author('alice');
        $this->em->persist($author);
        $this->persisted($comment);

        $created = $this->lastDocument();
        self::assertSame('comment', $created['objectType']);
        self::assertSame('c0ffee', $created['objectId']);
        self::assertSame(['old' => null, 'new' => 'alice'], $created['changes']['author']);

        $this->gateway->documents = [];
        $comment->body = 'First! (edited)';
        $comment->likes = 3;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];
        self::assertSame(['old' => 'First!', 'new' => 'First! (edited)'], $changes['body']);
        self::assertSame(['old' => false, 'new' => false], $changes['approved']);
        self::assertArrayNotHasKey('likes', $changes);
    }

    public function testEntitiesWithoutAnAuditDeclarationAreIgnored(): void
    {
        $this->persisted(new Author('nobody'));

        self::assertSame([], $this->documents());
    }

    public function testAFailingTransportDoesNotAbortTheFlush(): void
    {
        $this->gateway->failWith = new \RuntimeException('cluster down');

        $article = $this->persisted(new Article('Hello'));

        self::assertNotNull($article->id, 'the insert went through');
        self::assertSame([], $this->documents());
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function persisted(object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}
