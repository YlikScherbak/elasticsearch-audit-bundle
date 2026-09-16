<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ClusterVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one comparison that decides whether a write carries include_source_on_error.
 *
 * It was reached only through the gateway before, with three versions that are not
 * near anything: 8.19, 8.17 and 8.5. Mutation testing found the shape of that — the
 * boundary itself was never asked about, so `>=` could have been `>` and nothing
 * would have noticed, which is one minor version of clusters silently losing the
 * parameter.
 */
final class ClusterVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function versions(): iterable
    {
        // The boundary, from both sides. 8.18 is the first release that knows the
        // parameter, so it is the one version where > and >= disagree.
        yield 'the boundary itself' => ['8.18.0', true];
        yield 'one patch below the boundary' => ['8.17.9', false];
        yield 'one minor above' => ['8.19.0', true];

        yield 'a later major' => ['9.1.0', true];
        yield 'an earlier major, however high its minor' => ['7.99.0', false];
        yield 'the cluster this was all reported from' => ['8.5.1', false];

        // Unreadable counts as new enough, and the caret is what makes these unreadable
        // rather than "8.19": info() reports a bare version, so anything with something
        // in front of it is a string this does not recognise. Withholding the parameter
        // on a string nobody can parse would turn a formatting surprise into a quietly
        // weaker guarantee; sending it is wrong out loud instead.
        yield 'a version with something before it' => ['v8.5.1', true];
        yield 'a major with no minor' => ['8', true];
        yield 'nothing at all' => ['', true];
    }

    #[DataProvider('versions')]
    public function testWhetherAClusterKnowsTheParameter(string $version, bool $expected): void
    {
        self::assertSame($expected, ClusterVersion::knowsIncludeSourceOnError($version));
    }
}
