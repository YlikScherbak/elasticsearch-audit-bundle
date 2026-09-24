<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Writer\Provenance;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Whose name each fact of the shadow history would carry, when the actor changes between an
 * outer flush and a flush nested inside it.
 *
 * A fact belongs to the flush its statement ran in, and a flush's moment -- its time, its
 * actor -- is the one the listener took in its onFlush. So a fact's author is the author of the
 * flush that owns its statement. With savepoints a nested flush opens a frame of its own and
 * owns what it ran; without them it opens nothing, its statements are the outer flush's, and
 * so is the name on them. This measures that, rather than saying it: the measurement is what
 * the choice about use_savepoints on DBAL 3 is to be made from.
 */
class WhoWroteItTest extends DoctrineTestCase
{
    protected bool $savepoints = false;

    private StatementLog $log;

    /** @var object{who: ?string}&ActorResolverInterface */
    private ActorResolverInterface $who;

    /** @var array<int, ?string> the actor each flush's moment was taken with, copied when it was taken */
    private array $authors = [];

    /** @var array<class-string, list<object>> */
    private array $persisted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->who = $this->actors = new class implements ActorResolverInterface {
            public ?string $who = 'alice';

            public function resolve(): ?string
            {
                return $this->who;
            }
        };

        $this->log = $this->watchTheConnection(savepoints: $this->savepoints);

        // Behind this listener in onFlush, where it has just taken the flush's moment: a copy
        // of whose it was, before the flush that took it is over and the moment is let go.
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class($this->authors, $this->em) {
            /** @param array<int, ?string> $authors */
            public function __construct(private array &$authors, private readonly \Doctrine\ORM\EntityManagerInterface $em)
            {
            }

            public function onFlush(): void
            {
                foreach ($this->em->getEventManager()->getListeners(Events::onFlush) as $listener) {
                    if ($listener instanceof AuditSubscriber) {
                        $flush = (new \ReflectionProperty(AuditSubscriber::class, 'flush'))->getValue($listener);
                        $moment = (new \ReflectionProperty(AuditSubscriber::class, 'provenance'))->getValue($listener)[$flush] ?? null;
                        $this->authors[$flush] = $moment instanceof Provenance ? $moment->actor : null;
                    }
                }
            }
        });
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class($this->persisted) {
            /** @param array<class-string, list<object>> $persisted */
            public function __construct(private array &$persisted)
            {
            }

            public function postPersist(PostPersistEventArgs $args): void
            {
                $this->persisted[$args->getObjectManager()->getClassMetadata($args->getObject()::class)->rootEntityName][] = $args->getObject();
            }
        });
    }

    public function testWhatANestedFlushWroteForTheOuterOneIsSignedByWhoeverOwnsItsStatement(): void
    {
        // S1b, with bob running the nested flush: it writes Y with 7, the outer flush's
        // leftover, and alice's outer flush wrote X.
        [$from, $before, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($y): void {
            $this->who->who = 'bob';
            $y->quantity = 7;
            $this->em->flush();
            $this->who->who = 'alice';
        });

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        self::assertSame(
            $this->nestedFramesAreSeen()
                ? ['crate C-1 items.1.quantity: 1 -> 2 by alice', 'crate C-1 items.2.quantity: 1 -> 7 by bob']
                : ['crate C-1 items.1.quantity: 1 -> 2 by alice', 'crate C-1 items.2.quantity: 1 -> 7 by alice'],
            $this->signed($from, $before),
        );
    }

    public function testTwoChangesOfOneColumnAreSignedByEachFlushThatMadeThem(): void
    {
        // S1a: alice's outer flush writes X = 2, bob's nested one X = 5 and the leftover Y.
        [$from, $before, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($x): void {
            $this->who->who = 'bob';
            $x->quantity = 5;
            $this->em->flush();
            $this->who->who = 'alice';
        });

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        $said = $this->signed($from, $before);
        sort($said); // ORM 2 and 3 run the nested flush's two statements in different orders

        self::assertSame(
            $this->nestedFramesAreSeen()
                ? ['crate C-1 items.1.quantity: 1 -> 2 by alice', 'crate C-1 items.1.quantity: 2 -> 5 by bob', 'crate C-1 items.2.quantity: 1 -> 2 by bob']
                : ['crate C-1 items.1.quantity: 1 -> 2 by alice', 'crate C-1 items.1.quantity: 2 -> 5 by alice', 'crate C-1 items.2.quantity: 1 -> 2 by alice'],
            $said,
        );
    }

    /**
     * @param array<class-string, array<string, array<string, mixed>>> $before
     *
     * @return list<string>
     */
    private function signed(int $from, array $before): array
    {
        $shadow = ShadowHistory::fromWhatWasRemembered($this->em, $before)->replay($this->log, $from, $this->persisted);
        self::assertSame([], $shadow['unsure']);

        $signed = [];

        foreach ($shadow['facts'] as $i => $fact) {
            $owner = $shadow['owners'][$i] ?? null;
            $signed[] = $fact.' by '.($owner === null ? 'nobody' : ($this->authors[$owner] ?? 'an unknown flush '.$owner));
        }

        return $signed;
    }

    /**
     * @return array{int, array<class-string, array<string, array<string, mixed>>>, CrateItem, CrateItem}
     */
    private function aCrateWithTwoLines(): array
    {
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($x = new CrateItem('SKU-X'));
        $crate->add($y = new CrateItem('SKU-Y'));
        $this->em->flush();

        $before = ShadowHistory::theRows($this->em, [Crate::class, CrateItem::class]);

        return [$this->log->position(), $before, $x, $y];
    }

    private function nestedFramesAreSeen(): bool
    {
        $connection = $this->em->getConnection();

        return !method_exists($connection, 'getNestTransactionsWithSavepoints') || $connection->getNestTransactionsWithSavepoints();
    }

    private function inThePostUpdateOf(object $entity, \Closure $what): void
    {
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($entity, $what) {
            private bool $ran = false;

            public function __construct(private readonly object $entity, private readonly \Closure $what)
            {
            }

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                if ($this->ran || $args->getObject() !== $this->entity) {
                    return;
                }

                $this->ran = true;
                ($this->what)();
            }
        });
    }
}
