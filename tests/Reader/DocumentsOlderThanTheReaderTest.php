<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Documents written by older versions of this bundle, read by today's reader.
 *
 * The 1.x promise is about the API, and this is the other half of it: an index does not
 * upgrade. Whatever is in one was written by whichever version was installed the day it
 * happened, and an audit trail that stops being readable has lost the thing it was kept
 * for. So the fixtures next door are not written by hand — `tools/old-documents/run.sh`
 * checks each version out and has *it* write the record, because a hand-made fixture
 * pins what somebody remembers a version writing rather than what it wrote.
 *
 * What that turned up is worth stating: the document has not changed shape since v0.3.0.
 * The one real difference is older than that — v0.1.0 and v0.2.0 wrote no `id` at all,
 * which is the shape `after()` still refuses a cursor over, because two records written
 * in the same second have no order search_after can continue from and one of them would
 * be stepped over in silence.
 */
final class DocumentsOlderThanTheReaderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function versions(): iterable
    {
        foreach (glob(__DIR__.'/../Golden/documents/*.json') ?: [] as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    #[DataProvider('versions')]
    public function testARecordWrittenByThatVersionStillReadsTheSame(string $file): void
    {
        $fixture = self::read($file);
        $entry = AuditEntry::fromHit(['_id' => $fixture['_id'], '_source' => $fixture['_source']]);

        self::assertSame([], $entry->warnings, 'the reader had to invent nothing to read this');
        self::assertSame('order', $entry->objectType);
        self::assertSame(42, $entry->objectId);
        self::assertSame('update', $entry->event);
        self::assertSame('2026-08-26 11:59:58', $entry->loggedAt->format('Y-m-d H:i:s'));
        self::assertSame('u-1', $entry->actor);
        self::assertSame(['old' => 'draft', 'new' => 'paid'], $entry->changes['status'] ?? null);
        self::assertSame(['old' => 100, 'new' => 250], $entry->changes['total'] ?? null);
        // Everything the record carried beyond the reserved fields, which is where an
        // application's own columns land however old the document is.
        self::assertSame(7, $entry->attributes['warehouseId'] ?? null);
        self::assertSame('reconciled', $entry->attributes['note'] ?? null);
    }

    public function testTheShapeHasNotMovedSinceTheIdArrived(): void
    {
        // Not a rule that may never be broken — a format can change — but one that has
        // to be broken deliberately. If this fails, a document written today no longer
        // looks like the ones already in everybody's indices, and the question is what
        // reads the old ones afterwards.
        $shapes = [];

        foreach (self::versions() as $version => [$file]) {
            if ($version === 'v0.1.0') {
                continue; // the one that predates ids, and is a different shape on purpose
            }

            $shapes[$version] = array_keys(self::read($file)['_source']);
        }

        self::assertGreaterThan(1, \count($shapes), 'the premise: there is more than one version to compare');

        foreach ($shapes as $version => $fields) {
            self::assertSame(reset($shapes), $fields, sprintf('%s writes a different document shape', $version));
        }
    }

    public function testARecordFromBeforeIdsIsReadableButCannotBeContinuedFrom(): void
    {
        // Both halves matter. The record still reads — history from 2026 does not stop
        // being history — but the sort tuple it produces has a null where the id should
        // be, and search_after cannot tell two such records apart. Paging past one would
        // step over the other without saying so, so the cursor is refused instead.
        $fixture = self::read(__DIR__.'/../Golden/documents/v0.1.0.json');

        self::assertArrayNotHasKey('id', $fixture['_source'], 'the premise: this version wrote no id');

        $entry = AuditEntry::fromHit([
            '_id' => $fixture['_id'],
            '_source' => $fixture['_source'],
            'sort' => ['2026-08-26 11:59:58', null, 'audit_log'],
        ]);

        self::assertSame([], $entry->warnings);
        self::assertSame('order', $entry->objectType);

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('from before audit records carried ids');

        AuditQuery::for('order')->after($entry->sort);
    }

    /**
     * @return array{version: string, index: string, _id: string|null, _source: array<string, mixed>}
     */
    private static function read(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['_source'] ?? null);

        /** @var array{version: string, index: string, _id: string|null, _source: array<string, mixed>} $decoded */
        return $decoded;
    }
}
