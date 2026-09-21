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
use Doctrine\ORM\EntityManagerInterface;
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

    public function testAFlushRunningInsideAnotherDoesNotLendItItsMoment(): void
    {
        // Two flushes alive at once, which is the only shape in which the flush number
        // is observable at all — and the shape the first version of this fix got wrong.
        // A lifecycle listener calls flush() while Alice's is still running; that inner
        // flush belongs to whoever is acting by then. What must not happen is the outer
        // flush's records taking the inner one's answers, which is the defect this whole
        // file is about, arriving by a different road.
        $this->em->persist($alice = new Article('Alice wrote this'));
        $this->em->persist($aside = new Article('Something else'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $this->who, $this->when, $aside) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $who,
                private readonly object $when,
                private readonly Article $aside,
            ) {
            }

            public function postUpdate(): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;

                // Another request's worth of difference, from inside Alice's flush.
                $this->who->actor = 'bob';
                $this->when->now = WhoseMomentALateRecordCarriesTest::bobsMoment();

                $this->aside->title = 'Bob changed this from inside';
                $this->em->flush();
            }
        });

        $alice->title = 'Alice edited this';
        $this->em->flush();

        $records = [];

        foreach ($this->documents() as $document) {
            $records[$document['changes']['title']['new'] ?? '?'] = $document;
        }

        self::assertArrayHasKey('Alice edited this', $records, 'the premise: the outer flush was recorded');
        self::assertArrayHasKey('Bob changed this from inside', $records, 'the premise: so was the inner one');

        self::assertSame('alice', $records['Alice edited this']['source'], 'the outer flush was signed by whoever the inner one was running as');
        self::assertSame(self::ALICE, $records['Alice edited this']['loggedAt'], 'and dated by the inner flush too');

        self::assertSame('bob', $records['Bob changed this from inside']['source'], 'the inner flush is its own moment, not a copy of the outer one');
        self::assertSame(self::BOB, $records['Bob changed this from inside']['loggedAt']);
    }

    public function testBothMomentsSurviveWhenTheWholeThingIsPublishedLate(): void
    {
        // The other direction: nothing published at the time — the outer flush's
        // postFlush is swallowed, and the inner one never publishes anyway — so the next
        // flush writes both of them, late. Each stretch has to keep its own moment
        // through that, and a single provenance for the whole batch would flatten them
        // into one.
        $this->em->persist($alice = new Article('Alice wrote this'));
        $this->em->persist($aside = new Article('Something else'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $this->who, $this->when, $aside) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $who,
                private readonly object $when,
                private readonly Article $aside,
            ) {
            }

            public function postUpdate(): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;

                $this->who->actor = 'bob';
                $this->when->now = WhoseMomentALateRecordCarriesTest::bobsMoment();

                $this->aside->title = 'Bob changed this from inside';
                $this->em->flush();
            }
        });

        $this->aliceChanges(static fn () => $alice->title = 'Alice edited this');

        // A third request writes what the two of them left behind.
        $this->who->actor = 'carol';
        $this->when->now = '2026-09-23 09:00:00';

        $this->em->persist(new Article('Carol writes something'));
        $this->em->flush();

        $records = [];

        foreach ($this->documents() as $document) {
            $records[$document['changes']['title']['new'] ?? '?'] = $document;
        }

        self::assertSame(['alice', self::ALICE], [$records['Alice edited this']['source'] ?? null, $records['Alice edited this']['loggedAt'] ?? null], 'the outer flush, published two requests later, is still Alice at her moment');
        self::assertSame(['bob', self::BOB], [$records['Bob changed this from inside']['source'] ?? null, $records['Bob changed this from inside']['loggedAt'] ?? null], 'and the flush that ran inside it is still Bob at his');
        self::assertSame('carol', $records['Carol writes something']['source'] ?? null, 'and the flush that did the writing is only itself');
    }

    public function testWhatTheOuterFlushCollectsAfterTheInnerOneIsStillTheOuterFlushes(): void
    {
        // The half of the previous test that it cannot see. Doctrine works through the
        // entities of one flush in turn, so a nested flush started from the first
        // entity's postUpdate is over by the time the second entity's runs — and
        // everything after it has to be filed under the outer flush again. Without the
        // inner number coming off the stack, the second record is collected under the
        // inner flush's number and published with its actor and its clock.
        $this->em->persist($first = new Article('First'));
        $this->em->persist($second = new Article('Second'));
        $this->em->persist($aside = new Article('Something else'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $this->who, $this->when, $aside) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $who,
                private readonly object $when,
                private readonly Article $aside,
            ) {
            }

            public function postUpdate(): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;

                $this->who->actor = 'bob';
                $this->when->now = WhoseMomentALateRecordCarriesTest::bobsMoment();

                $this->aside->title = 'Bob changed this from inside';
                $this->em->flush();
            }
        });

        $first->title = 'First, edited by Alice';
        $second->title = 'Second, edited by Alice';
        $this->em->flush();

        $records = [];

        foreach ($this->documents() as $document) {
            $records[$document['changes']['title']['new'] ?? '?'] = $document;
        }

        self::assertArrayHasKey('Second, edited by Alice', $records, 'the premise: both of the outer flush\'s entities were recorded');

        self::assertSame('alice', $records['Second, edited by Alice']['source'], 'what the outer flush collected after the inner one finished was filed under the inner flush');
        self::assertSame(self::ALICE, $records['Second, edited by Alice']['loggedAt']);
    }

    public function testAnOwnerSeenOnlyByTheInnerFlushKeepsThatFlushesContext(): void
    {
        // The owner path, where the record does not exist until publish() builds it and
        // the always-recorded context beside it is read from a snapshot. Which snapshot
        // is the question: the crate is touched by the inner flush, and the record is
        // written by the outer one, so reading it under "the flush that is publishing"
        // finds nothing at all.
        $crate = new Crate('C-1');
        $crate->add($item = new CrateItem('SKU-1'));
        $crate->status = 'packed by bob';

        $this->em->persist($crate);
        $this->em->persist($alice = new Article('Alice wrote this'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $this->who, $this->when, $item) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $who,
                private readonly object $when,
                private readonly CrateItem $item,
            ) {
            }

            public function postUpdate(): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;

                $this->who->actor = 'bob';
                $this->when->now = WhoseMomentALateRecordCarriesTest::bobsMoment();

                // Only the line moves, so the crate itself gets no event of its own and
                // its record is built after the commit, by the outer flush.
                $this->item->quantity = 7;
                $this->em->flush();
            }
        });

        $alice->title = 'Alice edited this';
        $this->em->flush();

        $crates = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate'));

        self::assertCount(1, $crates, 'the premise: the crate got a record for what happened inside it');
        self::assertSame('bob', $crates[0]['source'], 'the crate was touched by the inner flush and signed by the outer one');
        self::assertSame(
            ['old' => 'packed by bob', 'new' => 'packed by bob'],
            $crates[0]['changes']['status'] ?? null,
            'the context beside it was read under the flush that published rather than the one that saw it',
        );
    }

    public function testTheListenerKeepsNoMomentsAfterTheFlushThatMadeThem(): void
    {
        // A worker runs for weeks and flushes millions of times. One Provenance per
        // flush, kept forever, is a leak nothing else in this file can see: every
        // assertion here is about a record, and a record is written whether or not the
        // map behind it was emptied. So it is asked of the listener directly.
        $this->em->persist($article = new Article('Alice wrote this'));
        $this->em->flush();

        $article->title = 'Alice edited this';
        $this->em->flush();

        $held = new \ReflectionProperty(AuditSubscriber::class, 'provenance');
        $collecting = new \ReflectionProperty(AuditSubscriber::class, 'collecting');

        self::assertSame([], $held->getValue($this->ourListener()), 'a moment stayed behind after the flush that settled it was published');
        self::assertSame([], $collecting->getValue($this->ourListener()), 'and the flush it belonged to is still on the stack');
    }

    /**
     * The moment the nested flush belongs to, readable from inside an anonymous class.
     */
    public static function bobsMoment(): string
    {
        return self::BOB;
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
