<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * One UPDATE of a line that moves it to another shipment and changes its quantity, written with
 * the reference first -- a listener's own statement, inside the flush: what follows the
 * reference is still read, and told to the shipment the line arrived at. (Doctrine writes the
 * columns of fields before those of references.)
 */
final class ALineMovedAndChangedByOneUpdateTest extends DoctrineTestCase
{
    /**
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function whatComesFirst(): iterable
    {
        yield 'the reference' => ['UPDATE ShipmentLine SET shipment_id = ?, quantity = ? WHERE id = ?', ['to', 5, 'line']];
        yield 'a column written as it was' => ['UPDATE ShipmentLine SET shipment_id = ?, product = ?, quantity = ? WHERE id = ?', ['to', 'bolt', 5, 'line']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatComesFirst')]
    public function testWhatTheUpdateWritesAfterTheReferenceIsStillRead(string $sql, array $params): void
    {
        $from = new Shipment('FROM');
        $from->add($line = new ShipmentLine('bolt', 2));
        $to = new Shipment('TO');
        $this->em->persist($from);
        $this->em->persist($to);
        $this->em->flush();
        $this->gateway->documents = [];
        [$lineId, $toId] = [$line->id, $to->id];

        $params = array_map(static fn (mixed $p): mixed => match ($p) { 'to' => $toId, 'line' => $lineId, default => $p }, $params);
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($from, $this->em->getConnection(), $sql, $params) {
            private bool $ran = false;

            /** @param list<mixed> $params */
            public function __construct(private readonly Shipment $shipment, private readonly \Doctrine\DBAL\Connection $connection, private readonly string $sql, private readonly array $params)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->shipment && !$this->ran) {
                    $this->ran = true;
                    $this->connection->executeStatement($this->sql, $this->params);
                }
            }
        });
        $from->reference = 'FROM, again';
        $this->em->flush();

        $changes = [];

        foreach ($this->documents() as $document) {
            $changes[$document['objectId']] = $document['changes'];
        }

        self::assertSame(['old' => 2, 'new' => 5], $changes[$toId]['lines.'.$lineId.'.quantity'] ?? null);
    }
}
