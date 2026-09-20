<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Address;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Customer;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MisspelledTracking;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\PostBox;
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

    public function testAuditingSomethingDoctrineDoesNotMapAtAll(): void
    {
        // The plainest refusal of the three, and the one whose whole job is to say that
        // nothing would have been recorded — rather than recording nothing and letting
        // somebody find out from an empty history a year later.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist(new PostBox());

        try {
            $this->em->flush();
            self::fail('the declaration should have been refused');
        } catch (WriteFailedException $refused) {
            self::assertStringContainsString(sprintf(
                '%s::$nickname is audited, but Doctrine maps it as neither a field nor an association, so nothing about it would ever be recorded.',
                PostBox::class,
            ), self::chain($refused));
        }
    }

    public function testAuditingAnEmbeddableNamesTheColumnsToAuditInstead(): void
    {
        // The subtler one, and the reason it gets a sentence of its own: the property is
        // mapped, just never under the name it was audited by. Doctrine reports its
        // columns as "address.city" and "address.street", and there is no property with
        // either name to put an attribute on — so the refusal names the columns, then
        // names the first of them to show what an attribute would have to sit on, and
        // then names the only way left to declare them.
        //
        // The fixture carries an "addressNote" column as well, which is not part of the
        // embeddable and must not be named: "starts with address" sweeps it in, and the
        // developer is then told to audit a column they never asked about.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist(new Customer('Ada', new Address('Kyiv', 'Khreshchatyk')));

        try {
            $this->em->flush();
            self::fail('the declaration should have been refused');
        } catch (WriteFailedException $refused) {
            self::assertStringContainsString(sprintf(
                '%s::$address is audited, but it is an embeddable: Doctrine reports its columns as "address.city", "address.street" and never as "address", so nothing about it would ever be recorded. Name those columns instead — which #[AuditField] cannot do, since there is no property called "address.city" to put it on: declare them through AuditableInterface::getAuditedFields(), which takes the names as strings.',
                Customer::class,
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
