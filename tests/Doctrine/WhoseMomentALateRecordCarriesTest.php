<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
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

    public function testAnOwnerBothFlushesTouchedBelongsToTheOneThatStarted(): void
    {
        // One owner, one record, two flushes that moved something inside it. The record
        // goes with the flush that first saw the owner rather than the last: it is the
        // operation that started touching it, and it is the one whose commit the whole
        // history hangs off. The test beside this one is the other case — an owner only
        // the inner flush saw keeps the inner moment — and together they say which of
        // the two sightings counts.
        $crate = new Crate('C-1');
        $crate->add($first = new CrateItem('SKU-1'));
        $crate->add($second = new CrateItem('SKU-2'));

        $this->em->persist($crate);
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $this->who, $this->when, $second) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $who,
                private readonly object $when,
                private readonly CrateItem $second,
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

                $this->second->quantity = 9;
                $this->em->flush();
            }
        });

        $first->quantity = 7;
        $this->em->flush();

        $crates = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate'));

        self::assertCount(1, $crates, 'the premise: one owner, one record, whatever moved inside it');
        self::assertSame('alice', $crates[0]['source'], 'the owner went with the flush that touched it last rather than the one that started');
    }

    public function testAnOwnerTheInnerFlushOnlyEmptiedIsThatFlushesToo(): void
    {
        // The third road into the map of who saw an owner: a collection emptied. An
        // owner nothing else touched is remembered there and nowhere else, so forgetting
        // to note the flush costs it its moment — and the outer flush, which publishes,
        // would sign it.
        $crate = new Crate('C-1');
        $crate->add(new CrateItem('SKU-1'));

        $this->em->persist($crate);
        $this->em->persist($alice = new Article('Alice wrote this'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $this->who, $this->when, $crate) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $who,
                private readonly object $when,
                private readonly Crate $crate,
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

                $this->crate->items->clear();
                $this->em->flush();
            }
        });

        $alice->title = 'Alice edited this';
        $this->em->flush();

        $crates = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate'));

        self::assertNotSame([], $crates, 'the premise: emptying the collection was recorded against its owner');
        self::assertSame('bob', $crates[0]['source'], 'the owner was signed by the flush that published rather than the one that emptied it');
        self::assertSame(self::BOB, $crates[0]['loggedAt']);
    }

    public function testAnOwnerIsStillTheFirstFlushesWhenTheSecondEmptiesItsCollection(): void
    {
        // The same rule on the other road into that map: an owner is remembered when a
        // collection of its is emptied, and the inner flush emptying one must not take
        // an owner the outer flush had already touched.
        $crate = new Crate('C-1');
        $crate->add($first = new CrateItem('SKU-1'));
        $crate->add(new CrateItem('SKU-2'));

        $this->em->persist($crate);
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $this->who, $this->when, $crate) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $who,
                private readonly object $when,
                private readonly Crate $crate,
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

                $this->crate->items->clear();
                $this->em->flush();
            }
        });

        $first->quantity = 7;
        $this->em->flush();

        $crates = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate'));

        self::assertNotSame([], $crates, 'the premise: the crate got a record');
        self::assertSame('alice', $crates[0]['source'], 'emptying a collection in the inner flush took an owner the outer one had already touched');
    }

    public function testARemovalAskedForBeforeTheFlushStillBelongsToIt(): void
    {
        // preRemove runs at $em->remove(), not at the flush — so when the record is taken
        // there is no flush to belong to yet. Carrying that answer forward made a removal
        // published late take the publishing request's moment, which is the defect of
        // this whole release, kept alive for deletions only.
        $article = $this->alicePersisted();

        // The remove() is asked for before anything flushes.
        $this->em->remove($article);

        $this->aliceChanges(static function (): void {});
        $this->bobFlushes();

        $removals = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['event'] === 'remove'));

        self::assertCount(1, $removals, 'the premise: the removal was recorded');
        self::assertSame('alice', $removals[0]['source'], 'a removal asked for before the flush was signed by whoever published it');
        self::assertSame(self::ALICE, $removals[0]['loggedAt']);
    }

    public function testAnInnerFlushThatDiedDoesNotOwnWhatTheOuterOneDoesNext(): void
    {
        // The inner flush of the earlier tests reaches its own postFlush and says so.
        // This one is refused in onFlush by a listener the application catches, so it
        // never comes back at all — and everything the outer flush collects afterwards
        // was being filed under a flush that never happened. The assertion is on the
        // SECOND entity: a stack that is tidy by the end is no use if the record built in
        // the middle already has the wrong moment on it.
        $this->em->persist($first = new Article('First'));
        $this->em->persist($second = new Article('Second'));
        $this->em->persist($aside = new Article('Aside'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            private int $seen = 0;

            public function onFlush(): void
            {
                // The outer flush passes; the inner one is refused.
                if (++$this->seen === 2) {
                    throw new \DomainException('this entity may not be saved');
                }
            }
        });

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

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // what an application does when a listener refuses its inner flush
                }
            }
        });

        $first->title = 'First, edited by Alice';
        $second->title = 'Second, edited by Alice';
        $this->em->flush();

        $records = [];

        foreach ($this->documents() as $document) {
            $records[$document['changes']['title']['new'] ?? '?'] = $document;
        }

        self::assertArrayHasKey('Second, edited by Alice', $records, 'the premise: the outer flush recorded both of its entities');
        self::assertSame('alice', $records['Second, edited by Alice']['source'], 'what the outer flush did after the inner one died was filed under the flush that never happened');
        self::assertSame(self::ALICE, $records['Second, edited by Alice']['loggedAt']);
    }

    public function testAFlushStartedAfterADeadInnerOneDoesNotPublishTheOuterOnesWork(): void
    {
        // The same dead inner flush as above, and then the thing an application actually
        // does about it: catch, and flush again to record that it failed. That second
        // flush is a perfectly ordinary inner flush — the outer one is still walking its
        // entities, its transaction is still open — but it begins at the level the dead
        // one pushed at, and reading that as "the flush below me is gone" made it publish
        // everything the LIVE outer flush had collected. Before the commit, which is the
        // one thing postFlush exists to wait for, and signed by whoever was acting in the
        // listener that started the dead one.
        $this->em->persist($first = new Article('First'));
        $this->em->persist($second = new Article('Second'));
        $this->em->persist($aside = new Article('Aside'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            private int $seen = 0;

            public function onFlush(): void
            {
                // The outer flush passes, the inner one is refused, and the flush that
                // logs the refusal passes again.
                if (++$this->seen === 2) {
                    throw new \DomainException('this entity may not be saved');
                }
            }
        });

        $published = [];

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $this->who, $this->when, $aside, $this->gateway, $published) {
            private bool $ran = false;

            /** @param list<int> $published */
            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $who,
                private readonly object $when,
                private readonly Article $aside,
                private readonly object $gateway,
                private array &$published,
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

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // what an application does when a listener refuses its inner flush
                }

                $this->aside->title = 'Bob logged the refusal';
                $this->em->flush();

                // Counted here, inside the outer flush, because by the end everything is
                // published either way and the end is not where this goes wrong.
                $this->published[] = \count($this->gateway->documents['audit_log'] ?? []);
            }
        });

        $first->title = 'First, edited by Alice';
        $second->title = 'Second, edited by Alice';
        $this->em->flush();

        self::assertSame([0], $published, "the outer flush's records were published from inside it, before its own commit");

        $signed = array_map(
            static fn (array $d): array => [$d['changes']['title']['new'] ?? '?', $d['source']],
            $this->documents(),
        );

        // The whole history of the operation, and nothing else. Alice's two changes are
        // hers although the flush that logged the refusal carried one of them out, and
        // that flush's own change is Bob's.
        self::assertSame([
            ['First, edited by Alice', 'alice'],
            ['Second, edited by Alice', 'alice'],
            ['Bob logged the refusal', 'bob'],
        ], $signed);
    }

    public function testAnEntityUpdatedTwiceInOneOperationHasTwoRecords(): void
    {
        // The other side of recording a re-announced statement once. Here both
        // announcements are real: the flush writes One -> Two, a listener changes the
        // value again and flushes, and that flush writes Two -> Three. Two statements,
        // two steps, and a history that skipped the first would be a row's value
        // appearing from nowhere.
        //
        // What tells this from the re-announcement is the unit of work: it still holds a
        // change set for the entity here, and holds nothing the second time the same
        // statement is announced.
        $this->em->persist($article = new Article('One'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $article, $this->who) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly Article $article,
                private readonly object $who,
            ) {
            }

            public function postUpdate(): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;
                $this->who->actor = 'bob';
                $this->article->title = 'Three';
                $this->em->flush();
            }
        });

        $article->title = 'Two';
        $this->em->flush();

        self::assertSame([
            [['One', 'Two'], 'alice'],
            [['Two', 'Three'], 'bob'],
        ], array_map(
            static fn (array $d): array => [[$d['changes']['title']['old'], $d['changes']['title']['new']], $d['source']],
            $this->documents(),
        ), 'a real second update of the same entity was folded into the first one');
    }

    public function testAnOwnerFirstSeenByAFlushThatDiedBelongsToTheOneThatSurvives(): void
    {
        // The owner is reached only from inside a collection, and the flush that sees it
        // first is the one that is refused: the outer flush never touches the crate, the
        // inner one plans a line and dies, and the flush after it plans the line again
        // and carries it out. "Whoever saw it first" then names a flush that never
        // happened, whose moment went away with it -- and the record, having no moment of
        // its own to carry, took the moment of whenever it was finally written.
        $crate = new Crate('C-1');
        $crate->add($item = new CrateItem('SKU-1'));
        $this->em->persist($crate);
        $this->em->persist($article = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            private int $seen = 0;

            public function onFlush(): void
            {
                if (++$this->seen === 2) {
                    throw new \DomainException('the inner flush is refused');
                }
            }
        });

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $this->who, $item) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $who,
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
                $this->item->quantity = 9;

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // what an application does when a listener refuses its inner flush
                }

                $this->who->actor = 'carol';
                $this->item->quantity = 3;
                $this->em->flush();

                // And somebody else is acting by the time the outer flush publishes, so
                // "ask again now" and "the flush that really did it" are different
                // answers.
                $this->who->actor = 'dave';
            }
        });

        $article->title = 'Trigger, edited';
        $this->em->flush();

        $crates = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate'));

        self::assertCount(1, $crates, 'the premise: the crate got one record for what happened inside it');
        self::assertSame('carol', $crates[0]['source'], 'the crate was signed by whoever was acting when it was written rather than when it changed');
    }

    public function testADeadInnerFlushDoesNotReachARemovalTheOuterOneMade(): void
    {
        // The two together, which is where a fix for either alone would still be wrong:
        // an inner flush dies, the outer one then removes something, and the whole lot is
        // published two requests later.
        $this->em->persist($article = new Article('Alice wrote this'));
        $this->em->persist($doomed = new Article('Alice removes this'));
        $this->em->persist($aside = new Article('Aside'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            private int $seen = 0;

            public function onFlush(): void
            {
                if (++$this->seen === 2) {
                    throw new \DomainException('this entity may not be saved');
                }
            }
        });

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

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // caught, as an application would
                }
            }
        });

        $ours = $this->ourListener();
        $this->em->getEventManager()->removeEventListener([Events::postFlush], $ours);

        $article->title = 'Alice edited this';
        $this->em->remove($doomed);
        $this->em->flush();

        $this->em->getEventManager()->addEventListener([Events::postFlush], $ours);

        // A third request writes what that flush left behind.
        $this->who->actor = 'carol';
        $this->when->now = '2026-09-23 09:00:00';

        $this->em->persist(new Article('Carol writes something'));
        $this->em->flush();

        $removals = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['event'] === 'remove'));

        self::assertCount(1, $removals, 'the premise: the removal survived to be published');
        self::assertSame('alice', $removals[0]['source'], 'the removal took the moment of the inner flush that died, or of the request that published it');
        self::assertSame(self::ALICE, $removals[0]['loggedAt']);
    }

    public function testAClearBetweenTwoOperationsDoesNotHandTheSecondTheFirstsMoment(): void
    {
        // An import loop: a flush whose publishing was swallowed, then $em->clear(), then
        // the next operation. The clear drops the records that flush collected — it
        // always has, because they describe rows that may have been rolled back — and it
        // has to drop what went with them. Leaving the numbers behind meant the next
        // flush's first record, at position zero, was matched with the moment of the
        // records that had just been thrown away: a new change written as somebody else
        // at a time before it happened.
        $article = $this->alicePersisted();

        $this->aliceChanges(static fn () => $article->title = 'Alice edited this');

        $this->em->clear();

        $this->who->actor = 'bob';
        $this->when->now = self::BOB;

        $this->em->persist(new Article('Bob writes something'));
        $this->em->flush();

        $documents = $this->documents();

        self::assertCount(1, $documents, 'the premise: the cleared flush is gone and only Bob is left');
        self::assertSame('bob', $documents[0]['source'], 'Bob is change was written as Alice, from before the clear');
        self::assertSame(self::BOB, $documents[0]['loggedAt']);
    }

    public function testOneRunFailingDoesNotThrowAwayTheRecordsOfTheOthers(): void
    {
        // Under on_failure: throw a refused record leaves writeAll() as an exception.
        // Publishing walks the records in stretches that share a moment, and stopping at
        // the first exception dropped every stretch after it — records of changes that
        // are already committed, thrown away because something else could not be written.
        // A single writeAll() never did that: it tries every record and raises after.
        $refusing = new class implements AuditEnricherInterface {
            public bool $armed = false;
            public int $seen = 0;

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                // The outer flush's record is collected first, so this refuses the first
                // stretch and leaves the one the inner flush made.
                if ($this->armed && ++$this->seen === 1) {
                    throw new \RuntimeException('this record cannot be enriched');
                }

                return $record;
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $this->attachListener(FailurePolicy::Throw, null, [$refusing]);

        $this->em->persist($article = new Article('Alice wrote this'));
        $this->em->persist($aside = new Article('Aside'));
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

        $refusing->armed = true;
        $article->title = 'Alice edited this';

        try {
            $this->em->flush();
            self::fail('on_failure: throw did not reach the caller');
        } catch (WriteFailedException $e) {
            // Which is what the caller asked for -- and about the record that was
            // actually refused. "Something was thrown" would pass just as well if the
            // exception were the last stretch's, or one this method wrapped itself
            // around a run that had nothing wrong with it.
            self::assertSame($article->id, $e->record?->objectId, 'the exception the caller got is not about the record that was refused');
        }

        self::assertSame(
            ['Bob changed this from inside'],
            array_map(static fn (array $d): string => $d['changes']['title']['new'] ?? '?', $this->documents()),
            'the stretch after the one that failed was thrown away with it',
        );
    }

    public function testTheExceptionTheCallerGetsIsTheFirstRunsAndNotTheLast(): void
    {
        // Every stretch refused, so there is a choice about which failure comes out. It
        // is the first, because that is the one writeAll() reported at the time; the
        // later ones are reported too, and raising the last of them would tell the caller
        // about the record furthest from what went wrong. The same order a single
        // writeAll() keeps, for the same reason.
        $refusing = new class implements AuditEnricherInterface {
            public bool $armed = false;

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                if ($this->armed) {
                    throw new \RuntimeException('this record cannot be enriched');
                }

                return $record;
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $this->attachListener(FailurePolicy::Throw, null, [$refusing]);

        $this->em->persist($article = new Article('Alice wrote this'));
        $this->em->persist($aside = new Article('Aside'));
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

        $refusing->armed = true;
        $article->title = 'Alice edited this';

        try {
            $this->em->flush();
            self::fail('on_failure: throw did not reach the caller');
        } catch (WriteFailedException $e) {
            self::assertSame($article->id, $e->record?->objectId, 'the caller was told about a later stretch than the one that failed first');
        }

        self::assertSame([], $this->documents(), 'the premise: every stretch was refused');
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
        $flushes = new \ReflectionProperty(AuditSubscriber::class, 'flushes');

        self::assertSame([], $held->getValue($this->ourListener()), 'a moment stayed behind after the flush that settled it was published');
        self::assertSame([], $flushes->getValue($this->ourListener()), 'and the flush it belonged to is still on the stack');
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
