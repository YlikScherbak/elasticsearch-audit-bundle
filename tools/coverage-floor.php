<?php

declare(strict_types=1);

/**
 * Fails the build when line coverage of src falls below a floor. PHPUnit reports
 * coverage but does not enforce a minimum, and a suite that quietly stops covering
 * a class is exactly what a floor is for.
 *
 * Usage: php tools/coverage-floor.php coverage.xml 90
 */
$file = $argv[1] ?? 'coverage.xml';
$floor = (float) ($argv[2] ?? 90);

if (!is_file($file)) {
    fwrite(\STDERR, sprintf("No coverage report at %s — did phpunit run with --coverage-clover?\n", $file));
    exit(1);
}

$xml = simplexml_load_file($file);

if ($xml === false) {
    fwrite(\STDERR, sprintf("%s is not readable as XML.\n", $file));
    exit(1);
}

$metrics = $xml->xpath('//project/metrics');

if ($metrics === false || $metrics === []) {
    fwrite(\STDERR, "The clover report carries no project metrics.\n");
    exit(1);
}

$statements = (int) $metrics[0]['statements'];
$covered = (int) $metrics[0]['coveredstatements'];
$percentage = $statements === 0 ? 0.0 : round($covered / $statements * 100, 2);

printf("Line coverage: %.2f%% (%d/%d statements), floor %.2f%%\n", $percentage, $covered, $statements, $floor);

if ($percentage + 0.005 < $floor) {
    fwrite(\STDERR, sprintf("Coverage fell below the floor by %.2f points.\n", $floor - $percentage));
    exit(1);
}

exit(0);
