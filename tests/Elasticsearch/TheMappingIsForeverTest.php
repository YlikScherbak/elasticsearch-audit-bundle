<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ClientLogGate;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Two contracts that cannot be changed after the fact.
 *
 * **The base mapping.** Elasticsearch will not re-type a field that already holds
 * documents, so every entry in it is a decision taken once for the life of an index:
 * `keyword` because these are filtered and aggregated by rather than searched in, a
 * fixed date format because the writer writes that format, and `changes` stored but
 * not indexed because a free-form object indexed field-by-field is a mapping
 * explosion and then a refusal. Dropping one of them is not a mapping that is missing
 * a field — it is one where Elasticsearch guesses, and the guess is what an index
 * carries forever.
 *
 * **What the client is allowed to say.** The official client logs the audited document
 * at debug, both bodies, and puts the PSR-7 objects into the context of its info
 * lines. None of that is configurable at the client, so the gate is where it stops —
 * and a comparison that matched only one spelling of "debug" would let the most
 * detailed level through on whichever library spells it the other way.
 */
final class TheMappingIsForeverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function baseFields(): iterable
    {
        yield 'the record id' => ['id', ['type' => 'keyword']];
        yield 'the object type' => ['objectType', ['type' => 'keyword']];
        yield 'the event' => ['event', ['type' => 'keyword']];
        yield 'the actor' => ['source', ['type' => 'keyword']];
        yield 'when it happened' => ['loggedAt', ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss']];
        yield 'what changed' => ['changes', ['type' => 'object', 'enabled' => false]];
    }

    /**
     * @param array<string, mixed> $mapping
     */
    #[DataProvider('baseFields')]
    public function testEveryBaseFieldIsMappedAndMappedThatWay(string $field, array $mapping): void
    {
        self::assertSame($mapping, (new IndexDefinition())->properties()[$field] ?? null);
    }

    public function testTheObjectIdIsMappedTheWayTheApplicationSaidItWould(): void
    {
        // The one base field an application chooses: keyword by default, because an id
        // is only ever matched exactly, and integer where every audited type has numeric
        // ids and somebody wants to sort or range over them.
        self::assertSame(['type' => 'keyword'], (new IndexDefinition())->properties()['objectId']);
        self::assertSame(['type' => 'integer'], (new IndexDefinition('integer'))->properties()['objectId']);
    }

    public function testSettingsAreAddedToRatherThanReplaced(): void
    {
        // An index definition collects settings from more than one place — the bundle's
        // own, then the application's. Replacing rather than merging would drop whatever
        // was decided first, which is how an index ends up created without the refresh
        // interval or the shard count somebody set two lines earlier.
        $definition = (new IndexDefinition())
            ->withSettings(['number_of_shards' => 1])
            ->withSettings(['number_of_replicas' => 0]);

        $settings = $definition->toArray()['settings'] ?? [];

        self::assertSame(1, $settings['number_of_shards'] ?? null);
        self::assertSame(0, $settings['number_of_replicas'] ?? null, 'the second setting replaced the first instead of joining it');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spellingsOfDebug(): iterable
    {
        yield 'as PSR-3 spells it' => [LogLevel::DEBUG];
        yield 'shouted' => ['DEBUG'];
        yield 'in between' => ['Debug'];
    }

    #[DataProvider('spellingsOfDebug')]
    public function testNothingTheClientSaysAtDebugReachesTheApplicationsLog(string $level): void
    {
        $said = [];
        $gate = new ClientLogGate($this->recording($said));

        $gate->log($level, 'Headers: {"authorization":"Basic c2VjcmV0"} Body: {"changes":{"password":"secret"}}');

        self::assertSame([], $said, 'the audited document reached the log at '.$level);
    }

    public function testWhatTheClientSaysAboveDebugStillGetsThrough(): void
    {
        // The gate is not a gag: method, URL, status and retry count are what an
        // operator needs to see the traffic, and they are allowed through.
        $said = [];
        $gate = new ClientLogGate($this->recording($said));

        $gate->log(LogLevel::INFO, 'Request: GET http://es.test:9200/audit_log/_search');

        self::assertCount(1, $said);
        self::assertStringContainsString('audit_log/_search', $said[0]);
    }

    /**
     * @param list<string> $said
     */
    private function recording(array &$said): AbstractLogger
    {
        return new class($said) extends AbstractLogger {
            /** @param list<string> $said */
            public function __construct(private array &$said)
            {
            }

            /**
             * @param mixed               $level
             * @param mixed               $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->said[] = (string) $message;
            }
        };
    }
}
