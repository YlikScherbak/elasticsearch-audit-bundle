<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Command;

use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ClusterVersion;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\AuditException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Writer\FailureDetails;
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
     * @param bool|null                        $sourceOnError   client.include_source_on_error as the gateway
     *                                                          reads it: null decides from the version, a
     *                                                          boolean was decided by the deployment
     */
    public function __construct(
        private readonly GatewayInterface $gateway,
        private readonly IndexResolver $indexResolver,
        private readonly IndexDefinition $definition,
        private readonly iterable $enrichers = [],
        private readonly int $maxResultWindow = AuditQuery::DEFAULT_MAX_WINDOW,
        private readonly ?object $outboxQueue = null,
        private readonly ?Connection $auditedConnection = null,
        private readonly string $outboxQueueName = '',
        // Appended rather than placed with the other cluster-facing arguments: every
        // caller passes these positionally, and a parameter added in the middle is
        // silently a different argument to all of them.
        private readonly ?bool $sourceOnError = null,
        private readonly FailureDetails $failureDetails = FailureDetails::Cause,
        // Which road records take. Only one of them is worth a line here, and it is the
        // one that makes every other line of this command misleading.
        private readonly string $transport = 'sync',
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

        $version = \is_string($info['version']['number'] ?? null) ? $info['version']['number'] : '?';

        $io->text(sprintf('Cluster <info>%s</info>, Elasticsearch <info>%s</info>', $info['cluster_name'] ?? $info['name'] ?? '?', $version));

        $healthy = true;

        // Two different things can be true of an old cluster, and only one of them is a
        // failure. Left to itself the bundle withholds a parameter this cluster does not
        // know and carries on; told to send it anyway, it sends it, and an unknown query
        // parameter is a 400 on every single write. A check that called both "unhealthy"
        // could not be used as a health check at all on a cluster below 8.18 — which is
        // most of them — and one that called both fine would say nothing about a
        // deployment refusing every record.
        if (!ClusterVersion::knowsIncludeSourceOnError($version)) {
            if ($this->sourceOnError === false) {
                $io->text(sprintf('<error>Elasticsearch %s does not know include_source_on_error</error> (%d.%d and later do), and client.include_source_on_error is false, which sends it regardless: an unknown query parameter is answered with a 400, so every audit record would be refused. Set it to "auto", or upgrade the cluster.', $version, GatewayInterface::MINIMUM_VERSION[0], GatewayInterface::MINIMUM_VERSION[1]));

                $healthy = false;
            } elseif ($this->failureDetails === FailureDetails::Full) {
                // The combination that actually costs something, said as a fact rather
                // than as the conditional above: this cluster quotes a refused document
                // back, and this deployment has asked for foreign messages to be
                // repeated. Not a failure - it is a choice, and both halves of it were
                // configured on purpose - but it is the one an operator wants to meet
                // here rather than in a log.
                $io->text(sprintf('<comment>Elasticsearch %s predates include_source_on_error</comment> (%d.%d and later know it), so a document it refuses is quoted back in its own error - and redact.failure_details is "full", which repeats what other code said. On this cluster that means a refused audit record can reach your logs, your failure events and the exceptions you catch. Set failure_details to "cause" for the version-independent guarantee, or upgrade the cluster.', $version, GatewayInterface::MINIMUM_VERSION[0], GatewayInterface::MINIMUM_VERSION[1]));
            } else {
                $io->text(sprintf('<comment>Elasticsearch %s predates include_source_on_error</comment> (%d.%d and later know it), so writes do not carry it and a document this cluster refuses is quoted back in its own error. The bundle does not repeat that error — it names the type and the field and nothing else, and redact.failure_details: "cause", the default, keeps that exception out of what is logged, raised and dispatched. Setting it to "full" on this cluster means a refused audit record can appear in your logs.', $version, GatewayInterface::MINIMUM_VERSION[0], GatewayInterface::MINIMUM_VERSION[1]));
            }
        }

        if ($this->transport === 'collector') {
            // Everything below this line is about indices that nothing is being written
            // to. The collector is the test transport: it keeps finished records in
            // memory and sends none, so a green check here says the indices are ready
            // for a history that is not arriving. Said first, because it changes how to
            // read the rest.
            $io->text('<error>transport: collector</error> — finished records are kept in memory and never reach Elasticsearch. That is the test transport; in any environment that is meant to keep history, set transport to sync, messenger or outbox.');

            $healthy = false;
        }

        foreach ($this->indexResolver->all() as $index) {
            try {
                $expected = EnricherMapping::forIndex($this->definition, $this->enrichers, $this->indexResolver, $index)->properties();

                $healthy = $this->checkIndex($io, $index, $expected) && $healthy;
            } catch (AuditException $e) {
                $io->text(sprintf('<error>%s</error>: %s', $index, self::diagnostic($e)));
                $healthy = false;
            }
        }

        $healthy = $this->checkTheOutbox($io) && $healthy;

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Whether the outbox queue is where the guarantee needs it to be.
     *
     * The boot checks this whenever the DSN can be read, and the DSN that most
     * production applications write cannot be: it comes from an environment variable,
     * and until that is resolved the configuration says nothing at all. This is where
     * it is asked of the resolved services instead - of the queue itself, which knows
     * which connection it holds.
     *
     * Asked through configureSchema(), whose whole contract is "add your table if this
     * is your connection": a queue that answers with a table shares it, one that
     * answers with nothing does not. Nothing is written and nothing is created - the
     * schema exists only to be asked.
     */
    private function checkTheOutbox(SymfonyStyle $io): bool
    {
        if ($this->outboxQueue === null || $this->auditedConnection === null) {
            return true; // any transport but the outbox
        }

        if (!$this->outboxQueue instanceof DoctrineTransport) {
            $io->text(sprintf('<comment>%s</comment>: not a Doctrine transport, so its records are not committed with the changes they describe.', $this->outboxQueueName));

            return false;
        }

        // Read off the schema handed in rather than off what comes back: the method
        // returns void on Symfony 6.4 and a Schema on 7, and it fills in the object it
        // was given in both. Using the return value is what the oldest supported version
        // will not even let an analyser look at.
        $schema = new Schema();
        $this->outboxQueue->configureSchema($schema, $this->auditedConnection, static fn (): bool => false);

        if ($schema->getTables() === []) {
            $io->text(sprintf('<error>%s</error>: the queue is on a different Doctrine connection from the entities being audited. Two connections are two transactions even against one database, so a record and the change it describes commit separately - which is the one thing transport: outbox exists to prevent.', $this->outboxQueueName));

            return false;
        }

        try {
            $waiting = $this->outboxQueue->getMessageCount();
        } catch (\Throwable $e) {
            $io->text(sprintf('<error>%s</error>: the queue table cannot be read (%s). It is created by a migration and never by the bundle - auto_setup would run DDL, which on MySQL commits the transaction it is standing in.', $this->outboxQueueName, $e::class));

            return false;
        }

        $io->text(sprintf('Outbox <info>%s</info>: on the audited connection, %d record(s) waiting for a worker', $this->outboxQueueName, $waiting));

        return true;
    }

    /**
     * Whether a cluster this old can be written to at all.
     *
     * A version it cannot read is not called unsupported: `audit:check` reports what it
     * can see, and inventing a failure out of a string nobody recognised would be worse
     * than saying nothing about it.
     */

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
