<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\HistoryReplay;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * The history the connection's log gives, as the acceptance tests write facts -- TEST-ONLY, a
 * thin reading of {@see HistoryReplay}, which is the listener's own.
 *
 * What it adds is where the starting rows come from when a test wants them read from the
 * database before a scenario, and a fact written as "<type> <id> <field>: <old> -> <new>",
 * which is how the acceptance tests state the truth.
 */
final class ShadowHistory
{
    /**
     * @param array<string, array<string, array<string, mixed>>> $rows
     * @param array<string, array<string, int>>                 $takenAt
     */
    private function __construct(private readonly EntityManagerInterface $em, private readonly array $rows, private readonly array $takenAt = [])
    {
    }

    /**
     * Starting from the rows as they are now.
     *
     * @param list<class-string> $classes
     */
    public static function fromTheRows(EntityManagerInterface $em, array $classes): self
    {
        return new self($em, self::theRows($em, $classes));
    }

    /**
     * The rows as they are now, by root class and key.
     *
     * @param list<class-string> $classes
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function theRows(EntityManagerInterface $em, array $classes): array
    {
        $rows = [];

        foreach ($classes as $class) {
            $metadata = $em->getClassMetadata($class);

            foreach ($em->getConnection()->fetchAllAssociative('SELECT * FROM '.$metadata->getTableName()) as $row) {
                $rows[$metadata->rootEntityName][HistoryReplay::keyOf($metadata, $row)] = $row;
            }
        }

        return $rows;
    }

    /**
     * Starting from rows remembered elsewhere, each counting from where the log stood when it
     * was taken.
     *
     * @param array<string, array<string, array<string, mixed>>> $rows
     * @param array<string, array<string, int>>                 $takenAt
     */
    public static function fromWhatWasRemembered(EntityManagerInterface $em, array $rows, array $takenAt = [], ?bool $countsChangedRows = null): self
    {
        $shadow = new self($em, $rows, $takenAt);
        $shadow->countsChangedRows = $countsChangedRows;

        return $shadow;
    }

    /** Whether an UPDATE's count is of the rows it changed (MySQL's) -- null, by the platform. */
    private ?bool $countsChangedRows = null;

    /**
     * @return array{facts: list<string>, unsure: list<string>, owners: list<int|null>}
     */
    public function replay(StatementLog $log, int $from): array
    {
        $replay = new HistoryReplay($this->em, $this->rows, $this->takenAt, countsChangedRows: $this->countsChangedRows);
        $replay->replay($log, $from);

        $facts = [];
        $owners = [];

        foreach ($replay->facts() as $fact) {
            $facts[] = sprintf('%s %s %s: %s -> %s', $fact['type'], $fact['id'], $fact['field'], json_encode($fact['old']), json_encode($fact['new']));
            $owners[] = $fact['flush'];
        }

        return ['facts' => $facts, 'unsure' => $replay->doubts(), 'owners' => $owners];
    }

    /**
     * @param ClassMetadata<object>   $metadata
     * @param array<array-key, mixed> $row
     */
    public static function keyOf(ClassMetadata $metadata, array $row): string
    {
        return HistoryReplay::keyOf($metadata, $row);
    }
}
