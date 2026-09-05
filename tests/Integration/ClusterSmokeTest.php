<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the CI wiring: the client version under test can reach the cluster
 * version under test and round-trip a document. Every later integration test
 * builds on the same base class.
 */
#[Group('integration')]
final class ClusterSmokeTest extends ElasticsearchTestCase
{
    public function testTheClusterAnswersAndReportsItsVersion(): void
    {
        $info = self::client()->info()->asArray();

        self::assertArrayHasKey('version', $info);
        self::assertMatchesRegularExpression('/^[89]\./', $info['version']['number']);

        // 8.0-8.17 passed this check and cannot honour include_source_on_error, which
        // is the difference between a rejected document being named and being quoted.
        // The composer floor says 8.18; the gate has to say it too, or the claim is
        // only true of the versions somebody happened to run.
        [$major, $minor] = array_map('intval', explode('.', $info['version']['number']) + [1 => '0']);

        self::assertTrue($major >= 9 || $minor >= 18, sprintf('Elasticsearch %s is below the supported floor of 8.18', $info['version']['number']));
    }

    public function testTheClusterAcceptsTheParameterThatKeepsASourceOutOfAnError(): void
    {
        // Why the floor is 8.18: include_source_on_error does not exist before it, and
        // an unknown query parameter is a 400 — which the gateway classifies as a
        // permanent refusal, so on 8.0-8.17 every audit record would be dropped by the
        // very line meant to protect it. This proves the cluster under test knows it.
        $index = $this->scratchIndex();

        try {
            self::client()->indices()->create(['index' => $index]);

            $response = self::client()->index([
                'index' => $index,
                'id' => '1',
                'include_source_on_error' => false,
                'body' => ['objectType' => 'order'],
            ])->asArray();

            self::assertSame('created', $response['result']);
        } finally {
            $this->dropIndex($index);
        }
    }

    public function testAValueTheClusterRefusesDoesNotComeBackInTheBundlesException(): void
    {
        // Measured, not assumed: on 8.19 and 9.1 include_source_on_error suppresses the
        // document source and leaves "Preview of field's value: '…'" exactly where it
        // was, with or without the parameter. So the thing that actually keeps a refused
        // value out of the bundle's exception — and out of every log line and event
        // built from it — is RequestRejectedException::withoutValuePreview(), and it is
        // proved here against a cluster rather than against a fixture of what one says.
        $index = $this->scratchIndex();
        $gateway = new ElasticsearchGateway(self::client());

        try {
            self::client()->indices()->create(['index' => $index, 'body' => [
                'mappings' => ['properties' => ['total' => ['type' => 'long']]],
            ]]);

            $gateway->index($index, ['total' => 'hunter2-the-actual-password'], '1');

            self::fail('the cluster should have refused a string for a long');
        } catch (RequestRejectedException $e) {
            self::assertStringNotContainsString('hunter2-the-actual-password', $e->getMessage());
            self::assertStringContainsString('total', $e->getMessage(), 'the field is named — that is the diagnostic that stays');
        } finally {
            $this->dropIndex($index);
        }
    }

    public function testADocumentCanBeIndexedAndReadBack(): void
    {
        $index = $this->scratchIndex();

        try {
            self::client()->indices()->create(['index' => $index]);
            self::client()->index([
                'index'   => $index,
                'id'      => '1',
                'refresh' => 'true',
                'body'    => ['objectType' => 'order', 'objectId' => 42, 'event' => 'update'],
            ]);

            $hits = self::client()->search([
                'index' => $index,
                'body'  => ['query' => ['term' => ['objectId' => 42]]],
            ])->asArray()['hits']['hits'];

            self::assertCount(1, $hits);
            self::assertSame('order', $hits[0]['_source']['objectType']);
        } finally {
            $this->dropIndex($index);
        }
    }
}
