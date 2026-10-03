<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Baton;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\RelayTeam;

/**
 * An owner going whose join columns do not cascade: Doctrine deletes its join rows itself, in
 * a statement of the join table before the owner's DELETE. What they held is read before they
 * go, so that statement is the owner's going and nothing the history cannot follow.
 */
final class ALinkThatDoesNotCascadeTest extends DoctrineTestCase
{
    public function testTheJoinRowsAreDoctrinesOwnStatementAndTheOwnerOnlySaysItWent(): void
    {
        $team = new RelayTeam();
        $team->batons->add($red = new Baton('red'));
        $team->batons->add(new Baton('blue'));
        $this->em->persist($red);
        $this->em->persist($team->batons[1] ?? throw new \LogicException());
        $this->em->persist($team);
        $this->em->flush();
        $id = $team->id;
        $this->gateway->documents = [];
        $this->queries = [];

        $this->em->remove($team);
        $this->em->flush();

        self::assertContains('DELETE FROM relay_team_baton WHERE team_id = ?', $this->queries, 'the premise: Doctrine deletes the join rows itself');
        self::assertSame(
            [['relay_team', $id, 'remove']],
            array_map(static fn (array $d): array => [$d['objectType'], $d['objectId'], $d['event']], $this->documents()),
        );
        self::assertSame([], $this->logs, 'and nothing it could not follow');
    }

    public function testALinkTakenOffEarlierIsNotTakenAgainWhenTheOwnerGoes(): void
    {
        $team = new RelayTeam();
        $team->batons->add($red = new Baton('red'));
        $this->em->persist($red);
        $this->em->persist($team);
        $this->em->flush();
        $this->gateway->documents = [];

        $team->batons->removeElement($red);
        $team->name = 'renamed';
        $this->em->flush();
        $this->em->remove($team);
        $this->em->flush();

        self::assertSame(
            [['update', ['name', 'batons']], ['remove', []]],
            array_map(static fn (array $d): array => [$d['event'], array_keys($d['changes'] ?? [])], $this->documents()),
        );
        self::assertSame([], $this->logs);
    }

    public function testAnOwnerThatGoesInTheFlushThatChangedItsLinksOnlySaysItWent(): void
    {
        $team = new RelayTeam();
        $team->batons->add($red = new Baton('red'));
        $this->em->persist($red);
        $this->em->persist($team);
        $this->em->flush();
        $this->gateway->documents = [];

        $team->batons->removeElement($red);
        $team->batons->add($blue = new Baton('blue'));
        $this->em->persist($blue);
        $this->em->remove($team);
        $this->em->flush();

        self::assertSame([['remove', []]], array_map(static fn (array $d): array => [$d['event'], array_keys($d['changes'] ?? [])], $this->documents()));
        self::assertSame([], $this->logs);
    }
}
