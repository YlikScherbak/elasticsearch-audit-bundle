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
        $manager->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory()));
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
