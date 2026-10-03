<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Outbox;

use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use PHPUnit\Framework\TestCase;

/**
 * What the outbox's context keeps between entering and leaving: whether a transaction is open,
 * and the first reason it may not commit -- and nothing outside one.
 */
final class WhatTheOutboxContextRemembersTest extends TestCase
{
    public function testTheFirstReasonIsTheOneKept(): void
    {
        $context = new OutboxContext();
        $context->enter();
        $context->spoil('first');
        $context->spoil('second');

        self::assertSame('first', $context->spoiledBecause());
    }

    public function testEnteringAgainDoesNotCleanWhatTheOuterLevelSpoiled(): void
    {
        $context = new OutboxContext();
        $context->enter();
        $context->spoil('outer');
        $context->enter();

        self::assertSame('outer', $context->spoiledBecause());
    }

    public function testNothingIsRememberedOutsideATransaction(): void
    {
        $context = new OutboxContext();
        $context->spoil('nobody holds a transaction');

        self::assertNull($context->spoiledBecause());
    }

    public function testALeaveWithNothingOpenLeavesTheNextEnterOpen(): void
    {
        $context = new OutboxContext();
        $context->leave();
        $context->enter();

        self::assertTrue($context->isOpen());
    }

    public function testAResetLeavesTheNextEnterOpen(): void
    {
        $context = new OutboxContext();
        $context->enter();
        $context->reset();
        $context->enter();

        self::assertTrue($context->isOpen());
    }
}
