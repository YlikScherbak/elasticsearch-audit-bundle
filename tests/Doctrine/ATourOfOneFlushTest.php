<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\DepartedObjects;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\EntityRowRuns;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LinkFacts;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LinkRuns;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * One flush, followed through the listener stage by stage -- the tour ARCHITECTURE.md describes,
 * as a test that fails the day a stage says something else.
 *
 * The flush renames an article and gives it a second tag. What each stage holds is read at the
 * one moment all of them still hold it: in a postFlush registered ahead of the audit listener,
 * after the commit and before the listener publishes and lets go.
 *
 * Read it top to bottom with ARCHITECTURE.md open: each block is a section there.
 */
final class ATourOfOneFlushTest extends DoctrineTestCase
{
    public function testOneFlushFromTheConnectionToTheDocument(): void
    {
        $this->em->persist($php = new Tag('php'));
        $this->em->persist($es = new Tag('es'));
        $this->em->persist($article = new Article('One'));
        $article->tags->add($php);
        $this->em->flush();
        $this->gateway->documents = [];
        $from = $this->statements->position();

        $seen = new \ArrayObject();
        $this->aheadOfTheAuditListener($seen);

        $article->title = 'Two';
        $article->tags->add($es);
        $this->em->flush();

        // "Watching the connection", "StatementLog": what the flush ran, in order, and what
        // became of it -- committed, the flush's transaction having gone through.
        self::assertSame(
            [['UPDATE Article SET title = ? WHERE id = ?', StatementLog::COMMITTED], ['INSERT INTO article_tag (article_id, tag_id) VALUES (?, ?)', StatementLog::COMMITTED]],
            $seen['log'],
        );

        // "What the rows held": the article's row as it stood before the flush, taken at
        // preFlush -- before Doctrine wrote its plan over its own memory of the row.
        self::assertSame('One', $seen['row before']['title']);

        // "HistoryReplay": the statement read as a fact of the row, both sides from the rows.
        self::assertSame([['update', ['title' => ['old' => 'One', 'new' => 'Two']]]], $seen['row facts']);

        // "From facts to runs": the execution the article's record describes.
        self::assertSame([['update', ['title']]], $seen['entity runs']);

        // And the join row, as a link fact told against the article's account, and as the
        // whole list before and after, in the order of the targets' keys.
        self::assertSame([['es', true]], $seen['link facts']);
        self::assertSame([[['php'], ['php', 'es']]], $seen['link runs']);

        // "AuditSubscriber: drafts(), publish()": one record of the article for the flush, its
        // row's change and its list's together, the always-recorded status beside them.
        self::assertSame(
            [['article', 'update', ['status' => ['old' => 'draft', 'new' => 'draft'], 'tags' => ['old' => ['php'], 'new' => ['php', 'es']], 'title' => ['old' => 'One', 'new' => 'Two']]]],
            array_map(static function (array $d): array {
                $changes = $d['changes'];
                ksort($changes);

                return [$d['objectType'], $d['event'], $changes];
            }, $this->documents()),
        );
        self::assertGreaterThan($from, $this->statements->position(), 'the premise: the flush ran statements');
    }

    /**
     * A postFlush ahead of the audit listener's, reading what every stage holds.
     *
     * @param \ArrayObject<string, mixed> $seen
     */
    private function aheadOfTheAuditListener(\ArrayObject $seen): void
    {
        $manager = $this->em->getEventManager();
        $audit = array_values(array_filter($manager->getListeners(Events::postFlush), static fn (object $one): bool => $one instanceof AuditSubscriber))[0] ?? null;
        self::assertInstanceOf(AuditSubscriber::class, $audit);
        $statements = $this->statements;

        $reader = new class($audit, $statements, $seen) {
            public function __construct(private readonly AuditSubscriber $audit, private readonly StatementLog $statements, private readonly \ArrayObject $seen)
            {
            }

            public function postFlush(PostFlushEventArgs $args): void
            {
                $em = $args->getObjectManager();
                $read = fn (string $property): mixed => (new \ReflectionProperty(AuditSubscriber::class, $property))->getValue($this->audit);
                $rows = $read('rows');
                $entityRuns = $read('entityRuns');
                $linkRuns = $read('linkRuns');
                $departed = $read('departed');
                $readThrough = $read('factsReadThrough');
                \assert($rows instanceof RowMemory && $entityRuns instanceof EntityRowRuns && $linkRuns instanceof LinkRuns && $departed instanceof DepartedObjects && \is_int($readThrough));

                $log = [];

                for ($at = $readThrough + 1; $at <= $this->statements->position(); ++$at) {
                    $statement = $this->statements->statement($at);

                    if ($statement !== null) {
                        $log[] = [$statement['sql'], $this->statements->fate($at)];
                    }
                }

                $replay = $rows->replayed($em);
                $article = array_values(array_filter($replay->rowFacts(), static fn (array $fact): bool => $fact['class'] === Article::class && $fact['at'] > $readThrough));
                $told = LinkFacts::of($em, $replay, $this->statements, $rows->links());
                $labels = static fn (array $targets): array => array_map(static fn (mixed $tag): string => $tag instanceof Tag ? $tag->label : (string) $tag, $targets);

                $this->seen['log'] = $log;
                $this->seen['row before'] = $rows->rows()[Article::class][$article[0]['id'] ?? ''] ?? [];
                $this->seen['row facts'] = array_map(static fn (array $fact): array => [$fact['statement'], $fact['fields']], $article);
                $this->seen['entity runs'] = array_map(static fn (array $run): array => [$run['event'], array_keys($run['bare'])], $entityRuns->of($em, $replay, $this->statements, $readThrough, $departed));
                $this->seen['link facts'] = array_map(static fn (array $fact): array => [$em->find(Tag::class, $fact['targetId'])?->label, $fact['arrived']], $told->facts());
                $this->seen['link runs'] = array_map(static fn (array $run): array => [$run['old'], $run['new']], $linkRuns->of($em, $replay, $this->statements, $told, $departed));
            }
        };

        foreach ($manager->getListeners(Events::postFlush) as $one) {
            $manager->removeEventListener([Events::postFlush], $one);
        }

        $manager->addEventListener([Events::postFlush], $reader);
        $manager->addEventListener([Events::postFlush], $audit);
    }
}
