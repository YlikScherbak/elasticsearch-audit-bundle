<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Locker;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Member;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MemberCard;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Pouch;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Satchel;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Sku;

/**
 * A row the listener did not see written, remembered at preFlush from what Doctrine holds of it
 * (RowMemory::remember()): its columns as the database has them, and nothing remembered where
 * Doctrine holds nothing yet.
 */
final class WhatARowRememberedFromDoctrineHoldsTest extends DoctrineTestCase
{
    public function testAForeignKeyToARowKeyedByAnObjectIsRememberedAsTheDatabaseHasIt(): void
    {
        $this->em->persist($tea = new Pouch(new Sku('P-TEA'), 'tea'));
        $this->em->persist($coins = new Pouch(new Sku('P-COINS'), 'coins'));
        $this->em->persist($satchel = new Satchel());
        $satchel->pouch = $tea;
        $this->em->flush();
        $id = $satchel->id;
        unset($tea, $coins, $satchel);
        $this->forgetEverything();

        $satchel = $this->em->find(Satchel::class, $id);
        self::assertInstanceOf(Satchel::class, $satchel);
        $satchel->pouch = $this->em->find(Pouch::class, new Sku('P-COINS'));
        $this->em->flush();

        self::assertSame([['satchel', 'update', ['pouch' => ['old' => 'tea', 'new' => 'coins']]]], $this->said());
    }

    public function testAForeignKeyToARowKeyedByAnAssociationIsRememberedAsTheKeyItNames(): void
    {
        $this->em->persist($ann = new Member('Ann'));
        $this->em->persist($bob = new Member('Bob'));
        $this->em->persist($first = new MemberCard($ann, 'first'));
        $this->em->persist($second = new MemberCard($bob, 'second'));
        $this->em->persist($locker = new Locker());
        $locker->card = $first;
        $this->em->flush();
        [$id, $bobId] = [$locker->id, $bob->id];
        unset($ann, $bob, $first, $second, $locker);
        $this->forgetEverything();

        $locker = $this->em->find(Locker::class, $id);
        self::assertInstanceOf(Locker::class, $locker);
        $locker->card = $this->em->find(MemberCard::class, $bobId);
        $this->em->flush();

        self::assertSame([['locker', 'update', ['card' => ['old' => 'first', 'new' => 'second']]]], $this->said());
    }

    public function testAReferenceDoctrineHoldsNothingOfIsNotRememberedAsARowOfNothing(): void
    {
        $this->em->persist($article = new Article('One'));
        $this->em->flush();
        $id = $article->id;
        unset($article);
        $this->forgetEverything();

        // At this preFlush the reference is in the identity map with nothing loaded.
        $reference = $this->em->getReference(Article::class, $id);
        self::assertNotNull($reference);
        $this->em->persist(new Article('Other'));
        $this->em->flush();
        $this->gateway->documents = [];

        $reference->title = 'One, again';
        $this->em->flush();

        $said = array_values(array_filter($this->said(), static fn (array $one): bool => $one[1] === 'update'));
        self::assertSame(['old' => 'One', 'new' => 'One, again'], $said[0][2]['title'] ?? null);
    }

    /** Nothing held of the rows: the manager cleared, and a flush settling what that let go. */
    private function forgetEverything(): void
    {
        $this->em->clear();
        gc_collect_cycles();
        $this->em->flush();
        $this->gateway->documents = [];
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private function said(): array
    {
        return array_map(static fn (array $d): array => [$d['objectType'], $d['event'], $d['changes']], $this->documents());
    }
}
