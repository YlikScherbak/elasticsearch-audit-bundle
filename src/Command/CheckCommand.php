<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Command;

use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\AuditException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Answers "is the audit log going to work?" in one run: cluster reachable, every
 * configured index present, and every field the bundle or an enricher writes
 * mapped the way it is declared — the type, its options and the fields inside an
 * object. A field missing from the mapping is the usual reason a filter silently
 * returns nothing; a field of another type (loggedAt as text, say) or of the
 * right type with a drifted option (a date without our format refuses every
 * document) is what an index Elasticsearch created on its own looks like: writes
 * or reads fail, and the fix is a reindex.
 *
 * @internal run as audit:check
 */
#[AsCommand(name: 'audit:check', description: 'Check the Elasticsearch connection and the audit indices')]
final class CheckCommand extends Command
{
    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     * @param int                              $maxResultWindow reader.max_result_window — checked against
     *                                                          every index's own, because the two must
     *                                                          move together or a deep page is refused
     */
    public function __construct(
        private readonly GatewayInterface $gateway,
        private readonly IndexResolver $indexResolver,
        private readonly IndexDefinition $definition,
        private readonly iterable $enrichers = [],
        private readonly int $maxResultWindow = AuditQuery::DEFAULT_MAX_WINDOW,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $info = $this->gateway->info();
        } catch (AuditException $e) {
            $io->error('Elasticsearch is unreachable: '.self::diagnostic($e));

            return self::FAILURE;
        }

        $io->text(sprintf('Cluster <info>%s</info>, Elasticsearch <info>%s</info>', $info['cluster_name'] ?? $info['name'] ?? '?', $info['version']['number'] ?? '?'));

        $expected = EnricherMapping::apply($this->definition, $this->enrichers)->properties();
        $healthy = true;

        foreach ($this->indexResolver->all() as $index) {
            try {
                $healthy = $this->checkIndex($io, $index, $expected) && $healthy;
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
    private function checkIndex(SymfonyStyle $io, string $index, array $expected): bool
    {
        if (!$this->gateway->indexExists($index)) {
            $io->text(sprintf('<error>%s</error> missing — run audit:index:create', $index));

            return false;
        }

        $diff = MappingComparison::between($expected, $this->gateway->mapping($index));
        $drift = $this->windowDrift($index);

        // The claim the bundle makes about its own indices, checked rather than assumed:
        // dynamic: false is what keeps Elasticsearch from inventing a mapping, and an
        // index created by hand, a changed template or a new member of an alias can be
        // without it while every declared field is mapped exactly right.
        foreach ($this->gateway->indicesAcceptingUnknownFields($index) as $open) {
            $drift[] = sprintf('%s is not "dynamic: false", so Elasticsearch will map fields nobody declared — the mapping grows on its own and later documents are refused over type conflicts. Reindex it into an index that audit:index:create made, or set dynamic to false on it.', $open);
        }

        if ($diff->clean() && $drift === []) {
            $io->text(sprintf('<info>%s</info> ok', $index));

            return true;
        }

        if ($diff->missing !== []) {
            $io->text(sprintf('<comment>%s</comment> exists but lacks mapping for: %s — run audit:index:sync to add it', $index, implode(', ', $diff->missing)));
        }

        if ($diff->mismatched !== []) {
            $io->text(sprintf('<error>%s</error> is mapped differently (reindex needed): %s', $index, implode('; ', $diff->mismatched)));
        }

        foreach ($drift as $line) {
            $io->text(sprintf('<error>%s</error>: %s', $index, $line));
        }

        return false;
    }

    /**
     * reader.max_result_window promises pages the index then has to serve: an index
     * whose own window is lower refuses them, and the drift surfaces on a deep page in
     * production. Checked per concrete index — an alias can stand for several.
     *
     * @return list<string>
     */
    private function windowDrift(string $index): array
    {
        $drift = [];

        foreach ($this->gateway->settings($index) as $concrete => $settings) {
            $window = (int) ($settings['max_result_window'] ?? AuditQuery::DEFAULT_MAX_WINDOW);

            if ($window < $this->maxResultWindow) {
                $drift[] = sprintf('index.max_result_window (%d) on %s is below reader.max_result_window (%d) — a page past row %d will be refused: raise the index setting, or lower the reader\'s to match', $window, $concrete, $this->maxResultWindow, $window);
            }
        }

        return $drift;
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
