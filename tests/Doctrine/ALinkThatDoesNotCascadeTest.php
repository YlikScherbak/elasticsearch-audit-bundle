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
    protected function middlewaresOfTheTest(): array
    {
        return $this->name() === 'testAnOwnerWhoseDeleteTheDatabaseRefusesKeepsItsLinksAndItsHistory' ? [new RefusingMiddleware('DELETE FROM RelayTeam ')] : [];
    }

    public function testAnOwnerWhoseDeleteTheDatabaseRefusesKeepsItsLinksAndItsHistory(): void
    {
        // Doctrine deletes the join rows, then the owner's row, in one transaction: the row's
        // refused, the flush is rolled back, and the join rows' DELETE with it -- the two stand
        // or fall together, and nothing in between is the application's.
        $team = new RelayTeam();
        $team->batons->add($red = new Baton('red'));
        $this->em->persist($red);
        $this->em->persist($team);
        $this->em->flush();
        $this->gateway->documents = [];

        $this->em->remove($team);

        try {
            $this->em->flush();
            self::fail('the premise: the database refuses the row\'s DELETE');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Refused by the database.', $e->getMessage());
        }

        $connection = $this->em->getConnection();
        self::assertSame([1, 1], [(int) $connection->fetchOne('SELECT COUNT(*) FROM RelayTeam'), (int) $connection->fetchOne('SELECT COUNT(*) FROM relay_team_baton')], 'the team and its link stand');
        self::assertSame([], $this->documents());
        $this->logs = [];
    }

    public function testAnOwnerGoingAsksNothingOfItsLinks(): void
    {
        // What its join rows held is its removal's, and said by the statements: Doctrine deletes
        // them right before the row, in the same transaction, and the replay takes them as the
        // removal's (5.3c). Nothing is read of them first -- of a team another process wrote, whose
        // batons nothing loaded.
        $this->em->persist($red = new Baton('red'));
        $this->em->flush();
        $connection = $this->em->getConnection();
        $connection->insert('RelayTeam', ['name' => 'written elsewhere']);
        $id = (int) $connection->lastInsertId();
        $connection->insert('relay_team_baton', ['team_id' => $id, 'baton_id' => $red->id]);
        $this->unownedStatementsAreExpected = true;
        $this->em->clear();
        $team = $this->em->find(RelayTeam::class, $id);
        self::assertInstanceOf(RelayTeam::class, $team);
        self::assertInstanceOf(\Doctrine\ORM\PersistentCollection::class, $team->batons);
        self::assertFalse($team->batons->isInitialized(), 'the premise: nothing loaded its batons');
        $this->gateway->documents = [];
        $this->queries = [];

        $this->em->remove($team);
        $this->em->flush();

        self::assertSame([], array_values(array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'relay_team_baton'))));
        self::assertSame([['relay_team', 'remove', []]], array_map(static fn (array $d): array => [$d['objectType'], $d['event'], $d['changes'] ?? []], $this->documents()), 'and it says only that it went');
        self::assertSame([], array_values(array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'may be missing'))), 'nothing it could not follow: the rows the other process wrote are said as such, and that is all');
        $this->logs = [];
    }
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
