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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates every index the configuration routes to, with the bundle's mapping plus
 * the fields the application's enrichers declare. Existing indices are left alone —
 * changing a live mapping is a reindex, not something a command should do quietly.
 *
 * @internal run as audit:index:create
 */
#[AsCommand(name: 'audit:index:create', description: 'Create the Elasticsearch audit indices with their mapping')]
final class CreateIndexCommand extends Command
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

    protected function configure(): void
    {
        $this->addOption('dump', null, InputOption::VALUE_NONE, 'Print the index definition as JSON instead of creating anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $definition = EnricherMapping::apply($this->definition, $this->enrichers);

        if ($input->getOption('dump') === true) {
            $output->writeln((string) json_encode($definition->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($this->indexResolver->all() as $index) {
            try {
                if ($this->gateway->indexExists($index)) {
                    $io->text(sprintf('<comment>%s</comment> already exists, left untouched', $index));
                    continue;
                }

                $this->gateway->createIndex($index, $definition->toArray());
                $io->text(sprintf('<info>%s</info> created', $index));
            } catch (AuditException $e) {
                $io->error(sprintf('%s: %s', $index, self::diagnostic($e)));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
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
