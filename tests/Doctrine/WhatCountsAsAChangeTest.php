<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;

/**
 * What counts as a change is a property of the data, not of Doctrine: the same
 * comparators that decide what a frame drops decide what is recorded in the first
 * place.
 */
final class WhatCountsAsAChangeTest extends DoctrineTestCase
{
    public function testWithoutAComparatorTwoZonesOfTheSameWallClockAreAChange(): void
    {
        $article = $this->published('2026-08-30 10:00:00', 'UTC');

        // Same reading on the wall, two hours apart in fact — and the record then shows
        // two timestamps that look identical to whoever reads the history.
        $article->publishedAt = new \DateTimeImmutable('2026-08-30 10:00:00', new \DateTimeZone('+02:00'));
        $this->em->flush();

        self::assertArrayHasKey('publishedAt', $this->lastDocument()['changes']);
    }

    public function testAComparatorSettlesItForTheListenerToo(): void
    {
        $this->useComparator(new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                if ($field !== 'publishedAt') {
                    return null; // no opinion; the default decides
                }

                return $old instanceof \DateTimeInterface
                    && $new instanceof \DateTimeInterface
                    && $old->format('Y-m-d H:i:s') === $new->format('Y-m-d H:i:s');
            }
        });

        $article = $this->published('2026-08-30 10:00:00', 'UTC');

        $article->publishedAt = new \DateTimeImmutable('2026-08-30 10:00:00', new \DateTimeZone('+02:00'));
        $this->em->flush();

        self::assertSame([], $this->documents(), 'by wall clock nothing moved, so there is nothing to record');
    }

    public function testTheComparatorIsNotAskedToDecideEverything(): void
    {
        $this->useComparator(new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                return $field === 'publishedAt' ? true : null;
            }
        });

        $article = $this->published('2026-08-30 10:00:00', 'UTC');

        $article->title = 'Second';
        $article->publishedAt = new \DateTimeImmutable('2027-01-01 00:00:00', new \DateTimeZone('UTC'));
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertArrayHasKey('title', $changes, 'a field it has no opinion about is unaffected');
        self::assertArrayNotHasKey('publishedAt', $changes);
    }

    public function testAnAssociativeArrayWhoseKeysMovedIsNotAChange(): void
    {
        // A json column holding permissions, written back in another order by whatever
        // built it. Nothing moved, and until 0.9.2 a record said otherwise — the builder
        // compared arrays with ===, while the comparators the frame uses go key by key.
        $shipment = $this->shipment(['read' => true, 'write' => false]);

        $shipment->meta = ['write' => false, 'read' => true];
        $this->em->flush();

        self::assertSame([], $this->documents());
    }

    public function testAValueInsideItStillIs(): void
    {
        $shipment = $this->shipment(['read' => true, 'write' => false]);

        $shipment->meta = ['read' => true, 'write' => true];
        $this->em->flush();

        self::assertSame(
            ['old' => ['read' => true, 'write' => false], 'new' => ['read' => true, 'write' => true]],
            $this->lastDocument()['changes']['meta'],
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function shipment(array $meta): Shipment
    {
        $shipment = new Shipment('SH-1');
        $shipment->meta = $meta;

        $this->em->persist($shipment);
        $this->em->flush();
        $this->gateway->documents = [];

        return $shipment;
    }

    public function testAComparatorThatAlwaysDefersStillGetsAnAnswer(): void
    {
        // A bare implementation of the interface, not the chain: null is what the
        // contract calls "no opinion", and the branch that answers it is all that stands
        // between a deferring comparator and a ?bool where a bool is required. Covered
        // here on purpose — whoever calls that branch unreachable has to disprove this
        // test first.
        $this->useComparator(self::alwaysDefers());

        $shipment = $this->shipment(['read' => true]);

        $shipment->reference = 'SH-2';
        $this->em->flush();

        self::assertSame(['old' => 'SH-1', 'new' => 'SH-2'], $this->lastDocument()['changes']['reference']);
    }

    public function testAndTheAnswerItFallsBackOnIsTheChainsOwn(): void
    {
        // Not a strict comparison written beside it: the keys moved and nothing changed,
        // which === would have called a change.
        $this->useComparator(self::alwaysDefers());

        $shipment = $this->shipment(['read' => true, 'write' => false]);

        $shipment->meta = ['write' => false, 'read' => true];
        $this->em->flush();

        self::assertSame([], $this->documents());
    }

    private static function alwaysDefers(): ValueComparatorInterface
    {
        return new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                return null;
            }
        };
    }

    private function published(string $at, string $zone): Article
    {
        $article = new Article('First');
        $article->publishedAt = new \DateTimeImmutable($at, new \DateTimeZone($zone));

        $this->em->persist($article);
        $this->em->flush();
        $this->gateway->documents = [];

        return $article;
    }

    private function useComparator(ValueComparatorInterface $comparator): void
    {
        $attached = array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        ));

        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...$attached);
        $this->attachListener(FailurePolicy::Log, $comparator);
    }
}
