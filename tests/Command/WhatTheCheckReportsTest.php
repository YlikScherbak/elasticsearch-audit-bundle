<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Command;

use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\FailureDetails;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `audit:check` puts in front of an operator, and when it calls the deployment
 * unhealthy.
 *
 * It is the command a deployment pipeline runs and a monitor polls, so its exit code
 * is a contract: green has to mean "records are being written and can be read back",
 * and red has to mean something a person can act on. A check that is red about
 * something harmless gets ignored, and an ignored check is worse than none — which
 * is the reason half of these tests exist at all.
 *
 * The other half is about the sentences. Every one of them is read at a moment when
 * something is already wrong, so a number missing from a message ("records were
 * dropped", "the window is too small") is a person guessing at three in the morning.
 */
final class WhatTheCheckReportsTest extends TestCase
{
    private InMemoryGateway $gateway;
    private IndexResolver $resolver;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->resolver = new IndexResolver('audit_log', ['auth' => 'audit_auth']);
    }

    public function testAClusterThatCannotBeReachedIsAFailedCheckAndSaysWhy(): void
    {
        $this->gateway->failWith = TransportUnavailableException::saying('no route to host');

        $tester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $tester->execute([]), 'a cluster nobody can reach was reported as healthy');
        self::assertStringContainsString('Elasticsearch is unreachable', $tester->getDisplay());
        self::assertStringContainsString('no route to host', $tester->getDisplay(), 'and did not say what the cluster said');
    }

    public function testTheSameReasonTwiceIsSaidOnce(): void
    {
        // A cause chain repeats itself — the transport wraps the client which wraps the
        // socket, each restating the last — and an error made of the same sentence three
        // times reads as three problems.
        $inner = new \RuntimeException('connection refused');
        $middle = new \RuntimeException('connection refused', 0, $inner);
        $outer = new \RuntimeException('', 0, $middle);

        $this->gateway->failWith = TransportUnavailableException::because($outer);

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertSame(1, substr_count($tester->getDisplay(), 'connection refused'), 'the same reason was reported more than once');
    }

    public function testTheClusterIsNamedByItsOwnNameRatherThanOneOfItsNodes(): void
    {
        // Every node answers with its own name, and the cluster answers with the
        // cluster's. An operator checking which environment they are pointed at needs
        // the second one; the first is whichever node happened to reply.
        $this->gateway->clusterName = 'production-audit';
        $this->everyIndexExists();

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertStringContainsString('production-audit', $tester->getDisplay());
    }

    public function testAnIndexThatIsNotThereIsAFailedCheckWithTheCommandToFixIt(): void
    {
        $this->gateway->indices['audit_log'] = (new IndexDefinition())->toArray();

        $tester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $tester->execute([]), 'a missing index passed the check');

        $display = $tester->getDisplay();

        self::assertStringContainsString('audit_auth', $display);
        self::assertStringContainsString('audit:index:create', $display, 'and did not say what to run');
    }

    public function testAWindowTheIndexRefusesIsReportedForEveryIndexItIsWrongOn(): void
    {
        // Two indices behind one configuration drift apart one at a time — one created
        // before the setting was raised, one after. Reporting the first and stopping
        // leaves the second to be discovered by a reader hitting the ceiling.
        $this->everyIndexExists();
        $this->gateway->indices['audit_log']['settings'] = ['max_result_window' => '5000'];
        $this->gateway->indices['audit_auth']['settings'] = ['max_result_window' => '7000'];

        $tester = new CommandTester($this->command(maxResultWindow: 10_000));

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('(5000) on audit_log', $display);
        self::assertStringContainsString('(7000) on audit_auth', $display, 'only the first drifting index was reported');
    }

    public function testASettingElasticsearchAnswersAsTextIsStillANumber(): void
    {
        // Elasticsearch answers index settings as strings — "10000", not 10000 — and a
        // comparison that forgets it reads every window as zero and calls every index
        // wrong, which is a check that cries wolf on a perfectly good deployment.
        $this->everyIndexExists();
        $this->gateway->indices['audit_log']['settings'] = ['max_result_window' => '10000'];
        $this->gateway->indices['audit_auth']['settings'] = ['max_result_window' => '10000'];

        $tester = new CommandTester($this->command(maxResultWindow: 10_000));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString('max_result_window', $tester->getDisplay());
    }

    public function testEachVersionMessageNamesTheReleaseTheParameterArrivedIn(): void
    {
        // Three branches say three different things about the same cluster, and every
        // one of them has to carry the number an operator would act on. "8.18" is also
        // inside "18.18", so the phrase is pinned rather than the digits.
        $this->everyIndexExists();
        $this->gateway->version = '8.5.1';

        foreach ([
            'told to send it anyway' => [false, FailureDetails::Cause, 'and later do'],
            'repeating foreign messages' => [null, FailureDetails::Full, 'and later know it'],
            'left to itself' => [null, FailureDetails::Cause, 'and later know it'],
        ] as $case => [$sourceOnError, $details, $fragment]) {
            $tester = new CommandTester($this->command(sourceOnError: $sourceOnError, failureDetails: $details));
            $tester->execute([]);

            self::assertStringContainsString('(8.18 '.$fragment.')', $tester->getDisplay(), $case.' did not name the release');
        }
    }

    public function testAMissingIndexIsComplainedAboutOnceRatherThanTwice(): void
    {
        // Saying it and carrying on would ask the cluster for the mapping of an index
        // that is not there, and report the same index twice in two different
        // vocabularies — the second one about a failure the first already explained.
        $this->gateway->indices['audit_log'] = (new IndexDefinition())->toArray();

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertSame(1, substr_count($tester->getDisplay(), 'audit_auth'), 'the same missing index was reported twice');
    }

    public function testAnEmptyLinkInTheChainIsNotAnEmptyLineInTheReason(): void
    {
        // An exception with no message of its own is ordinary — a wrapper that exists to
        // add a type rather than a sentence. Joined in regardless it shows as a dangling
        // separator, which reads as a message somebody forgot to write.
        $this->gateway->failWith = TransportUnavailableException::because(new \RuntimeException('', 0, new \RuntimeException('connection refused')));

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('—  —', $display);
        self::assertStringNotContainsString('— \n', $display);
    }

    public function testATransportThatIsNotTheOutboxIsNotCheckedForOne(): void
    {
        // Both halves or neither: the queue and the connection it must share arrive
        // together or not at all, and a check that went looking for the second because
        // it found the first would fail every deployment that does not use the outbox.
        $this->everyIndexExists();

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [], AuditQuery::DEFAULT_MAX_WINDOW, new \stdClass(), null, 'audit_outbox'));

        self::assertSame(Command::SUCCESS, $tester->execute([]), 'a deployment without an outbox was checked for one');
        self::assertStringNotContainsString('outbox', $tester->getDisplay());
    }

    private function everyIndexExists(): void
    {
        foreach ($this->resolver->all() as $index) {
            $this->gateway->indices[$index] = (new IndexDefinition())->toArray();
        }
    }

    private function command(
        int $maxResultWindow = AuditQuery::DEFAULT_MAX_WINDOW,
        ?bool $sourceOnError = null,
        FailureDetails $failureDetails = FailureDetails::Cause,
    ): CheckCommand {
        return new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [], $maxResultWindow, null, null, '', $sourceOnError, $failureDetails);
    }
}
