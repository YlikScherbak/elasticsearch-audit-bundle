<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\BadgeBoard;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Poster;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Sticker;

/**
 * An audited link a mapped superclass declares, held by its entity: what moves it, and a target
 * removed from under it, are the entity's history as they would be had it declared the link.
 */
final class ALinkAMappedSuperclassDeclaresTest extends DoctrineTestCase
{
    public function testALinkAddedAndTakenOffIsTheEntitysChange(): void
    {
        $this->em->persist($a = new Sticker('a'));
        $this->em->persist($b = new Sticker('b'));
        $poster = new Poster();
        $poster->stickers->add($a);
        $this->em->persist($poster);
        $this->em->flush();
        $this->gateway->documents = [];

        $poster->stickers->removeElement($a);
        $poster->stickers->add($b);
        $this->em->flush();

        self::assertSame([['update', ['old' => ['a'], 'new' => ['b']]]], array_map(static fn (array $d): array => [$d['event'], $d['changes']['stickers'] ?? null], $this->documents()));
    }

    public function testASuperclassThatDeclaresItselfAuditedIsStillNoOwnerOfRows(): void
    {
        // It can be made, and answers as audited: neither makes it a holder of the join rows,
        // nor a second one of a target removed from under the board.
        $this->em->persist($a = new Sticker('a'));
        $this->em->persist($b = new Sticker('b'));
        $board = new BadgeBoard();
        $board->badges->add($a);
        $board->badges->add($b);
        $this->em->persist($board);
        $this->em->flush();
        $this->gateway->documents = [];

        $board->badges->removeElement($b);
        $this->em->flush();
        $this->em->remove($a);
        $this->em->flush();

        self::assertSame(
            [['badge_board', ['old' => ['a', 'b'], 'new' => ['a']]], ['badge_board', ['old' => ['a'], 'new' => []]]],
            array_map(static fn (array $d): array => [$d['objectType'], $d['changes']['badges'] ?? null], $this->documents()),
        );
        self::assertSame([], $this->logs);
    }

    public function testATargetRemovedFromUnderItIsTheEntitysChange(): void
    {
        $this->em->persist($a = new Sticker('a'));
        $this->em->persist($b = new Sticker('b'));
        $poster = new Poster();
        $poster->stickers->add($a);
        $poster->stickers->add($b);
        $this->em->persist($poster);
        $this->em->flush();
        $this->gateway->documents = [];

        $this->em->remove($a);
        $this->em->flush();

        self::assertSame([['poster', 'update', ['old' => ['a', 'b'], 'new' => ['b']]]], array_map(static fn (array $d): array => [$d['objectType'], $d['event'], $d['changes']['stickers'] ?? null], $this->documents()));
    }
}
