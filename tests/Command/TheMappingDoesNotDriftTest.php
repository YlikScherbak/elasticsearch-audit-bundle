<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Command;

use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The mapping, written down where a change to it shows up as a diff.
 *
 * Elasticsearch will not re-type a field that already holds documents. So the mapping
 * this bundle creates is not a default anybody can adjust later — it is the shape every
 * index created by every installation carries for as long as that index exists, and
 * changing it silently would split the world into indices created before the change and
 * indices created after, with the same bundle unable to read both the same way.
 *
 * Which makes "did the mapping change" a question worth answering in a review rather
 * than in production. The file next to this test is the answer as it stands; a change
 * to the definition fails here, and the fix is to look at the diff and decide, then
 * update the file in the same commit. That is the whole mechanism: it does not prevent
 * anything, it makes it deliberate.
 *
 * @see tests/Golden/index-mapping.json
 */
final class TheMappingDoesNotDriftTest extends TestCase
{
    private const GOLDEN = __DIR__.'/../Golden/index-mapping.json';

    public function testTheDefinitionIsWhatTheFileSaysItIs(): void
    {
        $dumped = json_encode((new IndexDefinition())->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";

        self::assertSame(
            (string) file_get_contents(self::GOLDEN),
            $dumped,
            "The index mapping changed.\n\n"
            ."Elasticsearch cannot re-type a field that already holds documents, so this is a decision "
            ."taken once for every index every installation will ever create. Read the diff, decide "
            ."whether the change is what you meant, and if it is, write the new definition into "
            ."tests/Golden/index-mapping.json in the same commit — with a line in the CHANGELOG, "
            ."because existing indices will not have it.",
        );
    }

    public function testTheCommandDumpsWhatTheDefinitionHolds(): void
    {
        // The file above is the definition; this is the command an operator actually runs
        // to see it. They have drifted apart before — a command that reads the definition
        // through one path and creates indices through another is how an index ends up
        // with a mapping nobody dumped.
        $tester = new CommandTester(new CreateIndexCommand(new InMemoryGateway(), new IndexResolver('audit_log'), new IndexDefinition(), []));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dump' => true]));

        $dumped = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame($golden, $dumped, 'audit:index:create --dump no longer prints the definition it creates indices from');
    }

    public function testWhatTheCommandCreatesIsWhatItDumps(): void
    {
        // And the third corner of the same triangle: the index really created carries the
        // mapping that was dumped. Two of the three agreeing is not enough — the one that
        // matters is the one written into the cluster.
        $gateway = new InMemoryGateway();
        $tester = new CommandTester(new CreateIndexCommand($gateway, new IndexResolver('audit_log'), new IndexDefinition(), []));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame($golden, $gateway->indices['audit_log'] ?? null);
    }
}
