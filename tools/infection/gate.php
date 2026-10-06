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
 * the summed counts, never from the parts' percentages — and held to the set's floor in
 * parts.json: what a test failed on, of the covered, compared in integers. A timeout or an
 * error is shown and not counted, because whether a mutant times out depends on the machine.
 * The parts themselves run with no floor, so that a part with many escaped mutants still
 * finishes and is counted rather than lost.
 *
 *   php tools/infection/gate.php parts <set>
 *   php tools/infection/gate.php matrix <set>                 # the parts, as JSON, for a CI matrix
 *   php tools/infection/gate.php agrees <set> <plan-record>   # before a part runs: is the plan of this tree?
 *   php tools/infection/gate.php files <set> <part>
 *   php tools/infection/gate.php fingerprint <set>
 *   php tools/infection/gate.php record <set> <part|plan> <log.json> <exit-code> <fingerprint-before> <threads> <cpus> <out-dir>
 *   php tools/infection/gate.php excluded <set> <whole-log.json> <out-dir>   # the exclusions, against the plan made without them
 *   php tools/infection/gate.php summarise <set> <dir>                  # writes <dir>/<set>-statuses.json too
 *   php tools/infection/gate.php transitions <statuses.json> <statuses.json>   # two runs, mutant by mutant
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

/** @return array{config: string, threads: int, floor: string, base?: array{run: string, plan: string, counts: array<string, int>}, sources: list<string>, parts: array<string, list<string>>} */
function setOf(string $root, string $set): array
{
    $manifest = json_decode((string) file_get_contents($root . '/tools/infection/parts.json'), true, 512, \JSON_THROW_ON_ERROR);

    if (!isset($manifest[$set])) {
        refuse(sprintf('parts.json has no set "%s".', $set));
    }

    if (!\is_int($manifest[$set]['threads'] ?? null) || $manifest[$set]['threads'] < 1) {
        refuse(sprintf('parts.json gives set "%s" no number of threads.', $set));
    }

    // A decimal in a string: read as digits, never as a float, so that the floor written is
    // the floor held.
    if (!\is_string($manifest[$set]['floor'] ?? null) || preg_match('/^[0-9]{1,3}(\.[0-9]{1,4})?$/', $manifest[$set]['floor']) !== 1 || !reaches(1, 1, $manifest[$set]['floor'])) {
        refuse(sprintf('parts.json gives set "%s" no floor (a percentage written as a string, "92.49").', $set));
    }

    // The counts of the run the floor was measured on, with the run and its plan: optional.
    $base = $manifest[$set]['base'] ?? null;

    if ($base !== null && !(\is_array($base) && \is_string($base['run'] ?? null) && \is_string($base['plan'] ?? null)
        && array_filter(['killed', 'escaped', 'timeouted', 'errored'], static fn (string $status): bool => !\is_int($base['counts'][$status] ?? null)) === [])) {
        refuse(sprintf('parts.json gives set "%s" a base without its run, its plan, or the counts killed, escaped, timeouted and errored.', $set));
    }

    return $manifest[$set];
}

/**
 * Whether $killed of $counted reach the floor, by integers alone: no float and no rounding,
 * so a score shown as the floor and short of it by a fraction of a mutation is short.
 */
function reaches(int $killed, int $counted, string $floor): bool
{
    [$whole, $fraction] = explode('.', $floor . '.', 3);
    $scale = 10 ** \strlen($fraction);

    return $killed * 100 * $scale >= ((int) $whole * $scale + (int) ('0' . $fraction)) * $counted;
}

/** How many more mutations a test must fail on for $killed of $counted to reach the floor. */
function shortBy(int $killed, int $counted, string $floor): int
{
    $more = 0;

    while (!reaches($killed + $more, $counted, $floor)) {
        ++$more;
    }

    return $more;
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

    // What a run leaves out is part of what it ran on.
    if (is_file($root . '/tools/infection/exclusions.json')) {
        $paths[] = 'tools/infection/exclusions.json';
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
function record(string $root, string $setName, string $part, string $log, string $exitCode, string $before, string $threads, string $cpus, string $out): void
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
        'threads' => (int) $threads,
        'cpus' => (int) $cpus,
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
 * @return array{counts: array<string, int>, mutants: array<string, int>, statuses: array<string, list<string>>, why: array<string, list<string>>}
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
    $statuses = [];
    $why = [];

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
            $statuses[$key][] = $status;

            // What a mutant that errored or timed out left of its run: the end of it, where a
            // crash or a test that never finished says what it was.
            if ($status === 'errored' || $status === 'timeouted') {
                $output = (string) ($mutant['processOutput'] ?? '');
                $why[$key][] = \strlen($output) > 2000 ? '…'.substr($output, -2000) : $output;
            }
        }
    }

    ksort($statuses);
    ksort($why);

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

    return ['counts' => $counts, 'mutants' => $mutants, 'statuses' => $statuses, 'why' => $why];
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

    // Every one named at once: a CI run that lost three parts should not read as one that lost one.
    $unrecorded = array_values(array_diff([...array_keys($parts), 'plan'], array_keys($records)));

    if ($unrecorded !== []) {
        refuse(sprintf('%s has no record of %s — the part did not run, or its record was not collected.', $setName, implode(', ', $unrecorded)));
    }

    $extra = array_diff(array_keys($records), [...array_keys($parts), 'plan']);

    if ($extra !== []) {
        refuse(sprintf('Records of parts the manifest does not have: %s.', implode(', ', $extra)));
    }

    $plan = null;
    $counts = [];
    $mutants = [];
    $statuses = [];
    $why = [];

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

        // A timeout counts as a kill, and how many a run has depends on how many mutants were
        // sharing the machine: on HistoryReplay.php, eleven threads of twelve called eight
        // escaped mutants timeouts. So the threads are the manifest's, and one CPU is left over.
        if ($record['threads'] !== $set['threads']) {
            refuse(sprintf('%s ran on %d threads, and the manifest says %d.', $where, $record['threads'], $set['threads']));
        }

        if ($record['threads'] >= $record['cpus']) {
            refuse(sprintf('%s ran %d threads on %d CPUs, which leaves the tests none to wait on.', $where, $record['threads'], $record['cpus']));
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

        foreach ($record['statuses'] ?? [] as $key => $of) {
            $statuses[$key] = [...($statuses[$key] ?? []), ...$of];
        }

        foreach ($record['why'] ?? [] as $key => $of) {
            $why[$key] = [...($why[$key] ?? []), ...$of];
        }

        foreach ($read['mutants'] as $key => $count) {
            $mutants[$key] = ($mutants[$key] ?? 0) + $count;
        }
    }

    // What the plan left out by the exclusions is exactly what they argue for: the plan without
    // them, less one of each, is the plan the parts ran.
    $argued = is_file($root . '/tools/infection/exclusions.json')
        ? json_decode((string) file_get_contents($root . '/tools/infection/exclusions.json'), true, 512, \JSON_THROW_ON_ERROR)[$setName] ?? []
        : [];
    $exclusions = null;

    if (is_file($dir . '/' . $setName . '-exclusions.json')) {
        $exclusions = json_decode((string) file_get_contents($dir . '/' . $setName . '-exclusions.json'), true, 512, \JSON_THROW_ON_ERROR);
    } elseif ($argued !== []) {
        refuse(sprintf('%s excludes %d mutations and there is no record of them held against the plan made without them.', $setName, \count($argued)));
    }

    if ($exclusions !== null) {
        foreach (['fingerprint' => $fingerprint, 'infection' => $infection] as $field => $expected) {
            if ($exclusions[$field] !== $expected) {
                refuse(sprintf('%s: the exclusions were held against another %s than this tree has.', $setName, $field));
            }
        }

        if (\count($exclusions['excluded']) !== \count($argued)) {
            refuse(sprintf('%s: %d exclusions were held against the plan, and the tree has %d.', $setName, \count($exclusions['excluded']), \count($argued)));
        }

        $without = $exclusions['whole'];

        foreach ($exclusions['excluded'] as $key) {
            $without[$key] = ($without[$key] ?? 0) - 1;
        }

        $without = array_filter($without, static fn (int $count): bool => $count !== 0);

        if (differenceOf($without, $plan) !== [] || differenceOf($plan, $without) !== []) {
            refuse(sprintf('%s: the plan is not the plan without exclusions less exactly the excluded mutations.', $setName));
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

    $total = array_sum($counts);
    $covered = $total - $counts['uncovered'] - $counts['ignored'];
    $killed = $counts['killed'] + $counts['killedByStaticAnalysis'];
    $detected = $killed + $counts['timeouted'] + $counts['errored'] + $counts['syntaxErrors'];
    $msi = $covered === 0 ? 0.0 : $detected / $covered * 100;
    $strict = $covered === 0 ? 0.0 : $killed / $covered * 100;

    printf("%s: %d mutants in %d part(s), every one planned and run once, none skipped.\n", $setName, $total, \count($parts));
    printf("  killed %d, escaped %d, timed out %d, errored %d, syntax errors %d, not covered %d, ignored %d\n", $killed, $counts['escaped'], $counts['timeouted'], $counts['errored'], $counts['syntaxErrors'], $counts['uncovered'], $counts['ignored']);
    printf("  covered MSI %.2f%% counting only what a test failed on -- the one the floor holds\n", $strict);
    printf("  covered MSI %.2f%% with timeouts and errors counted as detected, as Infection counts them\n", $msi);
    if ($exclusions !== null && $exclusions['excluded'] !== []) {
        ksort($exclusions['bases']);
        printf(
            "  excluded before the count: %d of %d planned, each argued for in tools/infection/exclusions.json (%s)\n",
            \count($exclusions['excluded']),
            array_sum($exclusions['whole']),
            implode(', ', array_map(static fn (string $basis, int $n): string => sprintf('%s %d', $basis, $n), array_keys($exclusions['bases']), $exclusions['bases'])),
        );
    }

    printf("  floor %s%% of the covered, counting only what a test failed on (parts.json)\n", $set['floor']);

    // Every mutant's status by name, red or green: what two runs are compared by, mutant by
    // mutant (`gate.php transitions`) -- a difference of counts says that something moved, and
    // never which mutant, or why.
    ksort($statuses);
    ksort($why);
    file_put_contents($dir . '/' . $setName . '-statuses.json', json_encode(['fingerprint' => $fingerprint, 'infection' => $infection, 'plan' => planHash($plan), 'statuses' => $statuses, 'why' => $why], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

    $against = againstTheBase($set, $plan, $counts, $killed);

    if ($against !== null) {
        printf("  %s\n", $against);
    }

    // A timeout or an error is shown and not counted: whether a mutant times out depends on the
    // machine. Nothing here says what made a run short: the counts cannot, and the statuses by
    // name are where to look.
    if (!reaches($killed, $covered, $set['floor'])) {
        fwrite(\STDERR, sprintf(
            "%s is below its floor: %d more of the %d would have to be killed by a test.%s The cause is not established: compare the mutants' statuses by name (gate.php transitions, %s-statuses.json against the base run's).\n",
            $setName,
            shortBy($killed, $covered, $set['floor']),
            $covered,
            $against === null ? '' : ' ' . ucfirst($against) . '.',
            $setName,
        ));

        return 1;
    }

    return 0;
}

/** What identifies a plan: its mutants, as a multiset. */
function planHash(array $plan): string
{
    ksort($plan);

    return hash('sha256', json_encode($plan, \JSON_THROW_ON_ERROR));
}

/**
 * The run against the counts of the run the floor was measured on, when parts.json keeps them --
 * and only when both are of one plan: counts of other mutants differ by what was planned, not by
 * what became of the same ones.
 *
 * @param array<string, int> $plan
 * @param array<string, int> $counts
 */
function againstTheBase(array $set, array $plan, array $counts, int $killed): ?string
{
    $base = $set['base'] ?? null;

    if (!\is_array($base)) {
        return null;
    }

    if ($base['plan'] !== planHash($plan)) {
        return sprintf('the plan is not the one of the base (run %s): its counts are of other mutants, and are not compared', $base['run']);
    }

    return sprintf(
        'against the base (run %s, one plan): killed %+d, escaped %+d, timed out %+d, errored %+d',
        $base['run'],
        $killed - $base['counts']['killed'],
        $counts['escaped'] - $base['counts']['escaped'],
        $counts['timeouted'] - $base['counts']['timeouted'],
        $counts['errored'] - $base['counts']['errored'],
    );
}

/**
 * Two runs' statuses, mutant by mutant: every mutant whose status is not the same in both, with
 * what an errored or timed-out one left of its run.
 */
function transitions(string $from, string $to): void
{
    $a = json_decode((string) file_get_contents($from), true, 512, \JSON_THROW_ON_ERROR);
    $b = json_decode((string) file_get_contents($to), true, 512, \JSON_THROW_ON_ERROR);

    if ($a['plan'] !== $b['plan']) {
        printf("The two runs are of different plans: only the mutants of both are compared.\n");
    }

    $moved = 0;

    foreach ($b['statuses'] as $key => $statuses) {
        $before = $a['statuses'][$key] ?? null;

        if ($before === null || $before === $statuses) {
            continue;
        }

        ++$moved;
        printf("%s: %s -> %s\n", $key, implode(', ', $before), implode(', ', $statuses));

        foreach ($b['why'][$key] ?? [] as $output) {
            printf("    %s\n", str_replace("\n", "\n    ", trim(substr($output, -600))));
        }
    }

    printf("%d mutant(s) changed status; %d only in the first run, %d only in the second.\n", $moved, \count(array_diff_key($a['statuses'], $b['statuses'])), \count(array_diff_key($b['statuses'], $a['statuses'])));
}

/**
 * Whether the plan a part was handed is of this tree, this Infection and this configuration.
 * The summary would refuse the run anyway; asked before a part starts, it is an hour of a CI
 * runner not spent on mutants the summary will throw away.
 */
function agrees(string $root, string $setName, string $planRecord): void
{
    $set = setOf($root, $setName);

    if (!is_file($planRecord)) {
        refuse(sprintf('%s: there is no plan to run against.', $planRecord));
    }

    $plan = json_decode((string) file_get_contents($planRecord), true, 512, \JSON_THROW_ON_ERROR);
    $here = ['fingerprint' => fingerprint($root, $set), 'infection' => infectionVersion($root), 'config' => hash_file('sha256', $root . '/' . $set['config'])];

    if ($plan['set'] !== $setName || $plan['part'] !== 'plan') {
        refuse(sprintf('%s is not the plan of set "%s".', $planRecord, $setName));
    }

    foreach ($here as $field => $expected) {
        if ($plan[$field] !== $expected) {
            refuse(sprintf('The plan was made on another %s than this tree has (%s, here %s).', $field, $plan[$field], $expected));
        }
    }
}

/**
 * The exclusions of a set (tools/infection/exclusions.json), held against the plan made without
 * them: each names one mutation of one line -- its file, its method, its mutator, its line, the
 * line's text and the mutation's diff -- and it has to be there, exactly, and alone of its kind.
 * Infection ignores a mutator at a line of a method, every mutation it makes there; so the
 * mutations a rule takes are counted, and each must have a record of its own. A line that moved,
 * a line whose text changed, a method gone, a diff that is not the one argued about, a second
 * mutation the rule would take with it: each is a refusal, by name.
 *
 * Written to <out>/<set>-exclusions.json: what was excluded and why, and the plan without the
 * exclusions -- the summary holds the plan with them to it, minus exactly these.
 */
function excluded(string $root, string $setName, string $wholeLog, string $out): void
{
    $set = setOf($root, $setName);
    $files = sourceFiles($root, $set['sources']);
    $exclusions = is_file($root . '/tools/infection/exclusions.json')
        ? json_decode((string) file_get_contents($root . '/tools/infection/exclusions.json'), true, 512, \JSON_THROW_ON_ERROR)[$setName] ?? []
        : [];
    $whole = readLog($setName . '.whole', $wholeLog, $files);

    // The diffs of the whole plan by file, mutator and line, as the records state them.
    $log = json_decode((string) file_get_contents($wholeLog), true, 512, \JSON_THROW_ON_ERROR);
    $made = [];

    foreach (STATUSES as $status) {
        foreach ($log[$status] as $mutant) {
            $file = (string) fileOf($mutant['mutator']['originalFilePath'], $files);
            $made[$file . ' | ' . $mutant['mutator']['mutatorName'] . ' | ' . $mutant['mutator']['originalStartLine']][] = [diffLines($mutant['diff']), sha1($mutant['diff'])];
        }
    }

    $claimed = [];
    $keys = [];
    $reasons = [];

    foreach ($exclusions as $i => $exclusion) {
        foreach (['file', 'method', 'mutator', 'line', 'text', 'diff', 'basis', 'reason'] as $field) {
            if (!isset($exclusion[$field]) || $exclusion[$field] === '' || $exclusion[$field] === []) {
                refuse(sprintf('Exclusion %d of %s has no %s.', $i, $setName, $field));
            }
        }

        $where = sprintf('The exclusion of %s at %s:%d (%s)', $exclusion['mutator'], $exclusion['file'], $exclusion['line'], $exclusion['method']);

        if (!\in_array($exclusion['basis'], ['PHP', 'Doctrine'], true)) {
            refuse(sprintf('%s rests on "%s"; only PHP\'s semantics and Doctrine\'s or DBAL\'s contract are grounds to exclude.', $where, $exclusion['basis']));
        }

        if (!\in_array($exclusion['file'], $files, true)) {
            refuse(sprintf('%s names a file that is not of the set.', $where));
        }

        $source = (string) file_get_contents($root . '/' . $exclusion['file']);
        $text = trim(explode("\n", $source)[$exclusion['line'] - 1] ?? '');

        if ($text !== $exclusion['text']) {
            refuse(sprintf('%s: the line is not the one argued about any more — it reads "%s", the record "%s".', $where, $text, $exclusion['text']));
        }

        if (methodAt($source, (int) $exclusion['line']) !== $exclusion['method']) {
            refuse(sprintf('%s: the line is in %s now.', $where, methodAt($source, (int) $exclusion['line']) ?? 'no method'));
        }

        $at = $exclusion['file'] . ' | ' . $exclusion['mutator'] . ' | ' . $exclusion['line'];
        $matching = array_keys(array_filter($made[$at] ?? [], static fn (array $one): bool => $one[0] === $exclusion['diff']));
        $free = array_values(array_diff($matching, $claimed[$at] ?? []));

        if ($free === []) {
            refuse(sprintf('%s: the plan without exclusions has no such mutation there%s.', $where, $matching === [] ? '' : ' left — another record claims it'));
        }

        $claimed[$at][] = $free[0];
        $keys[] = $at . ' | ' . $made[$at][$free[0]][1];
        $reasons[$exclusion['basis']] = ($reasons[$exclusion['basis']] ?? 0) + 1;
    }

    // A rule takes every mutation of its mutator at its line: each must be argued for.
    foreach ($claimed as $at => $taken) {
        if (\count($made[$at]) !== \count($taken)) {
            refuse(sprintf('The exclusion at %s would take %d mutations, and %d of them are argued for.', $at, \count($made[$at]), \count($taken)));
        }
    }

    if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
        refuse(sprintf('Cannot create %s.', $out));
    }

    file_put_contents($out . '/' . $setName . '-exclusions.json', json_encode([
        'set' => $setName,
        'fingerprint' => fingerprint($root, $set),
        'infection' => infectionVersion($root),
        'whole' => $whole['mutants'],
        'excluded' => $keys,
        'bases' => $reasons,
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
}

/**
 * A diff of Infection's as its changed lines: each "-" or "+" and the line's text, trimmed.
 *
 * @return list<string>
 */
function diffLines(string $diff): array
{
    $lines = [];

    foreach (explode("\n", str_replace("\r\n", "\n", $diff)) as $line) {
        if (($line[0] ?? '') === '-' && !str_starts_with($line, '---') || ($line[0] ?? '') === '+' && !str_starts_with($line, '+++')) {
            $lines[] = $line[0] . ' ' . trim(substr($line, 1));
        }
    }

    return $lines;
}

/** The method a line is in, as Infection's rules name one: the class with its namespace, "::", the method. */
function methodAt(string $source, int $line): ?string
{
    $namespace = '';
    $class = null;
    $found = null;
    $depth = 0;
    $open = [];
    $tokens = PhpToken::tokenize($source);

    foreach ($tokens as $i => $token) {
        if ($token->is(\T_NAMESPACE)) {
            $namespace = '';

            for ($j = $i + 1; isset($tokens[$j]) && !$tokens[$j]->is([';', '{']); ++$j) {
                $namespace .= $tokens[$j]->is([\T_NAME_QUALIFIED, \T_STRING]) ? $tokens[$j]->text : '';
            }
        }

        if ($token->is([\T_CLASS, \T_TRAIT, \T_ENUM]) && ($tokens[$i - 1] ?? null)?->is(\T_DOUBLE_COLON) !== true) {
            for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->is(\T_WHITESPACE); ++$j) {
            }

            if (isset($tokens[$j]) && $tokens[$j]->is(\T_STRING)) {
                $class = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$j]->text;
            }
        }

        if ($token->is(\T_FUNCTION) && $class !== null) {
            for ($j = $i + 1; isset($tokens[$j]) && !$tokens[$j]->is(\T_STRING) && !$tokens[$j]->is('('); ++$j) {
            }

            if (isset($tokens[$j]) && $tokens[$j]->is(\T_STRING)) {
                $open[] = ['name' => $tokens[$j]->text, 'from' => $token->line, 'depth' => null];
            }
        }

        if ($token->is('{') || $token->is(\T_CURLY_OPEN) || $token->is(\T_DOLLAR_OPEN_CURLY_BRACES)) {
            ++$depth;

            foreach ($open as $k => $method) {
                if ($method['depth'] === null && $token->is('{')) {
                    $open[$k]['depth'] = $depth;
                }
            }
        }

        if ($token->is('}')) {
            foreach ($open as $k => $method) {
                if ($method['depth'] === $depth) {
                    if ($line >= $method['from'] && $line <= $token->line) {
                        $found ??= $class . '::' . $method['name'];
                    }

                    unset($open[$k]);
                }
            }

            --$depth;
        }

        // A method with no body (an interface's, an abstract one) ends at its semicolon.
        if ($token->is(';')) {
            $open = array_filter($open, static fn (array $method): bool => $method['depth'] !== null);
        }
    }

    return $found;
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
        case 'matrix':
            echo json_encode(array_keys(partsOf($root, setOf($root, $arguments[1]))), \JSON_THROW_ON_ERROR), "\n";
            exit(0);
        case 'agrees':
            agrees($root, $arguments[1], $arguments[2]);
            exit(0);
        case 'files':
            echo implode(',', partsOf($root, setOf($root, $arguments[1]))[$arguments[2]] ?? refuse(sprintf('No part "%s".', $arguments[2]))), "\n";
            exit(0);
        case 'fingerprint':
            echo fingerprint($root, setOf($root, $arguments[1])), "\n";
            exit(0);
        case 'record':
            record($root, ...array_slice($arguments, 1, 8));
            exit(0);
        case 'excluded':
            excluded($root, $arguments[1], $arguments[2], $arguments[3]);
            exit(0);
        case 'summarise':
            exit(summarise($root, $arguments[1], $arguments[2]));
        case 'transitions':
            transitions($arguments[1], $arguments[2]);
            exit(0);
        default:
            fwrite(\STDERR, "Usage: see the docblock of tools/infection/gate.php.\n");
            exit(2);
    }
} catch (GateRefused $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");
    exit(1);
}
