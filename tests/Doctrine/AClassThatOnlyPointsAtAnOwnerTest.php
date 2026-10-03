<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\WatchedRows;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentFee;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;

/**
 * A class whose to-one has the name an audited collection is mapped by, and which that
 * collection does not hold: a fee of a shipment, beside its lines. Its rows are no element of
 * anything, and no history is written or doubted about them.
 */
final class AClassThatOnlyPointsAtAnOwnerTest extends DoctrineTestCase
{
    public function testItsRowsAreNotWatched(): void
    {
        $watched = new WatchedRows();

        self::assertTrue($watched->areWatched($this->em, $this->em->getClassMetadata(ShipmentLine::class)));
        self::assertFalse($watched->areWatched($this->em, $this->em->getClassMetadata(ShipmentFee::class)));
        self::assertFalse($watched->isAHistoryTable($this->em, 'ShipmentFee'));
    }

    public function testWhatTheApplicationWritesOfItIsNoStatementOfAWatchedRow(): void
    {
        $shipment = new Shipment('S-1');
        $shipment->add(new ShipmentLine('bolt', 1));
        $this->em->persist($shipment);
        $fee = new ShipmentFee();
        $fee->shipment = $shipment;
        $this->em->persist($fee);
        $this->em->flush();
        $this->gateway->documents = [];

        $this->em->getConnection()->executeStatement('UPDATE ShipmentFee SET amount = ? WHERE id = ?', [7, $fee->id]);
        $fee->amount = 9;
        $shipment->reference = 'S-2';
        $this->em->flush();

        self::assertSame([['shipment', ['reference']]], array_map(static fn (array $d): array => [$d['objectType'], array_keys($d['changes'])], $this->documents()));
        self::assertSame([], array_values(array_filter($this->logs, static fn (string $line): bool => str_contains($line, ShipmentFee::class))), 'nothing said of a fee');
    }
}
