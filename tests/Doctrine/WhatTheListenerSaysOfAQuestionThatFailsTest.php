<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;

/**
 * What the listener asks of the join rows in onFlush, refused by the database: each failure
 * goes through the policy, every one of them -- the flush goes on, and what it would have read
 * is not known.
 */
final class WhatTheListenerSaysOfAQuestionThatFailsTest extends DoctrineTestCase
{
    protected function middlewaresOfTheTest(): array
    {
        return [new RefusingMiddleware(match ($this->name()) {
            // The links of an owner a flush is about to change.
            'testAFailedReadOfAnOwnersLinksIsSaid' => 'SELECT tag_id FROM article_tag WHERE',
            // Who holds a target about to go: one question per collection that can hold a tag.
            default => 'SELECT j.',
        })];
    }

    public function testAFailedReadOfAnOwnersLinksIsSaid(): void
    {
        $this->em->persist($a = new Tag('a'));
        $this->em->persist($article = new Article('One'));
        $this->em->flush();
        $this->logs = [];
        $this->unownedStatementsAreExpected = true;

        $article->tags->add($a);
        $this->em->flush();

        self::assertCount(1, $this->refusals());
    }

    public function testEveryFailedReadOfWhoHoldsATargetIsSaid(): void
    {
        $this->em->persist($a = new Tag('a'));
        $this->em->flush();
        $this->logs = [];
        $this->unownedStatementsAreExpected = true;

        $this->em->remove($a);
        $this->em->flush();

        // An article's tags, a shelf's labels, and the misdeclared fixture's.
        self::assertCount(3, $this->refusals());
    }

    /** @return list<string> */
    private function refusals(): array
    {
        return array_values(array_filter($this->logs, static fn (string $line): bool => str_starts_with($line, 'Audit record could not be written: RuntimeException')));
    }
}
