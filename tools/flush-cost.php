<?php

declare(strict_types=1);

/*
 * What the audit listener costs one flush, measured rather than reasoned about: the README's
 * performance table comes from here.
 *
 *   php tools/flush-cost.php [entities=20000] [repeats=3] [memory_limit=-1]
 *
 * SQLite in memory, a transport that keeps nothing, one flush of N entities of the benchmark's own
 * classes (tools/flush-cost/Entities.php), each case in a process of its own so that nothing one
 * leaves behind is counted in the next, each repeated and reported by its median. What is
 * reported is the flush's own time, the peak memory it added over what the process held
 * before it, and the process's peak through the flush: what PHP used, and what it allocated from
 * the system -- the second is what memory_limit is held against.
 * A case that runs out of the memory limit given says so.
 *
 * Runs against whichever release of the bundle is installed: from 1.3 the audited connection is
 * watched through the bundle's driver middleware, before it the listener needed nothing of the
 * connection. Mount another release's src/ over this one's to compare the two on one machine.
 */

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Tools\FlushCost\Audited;
use Borsche\ElasticsearchAuditBundle\Tools\FlushCost\Plain;
use Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Clock\ClockInterface;

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/flush-cost/Entities.php';

$entities = (int) ($argv[1] ?? 20000);
$repeats = (int) ($argv[2] ?? 3);
$limit = (string) ($argv[3] ?? '-1');
$case = $argv[4] ?? null;

if ($case === null) {
    $cases = [
        'inserting audited entities' => ['insert', 'audited'],
        'updating audited entities' => ['update', 'audited'],
        'updating entities nobody audits' => ['update', 'plain'],
    ];
    $release = class_exists(\Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog::class) ? '1.3 or later' : 'before 1.3';
    printf("%d entities in one flush, the bundle %s, PHP %s, memory_limit %s, median of %d\n\n| The flush | Without the listener | With it |\n|---|---|---|\n", $entities, $release, \PHP_VERSION, $limit, $repeats);

    // A driver this process was given on its command line, and a process of its own would not
    // have: the cases run under the same PHP as the table says.
    $php = escapeshellarg(\PHP_BINARY);
    $given = preg_match('/^pdo_sqlite$/mi', (string) shell_exec($php.' -m')) === 1;
    $driver = \extension_loaded('pdo_sqlite') && !$given ? ' -d extension=pdo_sqlite' : '';

    foreach ($cases as $name => [$what, $whose]) {
        $cells = [];

        foreach (['without', 'with'] as $listener) {
            $runs = [];
            $failure = null;

            for ($i = 0; $i < $repeats; ++$i) {
                $out = trim((string) shell_exec(sprintf('%s%s -d memory_limit=%s %s %d %d %s %s 2>&1', $php, $driver, escapeshellarg($limit), escapeshellarg(__FILE__), $entities, 1, escapeshellarg($limit), escapeshellarg("$what:$whose:$listener"))));

                if (preg_match('/^(\d+) (\d+) (\d+) (\d+)$/', $out, $m) === 1) {
                    $runs[] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
                } else {
                    // Out of memory is a result; anything else is the benchmark failing, and says what.
                    $failure ??= str_contains($out, 'Allowed memory size') ? 'out of memory' : 'failed: '.strtok($out, "\n");
                }
            }

            if ($failure !== null) {
                $cells[] = $failure;

                continue;
            }

            sort($runs);
            $ms = $runs[intdiv(\count($runs), 2)][0];
            $bytes = array_column($runs, 1);
            sort($bytes);
            $peaks = array_column($runs, 2);
            sort($peaks);
            $allocated = array_column($runs, 3);
            sort($allocated);
            $cells[] = sprintf('%d ms, +%d MB (peak %d MB used, %d MB allocated)', $ms, round($bytes[intdiv(\count($bytes), 2)] / 1048576), round($peaks[intdiv(\count($peaks), 2)] / 1048576), round($allocated[intdiv(\count($allocated), 2)] / 1048576));
        }

        printf("| %s | %s | %s |\n", $name, $cells[0], $cells[1]);
    }

    exit(0);
}

[$what, $whose, $listener] = explode(':', $case);

$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/flush-cost']));
$config->setProxyDir(sys_get_temp_dir().'/borsche-audit-bench-proxies');
$config->setProxyNamespace('BorscheAuditBenchProxies');
$config->setAutoGenerateProxyClasses(true);

if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
    $config->enableNativeLazyObjects(true);
}

$watched = class_exists(\Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog::class);
$statements = $watched ? new \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog() : null;

if ($listener === 'with' && $statements !== null) {
    $config->setMiddlewares([new \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware($statements)]);
}

$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
(new SchemaTool($em))->createSchema([$em->getClassMetadata(Audited::class), $em->getClassMetadata(Plain::class)]);

if ($listener === 'with') {
    $nowhere = new class implements BatchTransportInterface {
        public function send(string $index, array $document, ?string $id = null): void
        {
        }

        public function sendMany(array $items): BulkResult
        {
            return BulkResult::allSucceeded(\count($items));
        }
    };
    $clock = new class implements ClockInterface {
        public function now(): \DateTimeImmutable
        {
            return new \DateTimeImmutable('2026-09-30 12:00:00', new \DateTimeZone('UTC'));
        }
    };
    $writer = new AuditWriter($nowhere, $nowhere, new IndexResolver('audit_log'), new ChainActorResolver([], 'bench'), $clock, [], FailurePolicy::Throw);
    $subscriber = $statements !== null
        ? new AuditSubscriber($writer, new AuditMetadataFactory(), $statements)
        : new AuditSubscriber($writer, new AuditMetadataFactory());
    $em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, $subscriber);
}

$all = [];

for ($i = 0; $i < $entities; ++$i) {
    $em->persist($all[] = $whose === 'audited' ? new Audited('a'.$i) : new Plain('p'.$i));
}

if ($what === 'update') {
    $em->flush();

    foreach ($all as $one) {
        if ($one instanceof Audited) {
            $one->label .= '!';
        } else {
            $one->name .= '!';
        }
    }
}

gc_collect_cycles();
memory_reset_peak_usage();
$before = memory_get_usage();
$started = hrtime(true);
$em->flush();
$ms = (hrtime(true) - $started) / 1e6;

// What the flush added, and the process's peak through it: the second is what a memory limit is
// held against.
printf("%d %d %d %d\n", round($ms), memory_get_peak_usage() - $before, memory_get_peak_usage(), memory_get_peak_usage(true));
