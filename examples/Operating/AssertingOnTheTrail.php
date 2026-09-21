<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Operating;

use Borsche\ElasticsearchAuditBundle\Test\AuditAssertions;
use Borsche\ElasticsearchAuditBundle\Test\AuditCollector;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Testing what your application records.
 *
 * Turn the collector on in the test environment and nothing else changes:
 *
 *     # config/packages/test/borsche_elasticsearch_audit.yaml
 *     borsche_elasticsearch_audit:
 *         transport: collector
 *
 * The records are still completed, enriched, coalesced, redacted and routed — only
 * the last step keeps them instead of sending them. So what is asserted on here is
 * the document that would have been stored, which is the thing a hand-written fake
 * transport usually stops short of: it sits a layer higher, and the layers it
 * replaces are where the mistakes are.
 *
 * `audit:check` refuses to call an installation healthy under this transport, so a
 * copy of the setting that escapes into another environment is loud rather than a
 * history that quietly does not exist.
 */
final class AssertingOnTheTrail extends KernelTestCase
{
    use AuditAssertions;

    /**
     * Where the assertions get what they read. Public under `transport: collector`,
     * precisely so a test can ask for it.
     */
    protected function auditCollector(): AuditCollector
    {
        /** @var AuditCollector $collector */
        $collector = static::getContainer()->get(AuditCollector::class);

        return $collector;
    }

    public function testPayingAnOrderIsRecordedOnce(): void
    {
        $orders = $this->orders();

        $orders->pay(42);

        // Exactly one, which is the assertion and not an accident of wording: a
        // duplicated record is indistinguishable from history, and "at least one"
        // would pass on two.
        $record = $this->assertAudited('order', 42, 'update');

        self::assertSame('paid', $record->entry()->changes['status']['new']);

        // Attributes are top-level fields of the document, so they read back the same
        // way here as they do from the reader in production.
        self::assertSame('/checkout', $record->entry()->attribute('route'));
    }

    public function testCancellingAnOrderLeavesNoHistoryOfTheReminder(): void
    {
        // A listener that vetoes the bookkeeping record. The record is built and then
        // stopped, so it reaches no transport and nothing is logged — this is the only
        // place it can be asserted on at all.
        $this->orders()->cancel(42);

        $this->assertAuditVetoed('reminder', 42);
        $this->assertNotAudited('reminder', 42);
    }

    public function testAFailedPaymentChangesNothingAndRecordsNothing(): void
    {
        $this->orders()->pay(99);

        // If this fails, the message says whether nothing was recorded, something else
        // was, a listener vetoed it, or a frame is still open — which are four
        // different bugs and only one of them is in the code under test.
        $this->assertNothingAudited();
    }

    /**
     * The application service under test. Whatever yours is called.
     */
    private function orders(): OrderService
    {
        /** @var OrderService $orders */
        $orders = static::getContainer()->get(OrderService::class);

        return $orders;
    }
}

/**
 * Stands in for the application's own service, so the example reads as a test of
 * something rather than of the bundle.
 */
interface OrderService
{
    public function pay(int $id): void;

    public function cancel(int $id): void;
}
