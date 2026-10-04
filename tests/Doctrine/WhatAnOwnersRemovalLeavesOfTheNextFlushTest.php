<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;

/**
 * A flush that removes an owner with the lines it had, and a line of another owner after them:
 * the removed owner's lines are its removal and nothing more, and the line that went after them
 * is still the other owner's record.
 */
final class WhatAnOwnersRemovalLeavesOfTheNextFlushTest extends DoctrineTestCase
{
    public function testTheLinesOfARemovedOwnerDoNotEndTheReading(): void
    {
        $gone = new Shipment('GONE');
        $gone->add($goneLine = new ShipmentLine('bolt', 1));
        $kept = new Shipment('KEPT');
        $kept->add(new ShipmentLine('nut', 2));
        $kept->add($keptLine = new ShipmentLine('washer', 3));
        $this->em->persist($gone);
        $this->em->persist($kept);
        $this->em->flush();
        $this->gateway->documents = [];
        [$goneId, $lineId] = [$gone->id, $keptLine->id];

        // In this order, so that the DELETE of the other owner's line runs after the removed one's.
        $this->em->remove($goneLine);
        $this->em->remove($gone);
        $kept->lines->removeElement($keptLine);
        $this->em->remove($keptLine);
        $this->em->flush();

        self::assertSame(
            [['remove', $goneId], ['update', $kept->id]],
            array_map(static fn (array $d): array => [$d['event'], $d['objectId']], $this->documents()),
        );
        self::assertSame(['lines.'.$lineId => ['old' => 'washer', 'new' => null]], $this->documents()[1]['changes']);
    }
}
