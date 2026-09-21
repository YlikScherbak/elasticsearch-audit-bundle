<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Test;

use PHPUnit\Framework\Assert;

/**
 * Assertions over what {@see AuditCollector} caught.
 *
 * They exist for their failure messages. `assertCount(1, $collector->writtenFor(...))`
 * says "expected 1, got 0" and leaves a person guessing between "nothing was recorded",
 * "it was recorded about something else", "a listener vetoed it" and "the frame is still
 * open" — which are four different bugs, three of which are not in the code under test.
 * Every assertion here fails with {@see AuditCollector::explain()} behind it, which says
 * which of the four it is.
 *
 *     final class PayingAnOrderTest extends KernelTestCase
 *     {
 *         use AuditAssertions;
 *
 *         protected function auditCollector(): AuditCollector
 *         {
 *             return static::getContainer()->get(AuditCollector::class);
 *         }
 *
 *         public function testPayingIsRecorded(): void
 *         {
 *             $this->orders->pay($order);
 *
 *             $record = $this->assertAudited('order', $order->getId(), 'update');
 *
 *             self::assertSame('paid', $record->entry()->changes['status']['new']);
 *         }
 *     }
 *
 * Needs phpunit/phpunit, which a test suite has. The collector itself does not, so an
 * application on another framework uses its query methods directly.
 */
trait AuditAssertions
{
    /**
     * The collector to assert on. Under `transport: collector` it is a public service:
     * `static::getContainer()->get(AuditCollector::class)`.
     */
    abstract protected function auditCollector(): AuditCollector;

    /**
     * Exactly one record about this object, returned so the test can go on to its
     * changes and attributes.
     *
     * Exactly one rather than at least one, deliberately: a duplicated audit record is
     * a defect this bundle spends whole subsystems preventing, and an assertion that
     * passes on two of them is one that would not notice. Use assertAuditedTimes() when
     * more than one is what you mean.
     */
    protected function assertAudited(string $objectType, int|string|null $objectId = null, ?string $event = null): CollectedRecord
    {
        $collector = $this->auditCollector();
        $found = $collector->writtenFor($objectType, $objectId, $event);

        Assert::assertCount(1, $found, sprintf('Expected exactly one audit record %s. %s', self::wanted($objectType, $objectId, $event), $collector->explain()));

        return $found[0];
    }

    /**
     * @return list<CollectedRecord>
     */
    protected function assertAuditedTimes(int $times, string $objectType, int|string|null $objectId = null, ?string $event = null): array
    {
        $collector = $this->auditCollector();
        $found = $collector->writtenFor($objectType, $objectId, $event);

        Assert::assertCount($times, $found, sprintf('Expected %d audit record(s) %s. %s', $times, self::wanted($objectType, $objectId, $event), $collector->explain()));

        return $found;
    }

    protected function assertNotAudited(string $objectType, int|string|null $objectId = null, ?string $event = null): void
    {
        $collector = $this->auditCollector();

        Assert::assertSame([], $collector->writtenFor($objectType, $objectId, $event), sprintf('Expected no audit record %s. %s', self::wanted($objectType, $objectId, $event), $collector->explain()));
    }

    /**
     * A record a listener stopped on purpose. It reached no transport and nothing was
     * logged, so this is the only place it can be asserted on.
     */
    protected function assertAuditVetoed(string $objectType, int|string|null $objectId = null, ?string $event = null): CollectedRecord
    {
        $collector = $this->auditCollector();
        $found = array_values(array_filter($collector->vetoed(), static fn (CollectedRecord $record): bool => $record->isAbout($objectType, $objectId, $event)));

        Assert::assertCount(1, $found, sprintf('Expected exactly one vetoed audit record %s. %s', self::wanted($objectType, $objectId, $event), $collector->explain()));

        return $found[0];
    }

    /**
     * Nothing was written at all — for the operation that must leave no history, and for
     * the first line of a test that is about to make some.
     */
    protected function assertNothingAudited(): void
    {
        $collector = $this->auditCollector();

        Assert::assertSame([], $collector->written(), sprintf('Expected no audit records at all. %s', $collector->explain()));
    }

    /**
     * Which record was wanted, in the words the caller used.
     */
    private static function wanted(string $objectType, int|string|null $objectId, ?string $event): string
    {
        return sprintf(
            'for %s%s%s',
            $objectType,
            $objectId === null ? '' : '#'.$objectId,
            $event === null ? '' : sprintf(' (%s)', $event),
        );
    }
}
