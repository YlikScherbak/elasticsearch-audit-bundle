<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Folder;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\FolderDocument;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;

/**
 * What "a to-many field records which elements were added and removed" means for the
 * two shapes Doctrine treats completely differently.
 */
final class CollectionMembershipTest extends DoctrineTestCase
{
    public function testAnInverseCollectionRecordsMembershipWithoutElementTracking(): void
    {
        // The common shape: the folder audits its documents, and does not care what
        // changes inside one. Doctrine keeps the membership change on the document's
        // own reference back, so the folder's collection never goes dirty and the
        // folder is never scheduled for an update — and until element tracking was
        // separated from membership, this flush left no trace at all.
        $folder = new Folder('Contracts');
        $folder->add(new FolderDocument('lease.pdf'));
        $this->em->persist($folder);
        $this->em->flush();
        $this->gateway->documents = [];

        $added = new FolderDocument('addendum.pdf');
        $folder->add($added);
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => null, 'new' => 'addendum.pdf'], $changes['documents.'.$added->id]);
    }

    public function testAndRecordsWhatLeavesItToo(): void
    {
        $folder = new Folder('Contracts');
        $folder->add($document = new FolderDocument('lease.pdf'));
        $this->em->persist($folder);
        $this->em->flush();
        $this->gateway->documents = [];

        $id = $document->id;
        $folder->documents->removeElement($document);
        $this->em->remove($document);
        $this->em->flush();

        self::assertSame(['old' => 'lease.pdf', 'new' => null], $this->lastDocument()['changes']['documents.'.$id]);
    }

    public function testAnInverseCollectionChangedWithItsOwnerIsNotRecordedTwice(): void
    {
        // Two paths reach the same business change: the owner's change set sees the
        // collection dirty in memory, and the element's own reference back is what
        // Doctrine actually persists. Recording both put the addition in the record
        // twice, once as the whole contents and once as a membership key.
        $folder = new Folder('Contracts');
        $folder->add(new FolderDocument('lease.pdf'));
        $this->em->persist($folder);
        $this->em->flush();
        $this->gateway->documents = [];

        $folder->name = 'Renamed';
        $folder->add($added = new FolderDocument('contract.pdf'));
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'Contracts', 'new' => 'Renamed'], $changes['name']);
        self::assertSame(['old' => null, 'new' => 'contract.pdf'], $changes['documents.'.$added->id]);
        self::assertArrayNotHasKey('documents', $changes, 'the same change, told once');
    }

    public function testChangingOnlyTheInverseSideInventsNoMembershipChange(): void
    {
        // The collection is dirty in memory and the database will not have the row:
        // an inverse collection is not what Doctrine persists, the back-reference is.
        // A history that shows this addition describes something that never happened.
        $folder = new Folder('Contracts');
        $this->em->persist($folder);
        $this->em->flush();
        $this->gateway->documents = [];

        $folder->name = 'Renamed';
        $folder->documents->add(new FolderDocument('never-linked.pdf')); // no back-reference
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'Contracts', 'new' => 'Renamed'], $changes['name']);
        self::assertSame(['name'], array_keys($changes), 'nothing was written about a relation the database does not have');
    }

    public function testAnOwningCollectionIsRecordedAsItsWholeContents(): void
    {
        // The other shape, and the reason both are needed: an owning ManyToMany makes
        // the owner dirty, so Doctrine schedules it and the collection is compared
        // snapshot against current — one change, not one per element.
        $php = new Tag('php');
        $this->em->persist($php);
        $article = new Article('Hello');
        $article->tags->add($php);
        $this->em->persist($article);
        $this->em->flush();
        $this->gateway->documents = [];

        $es = new Tag('elasticsearch');
        $this->em->persist($es);
        $article->tags->add($es);
        $this->em->flush();

        self::assertSame(['old' => ['php'], 'new' => ['php', 'elasticsearch']], $this->lastDocument()['changes']['tags']);
    }
}
