<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MergedRecordEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditOrigin;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;

/**
 * Two facts an enricher could not get at before: which part of the application produced
 * the record, and what the record says after a frame merged the steps that made it.
 */
final class OriginAndMergedEnrichersTest extends TestCase
{
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testAnEnricherSeesWhereTheRecordCameFrom(): void
    {
        $seen = [];
        $writer = $this->writer([$this->stamps($seen)]);

        $writer->record('order', 1, 'update', ['status' => new Change('a', 'b')]);
        $writer->write(new AuditRecord('order', 2, 'update', origin: AuditOrigin::Doctrine));

        self::assertSame([AuditOrigin::Manual, AuditOrigin::Doctrine], $seen);
    }

    public function testAMergedRecordDoesNotClaimAnOriginItDoesNotHave(): void
    {
        $writer = null;
        // Stamped by a merged enricher on purpose: an ordinary one runs per step, and
        // the merge keeps the attributes of the last step — which would say "manual"
        // about a record that is half the listener's work.
        $frame = $this->frame($writer, [new class implements MergedRecordEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes(['origin' => $record->origin->value]);
            }

            public function mapping(): array
            {
                return ['origin' => ['type' => 'keyword']];
            }
        }]);

        $frame->begin();
        $writer->write(new AuditRecord('order', 1, 'update', changes: ['status' => new Change('a', 'b')], origin: AuditOrigin::Doctrine));
        $writer->record('order', 1, 'update', ['note' => new Change(null, 'called')]);
        $frame->end();

        self::assertSame('mixed', $this->document()['origin']);
    }

    public function testAMergedEnricherRunsOnWhatTheFrameProduced(): void
    {
        $writer = null;
        $frame = $this->frame($writer, [new class implements MergedRecordEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return $record->objectType === 'stock';
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes(['quantityChanged' => \array_key_exists('quantity', $record->changes)]);
            }

            public function mapping(): array
            {
                return ['quantityChanged' => ['type' => 'boolean']];
            }
        }]);

        // 1000 → 1040 → 1000: the last step says the quantity changed, the outcome does
        // not. Something else did move, or the frame would write nothing at all.
        $frame->begin();
        $writer->record('stock', 7, 'update', ['quantity' => new Change(1000, 1040), 'note' => new Change(null, 'recount')]);
        $writer->record('stock', 7, 'update', ['quantity' => new Change(1040, 1000)]);
        $frame->end();

        $document = $this->document();

        self::assertArrayNotHasKey('quantity', $document['changes'], 'it ended where it started');
        self::assertFalse($document['quantityChanged'], 'and the attribute says so, because it was computed on the merged record');
    }

    public function testAMergedEnricherAlsoRunsWhenNoFrameWasOpen(): void
    {
        // Otherwise what an attribute means would depend on whether the caller happened
        // to open a frame, which is not a difference an enricher should have to know.
        $writer = $this->writer([new class implements MergedRecordEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes(['seen' => true]);
            }

            public function mapping(): array
            {
                return [];
            }
        }]);

        $writer->record('order', 1, 'update', ['status' => new Change('a', 'b')]);

        self::assertTrue($this->document()['seen']);
    }

    /**
     * @param list<string> $seen
     */
    private function stamps(array &$seen): AuditEnricherInterface
    {
        return new class($seen) implements AuditEnricherInterface {
            /** @param list<mixed> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                $this->seen[] = $record->origin;

                return $record->withAttributes(['origin' => $record->origin->value]);
            }

            public function mapping(): array
            {
                return ['origin' => ['type' => 'keyword']];
            }
        };
    }

    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    private function writer(iterable $enrichers = [], ?FrameBuffer $buffer = null): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), $enrichers, FailurePolicy::Throw, null, null, $buffer);
    }

    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    private function frame(?AuditWriter &$writer, iterable $enrichers = []): AuditFrame
    {
        $buffer = new FrameBuffer(new ValueComparator([]));
        $writer = $this->writer($enrichers, $buffer);

        return new AuditFrame($buffer, $writer);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $documents = $this->gateway->documents['audit_log'] ?? [];

        self::assertNotEmpty($documents);

        return $documents[array_key_last($documents)];
    }
}
