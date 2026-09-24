<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;

/**
 * The scenario the feature exists for: one business operation saving several
 * times — here two flushes that revert and re-apply — recorded as one change.
 */
final class DoctrineCoalescingTest extends DoctrineTestCase
{
    private AuditFrame $frame;

    protected function setUp(): void
    {
        parent::setUp();

        // Re-wire the listener with a frame-aware writer.
        $buffer = new FrameBuffer();
        $transport = new SyncTransport($this->gateway);
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), [], FailurePolicy::Log, null, null, $buffer);
        $this->frame = new AuditFrame($buffer, $writer);

        $manager = $this->em->getEventManager();
        foreach ($manager->getAllListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof AuditSubscriber) {
                    $manager->removeEventListener([$event], $listener);
                }
            }
        }
        $manager->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory(), $this->statements));
    }

    public function testTwoFlushesInsideAFrameAreOneRecord(): void
    {
        $article = new Article('Draft');
        $this->em->persist($article);
        $this->em->flush();
        $this->gateway->documents = [];

        $this->frame->coalesce(function () use ($article): void {
            $article->title = 'Intermediate';   // the "reverse" step
            $this->em->flush();
            $article->title = 'Final';          // the "apply" step
            $this->em->flush();
        });

        $documents = $this->documents();

        self::assertCount(1, $documents);
        self::assertSame(['old' => 'Draft', 'new' => 'Final'], $documents[0]['changes']['title']);
    }

    public function testAnInnerWriteAndTheOuterOneAfterItFoldIntoOneInAFrame(): void
    {
        // Without a frame these are two records, 1 -> 9 by the nested flush and 9 -> 3 by the
        // outer one (WhatAnAbandonedFlushLeavesTest). A frame is the operation as one answer.
        [$crate, $line] = $this->aCrateWithOneLine();

        $this->frame->coalesce(function () use ($line): void {
            $this->inThePreUpdateOfTheLine($line, 9, 3);
            $line->quantity = 2;
            $this->em->flush();
        });

        $crates = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate'));

        self::assertCount(1, $crates);
        self::assertSame(['old' => 1, 'new' => 3], $crates[0]['changes']['items.'.$line->id.'.quantity']);
    }

    public function testANestedWriteTheOuterFlushTakesBackLeavesNothingInAFrame(): void
    {
        // 1 -> 9, then 9 -> 1: two facts without a frame, and the operation as one answer is
        // that nothing moved.
        [, $line] = $this->aCrateWithOneLine();

        $this->frame->coalesce(function () use ($line): void {
            $this->inThePreUpdateOfTheLine($line, 9, 1);
            $line->quantity = 2;
            $this->em->flush();
        });

        self::assertSame([], array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate')));
    }

    /**
     * @return array{0: \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate, 1: \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem}
     */
    private function aCrateWithOneLine(): array
    {
        $this->em->persist($crate = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate('C-1'));
        $crate->add($line = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem('SKU-1'));
        $this->em->flush();
        $this->gateway->documents = [];

        return [$crate, $line];
    }

    /**
     * Once, in the line's preUpdate: a nested flush writes $inner, and the outer flush is
     * left to write $outer.
     */
    private function inThePreUpdateOfTheLine(object $line, int $inner, int $outer): void
    {
        $this->em->getEventManager()->addEventListener([\Doctrine\ORM\Events::preUpdate], new class($line, $inner, $outer) {
            private bool $ran = false;

            public function __construct(private readonly object $line, private readonly int $inner, private readonly int $outer)
            {
            }

            public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
            {
                if ($this->ran || $args->getObject() !== $this->line) {
                    return;
                }

                $this->ran = true;
                $em = $args->getObjectManager();
                $this->line->quantity = $this->inner;
                $em->flush();
                $this->line->quantity = $this->outer;
            }
        });
    }

    public function testAnAlwaysRecordedFieldStillGivesTheCoalescedRecordItsContext(): void
    {
        $article = new Article('Draft');
        $this->em->persist($article);
        $this->em->flush();
        $this->gateway->documents = [];

        $this->frame->coalesce(function () use ($article): void {
            $article->title = 'Mid';
            $this->em->flush();
            $article->title = 'Final';
            $this->em->flush();
        });

        $changes = $this->documents()[0]['changes'];

        self::assertSame(['old' => 'Draft', 'new' => 'Final'], $changes['title']);
        self::assertSame(['old' => 'draft', 'new' => 'draft'], $changes['status'] ?? null, 'Article declares alwaysRecord: [status]; coalescing must not eat the context');
    }

    public function testAnOperationThatEndsWhereItStartedLeavesNoRecord(): void
    {
        $article = new Article('Same');
        $this->em->persist($article);
        $this->em->flush();
        $this->gateway->documents = [];

        $this->frame->coalesce(function () use ($article): void {
            $article->title = 'Other';
            $this->em->flush();
            $article->title = 'Same';
            $this->em->flush();
        });

        self::assertSame([], $this->documents());
    }

    public function testWithoutAFrameEachFlushIsItsOwnRecord(): void
    {
        $article = new Article('Draft');
        $this->em->persist($article);
        $this->em->flush();
        $this->gateway->documents = [];

        $article->title = 'Intermediate';
        $this->em->flush();
        $article->title = 'Final';
        $this->em->flush();

        self::assertCount(2, $this->documents());
    }
}
