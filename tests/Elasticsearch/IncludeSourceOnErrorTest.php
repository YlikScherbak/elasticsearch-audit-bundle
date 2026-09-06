<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Which clusters a write asks not to quote the refused document back to.
 *
 * The parameter has existed since 8.18 and an unknown query parameter is a 400, so
 * sending it to every cluster made the bundle unusable below that — a hard floor
 * built out of a first line whose second line was holding anyway (a refusal is
 * described structurally, and the cluster's own exception does not travel under the
 * default redact.failure_details). What is asserted here is the whole of the new
 * behaviour: where it goes, where it does not, what a deployment can insist on, and
 * that deciding costs one request rather than one per write.
 */
final class IncludeSourceOnErrorTest extends TestCase
{
    /** @var list<string> every request the gateway made, as "METHOD /path?query" */
    private array $seen = [];
    /** @var list<string> the warnings the gateway wrote */
    private array $warnings = [];
    /** @var list<string> and what it said at info */
    private array $infos = [];
    private MovableClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MovableClock();
    }

    public function testAModernClusterIsAskedNotToQuoteTheDocument(): void
    {
        $gateway = $this->gateway('8.19.0');

        $gateway->index('audit_log', ['objectId' => 1], 'a');
        $gateway->bulk([['index' => 'audit_log', 'document' => ['objectId' => 2], 'id' => 'b']]);

        self::assertSame(['PUT /audit_log/_doc/a', 'POST /_bulk'], $this->writesWithTheParameter());
        self::assertSame([], $this->writesWithoutTheParameter(), 'a write reached 8.19 without it');
    }

    public function testAClusterThatWouldNotUnderstandIsNotSentIt(): void
    {
        // The whole point. On 8.17 this used to be a 400 for every record; now the
        // parameter stays behind and the record is written.
        $gateway = $this->gateway('8.17.4');

        $gateway->index('audit_log', ['objectId' => 1], 'a');
        $gateway->bulk([['index' => 'audit_log', 'document' => ['objectId' => 2], 'id' => 'b']]);

        self::assertSame([], $this->writesWithTheParameter());
    }

    public function testTheClusterIsAskedItsVersionOnceRatherThanPerWrite(): void
    {
        $gateway = $this->gateway('8.19.0');

        $gateway->index('audit_log', ['objectId' => 1], 'a');
        $gateway->index('audit_log', ['objectId' => 2], 'b');
        $gateway->bulk([['index' => 'audit_log', 'document' => ['objectId' => 3], 'id' => 'b']]);

        self::assertSame(1, \count(array_filter($this->seen, static fn (string $r): bool => $r === 'GET /')), 'the version was asked more than once');
    }

    public function testADeploymentCanInsistOnTheParameter(): void
    {
        // Someone who knows their cluster and does not want the extra request — or who
        // is behind a proxy that answers info() differently from the cluster itself.
        $gateway = $this->gateway('8.17.4', sourceOnError: false);

        $gateway->index('audit_log', ['objectId' => 1], 'a');

        self::assertSame(['PUT /audit_log/_doc/a'], $this->writesWithTheParameter());
        self::assertNotContains('GET /', $this->seen, 'and nothing was asked of the cluster');
    }

    public function testADeploymentCanRefuseTheParameter(): void
    {
        $gateway = $this->gateway('8.19.0', sourceOnError: true);

        $gateway->index('audit_log', ['objectId' => 1], 'a');

        self::assertSame([], $this->writesWithTheParameter());
        self::assertNotContains('GET /', $this->seen, 'and nothing was asked of the cluster');
    }

    public function testAClusterThatCannotSayItsVersionKeepsTheProtection(): void
    {
        // Fail closed, and do not remember failing: an info() that could not be answered
        // has said nothing about the cluster, and the write about to go out will fail on
        // its own terms if the cluster is really unreachable. Remembering it would turn
        // one bad moment into a process that stops protecting anything.
        $answers = 0;
        $gateway = $this->gateway('8.19.0', info: function () use (&$answers): ResponseInterface {
            return ++$answers === 1
                ? self::response(503, ['error' => ['type' => 'unavailable_shards_exception']])
                : self::response(200, ['version' => ['number' => '8.19.0']]);
        });

        $gateway->index('audit_log', ['objectId' => 1], 'a');
        $gateway->index('audit_log', ['objectId' => 2], 'a');

        self::assertSame(['PUT /audit_log/_doc/a', 'PUT /audit_log/_doc/a'], $this->writesWithTheParameter());
        self::assertSame(2, $answers, 'the unanswered question was remembered as an answer');
    }

    public function testANodeThatRefusesTheParameterGetsTheWriteAgainWithoutIt(): void
    {
        // A rolling 8.17 to 8.18: info() asked the new node and was told 8.18, and the
        // write landed on an old one. Version detection cannot see that, and a 400 is a
        // dropped record under on_failure: log — for the length of the upgrade. So the
        // refusal is read, the answer is remembered, and the write goes again.
        $refused = 0;
        $gateway = $this->gateway('8.19.0', write: function (RequestInterface $request) use (&$refused): ResponseInterface {
            if (str_contains($request->getUri()->getQuery(), 'include_source_on_error')) {
                ++$refused;

                return self::response(400, ['error' => ['type' => 'illegal_argument_exception', 'reason' => 'request [/audit_log/_doc/a] contains unrecognized parameter: [include_source_on_error]']]);
            }

            return self::response(200, ['_id' => 'a', 'result' => 'created']);
        });

        $gateway->index('audit_log', ['objectId' => 1], 'a');
        $gateway->index('audit_log', ['objectId' => 2], 'b');

        self::assertSame(1, $refused, 'the refusal was not remembered: every write tried the parameter again');
        self::assertSame(['PUT /audit_log/_doc/a', 'PUT /audit_log/_doc/b'], $this->writesWithoutTheParameter(), 'the record was lost rather than written again');
    }

    public function testARefusedDocumentIsNotMistakenForARefusedParameter(): void
    {
        // The other side of reading the wording: a document that does not fit the
        // mapping is also a 400, and retrying it without the parameter would send the
        // same document again, be refused again, and quote it back the second time with
        // nothing to stop the cluster echoing it.
        $attempts = 0;
        $gateway = $this->gateway('8.19.0', write: function () use (&$attempts): ResponseInterface {
            ++$attempts;

            return self::response(400, ['error' => ['type' => 'document_parsing_exception', 'reason' => 'failed to parse field [objectId] of type [keyword]']]);
        });

        try {
            $gateway->index('audit_log', ['objectId' => 1], 'a');
            self::fail('the document should have been refused');
        } catch (RequestRejectedException) {
        }

        self::assertSame(1, $attempts, 'a refused document was sent a second time');
    }

    public function testADeploymentThatInsistedIsNotOverruledByARefusal(): void
    {
        // It asked for the parameter on every write. Dropping it at the first 400 would
        // be the bundle answering a question the deployment had already answered — and
        // on a cluster that quotes documents back, answering it the other way.
        $attempts = 0;
        $gateway = $this->gateway('8.19.0', sourceOnError: false, write: function () use (&$attempts): ResponseInterface {
            ++$attempts;

            return self::response(400, ['error' => ['type' => 'illegal_argument_exception', 'reason' => 'request [/audit_log/_doc/a] contains unrecognized parameter: [include_source_on_error]']]);
        });

        try {
            $gateway->index('audit_log', ['objectId' => 1], 'a');
            self::fail('the write should have been refused');
        } catch (RequestRejectedException) {
        }

        self::assertSame(1, $attempts);
    }

    public function testAClusterThatRefusedIsAskedAgainLaterRatherThanNever(): void
    {
        // The one that matters in a worker. messenger:consume runs for weeks, so a
        // refusal met once during a rolling upgrade would turn the first line off until
        // somebody restarted it — and the cluster finishing its upgrade would not bring
        // it back. The answer is believed for a while, not forever.
        $refusals = 0;
        $gateway = $this->gateway('8.19.0', write: function (RequestInterface $request) use (&$refusals): ResponseInterface {
            if (str_contains($request->getUri()->getQuery(), 'include_source_on_error')) {
                ++$refusals;

                return self::response(400, ['error' => ['type' => 'illegal_argument_exception', 'reason' => 'contains unrecognized parameter: [include_source_on_error]']]);
            }

            return self::response(200, ['_id' => 'a', 'result' => 'created']);
        });

        $gateway->index('audit_log', ['objectId' => 1], 'a');
        self::assertSame(1, $refusals);

        // Still inside the window: no second refused request.
        $this->clock->move(299);
        $gateway->index('audit_log', ['objectId' => 2], 'b');
        self::assertSame(1, $refusals, 'the answer was not remembered at all, so every write pays for it');

        // Past it: asked again, which is how an upgraded cluster gets the parameter back
        // without anybody restarting the worker.
        $this->clock->move(2);
        $gateway->index('audit_log', ['objectId' => 3], 'c');
        self::assertSame(2, $refusals, 'the refusal was remembered for the life of the process');
    }

    public function testTurningTheFirstLineOffIsSaidOutLoud(): void
    {
        $gateway = $this->gateway('8.17.4');

        $gateway->index('audit_log', ['objectId' => 1], 'a');

        self::assertCount(1, $this->warnings, 'a guarantee went quiet quietly');
        self::assertStringContainsString('will not carry include_source_on_error', $this->warnings[0]);
        self::assertStringContainsString('8.17.4', $this->warnings[0], 'and it says what the cluster answered');
        self::assertStringContainsString('client.include_source_on_error', $this->warnings[0], 'and how to stop being asked');
    }

    public function testAClusterThatKnowsItIsNotAskedAgain(): void
    {
        // Only the negative expires. A cluster that takes the parameter is not going to
        // stop taking it, and re-asking would be an info() per window forever.
        $gateway = $this->gateway('8.19.0');

        $gateway->index('audit_log', ['objectId' => 1], 'a');
        $this->clock->move(3600);
        $gateway->index('audit_log', ['objectId' => 2], 'b');

        self::assertSame(1, \count(array_filter($this->seen, static fn (string $r): bool => $r === 'GET /')));
        self::assertSame([], $this->warnings);
    }

    public function testADeploymentThatDecidedIsNeitherAskedNorWarned(): void
    {
        $gateway = $this->gateway('8.17.4', sourceOnError: true);

        $gateway->index('audit_log', ['objectId' => 1], 'a');

        self::assertSame([], $this->warnings, 'warned about a decision the deployment made on purpose');
    }

    public function testAClusterThatIsNeverGoingToChangeIsNotWarnedAboutForever(): void
    {
        // Not every cluster below the line is mid-upgrade; some are staying there. At one
        // warning per window that worker writes 288 identical lines a day about a fact
        // nobody can act on differently, which reads as an outage in progress — and a log
        // like that is where the real warning next to it goes unread. The window says how
        // often to ask; this says how often it is news.
        $gateway = $this->gateway('8.5.1');

        $gateway->index('audit_log', ['objectId' => 1], 'a');

        $this->clock->move(301);
        $gateway->index('audit_log', ['objectId' => 2], 'b');

        $this->clock->move(301);
        $gateway->index('audit_log', ['objectId' => 3], 'c');

        self::assertCount(1, $this->warnings, 'the same unchanged answer was announced again');
        self::assertSame([], $this->writesWithTheParameter(), 'and it kept asking as it should');
    }

    public function testAnAnswerThatChangesIsNewsAgain(): void
    {
        // Two negatives in a row for different reasons, with nothing in between to reset
        // anything — a mixed cluster mid-upgrade, where one node refuses the parameter and
        // the node that answers the next info() is an old one. Same outcome, different
        // fact, and being quiet about the second would be the price of not repeating the
        // first.
        $version = '8.19.0';
        $gateway = $this->gateway('8.19.0', info: function () use (&$version): ResponseInterface {
            return self::response(200, ['version' => ['number' => $version]]);
        }, write: function (RequestInterface $request): ResponseInterface {
            if (str_contains($request->getUri()->getQuery(), 'include_source_on_error')) {
                return self::response(400, ['error' => ['type' => 'illegal_argument_exception', 'reason' => 'contains unrecognized parameter: [include_source_on_error]']]);
            }

            return self::response(200, ['_id' => 'a', 'result' => 'created']);
        });

        $gateway->index('audit_log', ['objectId' => 1], 'a');

        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('a node refused it', $this->warnings[0]);

        // The window passes and an old node answers this time.
        $version = '8.5.1';
        $this->clock->move(301);
        $gateway->index('audit_log', ['objectId' => 2], 'b');

        self::assertCount(2, $this->warnings, 'a different answer was folded into the first one');
        self::assertStringContainsString('8.5.1', $this->warnings[1]);
    }

    public function testComingBackIsSaidToo(): void
    {
        // The closing bracket of a rolling upgrade: the log showed the guarantee going
        // quiet, and it should show it coming back rather than leaving somebody to infer
        // it from the absence of anything.
        $version = '8.5.1';
        $gateway = $this->gateway('8.5.1', info: function () use (&$version): ResponseInterface {
            return self::response(200, ['version' => ['number' => $version]]);
        });

        $gateway->index('audit_log', ['objectId' => 1], 'a');
        self::assertCount(1, $this->warnings);

        $version = '8.19.0';
        $this->clock->move(301);
        $gateway->index('audit_log', ['objectId' => 2], 'b');

        self::assertSame(['PUT /audit_log/_doc/b'], $this->writesWithTheParameter());
        self::assertContains('Audit writes to Elasticsearch carry include_source_on_error again: the cluster reports version 8.19.0.', $this->infos);
    }

    /**
     * @return list<string>
     */
    private function writesWithTheParameter(): array
    {
        return array_values(array_map(
            static fn (string $r): string => explode('?', $r)[0],
            array_filter($this->seen, static fn (string $r): bool => str_contains($r, 'include_source_on_error=false')),
        ));
    }

    /**
     * @return list<string>
     */
    private function writesWithoutTheParameter(): array
    {
        return array_values(array_filter(
            $this->seen,
            static fn (string $r): bool => (str_contains($r, '/_doc') || str_contains($r, '/_bulk')) && !str_contains($r, 'include_source_on_error'),
        ));
    }

    private function gateway(string $version, ?bool $sourceOnError = null, ?callable $info = null, ?callable $write = null): ElasticsearchGateway
    {
        $seen = &$this->seen;
        $respond = static function (RequestInterface $request) use (&$seen, $version, $info, $write): ResponseInterface {
            $path = $request->getUri()->getPath();
            $query = $request->getUri()->getQuery();

            if ($request->getMethod() === 'HEAD') {
                return self::response(200, []);
            }

            $seen[] = $request->getMethod().' '.$path.($query === '' ? '' : '?'.$query);

            if ($path === '/') {
                return $info === null ? self::response(200, ['version' => ['number' => $version]]) : $info();
            }

            if ($write !== null) {
                return $write($request);
            }

            return self::response(200, str_contains($path, '_bulk')
                ? ['errors' => false, 'items' => [['index' => ['_id' => 'b', 'status' => 201]]]]
                : ['_id' => 'a', 'result' => 'created']);
        };

        $http = new class($respond) implements ClientInterface {
            /** @param callable(RequestInterface): ResponseInterface $respond */
            public function __construct(private $respond)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->respond)($request);
            }
        };

        $warnings = &$this->warnings;
        $infos = &$this->infos;
        $logger = new class($warnings, $infos) extends AbstractLogger {
            /**
             * @param list<string> $warnings
             * @param list<string> $infos
             */
            public function __construct(private array &$warnings, private array &$infos)
            {
            }

            /**
             * @param mixed               $level
             * @param mixed               $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $line = strtr((string) $message, [
                    '{because}' => (string) ($context['because'] ?? ''),
                    '{seconds}' => (string) ($context['seconds'] ?? ''),
                    '{version}' => (string) ($context['version'] ?? ''),
                ]);

                if ($level === LogLevel::WARNING) {
                    $this->warnings[] = $line;
                }

                if ($level === LogLevel::INFO) {
                    $this->infos[] = $line;
                }
            }
        };

        return new ElasticsearchGateway(ClientBuilder::create()->setHosts(['http://es.test:9200'])->setHttpClient($http)->build(), $sourceOnError, $this->clock, $logger);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function response(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], (string) json_encode($body));
    }
}

/**
 * A clock a test can push forward, for the one thing here that is about elapsed
 * time rather than about requests.
 */
final class MovableClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new \DateTimeImmutable('2026-09-06 12:00:00', new \DateTimeZone('UTC'));
    }

    public function move(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
