<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Command;

use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\AuditException;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Acts on what audit:check reports: adds the fields an existing index lacks — an
 * enricher grew a field after the index was created, the usual story — and refuses
 * to touch anything mapped otherwise than declared, because changing a live field
 * is a reindex, not something a command should do quietly. audit:index:create
 * cannot help here: it leaves existing indices alone by design.
 *
 * A field inside an object travels as a partial parent ("context" carrying only
 * "city"), which Elasticsearch merges without touching the siblings.
 *
 * @internal run as audit:index:sync
 */
#[AsCommand(name: 'audit:index:sync', description: 'Add missing fields to existing audit indices (never changes what is already mapped)')]
final class SyncIndexCommand extends Command
{
    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    public function __construct(
        private readonly GatewayInterface $gateway,
        private readonly IndexResolver $indexResolver,
        private readonly IndexDefinition $definition,
        private readonly iterable $enrichers = [],
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $expected = EnricherMapping::apply($this->definition, $this->enrichers)->properties();
        $healthy = true;

        foreach ($this->indexResolver->all() as $index) {
            try {
                $healthy = $this->sync($io, $index, $expected) && $healthy;
            } catch (AuditException $e) {
                $io->text(sprintf('<error>%s</error>: %s', $index, self::diagnostic($e)));
                $healthy = false;
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string, array<string, mixed>> $expected
     */
    private function sync(SymfonyStyle $io, string $index, array $expected): bool
    {
        if (!$this->gateway->indexExists($index)) {
            $io->text(sprintf('<error>%s</error> missing — run audit:index:create', $index));

            return false;
        }

        $diff = MappingComparison::between($expected, $this->gateway->mapping($index));

        if ($diff->missing !== []) {
            $partial = [];

            foreach ($diff->missing as $path) {
                $partial = array_replace_recursive($partial, self::partial($expected, explode('.', $path)));
            }

            $this->gateway->putMapping($index, $partial);
            $io->text(sprintf('<info>%s</info>: added mapping for %s', $index, implode(', ', $diff->missing)));
        }

        // Reported after the additions: what could be fixed was, and what remains is
        // named for what it needs.
        if ($diff->mismatched !== []) {
            $io->text(sprintf('<error>%s</error> is mapped differently and was not touched (reindex needed): %s', $index, implode('; ', $diff->mismatched)));

            return false;
        }

        if ($diff->missing === []) {
            $io->text(sprintf('<info>%s</info> ok — nothing to add', $index));
        }

        return true;
    }

    /**
     * The smallest putMapping payload that adds one missing path: the declared subtree
     * at its end, wrapped in bare "properties" shells on the way down so nothing
     * already mapped is restated.
     *
     * @param array<string, array<string, mixed>> $expected
     * @param non-empty-list<string>              $segments
     *
     * @return array<string, array<string, mixed>>
     */
    private static function partial(array $expected, array $segments): array
    {
        $head = array_shift($segments);
        /** @var array<string, mixed> $property */
        $property = $expected[$head] ?? [];

        if ($segments === []) {
            return [$head => $property];
        }

        /** @var array<string, array<string, mixed>> $children */
        $children = \is_array($property['properties'] ?? null) ? $property['properties'] : [];

        return [$head => ['properties' => self::partial($children, $segments)]];
    }

    /**
     * What to show an operator: the bundle's sentence and the cause behind it.
     *
     * The bundle deliberately keeps a foreign exception's message out of its own, because
     * those travel into logs, failure transports and listeners. A console command is the
     * other case — a person ran it to find out what is wrong, the output goes to their
     * terminal, and a class name alone would leave them nowhere.
     */
    private static function diagnostic(\Throwable $e): string
    {
        $said = [$e->getMessage()];

        for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $said[] = $cause->getMessage();
        }

        return implode(' — ', array_values(array_unique(array_filter($said, static fn (string $line): bool => $line !== ''))));
    }
}
