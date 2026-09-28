<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\WatchedRows;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CornerShelf;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MisdeclaredTracking;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shelf;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Stop;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\TrackedGroupMember;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\UntrackedInverseManyToMany;

/**
 * Which join rows are history (5.3a): an audited owner's owning ManyToMany among its audited
 * fields -- and, for a row going, which of those it is the target of.
 */
final class WhichLinksAreWatchedTest extends DoctrineTestCase
{
    public function testAnAuditedOwningManyToManyIsWatchedAndNothingElseIs(): void
    {
        $watched = new WatchedRows();
        $of = fn (string $class): \Doctrine\ORM\Mapping\ClassMetadata => $this->em->getClassMetadata($class);

        self::assertTrue($watched->areLinksWatched($of(Article::class), 'tags'));
        self::assertTrue($watched->areLinksWatched($of(Route::class), 'stops'));
        self::assertFalse($watched->areLinksWatched($of(Route::class), 'detours'), 'mapped and not audited');
        self::assertFalse($watched->areLinksWatched($of(Article::class), 'author'), 'one target, no join table');
        self::assertFalse($watched->areLinksWatched($of(Article::class), 'title'), 'no association at all');
        self::assertFalse($watched->areLinksWatched($of(UntrackedInverseManyToMany::class), 'members'), 'the inverse side: its rows are the other side\'s');
        // Watched as its rows are: whether the declaration is refused is the listener's to say,
        // through its policy, when it writes the record.
        self::assertTrue($watched->areLinksWatched($of(MisdeclaredTracking::class), 'tags'));
        self::assertFalse($watched->areLinksWatched($of(TrackedGroupMember::class), 'groups'), 'an owning ManyToMany of an owner not audited');
        self::assertTrue($watched->areLinksWatched($of(CornerShelf::class), 'labels'), 'inherited, and the same join rows');
    }

    public function testATargetIsTakenOutOfEveryWatchedCollectionOfItsClassAndOnlyThose(): void
    {
        $watched = new WatchedRows();

        // A shelf's labels once, under the class that declares them, and not again under the
        // subclass that inherits them: one join table is one collection's rows.
        $of = $watched->linksTo($this->em, $this->em->getClassMetadata(Tag::class));
        sort($of);
        self::assertSame([[Article::class, 'tags'], [MisdeclaredTracking::class, 'tags'], [Shelf::class, 'labels']], $of);
        self::assertSame([[Route::class, 'stops']], $watched->linksTo($this->em, $this->em->getClassMetadata(Stop::class)), 'not the detours, which are not audited');
        self::assertSame([], $watched->linksTo($this->em, $this->em->getClassMetadata(Author::class)));
    }
}
