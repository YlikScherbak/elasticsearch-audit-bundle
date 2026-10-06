<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * tools/infection/gate.php, against a tree of its own: two files of source, a manifest that
 * splits them into two parts, and Infection logs written by hand.
 *
 * The gate exists because a score was green over mutants nobody ran — Infection leaves a
 * skipped mutant out of the score rather than counting it — and a gate made of parts adds
 * the same kind of hole one level up: a part that did not run, or ran on other code, or
 * stopped halfway, still leaves a sum that reads like a score. Each case below is one of
 * those holes, and the first is the whole run the others are spoiled copies of, so that a
 * refusal is seen to come from the one thing each case changed.
 */
final class TheMutationGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/es-audit-gate-' . bin2hex(random_bytes(6));
        $this->write('tools/infection/parts.json', json_encode([
            'set' => ['config' => 'infection.json5', 'threads' => 2, 'floor' => '50', 'sources' => ['src/A'], 'parts' => ['one' => ['src/A/One.php'], 'rest' => ['*']]],
        ], \JSON_THROW_ON_ERROR));
        $this->write('tools/infection/composer.lock', json_encode([
            'packages' => [['name' => 'infection/infection', 'version' => '0.35.4', 'source' => ['reference' => 'abc']]],
        ], \JSON_THROW_ON_ERROR));
        $this->write('infection.json5', "{\n    timeout: 120,\n}\n");
        $this->write('phpunit.xml.dist', '<phpunit/>');
        $this->write('src/A/One.php', '<?php // one');
        $this->write('src/A/Two.php', '<?php // two');
        $this->write('src/A/Three.php', '<?php // three, which has no mutants and is still a part\'s');
        $this->write('tests/OneTest.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAWholeRunIsSummedFromItsCounts(): void
    {
        $this->runEverything();

        [$code, $output] = $this->gate('summarise', 'set', $this->root . '/out');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('set: 4 mutants in 2 part(s), every one planned and run once, none skipped.', $output);
        self::assertStringContainsString('killed 2, escaped 1, timed out 1, errored 0', $output);
        self::assertStringContainsString('covered MSI 50.00% counting only what a test failed on -- the one the floor holds', $output);
        self::assertStringContainsString('covered MSI 75.00% with timeouts and errors counted as detected', $output);
        self::assertStringContainsString('floor 50% of the covered, counting only what a test failed on', $output);
    }

    public function testAScoreExactlyAtTheFloorReachesIt(): void
    {
        $this->floor('75');
        $this->runEverything(mutants: ['rest' => ['killed' => [['src/A/Two.php', 'Plus', 3], ['src/A/Two.php', 'Minus', 4]]]]);

        [$code, $output] = $this->gate('summarise', 'set', $this->root . '/out');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('covered MSI 75.00% counting only what a test failed on', $output);
    }

    /** @param array<string, mixed>|null $base */
    public function floor(mixed $floor, ?array $base = null): void
    {
        $this->write('tools/infection/parts.json', json_encode([
            'set' => ['config' => 'infection.json5', 'threads' => 2, 'floor' => $floor, 'sources' => ['src/A'], 'parts' => ['one' => ['src/A/One.php'], 'rest' => ['*']]] + ($base === null ? [] : ['base' => $base]),
        ], \JSON_THROW_ON_ERROR));
    }

    /** The plan of runEverything(), as gate.php's planHash() identifies it: its four mutants, once each. */
    private static function plan(): string
    {
        $plan = [];

        foreach ([['src/A/One.php', 'Plus', 1], ['src/A/One.php', 'Minus', 2], ['src/A/Two.php', 'Plus', 3], ['src/A/Two.php', 'Minus', 4]] as [$file, $mutator, $line]) {
            $plan[implode(' | ', [$file, $mutator, $line, sha1(sprintf('--- line %d, %s', $line, $mutator))])] = 1;
        }

        ksort($plan);

        return hash('sha256', json_encode($plan, \JSON_THROW_ON_ERROR));
    }

    public function testEveryMutantsStatusIsWrittenByNameWithWhatAnErroredOneLeft(): void
    {
        $this->runEverything(mutants: ['rest' => ['killed' => [['src/A/Two.php', 'Plus', 3]], 'errored' => [['src/A/Two.php', 'Minus', 4]]]]);

        [$code, $output] = $this->gate('summarise', 'set', $this->root . '/out');
        $written = json_decode((string) file_get_contents($this->root . '/out/set-statuses.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(0, $code, $output);
        // By key: One's Minus and Plus, then Two's.
        self::assertSame(['escaped', 'killed', 'errored', 'killed'], array_merge(...array_values($written['statuses'])));
        self::assertSame(['src/A/Two.php | Minus | 4'], array_map(static fn (string $key): string => substr($key, 0, strrpos($key, ' | ')), array_keys($written['why'])));
        self::assertSame(['the run of line 4, Minus'], array_merge(...array_values($written['why'])));
    }

    public function testTwoRunsAreComparedMutantByMutant(): void
    {
        $this->runEverything();
        $this->gate('summarise', 'set', $this->root . '/out');
        rename($this->root . '/out/set-statuses.json', $this->root . '/first.json');
        $this->runEverything(mutants: ['rest' => ['killed' => [['src/A/Two.php', 'Plus', 3]], 'errored' => [['src/A/Two.php', 'Minus', 4]]]]);
        $this->gate('summarise', 'set', $this->root . '/out');

        [$code, $output] = $this->gate('transitions', $this->root . '/first.json', $this->root . '/out/set-statuses.json');

        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('~src/A/Two\.php \| Minus \| 4 \| \w+: timeouted -> errored\n    the run of line 4, Minus\n1 mutant\(s\) changed status; 0 only in the first run, 0 only in the second\.~', $output);
    }

    /**
     * @return iterable<string, array{\Closure(self): void, string}>
     */
    public static function spoiledRuns(): iterable
    {
        yield 'a part that did not run' => [
            static fn (self $test) => $test->runEverything(skip: ['rest']),
            'set has no record of rest',
        ];

        yield 'two parts that did not run, named together' => [
            static fn (self $test) => $test->runEverything(skip: ['one', 'rest']),
            'set has no record of one, rest',
        ];

        yield 'a part whose run did not finish' => [
            static fn (self $test) => $test->runEverything(exitCodes: ['rest' => 1]),
            'set.rest: Infection exited with 1',
        ];

        yield 'a log cut short' => [
            static fn (self $test) => $test->runEverything(logs: ['rest' => static fn (string $log): string => substr($log, 0, 40)]),
            'set.rest: the log is not whole',
        ];

        yield 'no log at all' => [
            static fn (self $test) => $test->runEverything(logs: ['rest' => static fn (): ?string => null]),
            'set.rest: Infection wrote no log',
        ];

        yield 'a mutant run twice' => [
            static fn (self $test) => $test->runEverything(mutants: ['rest' => ['killed' => [['src/A/Two.php', 'Plus', 3], ['src/A/Two.php', 'Plus', 3]], 'timeouted' => [['src/A/Two.php', 'Minus', 4]]]]),
            "The parts' mutants are not the plan's: 0 planned and not run, 1 run and not planned (or run twice)",
        ];

        yield 'a planned mutant no part ran' => [
            static fn (self $test) => $test->runEverything(mutants: ['rest' => ['killed' => [['src/A/Two.php', 'Plus', 3]]]]),
            "The parts' mutants are not the plan's: 1 planned and not run, 0 run and not planned",
        ];

        yield 'a mutant skipped for its time' => [
            static fn (self $test) => $test->runEverything(skipped: ['rest' => 3]),
            'set.rest: 3 mutants were skipped',
        ];

        yield 'the plan with mutants skipped, so that it lists fewer than there are' => [
            static fn (self $test) => $test->runEverything(skipped: ['plan' => 1]),
            'set.plan: 1 mutants were skipped',
        ];

        yield 'a status the gate does not know' => [
            static fn (self $test) => $test->runEverything(logs: ['one' => static fn (string $log): string => json_encode(['quarantined' => []] + json_decode($log, true), \JSON_THROW_ON_ERROR)]),
            'set.one: the log has statuses this gate does not know: quarantined',
        ];

        yield 'a log whose lists and count disagree' => [
            static fn (self $test) => $test->runEverything(logs: ['one' => static function (string $log): string {
                $data = json_decode($log, true);
                ++$data['stats']['totalMutantsCount'];

                return json_encode($data, \JSON_THROW_ON_ERROR);
            }]),
            'set.one: the log lists 2 mutants and says it made 3',
        ];

        yield 'a mutant of a file that is not the part\'s' => [
            static fn (self $test) => $test->runEverything(mutants: ['one' => ['killed' => [['src/A/One.php', 'Plus', 1], ['src/A/Two.php', 'Plus', 3]], 'escaped' => []]]),
            "set.one: a mutant of /app/src/A/Two.php, which is not this part's",
        ];

        yield 'a part run on another tree' => [
            static function (self $test): void {
                $test->runEverything();
                $test->write('src/A/One.php', '<?php // one, changed after the parts ran');
            },
            'set.one ran on another fingerprint than this tree has',
        ];

        yield 'a tree changed while a part ran' => [
            static fn (self $test) => $test->runEverything(before: ['one' => 'what the tree was when the part began']),
            'set.one ran on another fingerprintBefore than this tree has',
        ];

        yield 'a part run under another configuration' => [
            static function (self $test): void {
                $test->runEverything();
                $test->write('infection.json5', "{\n    timeout: 10,\n}\n");
            },
            'ran on another fingerprint than this tree has',
        ];

        yield 'a part run on more threads than the manifest gives the set' => [
            static fn (self $test) => $test->runEverything(machines: ['rest' => [6, 12]]),
            'set.rest ran on 6 threads, and the manifest says 2',
        ];

        yield 'a part whose threads took every CPU' => [
            static fn (self $test) => $test->runEverything(machines: ['one' => [2, 2]]),
            'set.one ran 2 threads on 2 CPUs',
        ];

        yield 'a score one mutation below the floor' => [
            static function (self $test): void {
                $test->floor('75');
                $test->runEverything(mutants: ['rest' => ['killed' => [['src/A/Two.php', 'Plus', 3]], 'escaped' => [['src/A/Two.php', 'Minus', 4]]]]);
            },
            "set is below its floor: 1 more of the 4 would have to be killed by a test. The cause is not established: compare the mutants' statuses by name",
        ];

        // Infection's own count, 75%, would reach the floor; the gate's does not -- and says no
        // more of why than it knows.
        yield 'a timeout, which is not counted as killed' => [
            static function (self $test): void {
                $test->floor('75');
                $test->runEverything();
            },
            "set is below its floor: 1 more of the 4 would have to be killed by a test. The cause is not established: compare the mutants' statuses by name",
        ];

        // Against the counts of the run the floor was measured on, when of one plan.
        yield 'a run short against its base' => [
            static function (self $test): void {
                $test->floor('75', ['run' => '42', 'plan' => self::plan(), 'counts' => ['killed' => 3, 'escaped' => 1, 'timeouted' => 0, 'errored' => 0]]);
                $test->runEverything();
            },
            'Against the base (run 42, one plan): killed -1, escaped +0, timed out +1, errored +0. The cause is not established',
        ];

        yield 'a run of another plan than its base' => [
            static function (self $test): void {
                $test->floor('75', ['run' => '42', 'plan' => 'another', 'counts' => ['killed' => 3, 'escaped' => 1, 'timeouted' => 0, 'errored' => 0]]);
                $test->runEverything();
            },
            'The plan is not the one of the base (run 42): its counts are of other mutants, and are not compared.',
        ];

        yield 'a base without its counts' => [
            static function (self $test): void {
                $test->runEverything();
                $test->floor('50', ['run' => '42', 'plan' => self::plan(), 'counts' => ['killed' => 3]]);
            },
            'parts.json gives set "set" a base without its run, its plan, or the counts',
        ];

        // Two of three: shown as 66.67%, and short of a floor of 66.67 all the same.
        yield 'a score shown as the floor and short of it' => [
            static function (self $test): void {
                $test->floor('66.67');
                $test->runEverything(mutants: ['rest' => ['killed' => [['src/A/Two.php', 'Plus', 3]], 'uncovered' => [['src/A/Two.php', 'Minus', 4]]]]);
            },
            'set is below its floor: 1 more of the 3',
        ];

        yield 'a floor that is not a decimal in a string' => [
            static function (self $test): void {
                $test->runEverything();
                $test->floor(75);
            },
            'parts.json gives set "set" no floor',
        ];

        yield 'a floor above a hundred' => [
            static function (self $test): void {
                $test->runEverything();
                $test->floor('100.01');
            },
            'parts.json gives set "set" no floor',
        ];
    }

    #[DataProvider('spoiledRuns')]
    public function testASpoiledRunIsRefusedByName(\Closure $spoil, string $refusal): void
    {
        $spoil($this);

        [$code, $output] = $this->gate('summarise', 'set', $this->root . '/out');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString($refusal, $output);
    }

    public function testAFileTwoPartsNameIsRefusedBeforeAnythingRuns(): void
    {
        $this->write('tools/infection/parts.json', json_encode([
            'set' => ['config' => 'infection.json5', 'threads' => 2, 'floor' => '50', 'sources' => ['src/A'], 'parts' => ['one' => ['src/A/One.php'], 'again' => ['src/A/One.php'], 'rest' => ['*']]],
        ], \JSON_THROW_ON_ERROR));

        [$code, $output] = $this->gate('files', 'set', 'one');

        self::assertSame(1, $code);
        self::assertStringContainsString('src/A/One.php is named by both "one" and "again"', $output);
    }

    public function testAPartNamesOnlyFilesOfTheSet(): void
    {
        $this->write('tools/infection/parts.json', json_encode([
            'set' => ['config' => 'infection.json5', 'threads' => 2, 'floor' => '50', 'sources' => ['src/A'], 'parts' => ['one' => ['src/B/Gone.php'], 'rest' => ['*']]],
        ], \JSON_THROW_ON_ERROR));

        [$code, $output] = $this->gate('files', 'set', 'rest');

        self::assertSame(1, $code);
        self::assertStringContainsString('Part "one" names src/B/Gone.php, which is not a PHP file of the set', $output);
    }

    public function testASetGivesItsThreads(): void
    {
        $this->write('tools/infection/parts.json', json_encode([
            'set' => ['config' => 'infection.json5', 'sources' => ['src/A'], 'parts' => ['rest' => ['*']]],
        ], \JSON_THROW_ON_ERROR));

        [$code, $output] = $this->gate('files', 'set', 'rest');

        self::assertSame(1, $code);
        self::assertStringContainsString('parts.json gives set "set" no number of threads', $output);
    }

    public function testAPartAsksWhetherItsPlanIsOfThisTree(): void
    {
        $this->runEverything();
        $plan = $this->root . '/out/set.plan.json';

        self::assertSame([0, ''], $this->gate('agrees', 'set', $plan));

        [$code, $output] = $this->gate('agrees', 'set', $this->root . '/out/set.one.json');
        self::assertSame(1, $code);
        self::assertStringContainsString('is not the plan of set "set"', $output);

        [$code, $output] = $this->gate('agrees', 'set', $this->root . '/out/missing.json');
        self::assertSame(1, $code);
        self::assertStringContainsString('there is no plan to run against', $output);

        $this->write('tests/OneTest.php', '<?php // changed after the plan was made');
        [$code, $output] = $this->gate('agrees', 'set', $plan);
        self::assertSame(1, $code);
        self::assertStringContainsString('The plan was made on another fingerprint than this tree has', $output);
    }

    public function testTheMatrixIsTheManifestsParts(): void
    {
        self::assertSame([0, "[\"one\",\"rest\"]\n"], $this->gate('matrix', 'set'));
    }

    public function testTheRestIsEveryFileNoOtherPartNames(): void
    {
        self::assertSame([0, "src/A/Three.php,src/A/Two.php\n"], $this->gate('files', 'set', 'rest'));
    }

    /**
     * The plan and both parts, each recorded the way gate.sh records it. Each argument
     * spoils one part (or the plan) and leaves the rest of the run as it was.
     *
     * @param list<string>                                    $skip      parts (or the plan) left unrecorded
     * @param array<string, int>                              $exitCodes
     * @param array<string, \Closure(string): ?string>        $logs
     * @param array<string, array<string, list<array{string, string, int}>>> $mutants
     * @param array<string, int>                              $skipped
     * @param array<string, string>                           $before
     * @param array<string, array{int, int}>                  $machines threads and CPUs
     */
    public function runEverything(
        array $skip = [],
        array $exitCodes = [],
        array $logs = [],
        array $mutants = [],
        array $skipped = [],
        array $before = [],
        array $machines = [],
    ): void {
        $mutants += [
            'one' => ['killed' => [['src/A/One.php', 'Plus', 1]], 'escaped' => [['src/A/One.php', 'Minus', 2]]],
            'rest' => ['killed' => [['src/A/Two.php', 'Plus', 3]], 'timeouted' => [['src/A/Two.php', 'Minus', 4]]],
        ];
        $mutants['plan'] = ['escaped' => [['src/A/One.php', 'Plus', 1], ['src/A/One.php', 'Minus', 2], ['src/A/Two.php', 'Plus', 3], ['src/A/Two.php', 'Minus', 4]]];

        [, $fingerprint] = $this->gate('fingerprint', 'set');

        foreach (['plan', 'one', 'rest'] as $part) {
            if (\in_array($part, $skip, true)) {
                continue;
            }

            $log = $this->root . '/var/' . $part . '.json';
            $text = ($logs[$part] ?? static fn (string $log): string => $log)($this->logOf($mutants[$part], $skipped[$part] ?? 0));

            if ($text !== null) {
                $this->write('var/' . $part . '.json', $text);
            }

            [$threads, $cpus] = $machines[$part] ?? [2, 4];
            [$code, $output] = $this->gate('record', 'set', $part, $log, (string) ($exitCodes[$part] ?? 0), $before[$part] ?? trim($fingerprint), (string) $threads, (string) $cpus, $this->root . '/out');
            self::assertSame(0, $code, $output);
        }
    }

    /** @param array<string, list<array{string, string, int}>> $byStatus */
    private function logOf(array $byStatus, int $skipped): string
    {
        $log = ['stats' => ['totalMutantsCount' => $skipped, 'skippedCount' => $skipped]];

        foreach (['killed', 'killedByStaticAnalysis', 'escaped', 'timeouted', 'errored', 'syntaxErrors', 'uncovered', 'ignored'] as $status) {
            $log[$status] = array_map(static fn (array $mutant): array => [
                'mutator' => ['mutatorName' => $mutant[1], 'originalFilePath' => '/app/' . $mutant[0], 'originalStartLine' => $mutant[2]],
                'diff' => sprintf('--- line %d, %s', $mutant[2], $mutant[1]),
                'processOutput' => sprintf('the run of line %d, %s', $mutant[2], $mutant[1]),
            ], $byStatus[$status] ?? []);
            $log['stats']['totalMutantsCount'] += \count($log[$status]);
        }

        return json_encode($log, \JSON_THROW_ON_ERROR);
    }

    /** @return array{int, string} the exit code, and what it wrote to either stream */
    private function gate(string ...$arguments): array
    {
        $command = array_merge([\PHP_BINARY, \dirname(__DIR__, 2) . '/tools/infection/gate.php', '--root=' . $this->root], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    public function write(string $path, string $content): void
    {
        if (!is_dir(\dirname($this->root . '/' . $path))) {
            mkdir(\dirname($this->root . '/' . $path), 0777, true);
        }

        file_put_contents($this->root . '/' . $path, $content);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }

            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
}
