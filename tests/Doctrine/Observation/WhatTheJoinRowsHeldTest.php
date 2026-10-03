<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\JoinRowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\JoinRowsQuery;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;

/**
 * What an owning ManyToMany's join rows held when they were read (5.3a): the account a link's
 * facts are told against, read from the rows and never from Doctrine's snapshot of the
 * collection.
 */
final class WhatTheJoinRowsHeldTest extends DoctrineTestCase
{
    private JoinRowMemory $memory;

    /** @var list<array{0: string, 1: string}> what the memory asked for the statements its accounts hold */
    private array $includesAskedFor = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->memory = new JoinRowMemory($this->statements);
    }

    public function testAnOwnersLinksAreReadAsKeysAndNothingIsLoaded(): void
    {
        // Read from the join table as keys: no tag is put into the identity map, so no
        // postLoad of the application's runs on the bundle's account.
        [$article, $php, $db] = $this->anArticleTagged('php', 'db');
        [$id, $phpId, $dbId] = [$article->id, $php->id, $db->id];
        $this->em->clear();
        $article = $this->em->find(Article::class, $id);
        self::assertInstanceOf(Article::class, $article);
        $position = $this->statements->position();

        $this->memory->rememberTheLinksOf($this->em, $article, 'tags', $this->includes([7]));

        self::assertSame(
            ['owner' => ['id' => $id], 'targets' => [(string) $phpId => ['id' => $phpId], (string) $dbId => ['id' => $dbId]], 'takenAt' => $position, 'includes' => [7]],
            self::normalised($this->memory->links()[JoinRowMemory::associationOf(Article::class, 'tags')][(string) $id]),
        );
        self::assertSame([[JoinRowMemory::associationOf(Article::class, 'tags'), (string) $id]], $this->includesAskedFor, 'the statements it already holds, asked where it was taken');
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap()[Tag::class] ?? [], 'no tag was loaded');
        self::assertSame(1, $this->memory->asked());
    }

    public function testAnAccountIsReadOnceAndKept(): void
    {
        // The first account stands: read again, the rows would already hold what the log has
        // to account for since.
        [$article] = $this->anArticleTagged('php');
        $this->memory->rememberTheLinksOf($this->em, $article, 'tags', $this->includes([]));
        $first = $this->memory->links();
        $this->em->getConnection()->executeStatement('DELETE FROM article_tag');

        $this->memory->rememberTheLinksOf($this->em, $article, 'tags', $this->includes([]));

        self::assertSame($first, $this->memory->links());
        self::assertSame(1, $this->memory->asked());
    }

    public function testAnOwnerNotInsertedYetHoldsNothingAndCostsNoQuestion(): void
    {
        // Its INSERT, and its links', will say what it holds. With no key yet there is no
        // account at all; with one a sequence handed out at persist() -- PostgreSQL under DBAL 3
        // -- it is an account of nothing.
        $this->em->persist($article = new Article('New'));

        $this->memory->rememberTheLinksOf($this->em, $article, 'tags', $this->includes([]));

        // And never under an empty key, which every new owner would share.
        self::assertSame(
            $article->id === null ? [] : [JoinRowMemory::associationOf(Article::class, 'tags') => [(string) $article->id => []]],
            array_map(static fn (array $owners): array => array_map(static fn (array $account): array => $account['targets'], $owners), $this->memory->links()),
        );
        self::assertSame(0, $this->memory->asked());
    }

    public function testTheHoldersOfATargetAreReadWithEverythingTheyHoldInOneQuestion(): void
    {
        // One holds a and b, two holds a, three holds only b. The holders of a are one and
        // two, each with its whole list: a going is [a, b] -> [b] for one, not [a] -> [].
        $a = new Tag('a');
        $b = new Tag('b');
        $this->em->persist($a);
        $this->em->persist($b);
        [$one] = $this->anArticleTagged(null, null, $a, $b);
        [$two] = $this->anArticleTagged(null, null, $a);
        [$three] = $this->anArticleTagged(null, null, $b);
        $before = \count($this->queries);

        $this->memory->rememberTheHoldersOf($this->em, [$a], Article::class, 'tags', $this->includes([]));

        $of = JoinRowMemory::associationOf(Article::class, 'tags');
        $links = $this->memory->links()[$of] ?? [];
        ksort($links);

        self::assertSame(
            [(string) $one->id => [(string) $a->id, (string) $b->id], (string) $two->id => [(string) $a->id]],
            array_map(static fn (array $account): array => self::sortedKeys($account['targets']), $links),
        );
        self::assertArrayNotHasKey((string) $three->id, $links, 'an owner that does not hold it is none of its holders');
        self::assertSame(1, \count($this->queries) - $before, 'one question, whatever the number of holders');
        self::assertSame([(string) $a->id], array_map('strval', array_keys($this->memory->holdersRead()[$of])));

        // And asked again for the same target, nothing is asked.
        $this->memory->rememberTheHoldersOf($this->em, [$a], Article::class, 'tags', $this->includes([]));
        self::assertSame(1, $this->memory->asked());
    }

    public function testAHolderAlreadyAccountedForKeepsItsAccount(): void
    {
        $a = new Tag('a');
        $b = new Tag('b');
        $this->em->persist($a);
        $this->em->persist($b);
        [$one] = $this->anArticleTagged(null, null, $a, $b);
        $this->memory->rememberTheLinksOf($this->em, $one, 'tags', $this->includes([3]));
        $this->em->getConnection()->executeStatement('DELETE FROM article_tag WHERE tag_id = ?', [$b->id]);

        $this->memory->rememberTheHoldersOf($this->em, [$a], Article::class, 'tags', $this->includes([9]));

        $account = $this->memory->links()[JoinRowMemory::associationOf(Article::class, 'tags')][(string) $one->id];
        self::assertSame([[(string) $a->id, (string) $b->id], [3]], [self::sortedKeys($account['targets']), $account['includes']]);
    }

    public function testSettlingKeepsWhatIsHeldAndWhatWasFollowedAndNothingElse(): void
    {
        // The owner the application holds, with what the replay says its rows hold: kept, as an
        // account with nothing in it to undo. A holder read for a target, held by nothing: let
        // go. An owner the replay could not follow: let go, to be read again.
        $a = new Tag('a');
        $this->em->persist($a);
        [$held] = $this->anArticleTagged(null, null, $a);
        [$followedNot] = $this->anArticleTagged(null, null, $a);
        $this->memory->rememberTheLinksOf($this->em, $held, 'tags', $this->includes([4]));
        $this->memory->rememberTheLinksOf($this->em, $followedNot, 'tags', $this->includes([]));
        [$other] = $this->anArticleTagged(null, null, $a);
        $otherId = (string) $other->id;
        $this->em->detach($other);
        unset($other);
        $this->memory->rememberTheHoldersOf($this->em, [$a], Article::class, 'tags', $this->includes([]));
        $of = JoinRowMemory::associationOf(Article::class, 'tags');
        self::assertArrayHasKey($otherId, $this->memory->links()[$of], 'the premise: a holder was read');

        $this->memory->settle($this->em, [$of => [
            (string) $held->id => ['9' => ['id' => 9]],
            (string) $followedNot->id => null,
            $otherId => [],
        ]], 42);

        self::assertSame([$of => [(string) $held->id => ['owner' => ['id' => $held->id], 'targets' => ['9' => ['id' => 9]], 'takenAt' => 42, 'includes' => []]]], $this->memory->links());
        self::assertSame([], $this->memory->holdersRead());
        self::assertSame(1, $this->memory->size());
    }

    public function testTheQuestionsAreAskedOfTheJoinTableAsTheMappingNamesIt(): void
    {
        // A schema, and names the mapping quotes: the shapes no fixture has.
        $mapping = ['joinTable' => [
            'name' => 'article tag',
            'schema' => 'audit',
            'quoted' => true,
            'joinColumns' => [['name' => 'article id', 'referencedColumnName' => 'id', 'quoted' => true]],
            'inverseJoinColumns' => [['name' => 'tag_id', 'referencedColumnName' => 'id'], ['name' => 'tag_kind', 'referencedColumnName' => 'kind']],
        ]];
        $platform = $this->em->getConnection()->getDatabasePlatform();
        $q = $platform->quoteIdentifier(...);

        self::assertSame(
            ['sql' => 'SELECT tag_id, tag_kind FROM '.$q('audit').'.'.$q('article tag').' WHERE '.$q('article id').' = ?', 'params' => [5], 'targets' => ['id', 'kind']],
            JoinRowsQuery::linksOf($platform, $mapping, ['id' => 5]),
        );
        self::assertSame(
            [
                'sql' => 'SELECT j.'.$q('article id').', j.tag_id, j.tag_kind FROM '.$q('audit').'.'.$q('article tag').' j WHERE EXISTS (SELECT 1 FROM '.$q('audit').'.'.$q('article tag').' h WHERE h.'.$q('article id').' = j.'.$q('article id').' AND h.tag_id = ? AND h.tag_kind = ?)',
                'params' => [3, 'x'],
                'owners' => ['id'],
                'targets' => ['id', 'kind'],
            ],
            JoinRowsQuery::holdersOf($platform, $mapping, [['id' => 3, 'kind' => 'x']]),
        );

        // Several targets in one question: a conjunction each, for a key of several columns --
        self::assertSame(
            'SELECT j.'.$q('article id').', j.tag_id, j.tag_kind FROM '.$q('audit').'.'.$q('article tag').' j WHERE EXISTS (SELECT 1 FROM '.$q('audit').'.'.$q('article tag').' h WHERE h.'.$q('article id').' = j.'.$q('article id').' AND ((h.tag_id = ? AND h.tag_kind = ?) OR (h.tag_id = ? AND h.tag_kind = ?)))',
            JoinRowsQuery::holdersOf($platform, $mapping, [['id' => 3, 'kind' => 'x'], ['id' => 4, 'kind' => 'y']])['sql'] ?? null,
        );

        // -- and IN, for a key of one.
        $single = ['joinTable' => ['name' => 'article_tag', 'joinColumns' => [['name' => 'article_id', 'referencedColumnName' => 'id']], 'inverseJoinColumns' => [['name' => 'tag_id', 'referencedColumnName' => 'id']]]];
        self::assertSame(
            ['sql' => 'SELECT j.article_id, j.tag_id FROM article_tag j WHERE EXISTS (SELECT 1 FROM article_tag h WHERE h.article_id = j.article_id AND h.tag_id IN (?, ?, ?))', 'params' => [3, 4, 5], 'owners' => ['id'], 'targets' => ['id']],
            JoinRowsQuery::holdersOf($platform, $single, [['id' => 3], ['id' => 4], ['id' => 5]]),
        );

        // And nothing, where the mapping cannot say or the key is not what the rows carry.
        self::assertNull(JoinRowsQuery::linksOf($platform, $mapping, ['uuid' => 5]));
        self::assertNull(JoinRowsQuery::holdersOf($platform, $mapping, [['id' => 3]]));
        self::assertNull(JoinRowsQuery::linksOf($platform, ['joinTable' => ['name' => 't', 'joinColumns' => [], 'inverseJoinColumns' => []]], ['id' => 5]));
        self::assertNull(JoinRowsQuery::linksOf($platform, ['mappedBy' => 'x'], ['id' => 5]));
    }

    public function testEveryColumnIsNamedAsTheMappingQuotesItWhicheverSideItIsOn(): void
    {
        // The targets' columns quoted, and the owners' plain: the other way round from above.
        $mapping = ['joinTable' => [
            'name' => 'article_tag',
            'schema' => 'audit',
            'joinColumns' => [['name' => 'article id', 'referencedColumnName' => 'id', 'quoted' => true]],
            'inverseJoinColumns' => [['name' => 'tag id', 'referencedColumnName' => 'id', 'quoted' => true]],
        ]];
        $platform = $this->em->getConnection()->getDatabasePlatform();
        $q = $platform->quoteIdentifier(...);

        self::assertSame('SELECT '.$q('tag id').' FROM audit.article_tag WHERE '.$q('article id').' = ?', JoinRowsQuery::linksOf($platform, $mapping, ['id' => 5])['sql'] ?? null);
        self::assertSame(
            'SELECT j.'.$q('article id').', j.'.$q('tag id').' FROM audit.article_tag j WHERE EXISTS (SELECT 1 FROM audit.article_tag h WHERE h.'.$q('article id').' = j.'.$q('article id').' AND h.'.$q('tag id').' IN (?, ?))',
            JoinRowsQuery::holdersOf($platform, $mapping, [['id' => 3], ['id' => 4]])['sql'] ?? null,
        );
        self::assertSame(
            ['sql' => 'SELECT '.$q('article id').' FROM audit.article_tag WHERE '.$q('tag id').' = ?', 'owners' => ['id']],
            JoinRowsQuery::ownersHolding($platform, $mapping, ['id']),
        );
        self::assertSame(['table' => 'audit.article_tag', 'columns' => ['tag id']], JoinRowsQuery::pointingAt($mapping, ['id']), 'as a statement names it: unquoted, with its schema');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array{table: string, columns: list<string>}|null}>
     */
    public static function joinTablesThatCannotBeFollowed(): iterable
    {
        $owner = [['name' => 'article_id', 'referencedColumnName' => 'id']];
        $target = [['name' => 'tag_id', 'referencedColumnName' => 'id']];

        // A statement's join row names its targets' columns only: the owners' are not asked for.
        yield 'no owner columns' => [['joinTable' => ['name' => 'article_tag', 'joinColumns' => [], 'inverseJoinColumns' => $target]], ['table' => 'article_tag', 'columns' => ['tag_id']]];
        yield 'no target columns' => [['joinTable' => ['name' => 'article_tag', 'joinColumns' => $owner, 'inverseJoinColumns' => []]], null];
        yield 'no name' => [['joinTable' => ['name' => '', 'joinColumns' => $owner, 'inverseJoinColumns' => $target]], null];
        yield 'a target column that references nothing' => [['joinTable' => ['name' => 'article_tag', 'joinColumns' => $owner, 'inverseJoinColumns' => [['name' => 'tag_id']]]], null];
    }

    /**
     * @param array<string, mixed>                                 $mapping
     * @param array{table: string, columns: list<string>}|null $pointing
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('joinTablesThatCannotBeFollowed')]
    public function testAJoinTableTheMappingCannotSayAllOfIsNotAsked(array $mapping, ?array $pointing): void
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();

        self::assertSame(
            [null, null, null, $pointing],
            [
                JoinRowsQuery::linksOf($platform, $mapping, ['id' => 5]),
                JoinRowsQuery::holdersOf($platform, $mapping, [['id' => 3]]),
                JoinRowsQuery::ownersHolding($platform, $mapping, ['id']),
                JoinRowsQuery::pointingAt($mapping, ['id']),
            ],
        );
    }

    /**
     * An article with its tags, written: tags made of the labels given, or the tags given.
     *
     * @return array{0: Article, 1?: Tag, 2?: Tag}
     */
    private function anArticleTagged(?string $one, ?string $two = null, Tag ...$tags): array
    {
        foreach ([$one, $two] as $label) {
            if ($label !== null) {
                $this->em->persist($tags[] = new Tag($label));
            }
        }

        $article = new Article('Tagged');

        foreach ($tags as $tag) {
            $article->tags->add($tag);
        }

        $this->em->persist($article);
        $this->em->flush();

        return [$article, ...$tags];
    }

    /**
     * @param list<int> $positions
     *
     * @return \Closure(string, string): list<int>
     */
    private function includes(array $positions): \Closure
    {
        return function (string $of, string $id) use ($positions): array {
            $this->includesAskedFor[] = [$of, $id];

            return $positions;
        };
    }

    /**
     * A key as a database hands it back may be a string where the statement bound an integer.
     *
     * @param array{owner: array<string, mixed>, targets: array<string, array<string, mixed>>, takenAt: int, includes: list<int>} $account
     *
     * @return array{owner: array<string, mixed>, targets: array<string, array<string, mixed>>, takenAt: int, includes: list<int>}
     */
    private static function normalised(array $account): array
    {
        $targets = array_map(static fn (array $key): array => array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, $key), $account['targets']);
        ksort($targets);
        $account['targets'] = $targets;
        $account['owner'] = array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, $account['owner']);

        return $account;
    }

    /**
     * @param array<string, array<string, mixed>> $targets
     *
     * @return list<string>
     */
    private static function sortedKeys(array $targets): array
    {
        $keys = array_map('strval', array_keys($targets));
        sort($keys);

        return $keys;
    }
}
