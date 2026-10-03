<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Beacon;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\ORM\Events;

/**
 * How the listener hands a flush's records to the writer: as it makes them, a run of one moment
 * at a time, in batches of the writer's own size outside a frame -- a flush of twenty thousand
 * held its drafts and its records whole at once. Inside a frame a moment goes in one call: under
 * on_overflow: throw the frame that overflows refuses the rest of that call, and a moment in
 * parts would have its later parts completed -- enriched, announced -- for an operation already
 * refused.
 */
final class HowAPublicationGoesOutTest extends DoctrineTestCase
{
    public function testAMomentLongerThanABatchGoesOutWholeInItsOrder(): void
    {
        $this->listenWith($this->writerOf(batchSize: 2));

        for ($i = 0; $i < 5; ++$i) {
            $beacon = new Beacon();
            $beacon->label = 'b'.$i;
            $this->em->persist($beacon);
        }

        $this->em->flush();

        $documents = array_values($this->gateway->documents['audit_log'] ?? []);
        self::assertSame(['b0', 'b1', 'b2', 'b3', 'b4'], array_map(static fn (array $d): mixed => $d['changes']['label']['new'] ?? null, $documents), 'every record, in the order its statement ran');
        self::assertCount(3, $this->gateway->bulks, 'in batches of two');

        $ids = array_map('strval', $this->gateway->ids['audit_log'] ?? []);
        $sorted = $ids;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $ids, 'and the ids one moment builds keep that order across the batches');
        self::assertCount(5, array_unique($ids));
    }

    /** @return iterable<string, array{int}> */
    public static function batchSizes(): iterable
    {
        yield 'a batch of one' => [1];
        yield 'a batch larger than the moment' => [10];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('batchSizes')]
    public function testInAFrameThatRefusesTheOperationNothingPastTheOverflowIsCompleted(int $batchSize): void
    {
        $completed = new \ArrayObject();
        $buffer = new FrameBuffer(maxHeld: 2, throwOnOverflow: true);
        $this->listenWith($this->writerOf(batchSize: $batchSize, buffer: $buffer, completed: $completed));
        $buffer->open();

        for ($i = 0; $i < 5; ++$i) {
            $beacon = new Beacon();
            $beacon->label = 'b'.$i;
            $this->em->persist($beacon);
        }

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // What the refusal raises is the frame's business, and its tests'.
        }

        $buffer->closeAll();

        self::assertSame([], array_values($this->gateway->documents['audit_log'] ?? []), 'the premise: the operation was refused whole');
        self::assertSame(['b0', 'b1', 'b2'], $completed->getArrayCopy(), 'completed up to the record that overflowed, and no further: the rest of the moment was never the writer\'s');
    }

    private function writerOf(int $batchSize, ?FrameBuffer $buffer = null, ?\ArrayObject $completed = null): AuditWriter
    {
        $completed ??= new \ArrayObject();
        $counting = new class($completed) implements AuditEnricherInterface {
            public function __construct(private readonly \ArrayObject $completed)
            {
            }

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                $this->completed[] = $record->changes['label']->new ?? null;

                return $record;
            }

            public function mapping(): array
            {
                return [];
            }
        };
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), [$counting], FailurePolicy::Log, $this->logger(), null, $buffer, batchSize: $batchSize);
    }

    private function listenWith(AuditWriter $writer): void
    {
        foreach (array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $listener) => $listener instanceof AuditSubscriber,
        ) as $previous) {
            $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, $previous);
        }

        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory(), $this->statements, logger: $this->logger()));
    }
}
