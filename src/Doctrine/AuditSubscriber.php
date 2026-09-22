<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine;

use Borsche\ElasticsearchAuditBundle\Exception\DeclarationMistake;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\AuditableInterface;
use Borsche\ElasticsearchAuditBundle\Contract\TracksCollectionElementsInterface;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadata;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditOrigin;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\Provenance;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Doctrine\Persistence\ObjectManager;

/**
 * Records entity lifecycle events during flush().
 *
 * The records are built while the unit of work still knows the change sets —
 * postPersist, postUpdate, and for removals preRemove (while the entity has its
 * identifier) — but written only in postFlush, once the transaction committed.
 * A flush that fails half-way rolls its INSERTs back and closes the manager;
 * onClear then drops what was collected, so the history never describes a state
 * the database did not reach.
 *
 * A flush inside an outer transaction (wrapInTransaction) is the one case where
 * postFlush still precedes the real commit; the records are sent then anyway,
 * since nothing later would tell the listener the transaction ended.
 *
 * What goes wrong while building a record — a declaration naming an unknown
 * field, an identifier the listener cannot represent — is handed to the writer's
 * failure policy like a failed write, so with the default "log" the flush goes
 * through and the mistake is in the log.
 *
 * @internal register through the bundle configuration (doctrine.enabled)
 */
final class AuditSubscriber
{
    public const EVENTS = [Events::onFlush, Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove, Events::postFlush, Events::onClear];

    /**
     * The number that means "no flush is collecting".
     *
     * Rather than null, which was the same statement in a second shape: a bucket keyed by
     * the flush that filled it needs a key whatever the answer is, so every one of those
     * had a `?? 0` beside it saying what null meant there — a dozen expressions whose
     * other arm nothing could reach, because inside a flush there is always one. The
     * counter starts at one, so zero is free to say it once.
     */
    private const NO_FLUSH = 0;

    /**
     * Which classes have already been checked against Doctrine's mapping.
     *
     * Attribute declarations only. They are fixed per class — the same names, the same
     * representers, the same tracked fields for every instance — so checking one
     * instance answers for all of them.
     *
     * An interface declaration is not cached at all, and the key is why: it held the
     * class plus the *names* the declaration used, while what the checks read is more
     * than the names — whether an audited association has a representer, what is always
     * recorded, which fields of an element are tracked. Two instances answering with the
     * same names and a different representer shared one key, and the second one walked
     * past the check that exists for it. Fingerprinting all of it would be a second
     * declaration format to keep in step; not caching what an instance is free to change
     * is the version that cannot drift.
     *
     * @var array<string, true>
     */
    private array $checkedTracking = [];

    /** @var list<AuditRecord> records built during the current flush, written after its commit */
    private array $pending = [];

    /**
     * Which flush collected each pending record, by the same index.
     *
     * Parallel to $pending rather than folded into it: publish() replaces entries by
     * index when it merges what happened inside a collection into its owner's record,
     * and an index that means two things is an index that drifts.
     *
     * @var list<int>
     */
    private array $pendingFlush = [];

    /** @var array<int, AuditRecord> records for entities being removed, keyed by object id */
    private array $pendingRemovals = [];

    /**
     * Which flush saw each owner whose record is built after the commit, by object id.
     *
     * An owner with no lifecycle event of its own has no record until publish() makes
     * one, so there is nothing else carrying its number — and it needs one for the same
     * reason every other record does: a nested flush must not lend it its moment or its
     * context.
     *
     * @var array<int, int>
     */
    private array $ownerFlush = [];

    /**
     * Changes inside tracked collection elements, by the owner's object id and then by
     * the flush that collected them.
     *
     * The flush is inside rather than outside because the owner is what the record is
     * built for, and the object beside it is the only thing some flushes have to build
     * one from. What the number does is keep two flushes' answers about the same field
     * apart: they share a key — "lines.42.quantity" names a column, not an occasion — so
     * one bucket per owner meant the later flush simply wrote over the earlier one's,
     * whichever of the two turned out to be real.
     *
     * Read back by the stamp each entry carries, the highest per key winning, which is
     * the answer one bucket gave by being written over.
     *
     * @var array<int, array{0: object, 1: array<int, array<string, array{at: int, change: Change}>>}>
     */
    private array $elementChanges = [];

    /**
     * Elements a tracked collection gained or lost, by the owner's object id and then by
     * the flush that collected them. {@see self::$elementChanges} for why the number is
     * where it is.
     *
     * @var array<int, array{0: object, 1: array<int, array<string, array{at: int, element: object, added: bool, field: string, represent: (callable(object): mixed)|null, value: mixed, deferred: bool, id: int|string|null}>>}>
     */
    private array $elementMembership = [];

    /** @var array<int, int> the pending lifecycle record of an entity — create or update — so what its elements did can be folded into it */
    private array $pendingIndexByEntity = [];

    /**
     * The first failure raised while this flush's records were being assembled.
     *
     * Reporting a failure raises under `on_failure: throw` — that is what the setting is
     * for — and the records of a flush are assembled one by one, after the commit. So a
     * representer of the application's that throws for one collection used to take the
     * whole flush's history with it: the exception left the loop, the records already
     * built never reached the transport, and postFlush's `finally` dropped them. The row
     * of an entity with nothing wrong with it was in the database and its history was
     * gone.
     *
     * Held here instead, and raised once the good records are out — the same order
     * AuditWriter::writeAll() keeps for the same reason. The reporting itself still
     * happens where it happened: the event is dispatched and the line is logged as the
     * failure occurs, and only the raising waits.
     */
    private ?\Throwable $failureWhileBuilding = null;

    /**
     * What the always-recorded fields of an audited entity held when the flush began,
     * keyed by object id.
     *
     * Context beside a change has to describe the row, and the object stops describing
     * the row the moment a postUpdate listener touches it — post events are explicitly
     * not part of that flush's persistence. A field the flush itself wrote is read from
     * the change set instead, which is recomputed when a preUpdate listener corrects it;
     * this is for the ones it did not write, where the row keeps what it had.
     *
     * Keyed by the flush that saw them, and then by the entity. By the flush because a
     * flush whose publishing was swallowed is written by the next one, and that next one
     * is collecting its own context while the old records are still waiting: keyed by
     * the entity alone, the second visit to the same object overwrote what the first had
     * kept. Today's order of calls happens to prevent it; a number no other flush holds
     * prevents it whatever the order becomes.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $contextAsFlushed = [];

    /**
     * How many things one operation has collected about the elements of a tracked
     * collection, counting up.
     *
     * Every one of them is stamped with it, and the merge keeps the highest stamp per
     * key. A flush number cannot do that job: it says when a flush BEGAN, and an outer
     * flush writes its rows after an inner one it started -- so an outer flush recording
     * the value the column finally took lost to the inner flush's earlier value, which had
     * the higher number. Doctrine recomputes a change set in preUpdate and the statement
     * follows it, so the last thing recorded about a line is the one the row agrees with.
     *
     * Let go with the flush: the stamps only ever order what one operation collected, and
     * nothing compares two operations' stamps.
     */
    private int $wrote = 0;

    /**
     * Which flush this is, counting from the first one this listener sees.
     *
     * Only ever used as a key. It does not survive the process and does not mean
     * anything outside it — two workers number their flushes the same way and never
     * compare notes.
     *
     * Counting from one, so that zero is free to mean "no flush is collecting" wherever
     * something has to be filed under a number and there is none.
     */
    private int $flush = 0;

    /**
     * When each flush happened and who was acting, kept until its records are written.
     *
     * The records of a flush can be written by a later one, and everything the writer
     * takes from "now" — the timestamp, the actor, the identifier built from the
     * timestamp — would then be the later flush's. What is kept here is the answer as it
     * stood where the change happened, handed to the writer when the records finally go.
     *
     * There is always one. Settling it can fail — a clock or a resolver that threw,
     * reported through the failure policy where it happened — and what a failure costs is
     * a weaker answer rather than no answer: the system clock in place of the configured
     * one, an actor of null. No answer used to mean the records of that flush were
     * completed wherever they were finally written, which for a flush whose publishing
     * was swallowed is a later request, with somebody else logged in.
     *
     * @var array<int, Provenance>
     */
    private array $provenance = [];

    /**
     * The entity manager the flush on the stack belongs to, held weakly.
     *
     * Read when a later flush finds that state still there, to tell two situations
     * apart that look identical from here: a flush abandoned before it committed, and a
     * flush that committed and then never reached this listener's postFlush because
     * somebody else's postFlush listener threw first. `UnitOfWork::commit()` closes the
     * manager on any failure inside its try — that is where a rollback happens — so a
     * manager still open is a manager whose transaction went through.
     */
    /** @var \WeakReference<EntityManagerInterface>|null */
    private ?\WeakReference $flushingManager = null;

    /**
     * What an owning collection held before this flush empties it, keyed by the owner's
     * object id and then by field.
     *
     * Doctrine has two ways of taking a whole collection away, and neither leaves the
     * usual trace. `clear()` schedules the collection for deletion and then calls
     * takeSnapshot(), so by the time anything can look the collection is empty, its
     * snapshot is empty and isDirty() is false — the record was built from that and said
     * nothing at all, while the join rows were deleted. Assigning a fresh collection
     * over the property schedules the old one for deletion too, and the new one's
     * snapshot starts empty, so the old side went missing the same way.
     *
     * Both are visible in onFlush, and only there: the deletion list is cleared with the
     * rest of the unit of work when the flush ends.
     *
     * The owner is kept beside what its collection held, and not only its object id,
     * because this map is the only news some flushes have. `clear()` dirties nothing on
     * the owner — it schedules the collection for deletion and takes its snapshot in the
     * same breath — so Doctrine raises no lifecycle event for it, and a record built
     * only inside those is a record that never exists. postFlush builds one from here
     * instead, and needs the entity to build it from.
     *
     * Keyed by the owner's object id and then by the flush that saw the emptying, for
     * the reason {@see self::$elementChanges} gives.
     *
     * @var array<int, array{0: object, 1: array<int, array<string, list<object>>>}>
     */
    private array $emptiedCollections = [];

    /**
     * Doctrine's change sets as they stood in onFlush, keyed by object id.
     *
     * A record is built in postUpdate, and by then the unit of work may no longer hold
     * the change set: a flush inside any lifecycle listener ends in
     * postCommitCleanup(), which empties entityChangeSets — of the flush still running
     * too. The listener that did it need not be ours, need not be aware of us, and
     * leaves nothing in any log. Whoever reads the history simply finds an update whose
     * "changes" are empty.
     *
     * What this is, and what it is not: the listener keeps its own state across the
     * reentrant flushes it has met, so a trail does not go blank because somebody's
     * listener saved something. It is not a claim that reentrant flushes work —
     * Doctrine's own documentation calls flushing from a flush event strongly
     * discouraged, says the UnitOfWork was not designed for it, and warns that updates
     * can be lost or half-applied. The bundle defends its own records; the entities in
     * that flush are between the application and Doctrine, and the warning below says
     * where to move the work.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $changeSets = [];

    /**
     * Which flush computed each of those change sets, and the entity it was about, by
     * the same object id.
     *
     * Only ever read when a flush turns out never to have happened -- to know whose
     * snapshot is whose, and to reach the entity the row belongs to, which an object id
     * cannot do. Beside the map rather than inside it because every reader of a change
     * set wants the change set, and a shape that makes them all unwrap something is a
     * shape that spreads.
     *
     * The claim lasts until the owning flush writes the row; {@see
     * theRowNowMatchesTheObject()} lets it go.
     *
     * @var array<int, array{entity: object, flush: int}>
     */
    private array $changeSetFlush = [];

    /**
     * What the row still held, for an entity a flush computed a change set for and then
     * never wrote.
     *
     * Computing a change set is not free of consequence: Doctrine takes the new values to
     * be the entity's original data from then on. So a flush refused in its own onFlush
     * leaves the unit of work believing the row holds what it was about to write, and the
     * flush that really carries the change out a moment later reports it as starting from
     * there. Measured: a title the column took straight from "One" to "Three" was recorded
     * as going from "Two", a value the column never held — and the same for a line inside
     * a tracked collection, the two roads meeting here because both are built from the
     * change set above.
     *
     * Kept when the flush is discarded, where its snapshot is still the latest one, and
     * spent field by field by whichever flush computes a change set naming that field
     * next. Field by field because a flush plans several and the flush that recovers may
     * change only one: taking the whole entry then threw away a correction nobody had
     * used, and the next change to that other field started from a value the column never
     * held.
     *
     * **Not per flush, and not let go with one.** This is what the ROW holds, and a row
     * does not care which flush is collecting: an application that catches a refusal and
     * retries at the top level ends its first flush entirely before the second begins, and
     * a correction let go in between is a correction that was needed one line later. It
     * lives until something writes the entity or the manager is cleared.
     *
     * A WeakMap rather than a map keyed by object id, because it now outlives the
     * operation that filled it: PHP hands a freed object's id to the next one, and an
     * entry that outlived its entity would correct a different row entirely. It also
     * means an entity nobody holds any more takes its entry with it.
     *
     * @var \WeakMap<object, array<string, mixed>>
     */
    private \WeakMap $neverWritten;

    /**
     * Whether this flush began by publishing what an earlier one had left.
     *
     * Which is the one situation where a collection deletion on the schedule may already
     * have been carried out: a flush whose postFlush somebody swallowed never reaches
     * postCommitCleanup(), so its deletions stay listed and this flush is handed them
     * again, with the old collection's snapshot still full. Recorded again, that became a
     * second document about rows this operation never touched.
     *
     * A flag rather than asking every emptying, because asking costs a query and an
     * ordinary emptying has nothing to doubt. {@see
     * HowOftenTheListenerAsksTheDatabaseTest}, which is where the cost of this listener is
     * written down.
     */
    private bool $theLastFlushWasPublishedLate = false;

    /** Reported once per flush: a hundred entities would otherwise say the same thing a hundred times. */
    private bool $reportedLostChangeSets = false;

    /**
     * The transaction nesting level each flush that is still open started at.
     *
     * A flush called from inside a lifecycle listener dispatches onFlush and postFlush
     * of its own, and the inner postFlush must not throw away what the outer flush
     * captured — the outer one is still walking its entities and has yet to build its
     * records. So the snapshot is dropped only when the outermost flush ends.
     *
     * Counting flushes was enough only while every onFlush was answered by a postFlush,
     * and one is not: UnitOfWork::commit() dispatches onFlush, then beginTransaction(),
     * and only then enters the try whose catch closes the manager. A listener behind
     * this one that throws in onFlush — a validation veto is the usual reason — leaves
     * no onClear, no postFlush and an open manager, and a bare counter then reads every
     * later flush as nested: the trail goes silent for the rest of the process, and
     * nothing anywhere says why. The level says what a counter cannot. An inner flush
     * runs inside the outer one's transaction, so it always starts deeper; a flush that
     * starts no deeper than the one above it on this stack proves that one is gone.
     *
     * All of which holds because every flush this listener sees runs on one connection:
     * it is attached with `doctrine.event_listener` for the configured
     * `doctrine.connection` and hears nothing from any other. Two entity managers
     * sharing that connection share its nesting level too, so their flushes stack the
     * same way; an entity manager on a different connection never reaches here at all.
     *
     * And the flush's own number with it, because the two answer one question between
     * them: which flush is collecting right now. They were two stacks with one lifetime
     * and different rules for coming off — the levels unwound against the transaction,
     * the numbers popped one at a time in postFlush — and an inner flush refused in
     * onFlush never reaches postFlush at all. Its number stayed on top, and everything
     * the outer flush collected afterwards was filed under a flush that never happened.
     *
     * Nothing pops this. {@see collectingNow()} discards whatever the current
     * transaction level says is no longer live, every time it is asked, so a flush that
     * died without a word is gone by the next question rather than by the next postFlush.
     *
     * And whether Doctrine got as far as running statements for it, which is the other
     * half of what a flush leaving state behind has to be asked. An open manager is most
     * of the answer and not all of it: a listener that throws in *onFlush* — a validation
     * veto, the usual reason — aborts the flush before `UnitOfWork::commit()` enters the
     * try whose catch closes the manager, so it leaves an open manager and nothing
     * written.
     *
     * In the entry rather than in a flag of its own, because the question is about one
     * flush and a flag answered for all of them at once. A dead inner flush left the
     * outer one's statements standing as its own proof, which is how what it planned and
     * never carried out came to be published as history. What the flag could not say is
     * which flush ran, and that is exactly what has to be known to take one flush's work
     * away and leave the rest.
     *
     * Filled by any post-statement event at all, for every entity rather than the audited
     * ones. What a tracked collection said is deliberately not evidence of it: that is
     * collected in onFlush, before anything is written, and reading the two as the same
     * kind of proof is how a flush a veto aborted came to have its history published, and
     * then published again by the flush that really wrote it.
     *
     * @var list<array{level: int, flush: int, ran: bool}>
     */
    private array $flushes = [];

    public function __construct(
        private readonly AuditWriter $writer,
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly bool $skipEmptyUpdates = true,
        private readonly ValueComparatorInterface $comparator = new ValueComparator(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->neverWritten = new \WeakMap();
    }

    private readonly LoggerInterface $logger;

    /**
     * The change sets are computed and nothing has been written yet: the only moment
     * where what changed inside the elements of a tracked collection can be seen.
     *
     * The unit of work already knows which entities changed, so no collection is loaded
     * to find out — an element that nobody touched is simply not in the list, and a
     * collection whose elements are all untouched costs nothing at all.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = self::entityManagerOf($args->getObjectManager());

        if ($em === null) {
            return;
        }

        // This flush's number first, before anything else reads or writes per-flush
        // state. beginFlush() below may publish what the *previous* flush left — it
        // knows its own number and reads that — and everything collected from here on
        // is filed under this one. Two different numbers is what makes the isolation
        // structural: three of this week's defects lived in per-flush state whose
        // correctness rested on the order of two calls, and no rearrangement of these
        // lines can make one flush's moment overwrite another's.
        $flush = ++$this->flush;

        $this->beginFlush($em, $flush);

        $this->provenance[$flush] = $this->writer->provenance();

        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $element) {
            // Taken for every update, not only the audited ones: deciding that here
            // would mean reading each entity's declaration first. What it costs is
            // measured rather than assumed - about 60 bytes per entity, the array's own
            // structure, because PHP shares the values rather than copying them. The
            // figure and the flush it came from are in the README.
            $this->rememberChangeSet($element, $uow->getEntityChangeSet($element), $flush);
            $this->rememberContext($em, $element, $flush);

            $this->collectElementChanges($em, $element, $flush);
        }

        // A line added to or taken from an inverse collection never makes the collection
        // itself dirty — Doctrine tracks the owning side, which is the line's own
        // reference back. The unit of work knows about it all the same.
        foreach ($uow->getScheduledEntityInsertions() as $element) {
            // An insertion's change set dies in the same cleanup as an update's: a
            // create whose postPersist runs after somebody's nested flush would
            // otherwise say an entity appeared with no values at all.
            $this->rememberChangeSet($element, $uow->getEntityChangeSet($element), $flush);
            $this->rememberContext($em, $element, $flush);

            $this->collectElementChanges($em, $element, $flush, added: true);
        }

        foreach ($uow->getScheduledEntityDeletions() as $element) {
            $this->collectElementChanges($em, $element, $flush, added: false);
        }

        $this->rememberWhatIsBeingEmptied($em, $flush);
    }

    /**
     * Keeps the always-recorded fields as the flush found them.
     *
     * Only what a declaration names, and only for audited entities — the declaration is
     * read here anyway, a line further down, for the elements this entity may own.
     *
     * Filed under the number it is handed and not under the counter. The two are the same
     * from onFlush, where the flush collecting is the last one to have been given a
     * number, and they are not from postUpdate: a flush that ran in between has moved the
     * counter on, and the owner's context would then be written under that flush's number
     * while everything that reads it asks under the owner's. No fixture reaches the
     * difference, which is why this is a statement about the method rather than a fix
     * with a regression behind it — a method that takes a flush number and uses a
     * different one is a second rule waiting for the arrangement that tells them apart.
     */
    private function rememberContext(EntityManagerInterface $em, object $entity, int $flush): void
    {
        try {
            $metadata = $this->metadataFactory->for($entity);
        } catch (\Throwable) {
            // A declaration this listener cannot read is reported where it always was —
            // on the path that builds the record, through the failure policy. Raising it
            // here would take the flush down for a mistake the policy is allowed to log.
            return;
        }

        if ($metadata === null || $metadata->alwaysRecorded === []) {
            // Written all the same, and empty: "asked, and there was nothing" is an
            // answer, and the callers that ask once per flush need to see it.
            $this->contextAsFlushed[$flush][spl_object_id($entity)] = [];

            return;
        }

        $classMetadata = $em->getClassMetadata($entity::class);
        $context = [];

        foreach ($metadata->alwaysRecorded as $field) {
            if ($classMetadata->hasField($field)) {
                $context[$field] = $classMetadata->getFieldValue($entity, $field);
            }
        }

        $this->contextAsFlushed[$flush][spl_object_id($entity)] = $context;
    }

    /**
     * Keeps what a collection scheduled for deletion held, before the flush deletes it.
     *
     * The snapshot answers whenever there is one — a collection replaced by another
     * still remembers what it had. `clear()` is the case that has nothing left: it takes
     * a fresh (empty) snapshot on its way out, so the only place the old membership
     * still exists is the database, and the rows are still there because this runs
     * before the flush opens its transaction. One SELECT, and only for an audited
     * collection somebody actually emptied.
     */
    private function rememberWhatIsBeingEmptied(EntityManagerInterface $em, int $flush): void
    {
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            try {
                $owner = $collection->getOwner();

                if ($owner === null) {
                    continue;
                }

                $metadata = $this->metadataFactory->for($owner);
                $mapping = $collection->getMapping();
                /** @var string $field */
                $field = \is_array($mapping) ? $mapping['fieldName'] : $mapping->fieldName;

                if ($metadata === null || !\array_key_exists($field, $metadata->fields)) {
                    continue; // not audited: nothing to say about it
                }

                // And nothing to say about one Doctrine will not carry out. A collection
                // is put on this schedule by being replaced or cleared, and the check for
                // whether anything is deleted happens later, in the persister:
                // OneToManyPersister::delete() returns at once unless the association has
                // orphanRemoval, because the rows belong to the elements and the inverse
                // side is not what is persisted. Collected anyway, a replacement of such a
                // collection was recorded as an emptying while both rows stayed exactly
                // where they were.
                if (CollectionRowsQuery::entry($mapping, 'mappedBy') !== null && CollectionRowsQuery::entry($mapping, 'orphanRemoval') !== true) {
                    continue;
                }

                $held = $collection->getSnapshot();

                if ($held === []) {
                    // Underneath the filters, for the same reason the rows question is
                    // asked underneath them: what this needs is the members the DELETE
                    // will take, and a DELETE by the owner's key takes them whether or not
                    // a soft-delete filter would have shown them. Asked through the
                    // persister as it stands, a filter that hides half the elements halved
                    // the record, and one that hides all of them left no record at all
                    // while the rows went.
                    $held = self::withoutTheApplicationsFilters(
                        $em,
                        static fn (): array => $uow->getCollectionPersister($mapping)->slice($collection, 0, null),
                    );
                }

                if ($this->theLastFlushWasPublishedLate && $held !== [] && $this->theJoinRowsAreGone($em, $owner, $field) === true) {
                    // Already carried out. A flush whose postFlush somebody swallowed
                    // never reaches postCommitCleanup(), so its collection deletion stays
                    // on the schedule and the next flush is handed it again -- with the
                    // old collection's snapshot still full, so it looks exactly like an
                    // emptying about to happen. Recorded again, it became a second
                    // document, against a row this operation never touched.
                    continue;
                }

                if ($held === []) {
                    // Nothing to say it about. A collection that was already empty is one,
                    // and so is a deletion still on the schedule after it was carried out —
                    // which is what a postFlush listener that threw leaves behind, since
                    // postCommitCleanup() never ran to clear it. Recorded anyway, that
                    // second one became an update with no changes at all, published by
                    // whichever flush came next: a record of something that did not happen
                    // during it, attached to a row nobody touched.
                    continue;
                }

                $this->emptiedCollections[spl_object_id($owner)][0] = $owner;
                $this->rememberWhoSawTheOwner(spl_object_id($owner), $flush);
                $this->emptiedCollections[spl_object_id($owner)][1][$flush][$field] = array_values(array_filter($held, static fn (mixed $element): bool => \is_object($element)));
            } catch (\Throwable $e) {
                // Reading the old membership back is the one part of this listener that
                // asks the database a question of its own, and a question that fails must
                // not take the flush with it. The record then says what it can — which is
                // what it said before this existed — and the failure is reported like any
                // other.
                $this->writer->reportFailure($e, null);
            }
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::CREATE);
        $manager = self::entityManagerOf($args->getObjectManager());
        $collecting = $manager === null ? self::NO_FLUSH : $this->collectingNowAfterAStatement($manager);
        $this->theRowNowMatchesTheObject($args->getObject());

        if ($record !== null) {
            $this->pending[] = $record;
            $this->pendingFlush[] = $collecting;
            // Registered like an update's: an owner created with its lines has one
            // record, and what the lines did belongs in it. Without this the membership
            // found no record to join and invented a second, phantom update.
            $this->pendingIndexByEntity[spl_object_id($args->getObject())] = array_key_last($this->pending);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        // Before the record, and for every updated entity rather than the audited
        // ones: an element of a tracked collection is usually not audited itself, and
        // this is the only moment its final change set can be read.
        $manager = self::entityManagerOf($args->getObjectManager());
        $collecting = $manager === null ? self::NO_FLUSH : $this->collectingNowAfterAStatement($manager);

        if ($manager !== null) {
            $this->readTheChangeSetAgainAfterPreUpdate($manager, $args->getObject(), $collecting);
        }

        $record = $this->recordFor($args, AuditEvent::UPDATE);
        $this->theRowNowMatchesTheObject($args->getObject());

        if ($record === null) {
            return;
        }

        $entity = $args->getObject();

        // The check has to know about them before they are folded in, in postFlush: an
        // update whose only change was inside a line of an order is still an update to
        // that order.
        if ($this->skipEmptyUpdates && !$record->hasChanges() && !$this->hasElementChanges($entity)) {
            return;
        }

        $already = $this->pendingIndexByEntity[spl_object_id($entity)] ?? null;

        if ($already !== null
            && ($this->pending[$already] ?? null)?->event === AuditEvent::UPDATE
            && $manager !== null
            && $manager->getUnitOfWork()->getEntityChangeSet($entity) === []
        ) {
            // The same UPDATE announced a second time. A flush started from a lifecycle
            // listener runs on the outer flush's unit of work and carries out whatever it
            // finds scheduled — including the outer flush's own remaining updates — and
            // when the outer flush then resumes its list, it dispatches postUpdate for a
            // row somebody else already wrote. Measured rather than deduced: in
            // DoctrineCanariesTest the second announcement arrives one level up with an
            // empty change set, because the unit of work consumed it the first time.
            //
            // That emptiness is the whole of the test, and it needs the pending record
            // beside it: a change set the listener cannot see is also what a nested flush
            // leaves behind when its postCommitCleanup() empties the one still running,
            // which is why this listener keeps a snapshot at all. Nothing pending means a
            // first announcement of a change set that went missing, and it is recorded.
            // Something pending, for an update, means the announcement is the second.
            //
            // A second, real update of the same entity in the same operation is not this
            // and must not be folded into the first: the unit of work still holds its
            // change set, which is what the emptiness asks about, and
            // WhoseMomentALateRecordCarriesTest walks One -> Two -> Three through a
            // listener to say so.
            //
            // The update the pending record has to be is the one part of this no fixture
            // reaches, and it is left escaping rather than claimed equivalent. It would
            // take an entity created earlier in the operation whose later update is
            // announced with nothing left in the unit of work — three flushes deep, where
            // Doctrine's own documentation stops promising anything. Without it a
            // creation would be overwritten by an update of the same row, and a history
            // saying a thing was edited when it appeared is the kind of wrong this bundle
            // refuses to produce.
            //
            // Recorded once, and under the flush that is collecting NOW: the re-announcing
            // loop belongs to the outer flush, the change was made before it started, and
            // its commit is the one the row hangs off. Appending instead put the same
            // change in the history twice, signed by two different people.
            // Spliced rather than assigned by key: both of these are lists whose indexes
            // are each other's, and writing through a key is how an index that means two
            // things starts.
            array_splice($this->pending, $already, 1, [$record]);
            array_splice($this->pendingFlush, $already, 1, [$collecting]);

            return;
        }

        $this->pending[] = $record;
        $this->pendingFlush[] = $collecting;
        $this->pendingIndexByEntity[spl_object_id($entity)] = array_key_last($this->pending);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::REMOVE, withChanges: false);

        if ($record !== null) {
            $this->pendingRemovals[spl_object_id($args->getObject())] = $record;
        }
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $manager = self::entityManagerOf($args->getObjectManager());
        $collecting = $manager === null ? self::NO_FLUSH : $this->collectingNowAfterAStatement($manager);

        $this->theRowNowMatchesTheObject($args->getObject());

        $key = spl_object_id($args->getObject());
        $record = $this->pendingRemovals[$key] ?? null;
        unset($this->pendingRemovals[$key]);

        if ($record !== null) {
            $this->pending[] = $record;
            // Asked here rather than carried from preRemove, which is where the record
            // was taken: for an ordinary $em->remove() preRemove runs at the call, before
            // any flush exists, so what it could have carried is "no flush at all" — and
            // a removal published late then took whatever moment the publishing request
            // had. postRemove is inside the flush that did the deleting, which is the
            // flush the record belongs to.
            $this->pendingFlush[] = $collecting;
        }
    }

    /**
     * The transaction is committed: send what this flush collected.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        // An inner flush — one a lifecycle listener started while this listener's own
        // flush is still running — reaches here first, and what it must not do is
        // publish the outer flush's work: those records belong to a transaction that
        // has not committed, and writing them makes the history describe a state the
        // database may still roll back. Only the outermost flush publishes; the inner
        // one hands back what it collected and lets the outer one finish.
        //
        // Which flush this is, is asked of the transaction rather than assumed: by the
        // time postFlush runs its own transaction is committed, so every level on the
        // stack from here down belongs to this flush and to flushes that never came
        // back. Popping the top of the stack instead was right until an inner flush was
        // abandoned — a listener behind this one throwing in *its* onFlush, caught by
        // the application — and then the outer flush saw a level left over, published
        // nothing, and the next flush read the stack as abandoned and dropped
        // everything the outer one had committed.
        $em = self::entityManagerOf($args->getObjectManager());

        // The same discarding every other reader does, and for the same reason: an entry
        // at or below this level belongs to a flush that is over. Asked here it also
        // answers the question this method starts with, because the outermost flush is
        // the one that leaves nothing behind.
        $collecting = $em === null ? self::NO_FLUSH : $this->collectingNow($em, theOneAtThisLevelCommitted: true);

        if ($collecting !== self::NO_FLUSH || ($em !== null && $this->flushes !== [])) {
            // An inner flush is over. What the outer flush collects from here on is filed
            // under the outer's number again — it is simply the one left on top — and the
            // outer's own postFlush does the publishing.
            return;
        }

        // Everything below runs application code — a deferred representer, withContext()
        // reading a declaration — and under "throw" reporting one of those failures
        // leaves this method through the exception. The state a flush collects has to go
        // with the flush whichever way it ends: the depth stack is already unwound by
        // then, so the next flush would not read it as abandoned either, and somebody
        // else's operation would publish these records as its own.
        try {
            $this->publish($args->getObjectManager(), $em);
        } finally {
            $this->forgetThisFlush();
        }
    }

    /**
     * @param \Doctrine\ORM\EntityManagerInterface|null $em
     */
    private function publish(ObjectManager $manager, ?EntityManagerInterface $em): void
    {
        $records = $this->pending;
        $collected = $this->pendingFlush;

        // Now that the flush is over, every element has its identifier — including the
        // ones inserted a moment ago — so what happened inside a tracked collection can
        // be named and folded into its owner's record. An owner whose own columns did
        // not change gets no postUpdate from Doctrine, there being nothing to UPDATE on
        // it, so that record is built here or nowhere.

        // The whole of this is inside the failure policy, not only the record building:
        // withContext() reads the declaration and Doctrine's metadata, and both can
        // throw. This is postFlush — the transaction has committed — so an exception
        // escaping would come out of flush() for a database change that is already
        // real. What the policy cannot do here is undo it: "throw" tells the caller,
        // it does not rewind the flush.
        foreach ($this->elementsByOwner($manager) as [$owner, $changes]) {
            $index = $this->pendingIndexByEntity[spl_object_id($owner)] ?? null;

            try {
                // The flush that saw this owner, which is not always the one publishing:
                // an inner flush may have run in between, and its number must not become
                // the outer record's moment or the key its context is read under.
                $flush = $this->ownerFlush[spl_object_id($owner)] ?? self::NO_FLUSH;

                if ($index !== null && isset($records[$index])) {
                    // Not the emptying: an owner with a pending record got that in
                    // recordFor(), which is handed the same map. Folding it in again here
                    // was written before that was measured, and a second road to one
                    // answer is how two of this week's defects started.
                    $merged = array_replace($records[$index]->changes, $changes);
                    $records[$index] = $records[$index]->withChanges($this->withContext($em, $owner, $merged, $collected[$index] ?? $flush));

                    continue;
                }

                $record = $this->recordForOwner($manager, $owner, $changes, $flush);

                if ($record !== null) {
                    $records[] = $record;
                    $collected[] = $flush;
                }
            } catch (\Throwable $e) {
                $this->reportWhileBuilding($e, $index !== null ? ($records[$index] ?? null) : null);
            }
        }

        // One batch: a flush that touched fifty entities is one _bulk call, not fifty
        // round-trips. The moment the change happened, not the moment it is being
        // written: this method runs in postFlush, and in the branch that publishes a
        // swallowed flush it runs during a later one entirely.
        //
        // One batch per run of records that share a moment, and runs rather than groups
        // so that the order records were collected in is the order they are written in.
        // Nearly always there is exactly one run; there are two or three when a
        // lifecycle listener called flush() in the middle of this one, and then each
        // stretch goes out with the moment its own flush settled.
        //
        // One run failing does not cost the rest. Under on_failure: throw a refused
        // record leaves writeAll() as an exception, and stopping there would drop every
        // later run — records of changes that are already committed, thrown away because
        // something else could not be written. A single writeAll() never did that: it
        // tries every record and raises afterwards. The runs are held to the same
        // promise, and the first exception is the one the caller gets, because it is the
        // one writeAll() already reported.
        $records = array_values($records);
        $collected = array_values($collected);
        $run = [];
        $moment = self::NO_FLUSH;
        $refused = null;

        $send = function (array $run, int $moment) use (&$refused): void {
            try {
                $this->writer->writeAll(array_values($run), $this->provenance[$moment] ?? null);
            } catch (\Throwable $e) {
                // Kept, not reported: writeAll() has already put this through the failure
                // policy — logged it, or dispatched it — and saying it again here would
                // be one failure in the log twice.
                $refused ??= $e;
            }
        };

        foreach ($records as $position => $record) {
            $its = $collected[$position] ?? self::NO_FLUSH;

            if ($run !== [] && $its !== $moment) {
                $send($run, $moment);
                $run = [];
            }

            $run[] = $record;
            $moment = $its;
        }

        if ($run !== []) {
            $send($run, $moment);
        }

        if ($refused !== null) {
            throw $refused;
        }

        // And only now, with everything that could be written on its way out. If
        // writeAll() raised instead, that is the exception the caller gets: it is about
        // records that reached the transport, and this one has been reported either way.
        if ($this->failureWhileBuilding !== null) {
            throw $this->failureWhileBuilding;
        }
    }

    /**
     * How many records the flush on the stack has collected, wherever it put them.
     *
     * Two places, and asking only the first one was a way to lose history without
     * saying so. Finished records sit in the pending list. And what happened inside a
     * tracked collection is held against its owner until postFlush builds the record
     * from it — which is the whole point of that map: **an owner whose own columns did
     * not change gets no event from Doctrine at all**, so nothing about it ever reaches
     * the pending list.
     *
     * That second one is why this exists. A flush whose only news came from inside a
     * collection was read as a flush that collected nothing: its rows were committed,
     * its records were dropped, and the warning said zero records were lost.
     *
     * A removal drafted in preRemove is deliberately not among them. It is not something
     * this flush collected — it is a record waiting for the flush that will do the
     * deleting, which may be the one about to start — and publish() never writes one.
     * Counted here it made the warning say a record was being written late that was not
     * being written at all, and it let a flush with nothing to publish take the branch
     * that says it is publishing.
     *
     * Counted once per owner, and not at all for an owner already in the pending list —
     * publish() folds what its elements did into the record that is there rather than
     * writing a second one.
     */
    private function collectedSoFar(): int
    {
        $count = \count($this->pending);

        $owners = array_unique(array_merge(
            array_keys($this->elementChanges),
            array_keys($this->elementMembership),
            array_keys($this->emptiedCollections),
        ));

        foreach ($owners as $owner) {
            if (!isset($this->pendingIndexByEntity[$owner])) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Reports a failure that happened while the records were being assembled, without
     * letting it end the assembling.
     *
     * @see self::$failureWhileBuilding
     */
    private function reportWhileBuilding(\Throwable $e, ?AuditRecord $record = null): void
    {
        try {
            $this->writer->reportFailure($e, $record);
        } catch (\Throwable $raised) {
            $this->failureWhileBuilding ??= $raised;
        }
    }

    /**
     * Whether the collections this flush emptied were really emptied.
     *
     * A flush whose only news is a `clear()` has no statement of its own to be asked
     * about: the owner is never dirtied, so no entity event fires and its entry's `ran`
     * stays false however well the flush went. Asked only that way, the one kind of
     * history this listener had to be taught to keep would be dropped again — and with
     * the warning about a flush that was interrupted, which it was not.
     *
     * **Asked of the database, because every cheaper witness lies here.** The unit of
     * work's schedule was the earlier answer: a scheduled deletion is cleared in
     * postCommitCleanup(), so one still on the list meant the flush that scheduled it did
     * not commit. That is true of a flush a veto aborted and false of the case this whole
     * branch exists for — `UnitOfWork::commit()` dispatches postFlush and calls
     * postCommitCleanup() afterwards, with no try/finally between them, so a postFlush
     * listener that throws leaves every schedule exactly as a refusal would. The two
     * states are identical from here, and one of them has the rows deleted.
     *
     * So the rows are asked, and asked underneath the ORM rather than through it. The
     * collection persister's count() would be the obvious way and is the wrong one: it is
     * a query the ORM writes, and it adds the target entity's SQL filter to it. A
     * soft-delete filter — ordinary in a Symfony application — then answers "no rows" for
     * a collection whose rows are every one of them still there, and a refused flush is
     * published as an emptying. A decision that needs the physical state cannot be taken
     * from a filtered answer; that is one of the shapes this bundle refuses on principle,
     * and it arrived here by asking the right question of the wrong reader.
     *
     * So it is one COUNT over the join table, by the owner's own columns, through the
     * connection. The mapping is read as an array, which is what it is on ORM 2 and what
     * ORM 3's mapping objects answer as — the same reading the field mappings get further
     * down. Anything this cannot build a query from is answered as not established.
     *
     * A collection that was empty before the flush cannot be told apart this way, and is
     * not worth telling apart: emptying an empty collection records nothing either way.
     */
    private function theEmptiedCollectionsWentThrough(EntityManagerInterface $em, int $flush): bool
    {
        $asked = false;

        foreach ($this->emptiedCollections as [$owner, $byFlush]) {
            foreach (array_keys($byFlush[$flush] ?? []) as $field) {
                try {
                    $gone = $this->theJoinRowsAreGone($em, $owner, $field);
                } catch (\Throwable $e) {
                    // The one question this listener asks of the database on this road,
                    // and a question that fails must not take the flush with it.
                    $this->writer->reportFailure($e, null);

                    return false;
                }

                if ($gone !== true) {
                    // Either the rows are there, or nothing here could ask: both are "not
                    // established", and this branch drops the records when nothing can
                    // vouch for them.
                    return false;
                }

                $asked = true;
            }
        }

        return $asked;
    }

    /**
     * Reads something through the ORM with the application's filters put aside.
     *
     * A filter is the application's opinion about what its users should see, and this
     * listener's questions are about what the database holds -- the two are different
     * questions and the second one is the one a history has to answer. Suspended rather
     * than disabled: a suspended filter keeps the parameters it was given and comes back
     * as the same instance, which a disable-and-enable pair does not.
     *
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private static function withoutTheApplicationsFilters(EntityManagerInterface $em, \Closure $read): mixed
    {
        $filters = $em->getFilters();
        $suspended = [];

        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            $filters->suspend($name);
            $suspended[] = $name;
        }

        try {
            return $read();
        } finally {
            foreach ($suspended as $name) {
                $filters->restore($name);
            }
        }
    }

    /**
     * Whether the rows this collection stands for are gone, asked of the connection.
     *
     * Asked of the connection rather than of a persister, and this is the point of it: a
     * persister's count() is a query the ORM writes and it adds the target entity's SQL
     * filter. A soft-delete filter then answers "no rows" for a collection whose rows are
     * every one of them still there, and a refused flush is published as an emptying.
     *
     * The statement is built by {@see CollectionRowsQuery}, which is a class of its own so
     * that the shapes this cannot reach from a fixture — a schema, an identifier that is an
     * object, the arm of each test belonging to the other ORM major — have somewhere to be
     * tested. Null from it means the mapping could not say, which the callers read as "not
     * established", the same as "there are rows": the two differ to a person and not to the
     * decision.
     */
    private function theJoinRowsAreGone(EntityManagerInterface $em, object $owner, string $field): ?bool
    {
        $classMetadata = $em->getClassMetadata($owner::class);
        $collection = $classMetadata->getFieldValue($owner, $field);

        if (!$collection instanceof PersistentCollection) {
            return null;
        }

        $mapping = $collection->getMapping();
        $target = CollectionRowsQuery::entry($mapping, 'targetEntity');
        $counting = CollectionRowsQuery::counting(
            $em->getConnection()->getDatabasePlatform(),
            $classMetadata,
            $owner,
            $mapping,
            \is_string($target) && $target !== '' ? $em->getClassMetadata($target) : null,
        );

        if ($counting === null) {
            return null;
        }

        [$sql, $values, $types] = $counting;
        $rows = $em->getConnection()->fetchOne($sql, $values, $types);

        return \is_numeric($rows) && (int) $rows === 0;
    }


    /**
     * Everything the flush that is ending collected, dropped with it.
     */
    /**
     * Notes which flush saw an owner whose record is built after the commit, the first
     * sighting winning.
     *
     * The first rather than the last, and in one place rather than at each of the three
     * roads into that map: an owner two flushes touched belongs to the one that started
     * touching it — the outer one, whose commit the whole history hangs off — and three
     * copies of a rule are three chances for it to stop being the same rule.
     */
    private function rememberWhoSawTheOwner(int $owner, int $flush): void
    {
        $this->ownerFlush[$owner] ??= $flush;
    }

    /**
     * The flush things are being collected under right now, if any — and the only way to
     * ask.
     *
     * Every entry at or below the current transaction level belongs to a flush that is
     * over: an inner flush pushes deeper than the one it runs inside, so an entry that
     * is no longer deeper than where we are is either finished or was refused in its own
     * onFlush and never came back to say so. Both are discarded here, which is why
     * nothing else pops this stack.
     *
     * **Only from a lifecycle event of a flush, never from onFlush itself.** A flush's
     * onFlush arrives at the same level it pushes at, so asking there would discard the
     * entry that was just made; its events arrive one level deeper, which is what makes
     * the comparison mean "someone else's flush". That is why this takes a manager and
     * the collecting-time callers take a number instead: there is no version of this
     * question that can be asked in the wrong place.
     */
    private function collectingNow(EntityManagerInterface $em, bool $theOneAtThisLevelCommitted = false): int
    {
        $this->unwindTo($em, $em->getConnection()->getTransactionNestingLevel(), $theOneAtThisLevelCommitted);

        return $this->flushes === [] ? self::NO_FLUSH : $this->flushes[array_key_last($this->flushes)]['flush'];
    }

    /**
     * The same, and a statement of Doctrine's that the flush collecting now reached its
     * statements. The two in one call because they are one fact from one event: a post*
     * event arrives inside a flush, and the flush it arrives inside is the one it proves.
     *
     * Written as two calls it would be right only in one order — mark, then unwind, and
     * the mark lands on an entry that is about to be thrown away; unwind, then mark, and
     * it is right until somebody moves a line. This listener has lost history three times
     * to per-flush state whose correctness rested on the order of two calls.
     */
    private function collectingNowAfterAStatement(EntityManagerInterface $em): int
    {
        $flush = $this->collectingNow($em);

        if ($this->flushes !== []) {
            // Taken off and put back rather than written through its key: the stack is a
            // list, and writing through a key is how an analyser — rightly — stops being
            // able to say that it still is one.
            $entry = array_pop($this->flushes);
            $entry['ran'] = true;
            $this->flushes[] = $entry;
        }

        return $flush;
    }

    /**
     * Takes off the stack every flush the current transaction level says is over, and
     * says whether any of them got as far as running statements.
     *
     * A flush that did not is a flush whose work never happened: refused in its own
     * onFlush by a listener behind this one, before `UnitOfWork::commit()` opened a
     * transaction or wrote a row. What it collected describes rows nobody has — and the
     * rest of the flush it was nested in is still live and still collecting, so dropping
     * everything is not open either. It takes its own share and leaves the rest, which is
     * what the per-flush buckets are for: an outer flush's "1 -> 2" for the same field
     * comes back when the inner flush's "2 -> 9" goes, and the history agrees with the
     * database again.
     *
     * A flush that only emptied a collection has no statement of its own to show, and is
     * asked about its collections instead — except in its own postFlush, which says it
     * outright. postFlush is dispatched by a commit that went through, and it is
     * dispatched BEFORE postCommitCleanup() empties the schedules, so the collections
     * that flush deleted are still on the list there and asking would answer "not yet".
     * Which entry that is, is not "the top": a dead inner flush can be sitting above it,
     * at a deeper level, and is exactly what this is here to take away.
     */
    private function unwindTo(EntityManagerInterface $em, int $level, bool $theOneAtThisLevelCommitted = false): bool
    {
        $ran = false;

        while ($this->flushes !== [] && $this->flushes[array_key_last($this->flushes)]['level'] >= $level) {
            $entry = array_pop($this->flushes);

            if ($entry['ran']
                || ($theOneAtThisLevelCommitted && $entry['level'] === $level)
                || $this->theEmptiedCollectionsWentThrough($em, $entry['flush'])
            ) {
                $ran = true;

                continue;
            }

            $this->forgetWhatThisFlushCollected($entry['flush']);
        }

        return $ran;
    }

    /**
     * Everything one flush collected, taken away without touching what the others did.
     *
     * The finished records are not among it, and that is the invariant rather than an
     * oversight: every one of them is taken in a post-statement event, which is the very
     * event that marks the flush as having run, so a flush being discarded here has none.
     * What it does have is what it collected in its onFlush, before Doctrine wrote
     * anything — the three element maps — and the moment and context filed under its
     * number.
     *
     * Which flush first saw an owner is rebuilt rather than patched: it is the lowest
     * bucket that owner has left, and deriving it again cannot fall out of step with the
     * buckets the way a second rule about it would.
     */
    /**
     * Keeps a change set, under the flush that computed it and corrected for whatever a
     * flush before it planned and never wrote.
     *
     * The two together so they cannot drift: a snapshot whose number is somebody else's
     * is a snapshot that will be handed forward as the wrong row's history.
     *
     * @param array<string, mixed> $set
     */
    private function rememberChangeSet(object $entity, array $set, int $flush): void
    {
        $key = spl_object_id($entity);

        // Not ours to re-file. A flush started from a lifecycle listener shares the outer
        // flush's unit of work, and getScheduledEntityUpdates() there still holds the
        // outer flush's own entities: executeUpdates() takes one off the list only after
        // its statement. So the inner flush's onFlush is handed rows it will never write,
        // and filing them under its number meant its discarding threw away the correction
        // the outer flush had made and handed the outer's own old side forward as if it
        // were unwritten.
        //
        // Ownership lasts until the owning flush writes the row, and post* below says
        // when that is. Until then the snapshot is what that flush computed, and neither
        // the values nor the number move.
        if (($this->changeSetFlush[$key]['flush'] ?? $flush) !== $flush) {
            return;
        }

        $held = $this->neverWritten[$entity] ?? [];

        foreach ($set as $field => $sides) {
            if (!\is_array($sides) || !\array_key_exists(1, $sides) || !\array_key_exists($field, $held)) {
                continue;
            }

            // The side the column really came from, in place of the one the unit of work
            // believes because a flush that never happened told it so. The new side is
            // left alone: that one is about to be written, and it is the only part of
            // this Doctrine is right about.
            $set[$field] = [$held[$field], $sides[1]];

            unset($held[$field]);
        }

        // Spent, and only what was spent. What a flush that never happened left behind is
        // true of the column until something writes it, and the flush computing this
        // change set is the one that will -- for the fields it names, and for no others.
        // If this flush is refused as well, its own discarding puts the same answers back,
        // because the sides it is being corrected to are the sides it hands forward.
        if ($held === []) {
            unset($this->neverWritten[$entity]);
        } else {
            $this->neverWritten[$entity] = $held;
        }

        $this->changeSets[$key] = $set;
        $this->changeSetFlush[$key] = ['entity' => $entity, 'flush' => $flush];
    }

    /**
     * Doctrine has written this entity: whatever flush computed the change set it was
     * written from has no further claim on it.
     *
     * The snapshot itself stays -- publishing reads it after the commit -- but it stops
     * being evidence of what the row still holds, which is the only thing the number is
     * for. A flush discarded after this must not hand the old side forward, because the
     * statement already moved the row past it; and the next flush to compute a change set
     * for this entity is describing a later state and owns it.
     */
    private function theRowNowMatchesTheObject(object $entity): void
    {
        unset($this->changeSetFlush[spl_object_id($entity)]);
    }

    private function forgetWhatThisFlushCollected(int $flush): void
    {
        foreach ($this->changeSetFlush as $key => $its) {
            if ($its['flush'] !== $flush) {
                continue;
            }

            // Its snapshot is the latest one for that entity — a flush discarded here was
            // refused before it wrote anything, so nothing has computed a change set for
            // it since — which makes the side it came from what the column still holds.
            $held = $this->neverWritten[$its['entity']] ?? [];

            foreach ($this->changeSets[$key] ?? [] as $field => $sides) {
                if (\is_array($sides) && \array_key_exists(0, $sides)) {
                    $held[$field] ??= $sides[0];
                }
            }

            if ($held !== []) {
                $this->neverWritten[$its['entity']] = $held;
            }

            unset($this->changeSets[$key], $this->changeSetFlush[$key]);
        }

        foreach ([&$this->elementChanges, &$this->elementMembership, &$this->emptiedCollections] as &$map) {
            foreach ($map as $owner => [, $byFlush]) {
                unset($byFlush[$flush]);

                if ($byFlush === []) {
                    unset($map[$owner]);

                    continue;
                }

                $map[$owner][1] = $byFlush;
            }
        }

        unset($map);

        $this->ownerFlush = [];

        foreach ([$this->elementChanges, $this->elementMembership, $this->emptiedCollections] as $map) {
            foreach ($map as $owner => [, $byFlush]) {
                foreach (array_keys($byFlush) as $bucket) {
                    $this->ownerFlush[$owner] = min($this->ownerFlush[$owner] ?? $bucket, $bucket);
                }
            }
        }

        unset($this->provenance[$flush], $this->contextAsFlushed[$flush]);
    }

    /**
     * @param bool $keepingWhatWasDraftedForTheNextFlush whether a removal's record taken
     *        in preRemove survives this. It does when the forgetting is a flush STARTING
     *        and finding the last one's state behind it: `$em->remove()` fires preRemove
     *        where it is called, before any flush exists, so a record drafted there
     *        belongs to the flush about to run and not to the one that left. Swept up
     *        with the rest, the deletion was committed with no history of it at all —
     *        and the more so on the road that publishes late, where an operation's first
     *        act is to write somebody else's records and its second was to lose its own.
     */
    private function forgetThisFlush(bool $keepingWhatWasDraftedForTheNextFlush = false): void
    {
        $drafts = $keepingWhatWasDraftedForTheNextFlush ? $this->pendingRemovals : [];

        $this->pending = [];
        $this->pendingFlush = [];
        $this->pendingRemovals = $drafts;
        $this->pendingIndexByEntity = [];
        $this->elementChanges = [];
        $this->elementMembership = [];
        $this->ownerFlush = [];
        $this->flushes = [];
        $this->wrote = 0;
        $this->failureWhileBuilding = null;

        // Everything, not this flush's entry: forgetting happens when nothing is live —
        // the outermost postFlush, or a new flush finding the last one abandoned — and
        // an entry left behind under another number would be a moment nothing can ever
        // publish. Removing only the current one and then emptying the map anyway was
        // two rules for one thing, and the second made the first unobservable.
        $this->provenance = [];
        $this->changeSets = [];
        $this->changeSetFlush = [];
        $this->emptiedCollections = [];
        $this->contextAsFlushed = [];
        $this->reportedLostChangeSets = false;
        $this->theLastFlushWasPublishedLate = false;
    }

    /**
     * The manager was cleared — after a failed flush, or by the application: whatever
     * was collected belongs to a flush that will not commit.
     *
     * ORM 2 can clear a single entity class while the flush that is running commits
     * the rest, and dropping the records then would lose history that did happen. But
     * a closed manager means the flush failed, and a partial clear does not change
     * that: those records describe a state the database never reached, and inventing
     * history is worse than missing it.
     */
    public function onClear(OnClearEventArgs $args): void
    {
        if (self::isPartialClear($args) && self::entityManagerOf($args->getObjectManager())?->isOpen() === true) {
            return;
        }

        // Everything a flush collected, not only the records: what onFlush saw about the
        // elements of tracked collections describes INSERTs and UPDATEs that were rolled
        // back with the rest, and would otherwise surface in the next flush as history.
        //
        // Through forgetThisFlush() rather than by listing the same fields again. This
        // used to be a second copy of that method, written out by hand, and it fell
        // behind: the fields added for the per-flush moment were not in it, so a clear
        // after a swallowed publication emptied the records and left the numbers that
        // went with them — and the next flush's first record, at position zero, was
        // written with the moment of the flush whose records had just been thrown away.
        // A new record dated and signed as somebody else's, which is worse than the loss
        // the clear was already causing.
        //
        // Unconditionally, and the flush stack with it: onClear is not paired with
        // anything, so popping one level would leave the stack describing a flush that no
        // longer exists.
        $this->forgetThisFlush();

        // And what the rows were believed to hold, which the clear has just made
        // unanswerable: a cleared manager means either a flush that failed and rolled
        // back, or an application throwing its objects away. Either way nothing here
        // knows what the columns hold any more, and a correction is a claim about a
        // column.
        $this->neverWritten = new \WeakMap();
    }

    /**
     * Remember how deep this flush started, and notice a flush above it that died.
     *
     * The unwinding is the one {@see collectingNow()} does, spelled out because here it
     * decides more than which number is current. Whatever is at or above this level
     * belongs to a flush that is over; what is left underneath is what says whether
     * anything is still live — and only an empty stack means nothing is.
     *
     * The distinction is the whole of it. A dead flush on top of a live one is the
     * ordinary aftermath of a listener refusing an inner flush, and the application
     * catching that and carrying on is the pattern the bundle exists to survive: the
     * outer flush is still walking its entities, still has its transaction open, and its
     * records must not be published — they describe rows the outer flush may still roll
     * back. Read as an abandoned outer, that is exactly what happened: every record the
     * live flush had collected went out before its commit, signed by whoever was acting
     * in the listener that started the dead one.
     */
    private function beginFlush(EntityManagerInterface $em, int $flush): void
    {
        $level = $em->getConnection()->getTransactionNestingLevel();
        $before = \count($this->flushes);
        $committed = $this->unwindTo($em, $level);

        if (\count($this->flushes) < $before && $this->flushes === []) {
            // Nothing is left underneath: the flush this state belongs to is over, and it
            // did not come back through postFlush. Two very different things end that way.
            $abandoned = $this->flushingManager?->get();
            $abandoned = $abandoned instanceof EntityManagerInterface ? $abandoned : null;

            // Whatever the flushes that unwound left: the ones that ran nothing have
            // already taken their share away, so this counts what is really there.
            $collected = $this->collectedSoFar();

            if ($abandoned !== null && $abandoned->isOpen() && $committed && $collected > 0) {
                // Its manager is still open, so UnitOfWork::commit() did not fail — every
                // failure inside its try closes the manager on the way out. The
                // transaction committed and something else swallowed the rest of the
                // event: a postFlush listener registered before this one threw, and this
                // listener never ran. The rows are in the database; dropping the records
                // would be the audit trail losing what actually happened, which is the
                // one outcome it must not choose.
                $this->logger->warning('A flush committed without reaching this listener — a postFlush listener registered before it threw — so its {count} audit record(s) are being written now, late. Give the audit listener a higher priority than listeners that may fail, or handle the failure in that listener.', ['count' => $collected]);

                try {
                    $this->publish($abandoned, $abandoned);
                } catch (\Throwable $e) {
                    $this->writer->reportFailure($e, null);
                } finally {
                    $this->forgetThisFlush(keepingWhatWasDraftedForTheNextFlush: true);
                    $this->theLastFlushWasPublishedLate = true;
                }
            } else {
                // Nothing was collected; or the flush never reached a statement,
                // because a listener in onFlush threw before Doctrine wrote anything; or
                // the manager is gone — closed by UnitOfWork::commit() after a failure,
                // or replaced by the application afterwards. Any of the three means
                // nothing here can be shown to have reached the database, and history
                // that describes rows nobody has is worse than history that is missing.
                $this->logger->warning('A flush ended without committing, or without anything left to prove it did — a listener in onFlush threw, most likely — so {count} audit record(s) it had collected are dropped.', ['count' => $collected]);

                $this->forgetThisFlush(keepingWhatWasDraftedForTheNextFlush: true);
            }
        }

        $this->flushingManager = \WeakReference::create($em);
        $this->flushes[] = ['level' => $level, 'flush' => $flush, 'ran' => false];
    }

    /**
     * Whether this clear names a single entity class rather than emptying the manager.
     *
     * Only ORM 2 can do that, and only ORM 2 has the method to ask, so the question is
     * put through reflection: a static analyser sees one version at a time and would
     * call any direct check redundant on ORM 2 and impossible on ORM 3. It is neither —
     * it is what tells the two versions apart, and both are supported.
     */
    private static function isPartialClear(OnClearEventArgs $args): bool
    {
        $event = new \ReflectionClass($args);

        if (!$event->hasMethod('clearsAllEntities')) {
            return false; // ORM 3: a clear is always a full one
        }

        return $event->getMethod('clearsAllEntities')->invoke($args) === false;
    }

    /**
     * Doctrine's own listeners always hand an entity manager, but the event only
     * promises an ObjectManager on the oldest supported doctrine/persistence, so the
     * narrowing is real rather than decorative: without an entity manager there is no
     * unit of work to read a change set from.
     */
    private static function entityManagerOf(ObjectManager $manager): ?EntityManagerInterface
    {
        return $manager instanceof EntityManagerInterface ? $manager : null;
    }

    /**
     * The change set read again, after preUpdate has had its say.
     *
     * A change set is taken in onFlush, which is before Doctrine builds the UPDATE — and a
     * preUpdate listener may still correct a value, or bring in a field that was not in
     * it, which Doctrine merges through recomputeSingleEntityChangeSet(). What the
     * statement writes is that later answer.
     *
     * **For every updated entity, and not only the elements of a tracked collection.**
     * The elements are why this exists — their changes are folded into the owner's record
     * from what was collected in onFlush, so a correction that never reached it had the
     * history saying a line went to 7 while the row took 5 — but narrowing it to them
     * would take two other things down with it, and neither is obvious from here:
     *
     * - a field a preUpdate listener ADDS is not in the snapshot at all, and {@see
     *   sidesFrom()} keeps such a field exactly as the unit of work reports it. After a
     *   refused flush the unit of work reports it from a value the column never took, and
     *   {@see $neverWritten} is what says otherwise — spent by rememberChangeSet(), which
     *   is reached for that field on this road and no other;
     * - and the same call is what SPENDS the correction, so leaving it unspent here means
     *   it is still there for the flush after the one that wrote the column, which then
     *   starts its change from a value two writes old. Measured, with this one call taken
     *   out: One to Three recorded as "Two to Three", and the next change to "Four"
     *   recorded as starting from "One", under a third actor.
     *
     * Only when the change set actually moved. A correction in preUpdate is rare, and
     * walking every entity of every flush to discover that nothing changed is work every
     * application would pay for the few that need it.
     */
    private function readTheChangeSetAgainAfterPreUpdate(EntityManagerInterface $em, object $element, int $flush): void
    {
        $current = $em->getUnitOfWork()->getEntityChangeSet($element);
        $snapshot = $this->changeSets[spl_object_id($element)] ?? null;

        // An empty current set is the unit of work having been emptied under us - by a
        // listener's own flush - and not a correction. Replacing the snapshot with it
        // would take the audited entity's record down with it.
        if ($snapshot === null || $current === [] || $current === $snapshot) {
            return;
        }

        // Merged rather than replaced, and for the same reason the owner's own fields
        // are: what the row went FROM is only in the snapshot.
        $this->rememberChangeSet($element, self::sidesFrom($current, $snapshot), $flush);

        // Named, rather than "this element". What the statement is about is what the unit
        // of work has just recomputed, and a field it does not mention is a field this
        // update is not touching -- which is not the same statement as "that field went
        // back to where it started", although both arrive here with no change to record.
        // Read as the same, a second nested flush correcting one field of a line erased
        // the successful change another flush had made to a different field of it.
        $this->collectElementChanges($em, $element, $flush, replacing: array_keys($current));
    }

    /**
     * Whether this updated entity is an element of a tracked collection, and if so,
     * what changed in it — held against its owner until the owner's record is built.
     *
     * The owner is reached through the element's own side of the association, which is
     * already loaded, so this asks nothing of the database. A failure here is reported
     * like any other: an element that cannot be read must not fail the flush.
     */
    /**
     * @param list<string>|null $replacing the element's fields this update is about, when it is being read again
     */
    private function collectElementChanges(EntityManagerInterface $em, object $element, int $flush, ?bool $added = null, ?array $replacing = null): void
    {
        try {
            $elementMetadata = $em->getClassMetadata($element::class);

            // Corrected, like everything else built from a change set. Which owner an
            // element is arriving FROM is read out of the change set of its own reference
            // back, and the unit of work answers that from a value a refused flush left
            // behind: a line the database had under A, re-pointed at B by a flush that was
            // refused and then at C by the retry, was recorded as leaving B — a crate that
            // never held it — while A kept it in its history for ever. Since the change set
            // a record is built from was taught to start where the column did, this was the
            // one reader left asking the unit of work directly.
            $changeSet = $added === null
                ? self::sidesFrom($em->getUnitOfWork()->getEntityChangeSet($element), $this->changeSets[spl_object_id($element)] ?? [])
                : [];

            foreach ($elementMetadata->getAssociationNames() as $association) {
                if (!$elementMetadata->isSingleValuedAssociation($association) || $elementMetadata->isAssociationInverseSide($association)) {
                    continue;
                }

                $current = $elementMetadata->getFieldValue($element, $association);

                // The element changed hands. Doctrine keeps that on the owning side — the
                // element's own reference — so neither collection is dirty and, without
                // reading the change set, both owners stay silent about it.
                if (\array_key_exists($association, $changeSet) && \is_array($changeSet[$association])) {
                    $this->holdMembership($em, $element, $changeSet[$association][0] ?? null, $association, $flush, added: false, replacing: $replacing);
                    $this->holdMembership($em, $element, $current, $association, $flush, added: true, replacing: $replacing);

                    // Its own fields are left out of this flush on purpose: the owner it
                    // arrived at never held the value it is arriving from.
                    continue;
                }

                // A deletion answers to the owner the database row had, not to whatever
                // the object points at in memory: a line re-pointed at B and removed in
                // the same flush was deleted from A's rows, and a back-ref nulled before
                // an orphanRemoval left the removal recorded nowhere at all. The change
                // set is asked first — computing it refreshes the "original" data to the
                // current values, so for a nulled back-ref the old owner survives only
                // there — then the original data, then the object itself.
                if ($added === false) {
                    // Corrected the same way, so that the two roads read one thing. No
                    // fixture tells this one apart: a removal after a refused re-pointing
                    // comes back naming the right owner already, because what the change
                    // set of a deleted element says and what the original data says still
                    // agree there. The road above is the one with a scenario, and a reader
                    // that corrects on one road and not the other is the shape this round
                    // spent three findings on.
                    $deletedChangeSet = self::sidesFrom($em->getUnitOfWork()->getEntityChangeSet($element), $this->changeSets[spl_object_id($element)] ?? []);
                    $owner = \array_key_exists($association, $deletedChangeSet) && \is_array($deletedChangeSet[$association])
                        ? $deletedChangeSet[$association][0] ?? null
                        : ($em->getUnitOfWork()->getOriginalEntityData($element)[$association] ?? $current);

                    $this->holdMembership($em, $element, $owner, $association, $flush, added: false, replacing: $replacing);

                    continue;
                }

                $this->holdMembership($em, $element, $current, $association, $flush, $added, $replacing);
            }
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, null);
        }
    }

    /**
     * An audited field has to be one Doctrine reports under that name.
     *
     * The case that costs the most is an embeddable: Doctrine stores it as columns of
     * its owner and reports them as "address.city", never as "address", so
     * #[AuditField] on the embedded property matched nothing every time and said nothing
     * about it. A property that is mapped as neither a field nor an association does the
     * same. Both are declarations that cannot be honoured, and this bundle exists to
     * refuse the silence rather than produce it — so they travel the failure policy like
     * every other declaration mistake.
     *
     * Asked once per class and field list, like the tracking check above.
     */
    private function assertAuditedFieldsAreThere(EntityManagerInterface $em, object $entity, AuditMetadata $metadata): void
    {
        // Cached for a declaration that cannot change between instances, and repeated
        // for one that can: getAuditedFields() and getAlwaysRecordedFields() are the
        // instance's answer, and the checks below read the representers and the
        // always-recorded list, not only the field names.
        $checked = $entity instanceof AuditableInterface ? null : $entity::class."\0fields";

        if ($checked !== null && isset($this->checkedTracking[$checked])) {
            return;
        }

        $fields = array_keys($metadata->fields);

        $classMetadata = $em->getClassMetadata($entity::class);

        // An always-recorded field is read straight off the entity and stored as it is,
        // which only a scalar can be: withAlwaysRecorded() skips associations, so naming
        // one read as a supported declaration and was honoured nowhere — and the promise
        // it exists for, that every history line reads on its own, quietly did not hold
        // for that field.
        foreach ($metadata->alwaysRecorded as $always) {
            if ($classMetadata->hasAssociation($always)) {
                throw new DeclarationMistake(sprintf('%s::$%s is listed as always recorded, but it is an association. Always-recorded fields are stored as they are beside the changes, which a related object cannot be; audit it as a field with a representer, and it is recorded when it changes.', $entity::class, $always));
            }
        }

        foreach ($fields as $field) {
            if ($classMetadata->hasAssociation($field)) {
                // Once, here, for every path a related object can reach a record by.
                // ChangeSetBuilder raised this when it represented a value, which left
                // two holes: an association that is null was never represented and so
                // never checked, and the membership path represented nothing at all and
                // answered with null — "documents.42: null → null", a history line that
                // exists, looks valid and means nothing.
                // A collection nothing can report to this side. Doctrine persists the
                // owning side of a ManyToMany, so ChangeSetBuilder skips the inverse one
                // deliberately, and membership travels through the element's own
                // single-valued reference back — which an element of a ManyToMany does
                // not have. Refused with trackElements already; without it the
                // declaration merely read as supported and recorded nothing at all.
                if ($classMetadata->isCollectionValuedAssociation($field) && $classMetadata->isAssociationInverseSide($field)) {
                    $mappedBy = $classMetadata->getAssociationMappedByTargetField($field);

                    if ($mappedBy === '' || !$em->getClassMetadata($classMetadata->getAssociationTargetClass($field))->isSingleValuedAssociation($mappedBy)) {
                        throw new DeclarationMistake(sprintf('%s::$%s is an audited collection whose elements reach back through a collection of their own (the inverse side of a ManyToMany), and nothing reports that to this side: neither what the collection holds nor what joins and leaves it would ever be recorded. Audit it on the owning side instead.', $entity::class, $field));
                    }
                }

                if ($metadata->fields[$field] === null) {
                    throw new DeclarationMistake(sprintf('%s::$%s is an audited association and has no representer. Give the declaration a callable turning the related object into what the history should show (a name, a reference), or the record could only say that something changed and not what.', $entity::class, $field));
                }

                continue;
            }

            // getFieldNames() rather than hasField(): the latter says yes to an
            // embeddable's own name, which is exactly the name Doctrine never reports a
            // change under.
            if (\in_array($field, $classMetadata->getFieldNames(), true)) {
                self::assertTheColumnSaysWhatTheRowHolds($entity, $classMetadata, $field);

                continue;
            }

            // An embeddable's own columns are mapped as "field.property", which is also
            // how they arrive in the change set — so the declaration has to name them.
            $parts = array_values(array_filter(
                $classMetadata->getFieldNames(),
                static fn (string $mapped): bool => str_starts_with($mapped, $field.'.'),
            ));

            throw new DeclarationMistake($parts === []
                ? sprintf('%s::$%s is audited, but Doctrine maps it as neither a field nor an association, so nothing about it would ever be recorded.', $entity::class, $field)
                : sprintf('%s::$%s is audited, but it is an embeddable: Doctrine reports its columns as %s and never as "%s", so nothing about it would ever be recorded. Name those columns instead — which #[AuditField] cannot do, since there is no property called "%s" to put it on: declare them through AuditableInterface::getAuditedFields(), which takes the names as strings.', $entity::class, $field, implode(', ', array_map(static fn (string $p): string => '"'.$p.'"', $parts)), $field, $parts[0]));
        }

        if ($checked !== null) {
            $this->checkedTracking[$checked] = true;
        }
    }

    /**
     * An audited scalar has to be one whose change set is what the row took.
     *
     * "Doctrine maps this column" is not the same statement. Three kinds of column move
     * on their own, and for all three the change the unit of work reports is not the
     * change the database made:
     *
     * - a version column is written by Doctrine itself as part of the optimistic lock,
     *   so a record of it describes the lock and not what anybody did;
     * - a column marked not insertable or not updatable is left out of the statement:
     *   the property can change in PHP for the rest of the request while the row keeps
     *   what it had, and the record would say the value moved;
     * - a generated column is computed by the database, and what the object holds is
     *   whatever it held before the write.
     *
     * Refused rather than recorded, like every other declaration that could only produce
     * history nobody can trust. The mapping is read defensively because its shape is
     * Doctrine's own — an array on ORM 2, an object on ORM 3.
     *
     * @param ClassMetadata<object> $classMetadata
     */
    private static function assertTheColumnSaysWhatTheRowHolds(object $entity, ClassMetadata $classMetadata, string $field): void
    {
        if ($classMetadata->isVersioned && $classMetadata->versionField === $field) {
            throw new DeclarationMistake(sprintf('%s::$%s is audited, but it is the version column: Doctrine writes it itself to hold the optimistic lock, so a history line about it describes the lock rather than anything a person did.', $entity::class, $field));
        }

        $mapping = $classMetadata->fieldMappings[$field] ?? null;

        if ($mapping === null) {
            return;
        }

        // Read as an array either way: ORM 3's FieldMapping is an ArrayAccess over the
        // same keys, and reading one shape covers both majors without asking which is
        // installed.
        $reason = match (true) {
            (bool) ($mapping['notInsertable'] ?? false) => 'is mapped as not insertable, so an INSERT leaves it to the database',
            (bool) ($mapping['notUpdatable'] ?? false) => 'is mapped as not updatable, so an UPDATE never writes it — the property can move in PHP while the row keeps what it had',
            ($mapping['generated'] ?? null) !== null => 'is generated by the database, so what the object holds is what it held before the write',
            default => null,
        };

        if ($reason !== null) {
            throw new DeclarationMistake(sprintf('%s::$%s is audited, but it %s. What Doctrine reports as its change is not what the row took, and a history line that disagrees with the database is worse than one that is missing.', $entity::class, $field, $reason));
        }
    }

    /**
     * A tracked collection has to be one this listener can watch: the inverse side of a
     * OneToMany, whose elements point back through a single-valued owning association.
     * That is where the unit of work reports what they did.
     *
     * A ManyToMany, the owning side, or a field that is no association at all used to be
     * accepted and then silently record nothing, which is the worst answer an audit
     * library can give. It is a mistake in a declaration, so it travels the way the other
     * declaration mistakes do: logged, or raised with the "throw" policy.
     *
     * Asked once per class — a mapping does not change while the process runs.
     */
    private function assertTrackedCollectionsAreServable(EntityManagerInterface $em, object $entity, AuditMetadata $metadata): void
    {
        if ($metadata->trackedCollections() === []) {
            return;
        }

        // Keyed by the class AND the collections it declared, not by the class alone:
        // the interface form of a declaration is deliberately not cached, because its
        // field list may differ per instance, and a class-only key let the second
        // instance's different collections through unchecked.
        // As above: an instance that declares its own tracked collections declares its
        // own tracked element fields with them, and a typo in the second is what this
        // check is for.
        $checked = $entity instanceof TracksCollectionElementsInterface ? null : $entity::class."\0collections";

        if ($checked !== null && isset($this->checkedTracking[$checked])) {
            return;
        }

        $classMetadata = $em->getClassMetadata($entity::class);

        foreach ($metadata->trackedCollections() as $field) {
            $reason = match (true) {
                !$classMetadata->hasAssociation($field) => 'is not an association',
                !$classMetadata->isCollectionValuedAssociation($field) => 'is a to-one association',
                !$classMetadata->isAssociationInverseSide($field) => 'is the owning side — a ManyToMany, or a collection mapped here instead of on its elements',
                $classMetadata->getAssociationMappedByTargetField($field) === '' => 'has no mappedBy to reach its elements through',
                // The inverse side of a ManyToMany has a mappedBy and passed everything
                // above — but its elements reach back through a collection, which the
                // unit of work never reports element-by-element to this side.
                !$em->getClassMetadata($classMetadata->getAssociationTargetClass($field))
                    ->isSingleValuedAssociation($classMetadata->getAssociationMappedByTargetField($field))
                    => 'is mapped by a collection on its elements (a ManyToMany), and no element points back through a single-valued association',
                default => null,
            };

            if ($reason !== null) {
                throw new DeclarationMistake(sprintf('%s::$%s tracks its elements, but it %s. Element tracking watches the inverse side of a OneToMany, whose elements refer back to their owner.', $entity::class, $field, $reason));
            }

            // And the field names, when the declaration names them. elementChanges()
            // walks Doctrine's change set and skips whatever is not on the list, so a
            // misspelling watched nothing at all, for the life of the application,
            // without a word — the same silence the checks above refuse, arrived at by a
            // likelier accident. Associations are named separately because element
            // changes deliberately do not cover them: an element has nowhere to declare
            // a representer.
            $wanted = $metadata->trackedElementFields($field);

            if (!\is_array($wanted)) {
                continue;
            }

            $element = $em->getClassMetadata($classMetadata->getAssociationTargetClass($field));

            foreach ($wanted as $name) {
                if (\in_array($name, $element->getFieldNames(), true)) {
                    continue;
                }

                throw new DeclarationMistake(sprintf('%s::$%s tracks the element field "%s", which %s. Element tracking records what changed inside an element, and only its own scalar columns are reported that way.', $entity::class, $field, $name, $element->hasAssociation($name) ? 'is an association of '.$element->getName() : 'is not a field of '.$element->getName()));
            }
        }

        if ($checked !== null) {
            $this->checkedTracking[$checked] = true;
        }
    }

    /**
     * Holds what this element did against one owner, if that owner is audited and tracks
     * the collection this element belongs to.
     */
    /**
     * @param list<string>|null $replacing
     */
    private function holdMembership(EntityManagerInterface $em, object $element, mixed $owner, string $association, int $flush, ?bool $added, ?array $replacing = null): void
    {
        if (!\is_object($owner)) {
            return; // no owner on that side: nothing to write a history against
        }

        $metadata = $this->metadataFactory->for($owner);

        if ($metadata === null) {
            return;
        }

        // An owner on its way out gets its remove; the lines going with it are not a
        // second event, and an update after a remove would be one.
        if ($em->getUnitOfWork()->isScheduledForDelete($owner)) {
            return;
        }

        // Here, and not only where a lifecycle event builds a record. An owner whose own
        // columns did not change gets no postUpdate, so its record is assembled in
        // postFlush by recordForOwner() — after the commit, where "throw" can tell the
        // caller and nothing can stop the write. This is onFlush: a declaration that
        // cannot be honoured still refuses the flush that would have relied on it.
        $this->assertAuditedFieldsAreThere($em, $owner, $metadata);
        $this->assertTrackedCollectionsAreServable($em, $owner, $metadata);

        $this->holdElementChanges($em, $element, $owner, $metadata, $association, $flush, $added, $replacing);
    }

    /**
     * @param list<string>|null $replacing
     */
    private function holdElementChanges(EntityManagerInterface $em, object $element, object $owner, AuditMetadata $metadata, string $association, int $flush, ?bool $added = null, ?array $replacing = null): void
    {
        // An owner reached through its elements may have no event of its own — nothing on
        // it changed — so the loops in onFlush never offered it to rememberContext(). Its
        // always-recorded fields would then be read off the object when the record is
        // assembled in postFlush, which is a later moment, and a later moment still when
        // that postFlush belongs to the flush after it. Asked once per flush: the entry
        // is written even when it is empty, so a second element of the same owner finds
        // it rather than asking again.
        if (!isset($this->contextAsFlushed[$flush][spl_object_id($owner)])) {
            $this->rememberContext($em, $owner, $flush);
        }

        $ownerMetadata = $em->getClassMetadata($owner::class);

        // Every audited to-many field, not only the tracked ones: membership is part of
        // auditing a collection, and only what changed INSIDE an element needs
        // trackElements. Gating both behind it meant an inverse OneToMany that was
        // audited without tracking recorded nothing at all when a line was added or
        // taken away — Doctrine keeps such a change on the element's own reference
        // back, so the owner's collection never goes dirty and nothing else notices.
        foreach (array_keys($metadata->fields) as $field) {
            $wanted = $metadata->trackedElementFields($field);

            if ($added === null && $wanted === null) {
                continue; // no element tracking declared: nothing to look inside for
            }

            // The collection has to be the other side of the very association this
            // element points back through; a second collection of the same class,
            // mapped by another field, is not this element's home.
            if (!$ownerMetadata->hasAssociation($field)
                || !$ownerMetadata->isAssociationInverseSide($field)
                || $ownerMetadata->getAssociationMappedByTargetField($field) !== $association
                || !$element instanceof ($ownerMetadata->getAssociationTargetClass($field))
            ) {
                continue;
            }

            $key = spl_object_id($owner);

            // An element being inserted is named after the flush, not here; the object
            // is what is held until then.
            if ($added !== null) {
                $membership = $this->elementMembership[$key][1] ?? [];
                $held = $membership[$flush] ?? [];
                $identifier = $this->identifierOf($em, $element);

                // Asked of the unit of work, not of the identifier. "It has no id yet"
                // is what an insertion looks like against an identity column — MySQL,
                // SQLite, Postgres under DBAL 4 — where the value arrives with the
                // INSERT. A sequence hands the id out at persist() time instead
                // (Postgres maps a generated column that way under DBAL 3), and an
                // assigned identifier is there from the constructor. Read as "not being
                // inserted", those two ran the representer — the application's code —
                // right here, inside onFlush, before UnitOfWork opens its transaction:
                // one that threw took the application's flush down with it and the row
                // was never written, while the very same code against an identity column
                // committed the row and reported the audit failure afterwards, through
                // the failure policy. The same application with the same configuration
                // must not do opposite things to the data because of how its database
                // hands out identifiers.
                $inserting = $identifier === null || $em->getUnitOfWork()->isScheduledForInsert($element);

                $held[$field.'#'.spl_object_id($element)] = [
                    'at' => ++$this->wrote,
                    'element' => $element,
                    'added' => $added,
                    'field' => $field,
                    'represent' => $metadata->fields[$field] ?? null,
                    // A removal is represented now, while the element still has its
                    // values: Doctrine clears a generated identifier once the row is
                    // gone, and a representer reading one would find nothing after the
                    // flush. An insertion is the other way round — its representer waits
                    // for postFlush, where the row is real, the id is final whichever way
                    // the database gave it out, and a failure travels the policy rather
                    // than the flush.
                    'value' => $added && $inserting ? null : self::represent($element, $metadata->fields[$field] ?? null),
                    'deferred' => $added && $inserting,
                    'id' => $identifier,
                ];
                $membership[$flush] = $held;
                $this->elementMembership[$key] = [$owner, $membership];
                $this->rememberWhoSawTheOwner($key, $flush);

                continue;
            }

            $id = $this->identifierOf($em, $element);

            if ($id === null) {
                continue;
            }

            // From the snapshot rather than the unit of work: it is the same change set
            // while nothing has corrected the element, and the corrected one - with the
            // row's own old side - once something has.
            $changes = (new ChangeSetBuilder($em, $this->comparator))->elementChanges(
                $metadata->objectType,
                $field,
                $element,
                $id,
                $wanted,
                $this->changeSets[spl_object_id($element)] ?? null,
            );

            // Replacing rather than adding, and only then. Asked a second time (after
            // the element's preUpdate) a corrected value has to replace the planned one,
            // and a value put back where it started has to disappear rather than linger
            // as the change that never happened - so this element's keys go first. Only
            // this element's: the same owner holds its other lines here too.
            //
            // The scan is worth avoiding when there is nothing to replace, which is every
            // element of every ordinary flush. It walks everything the owner has
            // collected so far, so ten thousand lines of one order cost fifty million
            // prefix comparisons to discover that none of them matched.
            if ($replacing !== null) {
                $prefix = ElementKey::of($field, $id).'.';

                // Every bucket, not only this flush's. This is the element read again
                // after preUpdate, which is the last word about it in this operation:
                // Doctrine has recomputed, and the statement that follows writes exactly
                // that. An earlier answer about the same element — including one an inner
                // flush really wrote — is superseded, because the owner gets one record
                // for the operation and that record says where the column ended up.
                //
                // The stamps cannot reach this on their own. A value put back where it
                // started produces no change at all, and "no change" has no key to carry a
                // stamp on, so the older answer would win by default. The probe had a line
                // go 1, then 9 by an inner flush, then back to 1 by the outer one: the
                // column never moved and the history said it had.
                foreach ($this->elementChanges[$key][1] ?? [] as $its => $bucket) {
                    foreach (array_keys($bucket) as $name) {
                        if (str_starts_with($name, $prefix) && \in_array(substr($name, \strlen($prefix)), $replacing, true)) {
                            unset($this->elementChanges[$key][1][$its][$name]);
                        }
                    }

                    // A bucket emptied by that is taken away with the keys it held, so that
                    // "the owner has collected nothing" stays one question rather than two.
                    if (($this->elementChanges[$key][1][$its] ?? null) === []) {
                        unset($this->elementChanges[$key][1][$its]);
                    }
                }
            }

            if ($changes === [] && ($this->elementChanges[$key][1] ?? []) === []) {
                // Present but empty reads as "something changed inside" to
                // hasElementChanges(), which is how an update with no changes at all
                // becomes a record.
                unset($this->elementChanges[$key]);

                continue;
            }

            // Written key by key rather than through array_replace(), which copies the
            // whole of what the owner has collected every time another element arrives:
            // the same quadratic cost, in memcpy instead of callbacks.
            if (!isset($this->elementChanges[$key])) {
                $this->elementChanges[$key] = [$owner, []];
            }

            $this->rememberWhoSawTheOwner($key, $flush);

            foreach ($changes as $name => $change) {
                $this->elementChanges[$key][1][$flush][$name] = ['at' => ++$this->wrote, 'change' => $change];
            }
        }
    }

    /**
     * Whether this owner has news from inside a tracked collection, which is reason to
     * keep a record its own columns left empty.
     *
     * **It has not been possible to observe this changing anything, and that is written
     * down rather than acted on.** Wired to answer no, the whole suite still passes:
     * publish() builds a record for every owner that collected something, whether or not
     * one is already pending, so the same document arrives either way — amended in place
     * when this kept it, appended when it did not. Removing publish()'s half *is*
     * observable, and WhatAnElementChangeIsMadeOfTest fails on it.
     *
     * What is left is an ordering argument that could not be turned into a test.
     * Doctrine groups its updates by class, so an audited entity of another class that
     * is updated after the owner's would sit between the two in the pending list — and
     * then keeping the record here, rather than appending it in postFlush, is what puts
     * the owner's line before it in the history. No arrangement of the fixtures produced
     * that order, so the guard stays and is not claimed to be equivalent: "no test tells
     * these apart" is not the same statement as "there is nothing to tell apart", and
     * the mutant on this line is left escaping to say so.
     */
    private function hasElementChanges(object $owner): bool
    {
        $key = spl_object_id($owner);

        return isset($this->elementChanges[$key]) || isset($this->elementMembership[$key]);
    }

    /**
     * Everything a tracked collection has to say about this flush, owner by owner:
     * fields that changed inside an element, keyed "lines.42.quantity", and elements
     * the collection gained or lost, keyed "lines.42" with one side null.
     *
     * @return list<array{0: object, 1: array<string, Change>}>
     */
    private function elementsByOwner(ObjectManager $manager): array
    {
        $em = self::entityManagerOf($manager);
        $byOwner = [];

        foreach ($this->elementChanges as $key => [$owner, $byFlush]) {
            $byOwner[$key] = [$owner, self::theChangesInForce($byFlush)];
        }

        foreach ($this->elementMembership as $key => [$owner, $byFlush]) {
            $changes = $byOwner[$key][1] ?? [];

            foreach (self::theMembershipInForce($byFlush) as $entry) {
                // An inserted element had no identifier when it was collected; it has one now.
                $id = $entry['id'] ?? ($em === null ? null : $this->identifierOf($em, $entry['element']));

                if ($id === null) {
                    continue; // an element with no identifier is nothing the history can point at
                }

                if ($entry['deferred']) {
                    try {
                        // Now it has its identifier, so a representer that reads one has
                        // something to read. It is the application's code, it runs after
                        // the commit, and an exception escaping here would come out of
                        // flush() for a database change that is already real — so it goes
                        // through the failure policy like everything else the listener
                        // does, and only this element is lost.
                        $entry['value'] = self::represent($entry['element'], $entry['represent']);
                    } catch (\Throwable $e) {
                        $this->reportWhileBuilding($e);

                        continue;
                    }
                }

                $changes[ElementKey::of($entry['field'], $id)] = $entry['added']
                    ? new Change(null, $entry['value'])
                    : new Change($entry['value'], null);
            }

            $byOwner[$key] = [$owner, $changes];
        }

        // And the owners whose collection was taken away whole. Those are here for a
        // different reason from the two above: not because their record needs something
        // added to it, but because without this they have no record at all. `clear()`
        // dirties nothing on the owner, so Doctrine raises no event for it, and every
        // record built for an entity is built inside one. The rows went; recordForOwner()
        // is what says so.
        foreach ($this->emptiedCollections as $key => [$owner, $emptied]) {
            $byOwner[$key] ??= [$owner, []];
        }

        return array_values($byOwner);
    }

    /**
     * Which flush's answer about each key is the current one.
     *
     * By the stamp on each entry, the latest winning — which is what a single bucket did
     * by being written over, and what the flush numbers could not do. A flush's number
     * says when it BEGAN: an inner flush always has the higher one, and yet the outer
     * flush it was started from goes on to write its own rows afterwards. Ordered by
     * number, an outer flush recording the value the column finally took lost to the
     * inner flush's earlier value; the probe had the column holding 3 and the history
     * ending at 9.
     *
     * The buckets stay because a flush's share has to be removable, which is a different
     * question from whose answer is the current one.
     *
     * The rule lives here and the two maps that follow it are readers: an entry of one is
     * a Change and an entry of the other is an element's membership, and carrying one
     * template through an accumulator is something the oldest supported static analyser
     * cannot do. So the shapes are read apart and the comparison is written once.
     *
     * @param array<int, array<string, array{at: int}>> $byFlush
     *
     * @return array<string, int> the key, and the flush whose entry about it is current
     */
    private static function whoseAnswerIsCurrent(array $byFlush): array
    {
        $current = [];
        $stamps = [];

        foreach ($byFlush as $flush => $bucket) {
            foreach ($bucket as $name => $entry) {
                $at = $entry['at'];

                if (($stamps[$name] ?? -1) < $at) {
                    $stamps[$name] = $at;
                    $current[$name] = $flush;
                }
            }
        }

        return $current;
    }

    /**
     * What several flushes said changed inside one owner's elements, read as one answer.
     *
     * @param array<int, array<string, array{at: int, change: Change}>> $byFlush
     *
     * @return array<string, Change>
     */
    private static function theChangesInForce(array $byFlush): array
    {
        $changes = [];

        foreach (self::whoseAnswerIsCurrent($byFlush) as $name => $flush) {
            $changes[$name] = $byFlush[$flush][$name]['change'];
        }

        return $changes;
    }

    /**
     * The same for what its collection gained and lost.
     *
     * @param array<int, array<string, array{at: int, element: object, added: bool, field: string, represent: (callable(object): mixed)|null, value: mixed, deferred: bool, id: int|string|null}>> $byFlush
     *
     * @return array<string, array{at: int, element: object, added: bool, field: string, represent: (callable(object): mixed)|null, value: mixed, deferred: bool, id: int|string|null}>
     */
    private static function theMembershipInForce(array $byFlush): array
    {
        $entries = [];

        foreach (self::whoseAnswerIsCurrent($byFlush) as $name => $flush) {
            $entries[$name] = $byFlush[$flush][$name];
        }

        return $entries;
    }

    /**
     * The same for what an emptied collection held, which carries no stamp.
     *
     * It does not need one: this is what a collection held BEFORE a flush emptied it, and
     * two flushes emptying the same collection is two emptyings, of which the later is
     * the one the record being built is about.
     *
     * @template T
     *
     * @param array<int, array<string, T>> $byFlush
     *
     * @return array<string, T>
     */
    private static function inFlushOrder(array $byFlush): array
    {
        if (\count($byFlush) === 1) {
            return reset($byFlush);
        }

        ksort($byFlush);

        return array_replace([], ...array_values($byFlush));
    }

    /**
     * @param (callable(object): mixed)|null $represent
     */
    private static function represent(object $element, ?callable $represent): mixed
    {
        return $represent === null ? null : $represent($element);
    }

    /**
     * Always-recorded context for a record whose changes arrived from tracked elements:
     * build() adds it for a record built during the flush, and a record assembled in
     * postFlush — or amended there — has to keep the same promise, that every history
     * line reads on its own.
     *
     * @param array<string, Change|mixed> $changes
     *
     * @return array<string, Change|mixed>
     */
    private function withContext(?EntityManagerInterface $em, object $owner, array $changes, int $flush): array
    {
        $metadata = $em === null ? null : $this->metadataFactory->for($owner);

        if ($em === null || $metadata === null) {
            return $changes;
        }

        return (new ChangeSetBuilder($em, $this->comparator))->withAlwaysRecorded($owner, $metadata, $changes, $this->contextAsFlushed[$flush][spl_object_id($owner)] ?? [], $this->changeSets[spl_object_id($owner)] ?? []);
    }

    /**
     * What an emptied collection is recorded as, folded into whatever else the owner has
     * to say.
     *
     * Built here rather than merged in as a ready Change, because what an emptied
     * collection is recorded as is the builder's decision — the old side represented from
     * the snapshot the listener kept, the new side from what the field holds now, and the
     * comparator asked whether that counts as a move at all.
     *
     * **On both roads out of publish(), which is the whole point of it being one method.**
     * It used to live inside recordForOwner(), which is only reached by an owner Doctrine
     * raised no event for — and `clear()` raises none, so the case it was written for
     * worked. Replacing the collection instead (`$order->lines = new ArrayCollection()`)
     * dirties the owner, so it has a postUpdate and a pending record, and publish() took
     * the other road: the rows were deleted and the owner's record came back with nothing
     * in it at all. Measured with nothing else going wrong — no nesting, no refusal, no
     * swallowed postFlush — `clear()` recording both lines and the replacement recording
     * an empty update.
     *
     * What the owner already has wins: those are about different fields.
     *
     * @param array<string, Change|mixed> $changes
     *
     * @return array<string, Change|mixed>
     */
    private function withWhatWasEmptied(?EntityManagerInterface $em, object $owner, array $changes, int $flush): array
    {
        $emptied = self::inFlushOrder($this->emptiedCollections[spl_object_id($owner)][1] ?? []);
        $metadata = $em === null ? null : $this->metadataFactory->for($owner);

        if ($emptied === [] || $em === null || $metadata === null) {
            return $changes;
        }

        // Nothing here drops the whole-collection form when the elements said it
        // themselves, and that is measured rather than trusted: the two never arrive
        // together. A ManyToMany emptied leaves a snapshot and no element events, since an
        // element of one reaches back through a collection and is not tracked. An inverse
        // OneToMany with orphanRemoval leaves element events when it is cleared and a
        // snapshot when it is replaced, one or the other. And clearing an inverse
        // collection WITHOUT orphanRemoval does nothing to the database at all -- probed,
        // the rows still pointing at their owner -- so it leaves neither.
        return array_replace(
            (new ChangeSetBuilder($em, $this->comparator))->build($owner, $metadata, [], $emptied, $this->contextAsFlushed[$flush][spl_object_id($owner)] ?? []),
            $changes,
        );
    }

    /**
     * The record for an owner that Doctrine never raised an event for, built after the
     * commit from what onFlush collected. The entity is still managed and its
     * identifier is settled, which is all this needs.
     *
     * @param array<string, Change> $changes
     */
    private function recordForOwner(ObjectManager $manager, object $owner, array $changes, int $flush): ?AuditRecord
    {
        $record = null;

        try {
            $metadata = $this->metadataFactory->for($owner);
            $em = self::entityManagerOf($manager);

            if ($metadata === null || $em === null) {
                return null;
            }

            $id = $this->identifierOf($em, $owner);

            if ($id === null) {
                return null;
            }

            $changes = $this->withWhatWasEmptied($em, $owner, $changes, $flush);

            return (new AuditRecord($metadata->objectType, $id, AuditEvent::UPDATE, origin: AuditOrigin::Doctrine))
                ->withChanges($this->withContext($em, $owner, $changes, $flush));
        } catch (\Throwable $e) {
            $this->reportWhileBuilding($e, $record);

            return null;
        }
    }

    /**
     * The change set to build a record from: what the unit of work still has, filled in
     * from the snapshot taken in onFlush for whatever it lost.
     *
     * The unit of work wins wherever it still knows a field. A preUpdate listener may
     * legitimately change the entity after the snapshot was taken — Doctrine then calls
     * recomputeSingleEntityChangeSet() and merges that in — and the record has to
     * reflect what was actually written, not what was planned.
     *
     * @return array<string, mixed>
     */
    private function changeSetFor(EntityManagerInterface $em, object $entity): array
    {
        $current = $em->getUnitOfWork()->getEntityChangeSet($entity);
        $snapshot = $this->changeSets[spl_object_id($entity)] ?? [];

        if ($current === [] && $snapshot !== []) {
            $this->reportLostChangeSets($entity);

            return self::asTheEntityNowStands($em, $entity, $snapshot);
        }

        return self::sidesFrom($current, $snapshot);
    }

    /**
     * Each side from where it is true: the old from the snapshot onFlush took, the new
     * from the change set as it finally stands.
     *
     * Doctrine does not keep the row's own value around for this. computeChangeSet()
     * ends by writing the current values into originalEntityData, so a preUpdate
     * listener that corrects a field and calls recomputeSingleEntityChangeSet() gets a
     * change set whose "old" is the value that was planned a moment ago - never in the
     * database, never true. Taking the current set whole then wrote a record saying the
     * row went from a value it never held.
     *
     * @param array<string, mixed> $current  what the unit of work says now
     * @param array<string, mixed> $snapshot what onFlush saw, before any correction
     *
     * @return array<string, mixed>
     */
    private static function sidesFrom(array $current, array $snapshot): array
    {
        $merged = $snapshot;

        foreach ($current as $field => $sides) {
            $planned = $snapshot[$field] ?? null;

            if (!\is_array($sides) || !\is_array($planned)) {
                $merged[$field] = $sides;

                continue;
            }

            $merged[$field] = [$planned[0] ?? null, $sides[1] ?? null];
        }

        return $merged;
    }

    /**
     * The snapshot, with the "new" side of each field read off the entity.
     *
     * The snapshot is what onFlush saw; the row holds what the entity held when the
     * UPDATE was built, which is later. A preUpdate listener correcting a value —
     * Doctrine recomputes the change set for exactly that — is the ordinary way the two
     * differ, and the merge above normally covers it because the unit of work still has
     * the recomputed set. On the path that gets here it does not: another listener's
     * flush emptied it. Handing back the snapshot then writes a record that disagrees
     * with the row, and an audit trail that is wrong is worse than one that is thin.
     *
     * Only mapped fields, and only their new side: an association's old and new are
     * objects the snapshot already holds, and reading one back off the entity would say
     * nothing the record does not already know.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private static function asTheEntityNowStands(EntityManagerInterface $em, object $entity, array $snapshot): array
    {
        $metadata = $em->getClassMetadata($entity::class);

        foreach ($snapshot as $field => $sides) {
            if (!\is_array($sides) || !\array_key_exists(1, $sides) || !$metadata->hasField((string) $field)) {
                continue;
            }

            try {
                $sides[1] = $metadata->getFieldValue($entity, (string) $field);
            } catch (\Throwable) {
                continue; // unreadable for whatever reason: the snapshot's own value stands
            }

            $snapshot[$field] = $sides;
        }

        return $snapshot;
    }

    /**
     * Says out loud what used to be silent.
     *
     * Without this the symptom is an update whose "changes" are empty — no error, no
     * failed write, nothing in any log — and the cause sits in a listener that has
     * nothing to do with auditing. Finding it took three days once.
     */
    private function reportLostChangeSets(object $entity): void
    {
        if ($this->reportedLostChangeSets) {
            return;
        }

        $this->reportedLostChangeSets = true;

        $this->logger->warning(
            'The unit of work had no change set left for {entity}; the audit record was built from the snapshot taken in onFlush. Something called flush() from inside a lifecycle listener of this flush: UnitOfWork::commit() ends in postCommitCleanup(), which empties entityChangeSets — and with them extraUpdates, collectionUpdates, orphanRemovals and collectionDeletions of the flush still running, so more than the history may be missing. Move that work to postFlush.',
            ['entity' => $entity::class]
        );
    }

    private function recordFor(PostPersistEventArgs|PostUpdateEventArgs|PreRemoveEventArgs $args, string $event, bool $withChanges = true): ?AuditRecord
    {
        $entity = $args->getObject();
        $record = null;

        try {
            $metadata = $this->metadataFactory->for($entity);

            if ($metadata === null) {
                return null;
            }

            $em = self::entityManagerOf($args->getObjectManager());

            if ($em === null) {
                return null;
            }

            $this->assertAuditedFieldsAreThere($em, $entity, $metadata);
            $this->assertTrackedCollectionsAreServable($em, $entity, $metadata);

            $id = $this->identifierOf($em, $entity);

            if ($id === null) {
                return null; // nothing to attach a history to
            }

            $record = new AuditRecord($metadata->objectType, $id, $event, origin: AuditOrigin::Doctrine);

            if ($withChanges) {
                $record = $record->withChanges((new ChangeSetBuilder($em, $this->comparator))->build($entity, $metadata, $this->changeSetFor($em, $entity), self::inFlushOrder($this->emptiedCollections[spl_object_id($entity)][1] ?? []), $this->contextAsFlushed[$this->collectingNow($em)][spl_object_id($entity)] ?? []));
            }

            return $record;
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, $record);

            return null;
        }
    }

    /**
     * Identifiers are ints, strings, objects with a string form (Uuid, Ulid, enums)
     * or — in a composite key — other entities, represented by their own identifier.
     */
    private function identifierOf(EntityManagerInterface $em, object $entity): int|string|null
    {
        $values = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);

        if ($values === []) {
            return null;
        }

        $parts = array_map(fn (mixed $value): string => $this->stringify($em, $value), array_values($values));

        if (\count($parts) === 1) {
            $only = reset($values);

            return \is_int($only) ? $only : $parts[0];
        }

        // A composite key, joined — and each part escaped first, or the join is ambiguous:
        // ["a|b", "c"] and ["a", "b|c"] both read as a|b|c, which is two entities sharing
        // one identity in the history. A part that holds neither "|" nor "\" is untouched,
        // so the usual "42|like" is written exactly as it always was.
        return implode('|', array_map(
            static fn (string $part): string => str_replace(['\\', '|'], ['\\\\', '\\|'], $part),
            $parts,
        ));
    }

    private function stringify(EntityManagerInterface $em, mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            \is_object($value) => (string) ($this->identifierOf($em, $value) ?? throw new DeclarationMistake(sprintf('%s has no identifier yet and cannot be part of an audit object id.', get_debug_type($value)))),
            default => throw new DeclarationMistake(sprintf('Cannot use a %s as an audit object id.', get_debug_type($value))),
        };
    }
}
