<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * tools/infection/gate.php's exclusions, against a tree of its own: one class, a plan made
 * without the exclusions, and tools/infection/exclusions.json written by hand.
 *
 * An exclusion is a mutation taken out of the score because it is argued equivalent. In the
 * set's configuration it would be a line number, which drifts in silence the first time the file
 * changes and then excludes whatever stands there. So each exclusion carries what it is about --
 * the line's text, its method, the mutation's diff -- and the gate refuses the run when any of
 * it no longer agrees, when the rule would take a second mutation nobody argued for, and when the
 * plan the parts ran is not the plan without exclusions less exactly those.
 */
final class TheMutationGatesExclusionsTest extends TestCase
{
    private const SOURCE = <<<'PHP'
        <?php
        namespace App;
        final class One
        {
            public function a(bool $x): bool
            {
                return $x && true;
            }

            public function b(): int
            {
                return 1 + 2;
            }
        }
        PHP;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/es-audit-exclusions-' . bin2hex(random_bytes(6));
        $this->write('tools/infection/parts.json', json_encode([
            'set' => ['config' => 'infection.json5', 'threads' => 2, 'floor' => '50', 'sources' => ['src/A'], 'parts' => ['all' => ['*']]],
        ], \JSON_THROW_ON_ERROR));
        $this->write('tools/infection/composer.lock', json_encode([
            'packages' => [['name' => 'infection/infection', 'version' => '0.35.4', 'source' => ['reference' => 'abc']]],
        ], \JSON_THROW_ON_ERROR));
        $this->write('infection.json5', "{\n    timeout: 120,\n}\n");
        $this->write('phpunit.xml.dist', '<phpunit/>');
        $this->write('src/A/One.php', self::SOURCE);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAnExclusionThatAgreesIsTakenOutOfTheCount(): void
    {
        $this->exclude([self::exclusion()]);

        [$code, $output] = $this->excluded(self::whole());
        self::assertSame(0, $code, $output);

        $this->recordThePlanAndThePart(planned: [self::minusMutation(), self::plusMutation()], ran: ['killed' => [self::minusMutation(), self::plusMutation()]]);
        [$code, $output] = $this->gate('summarise', 'set', $this->root . '/out');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('set: 2 mutants in 1 part(s)', $output);
        self::assertStringContainsString('excluded before the count: 1 of 3 planned, each argued for in tools/infection/exclusions.json (PHP 1)', $output);
        self::assertStringContainsString('covered MSI 100.00%', $output);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function spoiledExclusions(): iterable
    {
        yield 'a line whose text changed' => [
            static fn (array $e): array => ['text' => 'return $x and true;'] + $e,
            'the line is not the one argued about any more — it reads "return $x && true;", the record "return $x and true;"',
        ];
        yield 'a line moved into another method' => [
            static fn (array $e): array => ['line' => 12, 'text' => 'return 1 + 2;'] + $e,
            'the line is in App\One::b now',
        ];
        yield 'a diff that is not the one argued about' => [
            static fn (array $e): array => ['diff' => ['- return $x && true;', '+ return $x && false;']] + $e,
            'the plan without exclusions has no such mutation there.',
        ];
        yield 'a ground that is the bundle\'s own' => [
            static fn (array $e): array => ['basis' => 'callers'] + $e,
            'rests on "callers"; only PHP\'s semantics and Doctrine\'s or DBAL\'s contract are grounds to exclude.',
        ];
        yield 'no reason' => [
            static fn (array $e): array => ['reason' => ''] + $e,
            'Exclusion 0 of set has no reason.',
        ];
        yield 'a file of no set' => [
            static fn (array $e): array => ['file' => 'src/B/One.php'] + $e,
            'names a file that is not of the set.',
        ];
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $spoil
     */
    #[DataProvider('spoiledExclusions')]
    public function testAnExclusionThatDoesNotAgreeIsRefusedByName(\Closure $spoil, string $refusal): void
    {
        $this->exclude([$spoil(self::exclusion())]);

        [$code, $output] = $this->excluded(self::whole());

        self::assertSame(1, $code, $output);
        self::assertStringContainsString($refusal, $output);
    }

    public function testARuleThatWouldTakeAMutationNobodyArguedForIsRefused(): void
    {
        // Two mutations of one mutator at the line: Infection's rule takes both.
        $this->exclude([self::exclusion()]);

        [$code, $output] = $this->excluded([...self::whole(), self::secondOrMutation()]);

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('The exclusion at src/A/One.php | LogicalAnd | 7 would take 2 mutations, and 1 of them are argued for.', $output);
    }

    public function testEachMutationARuleTakesArguedForIsTakenOutOfTheCount(): void
    {
        $this->exclude([self::exclusion(), ['diff' => ['- return $x && true;', '+ return !$x && true;']] + self::exclusion()]);

        [$code, $output] = $this->excluded([...self::whole(), self::secondOrMutation()]);

        self::assertSame(0, $code, $output);
    }

    public function testOneRecordForTwoMutationsOfTheSameDiffIsNotEnough(): void
    {
        $this->exclude([self::exclusion(), self::exclusion()]);

        [$code, $output] = $this->excluded(self::whole());

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('the plan without exclusions has no such mutation there left — another record claims it.', $output);
    }

    public function testExclusionsNotHeldAgainstThePlanAreRefused(): void
    {
        $this->exclude([self::exclusion()]);
        $this->recordThePlanAndThePart(planned: [self::minusMutation(), self::plusMutation()], ran: ['killed' => [self::minusMutation(), self::plusMutation()]]);

        [$code, $output] = $this->gate('summarise', 'set', $this->root . '/out');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('set excludes 1 mutations and there is no record of them held against the plan made without them.', $output);
    }

    public function testAPlanThatLeftOutMoreThanTheExclusionsIsRefused(): void
    {
        $this->exclude([self::exclusion()]);
        [$code, $output] = $this->excluded(self::whole());
        self::assertSame(0, $code, $output);

        // The plan the parts ran has lost a mutation no exclusion argues for.
        $this->recordThePlanAndThePart(planned: [self::minusMutation()], ran: ['killed' => [self::minusMutation()]]);
        [$code, $output] = $this->gate('summarise', 'set', $this->root . '/out');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('set: the plan is not the plan without exclusions less exactly the excluded mutations.', $output);
    }

    public function testExclusionsHeldAgainstAnotherTreeAreRefused(): void
    {
        $this->exclude([self::exclusion()]);
        [$code, $output] = $this->excluded(self::whole());
        self::assertSame(0, $code, $output);

        $this->write('src/A/Two.php', '<?php // a file the exclusions were not held against');
        $this->recordThePlanAndThePart(planned: [self::minusMutation(), self::plusMutation()], ran: ['killed' => [self::minusMutation(), self::plusMutation()]]);
        [$code, $output] = $this->gate('summarise', 'set', $this->root . '/out');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('set: the exclusions were held against another fingerprint than this tree has.', $output);
    }

    public function testExclusionsChangedAfterTheRunAreRefused(): void
    {
        // What a run left out is part of what it ran on: a reason rewritten after the run is
        // another tree's exclusions.
        $this->exclude([self::exclusion()]);
        [$code, $output] = $this->excluded(self::whole());
        self::assertSame(0, $code, $output);
        $this->recordThePlanAndThePart(planned: [self::minusMutation(), self::plusMutation()], ran: ['killed' => [self::minusMutation(), self::plusMutation()]]);

        $this->exclude([['reason' => 'written after the run'] + self::exclusion()]);
        [$code, $output] = $this->gate('summarise', 'set', $this->root . '/out');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('ran on another fingerprint than this tree has', $output);
    }

    public function testAMethodIsNamedByItsClassAndNamespace(): void
    {
        // The rule's name for the line, which Infection matches against: written into the record
        // as the method, and refused when it is any other spelling.
        $this->exclude([['method' => 'One::a'] + self::exclusion()]);

        [$code, $output] = $this->excluded(self::whole());

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('the line is in App\One::a now', $output);
    }

    /** @return array<string, mixed> */
    private static function exclusion(): array
    {
        return [
            'file' => 'src/A/One.php',
            'method' => 'App\One::a',
            'mutator' => 'LogicalAnd',
            'line' => 7,
            'text' => 'return $x && true;',
            'diff' => ['- return $x && true;', '+ return $x || true;'],
            'basis' => 'PHP',
            'reason' => 'true on the right of either decides nothing',
        ];
    }

    /** @return list<array{string, string, int, string, string}> status, mutator, line, from, to */
    private static function whole(): array
    {
        return [self::orMutation(), self::minusMutation(), self::plusMutation()];
    }

    /** @return array{string, string, int, string, string} */
    private static function plusMutation(): array
    {
        return ['escaped', 'Plus', 12, 'return 1 + 2;', 'return 1 - 2;'];
    }

    /** @return array{string, string, int, string, string} */
    private static function orMutation(): array
    {
        return ['escaped', 'LogicalAnd', 7, 'return $x && true;', 'return $x || true;'];
    }

    /** @return array{string, string, int, string, string} */
    private static function secondOrMutation(): array
    {
        return ['escaped', 'LogicalAnd', 7, 'return $x && true;', 'return !$x && true;'];
    }

    /** @return array{string, string, int, string, string} */
    private static function minusMutation(): array
    {
        return ['escaped', 'Minus', 12, 'return 1 + 2;', 'return 1 * 2;'];
    }

    /** @param list<array<string, mixed>> $exclusions */
    private function exclude(array $exclusions): void
    {
        $this->write('tools/infection/exclusions.json', json_encode(['set' => $exclusions], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array{string, string, int, string, string}> $mutants
     *
     * @return array{int, string}
     */
    private function excluded(array $mutants): array
    {
        $this->write('var/whole.json', $this->logOf($mutants));

        return $this->gate('excluded', 'set', $this->root . '/var/whole.json', $this->root . '/out');
    }

    /**
     * @param list<array{string, string, int, string, string}>                $planned
     * @param array<string, list<array{string, string, int, string, string}>> $ran     by status
     */
    private function recordThePlanAndThePart(array $planned, array $ran): void
    {
        [, $fingerprint] = $this->gate('fingerprint', 'set');
        $this->write('var/plan.json', $this->logOf($planned));
        $part = [];

        foreach ($ran as $status => $mutants) {
            foreach ($mutants as $mutant) {
                $part[] = [$status, ...\array_slice($mutant, 1)];
            }
        }

        $this->write('var/all.json', $this->logOf($part));

        foreach (['plan' => 'var/plan.json', 'all' => 'var/all.json'] as $name => $log) {
            [$code, $output] = $this->gate('record', 'set', $name, $this->root . '/' . $log, '0', trim($fingerprint), '2', '4', $this->root . '/out');
            self::assertSame(0, $code, $output);
        }
    }

    /** @param list<array{string, string, int, string, string}> $mutants */
    private function logOf(array $mutants): string
    {
        $log = ['stats' => ['totalMutantsCount' => \count($mutants), 'skippedCount' => 0]];

        foreach (['killed', 'killedByStaticAnalysis', 'escaped', 'timeouted', 'errored', 'syntaxErrors', 'uncovered', 'ignored'] as $status) {
            $log[$status] = [];
        }

        foreach ($mutants as [$status, $mutator, $line, $from, $to]) {
            $log[$status][] = [
                'mutator' => ['mutatorName' => $mutator, 'originalFilePath' => '/app/src/A/One.php', 'originalStartLine' => $line],
                'diff' => "--- Original\n+++ New\n@@ @@\n     {\n-        $from\n+        $to\n     }\n",
                'processOutput' => '',
            ];
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

    private function write(string $path, string $content): void
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
