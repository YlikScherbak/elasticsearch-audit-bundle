<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Catalogue;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Doctrine\ORM\Events;

/**
 * What a link's target is shown as, for a class whose rows the history reads: the row as it
 * stood at the link -- the old list before its first statement, the new one once its last ran --
 * and not the row as it stands by the time the record is built, nor the object the application
 * holds then.
 *
 * Built after the flush by a postFlush listener ahead of this one, which moves the targets on:
 * an UPDATE of the row that the flush claims, and the object changed in memory, or the object
 * alone. A catalogue's items are crate lines, whose rows the history reads.
 */
final class WhatALinksTargetIsShownAsTest extends DoctrineTestCase
{
    /** @return iterable<string, array{string, bool, list<array{old: list<string>, new: list<string>}>}> */
    public static function shapes(): iterable
    {
        yield 'added, its row and object moved after' => ['added', true, [['old' => [], 'new' => ['X@C-1']]]];
        yield 'renamed in the flush, then added' => ['renamed', true, [['old' => [], 'new' => ['X2@C-1']]]];
        // Held already and renamed by the flush before the link: before the link's statement
        // its row is renamed.
        yield 'held and renamed, another added' => ['held', true, [['old' => ['Y2@C-1'], 'new' => ['X@C-1', 'Y2@C-1']]]];
        yield 'taken out, its row and object moved after' => ['removed', true, [['old' => ['Y@C-1'], 'new' => []]]];
        yield 'added, its object alone moved after' => ['added', false, [['old' => [], 'new' => ['X@C-1']]]];
        yield 'taken out, its object alone moved after' => ['removed', false, [['old' => ['Y@C-1'], 'new' => []]]];
    }

    /**
     * @param list<array{old: list<string>, new: list<string>}> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('shapes')]
    public function testTheRowAsItStoodAtTheLink(string $shape, bool $rowMovesToo, array $expected): void
    {
        $this->unownedStatementsAreExpected = true;
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($x = new CrateItem('X'));
        $crate->add($y = new CrateItem('Y'));
        $this->em->persist($catalogue = new Catalogue('K-1'));

        if ($shape === 'held' || $shape === 'removed') {
            $catalogue->items->add($y);
        }

        $this->em->flush();
        $this->gateway->documents = [];

        match ($shape) {
            'added' => $catalogue->items->add($x),
            'renamed' => [$x->sku = 'X2', $catalogue->items->add($x)],
            'held' => [$y->sku = 'Y2', $catalogue->items->add($x)],
            'removed' => $catalogue->items->removeElement($y),
        };

        $after = new class($this->em->getConnection(), [$x, $y], $rowMovesToo) {
            /** @param list<CrateItem> $items */
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly array $items, private readonly bool $rowMovesToo)
            {
            }

            public function postFlush(): void
            {
                foreach ($this->items as $item) {
                    if ($this->rowMovesToo) {
                        $this->connection->update('CrateItem', ['sku' => $item->sku.'-sql'], ['id' => $item->id]);
                    }

                    $item->sku .= '-memory';
                }
            }
        };
        $manager = $this->em->getEventManager();
        $ours = $manager->getListeners(Events::postFlush);

        foreach ($ours as $listener) {
            $manager->removeEventListener([Events::postFlush], $listener);
        }

        $manager->addEventListener([Events::postFlush], $after);

        foreach ($ours as $listener) {
            $manager->addEventListener([Events::postFlush], $listener);
        }

        $this->em->flush();
        $this->logs = [];

        $catalogues = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'catalogue'));

        self::assertSame($expected, array_map(static fn (array $d): mixed => $d['changes']['items'] ?? null, $catalogues));
    }
}
