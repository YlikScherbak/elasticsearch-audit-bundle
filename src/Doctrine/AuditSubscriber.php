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
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\DepartedObjects;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ElementFieldRuns;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\EntityRowRuns;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\HistoryReplay;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowBinding;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowIdentity;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
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
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
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
    public const EVENTS = [Events::preFlush, Events::onFlush, Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove, Events::postFlush, Events::onClear, Events::postLoad];

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
     * Where the connection's log stood when each pending record was taken, and the class of
     * the row it is about, by the same index.
     *
     * Whether a record stands is the fate of the execution it describes, not its flush's: an
     * application may roll back to a savepoint of its own inside a flush that goes on to
     * commit, and a flush that dies inside one that commits leaves the other's statements
     * where they were. A clear decides neither. So each record is tied, when it is taken, to
     * the positions of its statements in the log ({@see self::statementBehind()}) -- one per
     * table of its class, for a hierarchy that writes several.
     *
     * @var list<list<int>|null>
     */
    private array $pendingAt = [];

    /**
     * The statements of each flush's window of the log, by the row they wrote, for
     * {@see self::statementBehind()}: read once, as far as the log had got, and read on from
     * there -- a flush writing a thousand rows is not a thousand readings of its log.
     *
     * @var array<int, array{read: int, rows: array<string, list<int>>, tables: array<string, list<int>>}>
     */
    private array $executions = [];

    /** @var array<int, true> the statements a record has been tied to: no two records share one */
    private array $claimedExecutions = [];

    /**
     * The key of each row a removal drafted in preRemove is about, by the entity's object id:
     * a generated identifier is cleared once the DELETE has run, before postRemove, and the
     * record needs it to find its statement ({@see self::$pendingAt}).
     *
     * @var array<int, array<string, mixed>|null>
     */
    private array $pendingRemovalKeys = [];

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

    /** Said once per flush: a hundred entities would otherwise say the same thing a hundred times. */
    private bool $reportedLostChangeSets = false;

    /**
     * The execution each entity's latest UPDATE announcement was tied to, and the flush a
     * second announcement of it hands its provenance to, by the execution's first statement
     * ({@see holdWhatItsCollectionsSay()}).
     *
     * @var \WeakMap<object, list<int>>
     */
    private \WeakMap $lastExecutionOf;

    /** @var array<int, int> */
    private array $announcedUnder = [];

    /**
     * What an owning collection's snapshot said at each post* event of its owner: the old and
     * the new side, represented then, and the execution it was taken beside.
     *
     * Transitional, until the join rows are facts of the log too (5.3): an entity's own record
     * is the log's, and what its owning many-to-many went through is not a fact of its row.
     * Doctrine replaces the collection's snapshot before postFlush, so it is read where the
     * events have always read it. Joined to the record of the execution it was taken beside --
     * by that execution's first statement, not by the owner, or two executions of one row in
     * one operation would share one snapshot -- and a record of its own when the owner had no
     * UPDATE to be beside: Doctrine announces a postUpdate for an owner whose only change is
     * its collection. A listener ahead of this one that flushes in its own postUpdate has
     * already had the snapshot replaced by then, which is why this does not claim to be where
     * a collection's history is safe: 5.3 is.
     *
     * @var list<array{execution: list<int>, position: int, flush: int, owner: object, key: array<string, mixed>|null, objectType: string, id: int|string, changes: array<string, Change>}>
     */
    private array $collectionSnapshots = [];

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
     * How far the connection's log has been read into the history: every fact of a
     * statement at or before this position has been turned into records, or dropped with
     * the flush it belonged to. A position in the log and not a flush number: without
     * savepoints several flushes' statements share one frame, and a flush that ran nothing
     * of its own still has to move this on past the ones before it.
     */
    private int $factsReadThrough = 0;

    /**
     * Where the statement log stood when each flush was about to begin, by flush -- handed
     * back to it when the flush claims the frame its statements ran in -- and the last
     * statement at that moment.
     *
     * @var array<int, array{0: int, 1: int|null, 2: int}>
     */
    private array $statementMarks = [];

    /** @var array<int, int> by flush: the last statement it has claimed what ran up to */
    private array $claimedThrough = [];

    /** @var array<int, bool> by flush: whether it planned a collection's rows and no entity's */
    private array $collectionsOnly = [];

    /**
     * Where each operation this listener saw began and ended in the log, the last one open
     * while it runs: what tells a statement nobody owns that ran during a flush -- a hole in
     * how flushes claim what they run -- from the application's own SQL between them.
     *
     * @var list<array{0: int, 1: int|null}>
     */
    private array $windows = [];

    /**
     * The pending lifecycle record of an entity -- create or update -- by the entity, so what
     * its elements did can be folded into it.
     *
     * Only an index: the record is in {@see self::$pending} and goes nowhere if the entity
     * does. By the object, weakly, and not by its object id -- PHP hands a freed object's id to
     * the next one, and after a clear the entity a record was taken for may be gone while the
     * record waits, so an id could fold another owner's news into it.
     *
     * @var \WeakMap<object, int>
     */
    private \WeakMap $pendingIndexByEntity;

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
     * Keyed by the owner's object id and then by the flush that saw the emptying: the
     * owner is what the record is built for, and the flush inside keeps two flushes'
     * emptyings apart, so that a flush that dies takes its share with it.
     *
     * Each field's members are kept under their own identifiers, read in onFlush while
     * the rows are still there: Doctrine clears a generated id once a row is gone, and
     * everything that reads this map reads it after the commit.
     *
     * @var array<int, array{0: object, 1: array<int, array<string, array<int|string, object>>>}>
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

    /*
     * $statements: what the audited connection did, written by the driver middleware the
     * bundle registers on it. Required: what a flush did is read from there, and a listener
     * without it would have a history with nothing to be read from -- not a smaller one.
     */
    public function __construct(
        private readonly AuditWriter $writer,
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly StatementLog $statements,
        private readonly bool $skipEmptyUpdates = true,
        private readonly ValueComparatorInterface $comparator = new ValueComparator(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->neverWritten = new \WeakMap();
        $this->pendingIndexByEntity = new \WeakMap();
        $this->lastExecutionOf = new \WeakMap();
        $this->rows = new RowMemory($statements);

        // What ran before this listener existed is nobody's history it can account for: it
        // heard none of the events that name those rows.
        $this->factsReadThrough = $statements->position();
        $identity = new RowIdentity($this->identifierOf(...), $this->identifierFrom(...));
        $this->elementRuns = new ElementFieldRuns($metadataFactory, $comparator, $this->logger, $identity);
        $this->entityRuns = new EntityRowRuns($metadataFactory, $comparator, $identity, $this->logger);
        $this->departed = new DepartedObjects();
    }

    private readonly ElementFieldRuns $elementRuns;

    /** What an entity's own row went through: its records, from the log's facts. */
    private readonly EntityRowRuns $entityRuns;

    /** What the application removed, for a record that names it once it is gone. */
    private readonly DepartedObjects $departed;

    /**
     * What each watched row held: remembered at preFlush and at a load inside a flush, and
     * settled once a flush's history has been published.
     */
    private readonly RowMemory $rows;

    /**
     * Before computeChangeSets(): the one moment Doctrine still remembers each row as it was
     * last written, and not as the flush means to write it.
     */
    public function preFlush(PreFlushEventArgs $args): void
    {
        $em = self::entityManagerOf($args->getObjectManager());

        if ($em !== null) {
            $this->rows->rememberWhatIsManaged($em);
        }
    }

    /**
     * A row loaded while a flush runs -- found again after a clear(), from inside a listener --
     * is remembered as it loads. One loaded outside a flush waits for the next preFlush, and a
     * find() does not pay for the audit.
     */
    public function postLoad(PostLoadEventArgs $args): void
    {
        $em = self::entityManagerOf($args->getObjectManager());

        if ($em !== null) {
            $this->rows->rememberLoaded($em, $args->getObject(), $this->flushes !== []);
        }
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

        // Where the connection stands before Doctrine begins this flush's transaction. The
        // frame is claimed once one of the flush's statements has run, not handed ahead to
        // whatever opens next: a listener behind this one may open a transaction of its own
        // first, and a flush refused before it began must not lend its number to the next
        // transaction the application opens.
        //
        // Taken before beginFlush() and kept after it: finding an abandoned flush behind this
        // one forgets everything that flush left, the marks with it, and this flush's own mark
        // went too -- its statements then belonged to nobody.
        $mark = [...$this->statements->mark(), $this->statements->position()];

        $this->beginFlush($em, $flush);
        $this->statementMarks[$flush] = $mark;
        $this->statements->aFlushStarts();

        $this->provenance[$flush] = $this->writer->provenance();

        $uow = $em->getUnitOfWork();

        // A flush that plans an entity's row raises an event for it when it runs, and claims
        // its frame there. One that plans only a collection's rows raises none, and if its
        // postFlush is swallowed too it has nothing to claim its frame by but being found over.
        $this->collectionsOnly[$flush] = $uow->getScheduledEntityInsertions() === []
            && $uow->getScheduledEntityUpdates() === []
            && $uow->getScheduledEntityDeletions() === []
            && ($uow->getScheduledCollectionDeletions() !== [] || $uow->getScheduledCollectionUpdates() !== []);

        foreach ($uow->getScheduledEntityUpdates() as $element) {
            // Taken for every update, not only the audited ones: deciding that here
            // would mean reading each entity's declaration first. What it costs is
            // measured rather than assumed - about 60 bytes per entity, the array's own
            // structure, because PHP shares the values rather than copying them. The
            // figure and the flush it came from are in the README.
            $this->rememberChangeSet($element, $uow->getEntityChangeSet($element), $flush);

            $this->aboutTheOwnersOf($em, $element, $flush);
        }

        // A line added to or taken from an inverse collection never makes the collection
        // itself dirty — Doctrine tracks the owning side, which is the line's own
        // reference back. The unit of work knows about it all the same.
        foreach ($uow->getScheduledEntityInsertions() as $element) {
            // An insertion's change set dies in the same cleanup as an update's: a
            // create whose postPersist runs after somebody's nested flush would
            // otherwise say an entity appeared with no values at all.
            $this->rememberChangeSet($element, $uow->getEntityChangeSet($element), $flush);

            $this->aboutTheOwnersOf($em, $element, $flush);
        }

        foreach ($uow->getScheduledEntityDeletions() as $element) {
            $this->aboutTheOwnersOf($em, $element, $flush);
        }

        $this->rememberWhatIsBeingEmptied($em, $flush);
    }

    /**
     * Keeps what a collection scheduled for deletion held, before the flush deletes it.
     *
     * The rows answer, and they are the only thing that does. The rows are still there to
     * be read because this runs in onFlush, before the flush opens its transaction, and
     * they are read underneath the application's filters because what this needs is the
     * members the DELETE will take. One SELECT, and only for an audited collection
     * somebody actually emptied.
     *
     * Doctrine's snapshot of the collection was the first answer and is not an answer at
     * all: it is the membership as of the last time that collection was synchronised with
     * the database, which is a different moment from this one on every road that changes
     * a membership without touching the collection. The comment on the read itself lists
     * them.
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
                $mappedBy = CollectionRowsQuery::entry($mapping, 'mappedBy');

                if ($mappedBy !== null && CollectionRowsQuery::entry($mapping, 'orphanRemoval') !== true) {
                    continue;
                }

                // An inverse collection's emptying is its elements' rows going, which the log
                // says: all it needs is the rows, and a collection nobody loaded has rows
                // nothing remembered. Read once, as rows. An owning one's is its owner's own
                // field, recorded from the change set below.
                if ($mappedBy !== null) {
                    if (!$collection->isInitialized()) {
                        $this->rows->rememberTheRowsOf($em, $owner, $field);
                    }

                    continue;
                }

                $target = CollectionRowsQuery::entry($mapping, 'targetEntity');
                $target = \is_string($target) && $target !== '' ? $target : null;

                // What the rows hold, and never what the collection remembers holding.
                //
                // The snapshot was the first source here, with the rows asked only when
                // there was no snapshot to read. It is Doctrine's record of the collection
                // as of the last time it was synchronised with the database, which is a
                // different moment from this one whenever anything went in between:
                //
                //   - a line moved to another owner by its own side leaves this
                //     collection clean, so nothing re-snapshots it and the line it no
                //     longer holds is still in the snapshot;
                //   - a deletion still on the schedule after it was carried out is handed
                //     to the next flush with the membership of the one before, which is
                //     what a postFlush listener that throws leaves behind: postFlush is
                //     dispatched before postCommitCleanup() and nothing stands between
                //     them, so nothing is cleared.
                //
                // A third was written here and was wrong: that a swallowed postFlush stops
                // takeSnapshot() from running. It does not. UnitOfWork::commit() takes new
                // snapshots from every visited collection BEFORE it dispatches postFlush
                // (ORM 3.6.8, UnitOfWork.php:473 against :476), so a collection that flush
                // visited is re-snapshotted whatever happens afterwards. The sequences
                // behind that line are real and the fix is right; the reason given for
                // them was not, and a wrong reason in a comment is a claim the next
                // person builds on.
                //
                // Every one of those was a record of rows this operation did not touch, or
                // a silence about rows it did. Generated sequences found twenty of them in
                // two hundred, in all three shapes at once: a loss named twice, a loss
                // named for the wrong owner, and a line added since the snapshot losing
                // its row with no record at all.
                //
                // Underneath the filters, because what this needs is the members the
                // DELETE will take, and a DELETE by the owner's key takes them whether or
                // not a soft-delete filter would have shown them. Asked through the
                // persister as it stands, a filter that hides half the elements halved the
                // record, and one that hides all of them left no record at all while the
                // rows went.
                //
                // The rows are still there to be read: this runs in onFlush, before the
                // flush opens its transaction. One SELECT per emptied collection, which is
                // what the question it replaced already cost, and it answers more: an
                // empty result is "nothing left to empty" and needs no second query to
                // establish it.
                $held = self::withoutTheApplicationsFilters(
                    $em,
                    static fn (): array => $uow->getCollectionPersister($mapping)->slice($collection, 0, null),
                );

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

                // Kept under each element's identifier, which is readable here and is not
                // readable later: Doctrine clears a generated id once the row is gone, and
                // everything that reads this map reads it after the commit. Taking the ids
                // now is what lets a departure the element form already named be matched to
                // the element that departed rather than to one that looks like it.
                $byIdentifier = [];

                foreach ($held as $element) {
                    if (!\is_object($element)) {
                        continue;
                    }

                    $id = $this->identifierOf($em, $element);

                    // An element with no identifier keeps its place under a number of its
                    // own. It cannot be matched to anything the element form named -- that
                    // form is keyed by identifier -- and staying in the emptying is the
                    // safe half of that: named twice is visible, named never is not.
                    $byIdentifier[$id ?? \count($byIdentifier)] = $element;
                }

                if ($byIdentifier === []) {
                    continue;
                }

                $this->emptiedCollections[spl_object_id($owner)][0] = $owner;
                $this->rememberWhoSawTheOwner(spl_object_id($owner), $flush);
                $this->emptiedCollections[spl_object_id($owner)][1][$flush][$field] = array_map(
                    fn (object $element): object => $this->asTheRowHasIt($em, $element),
                    $byIdentifier,
                );
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
        $manager = self::entityManagerOf($args->getObjectManager());

        if ($manager === null) {
            return;
        }

        $this->rows->rememberPersisted($manager, $args->getObject());
        $collecting = $this->collectingNowAfterAStatement($manager);
        $this->theRowNowMatchesTheObject($args->getObject());
        $this->holdWhatItsCollectionsSay($manager, $args->getObject(), $collecting, StatementShape::INSERT);
    }
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        // What the row went through is the log's; this is the flush claiming what it ran, and
        // what an owning collection's snapshot says while Doctrine still has it.
        $manager = self::entityManagerOf($args->getObjectManager());

        if ($manager === null) {
            return;
        }

        $collecting = $this->collectingNowAfterAStatement($manager);
        $this->theRowNowMatchesTheObject($args->getObject());
        $this->holdWhatItsCollectionsSay($manager, $args->getObject(), $collecting, StatementShape::UPDATE);
    }

    /**
     * The declaration checked, the execution the event announces tied to it, and what the
     * entity's owning collections say -- the one part of an entity's record that is not a fact
     * of its row yet ({@see self::$collectionSnapshots}).
     */
    private function holdWhatItsCollectionsSay(EntityManagerInterface $em, object $entity, int $flush, string $kind): void
    {
        try {
            $metadata = $this->checkTheDeclaration($em, $entity);

            if ($metadata === null) {
                return;
            }

            $this->warnOfALostChangeSet($em, $entity);

            // Tied whether or not it has a collection to speak of: an announcement is of one
            // execution, and the next one of the same row is of the next.
            $execution = $this->statementBehind($em, $entity, $flush, $kind);

            if ($kind === StatementShape::UPDATE && $execution !== []) {
                $this->lastExecutionOf[$entity] = $execution;
            } elseif ($kind === StatementShape::UPDATE && isset($this->lastExecutionOf[$entity])) {
                // The same UPDATE announced a second time: no statement of the row is left that
                // an announcement has not been tied to. A flush started from a lifecycle
                // listener runs on the outer flush's unit of work and carries out whatever it
                // finds scheduled -- the outer flush's own remaining updates too -- and the
                // outer flush, resuming its list, announces a row somebody else already wrote.
                // The change was the outer flush's, made before the nested one began, and its
                // commit is the one the row hangs off: its moment, actor and context are the
                // outer flush's, the flush announcing it now -- as they have always been.
                $this->announcedUnder[$this->lastExecutionOf[$entity][0]] = $flush;
            }
            $classMetadata = $em->getClassMetadata($entity::class);
            $collections = array_filter(
                $metadata->fields,
                static fn (string $field): bool => $classMetadata->isCollectionValuedAssociation($field) && !$classMetadata->isAssociationInverseSide($field),
                \ARRAY_FILTER_USE_KEY,
            );

            if ($collections === []) {
                return;
            }

            // Represented now, while the snapshot is Doctrine's: values, not the collection.
            $changes = (new ChangeSetBuilder($em, $this->comparator))->build($entity, new AuditMetadata($metadata->objectType, $collections), []);
            $id = $this->identifierOf($em, $entity);

            if ($changes === [] || $id === null) {
                return;
            }

            $this->collectionSnapshots[] = [
                'execution' => $execution,
                'position' => $this->statements->position(),
                'flush' => $flush,
                'owner' => $entity,
                'key' => RowMemory::keyColumns($em, $entity),
                'objectType' => $metadata->objectType,
                'id' => $id,
                'changes' => $changes,
            ];
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, null);
        }
    }

    /**
     * Says out loud what Doctrine does silently, to the application's flush and not to this
     * listener's history.
     *
     * A flush started from a lifecycle listener of the flush still running ends in
     * UnitOfWork::postCommitCleanup(), which empties that flush's change sets -- and with them
     * its extra updates, collection updates, orphan removals and collection deletions. The
     * history is read from the connection and is not what it costs any more; what the running
     * flush was still to write may be. The one place this listener can see it is an entity
     * announced with nothing left of the change set it had in onFlush: said once per flush, by
     * class, and with where to move the work. Finding such a listener once took three days.
     */
    private function warnOfALostChangeSet(EntityManagerInterface $em, object $entity): void
    {
        if ($this->reportedLostChangeSets
            || ($this->changeSets[spl_object_id($entity)] ?? []) === []
            || $em->getUnitOfWork()->getEntityChangeSet($entity) !== []
        ) {
            return;
        }

        $this->reportedLostChangeSets = true;

        $this->logger->warning(
            'The unit of work had no change set left for {entity}: something called flush() from inside a lifecycle listener of this flush, and UnitOfWork::commit() ends in postCommitCleanup(), which empties entityChangeSets -- and with them extraUpdates, collectionUpdates, orphanRemovals and collectionDeletions of the flush still running. The audit history is read from what the connection ran and is not affected; what the running flush had still to write may be. Move that work to postFlush.',
            ['entity' => $entity::class]
        );
    }

    /**
     * An audited entity's declaration, once it is known to be one that can be honoured; null
     * for an entity nobody audits. What cannot be honoured is refused -- raised, for the
     * caller to put through the policy.
     */
    private function checkTheDeclaration(EntityManagerInterface $em, object $entity): ?AuditMetadata
    {
        $metadata = $this->metadataFactory->for($entity);

        if ($metadata !== null) {
            $this->assertAuditedFieldsAreThere($em, $entity, $metadata);
            $this->assertTrackedCollectionsAreServable($em, $entity, $metadata);
        }

        return $metadata;
    }
    public function preRemove(PreRemoveEventArgs $args): void
    {
        // Every one, audited or not: what a record may have to name is any entity, and the
        // row's key is still on it here.
        $leaving = self::entityManagerOf($args->getObjectManager());

        if ($leaving === null) {
            return;
        }

        $this->departed->leaving($leaving, $args->getObject());

        try {
            $this->checkTheDeclaration($leaving, $args->getObject());
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, null);
        }
    }
    public function postRemove(PostRemoveEventArgs $args): void
    {
        $manager = self::entityManagerOf($args->getObjectManager());

        if ($manager !== null) {
            $this->collectingNowAfterAStatement($manager);
        }

        $this->theRowNowMatchesTheObject($args->getObject());
        $this->departed->gone($args->getObject(), $this->statements->position());
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

        // A flush that ran no entity statement -- only a collection's -- has had no event to
        // claim its frame by. The flush finishing is the one whose level the connection is
        // back at, not the one on top: a nested flush refused before it began is still on
        // the stack here, and claiming for it hands it whatever transaction the application
        // opened after it died.
        if ($em !== null) {
            $level = $em->getConnection()->getTransactionNestingLevel();

            foreach (array_reverse($this->flushes) as $entry) {
                if ($entry['level'] === $level) {
                    $this->claimTheFrameOf($entry['flush']);

                    break;
                }
            }
        }

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

            // Whatever became of the publishing: what the rows hold is a fact about the
            // database, and it is folded in once no transaction can still roll it back. The log
            // lets go of what the rows now hold, and not before.
            if ($em !== null && $this->rows->settle($em)) {
                $this->statements->forgetUpTo($this->statements->position());
            }
        }
    }

    /**
     * @param \Doctrine\ORM\EntityManagerInterface|null $em
     */
    private function publish(ObjectManager $manager, ?EntityManagerInterface $em): void
    {
        $em = self::entityManagerOf($manager) ?? $em;
        $records = [];
        $collected = [];

        // The whole of this is inside the failure policy: this is postFlush, the transaction
        // has committed, and an exception escaping would come out of flush() for a database
        // change that is already real. What the policy cannot do here is undo it.
        if ($em !== null) {
            foreach ($this->drafts($em, consume: true) as $draft) {
                try {
                    $records[] = $this->recordOfTheDraft($em, $draft);
                    $collected[] = $draft['flush'];
                } catch (\Throwable $e) {
                    $this->reportWhileBuilding($e);
                }
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
     * How many executions of an audited entity's row the log took back since the history was
     * last read into: what a flush that died wrote, and what it would have had records of.
     * Statements of one change, a table at a time, are one.
     */
    private function executionsTheLogTookBack(EntityManagerInterface $em): int
    {
        $count = 0;
        $last = null;

        for ($at = $this->factsReadThrough + 1, $to = $this->statements->position(); $at <= $to; ++$at) {
            $entry = $this->statements->statement($at);
            $shape = $entry === null ? null : StatementShape::read($entry['sql']);
            $binding = $entry === null || $shape === null ? null : RowBinding::of($em, $shape, $entry['params']);

            if ($shape === null || $binding === null || $binding->kind !== RowBinding::ROW || $binding->class === null
                || $this->statements->fate($at) !== StatementLog::VOID
                || $this->metadataFactory->for($em->getClassMetadata($binding->class)->newInstance()) === null
            ) {
                $last = null;

                continue;
            }

            $row = [$shape->kind, $em->getClassMetadata($binding->class)->rootEntityName, $binding->key === null ? null : self::rowOf($binding->key)];

            // The next table of the same change: the same kind and hierarchy, and a row that is
            // the same one or one whose key the statement does not carry.
            if ($last === null || $last[0] !== $row[0] || $last[1] !== $row[1] || ($last[2] !== null && $row[2] !== null && $last[2] !== $row[2])) {
                ++$count;
            }

            $last = $row;
        }

        return $count;
    }

    /**
     * How many records the flush on the stack has collected: exactly as many as publishing
     * it would write -- counted by building them, on the same road ({@see drafts()}), since two
     * counts kept in step by hand are how a flush whose only news came from inside a
     * collection was once said to have collected nothing, and its records were dropped.
     */
    private function collectedSoFar(?EntityManagerInterface $em): int
    {
        return $em === null ? 0 : \count($this->drafts($em, consume: false));
    }

    /**
     * The records of what is being published, each before it is given its context, in the
     * order they are written -- asked by publish() and, to count them, by a flush that finds
     * another's state behind it: one road, so that what is counted is what is written.
     *
     * - What each execution did to an audited entity's row, from the log's facts
     *   ({@see EntityRowRuns}), in the order the statements ran, with what the entity's owning
     *   collections said beside it ({@see self::$collectionSnapshots}); an update of nothing is
     *   nothing, under skip_empty_updates.
     * - What an owning collection said beside no execution of its owner, where the event was.
     * - An owning collection a flush took away whole, into the owner's record or one of its
     *   own (until 5.3, too).
     * - What changed inside elements of tracked collections: an owner's first run joins the
     *   record it has for that flush, and after that nothing goes back into an earlier record.
     *
     * The context each is given is the row's once the last execution it holds ran: the
     * entity's own execution's, or its elements' when one of theirs ran later.
     *
     * @return list<array{objectType: string, id: int|string, event: string, flush: int, owner: object|null, class: class-string|null, changes: array<string, mixed>, context: array<string, mixed>, at: int}>
     */
    private function drafts(EntityManagerInterface $em, bool $consume): array
    {
        $failed = $consume
            ? function (\Throwable $e): void {
                $this->reportWhileBuilding($e);
            }
            : static function (\Throwable $e): void {
            };
        $replay = $this->rows->replayed($em);
        $besides = [];
        $alone = [];

        foreach ($this->collectionSnapshots as $snapshot) {
            if ($this->theLogTookBack($snapshot['execution'])) {
                continue;
            }

            if ($snapshot['execution'] === []) {
                $alone[] = $snapshot;
            } else {
                $besides[$snapshot['execution'][0]] = $snapshot;
            }
        }

        // Ordered by where each happened: an execution by its first statement, a snapshot
        // beside none after the statement the log had reached when it was taken.
        $ordered = [];

        foreach ($this->entityRuns->of($em, $replay, $this->statements, $this->factsReadThrough, $this->departed, $consume, $this->ranDuringAFlush(...), $failed) as $run) {
            $changes = $run['bare'];
            $snapshot = $besides[$run['at'][0]] ?? null;
            unset($besides[$run['at'][0]]);

            if ($snapshot !== null && $run['event'] !== AuditEvent::REMOVE) {
                $changes = array_replace($changes, $snapshot['changes']);
            }

            if ($run['event'] === AuditEvent::UPDATE && $this->skipEmptyUpdates && $changes === []) {
                continue;
            }

            $ordered[] = [2 * $run['at'][0], [
                'objectType' => $run['objectType'],
                'id' => $run['id'],
                'event' => $run['event'],
                'flush' => $this->announcedUnder[$run['at'][0]] ?? $run['flush'],
                'owner' => $run['entity'],
                'class' => $run['class'],
                'changes' => $changes,
                'context' => $run['context'],
                'at' => $run['at'][\count($run['at']) - 1],
            ]];
        }

        foreach ([...$alone, ...array_values($besides)] as $snapshot) {
            $ordered[] = [2 * $snapshot['position'] + 1, [
                'objectType' => $snapshot['objectType'],
                'id' => $snapshot['id'],
                'event' => AuditEvent::UPDATE,
                'flush' => $snapshot['flush'],
                'owner' => $snapshot['owner'],
                'class' => $snapshot['owner']::class,
                'changes' => $snapshot['changes'],
                'context' => $this->contextWhere($em, $replay, $snapshot['owner']::class, $snapshot['key'], $snapshot['position']),
                'at' => $snapshot['position'],
            ]];
        }

        usort($ordered, static fn (array $one, array $other): int => $one[0] <=> $other[0]);
        /** @var list<array{objectType: string, id: int|string, event: string, flush: int, owner: object|null, class: class-string|null, changes: array<string, mixed>, context: array<string, mixed>, at: int}> $drafts */
        $drafts = array_column($ordered, 1);
        $byOwner = [];

        foreach ($drafts as $index => $draft) {
            if ($draft['event'] !== AuditEvent::REMOVE) {
                $byOwner[$draft['objectType'].'|'.$draft['id']] = $index;
            }
        }

        foreach ($this->ownersWhoseCollectionWasEmptied() as $owner) {
            try {
                // The flush that saw this owner, which is not always the one publishing.
                $flush = $this->ownerFlush[spl_object_id($owner)] ?? self::NO_FLUSH;
                $metadata = $this->metadataFactory->for($owner);
                $id = $this->identifierOf($em, $owner);

                if ($metadata === null || $id === null) {
                    continue;
                }

                $name = $metadata->objectType.'|'.$id;
                $index = $byOwner[$name] ?? null;

                if ($index !== null) {
                    // What the collection held before the flush took it away is the emptying's
                    // to say: the snapshot of a replaced collection starts from nothing.
                    $draft = $drafts[$index];
                    $draft['changes'] = array_replace($draft['changes'], $this->withWhatWasEmptied($em, $owner, [], $flush));
                    $drafts[$index] = $draft;

                    continue;
                }

                $key = RowMemory::keyColumns($em, $owner);
                $drafts[] = [
                    'objectType' => $metadata->objectType,
                    'id' => $id,
                    'event' => AuditEvent::UPDATE,
                    'flush' => $flush,
                    'owner' => $owner,
                    'class' => $owner::class,
                    'changes' => $this->withWhatWasEmptied($em, $owner, [], $flush),
                    // Where the replay stands: the emptying is no fact of the log's yet (5.3).
                    'context' => $key === null ? [] : $replay->contextOfTheRow($owner::class, $key),
                    'at' => $this->statements->position(),
                ];
                $byOwner[$name] = array_key_last($drafts);
            } catch (\Throwable $e) {
                $failed($e);
            }
        }

        $runs = [];

        try {
            $runs = $this->elementFieldRuns($em, $consume);
        } catch (\Throwable $e) {
            $failed($e);
        }

        $seen = [];

        foreach ($runs as $run) {
            $owner = $run['owner'];

            if ($owner === null) {
                continue;
            }

            try {
                $metadata = $this->metadataFactory->for($owner);
                $id = $this->identifierOf($em, $owner);

                if ($metadata === null || $id === null) {
                    continue;
                }

                $name = $metadata->objectType.'|'.$id;
                $index = $byOwner[$name] ?? null;

                if (!isset($seen[$name]) && $index !== null && $drafts[$index]['flush'] === $run['flush']) {
                    $seen[$name] = true;
                    $draft = $drafts[$index];
                    $draft['changes'] = array_replace($draft['changes'], $run['changes']);

                    if ($run['at'] > $draft['at']) {
                        $draft['context'] = $run['context'];
                        $draft['at'] = $run['at'];
                    }

                    $drafts[$index] = $draft;

                    continue;
                }

                $seen[$name] = true;
                $drafts[] = [
                    'objectType' => $metadata->objectType,
                    'id' => $id,
                    'event' => AuditEvent::UPDATE,
                    'flush' => $run['flush'],
                    'owner' => $owner,
                    'class' => $owner::class,
                    'changes' => $run['changes'],
                    'context' => $run['context'],
                    'at' => $run['at'],
                ];
            } catch (\Throwable $e) {
                $failed($e);
            }
        }

        return $drafts;
    }

    /**
     * A draft as the record it is: given its context, the always-recorded fields as the row
     * held them once the last execution it holds ran, unless it is a removal, which says only
     * that the row went.
     *
     * @param array{objectType: string, id: int|string, event: string, flush: int, owner: object|null, class: class-string|null, changes: array<string, mixed>, context: array<string, mixed>, at: int} $draft
     */
    private function recordOfTheDraft(EntityManagerInterface $em, array $draft): AuditRecord
    {
        $record = new AuditRecord($draft['objectType'], $draft['id'], $draft['event'], origin: AuditOrigin::Doctrine);

        if ($draft['event'] === AuditEvent::REMOVE) {
            return $record;
        }

        $owner = $draft['owner'] ?? ($draft['class'] === null ? null : $em->getClassMetadata($draft['class'])->newInstance());

        return $record->withChanges($owner === null ? $draft['changes'] : $this->withContext($em, $owner, $draft['changes'], $draft['context']));
    }

    /**
     * An owner's context where something that is no fact of its row happened -- what a
     * collection's snapshot said: the row once the last of its own statements at or before
     * that point ran, or as the replay holds it when none did.
     *
     * @param class-string              $class
     * @param array<string, mixed>|null $key
     *
     * @return array<string, mixed>
     */
    private function contextWhere(EntityManagerInterface $em, HistoryReplay $replay, string $class, ?array $key, int $position): array
    {
        if ($key === null) {
            return [];
        }

        $root = $em->getClassMetadata($em->getClassMetadata($class)->rootEntityName);
        $id = HistoryReplay::keyOf($root, $key);
        $context = null;

        foreach ($replay->rowFacts() as $fact) {
            if ($fact['at'] > $position) {
                break;
            }

            if ($fact['id'] === $id && $em->getClassMetadata($fact['class'])->rootEntityName === $root->name) {
                $context = $fact['context'];
            }
        }

        return $context ?? $replay->contextOfTheRow($class, $key);
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
     * The element as the DATABASE has it, for anything that has to show what went.
     *
     * Reading the rows said which elements the DELETE takes; it did not say what they
     * hold. Doctrine hands back the objects it already has -- createEntity() returns the
     * instance in the identity map rather than overwriting its fields with what the SELECT
     * read, which is the only thing it could do without throwing away the application's
     * unsaved work. So an element whose name was changed and never written showed the new
     * name in the history of its own deletion: the collection's DELETE takes the row
     * first, the UPDATE that would have written the name finds nothing, and the record
     * named a value no row ever held.
     *
     * So the representer is run against an object carrying what the columns hold, asked
     * of the same readers everything else here asks:
     *
     * What a refused flush left unwritten was one of them and is not any more. It is the
     * first reader everywhere else, and here it never answered: by the time a collection
     * is emptied after a refusal, sidesFrom() has already corrected the change set with
     * the same information, so the two arms said the same thing and only one of them was
     * ever reached. Neither three thousand generated sequences nor a test written for it
     * could tell the two apart, which is the whole of the argument for taking it out --
     * the behaviour it describes is pinned by
     * `testAnEmptyingAfterARefusedRenameNamesTheStoredName`, which still fails when this
     * method is taken away.
     *
     *   - the old side of the change set, corrected through sidesFrom() -- and NOT the
     *     original data, which is Doctrine's copy of the row until computeChangeSets()
     *     overwrites it with the values it just read off the object, which it has already
     *     done by the time onFlush runs;
     *   - the entity itself, for every field nothing else has anything to say about,
     *     which is all of them for an element nobody touched.
     *
     * Built with the metadata's own instantiator, which is what Doctrine hydrates with: no
     * constructor, no __clone, and only mapped values put back. Never handed to the
     * application and never managed -- it exists to be looked at once.
     *
     * Only where something really is unsaved, which is close to never: an element nobody
     * touched is returned as it is, and so is one this cannot build a copy of. A
     * representer that needs state no mapping carries would see less on the copy than on
     * the entity, so the copy is not made without a reason.
     */
    private function asTheRowHasIt(EntityManagerInterface $em, object $element): object
    {
        try {
            $metadata = $em->getClassMetadata($element::class);

            // What this listener knows about the column, and never the unit of work's change
            // set on its own. That set is spent the moment its UPDATE runs and Doctrine does
            // not clear it when a postFlush listener throws, so a rename that WAS written,
            // followed by an emptying, read the leftover and named the deleted row by the
            // name it had before the rename. Whose row is going already stopped trusting
            // that leftover; the fields of the same element were still trusting it.
            //
            // Two things know. What a refused flush left unwritten, which is the only record
            // of a column a refusal moved Doctrine past without moving the row. And this
            // listener's own change set -- but only while its statement is still to run,
            // which is what the flush number beside it says. The set itself outlives the
            // statement on purpose: publishing reads it after the commit. It was read here
            // as if its presence meant "not yet written", and a flush nested inside the
            // outer flush's postUpdate -- after the UPDATE had reached the row -- emptied the
            // collection and named the row by the value the UPDATE had just replaced.
            $kept = $this->neverWritten[$element] ?? [];
            $key = spl_object_id($element);
            $ours = isset($this->changeSetFlush[$key]) ? $this->changeSets[$key] ?? [] : [];

            $stored = [];

            foreach ($metadata->getFieldNames() as $name) {
                if (\array_key_exists($name, $kept)) {
                    $was = $kept[$name];
                } elseif (\array_key_exists($name, $ours) && \is_array($ours[$name]) && \array_key_exists(0, $ours[$name])) {
                    $was = $ours[$name][0];
                } else {
                    continue; // nothing says this column holds anything other than the object
                }

                // A stored NULL is a value like any other. It used to be taken for "nothing
                // known", so a nullable column that held nothing and was given something
                // in the same flush as its row went was shown with the value it never got.
                if ($was !== $metadata->getFieldValue($element, $name)) {
                    $stored[$name] = $was;
                }
            }

            if ($stored === []) {
                return $element;
            }

            $copy = $metadata->newInstance();

            foreach ($metadata->getFieldNames() as $name) {
                $metadata->setFieldValue(
                    $copy,
                    $name,
                    \array_key_exists($name, $stored) ? $stored[$name] : $metadata->getFieldValue($element, $name),
                );
            }

            foreach ($metadata->getAssociationNames() as $name) {
                if ($metadata->isSingleValuedAssociation($name)) {
                    $metadata->setFieldValue($copy, $name, $metadata->getFieldValue($element, $name));
                }
            }

            return $copy;
        } catch (\Throwable) {
            // A class this cannot instantiate or fill is shown as the application has it,
            // which is what it was shown as before this existed. Nothing here is worth a
            // flush.
            return $element;
        }
    }

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

        $this->claimTheFrameOf($flush);

        return $flush;
    }

    /**
     * Tells the statement log which frame this flush's statements ran in.
     */
    private function claimTheFrameOf(int $flush): void
    {
        if (!isset($this->statementMarks[$flush])) {
            return;
        }

        [$after, $enclosing, $last] = $this->statementMarks[$flush];
        $this->statements->claim([$after, $enclosing], $flush);

        // And what ran since this flush last said so, where no frame answers for it.
        $now = $this->statements->position();
        $this->statements->claimUnowned($this->claimedThrough[$flush] ?? $last, $now, $flush);
        $this->claimedThrough[$flush] = $now;
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
     * A flush that raised no event -- one that only emptied a collection -- is asked of the
     * connection's log instead: whether a statement it owns stayed done. Its own postFlush
     * says it outright. Which entry that is, is not "the top": a dead inner flush can be
     * sitting above it, at a deeper level, and is exactly what this is here to take away.
     */
    private function unwindTo(EntityManagerInterface $em, int $level, bool $theOneAtThisLevelCommitted = false): bool
    {
        $ran = false;

        while ($this->flushes !== [] && $this->flushes[array_key_last($this->flushes)]['level'] >= $level) {
            $entry = array_pop($this->flushes);

            // What it ran is its own before anything is asked about it -- for a flush that planned
            // a collection's rows and nothing else, which raises no event: whose postFlush
            // somebody swallowed never got to claim its frame. Not for any other: one that
            // planned an entity's row and raised nothing never ran, and claiming for it would
            // hand it the next transaction the application opens. (Left open: a flush that
            // planned only a collection's rows and was refused, followed by a transaction of the
            // application's own before the next flush -- that transaction is then taken for it.)
            if ($this->collectionsOnly[$entry['flush']] ?? false) {
                $this->claimTheFrameOf($entry['flush']);
            }

            if ($entry['ran']
                || ($theOneAtThisLevelCommitted && $entry['level'] === $level)
                || $this->statements->hasDoneAnythingFor($entry['flush'])
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

        foreach ($this->emptiedCollections as $owner => [, $byFlush]) {
            unset($byFlush[$flush]);

            if ($byFlush === []) {
                unset($this->emptiedCollections[$owner]);

                continue;
            }

            $this->emptiedCollections[$owner][1] = $byFlush;
        }

        $this->ownerFlush = [];

        foreach ($this->emptiedCollections as $owner => [, $byFlush]) {
            foreach (array_keys($byFlush) as $bucket) {
                $this->ownerFlush[$owner] = min($this->ownerFlush[$owner] ?? $bucket, $bucket);
            }
        }

        unset($this->provenance[$flush], $this->statementMarks[$flush], $this->claimedThrough[$flush], $this->collectionsOnly[$flush]);
        $this->collectionSnapshots = array_values(array_filter($this->collectionSnapshots, static fn (array $snapshot): bool => $snapshot['flush'] !== $flush));
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
        $this->pendingAt = [];
        $this->executions = [];
        $this->claimedExecutions = [];
        $this->pendingRemovalKeys = array_intersect_key($this->pendingRemovalKeys, $drafts);
        $this->pendingRemovals = $drafts;
        $this->pendingIndexByEntity = new \WeakMap();
        $this->ownerFlush = [];
        $this->flushes = [];
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
        $this->statementMarks = [];
        $this->claimedThrough = [];
        $this->collectionsOnly = [];
        $this->collectionSnapshots = [];
        $this->reportedLostChangeSets = false;
        $this->lastExecutionOf = new \WeakMap();
        $this->announcedUnder = [];

        // Whatever the log holds from here back is spent: published by the flush that is
        // ending, or dropped with the one that was found abandoned -- and in both cases not
        // the business of the next flush, which would otherwise write it as its own.
        $this->factsReadThrough = max($this->factsReadThrough, $this->statements->position());
        $this->departed->forgetThrough($this->factsReadThrough, $keepingWhatWasDraftedForTheNextFlush);

        $last = array_key_last($this->windows);

        if ($last !== null && $this->windows[$last][1] === null) {
            $this->windows[$last][1] = $this->factsReadThrough; // the operation is over
        }

        // Only what the next reading can still meet.
        $this->windows = array_values(array_filter($this->windows, fn (array $window): bool => ($window[1] ?? \PHP_INT_MAX) > $this->factsReadThrough));
    }

    /**
     * The manager was cleared: Doctrine forgot its objects. That decides nothing here.
     *
     * Whether what a flush wrote stands is the connection's log's to say -- a savepoint
     * rolled back, a transaction rolled back, a statement that failed -- and it is asked per
     * record, of the statement the record was taken after. Whether a flush is over is the
     * stack's, by nesting level. A clear is neither: close() clears on its way out of a
     * flush that dies inside one that commits, an application clears in the middle of a
     * flush that goes on, or between two operations, and in each of them what already ran
     * is exactly as real as it was a moment before.
     */
    public function onClear(OnClearEventArgs $args): void
    {
    }

    /**
     * The execution a record about to be taken describes: its positions in the log.
     *
     * A record is about one execution, and a row is written more than once: a listener ahead
     * of this one in the same event can run a flush nested there that writes the same row --
     * same table, same key, the same values even -- and that flush may succeed, or die before
     * claiming what it ran, or succeed inside a savepoint of the application's that is rolled
     * back after this listener. Neither the row nor the owner tells the executions apart, and
     * neither does which one is alive when the record is taken, nor which is last: all three
     * depend on the order of the listeners.
     *
     * Doctrine announces a change after running its statements, and not necessarily to this
     * listener at once: a flush nested in an earlier listener fits in between. What holds is
     * the bookkeeping. The record's own execution is the EARLIEST statement of its kind and
     * row, since its flush began, that no record has been tied to yet: whatever lies after it
     * is somebody else's, and a nested flush announced first has already taken its own. Where
     * no key binds a statement -- an INSERT whose key the database handed out -- it is the
     * earliest of the kind and table nobody has taken.
     *
     * A class of a JOINED hierarchy writes one change as a statement per table it touches, and
     * the persister runs them back to back: so the execution is that first statement and the
     * ones right after it, of the same kind and row, in other tables of the hierarchy and the
     * same frame. Not one per table wherever each is: a change of the root alone, followed by a
     * nested flush's change of the subclass alone, is two executions, and the nested one ran in
     * a savepoint of its own.
     *
     * Ownership is not asked. It answers whose moment and context a record carries, and a
     * frame nobody lived to claim is lent to the one around it -- right for that question,
     * and wrong for this one.
     *
     * @param string                    $kind the statement a record of this event is about: an
     *                                        INSERT, UPDATE or DELETE ({@see StatementShape})
     * @param array<string, mixed>|null $key the row's key as the statements carry it, when the
     *                                        entity no longer has it -- a removal's, taken before
     *                                        the DELETE cleared a generated identifier
     *
     * @return list<int>
     */
    private function statementBehind(EntityManagerInterface $em, object $entity, int $flush, string $kind, ?array $key = null): array
    {
        $metadata = $em->getClassMetadata($entity::class);
        $key ??= RowMemory::keyColumns($em, $entity);
        $row = $key === null ? null : self::rowOf($key);
        $index = $this->executionsOf($em, $flush);
        $tables = [];

        foreach ([$metadata->name, ...array_values($metadata->parentClasses)] as $name) {
            $tables[$em->getClassMetadata($name)->getTableName()] = true;
        }

        // The first statement of the execution: the earliest of its kind and row, in any of
        // the hierarchy's tables, that nobody has taken.
        $first = null;

        foreach (array_keys($tables) as $table) {
            $candidate = $row === null ? null : $this->firstUnclaimed($index['rows'][$kind.'|'.$table.'|'.$row] ?? []);
            $candidate ??= $this->firstUnclaimed($index['tables'][$kind.'|'.$table] ?? []);

            if ($candidate !== null && ($first === null || $candidate < $first)) {
                $first = $candidate;
            }
        }

        if ($first === null) {
            return [];
        }

        // And the ones the persister ran right after it for the same change.
        $tied = [$first];
        $frame = $this->statements->frameOf($first);
        $used = [StatementShape::read($this->statements->statement($first)['sql'] ?? '')?->table => true];

        for ($statement = $first + 1, $to = $this->statements->position(); $statement <= $to; ++$statement) {
            $entry = $this->statements->statement($statement);
            $shape = $entry === null ? null : StatementShape::read($entry['sql']);

            if ($entry === null || $shape === null || $shape->kind !== $kind || !isset($tables[$shape->table]) || isset($used[$shape->table])
                || $this->statements->frameOf($statement) !== $frame || isset($this->claimedExecutions[$statement])
            ) {
                break;
            }

            $binding = RowBinding::of($em, $shape, $entry['params']);
            $keyed = $binding->kind === RowBinding::ROW && $binding->key !== null;

            if ($keyed && ($row === null || self::rowOf($binding->key) !== $row)) {
                break;
            }

            $tied[] = $statement;
            $used[$shape->table] = true;
        }

        foreach ($tied as $statement) {
            $this->claimedExecutions[$statement] = true;
        }

        return $tied;
    }

    /**
     * A flush's window of the log, read on to where the log stands now.
     *
     * @return array{read: int, rows: array<string, list<int>>, tables: array<string, list<int>>}
     */
    private function executionsOf(EntityManagerInterface $em, int $flush): array
    {
        $index = $this->executions[$flush] ?? ['read' => $this->statementMarks[$flush][2] ?? 0, 'rows' => [], 'tables' => []];

        for ($statement = $index['read'] + 1, $to = $this->statements->position(); $statement <= $to; ++$statement) {
            $entry = $this->statements->statement($statement);
            $shape = $entry === null ? null : StatementShape::read($entry['sql']);

            if ($entry === null || $shape === null) {
                continue;
            }

            $binding = RowBinding::of($em, $shape, $entry['params']);

            if ($binding->kind === RowBinding::ROW && $binding->key !== null) {
                $index['rows'][$shape->kind.'|'.$shape->table.'|'.self::rowOf($binding->key)][] = $statement;
            } else {
                $index['tables'][$shape->kind.'|'.$shape->table][] = $statement;
            }
        }

        $index['read'] = $this->statements->position();

        return $this->executions[$flush] = $index;
    }

    /**
     * @param list<int> $statements
     */
    private function firstUnclaimed(array $statements): ?int
    {
        foreach ($statements as $statement) {
            if (!isset($this->claimedExecutions[$statement])) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * A row's key as one string, whatever order its columns came in.
     *
     * @param array<string, mixed> $key
     */
    private static function rowOf(array $key): string
    {
        ksort($key);

        return implode('|', array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $key));
    }

    /**
     * Takes out every pending record whose statement the log says was taken back.
     *
     * By the execution each was tied to when it was taken ({@see self::statementBehind()}): a
     * record goes when the log has voided any of its statements. A statement the log no longer
     * holds was folded in once no transaction could still roll it back, so it stood.
     */
    /**
     * @param list<int>|null $execution
     */
    private function theLogTookBack(?array $execution): bool
    {
        foreach ($execution ?? [] as $statement) {
            if ($this->statements->statement($statement) !== null && $this->statements->fate($statement) === StatementLog::VOID) {
                return true;
            }
        }

        return false;
    }

    private function dropWhatTheLogTookBack(?EntityManagerInterface $em): void
    {
        if ($em === null || $this->pending === []) {
            return;
        }

        $kept = [];

        foreach (array_keys($this->pending) as $index) {
            if (!$this->theLogTookBack($this->pendingAt[$index] ?? null)) {
                $kept[] = $index;
            }
        }

        if (\count($kept) === \count($this->pending)) {
            return;
        }

        $renumbered = array_flip($kept);
        $this->pending = array_values(array_map(fn (int $index): AuditRecord => $this->pending[$index], $kept));
        $this->pendingFlush = array_values(array_map(fn (int $index): int => $this->pendingFlush[$index], $kept));
        $this->pendingAt = array_values(array_map(fn (int $index): ?array => $this->pendingAt[$index] ?? null, $kept));
        $indexes = new \WeakMap();

        foreach ($this->pendingIndexByEntity as $entity => $index) {
            if (isset($renumbered[$index])) {
                $indexes[$entity] = $renumbered[$index];
            }
        }

        $this->pendingIndexByEntity = $indexes;
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
            // already taken their share away, and what the log says was taken back goes
            // now -- a flush that died rolled its statements back, and that, not its manager
            // being closed or cleared on the way out, is what says so. What is left is
            // really there.
            $this->dropWhatTheLogTookBack($abandoned ?? $em);
            $collected = $this->collectedSoFar($abandoned ?? $em);
            $before = $collected + $this->executionsTheLogTookBack($abandoned ?? $em);

            if ($committed && $collected > 0) {
                // Its statements stayed done, and it did not come back through postFlush:
                // something swallowed the rest of the event -- a postFlush listener
                // registered before this one threw, and this listener never ran. The rows
                // are in the database; dropping the records would be the audit trail
                // losing what actually happened, which is the one outcome it must not
                // choose.
                $this->logger->warning('A flush committed without reaching this listener — a postFlush listener registered before it threw — so its {count} audit record(s) are being written now, late. Give the audit listener a higher priority than listeners that may fail, or handle the failure in that listener.', ['count' => $collected]);

                try {
                    $this->publish($abandoned ?? $em, $abandoned ?? $em);
                } catch (\Throwable $e) {
                    $this->writer->reportFailure($e, null);
                } finally {
                    $this->forgetThisFlush(keepingWhatWasDraftedForTheNextFlush: true);
                }
            } else {
                // Nothing was collected; or the flush never reached a statement, because a
                // listener in onFlush threw before Doctrine wrote anything; or what it wrote
                // was rolled back. Either way nothing here reached the database, and history
                // that describes rows nobody has is worse than history that is missing.
                $this->logger->warning('A flush ended without committing, or without anything left to prove it did — a listener in onFlush threw, most likely — so {count} audit record(s) it had collected are dropped.', ['count' => $before]);

                $this->forgetThisFlush(keepingWhatWasDraftedForTheNextFlush: true);
            }
        }

        $this->flushingManager = \WeakReference::create($em);

        if ($this->flushes === []) {
            $this->windows[] = [$this->statements->position(), null]; // an operation begins
        }

        $this->flushes[] = ['level' => $level, 'flush' => $flush, 'ran' => false];
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
     * The owners an element reaches, checked and remembered: not what the collection gained
     * or lost -- the statements say that -- but what has to hold before they are written.
     *
     * A declaration that cannot be honoured refuses the flush that would have relied on it,
     * here in onFlush, for an owner that may have no event of its own. The owner the element points at and the one Doctrine remembers
     * it pointing at, which differ exactly when it moves.
     */
    private function aboutTheOwnersOf(EntityManagerInterface $em, object $element, int $flush): void
    {
        try {
            $elementMetadata = $em->getClassMetadata($element::class);
            $original = $em->getUnitOfWork()->getOriginalEntityData($element);

            foreach ($elementMetadata->getAssociationNames() as $association) {
                if (!$elementMetadata->isSingleValuedAssociation($association) || $elementMetadata->isAssociationInverseSide($association)) {
                    continue;
                }

                $owners = [$elementMetadata->getFieldValue($element, $association), $original[$association] ?? null];

                foreach (array_unique(array_filter($owners, 'is_object'), \SORT_REGULAR) as $owner) {
                    $metadata = $this->metadataFactory->for($owner);

                    if ($metadata === null || !self::holdsItsElementsThrough($em, $owner, $metadata, $association)) {
                        continue;
                    }

                    $this->assertAuditedFieldsAreThere($em, $owner, $metadata);
                    $this->assertTrackedCollectionsAreServable($em, $owner, $metadata);
                }
            }
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, null);
        }
    }

    /**
     * Whether one of the owner's audited collections is the other side of this association.
     */
    private static function holdsItsElementsThrough(EntityManagerInterface $em, object $owner, AuditMetadata $metadata, string $association): bool
    {
        $ownerMetadata = $em->getClassMetadata($owner::class);

        foreach (array_keys($metadata->fields) as $field) {
            if ($ownerMetadata->hasAssociation($field)
                && $ownerMetadata->isAssociationInverseSide($field)
                && $ownerMetadata->getAssociationMappedByTargetField($field) === $association
            ) {
                return true;
            }
        }

        return false;
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

            // And the field names, when the declaration names them. Only the fields on the
            // list are written into the history, whatever the statements set, so a
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
     * The owners whose collection a flush took away whole, which have no record otherwise:
     * `clear()` dirties nothing on the owner, so Doctrine raises no event for it, and every
     * record built for an entity is built inside one. The rows went; recordForOwner() is
     * what says so. (Only an owning many-to-many, until step 5: what any other collection
     * went through is read from the connection's log.)
     *
     * @return list<object>
     */
    private function ownersWhoseCollectionWasEmptied(): array
    {
        return array_values(array_map(static fn (array $emptied): object => $emptied[0], $this->emptiedCollections));
    }

    /**
     * What an emptied collection held, by the flushes that emptied it, as one answer.
     *
     * No flush's answer outranks another's: this is what a collection held BEFORE a flush emptied it, and
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
     * A record's always-recorded context: every history line reads on its own. The context is
     * the row's -- beside the change, once the change is in the database -- and never the
     * object's, which a postUpdate listener may have moved and the flush never wrote.
     *
     * @param array<string, Change|mixed> $changes
     * @param array<string, mixed>        $context the always-recorded fields as the row held them
     *
     * @return array<string, Change|mixed>
     */
    private function withContext(EntityManagerInterface $em, object $owner, array $changes, array $context): array
    {
        $metadata = $this->metadataFactory->for($owner);

        if ($metadata === null) {
            return $changes;
        }

        return (new ChangeSetBuilder($em, $this->comparator))->withAlwaysRecorded($owner, $metadata, $changes, $context);
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

        // What the elements said themselves, the whole-collection form does not repeat.
        //
        // This rule was here, taken out in the round that added it because no probe could
        // reach the case, and put back by a generated sequence that reached it at once:
        // clear() a collection and then replace it before one flush, and both roads have
        // something to say about the same rows -- the orphan removals raise an event per
        // element, and the replaced collection leaves a snapshot. The reviewer who asked
        // for that probe had named it in the same breath. "No probe of mine reached it" is
        // not "it cannot happen", and this is the third premise of this kind to be stated
        // wider than it was measured.
        //
        // Subtracted element by element rather than dropped whole: membership may name
        // some of what went and not the rest.
        foreach ($emptied as $field => $held) {
            // By the key the element form uses, which is the collection's field and the
            // element's own identifier. It used to be by what the element is SHOWN as,
            // because a deleted element has had its generated id cleared by the time this
            // runs -- and two lines of a crate may perfectly well carry the same name, so
            // one departure named for one of them took both out of the emptying and a row
            // went with nothing said. The identifiers are read in onFlush instead, where
            // they are still there, and kept as this map's keys; here the key is simply
            // built again and looked up. Nothing is compared by appearance.
            $named = [];

            foreach ($changes as $name => $change) {
                if (!$change instanceof Change || !str_starts_with((string) $name, $field.'.') || substr_count((string) $name, '.') !== 1) {
                    continue;
                }

                // Only what LEFT. The fact the two forms can describe twice is a
                // departure, and an element form that names an arrival is describing a
                // different one -- taking that as said as well subtracted a line from the
                // emptying because it had joined the collection earlier, so the row went
                // and the history never said so. Found by a generated sequence: a line
                // added by a flush whose publishing was swallowed, then the collection
                // cleared, so the arrival and the emptying were published together.
                if ($change->old !== null && $change->new === null) {
                    $named[(string) $name] = true;
                }
            }

            $left = [];

            foreach ($held as $id => $element) {
                if (!isset($named[ElementKey::of($field, $id)])) {
                    $left[$id] = $element;
                }
            }

            if ($left === []) {
                unset($emptied[$field]);
            } else {
                $emptied[$field] = $left;
            }
        }

        if ($emptied === []) {
            return $changes;
        }

        // Only the fields that were emptied. The builder is asked for a whole change set
        // because that is where the decision about what an emptying looks like lives, and
        // everything else it produces is about other fields -- the always-recorded ones,
        // filled from context rather than from anything that moved. Handed on whole, that
        // context overwrites the owner's own real change on the road where it has one:
        // "sealed -> sealed" where the row went from packed. This was taken out once, as
        // redundant, during a round when the road that needed it had been taken out too.
        $built = (new ChangeSetBuilder($em, $this->comparator))->build($owner, $metadata, [], $emptied);

        return array_replace(array_intersect_key($built, $emptied), $changes);
    }

    /**
     * What changed inside the elements of tracked collections since the log was last read
     * into the history: {@see ElementFieldRuns::of()}.
     *
     * @return list<array{owner: object|null, flush: int, changes: array<string, Change>, at: int, context: array<string, mixed>}>
     */
    private function elementFieldRuns(EntityManagerInterface $em, bool $consume = false): array
    {
        $replay = $this->rows->replayed($em);

        // A representer that threw: through the policy, once -- at the position it happened,
        // which this reading moves past -- and the fact it was for is left out. The row has
        // moved all the same, and the next statement starts from where it is.
        foreach ($consume ? $replay->failuresAfter($this->factsReadThrough) : [] as $failure) {
            $this->reportWhileBuilding($failure);
        }

        return $this->elementRuns->of($em, $replay, $this->factsReadThrough, $consume, $this->ranDuringAFlush(...));
    }

    /** Whether the statement at a position ran while an operation of this listener's did. */
    private function ranDuringAFlush(int $at): bool
    {
        foreach ($this->windows as [$from, $to]) {
            if ($at > $from && ($to === null || $at <= $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identifiers are ints, strings, objects with a string form (Uuid, Ulid, enums)
     * or — in a composite key — other entities, represented by their own identifier.
     */
    private function identifierOf(EntityManagerInterface $em, object $entity): int|string|null
    {
        return $this->identifierFrom($em, $em->getClassMetadata($entity::class)->getIdentifierValues($entity));
    }

    /**
     * @param array<string, mixed> $values an identifier's fields and their values
     */
    private function identifierFrom(EntityManagerInterface $em, array $values): int|string|null
    {
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
