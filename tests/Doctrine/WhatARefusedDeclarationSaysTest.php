<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MisspelledTracking;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\PackingCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Yard;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;

/**
 * The sentences a refused declaration is made of.
 *
 * This bundle refuses a declaration it cannot honour rather than recording nothing and
 * saying nothing, and the refusal is the only thing the developer gets. It has to name
 * the class, the field, what is wrong with it and what to write instead — a message that
 * says "tracks the element field" and stops has cost somebody an afternoon.
 *
 * The tests next door check that a refusal happened and look for one word in it. That
 * leaves the sentence itself unasserted, which mutation testing pointed at: every piece
 * of it could be removed or joined up differently and nothing anywhere noticed.
 */
final class WhatARefusedDeclarationSaysTest extends DoctrineTestCase
{
    public function testTrackingSomethingThatIsAnAssociationOfTheElement(): void
    {
        // And, in the same refusal, that the check reached this collection at all: the
        // one declared before it names no fields to track, so there is nothing in it to
        // be wrong — and the reading carries on rather than stopping there.
        $this->attachListener(FailurePolicy::Throw);

        $yard = new Yard();
        $yard->cases->add($case = new PackingCase('crate-1'));
        $case->yard = $yard;

        $this->em->persist($yard);
        $this->em->persist($case);

        try {
            $this->em->flush();
            self::fail('the declaration should have been refused');
        } catch (WriteFailedException $refused) {
            self::assertStringContainsString(sprintf(
                '%s::$cases tracks the element field "pallet", which is an association of %s. Element tracking records what changed inside an element, and only its own scalar columns are reported that way.',
                Yard::class,
                PackingCase::class,
            ), self::chain($refused));
        }
    }

    public function testTrackingSomethingTheElementDoesNotHaveAtAll(): void
    {
        // The other half of the same sentence, and the reason it is a choice rather than
        // one wording for both: "is an association of" sends somebody to look at a
        // mapping they wrote, "is not a field of" sends them to look for a typo.
        $this->attachListener(FailurePolicy::Throw);

        $owner = new MisspelledTracking();
        $this->em->persist($owner);

        try {
            $this->em->flush();
            self::fail('the declaration should have been refused');
        } catch (WriteFailedException $refused) {
            self::assertStringContainsString(sprintf(
                '%s::$lines tracks the element field "quanitity", which is not a field of %s. Element tracking records what changed inside an element, and only its own scalar columns are reported that way.',
                MisspelledTracking::class,
                ShipmentLine::class,
            ), self::chain($refused));
        }
    }

    /**
     * Every message in the chain, which is where a refusal built inside the listener
     * ends up by the time the writer has raised it.
     */
    private static function chain(\Throwable $thrown): string
    {
        $said = [];

        for ($link = $thrown; $link !== null; $link = $link->getPrevious()) {
            $said[] = $link->getMessage();
        }

        return implode("\n", $said);
    }
}
