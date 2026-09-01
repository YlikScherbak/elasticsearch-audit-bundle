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

        $missing = [];
        $mismatched = [];
        self::compare('', $expected, $this->gateway->mapping($index), $missing, $mismatched);

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

    /**
     * Whatever the definition declares has to hold in the index: the type, the options
     * behind it, and the fields inside an object. A date whose format drifted refuses
     * every document the writer sends, and a nested field that was never mapped filters
     * to nothing — both fail exactly like a wrong top-level type, and neither is visible
     * to a comparison that stops at the type.
     *
     * The walk is one-directional: an option the index has and the definition never
     * named is an Elasticsearch default, not drift.
     *
     * @param array<string, array<string, mixed>> $expected
     * @param array<string, mixed>                $actual
     * @param list<string>                        $missing
     * @param list<string>                        $mismatched
     */
    private static function compare(string $prefix, array $expected, array $actual, array &$missing, array &$mismatched): void
    {
        foreach ($expected as $field => $property) {
            $path = $prefix.$field;

            if (!\is_array($actual[$field] ?? null)) {
                $missing[] = $path;

                continue;
            }

            $found = $actual[$field];

            // An object needs no "type" in the mapping — a field holding properties and
            // nothing else is an object on both sides of the comparison.
            $expectedType = $property['type'] ?? (isset($property['properties']) ? 'object' : null);
            $actualType = $found['type'] ?? (isset($found['properties']) ? 'object' : null);

            if ($expectedType !== null && $actualType !== $expectedType) {
                $mismatched[] = sprintf('%s is %s, expected %s', $path, $actualType ?? 'an object', $expectedType);

                continue; // under a wrong type, every option is noise
            }

            foreach ($property as $option => $value) {
                if ($option === 'type' || $option === 'properties') {
                    continue;
                }

                if (!\array_key_exists($option, $found)) {
                    $mismatched[] = sprintf('%s %s is not set, expected %s', $path, $option, self::describe($value));
                } elseif ($found[$option] !== $value) {
                    $mismatched[] = sprintf('%s %s is %s, expected %s', $path, $option, self::describe($found[$option]), self::describe($value));
                }
            }

            if (\is_array($property['properties'] ?? null)) {
                self::compare($path.'.', $property['properties'], \is_array($found['properties'] ?? null) ? $found['properties'] : [], $missing, $mismatched);
            }
        }
    }

    private static function describe(mixed $value): string
    {
        return \is_string($value) ? '"'.$value.'"' : json_encode($value, \JSON_THROW_ON_ERROR);
    }
}
