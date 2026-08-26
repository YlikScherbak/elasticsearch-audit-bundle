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

        if ($input->getOption('dump')) {
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
                $io->error(sprintf('%s: %s', $index, $e->getMessage()));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
