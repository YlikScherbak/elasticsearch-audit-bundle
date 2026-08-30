<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
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
