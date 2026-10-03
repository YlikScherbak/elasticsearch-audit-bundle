<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Doctrine\Common\Collections\ArrayCollection;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\BayNumber;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Berth;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Comment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Pouch;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Sku;
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

    public function testAListenerBuiltWithItsDefaultsSkipsAnUpdateOfNothingAudited(): void
    {
        // skip_empty_updates is on unless somebody turns it off -- for the bundle's own
        // configuration and for a listener built by hand alike.
        $manager = $this->em->getEventManager();

        foreach ($manager->getListeners(\Doctrine\ORM\Events::postFlush) as $listener) {
            if ($listener instanceof \Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber) {
                $manager->removeEventListener(\Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber::EVENTS, $listener);
            }
        }

        $manager->addEventListener(\Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber::EVENTS, new \Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber($this->writer(\Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy::Throw), new \Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory(), $this->statements));

        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $article->views = 99;
        $this->em->flush();

        self::assertSame([], $this->documents());
    }

    public function testASkippedUpdateDoesNotTakeTheRecordsAfterItWithIt(): void
    {
        // Skipped is this execution's: the flush's other rows, run after it, are recorded.
        $quiet = $this->persisted(new Article('Quiet'));
        $loud = $this->persisted(new Article('Loud'));
        $this->gateway->documents = [];

        $quiet->views = 99;
        $loud->title = 'Louder';
        $this->em->flush();

        self::assertSame([['Loud', 'Louder']], array_map(static fn (array $d): array => [$d['changes']['title']['old'] ?? null, $d['changes']['title']['new'] ?? null], $this->documents()));
    }

    /** @return iterable<string, array{int}> */
    public static function rings(): iterable
    {
        yield 'two relays' => [2];
        yield 'three relays' => [3];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rings')]
    public function testACreationDoctrineCompletesLaterStandsWhereItsInsertRan(int $size): void
    {
        // New relays pointing at each other round a ring: Doctrine inserts one with its
        // reference empty, then the others, then completes the first with an UPDATE. Its record
        // is its INSERT and that UPDATE, and it is placed by where it began -- before the
        // others, whose INSERTs ran in between -- not by where it ended. Three, so that the
        // record completed late is on both sides of the comparison that orders them.
        $ring = [];

        for ($i = 0; $i < $size; ++$i) {
            $ring[] = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Relay('relay '.$i);
        }

        foreach ($ring as $i => $relay) {
            $relay->next = $ring[($i + 1) % $size];
            $this->em->persist($relay);
        }

        $this->gateway->documents = [];
        $this->queries = [];
        $from = $this->statements->position();
        $this->em->flush();

        $writes = array_values(array_filter($this->queries, static fn (string $sql): bool => (bool) preg_match('/^(INSERT|UPDATE)\b/', $sql)));
        self::assertSame([...array_fill(0, $size, 'INSERT'), 'UPDATE'], array_map(static fn (string $sql): string => (string) strtok($sql, ' '), $writes), 'the premise: one creation completed after the others\' INSERTs');

        // Which one Doctrine inserts first is its own choice, and the order of the keys says
        // nothing of it where a sequence hands them out before the INSERT: the INSERTs' own
        // parameters say it.
        $inserted = [];

        for ($at = $from + 1; $at <= $this->statements->position(); ++$at) {
            $statement = $this->statements->statement($at);

            if ($statement !== null && str_starts_with($statement['sql'], 'INSERT INTO Relay')) {
                $inserted[] = current(array_filter($statement['params'], static fn (mixed $value): bool => \is_string($value) && str_starts_with($value, 'relay ')));
            }
        }

        $documents = $this->documents();

        self::assertCount($size, $inserted, 'the premise: every INSERT read');
        self::assertSame(array_fill(0, $size, 'create'), array_column($documents, 'event'));
        self::assertSame($inserted, array_map(static fn (array $d): mixed => $d['changes']['name']['new'] ?? null, $documents));
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

    public function testClearingAnOwningCollectionIsRecordedRatherThanPassedOver(): void
    {
        // The join rows go; the history said nothing. PersistentCollection::clear()
        // schedules the collection for deletion and then takes a fresh snapshot of the
        // now-empty collection, which also resets isDirty() — so everything the record
        // was built from says nothing happened, while Doctrine deletes every row.
        $php = new Tag('php');
        $es = new Tag('elasticsearch');
        $this->em->persist($php);
        $this->em->persist($es);

        $article = new Article('Hello');
        $article->tags->add($php);
        $article->tags->add($es);
        $this->persisted($article);
        $this->gateway->documents = [];

        $article->tags->clear();
        $article->title = 'Hello again';
        $this->em->flush();

        self::assertSame(['old' => ['php', 'elasticsearch'], 'new' => []], $this->lastDocument()['changes']['tags']);
        self::assertSame([], $this->em->getConnection()->fetchAllAssociative('SELECT * FROM article_tag'), 'and the rows really are gone');
    }

    public function testReplacingAnOwningCollectionKeepsTheSideItReplaced(): void
    {
        // The other way to take a collection away. Doctrine schedules the old collection
        // for deletion and puts a fresh one in the property, whose snapshot never held
        // the old members — so the old side came out empty, or the whole change went
        // missing when the replacement was empty too.
        $php = new Tag('php');
        $es = new Tag('elasticsearch');
        $this->em->persist($php);
        $this->em->persist($es);

        $article = new Article('Hello');
        $article->tags->add($php);
        $this->persisted($article);
        $this->gateway->documents = [];

        $article->tags = new ArrayCollection([$es]);
        $article->title = 'Hello again';
        $this->em->flush();

        self::assertSame(['old' => ['php'], 'new' => ['elasticsearch']], $this->lastDocument()['changes']['tags']);
    }

    public function testReplacingAnOwningCollectionWithAnEmptyOneIsRecordedToo(): void
    {
        $php = new Tag('php');
        $this->em->persist($php);

        $article = new Article('Hello');
        $article->tags->add($php);
        $this->persisted($article);
        $this->gateway->documents = [];

        $article->tags = new ArrayCollection();
        $article->title = 'Hello again';
        $this->em->flush();

        self::assertSame(['old' => ['php'], 'new' => []], $this->lastDocument()['changes']['tags']);
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

    public function testAnIdentifierThatIsAnObjectIsRecordedAsItsStringForm(): void
    {
        // Uuid, Ulid, or an identifier of an application's own: the object goes into the
        // record as the string it prints as, because that is what a document id is and
        // what every query against the history will be written with. Handed on as the
        // object it would reach the transport as one, and what a serializer makes of it
        // is not something an audit trail should be finding out.
        $pouch = new Pouch(new Sku('SKU-9000'), 'tea');

        $this->em->persist($pouch);
        $this->em->flush();

        $document = $this->lastDocument();

        self::assertSame('pouch', $document['objectType']);
        self::assertSame('SKU-9000', $document['objectId']);
    }

    public function testAnIdentifierThatIsABackedEnumIsRecordedAsItsValue(): void
    {
        // An enum identifier is what a small fixed set of rows gets — bays, statuses,
        // regions — and the history carries the value behind it rather than the case
        // object, so a query written against the audit log reads the same as one written
        // against the table.
        //
        // It arrives that way rather than being converted: Doctrine hands an identifier
        // back as the backed value, which is why this reads 2 and not "2" and why the
        // listener's own arm for a BackedEnum is never the one that answers. Measured
        // here — the assertion was written the other way round first.
        $berth = new Berth(BayNumber::Two, 'the night boat');

        $this->em->persist($berth);
        $this->em->flush();

        $document = $this->lastDocument();

        self::assertSame('berth', $document['objectType']);
        self::assertSame(2, $document['objectId']);
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
