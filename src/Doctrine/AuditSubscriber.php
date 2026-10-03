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
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LinkFacts;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LinkRuns;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\NobodysStatement;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowIdentity;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditOrigin;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\Provenance;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
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
 * The history of what a flush did, read from what its connection ran (1.3; ARCHITECTURE.md).
 *
 * Doctrine's events say when: which flush is collecting, whose moment it has, and when to
 * read the connection's log into records -- at the outermost postFlush, once the
 * transaction committed. What each record says is the log's: one execution of a row, its
 * values from the rows before and after it, its fate the statement's. A statement rolled
 * back -- with the transaction, to a savepoint, with a nested flush that died -- takes its
 * record with it, and a clear of the manager decides nothing.
 *
 * A flush inside an outer transaction of the application's (wrapInTransaction) is the one
 * case where postFlush still precedes the real commit; the records are sent then anyway,
 * since nothing later would tell the listener the transaction ended -- the outbox, and an
 * atomic frame, are the roads that close it.
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

    /** Said once per flush: a hundred entities would otherwise say the same thing a hundred times. */
    private bool $reportedLostChangeSets = false;

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
     * The entities whose change set was not empty when a flush's onFlush saw them, by the flush
     * that saw each first ({@see rememberWhatWasPlanned()}): for the warning about a lost change
     * set, and for nothing else. Only that it had one -- none of its values.
     *
     * Weakly, by the object: an entity nobody holds any more takes its entry with it, and PHP
     * hands a freed object's id to the next one.
     *
     * @var \WeakMap<object, int>
     */
    private \WeakMap $plannedChanges;

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
        $this->plannedChanges = new \WeakMap();
        $this->keptFor = new \WeakMap();
        $this->rows = new RowMemory($statements);

        // What ran before this listener existed is nobody's history it can account for: it
        // heard none of the events that name those rows.
        $this->factsReadThrough = $statements->position();
        $identity = new RowIdentity($this->identifierOf(...), $this->identifierFrom(...));
        $this->elementRuns = new ElementFieldRuns($metadataFactory, $comparator, $this->logger, $identity);
        $this->entityRuns = new EntityRowRuns($metadataFactory, $comparator, $identity, $this->logger);
        $this->departed = new DepartedObjects();
        $this->identity = $identity;
        $this->linkRuns = new LinkRuns($identity, $metadataFactory);
    }

    private readonly RowIdentity $identity;

    /** An owning ManyToMany's facts put together as the history says them (5.3). */
    private readonly LinkRuns $linkRuns;

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
            $this->keepingOnlyTheHistorysTables($em);
            $this->rows->rememberWhatIsManaged($em);
        }
    }

    /**
     * From the first flush on, the log keeps the statements of the tables a history is about, and
     * no others: an import of rows nobody audits is no work of the listener's. Managers that
     * share the connection share the log, and a statement between their flushes may be any one's:
     * a table is kept if a manager that has flushed says it is history. Held weakly -- the last
     * one gone, the log keeps everything again rather than ask a closed one.
     */
    private function keepingOnlyTheHistorysTables(EntityManagerInterface $em): void
    {
        if (isset($this->keptFor[$em])) {
            return;
        }

        $this->keptFor[$em] = true;
        $managers = $this->keptFor;
        $rows = $this->rows;
        $this->statements->keepingOnly(static function (string $table) use ($managers, $rows): bool {
            if (\count($managers) === 0) {
                return true;
            }

            foreach ($managers as $manager => $_) {
                if ($rows->isAHistoryTable($manager, $table)) {
                    return true;
                }
            }

            return false;
        });
    }

    /** @var \WeakMap<EntityManagerInterface, true> the managers the log's tables were asked of */
    private \WeakMap $keptFor;

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
            $this->rememberWhatWasPlanned($em, $element, $flush);
            $this->aboutTheOwnersOf($em, $element, $flush);
        }

        // A line added to or taken from an inverse collection never makes the collection
        // itself dirty — Doctrine tracks the owning side, which is the line's own
        // reference back. The unit of work knows about it all the same.
        foreach ($uow->getScheduledEntityInsertions() as $element) {
            $this->rememberWhatWasPlanned($em, $element, $flush);
            $this->aboutTheOwnersOf($em, $element, $flush);
        }

        foreach ($uow->getScheduledEntityDeletions() as $element) {
            $this->aboutTheOwnersOf($em, $element, $flush);
        }

        $this->rememberWhatIsBeingEmptied($em, $flush);

        // What the join rows this flush is about to change hold, read before it changes them.
        foreach ($this->rows->rememberTheLinksAboutToChange($em, $flush) as $failure) {
            $this->writer->reportFailure($failure, null);
        }
    }

    /**
     * Keeps what an inverse collection scheduled for deletion held, before the flush deletes
     * it: the rows of its elements, which the DELETE takes with no event, read once as rows
     * where nothing remembered them. An owning collection's are its join rows, accounted for
     * in onFlush and told by the log (5.3); Doctrine's snapshot of either is the membership as
     * of the last time it was synchronised, and answers for neither.
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
                // nothing remembered. Read once, as rows. An owning one's is its join rows',
                // read as an account in onFlush ({@see RowMemory::rememberTheLinksAboutToChange()})
                // and told by the log (5.3).
                if ($mappedBy !== null && !$collection->isInitialized()) {
                    $this->rows->rememberTheRowsOf($em, $owner, $field);
                }
            } catch (\Throwable $e) {
                // A question that fails must not take the flush with it: the record then says
                // what it can, and the failure is reported like any other.
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
        $this->collectingNowAfterAStatement($manager);
        $this->announced($manager, $args->getObject());
    }
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        // What the row went through is the log's; this is the flush claiming what it ran.
        $manager = self::entityManagerOf($args->getObjectManager());

        if ($manager === null) {
            return;
        }

        $this->collectingNowAfterAStatement($manager);
        $this->announced($manager, $args->getObject());
    }

    /**
     * The declaration checked. What the entity's row went through is the log's, and what its
     * owning collections went through is its join rows' (5.3): nothing is taken here.
     */
    private function announced(EntityManagerInterface $em, object $entity): void
    {
        try {
            $metadata = $this->checkTheDeclaration($em, $entity);

            if ($metadata === null) {
                return;
            }

            $this->warnOfALostChangeSet($em, $entity);
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, null);
        }
    }

    /**
     * Says out loud what Doctrine does silently, to the application's flush and not to this
     * listener's history.
     *
     * What is seen is an entity announced with nothing left of the change set it had in
     * onFlush: something ran a flush from a lifecycle listener of this one, and
     * UnitOfWork::postCommitCleanup() emptied the change sets of the flush still running. What
     * that may have cost the application -- the same cleanup resets what the running flush had
     * still to do -- is said as a risk, not as a loss this listener could confirm. Said once per
     * flush, by class, with where to move the work: finding such a listener once took three days.
     *
     * With {@see rememberWhatWasPlanned()}, the only reading of Doctrine's change sets in this
     * listener, and a diagnostic only: no record depends on it -- what a row went through is read
     * from what the connection ran -- and it is logged, not put through the failure policy: it is
     * about Doctrine and the application's listener, not about this bundle's history.
     */
    private function warnOfALostChangeSet(EntityManagerInterface $em, object $entity): void
    {
        if ($this->reportedLostChangeSets
            || !isset($this->plannedChanges[$entity])
            || $em->getUnitOfWork()->getEntityChangeSet($entity) !== []
        ) {
            return;
        }

        $this->reportedLostChangeSets = true;

        $this->logger->warning(
            'The unit of work had no change set left for {entity}, which it had when this flush began: something called flush() from inside a lifecycle listener of this flush, and UnitOfWork::commit() ends in postCommitCleanup(), which empties the change sets of the flush still running. The same cleanup resets its extraUpdates, collectionUpdates, orphanRemovals and collectionDeletions, so what that flush had still to write is at risk. The audit history is read from what the connection ran and does not depend on it. Move that work to postFlush.',
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

        // Removed while a flush runs -- by a listener, after that flush's onFlush read what its
        // plan was about to change: what its rows hold is read here, before its DELETE.
        if ($this->flushes !== []) {
            foreach ($this->rows->rememberTheGoing($leaving, [$args->getObject()], $this->flushes[array_key_last($this->flushes)]['flush']) as $failure) {
                $this->writer->reportFailure($failure, null);
            }
        }
    }
    public function postRemove(PostRemoveEventArgs $args): void
    {
        $manager = self::entityManagerOf($args->getObjectManager());

        if ($manager !== null) {
            $this->collectingNowAfterAStatement($manager);
        }

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

                    // What it said to look at after its DELETEs goes with it; one left behind
                    // by a flush that never came here does no harm (StatementLog::watch()).
                    $this->statements->forgetTheWatchesOf($entry['flush']);

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

        // One batch per run of records that share a moment: a flush that touched fifty
        // entities is one _bulk call, not fifty round-trips. The moment the change happened,
        // not the moment it is being written: this method runs in postFlush, and in the branch
        // that publishes a swallowed flush it runs during a later one entirely. Nearly always
        // there is exactly one run; there are two or three when a lifecycle listener called
        // flush() in the middle of this one, and then each stretch goes out with the moment its
        // own flush settled. Runs rather than groups, so that the order records were collected
        // in is the order they are written in.
        //
        // A run goes out as it is made, not once every record is: a flush of twenty thousand
        // held its drafts and its records whole at once. Outside a frame a run goes in batches
        // of the writer's own size, which is how the writer sends them anyway. Inside one a run
        // goes in one call, as before: there one call is one decision, and under on_overflow:
        // throw a frame that overflows takes the whole call with it -- a run in parts would
        // leave the parts before it written.
        //
        // One run failing does not cost the rest. Under on_failure: throw a refused record
        // leaves writeAll() as an exception, and stopping there would drop every later run --
        // records of changes that are already committed, thrown away because something else
        // could not be written. A single writeAll() never did that: it tries every record and
        // raises afterwards. The runs are held to the same promise, and the first exception is
        // the one the caller gets, because it is the one writeAll() already reported.
        $run = [];
        $moment = self::NO_FLUSH;
        $refused = null;
        $whole = $this->writer->isInAFrame();
        $batch = $this->writer->batchSize();

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

        // The whole of this is inside the failure policy: this is postFlush, the transaction
        // has committed, and an exception escaping would come out of flush() for a database
        // change that is already real. What the policy cannot do here is undo it.
        if ($em !== null) {
            $drafts = $this->drafts($em, consume: true);

            // Each draft let go as its record is made.
            foreach (array_keys($drafts) as $index) {
                $draft = $drafts[$index];
                unset($drafts[$index]);

                try {
                    $record = $this->recordOfTheDraft($em, $draft);
                } catch (\Throwable $e) {
                    $this->reportWhileBuilding($e);

                    continue;
                }

                $its = $draft['flush'];

                if ($run !== [] && ($its !== $moment || (!$whole && \count($run) >= $batch))) {
                    $send($run, $moment);
                    $run = [];
                }

                $run[] = $record;
                $moment = $its;
            }
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
     *   ({@see EntityRowRuns}), in the order the statements ran; an update of nothing is
     *   nothing, under skip_empty_updates.
     * - What changed inside elements of tracked collections: an owner's first run joins the
     *   record it has for that flush, and after that nothing goes back into an earlier record.
     * - What an owning ManyToMany's join rows went through, one move of the whole list per
     *   contribution ({@see LinkRuns}), by the same rule: the first of an owner's collection in
     *   a flush joins the owner's record there, and the rest are records of their own.
     *
     * The context each is given is the row's once the last execution it holds ran: the
     * entity's own execution's, or its elements' or links' when one of theirs ran later.
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
        // Ordered by where each happened: an execution by its first statement.
        $ordered = [];

        // Each run made into its draft as it comes, and let go: no list of them is held.
        foreach ($this->entityRuns->each($em, $replay, $this->statements, $this->factsReadThrough, $this->departed, $consume, $this->ranDuringAFlush(...), $failed) as $run) {
            $changes = $run['bare'];

            if ($run['event'] === AuditEvent::UPDATE && $this->skipEmptyUpdates && $changes === []) {
                continue;
            }

            $ordered[] = [$run['at'][0], [
                'objectType' => $run['objectType'],
                'id' => $run['id'],
                'event' => $run['event'],
                // Whose record it is, is whose statement it is: the flush that ran it. A flush
                // nested in another carries out whatever it finds scheduled, the outer flush's
                // remaining updates too, and what it ran is its own -- signed, timed and put in
                // context as its own (WhoWroteItTest), as an element's rows are. The outer flush
                // announcing the row again afterwards says nothing of who wrote it.
                'flush' => $run['flush'],
                'owner' => $run['entity'],
                'class' => $run['class'],
                'changes' => $changes,
                'context' => $run['context'],
                'at' => $run['at'][\count($run['at']) - 1],
            ]];
        }

        usort($ordered, static fn (array $one, array $other): int => $one[0] <=> $other[0]);
        /** @var list<array{objectType: string, id: int|string, event: string, flush: int, owner: object|null, class: class-string|null, changes: array<string, mixed>, context: array<string, mixed>, at: int}> $drafts */
        $drafts = array_column($ordered, 1);
        $byOwner = [];
        // Where each record begins, for putting the ones links make in their place.
        $since = array_column($ordered, 0);

        foreach ($drafts as $index => $draft) {
            if ($draft['event'] !== AuditEvent::REMOVE) {
                $byOwner[$draft['objectType'].'|'.$draft['id']] = $index;
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

            try {
                // An owner the manager does not hold is named by its class and its key, as a
                // link's is: a reading that only counts loads nothing to find it, and the one
                // that writes is handed a reference. The two read one road, so that what is
                // counted is what is written -- a count that left such an owner out took a
                // committed change's late records for nothing collected, and dropped them.
                if ($owner === null) {
                    $ownerMetadata = $em->getClassMetadata($run['class']);
                    $columns = $ownerMetadata->getIdentifierColumnNames();
                    $metadata = $this->metadataFactory->forClass($ownerMetadata->name, $ownerMetadata->newInstance(...));
                    $id = \count($columns) === 1 ? $this->identity->historyId($em, $ownerMetadata->name, [$columns[0] => $run['key']]) : null;
                } else {
                    $metadata = $this->metadataFactory->for($owner);
                    $id = $this->identifierOf($em, $owner);
                }

                if ($metadata === null || $id === null) {
                    continue;
                }

                $name = $metadata->objectType.'|'.$id;
                $index = $byOwner[$name] ?? null;

                if (!isset($seen[$name]) && $index !== null && $drafts[$index]['flush'] === $run['flush']) {
                    $seen[$name] = true;
                    $since[$index] = min($since[$index] ?? $run['since'], $run['since']);
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
                    'class' => $owner === null ? $em->getClassMetadata($run['class'])->name : $owner::class,
                    'changes' => $run['changes'],
                    'context' => $run['context'],
                    'at' => $run['at'],
                ];
                $since[array_key_last($drafts)] = $run['since'];
                // The owner's record for this flush from here on, for its links to join: an owner
                // with no event of its own is one record of both kinds of news, not two.
                $byOwner[$name] ??= array_key_last($drafts);
            } catch (\Throwable $e) {
                $failed($e);
            }
        }

        // What an owning ManyToMany's join rows went through, one move of the whole list per
        // contribution (LinkRuns): the first of an owner's collection in a flush joins the record
        // the owner has for that flush, as an element's first run does, and after that nothing goes
        // back into an earlier record. What an owner's removal itself took is its removal's
        // (LinkFacts); what ran before it is said, before it.
        $links = [];

        try {
            $links = $this->linkRuns($em, $replay, $consume, $failed);
        } catch (\Throwable $e) {
            $failed($e);
        }

        $joined = [];

        foreach ($links as $run) {
            try {
                $owner = $run['owner'];
                $metadata = $owner !== null ? $this->metadataFactory->for($owner) : $this->metadataFactory->forClass($em->getClassMetadata($run['class'])->name, $em->getClassMetadata($run['class'])->newInstance(...));

                if ($metadata === null || $run['id'] === null) {
                    continue;
                }

                $name = $metadata->objectType.'|'.$run['id'];
                $index = $byOwner[$name] ?? null;
                $first = $name.'|'.$run['collection'].'|'.$run['flush'];

                if (!isset($joined[$first]) && $index !== null && $drafts[$index]['flush'] === $run['flush']) {
                    $joined[$first] = true;
                    $since[$index] = min($since[$index] ?? $run['since'], $run['since']);
                    $draft = $drafts[$index];
                    $draft['changes'] = array_replace($draft['changes'], $run['changes']);

                    if ($run['at'] > $draft['at']) {
                        $draft['context'] = $run['context'];
                        $draft['at'] = $run['at'];
                    }

                    $drafts[$index] = $draft;

                    continue;
                }

                $joined[$first] = true;
                $drafts[] = [
                    'objectType' => $metadata->objectType,
                    'id' => $run['id'],
                    'event' => AuditEvent::UPDATE,
                    'flush' => $run['flush'],
                    'owner' => $owner,
                    'class' => $run['class'],
                    'changes' => $run['changes'],
                    'context' => $run['context'],
                    'at' => $run['at'],
                ];
                $since[array_key_last($drafts)] = $run['since'];
                $byOwner[$name] ??= array_key_last($drafts);
            } catch (\Throwable $e) {
                $failed($e);
            }
        }

        // Every record where its first fact ran, whatever kind of news it is: an entity's row, what
        // happened inside its collection, what its join rows went through -- one made of an owner's
        // lines alone before the next entity's UPDATE, a record of links before the owner's removal
        // that came after them. The order the writer is handed is the order the ids it builds
        // keep, and one record's place is never decided by what kind of record came first.
        $order = array_keys($drafts);
        usort($order, static fn (int $one, int $other): int => [$since[$one] ?? $drafts[$one]['at'], $one] <=> [$since[$other] ?? $drafts[$other]['at'], $other]);
        $drafts = array_map(static fn (int $index): array => $drafts[$index], $order);

        return $drafts;
    }

    /**
     * An owning ManyToMany's contributions since the history was last written, each as a
     * change of the collection's field -- its whole list before and after -- with the owner,
     * where it happened, and the owner's row there as its context. A contribution of no flush --
     * the application's own statement outside every flush -- is said as such, and is no
     * record; what the log could not be followed through is said as doubt, once.
     *
     * @param \Closure(\Throwable): void $failed
     *
     * @return list<array{since: int, owner: object|null, class: class-string, id: int|string|null, collection: string, flush: int, changes: array<string, Change>, context: array<string, mixed>, at: int}>
     */
    private function linkRuns(EntityManagerInterface $em, HistoryReplay $replay, bool $consume, \Closure $failed): array
    {
        $told = LinkFacts::of($em, $replay, $this->statements, $this->rows->links());
        $doubts = array_values(array_filter($told->doubts(), fn (array $doubt): bool => $doubt['at'] > $this->factsReadThrough));

        if ($consume && $doubts !== []) {
            $this->logger->warning('What the connection ran could not be followed for {count} statement(s) of {classes} since the history was last written, so the history may be missing what they did.', [
                'count' => \count($doubts),
                'classes' => implode(', ', array_unique(array_column($doubts, 'class'))),
            ]);
        }

        $runs = [];

        foreach ($this->linkRuns->of($em, $replay, $this->statements, $told, $this->departed, $failed) as $run) {
            if ($run['at'][0] <= $this->factsReadThrough) {
                continue; // written already
            }

            if ($run['flush'] === null) {
                if ($consume) {
                    NobodysStatement::say($this->logger, $this->ranDuringAFlush($run['at'][0]), $run['collection'], $run['owner'], array_keys($run['ownerKey']));
                }

                continue;
            }

            // One decision whatever kind of field it was, as the builder's: the application's
            // comparator first -- two lists of the same labels are no move to it, say.
            $declared = $this->metadataFactory->forClass($em->getClassMetadata($run['owner'])->name, $em->getClassMetadata($run['owner'])->newInstance(...));

            if ($declared === null || ($this->comparator->equals($declared->objectType, $run['collection'], $run['old'], $run['new']) ?? ValueComparator::same($run['old'], $run['new']))) {
                continue;
            }

            $owner = $this->identity->managed($em, $run['owner'], $run['ownerKey']);
            $at = $run['at'][\count($run['at']) - 1];
            $runs[] = [
                'since' => $run['at'][0],
                'owner' => $owner,
                'class' => $owner === null ? $run['owner'] : $owner::class,
                'id' => $this->identity->historyId($em, $run['owner'], $run['ownerKey']),
                'collection' => $run['collection'],
                'flush' => $run['flush'],
                'changes' => [$run['collection'] => new Change($run['old'], $run['new'])],
                'context' => $this->contextWhere($em, $replay, $run['owner'], $run['ownerKey'], $at),
                'at' => $at,
            ];
        }

        return $runs;
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

        foreach ($replay->eachRowFact() as $fact) {
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
     * Whether the flush had a change set for the entity when its onFlush saw it -- that it had
     * one, and nothing of what was in it: for the warning ({@see warnOfALostChangeSet()}).
     *
     * By the flush that saw it first. A flush nested in a lifecycle listener is handed the outer
     * flush's entities still scheduled -- executeUpdates() takes one off the list only after its
     * statement -- and must not take the outer's evidence for its own.
     */
    private function rememberWhatWasPlanned(EntityManagerInterface $em, object $entity, int $flush): void
    {
        if (!isset($this->plannedChanges[$entity]) && $em->getUnitOfWork()->getEntityChangeSet($entity) !== []) {
            $this->plannedChanges[$entity] = $flush;
        }
    }

    /**
     * Everything one flush collected, taken away without touching what the others did.
     *
     * No record is among it: records are built from the log, and a flush discarded here ran
     * nothing of its own. What it has is filed under its number -- its moment, the marks of its
     * frame -- and what its onFlush saw planned.
     */
    private function forgetWhatThisFlushCollected(int $flush): void
    {
        foreach ($this->plannedChanges as $entity => $by) {
            if ($by === $flush) {
                unset($this->plannedChanges[$entity]);
            }
        }

        unset($this->provenance[$flush], $this->statementMarks[$flush], $this->claimedThrough[$flush], $this->collectionsOnly[$flush]);
    }

    /**
     * @param bool $keepingTheUnwrittenRemovals whether what the application removed, and whose
     *        DELETE has not run, survives this. It does when the forgetting is a flush STARTING
     *        and finding the last one's state behind it: `$em->remove()` fires preRemove where
     *        it is called, before any flush exists, so the object taken there belongs to the
     *        flush about to run and not to the one that left. Swept up with the rest, the
     *        record of the deletion lost the object the application removed -- and the more so
     *        on the road that publishes late, where an operation's first act is to write
     *        somebody else's records.
     */
    private function forgetThisFlush(bool $keepingTheUnwrittenRemovals = false): void
    {
        $this->flushes = [];
        $this->failureWhileBuilding = null;

        // Everything, not this flush's entry: forgetting happens when nothing is live —
        // the outermost postFlush, or a new flush finding the last one abandoned — and
        // an entry left behind under another number would be a moment nothing can ever
        // publish. Removing only the current one and then emptying the map anyway was
        // two rules for one thing, and the second made the first unobservable.
        $this->provenance = [];
        $this->plannedChanges = new \WeakMap();
        $this->statementMarks = [];
        $this->claimedThrough = [];
        $this->collectionsOnly = [];
        $this->reportedLostChangeSets = false;

        // Whatever the log holds from here back is spent: published by the flush that is
        // ending, or dropped with the one that was found abandoned -- and in both cases not
        // the business of the next flush, which would otherwise write it as its own.
        $this->factsReadThrough = max($this->factsReadThrough, $this->statements->position());
        $this->departed->forgetThrough($this->factsReadThrough, $keepingTheUnwrittenRemovals);

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
            // already taken their share away, and what the log says was taken back is in no
            // record built from it -- a flush that died rolled its statements back, and that,
            // not its manager being closed or cleared on the way out, is what says so.
            $collected = $this->collectedSoFar($abandoned ?? $em);

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
                    $this->forgetThisFlush(keepingTheUnwrittenRemovals: true);
                }
            } else {
                // Nothing was collected; or the flush never reached a statement, because a
                // listener in onFlush threw before Doctrine wrote anything; or what it wrote
                // was rolled back. Either way nothing here reached the database, and history
                // that describes rows nobody has is worse than history that is missing.
                //
                // No number. It once said how many records were dropped, counted by grouping
                // the statements taken back, and two things made that a number of something
                // else: an execution is not a record -- an owner's lines and links join its
                // record -- and a change made only inside a collection was counted as none.
                $this->logger->warning('A flush ended without committing, or without anything left to prove it did — a listener in onFlush threw, or its transaction was rolled back — so the audit changes it collected are dropped: no history will be published for them.');

                $this->forgetThisFlush(keepingTheUnwrittenRemovals: true);
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
     * here in onFlush, for an owner that may have no event of its own. The owner the element
     * points at and the one it pointed at, which differ exactly when it moves -- read from the
     * change set: by onFlush Doctrine has written the new values into what it calls the
     * entity's original data, and the owner an element leaves was never checked.
     */
    private function aboutTheOwnersOf(EntityManagerInterface $em, object $element, int $flush): void
    {
        try {
            $elementMetadata = $em->getClassMetadata($element::class);
            $changes = $em->getUnitOfWork()->getEntityChangeSet($element);

            foreach ($elementMetadata->getAssociationNames() as $association) {
                if (!$elementMetadata->isSingleValuedAssociation($association) || $elementMetadata->isAssociationInverseSide($association)) {
                    continue;
                }

                // One of each object, told apart by identity: compared with ==, two owners were
                // compared field by field, through their collections into the elements and back
                // to the owners -- a cycle PHP gives up on with a fatal error.
                $owners = [];

                foreach ([$elementMetadata->getFieldValue($element, $association), $changes[$association][0] ?? null] as $one) {
                    if (\is_object($one)) {
                        $owners[spl_object_id($one)] = $one;
                    }
                }

                foreach ($owners as $owner) {
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
     * What changed inside the elements of tracked collections since the log was last read
     * into the history: {@see ElementFieldRuns::of()}.
     *
     * @return list<array{owner: object|null, class: class-string, key: mixed, flush: int, changes: array<string, Change>, since: int, at: int, context: array<string, mixed>}>
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
