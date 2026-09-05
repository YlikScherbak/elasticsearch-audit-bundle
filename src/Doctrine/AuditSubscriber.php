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
use Doctrine\ORM\EntityManagerInterface;
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

    /** @var array<int, AuditRecord> records for entities being removed, keyed by object id */
    private array $pendingRemovals = [];

    /** @var array<int, array{0: object, 1: array<string, Change>}> changes inside tracked collection elements, keyed by the owner's object id */
    private array $elementChanges = [];

    /** @var array<int, array{0: object, 1: array<string, array{element: object, added: bool, field: string, represent: (callable(object): mixed)|null, value: mixed, deferred: bool, id: int|string|null}>}> elements a tracked collection gained or lost, keyed by the owner's object id */
    private array $elementMembership = [];

    /** @var array<int, int> the pending lifecycle record of an entity — create or update — so what its elements did can be folded into it */
    private array $pendingIndexByEntity = [];

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
     * @var list<int>
     */
    private array $flushDepths = [];

    public function __construct(
        private readonly AuditWriter $writer,
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly bool $skipEmptyUpdates = true,
        private readonly ValueComparatorInterface $comparator = new ValueComparator(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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

        $this->beginFlush($em);

        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $element) {
            // Taken for every update, not only the audited ones: deciding that here
            // would mean reading each entity's declaration twice per flush, and an
            // array of scalars costs nothing to keep.
            $this->changeSets[spl_object_id($element)] = $uow->getEntityChangeSet($element);

            $this->collectElementChanges($em, $element);
        }

        // A line added to or taken from an inverse collection never makes the collection
        // itself dirty — Doctrine tracks the owning side, which is the line's own
        // reference back. The unit of work knows about it all the same.
        foreach ($uow->getScheduledEntityInsertions() as $element) {
            // An insertion's change set dies in the same cleanup as an update's: a
            // create whose postPersist runs after somebody's nested flush would
            // otherwise say an entity appeared with no values at all.
            $this->changeSets[spl_object_id($element)] = $uow->getEntityChangeSet($element);

            $this->collectElementChanges($em, $element, added: true);
        }

        foreach ($uow->getScheduledEntityDeletions() as $element) {
            $this->collectElementChanges($em, $element, added: false);
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::CREATE);

        if ($record !== null) {
            $this->pending[] = $record;
            // Registered like an update's: an owner created with its lines has one
            // record, and what the lines did belongs in it. Without this the membership
            // found no record to join and invented a second, phantom update.
            $this->pendingIndexByEntity[spl_object_id($args->getObject())] = array_key_last($this->pending);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::UPDATE);

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

        $this->pending[] = $record;
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
        $key = spl_object_id($args->getObject());
        $record = $this->pendingRemovals[$key] ?? null;
        unset($this->pendingRemovals[$key]);

        if ($record !== null) {
            $this->pending[] = $record;
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
        $depth = $em?->getConnection()->getTransactionNestingLevel() ?? 0;

        while ($this->flushDepths !== [] && $this->flushDepths[array_key_last($this->flushDepths)] >= $depth) {
            array_pop($this->flushDepths);
        }

        if ($this->flushDepths !== []) {
            return;
        }

        // Everything below runs application code — a deferred representer, withContext()
        // reading a declaration — and under "throw" reporting one of those failures
        // leaves this method through the exception. The state a flush collects has to go
        // with the flush whichever way it ends: the depth stack is already unwound by
        // then, so the next flush would not read it as abandoned either, and somebody
        // else's operation would publish these records as its own.
        try {
            $this->publish($args, $em);
        } finally {
            $this->forgetThisFlush();
        }
    }

    /**
     * @param \Doctrine\ORM\EntityManagerInterface|null $em
     */
    private function publish(PostFlushEventArgs $args, ?EntityManagerInterface $em): void
    {
        $records = $this->pending;

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
        foreach ($this->elementsByOwner($args->getObjectManager()) as [$owner, $changes]) {
            $index = $this->pendingIndexByEntity[spl_object_id($owner)] ?? null;

            try {
                if ($index !== null && isset($records[$index])) {
                    $merged = array_replace($records[$index]->changes, $changes);
                    $records[$index] = $records[$index]->withChanges($this->withContext($em, $owner, $merged));

                    continue;
                }

                $record = $this->recordForOwner($args->getObjectManager(), $owner, $changes);

                if ($record !== null) {
                    $records[] = $record;
                }
            } catch (\Throwable $e) {
                $this->writer->reportFailure($e, $index !== null ? ($records[$index] ?? null) : null);
            }
        }

        // One batch: a flush that touched fifty entities is one _bulk call, not fifty round-trips.
        $this->writer->writeAll(array_values($records));
    }

    /**
     * Everything the flush that is ending collected, dropped with it.
     */
    private function forgetThisFlush(): void
    {
        $this->pending = [];
        $this->pendingRemovals = [];
        $this->pendingIndexByEntity = [];
        $this->elementChanges = [];
        $this->elementMembership = [];
        $this->flushDepths = [];
        $this->changeSets = [];
        $this->reportedLostChangeSets = false;
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
        // elements of tracked collections describes INSERTs and UPDATEs that were rolled back
        // with the rest, and would otherwise surface in the next flush as history.
        $this->pending = [];
        $this->pendingRemovals = [];
        $this->pendingIndexByEntity = [];
        $this->elementChanges = [];
        $this->elementMembership = [];

        // Unconditionally here, and the depth with it: onClear is not paired with
        // anything, so popping one level would leave the stack describing a flush that
        // no longer exists.
        $this->changeSets = [];
        $this->reportedLostChangeSets = false;
        $this->flushDepths = [];
    }

    /**
     * Remember how deep this flush started, and notice a flush above it that died.
     */
    private function beginFlush(EntityManagerInterface $em): void
    {
        $level = $em->getConnection()->getTransactionNestingLevel();

        if ($this->flushDepths !== [] && $level <= $this->flushDepths[array_key_last($this->flushDepths)]) {
            // Not nested inside the flush on the stack: that one never reached its
            // transaction, so it never committed and never will. What it collected
            // describes rows the database does not have.
            $this->logger->warning('A flush was abandoned before it committed — a listener in onFlush threw, most likely — so {count} audit record(s) it had collected are dropped. They describe changes the database never took.', ['count' => \count($this->pending) + \count($this->pendingRemovals)]);

            $this->pending = [];
            $this->pendingRemovals = [];
            $this->pendingIndexByEntity = [];
            $this->elementChanges = [];
            $this->elementMembership = [];
            $this->changeSets = [];
            $this->reportedLostChangeSets = false;
            $this->flushDepths = [];
        }

        $this->flushDepths[] = $level;
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
     * Whether this updated entity is an element of a tracked collection, and if so,
     * what changed in it — held against its owner until the owner's record is built.
     *
     * The owner is reached through the element's own side of the association, which is
     * already loaded, so this asks nothing of the database. A failure here is reported
     * like any other: an element that cannot be read must not fail the flush.
     */
    private function collectElementChanges(EntityManagerInterface $em, object $element, ?bool $added = null): void
    {
        try {
            $elementMetadata = $em->getClassMetadata($element::class);
            $changeSet = $added === null ? $em->getUnitOfWork()->getEntityChangeSet($element) : [];

            foreach ($elementMetadata->getAssociationNames() as $association) {
                if (!$elementMetadata->isSingleValuedAssociation($association) || $elementMetadata->isAssociationInverseSide($association)) {
                    continue;
                }

                $current = $elementMetadata->getFieldValue($element, $association);

                // The element changed hands. Doctrine keeps that on the owning side — the
                // element's own reference — so neither collection is dirty and, without
                // reading the change set, both owners stay silent about it.
                if (\array_key_exists($association, $changeSet) && \is_array($changeSet[$association])) {
                    $this->holdMembership($em, $element, $changeSet[$association][0] ?? null, $association, added: false);
                    $this->holdMembership($em, $element, $current, $association, added: true);

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
                    $deletedChangeSet = $em->getUnitOfWork()->getEntityChangeSet($element);
                    $owner = \array_key_exists($association, $deletedChangeSet) && \is_array($deletedChangeSet[$association])
                        ? $deletedChangeSet[$association][0] ?? null
                        : ($em->getUnitOfWork()->getOriginalEntityData($element)[$association] ?? $current);

                    $this->holdMembership($em, $element, $owner, $association, added: false);

                    continue;
                }

                $this->holdMembership($em, $element, $current, $association, $added);
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
    private function holdMembership(EntityManagerInterface $em, object $element, mixed $owner, string $association, ?bool $added): void
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

        $this->holdElementChanges($em, $element, $owner, $metadata, $association, $added);
    }

    private function holdElementChanges(EntityManagerInterface $em, object $element, object $owner, AuditMetadata $metadata, string $association, ?bool $added = null): void
    {
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

            // An element being inserted has no identifier yet, so what it is called in
            // the record is settled after the flush; the object is what is held here.
            if ($added !== null) {
                $held = $this->elementMembership[$key][1] ?? [];
                $identifier = $this->identifierOf($em, $element);

                $held[$field.'#'.spl_object_id($element)] = [
                    'element' => $element,
                    'added' => $added,
                    'field' => $field,
                    'represent' => $metadata->fields[$field] ?? null,
                    // A removal is represented now, while the element still has its
                    // values: Doctrine clears a generated identifier once the row is
                    // gone, and a representer reading one would find nothing after the
                    // flush. An insertion is the other way round — before the INSERT it
                    // has no identifier yet, so a representer built on one ("getId")
                    // would store null for a row that is about to have a perfectly good
                    // id. That one waits for postFlush.
                    'value' => $added && $identifier === null ? null : self::represent($element, $metadata->fields[$field] ?? null),
                    'deferred' => $added && $identifier === null,
                    'id' => $identifier,
                ];
                $this->elementMembership[$key] = [$owner, $held];

                continue;
            }

            $id = $this->identifierOf($em, $element);

            if ($id === null) {
                continue;
            }

            $changes = (new ChangeSetBuilder($em, $this->comparator))->elementChanges($metadata->objectType, $field, $element, $id, $wanted);

            if ($changes === []) {
                continue;
            }

            $held = $this->elementChanges[$key][1] ?? [];
            $this->elementChanges[$key] = [$owner, array_replace($held, $changes)];
        }
    }

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

        foreach ($this->elementChanges as $key => [$owner, $changes]) {
            $byOwner[$key] = [$owner, $changes];
        }

        foreach ($this->elementMembership as $key => [$owner, $entries]) {
            $changes = $byOwner[$key][1] ?? [];

            foreach ($entries as $entry) {
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
                        $this->writer->reportFailure($e);

                        continue;
                    }
                }

                $changes[ElementKey::of($entry['field'], $id)] = $entry['added']
                    ? new Change(null, $entry['value'])
                    : new Change($entry['value'], null);
            }

            $byOwner[$key] = [$owner, $changes];
        }

        return array_values($byOwner);
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
    private function withContext(?EntityManagerInterface $em, object $owner, array $changes): array
    {
        $metadata = $em === null ? null : $this->metadataFactory->for($owner);

        if ($em === null || $metadata === null) {
            return $changes;
        }

        return (new ChangeSetBuilder($em, $this->comparator))->withAlwaysRecorded($owner, $metadata, $changes);
    }

    /**
     * The record for an owner that Doctrine never raised an event for, built after the
     * commit from what onFlush collected. The entity is still managed and its
     * identifier is settled, which is all this needs.
     *
     * @param array<string, Change> $changes
     */
    private function recordForOwner(ObjectManager $manager, object $owner, array $changes): ?AuditRecord
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

            return (new AuditRecord($metadata->objectType, $id, AuditEvent::UPDATE, origin: AuditOrigin::Doctrine))
                ->withChanges($this->withContext($em, $owner, $changes));
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, $record);

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

        return $current + $snapshot;
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
                $record = $record->withChanges((new ChangeSetBuilder($em, $this->comparator))->build($entity, $metadata, $this->changeSetFor($em, $entity)));
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
