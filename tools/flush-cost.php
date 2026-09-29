<?php

declare(strict_types=1);

/*
 * What the audit listener costs one flush, measured rather than reasoned about: the README's
 * performance table comes from here.
 *
 *   php tools/flush-cost.php [entities=20000]
 *
 * SQLite in memory, a transport that keeps nothing, one flush of N entities, each case in a
 * process of its own so that nothing one leaves behind is counted in the next. What is reported
 * is the flush's own time and the peak memory it added over what the process held before it.
 */

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Beacon;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;

require __DIR__.'/../vendor/autoload.php';

$entities = (int) ($argv[1] ?? 20000);
$case = $argv[2] ?? null;

if ($case === null) {
    // Every case in a process of its own.
    $cases = [
        'inserting audited entities' => ['insert', 'audited'],
        'updating audited entities' => ['update', 'audited'],
        'updating entities nobody audits' => ['update', 'unaudited'],
    ];
    printf("%d entities in one flush, PHP %s\n\n| The flush | Without the listener | With it |\n|---|---|---|\n", $entities, \PHP_VERSION);

    foreach ($cases as $name => [$what, $whose]) {
        $cells = [];

        foreach (['without', 'with'] as $listener) {
            $out = shell_exec(sprintf('%s -d memory_limit=-1 %s %d %s', escapeshellarg(\PHP_BINARY), escapeshellarg(__FILE__), $entities, escapeshellarg("$what:$whose:$listener")));
            $cells[] = trim((string) $out);
        }

        printf("| %s | %s | %s |\n", $name, $cells[0], $cells[1]);
    }

    exit(0);
}

[$what, $whose, $listener] = explode(':', $case);

$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../tests/Fixtures']));
$config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
$config->setProxyNamespace('BorscheAuditProxies');
$config->setAutoGenerateProxyClasses(true);

if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
    $config->enableNativeLazyObjects(true);
}

$statements = new StatementLog();

if ($listener === 'with') {
    $config->setMiddlewares([new ObservingMiddleware($statements)]);
}

$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
(new SchemaTool($em))->createSchema([$em->getClassMetadata(Beacon::class), $em->getClassMetadata(Author::class)]);

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
    $writer = new AuditWriter($nowhere, $nowhere, new IndexResolver('audit_log'), new ChainActorResolver([], 'bench'), new FrozenClock(), [], FailurePolicy::Throw);
    $em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory(), $statements));
}

$make = $whose === 'audited'
    ? static fn (int $i): object => (static function () use ($i): Beacon { $b = new Beacon(); $b->label = 'b'.$i; return $b; })()
    : static fn (int $i): object => new Author('a'.$i);
$touch = $whose === 'audited'
    ? static function (object $one): void { $one->label .= '!'; }
    : static function (object $one): void { $one->name .= '!'; };

$all = [];

for ($i = 0; $i < $entities; ++$i) {
    $em->persist($all[] = $make($i));
}

if ($what === 'update') {
    $em->flush();

    foreach ($all as $one) {
        $touch($one);
    }
}

gc_collect_cycles();
memory_reset_peak_usage();
$before = memory_get_usage();
$started = hrtime(true);
$em->flush();
$ms = (hrtime(true) - $started) / 1e6;
$added = memory_get_peak_usage() - $before;

printf("%d ms, +%d MB\n", round($ms), round($added / 1048576));
