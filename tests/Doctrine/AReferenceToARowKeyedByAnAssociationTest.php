<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\AccountEntry;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Locker;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Member;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MemberAccount;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MemberCard;

/**
 * A locker's card, a row keyed by its member: the foreign key holds the member's id, and what
 * the row memory takes of the locker's row is that id -- not the card, not the member object --
 * read past a collection declared before it.
 */
final class AReferenceToARowKeyedByAnAssociationTest extends DoctrineTestCase
{
    public function testTheOldCardIsTheOneTheRowHeld(): void
    {
        [$locker] = $this->aLockerWithItsCard();

        $locker->card = $this->em->find(MemberCard::class, $this->other->id);
        $locker->name = 'renamed';
        $this->em->flush();

        self::assertSame(
            [['card' => ['old' => 'A', 'new' => 'B'], 'name' => ['old' => 'locker', 'new' => 'renamed']]],
            array_map(static fn (array $d): array => $d['changes'], $this->documents()),
        );
    }

    public function testACardTakenOffSaysWhichItWas(): void
    {
        [$locker] = $this->aLockerWithItsCard();

        $locker->card = null;
        $this->em->flush();

        self::assertSame([['card' => ['old' => 'A', 'new' => null]]], array_map(static fn (array $d): array => $d['changes'], $this->documents()));
    }

    public function testAnEntryBelongsToTheAccountItsRowPointsAt(): void
    {
        [$x, $y, $entry] = $this->twoAccountsAndAnEntry();

        $entry->amount = 7;
        $this->em->flush();

        self::assertSame(
            [['member_account', (string) $x->member->id, ['entries.'.$entry->id.'.amount' => ['old' => 5, 'new' => 7]]]],
            array_map(static fn (array $d): array => [$d['objectType'], $d['objectId'], $d['changes']], $this->documents()),
        );
    }

    public function testAnEntryMovedBetweenTwoAccountsLeavesOneAndJoinsTheOther(): void
    {
        // The two accounts are told apart as objects: compared with ==, field by field, each
        // led through its entries back to an account, and PHP ended the process with a fatal
        // "nesting level too deep".
        [$x, $y, $entry] = $this->twoAccountsAndAnEntry();

        $entry->account = $y;
        $this->em->flush();

        self::assertSame(
            [
                [(string) $x->member->id, ['entries.'.$entry->id => ['old' => 'rent', 'new' => null]]],
                [(string) $y->member->id, ['entries.'.$entry->id => ['old' => null, 'new' => 'rent']]],
            ],
            array_map(static fn (array $d): array => [$d['objectId'], $d['changes']], $this->documents()),
        );
    }

    /** @return array{MemberAccount, MemberAccount, AccountEntry} */
    private function twoAccountsAndAnEntry(): array
    {
        $this->em->persist($a = new Member('a'));
        $this->em->persist($b = new Member('b'));
        $this->em->persist($x = new MemberAccount($a));
        $this->em->persist($y = new MemberAccount($b));
        $this->em->persist($entry = new AccountEntry('rent', 5));
        $entry->account = $x;
        $this->em->flush();
        $this->em->clear();
        $this->gateway->documents = [];

        $x = $this->em->find(MemberAccount::class, $a->id);
        $y = $this->em->find(MemberAccount::class, $b->id);
        $entry = $this->em->find(AccountEntry::class, $entry->id);
        self::assertInstanceOf(MemberAccount::class, $x);
        self::assertInstanceOf(MemberAccount::class, $y);
        self::assertInstanceOf(AccountEntry::class, $entry);

        return [$x, $y, $entry];
    }

    private Member $other;

    /** @return array{Locker} */
    private function aLockerWithItsCard(): array
    {
        $this->em->persist($a = new Member('a'));
        $this->em->persist($b = new Member('b'));
        $this->em->persist(new MemberCard($a, 'A'));
        $this->em->persist(new MemberCard($b, 'B'));
        $this->em->flush();
        $locker = new Locker();
        $locker->card = $this->em->find(MemberCard::class, $a->id);
        $this->em->persist($locker);
        $this->em->flush();
        $this->em->clear();
        $this->other = $b;
        $this->gateway->documents = [];

        $locker = $this->em->find(Locker::class, $locker->id);
        self::assertInstanceOf(Locker::class, $locker);

        return [$locker];
    }
}
