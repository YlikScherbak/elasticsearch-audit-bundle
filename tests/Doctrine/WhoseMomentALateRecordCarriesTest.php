<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MomentEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Depot;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\PackingCase;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;
use Psr\Clock\ClockInterface;

/**
 * Alice's records, published while Bob is working, are Alice's records.
 *
 * A flush whose publishing was swallowed — a postFlush listener registered before this
 * one throwing — has its records written by the next flush that comes along. That next
 * flush belongs to somebody else: another user, another route, another day.
 *
 * Everything the writer takes from "now" is therefore taken from the wrong moment: the
 * actor from the security token of whoever is logged in when the write happens, the
 * timestamp from the clock at that point, and the record id from that timestamp. The
 * result is not a missing record, which is the failure this listener spends most of its
 * code avoiding — it is a record naming the wrong person at the wrong time, which is the
 * one kind of history worse than none, and the frame buffer says so in its own docblock
 * about merging across actors.
 *
 * So the moment is taken where the change happened — in onFlush — and carried with the
 * records to wherever they are eventually written.
 */
final class WhoseMomentALateRecordCarriesTest extends DoctrineTestCase
{
    private const ALICE = '2026-09-21 10:00:00';
    private const BOB = '2026-09-22 15:30:00';

    /**
     * Who the writer would answer with, changed between one flush and the next the way a
     * second request changes it.
     */
    private object $who;

    private object $when;

    protected function setUp(): void
    {
        $this->who = new class implements ActorResolverInterface {
            public ?string $actor = 'alice';

            public function resolve(): ?string
            {
                return $this->actor;
            }
        };

        // The constant by value: inside an anonymous class `self` is that class.
        $this->when = new class(self::ALICE) implements ClockInterface {
            public function __construct(public string $now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->now, new \DateTimeZone('UTC'));
            }
        };

        parent::setUp();

        $this->actors = $this->who;
        $this->clock = $this->when;
        $this->attachListener(FailurePolicy::Log);
    }

    public function testAnOrdinaryRecordKeepsTheActorOfItsOwnFlush(): void
    {
        $article = $this->alicePersisted();

        $this->aliceChanges(static fn () => $article->title = 'Alice edited this');
        $this->bobFlushes();

        self::assertSame('alice', $this->lateRecord()['source'], 'the record of Alice\'s change names whoever was logged in when it was written');
    }

    public function testTheRecordOfAnOwnerWithNoEventOfItsOwnKeepsItToo(): void
    {
        // Built in postFlush rather than in a lifecycle event, and in the late branch
        // that postFlush belongs to the next flush entirely.
        $depot = new Depot('north');
        $depot->add($case = new PackingCase('shelf-a', 10));
        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $this->aliceChanges(static fn () => $case->weight = 25);
        $this->bobFlushes();

        $depots = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'depot'));

        self::assertCount(1, $depots, 'the premise: the depot got a record for what happened inside it');
        self::assertSame('alice', $depots[0]['source']);
    }

    public function testTheTimestampIsTheMomentOfTheChange(): void
    {
        $article = $this->alicePersisted();

        $this->aliceChanges(static fn () => $article->title = 'Alice edited this');
        $this->bobFlushes();

        self::assertSame(self::ALICE, $this->lateRecord()['loggedAt'], 'the record is stamped with the day it was written rather than the day it happened');
    }

    public function testTheIdentifierIsBuiltFromThatMomentToo(): void
    {
        // UUIDv7 carries the timestamp it was built from, so a record stamped with the
        // later moment also sorts after everything that really came later — the history
        // reads in the wrong order even once the date field is right.
        $article = $this->alicePersisted();

        $this->aliceChanges(static fn () => $article->title = 'Alice edited this');
        $this->bobFlushes();

        $ids = array_column($this->documents(), 'id');

        self::assertCount(2, $ids, 'the premise: Alice\'s record and Bob\'s are both there');

        // The first 48 bits of a v7 are the millisecond it was built from, so the id
        // says out loud which moment it took. Read from the id rather than from the
        // order of the two: records of one moment sort at random, and the question here
        // is which moment, not which order.
        self::assertSame(self::millisecondsOf(self::ALICE), self::millisecondsIn($ids[0]), 'the identifier of Alice\'s record carries Bob\'s moment, so the history sorts by when it was written');
        self::assertSame(self::millisecondsOf(self::BOB), self::millisecondsIn($ids[1]));
    }

    public function testAnAbsentActorStaysAbsentRatherThanBecomingTheNextOne(): void
    {
        // A flush with nobody logged in — a console command, a consumer — published
        // during a request that does have somebody. "No actor" is an answer the flush
        // gave, not a question left open for whoever is around later.
        $article = $this->alicePersisted();

        $this->who->actor = null;
        $this->aliceChanges(static fn () => $article->title = 'Nobody edited this');

        $this->who->actor = 'bob';
        $this->bobFlushes();

        self::assertNull($this->lateRecord()['source'], 'a record with no actor was given the next request\'s one');
    }

    public function testWhatAMomentEnricherSaidTravelsWithTheRecordAndAnOrdinaryOneDoesNot(): void
    {
        // The two kinds side by side in the case that tells them apart. Both read the
        // same moving value; the moment enricher is asked in onFlush, the ordinary one
        // when the record is written, and in this flush those are a day and a user
        // apart. The ordinary one describing the later request is not a defect — it is
        // the documented contract, and it is what the other interface exists to avoid.
        $request = new \stdClass();
        $request->route = '/alices-request';

        $this->attachListener(FailurePolicy::Log, null, [
            new class($request) implements MomentEnricherInterface {
                public function __construct(private readonly \stdClass $request)
                {
                }

                public function describe(): array
                {
                    return ['momentRoute' => $this->request->route];
                }

                public function mapping(): array
                {
                    return ['momentRoute' => ['type' => 'keyword']];
                }
            },
            new class($request) implements AuditEnricherInterface {
                public function __construct(private readonly \stdClass $request)
                {
                }

                public function supports(AuditRecord $record): bool
                {
                    return true;
                }

                public function enrich(AuditRecord $record): AuditRecord
                {
                    return $record->withAttributes(['writeRoute' => $this->request->route]);
                }

                public function mapping(): array
                {
                    return ['writeRoute' => ['type' => 'keyword']];
                }
            },
        ]);

        $article = $this->alicePersisted();

        $this->aliceChanges(static fn () => $article->title = 'Alice edited this');

        $request->route = '/bobs-request';
        $this->bobFlushes();

        $late = $this->lateRecord();

        self::assertSame('/alices-request', $late['momentRoute'] ?? null, 'the moment enricher described the request that published the record rather than the one that caused it');
        self::assertSame('/bobs-request', $late['writeRoute'] ?? null, 'the premise: an ordinary enricher runs at write time, which is the contract');
    }

    public function testTheContextBesideALateRecordIsTheOneItsOwnFlushSaw(): void
    {
        // An always-recorded field is context: it says what the row held while the change
        // being recorded happened. For an owner whose own columns did not move, that
        // record is assembled in postFlush — and in the late branch, during a flush that
        // has since moved the field on.
        //
        // Read from the wrong flush, the context describes a state that came after the
        // change it is standing beside, which is the same lie as the wrong actor in a
        // quieter voice.
        $crate = new Crate('C-1');
        $crate->add($item = new CrateItem('SKU-1'));
        $crate->status = 'packed by alice';

        $this->em->persist($crate);
        $this->em->flush();

        $this->gateway->documents = [];

        // Only the line moves, so the crate itself gets no event of its own.
        $this->aliceChanges(static fn () => $item->quantity = 7);

        // And Bob's flush moves the very field that was context for Alice's record.
        $crate->status = 'unpacked by bob';
        $this->bobFlushes();

        $crates = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate'));

        self::assertNotSame([], $crates, 'the premise: the crate got a record for what happened inside it');
        self::assertSame(
            ['old' => 'packed by alice', 'new' => 'packed by alice'],
            $crates[0]['changes']['status'] ?? null,
            'the context beside that record describes the state Bob left',
        );
    }

    private static function millisecondsOf(string $moment): string
    {
        return sprintf('%012x', (int) (new \DateTimeImmutable($moment, new \DateTimeZone('UTC')))->format('Uv'));
    }

    private static function millisecondsIn(string $id): string
    {
        return substr(str_replace('-', '', $id), 0, 12);
    }

    private function alicePersisted(): Article
    {
        $this->em->persist($article = new Article('Alice wrote this'));
        $this->em->flush();

        $this->gateway->documents = [];

        return $article;
    }

    /**
     * Alice's flush, with this listener taken off postFlush for the length of it — which
     * is what a listener registered before it and throwing does.
     */
    private function aliceChanges(callable $change): void
    {
        $ours = $this->ourListener();

        $this->em->getEventManager()->removeEventListener([Events::postFlush], $ours);

        $change();
        $this->em->flush();

        self::assertSame([], $this->documents(), 'the premise: publishing never ran for Alice\'s flush');

        $this->em->getEventManager()->addEventListener([Events::postFlush], $ours);
    }

    /**
     * Another request, another day, another user — and it is this flush that writes what
     * Alice's left behind.
     */
    private function bobFlushes(): void
    {
        $this->who->actor = 'bob';
        $this->when->now = self::BOB;

        $this->em->persist(new Article('Bob writes something'));
        $this->em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function lateRecord(): array
    {
        $documents = $this->documents();

        self::assertNotSame([], $documents, 'nothing was published at all');

        return $documents[0];
    }

    private function ourListener(): AuditSubscriber
    {
        foreach ($this->em->getEventManager()->getListeners(Events::postFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                return $listener;
            }
        }

        self::fail('no audit listener is attached to postFlush');
    }
}
