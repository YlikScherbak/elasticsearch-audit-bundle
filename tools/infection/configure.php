<?php

declare(strict_types=1);

/**
 * The configuration a run of a set is made with: the set's own (parts.json names it), with the
 * exclusions tools/infection/exclusions.json gives the set added to its mutators' ignore lists --
 * or without them, for the plan the exclusions are held against (gate.php excluded).
 *
 *   php tools/infection/configure.php <set> with|without
 *
 * Written beside the set's configuration, as infection.<set>.run.json or
 * infection.<set>.whole.json -- Infection reads every path of a configuration from the directory
 * the file is in -- and the path is printed. The exclusions are not written into the set's own
 * file by hand, because there a line number drifts in silence; here each comes with the line's
 * text and the mutation's diff, and gate.php refuses the run when either no longer agrees.
 *
 * Needs Infection's own vendor directory, for its JSON5 reader: it runs where Infection runs.
 *
 * --root=<dir> points it at another tree than this one.
 */

$arguments = array_slice($argv, 1);
$root = dirname(__DIR__, 2);

foreach ($arguments as $i => $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, \strlen('--root='));
        unset($arguments[$i]);
    }
}

[$set, $mode] = array_values($arguments) + [null, null];

if (!\is_string($set) || !\in_array($mode, ['with', 'without'], true)) {
    fwrite(\STDERR, "Usage: php tools/infection/configure.php <set> with|without\n");
    exit(2);
}

require __DIR__ . '/vendor/autoload.php';

$manifest = json_decode((string) file_get_contents($root . '/tools/infection/parts.json'), true, 512, \JSON_THROW_ON_ERROR);
$own = $manifest[$set]['config'] ?? null;

if (!\is_string($own)) {
    fwrite(\STDERR, sprintf("parts.json gives set \"%s\" no configuration.\n", $set));
    exit(2);
}

$config = ColinODell\Json5\Json5Decoder::decode((string) file_get_contents($root . '/' . $own), true);
$exclusions = is_file($root . '/tools/infection/exclusions.json')
    ? json_decode((string) file_get_contents($root . '/tools/infection/exclusions.json'), true, 512, \JSON_THROW_ON_ERROR)[$set] ?? []
    : [];

if ($mode === 'with') {
    foreach ($exclusions as $exclusion) {
        $rule = $exclusion['method'] . '::' . $exclusion['line'];
        $mutator = $config['mutators'][$exclusion['mutator']] ?? true;
        $mutator = \is_array($mutator) ? $mutator : [];
        $mutator['ignore'] = array_values(array_unique([...$mutator['ignore'] ?? [], $rule]));
        $config['mutators'][$exclusion['mutator']] = $mutator;
    }
}

$out = sprintf('%s/infection.%s.%s.json', $root, $set, $mode === 'with' ? 'run' : 'whole');
file_put_contents($out, json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
echo basename($out), "\n";
