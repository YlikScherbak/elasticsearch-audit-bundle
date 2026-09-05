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
