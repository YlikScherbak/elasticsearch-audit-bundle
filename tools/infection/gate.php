<?php

declare(strict_types=1);

/**
 * The mutation gate, made of parts that are checked against a plan rather than trusted.
 *
 * Infection's own score cannot carry the gate by itself, for two reasons this file exists
 * to close. A mutant whose covering tests add up to more than the timeout is not run at
 * all — "required more time than configured" — and it is left out of the score rather than
 * counted against it: on 1.3 that was 494 of 2,663 mutants in one configuration and 1,176
 * of 3,842 in the other, under a gate that was green. And once a configuration is run in
 * parts, a part that did not run, ran on other code, or was cut short leaves a sum that
 * still looks like a score.
 *
 * So a run is a plan and parts. The plan is Infection's --dry-run over the whole
 * configuration: every mutant it would make, with no test run against any of them. Each
 * part is a real run over the files the manifest (parts.json) gives it, recorded with what
 * it ran on. summarise then refuses unless the parts' mutants are exactly the plan's, one
 * for one; nothing was skipped; every part finished; and every record is of the same tree,
 * the same Infection and the same configuration. Only then is the score computed — from
 * the summed counts, never from the parts' percentages — and held to the configuration's
 * minCoveredMsi. The parts themselves run with no floor, so that a part with many escaped
 * mutants still finishes and is counted rather than lost.
 *
 *   php tools/infection/gate.php parts <set>
 *   php tools/infection/gate.php files <set> <part>
 *   php tools/infection/gate.php fingerprint <set>
 *   php tools/infection/gate.php record <set> <part|plan> <log.json> <exit-code> <fingerprint-before> <out-dir>
 *   php tools/infection/gate.php summarise <set> <dir>
 *
 * --root=<dir> points it at another tree than this one, which is how its tests run it.
 */

const STATUSES = ['killed', 'killedByStaticAnalysis', 'escaped', 'timeouted', 'errored', 'syntaxErrors', 'uncovered', 'ignored'];

final class GateRefused extends RuntimeException
{
}

/** @return never */
function refuse(string $message): void
{
    throw new GateRefused($message);
}

/** @return array{config: string, sources: list<string>, parts: array<string, list<string>>} */
function setOf(string $root, string $set): array
{
    $manifest = json_decode((string) file_get_contents($root . '/tools/infection/parts.json'), true, 512, \JSON_THROW_ON_ERROR);

    if (!isset($manifest[$set])) {
        refuse(sprintf('parts.json has no set "%s".', $set));
    }

    return $manifest[$set];
}

/** @return list<string> every PHP file under the set's sources, relative to the root */
function sourceFiles(string $root, array $sources): array
{
    $files = [];

    foreach ($sources as $source) {
        if (!is_dir($root . '/' . $source)) {
            refuse(sprintf('The source "%s" is not a directory.', $source));
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $source, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $source . '/' . str_replace('\\', '/', substr($file->getPathname(), \strlen($root . '/' . $source) + 1));
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * The files of each part. A part names files, or "*" for every file of the set that no
 * other part names; a file named twice, or outside the set, is refused — a part is a
 * share of the set, and the shares are what makes "every mutant once" checkable.
 *
 * @return array<string, list<string>>
 */
function partsOf(string $root, array $set): array
{
    $all = sourceFiles($root, $set['sources']);
    $named = [];
    $parts = [];

    foreach ($set['parts'] as $part => $files) {
        foreach ($files as $file) {
            if ($file === '*') {
                continue;
            }

            if (!\in_array($file, $all, true)) {
                refuse(sprintf('Part "%s" names %s, which is not a PHP file of the set.', $part, $file));
            }

            if (isset($named[$file])) {
                refuse(sprintf('%s is named by both "%s" and "%s".', $file, $named[$file], $part));
            }

            $named[$file] = $part;
            $parts[$part][] = $file;
        }
    }

    foreach ($set['parts'] as $part => $files) {
        $parts[$part] ??= [];

        if (\in_array('*', $files, true)) {
            $parts[$part] = array_values(array_merge($parts[$part], array_diff($all, array_keys($named))));
        }

        sort($parts[$part]);
    }

    return $parts;
}

/**
 * What a run ran on: the code it mutated, the tests that judged it, and what decided how.
 * Hashed by content, so that a working tree and a checkout of the same commit agree.
 */
function fingerprint(string $root, array $set): string
{
    $paths = [];

    foreach (['src', 'tests', 'examples'] as $directory) {
        if (is_dir($root . '/' . $directory)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $paths[] = $directory . '/' . str_replace('\\', '/', substr($file->getPathname(), \strlen($root . '/' . $directory) + 1));
            }
        }
    }

    foreach (['phpunit.xml.dist', $set['config'], 'tools/infection/parts.json'] as $file) {
        if (!is_file($root . '/' . $file)) {
            refuse(sprintf('%s is missing.', $file));
        }

        $paths[] = $file;
    }

    sort($paths);
    $lines = array_map(static fn (string $path): string => $path . "\0" . hash_file('sha256', $root . '/' . $path), $paths);

    return hash('sha256', implode("\n", $lines));
}

function infectionVersion(string $root): string
{
    $lock = json_decode((string) file_get_contents($root . '/tools/infection/composer.lock'), true, 512, \JSON_THROW_ON_ERROR);

    foreach ($lock['packages'] as $package) {
        if ($package['name'] === 'infection/infection') {
            return $package['version'] . '@' . $package['source']['reference'];
        }
    }

    refuse('tools/infection/composer.lock does not lock infection/infection.');
}

/**
 * One file per part (or the plan): what the run ran on, and its log read down to the
 * mutants and their statuses — Infection's log carries the whole source file with every
 * mutant, hundreds of megabytes for the listener. A log that cannot be read is recorded
 * as that, so that summarise refuses it by name rather than the record going missing.
 */
function record(string $root, string $setName, string $part, string $log, string $exitCode, string $before, string $out): void
{
    $set = setOf($root, $setName);
    $parts = partsOf($root, $set);

    if ($part !== 'plan' && !isset($parts[$part])) {
        refuse(sprintf('Set "%s" has no part "%s".', $setName, $part));
    }

    if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
        refuse(sprintf('Cannot create %s.', $out));
    }

    $files = $part === 'plan' ? sourceFiles($root, $set['sources']) : $parts[$part];
    $record = [
        'set' => $setName,
        'part' => $part,
        'files' => $files,
        'exitCode' => (int) $exitCode,
        'fingerprintBefore' => $before,
        'fingerprint' => fingerprint($root, $set),
        'infection' => infectionVersion($root),
        'config' => hash_file('sha256', $root . '/' . $set['config']),
    ];

    try {
        $record += readLog(sprintf('%s.%s', $setName, $part), $log, $files);
    } catch (GateRefused $e) {
        $record['refusal'] = $e->getMessage();
    }

    file_put_contents($out . '/' . $setName . '.' . $part . '.json', json_encode($record, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
}

/**
 * The mutants of one log, as a multiset of what identifies a mutant across two runs of
 * the same code: its file, its mutator, its line and its diff. Infection's own id is not
 * in the JSON log; these four are, and two mutants that agree on all four would be the
 * same change to the same line.
 *
 * @param list<string> $files
 *
 * @return array{counts: array<string, int>, mutants: array<string, int>}
 */
function readLog(string $where, string $path, array $files): array
{
    if (!is_file($path)) {
        refuse(sprintf('%s: Infection wrote no log.', $where));
    }

    try {
        $log = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        refuse(sprintf('%s: the log is not whole (%s).', $where, $e->getMessage()));
    }

    $unknown = array_diff(array_keys($log), [...STATUSES, 'stats']);

    if ($unknown !== []) {
        refuse(sprintf('%s: the log has statuses this gate does not know: %s.', $where, implode(', ', $unknown)));
    }

    $counts = ['skipped' => (int) ($log['stats']['skippedCount'] ?? -1)];
    $mutants = [];

    foreach (STATUSES as $status) {
        if (!isset($log[$status]) || !\is_array($log[$status])) {
            refuse(sprintf('%s: the log has no "%s".', $where, $status));
        }

        $counts[$status] = \count($log[$status]);

        foreach ($log[$status] as $mutant) {
            $file = fileOf($mutant['mutator']['originalFilePath'], $files);

            if ($file === null) {
                refuse(sprintf('%s: a mutant of %s, which is not this part\'s.', $where, $mutant['mutator']['originalFilePath']));
            }

            $key = implode(' | ', [$file, $mutant['mutator']['mutatorName'], $mutant['mutator']['originalStartLine'], sha1($mutant['diff'])]);
            $mutants[$key] = ($mutants[$key] ?? 0) + 1;
        }
    }

    if ($counts['skipped'] !== 0) {
        refuse(sprintf(
            '%s: %d mutants were skipped — their covering tests take longer than the timeout, and Infection leaves them out of the score instead of running them. Raise the timeout.',
            $where,
            $counts['skipped'],
        ));
    }

    if (array_sum($counts) !== (int) ($log['stats']['totalMutantsCount'] ?? -1)) {
        refuse(sprintf('%s: the log lists %d mutants and says it made %d.', $where, array_sum($counts), $log['stats']['totalMutantsCount'] ?? -1));
    }

    return ['counts' => $counts, 'mutants' => $mutants];
}

/** The part's file an absolute path from the run is, wherever the run had the tree. */
function fileOf(string $path, array $files): ?string
{
    $path = str_replace('\\', '/', $path);

    foreach ($files as $file) {
        if ($path === $file || str_ends_with($path, '/' . $file)) {
            return $file;
        }
    }

    return null;
}

function summarise(string $root, string $setName, string $dir): int
{
    $set = setOf($root, $setName);
    $parts = partsOf($root, $set);
    $fingerprint = fingerprint($root, $set);
    $infection = infectionVersion($root);
    $config = hash_file('sha256', $root . '/' . $set['config']);

    $records = [];

    foreach (glob($dir . '/' . $setName . '.*.json') ?: [] as $file) {
        $record = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        $records[$record['part']] = $record;
    }

    foreach ([...array_keys($parts), 'plan'] as $part) {
        if (!isset($records[$part])) {
            refuse(sprintf('%s.%s has no record — the part did not run, or its record was not collected.', $setName, $part));
        }
    }

    $extra = array_diff(array_keys($records), [...array_keys($parts), 'plan']);

    if ($extra !== []) {
        refuse(sprintf('Records of parts the manifest does not have: %s.', implode(', ', $extra)));
    }

    $plan = null;
    $counts = [];
    $mutants = [];

    foreach ($records as $part => $record) {
        $where = $setName . '.' . $part;

        if ($record['exitCode'] !== 0) {
            refuse(sprintf('%s: Infection exited with %d, so the run did not finish.', $where, $record['exitCode']));
        }

        foreach (['fingerprint' => $fingerprint, 'fingerprintBefore' => $fingerprint, 'infection' => $infection, 'config' => $config] as $field => $expected) {
            if ($record[$field] !== $expected) {
                refuse(sprintf('%s ran on another %s than this tree has (%s, here %s).', $where, $field, $record[$field], $expected));
            }
        }

        if (isset($record['refusal'])) {
            refuse($record['refusal']);
        }

        $read = ['counts' => $record['counts'], 'mutants' => $record['mutants']];

        if ($part === 'plan') {
            $plan = $read['mutants'];
            continue;
        }

        foreach ($read['counts'] as $status => $count) {
            $counts[$status] = ($counts[$status] ?? 0) + $count;
        }

        foreach ($read['mutants'] as $key => $count) {
            $mutants[$key] = ($mutants[$key] ?? 0) + $count;
        }
    }

    $missing = differenceOf($plan, $mutants);
    $unplanned = differenceOf($mutants, $plan);

    if ($missing !== [] || $unplanned !== []) {
        refuse(sprintf(
            "The parts' mutants are not the plan's: %d planned and not run, %d run and not planned (or run twice). First: %s",
            array_sum($missing),
            array_sum($unplanned),
            (string) array_key_first($missing !== [] ? $missing : $unplanned),
        ));
    }

    preg_match('/^\s*minCoveredMsi:\s*([0-9.]+)/m', (string) file_get_contents($root . '/' . $set['config']), $floor);

    if (!isset($floor[1])) {
        refuse(sprintf('%s states no minCoveredMsi.', $set['config']));
    }

    $total = array_sum($counts);
    $covered = $total - $counts['uncovered'] - $counts['ignored'];
    $killed = $counts['killed'] + $counts['killedByStaticAnalysis'];
    $detected = $killed + $counts['timeouted'] + $counts['errored'] + $counts['syntaxErrors'];
    $msi = $covered === 0 ? 0.0 : $detected / $covered * 100;
    $strict = $covered === 0 ? 0.0 : $killed / $covered * 100;

    printf("%s: %d mutants in %d part(s), every one planned and run once, none skipped.\n", $setName, $total, \count($parts));
    printf("  killed %d, escaped %d, timed out %d, errored %d, syntax errors %d, not covered %d, ignored %d\n", $killed, $counts['escaped'], $counts['timeouted'], $counts['errored'], $counts['syntaxErrors'], $counts['uncovered'], $counts['ignored']);
    printf("  covered MSI %.2f%% (timeouts and errors counted as detected, as Infection counts them)\n", $msi);
    printf("  covered MSI %.2f%% counting only what a test failed on\n", $strict);
    printf("  floor %s%%\n", $floor[1]);

    if ($msi + 0.005 < (float) $floor[1]) {
        fwrite(\STDERR, sprintf("%s is below its floor by %.2f points.\n", $setName, (float) $floor[1] - $msi));

        return 1;
    }

    return 0;
}

/** @return array<string, int> what $a has more of than $b */
function differenceOf(array $a, array $b): array
{
    $difference = [];

    foreach ($a as $key => $count) {
        if ($count > ($b[$key] ?? 0)) {
            $difference[$key] = $count - ($b[$key] ?? 0);
        }
    }

    return $difference;
}

$arguments = array_slice($argv, 1);
$root = dirname(__DIR__, 2);

foreach ($arguments as $i => $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, \strlen('--root='));
        unset($arguments[$i]);
    }
}

$arguments = array_values($arguments);

try {
    switch ($arguments[0] ?? null) {
        case 'parts':
            echo implode(' ', array_keys(partsOf($root, setOf($root, $arguments[1])))), "\n";
            exit(0);
        case 'files':
            echo implode(',', partsOf($root, setOf($root, $arguments[1]))[$arguments[2]] ?? refuse(sprintf('No part "%s".', $arguments[2]))), "\n";
            exit(0);
        case 'fingerprint':
            echo fingerprint($root, setOf($root, $arguments[1])), "\n";
            exit(0);
        case 'record':
            record($root, ...array_slice($arguments, 1, 6));
            exit(0);
        case 'summarise':
            exit(summarise($root, $arguments[1], $arguments[2]));
        default:
            fwrite(\STDERR, "Usage: see the docblock of tools/infection/gate.php.\n");
            exit(2);
    }
} catch (GateRefused $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");
    exit(1);
}
