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
 * Answers "is the audit log going to work?" in one run: cluster reachable, every
 * configured index present, and every field the bundle or an enricher writes
 * mapped with the type it declares — a field missing from the mapping is the
 * usual reason a filter silently returns nothing, and a field of another type
 * (loggedAt as text, say) is what an index Elasticsearch created on its own
 * looks like: reads fail, and the fix is a reindex.
 */
#[AsCommand(name: 'audit:check', description: 'Check the Elasticsearch connection and the audit indices')]
final class CheckCommand extends Command
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

        try {
            $info = $this->gateway->info();
        } catch (AuditException $e) {
            $io->error('Elasticsearch is unreachable: '.$e->getMessage());

            return self::FAILURE;
        }

        $io->text(sprintf('Cluster <info>%s</info>, Elasticsearch <info>%s</info>', $info['cluster_name'] ?? $info['name'] ?? '?', $info['version']['number'] ?? '?'));

        $expected = EnricherMapping::apply($this->definition, $this->enrichers)->properties();
        $healthy = true;

        foreach ($this->indexResolver->all() as $index) {
            try {
                $healthy = $this->checkIndex($io, $index, $expected) && $healthy;
            } catch (AuditException $e) {
                $io->text(sprintf('<error>%s</error>: %s', $index, $e->getMessage()));
                $healthy = false;
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string, array<string, mixed>> $expected
     */
    private function checkIndex(SymfonyStyle $io, string $index, array $expected): bool
    {
        if (!$this->gateway->indexExists($index)) {
            $io->text(sprintf('<error>%s</error> missing — run audit:index:create', $index));

            return false;
        }

        $actual = $this->gateway->mapping($index);
        $missing = array_keys(array_diff_key($expected, $actual));
        $mismatched = [];

        foreach (array_intersect_key($expected, $actual) as $field => $property) {
            $expectedType = $property['type'] ?? null;
            $actualType = $actual[$field]['type'] ?? null;

            if ($expectedType !== null && $actualType !== $expectedType) {
                $mismatched[] = sprintf('%s is %s, expected %s', $field, $actualType ?? 'an object', $expectedType);
            }
        }

        if ($missing === [] && $mismatched === []) {
            $io->text(sprintf('<info>%s</info> ok', $index));

            return true;
        }

        if ($missing !== []) {
            $io->text(sprintf('<comment>%s</comment> exists but lacks mapping for: %s', $index, implode(', ', $missing)));
        }

        if ($mismatched !== []) {
            $io->text(sprintf('<error>%s</error> is mapped differently (reindex needed): %s', $index, implode('; ', $mismatched)));
        }

        return false;
    }
}
