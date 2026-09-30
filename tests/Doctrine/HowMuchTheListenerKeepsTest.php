<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Budget\Imported;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Budget\Unaudited;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the listener adds to the peak memory of one flush, per entity, over what Doctrine takes
 * for the same flush -- two numbers, held as tests so that the next rebuilding of a stage is seen
 * at the commit that makes it, not at the benchmark before a release.
 *
 * The bar, run with the suite: 5 KB an audited entity. 1.3 adds about 4 KB (measured
 * 2026-09-30); a stage that doubles it is red where it is written. Next to nothing for an entity
 * nobody audits -- the listener has no business with its rows.
 *
 * The goal, the benchmark group, run on request (`--group benchmark`): 3 KB an audited entity.
 * 1.2 added about 2 KB, and a flush of twenty thousand fitted in 128 MB; 1.3 does not yet -- a
 * transaction's log and facts live until it ends, since the history is rebuilt from the log when
 * it rolls back. Red until the goal is met, and outside the suite until then.
 *
 * Measured in this process, one flush without the listener and one with it, each from a peak
 * reset right before it; on PHP 8.1, which cannot reset the peak, skipped. The numbers of a
 * whole flush and of the time are tools/flush-cost.php's, and the README's table.
 */
final class HowMuchTheListenerKeepsTest extends TestCase
{
    private const ENTITIES = 5000;

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function theBar(): iterable
    {
        yield 'inserting audited entities' => ['insert', Imported::class, 5120];
        yield 'updating audited entities' => ['update', Imported::class, 5120];
        yield 'updating entities nobody audits' => ['update', Unaudited::class, 300];
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function theGoal(): iterable
    {
        yield 'inserting audited entities' => ['insert', Imported::class, 3072];
        yield 'updating audited entities' => ['update', Imported::class, 3072];
    }

    #[DataProvider('theBar')]
    public function testTheListenerStaysUnderItsBar(string $what, string $class, int $bytesAnEntity): void
    {
        $this->assertTheListenerAdds($what, $class, $bytesAnEntity);
    }

    #[DataProvider('theGoal')]
    #[\PHPUnit\Framework\Attributes\Group('benchmark')]
    public function testTheListenerKeepsWithinItsGoal(string $what, string $class, int $bytesAnEntity): void
    {
        $this->assertTheListenerAdds($what, $class, $bytesAnEntity);
    }

    private function assertTheListenerAdds(string $what, string $class, int $bytesAnEntity): void
    {
        if (!\function_exists('memory_reset_peak_usage')) {
            self::markTestSkipped('The peak memory of one flush can only be measured on PHP 8.2 and later.');
        }

        $without = $this->peakOfAFlush($what, $class, false);
        $with = $this->peakOfAFlush($what, $class, true);
        $added = intdiv(max(0, $with - $without), self::ENTITIES);

        self::assertLessThanOrEqual(
            $bytesAnEntity,
            $added,
            sprintf('%s: the listener added %d bytes an entity to the flush\'s peak (%d KB without it, %d KB with it); the limit is %d', $what, $added, intdiv($without, 1024), intdiv($with, 1024), $bytesAnEntity),
        );
    }

    /** What one flush of the entities added to the process's peak, with the listener or without. */
    private function peakOfAFlush(string $what, string $class, bool $listening): int
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/Budget']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $statements = new StatementLog();

        if ($listening) {
            $config->setMiddlewares([new ObservingMiddleware($statements)]);
        }

        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Imported::class), $em->getClassMetadata(Unaudited::class)]);

        if ($listening) {
            $nowhere = new class implements BatchTransportInterface {
                public function send(string $index, array $document, ?string $id = null): void
                {
                }

                public function sendMany(array $items): BulkResult
                {
                    return BulkResult::allSucceeded(\count($items));
                }
            };
            $writer = new AuditWriter($nowhere, $nowhere, new IndexResolver('audit_log'), new ChainActorResolver([], 'budget'), new FrozenClock(), [], FailurePolicy::Throw);
            $em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory(), $statements));
        }

        $all = [];

        for ($i = 0; $i < self::ENTITIES; ++$i) {
            if ($class === Imported::class) {
                $one = new Imported('b'.$i);
            } else {
                $one = new Unaudited('a'.$i);
            }

            $em->persist($all[] = $one);
        }

        if ($what === 'update') {
            $em->flush();

            foreach ($all as $one) {
                if ($one instanceof Imported) {
                    $one->label .= '!';
                } else {
                    $one->name .= '!';
                }
            }
        }

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $em->flush();
        $added = memory_get_peak_usage() - $before;

        $em->close();
        unset($em, $all);
        gc_collect_cycles();

        return $added;
    }
}
