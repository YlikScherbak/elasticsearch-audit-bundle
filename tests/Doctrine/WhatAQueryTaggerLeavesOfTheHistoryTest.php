<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A query tagger -- sqlcommenter, a tracer -- puts a comment on every statement the connection
 * runs. Outside the observer, the observer sees the comment; inside, between it and the driver,
 * it does not. Either way the history is the one written without a tagger: what is read is each
 * statement without its comments, and what the log keeps is each as it ran.
 */
final class WhatAQueryTaggerLeavesOfTheHistoryTest extends DoctrineTestCase
{
    private const SAID = [
        ['article', 'create', ['title' => ['old' => null, 'new' => 'One /* not */ -- a comment'], 'status' => ['old' => null, 'new' => 'draft'], 'tags' => ['old' => [], 'new' => ['a']]]],
        ['crate', 'create', ['status' => ['old' => null, 'new' => 'packed'], 'items.1' => ['old' => null, 'new' => 'SKU-A']]],
        ['article', 'update', ['title' => ['old' => 'One /* not */ -- a comment', 'new' => 'Two'], 'tags' => ['old' => ['a'], 'new' => ['b']], 'status' => ['old' => 'draft', 'new' => 'draft']]],
        ['crate', 'update', ['items.1.quantity' => ['old' => 1, 'new' => 5], 'status' => ['old' => 'packed', 'new' => 'packed']]],
        ['article', 'remove', []],
    ];

    /** @return iterable<string, array{string, string, bool, 3?: bool}> */
    public static function taggers(): iterable
    {
        yield 'no tagger' => ['', '', true];

        foreach (['outside the observer' => true, 'between it and the driver' => false] as $where => $outside) {
            yield "a comment after, $where" => ['', " /*traceparent='00-ab-cd-01',route='/x'*/", $outside];
            yield "a comment before, $where" => ["/* app:checkout */ ", '', $outside];
            yield "a line comment after, $where" => ['', ' -- tagged', $outside];
        }

        // MySQL's own comment, read as one only on MySQL: the connection's dialect, told by its
        // platform when it connects.
        yield 'a hash comment after, outside the observer, on MySQL' => ['', ' # tagged', true, true];
    }

    protected function middlewaresAroundTheObserver(): array
    {
        [$before, $after, $outside, $mysqlOnly] = $this->providedData() + ['', '', true, false];

        if ($mysqlOnly && !self::onMySql()) {
            return []; // elsewhere the comment is SQL the database refuses, the schema's first
        }

        return $outside && ($before !== '' || $after !== '') ? [new CommentingMiddleware($before, $after)] : [];
    }

    private static function onMySql(): bool
    {
        return str_starts_with((string) getenv('AUDIT_DB_URL'), 'mysql');
    }

    protected function middlewaresOfTheTest(): array
    {
        [$before, $after, $outside] = $this->providedData() + ['', '', true];

        return !$outside ? [new CommentingMiddleware($before, $after)] : [];
    }

    #[DataProvider('taggers')]
    public function testTheHistoryIsTheOneWrittenWithoutATagger(string $before, string $after, bool $outside, bool $mysqlOnly = false): void
    {
        if ($mysqlOnly && !self::onMySql()) {
            self::markTestSkipped('A hash begins a comment on MySQL alone.');
        }

        $this->em->persist($a = new Tag('a'));
        $this->em->persist($b = new Tag('b'));
        $article = new Article('One /* not */ -- a comment');
        $article->tags->add($a);
        $this->em->persist($article);
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($item = new CrateItem('SKU-A'));
        $this->em->flush();

        $article->title = 'Two';
        $article->tags->removeElement($a);
        $article->tags->add($b);
        $item->quantity = 5;
        $this->em->flush();

        $this->em->remove($article);
        $this->em->flush();

        self::assertSame(self::SAID, array_map(static fn (array $d): array => [$d['objectType'], $d['event'], $d['changes'] ?? []], $this->documents()));
        self::assertSame([], $this->logs, 'and nothing said it could not follow what ran');

        // What the log keeps is each statement as it ran -- with the comment, where the observer
        // saw one -- never the copy it read.
        $kept = [];

        for ($at = 1; $at <= $this->statements->position(); ++$at) {
            $statement = $this->statements->statement($at);

            if ($statement !== null && str_contains($statement['sql'], 'UPDATE CrateItem')) {
                $kept[] = $statement['sql'];
            }
        }

        $sees = $outside ? $before.'UPDATE CrateItem SET quantity = ? WHERE id = ?'.$after : 'UPDATE CrateItem SET quantity = ? WHERE id = ?';
        self::assertSame([$sees], $kept);
    }
}
