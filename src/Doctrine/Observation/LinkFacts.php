<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * What the statements of watched join rows did, told against what each owner's rows held: a
 * link come to an owner's collection or gone from it, one fact a link, at the statement that
 * did it -- or doubt, where what the rows held is not known well enough to say.
 *
 * **Where each owner starts.** From its account ({@see JoinRowMemory}), with the statements the
 * account already held undone from it: that is what the rows held where the log was settled,
 * and every statement since that stayed done is carried out over it again, in the order they
 * ran. A statement the log took back after the account was read is then in neither -- not
 * where the owner starts, and not among what it did. One an account held that cannot be undone
 * from it -- all of an owner's links taken, or a target gone, by statements that do not say
 * which rows they took -- leaves the owner not known. An owner whose row is inserted starts
 * again from nothing there; one whose row goes holds nothing after it, and its links going
 * with it are its removal's, not facts of their own.
 *
 * **What is doubt.** A link added to or taken from an owner whose links are not known; all of
 * an owner's links taken, when which they were is not known or the number the statement took
 * is not the number held; a target gone whose holders were never read; and a link taken that
 * the account did not hold. Each is said, and never filled in: an owner said to hold nothing
 * because nothing was known of it would be a history of a collection emptied that was not.
 *
 * Whose flush each fact is, is asked of the log when it is made, as for every other fact; the
 * replay these are made from is made again whenever the log takes something back.
 */
final class LinkFacts
{
    /**
     * @var list<array{association: string, owner: class-string, ownerId: string, ownerKey: array<string, mixed>, collection: string, target: class-string, targetId: string, targetKey: array<string, mixed>, arrived: bool, cause: string, at: int, flush: int|null}>
     */
    private array $facts = [];

    /** @var list<array{at: int, class: class-string, doubt: string}> */
    private array $doubts = [];

    /** @var array<string, array<string, array<string, array<string, mixed>>|null>> by association and owner: what its rows hold, null where not known */
    private array $states = [];

    /**
     * By association and owner: where what its rows held was established -- where the replay
     * began, and where its row was inserted or went -- and what it was there. What its rows held
     * before any fact is the last of these at or before it, with the facts since carried out over
     * it. Where it stops being known is none of them: no fact follows until one of these again.
     * Its row going is one only a link written for an owner that is not there can follow -- which
     * a join table whose foreign keys are enforced refuses, and so no test here reaches.
     *
     * @var array<string, array<string, list<array{at: int, targets: array<string, array<string, mixed>>|null}>>>
     */
    private array $starts = [];

    private function __construct()
    {
    }

    /** Whether every statement the log kept between two positions was taken back. */
    private static function nothingStoodBetween(StatementLog $log, int $from, int $to): bool
    {
        for ($at = $from + 1; $at < $to; ++$at) {
            if ($log->statement($at) !== null && $log->fate($at) !== StatementLog::VOID) {
                return false;
            }
        }

        return $from < $to;
    }

    public static function of(EntityManagerInterface $em, HistoryReplay $replay, StatementLog $log, JoinRowMemory $memory): self
    {
        $told = new self();
        $accounts = $memory->links();
        $holdersRead = $memory->holdersRead();
        $statements = $replay->linkStatements();

        $collections = array_unique([...array_keys($accounts), ...array_keys($statements)]);
        $owning = [];

        foreach ($collections as $of) {
            $owning[explode('::', $of, 2)[0]] = true;
        }

        // An owner's row inserted or gone, by root class and key, where it was -- of the classes
        // whose links are in play: a flush of twenty thousand rows of a class with no links
        // indexed every one of them to find none.
        $rows = [];

        foreach ($owning === [] ? [] : $replay->rowFacts() as $fact) {
            if ($fact['statement'] !== StatementShape::UPDATE
                && isset($owning[$root = $em->getClassMetadata($fact['class'])->rootEntityName])
            ) {
                $rows[$root][$fact['id']][$fact['at']] = $fact['statement'];
            }
        }

        foreach ($collections as $of) {
            [$root, $collection] = explode('::', $of, 2);
            /** @var class-string $root */
            $owner = $em->getClassMetadata($root);
            $target = $em->getClassMetadata($em->getClassMetadata($owner->getAssociationTargetClass($collection))->rootEntityName);
            $targets = $statements[$of]['targets'] ?? [];

            // A target gone whose holders nobody read before it went: which owners held it is not
            // known. Read where the log stood at a statement is read after that statement.
            foreach ($targets as $went) {
                $read = $holdersRead[$of][$went['target']] ?? null;

                if ($read === null || $read >= $went['at']) {
                    $told->doubt($went['at'], $root, sprintf('%s %s went, and which %s held it is not known', $target->name, $went['target'], $of));
                }
            }

            $owners = array_map('strval', array_unique([...array_keys($accounts[$of] ?? []), ...array_keys($statements[$of]['owners'] ?? [])]));

            foreach ($owners as $id) {
                $account = $accounts[$of][$id] ?? null;
                $ownerKey = $account['owner'] ?? ($statements[$of]['owners'][$id][0]['owner'] ?? []);
                $state = $account === null ? null : self::undone($em, $log, $of, $id, $account['targets'], $account['includes']);

                // In the order they ran: its own statements, the targets that went, and its row.
                $events = [];

                foreach ($statements[$of]['owners'][$id] ?? [] as $statement) {
                    $events[] = [$statement['at'], 1, $statement];
                }

                foreach ($targets as $went) {
                    $events[] = [$went['at'], 1, ['kind' => 'target'] + $went];
                }

                foreach ($rows[$root][$id] ?? [] as $at => $statement) {
                    $events[] = [$at, 0, ['kind' => $statement === StatementShape::INSERT ? 'row' : 'row gone', 'at' => $at]];
                }

                usort($events, static fn (array $one, array $other): int => [$one[0], $one[1]] <=> [$other[0], $other[1]]);

                $told->starts[$of][$id][] = ['at' => 0, 'targets' => $state];

                foreach ($events as [$at, , $event]) {
                    $targetId = $event['target'] ?? '';
                    $targetKey = isset($event['key']) && \is_array($event['key']) ? $event['key'] : [];
                    $fact = static fn (string $targetId, array $targetKey, bool $arrived, string $cause): array => [
                        'association' => $of, 'owner' => $root, 'ownerId' => $id, 'ownerKey' => $ownerKey, 'collection' => $collection,
                        'target' => $target->name, 'targetId' => $targetId, 'targetKey' => $targetKey,
                        'arrived' => $arrived, 'cause' => $cause, 'at' => $at, 'flush' => $log->ownerOf($at),
                    ];

                    switch ($event['kind']) {
                        case 'row':
                            $state = [];
                            $told->starts[$of][$id][] = ['at' => $at, 'targets' => $state];

                            break;

                        case 'row gone':
                            // What the owner's removal took is its removal's: the links Doctrine
                            // deletes by the owner's key right before its row's DELETE are part of
                            // that execution, no move of a list of their own. Right before among
                            // what stood: a statement taken back in between never ran. What ran
                            // before -- a link added, or taken with a target -- keeps its facts.
                            $told->facts = array_values(array_filter($told->facts, static fn (array $fact): bool => !($fact['association'] === $of && $fact['ownerId'] === $id && $fact['cause'] === 'emptied' && self::nothingStoodBetween($log, $fact['at'], $at))));
                            $state = null === $state ? null : [];
                            $told->starts[$of][$id][] = ['at' => $at, 'targets' => $state];

                            break;

                        case 'add':
                            if ($state === null) {
                                $told->doubt($at, $root, sprintf('a link of %s %s added, and what its rows held is not known', $of, $id));

                                break;
                            }

                            // The join table's key lets a link in once: one its rows already held
                            // is an account that was wrong.
                            if (\array_key_exists($targetId, $state)) {
                                $told->doubt($at, $root, sprintf('a link of %s %s added that what its rows held already had', $of, $id));
                                $state = null;

                                break;
                            }

                            $told->facts[] = $fact($targetId, $targetKey, true, 'row');
                            $state[$targetId] = $targetKey;

                            break;

                        case 'remove':
                            if ($event['affected'] === 0) {
                                break; // no row was there to take
                            }

                            if ($state === null || !\array_key_exists($targetId, $state)) {
                                $told->doubt($at, $root, sprintf('a link of %s %s taken that %s', $of, $id, $state === null ? 'nothing knew of' : 'what its rows held did not have'));
                                $state = null;

                                break;
                            }

                            $told->facts[] = $fact($targetId, $targetKey, false, 'row');
                            unset($state[$targetId]);

                            break;

                        case 'all':
                            if ($state === null || \count($state) !== $event['affected']) {
                                $told->doubt($at, $root, $state === null
                                    ? sprintf('every link of %s %s taken, and which they were is not known', $of, $id)
                                    : sprintf('every link of %s %s taken: %d rows, and %d held', $of, $id, $event['affected'], \count($state)));
                                $state = null;

                                break;
                            }

                            foreach ($state as $held => $heldKey) {
                                $told->facts[] = $fact((string) $held, $heldKey, false, 'emptied');
                            }

                            $state = [];

                            break;

                        default: // a target gone
                            if ($state === null) {
                                // Doubt only of an owner that may have held it: one whose holders
                                // were read is none of them unless it has an account.
                                if ($account !== null || !isset($holdersRead[$of][$targetId])) {
                                    $told->doubt($at, $root, sprintf('%s %s went, and whether %s %s held it is not known', $target->name, $targetId, $of, $id));
                                }

                                break;
                            }

                            if (!\array_key_exists($targetId, $state)) {
                                break;
                            }

                            // By the join table's own statement: its rows, said by it.
                            if (($event['by'] ?? null) === 'statement') {
                                $told->facts[] = $fact($targetId, $targetKey, false, 'target');
                                unset($state[$targetId]);

                                break;
                            }

                            // By the target's own row going: whether its rows here went with it is
                            // what was seen right after that DELETE, and nothing later. Inside a
                            // transaction a join row goes three ways only -- the target's DELETE
                            // cascading, the owner's DELETE cascading, or a statement of the join
                            // table's, which the log holds -- and right after the target's DELETE
                            // nothing else has run: so a row this owner held and no longer holds
                            // there went with the target, its fact the DELETE's -- its position,
                            // its flush, its fate. One it still holds did not go (no cascade; a
                            // trigger is the database's, past what this reads). Not seen, or not
                            // seen for failing to ask: not known.
                            $holding = self::holdersSeenAfter($em, $log, $at, $of, $owner, $target);

                            if ($holding === null) {
                                $told->doubt($at, $root, sprintf('%s %s went, and whether %s %s still holds it was not seen', $target->name, $targetId, $of, $id));
                                $state = null;

                                break;
                            }

                            if (!isset($holding[$id])) {
                                $told->facts[] = $fact($targetId, $targetKey, false, 'target');
                                unset($state[$targetId]);
                            }
                    }
                }

                $told->states[$of][$id] = $state;
            }

            // A join table's statement for a target takes a row per holder: as many facts as it
            // took, or the holders were not what the rows held.
            foreach ($targets as $went) {
                if ($went['by'] !== 'statement') {
                    continue;
                }

                $said = \count(array_filter($told->facts, static fn (array $fact): bool => $fact['association'] === $of && $fact['at'] === $went['at']));

                if ($said !== $went['affected']) {
                    $told->doubt($went['at'], $root, sprintf('%s %s taken out of %d of %s, and %d were known to hold it', $target->name, $went['target'], $went['affected'], $of, $said));
                }
            }
        }

        usort($told->facts, static fn (array $one, array $other): int => [$one['at'], $one['association'], $one['ownerId'], $one['targetId']] <=> [$other['at'], $other['association'], $other['ownerId'], $other['targetId']]);
        usort($told->doubts, static fn (array $one, array $other): int => [$one['at'], $one['doubt']] <=> [$other['at'], $other['doubt']]);

        return $told;
    }

    /**
     * @return list<array{association: string, owner: class-string, ownerId: string, ownerKey: array<string, mixed>, collection: string, target: class-string, targetId: string, targetKey: array<string, mixed>, arrived: bool, cause: string, at: int, flush: int|null}>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    /**
     * @return list<array{at: int, class: class-string, doubt: string}>
     */
    public function doubts(): array
    {
        return $this->doubts;
    }

    /**
     * @return array<string, array<string, list<array{at: int, targets: array<string, array<string, mixed>>|null}>>>
     */
    public function starts(): array
    {
        return $this->starts;
    }

    /**
     * What each owner's rows hold once every statement read ran -- what an account is settled
     * to; null where it is not known.
     *
     * @return array<string, array<string, array<string, array<string, mixed>>|null>>
     */
    public function states(): array
    {
        return $this->states;
    }

    /**
     * The owners seen still holding a target right after its DELETE, by id; null where it was
     * not looked at, or asking failed.
     *
     * @param ClassMetadata<object> $owner  the owner's root
     * @param ClassMetadata<object> $target the target's root
     *
     * @return array<string, true>|null
     */
    private static function holdersSeenAfter(EntityManagerInterface $em, StatementLog $log, int $at, string $of, ClassMetadata $owner, ClassMetadata $target): ?array
    {
        $seen = $log->observationsOf($at);

        if (!isset($seen[$of])) {
            return null;
        }

        $collection = explode('::', $of, 2)[1];
        $read = JoinRowsQuery::ownersHolding($em->getConnection()->getDatabasePlatform(), $owner->getAssociationMapping($collection), $target->getIdentifierColumnNames());

        if ($read === null) {
            return null;
        }

        $holding = [];

        foreach ($seen[$of] as $row) {
            $holding[HistoryReplay::keyOf($owner, array_combine($read['owners'], $row))] = true;
        }

        return $holding;
    }

    /**
     * An account with the statements it held undone: what the rows held before them. Null when
     * one of them cannot be undone -- it does not say which rows it took, or the log has let
     * go of it.
     *
     * @param array<string, array<string, mixed>> $targets
     * @param list<int>                           $includes
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function undone(EntityManagerInterface $em, StatementLog $log, string $of, string $id, array $targets, array $includes): ?array
    {
        rsort($includes);

        foreach ($includes as $at) {
            $statement = $log->statement($at);
            $shape = $statement === null ? null : StatementShape::read($statement['sql']);
            $binding = $shape === null || $statement === null ? null : RowBinding::of($em, $shape, $statement['params']);

            if ($shape === null || $binding === null || $binding->kind !== RowBinding::JOIN_ROW || $binding->class === null || $binding->association === null || $binding->element === null) {
                return null;
            }

            $owner = $em->getClassMetadata($binding->class);
            $target = $em->getClassMetadata($em->getClassMetadata($owner->getAssociationTargetClass($binding->association))->rootEntityName);

            if (JoinRowMemory::associationOf($binding->class, $binding->association) !== $of || HistoryReplay::keyOf($owner, $binding->key ?? []) !== $id) {
                return null;
            }

            $targetId = HistoryReplay::keyOf($target, $binding->element);

            if ($shape->kind === StatementShape::INSERT) {
                unset($targets[$targetId]);
            } elseif ((int) $statement['affected'] > 0) {
                $targets[$targetId] = $binding->element;
            }
        }

        return $targets;
    }

    /**
     * @param class-string $class
     */
    private function doubt(int $at, string $class, string $doubt): void
    {
        $this->doubts[] = ['at' => $at, 'class' => $class, 'doubt' => $doubt];
    }
}
