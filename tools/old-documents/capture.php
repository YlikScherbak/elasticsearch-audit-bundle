<?php

declare(strict_types=1);

/*
 * Writes one audit record with an old version of this bundle and prints the document it
 * produced, so what a reader has to cope with is what that version really wrote rather
 * than what somebody remembers it writing.
 *
 * Driven by run.sh, which exports each tag's src/ and hands the directory over here. The
 * bundle's own classes come from that directory; everything third-party comes from the
 * current vendor/, which is safe because the five constructor arguments used below, the
 * transport interface and record()'s signature are the same in every tag from v0.7.0 on.
 *
 *   php tools/old-documents/capture.php <src dir> <version>
 */

[$self, $src, $version] = $argv + [null, null, null];

if ($src === null || $version === null) {
    fwrite(\STDERR, "usage: capture.php <src dir> <version>\n");

    exit(2);
}

require __DIR__.'/../../vendor/autoload.php';

// Prepended, so the tag's classes win over the working tree's for this namespace.
spl_autoload_register(static function (string $class) use ($src): void {
    $prefix = 'Borsche\\ElasticsearchAuditBundle\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $src.'/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
}, true, true);

$transport = new class implements Borsche\ElasticsearchAuditBundle\Transport\TransportInterface {
    /** @var list<array{index: string, id: string|null, document: array<string, mixed>}> */
    public array $sent = [];

    public function send(string $index, array $document, ?string $id = null): void
    {
        $this->sent[] = ['index' => $index, 'id' => $id, 'document' => $document];
    }
};

$clock = new class implements Psr\Clock\ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-26 12:00:00', new DateTimeZone('UTC'));
    }
};

$writer = new Borsche\ElasticsearchAuditBundle\Writer\AuditWriter(
    $transport,
    $transport,
    new Borsche\ElasticsearchAuditBundle\Writer\IndexResolver('audit_log'),
    new Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver([], 'system'),
    $clock,
);

// Both shapes of a change, because the pair has been accepted as an array for longer
// than the Change object has existed, and both have to still read.
$writer->record(
    'order',
    42,
    'update',
    ['status' => ['old' => 'draft', 'new' => 'paid'], 'total' => ['old' => 100, 'new' => 250]],
    ['warehouseId' => 7, 'note' => 'reconciled'],
    new DateTimeImmutable('2026-08-26 11:59:58', new DateTimeZone('UTC')),
    'u-1',
);

if ($transport->sent === []) {
    fwrite(\STDERR, "nothing was written by $version\n");

    exit(1);
}

$written = $transport->sent[0];

// The id is a fresh uuid on every run, and a fixture that changes every time it is
// regenerated cannot be compared with the one in the repository. Replaced where it
// appears, and only where it appears: a version that wrote no id has to keep not
// writing one, since that is the shape a reader has to cope with.
$fixed = '01a03df1-0000-7000-8000-000000000000';

if (isset($written['document']['id']) && \is_string($written['document']['id'])) {
    $written['document']['id'] = $fixed;
}

if (\is_string($written['id'])) {
    $written['id'] = $fixed;
}

echo json_encode([
    'version' => $version,
    'index' => $written['index'],
    '_id' => $written['id'],
    '_source' => $written['document'],
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), "\n";
