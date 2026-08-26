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
 * actually mapped — a field missing from the mapping is the usual reason a filter
 * silently returns nothing.
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
            if (!$this->gateway->indexExists($index)) {
                $io->text(sprintf('<error>%s</error> missing — run audit:index:create', $index));
                $healthy = false;
                continue;
            }

            $missing = array_diff_key($expected, $this->gateway->mapping($index));

            if ($missing === []) {
                $io->text(sprintf('<info>%s</info> ok', $index));
                continue;
            }

            $io->text(sprintf('<comment>%s</comment> exists but lacks mapping for: %s', $index, implode(', ', array_keys($missing))));
            $healthy = false;
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
