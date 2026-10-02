<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Keys;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Machine;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Press;
use Borsche\ElasticsearchAuditBundle\Tests\TestConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * A canary, no bundle code in it: where Doctrine asks the driver for a key the database handed
 * out, against what it asks around it — the fact the listener's binding of a key to its INSERT
 * stands on.
 *
 * It used to stand on another one: the order postPersist announces rows in, matched to the order
 * of the INSERTs without a key. That holds until a flush dies between the two — postPersist is
 * dispatched after the class's INSERTs are all written, and an exception in the first stops the
 * rest from being announced at all — and then every INSERT after it was matched to the key of
 * the one before, and the history of the next flush went to the wrong row. What is pinned here
 * is that the key comes on the same connection right after its INSERT, before anything else is
 * written, whether or not anybody is ever told.
 *
 * Run on every engine and DBAL of the matrix, through TestConnection. A key a generator takes
 * before the INSERT (a sequence) is in the INSERT's own parameters, and no lastInsertId() comes:
 * the test says which of the two this mapping is, and holds that one.
 */
final class WhereDoctrineAsksForAGeneratedKeyTest extends TestCase
{
    private EntityManagerInterface $em;

    private KeyWatchingMiddleware $watch;

    protected function setUp(): void
    {
        if (TestConnection::isSqlite() && !\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the canary.');
        }

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../../Fixtures']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $config->setMiddlewares([$this->watch = new KeyWatchingMiddleware()]);
        $connection = DriverManager::getConnection(TestConnection::params(), $config);
        TestConnection::reset($connection);
        $this->em = new EntityManager($connection, $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->watch->said = [];
    }

    public function testEachKeyIsAskedForRightAfterItsInsertAndBeforeTheNext(): void
    {
        $articles = [new Article('One'), new Article('Two'), new Article('Three')];

        foreach ($articles as $article) {
            $this->em->persist($article);
        }

        $this->em->flush();

        self::assertSame($this->expected('Article', array_map(static fn (Article $a): int => (int) $a->id, $articles)), $this->watch->about('Article'));
    }

    public function testAHierarchyAcrossTablesAsksOnceForTheRowsFirstTable(): void
    {
        $this->em->persist($one = new Press('Stamp'));
        $this->em->persist($two = new Press('Punch'));
        $this->em->flush();

        $rootTable = $this->em->getClassMetadata(Machine::class)->getTableName();
        $childTable = $this->em->getClassMetadata(Press::class)->getTableName();
        $said = array_values(array_filter($this->watch->about($rootTable), static fn (string $s): bool => str_starts_with($s, 'key ') || $s === 'INSERT '.$rootTable || $s === 'INSERT '.$childTable));

        if (!$this->isPostInsert(Machine::class)) {
            self::assertSame(['INSERT '.$rootTable, 'INSERT '.$childTable, 'INSERT '.$rootTable, 'INSERT '.$childTable], $said, 'a key taken before the INSERT, in its parameters');

            return;
        }

        self::assertSame(['INSERT '.$rootTable, 'key '.$one->id, 'INSERT '.$childTable, 'INSERT '.$rootTable, 'key '.$two->id, 'INSERT '.$childTable], $said);
    }

    public function testAFlushThatDiesBeforeAnyRowIsAnnouncedHasAskedForEveryKeyAlready(): void
    {
        $announced = [];
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class($announced) {
            /** @param list<string> $announced */
            public function __construct(private array &$announced)
            {
            }

            public function postPersist(PostPersistEventArgs $args): void
            {
                $this->announced[] = $args->getObject()->title;

                throw new \DomainException('the first announcement is the last');
            }
        });

        $this->em->persist(new Article('One'));
        $this->em->persist(new Article('Two'));

        try {
            $this->em->flush();
            self::fail('the premise: the flush died');
        } catch (\DomainException) {
        }

        self::assertSame(['One'], $announced, 'one row announced, the other never');
        $said = $this->watch->about('Article');

        if (!$this->isPostInsert(Article::class)) {
            self::assertSame(['INSERT Article', 'INSERT Article'], $said);

            return;
        }

        self::assertCount(4, $said);
        self::assertSame(['INSERT Article', 'INSERT Article'], [$said[0], $said[2]]);
        self::assertStringStartsWith('key ', $said[1]);
        self::assertStringStartsWith('key ', $said[3]);
        self::assertNotSame($said[1], $said[3], 'each INSERT its own key');
    }

    public function testANestedFlushFromPostPersistAsksForItsKeysBetweenTheOuterOnes(): void
    {
        $order = [];
        $em = $this->em;
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class($order, $em) {
            private bool $nested = false;

            /** @param list<string> $order */
            public function __construct(private array &$order, private readonly EntityManagerInterface $em)
            {
            }

            public function postPersist(PostPersistEventArgs $args): void
            {
                $this->order[] = 'announced '.$args->getObject()->title;

                if (!$this->nested && $args->getObject()->title === 'A') {
                    $this->nested = true;
                    $this->em->persist(new Article('C'));
                    $this->em->flush();
                }
            }
        });

        $this->em->persist($a = new Article('A'));
        $this->em->persist($b = new Article('B'));
        $this->em->flush();
        $c = $this->em->getRepository(Article::class)->findOneBy(['title' => 'C']);

        // The announcements: A, then the nested flush's C, then B — not the order of the INSERTs.
        self::assertSame(['announced A', 'announced C', 'announced B'], $order);

        if (!$this->isPostInsert(Article::class)) {
            self::assertSame(['INSERT Article', 'INSERT Article', 'INSERT Article'], $this->watch->about('Article'));

            return;
        }

        // The INSERTs: A and B, the outer flush's, each with its key straight after it; then C's.
        self::assertSame(['INSERT Article', 'key '.$a->id, 'INSERT Article', 'key '.$b->id, 'INSERT Article', 'key '.$c->id], $this->watch->about('Article'));
    }

    /**
     * INSERT and key, alternating, with the keys the rows were given — or INSERTs alone, where the
     * key is taken before.
     *
     * @param list<int> $ids
     *
     * @return list<string>
     */
    private function expected(string $table, array $ids): array
    {
        $expected = [];

        foreach ($ids as $id) {
            $expected[] = 'INSERT '.$table;

            if ($this->isPostInsert(Article::class)) {
                $expected[] = 'key '.$id;
            }
        }

        return $expected;
    }

    /** @param class-string $class */
    private function isPostInsert(string $class): bool
    {
        return $this->em->getClassMetadata($class)->idGenerator->isPostInsertGenerator();
    }
}
