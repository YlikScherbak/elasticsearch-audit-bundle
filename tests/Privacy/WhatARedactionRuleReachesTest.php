<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Exception\RedactionLimitExceeded;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How far a redaction rule reaches, and what it refuses to be written as.
 *
 * Redaction is the one thing in this bundle that cannot be repaired afterwards: a
 * value written into the index in full has been written, and deleting the document
 * later does not unwrite it from a snapshot, a replica or whatever read it in the
 * meantime. So every boundary here fails closed — the record does not go out, and the
 * writer's failure policy says so — rather than letting the walk quietly stop short
 * and publish the rest.
 *
 * The refusals at configuration time are the same decision made earlier: a rule that
 * could never match is worse than no rule, because somebody is relying on it.
 */
final class WhatARedactionRuleReachesTest extends TestCase
{
    /**
     * Every base field, with the thing its refusal has to tell you instead. A rule
     * naming one of these is silently useless — redaction covers the fields inside
     * `changes` and the attributes, and these are neither — so it is refused where it
     * is written, with the way round it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function baseFields(): iterable
    {
        yield 'the actor' => ['source', 'ActorResolverInterface'];
        yield 'the object id' => ['objectId', 'internal id'];
        yield 'the changes as a whole' => ['changes', '"user.password"'];
        yield 'the object type' => ['objectType', 'findable'];
    }

    #[DataProvider('baseFields')]
    public function testARuleNamingABaseFieldIsRefusedWithTheWayRoundIt(string $field, string $advice): void
    {
        try {
            new ChangeRedactor([$field]);
            self::fail(sprintf('"%s" should not be accepted as a redaction rule', $field));
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($field, $e->getMessage());
            self::assertStringContainsString($advice, $e->getMessage(), 'the refusal did not say what to do instead');
        }
    }

    public function testAPairIsScrubbedOnBothSides(): void
    {
        // A change arrives as old/new, and a rule that covered only one of them would
        // write the value it exists to remove into the other — which is the same leak
        // with an extra step.
        $redactor = new ChangeRedactor(['password']);

        $record = $redactor->redact(new AuditRecord('user', 1, AuditEvent::UPDATE, changes: [
            'password' => new Change('old-secret', 'new-secret'),
        ]));

        $change = $record->changes['password'];

        self::assertInstanceOf(Change::class, $change);
        self::assertStringNotContainsString('old-secret', (string) json_encode($change));
        self::assertStringNotContainsString('new-secret', (string) json_encode($change));
    }

    public function testAPairSpeltAsAnArrayIsScrubbedToo(): void
    {
        // The same change written as ['old' => …, 'new' => …] — which is what a record
        // built by hand, or read back and re-sent, looks like.
        $redactor = new ChangeRedactor(['password']);

        $record = $redactor->redact(new AuditRecord('user', 1, AuditEvent::UPDATE, changes: [
            'password' => ['old' => 'old-secret', 'new' => 'new-secret'],
        ]));

        self::assertStringNotContainsString('secret', (string) json_encode($record->changes));
    }

    public function testARuleScopedToOneObjectTypeLeavesTheOthersAlone(): void
    {
        $redactor = new ChangeRedactor(['user.password']);

        $mine = $redactor->redact(new AuditRecord('user', 1, AuditEvent::UPDATE, changes: ['password' => new Change('a', 'secret')]));
        $theirs = $redactor->redact(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['password' => new Change('a', 'secret')]));

        self::assertStringNotContainsString('secret', (string) json_encode($mine->changes));
        self::assertStringContainsString('secret', (string) json_encode($theirs->changes), 'a rule for one object type reached another');
    }

    public function testAStructureDeeperThanTheLimitIsRefusedRatherThanPartlyWalked(): void
    {
        // Fail closed, and exactly at the limit. Walking as far as the limit and leaving
        // the rest alone is the safe choice for a data transformer and the wrong one
        // here: a rule reading as "this name, anywhere" would stop applying at a depth
        // nobody thinks about, and write the value in full.
        $redactor = new ChangeRedactor(['password'], maxDepth: 3);

        $atTheLimit = ['a' => ['b' => ['password' => 'secret']]];

        $record = $redactor->redact(new AuditRecord('user', 1, AuditEvent::UPDATE, changes: ['payload' => $atTheLimit]));

        self::assertStringNotContainsString('secret', (string) json_encode($record->changes));

        $this->expectException(RedactionLimitExceeded::class);

        $redactor->redact(new AuditRecord('user', 1, AuditEvent::UPDATE, changes: [
            'payload' => ['a' => ['b' => ['c' => ['password' => 'secret']]]],
        ]));
    }

    public function testARecordThatNeededNoRedactionIsWrittenTheSameWayAsOneThatDid(): void
    {
        // An object stays an object. json_encode turns [] into a JSON array and an empty
        // stdClass into a JSON object, and Elasticsearch maps those differently — so a
        // field that was rebuilt because something inside it was removed must not change
        // shape on the way.
        $redactor = new ChangeRedactor(['password']);

        $untouched = (object) ['keep' => 'this'];
        $rebuilt = (object) ['keep' => 'this', 'password' => 'secret'];

        $record = $redactor->redact(new AuditRecord('user', 1, AuditEvent::UPDATE, changes: [
            'left' => $untouched,
            'scrubbed' => $rebuilt,
        ]));

        self::assertIsObject($record->changes['left']);
        self::assertIsObject($record->changes['scrubbed'], 'a rebuilt object came back as an array');
        self::assertStringNotContainsString('secret', (string) json_encode($record->changes));
    }

    public function testNothingIsRebuiltWhenNothingWasRemoved(): void
    {
        // The other half: a structure with nothing to redact comes back as the same
        // value, not as a copy — cheaper, and it keeps whatever the application put
        // there exactly as it put it.
        $redactor = new ChangeRedactor(['password']);

        // Asserted on an object, because two equal arrays are assertSame to PHPUnit and
        // the question here is whether it is the *same* value or a rebuilt copy.
        $payload = (object) ['a' => ['b' => 'plain'], 'c' => 'also plain'];

        $record = $redactor->redact(new AuditRecord('user', 1, AuditEvent::UPDATE, changes: ['payload' => $payload]));

        self::assertSame($payload, $record->changes['payload'], 'a structure with nothing to remove was rebuilt anyway');
    }

    public function testARuleForAnotherObjectTypeDoesNotHideTheRulesAfterIt(): void
    {
        // Rules are walked in the order they were configured, and one scoped to another
        // object type is skipped. Leaving the walk instead of skipping one rule means
        // every rule written after it stops applying — silently, and only for the object
        // types that come alphabetically after somebody else's.
        $redactor = new ChangeRedactor(['order.reference', 'password']);

        $record = $redactor->redact(new AuditRecord('user', 1, AuditEvent::UPDATE, changes: [
            'password' => new Change('a', 'secret'),
        ]));

        self::assertStringNotContainsString('secret', (string) json_encode($record->changes), 'the rule after one for another object type never ran');
    }

    public function testFollowingAChainOfWrappersCostsTheBudgetLikeAnythingElse(): void
    {
        // A value that serialises to another value is followed rather than trusted —
        // otherwise a rule would never see what is inside a DTO. Following is a walk
        // like any other, so it has to be paid for: a chain nobody meant to build is
        // refused instead of walked until the request dies.
        $redactor = new ChangeRedactor(['password'], maxNodes: 3);

        $deep = new Wrapper(new Wrapper(new Wrapper(new Wrapper(['password' => 'secret']))));

        $this->expectException(RedactionLimitExceeded::class);

        $redactor->redact(new AuditRecord('user', 1, AuditEvent::UPDATE, changes: ['payload' => $deep]));
    }
}

/**
 * A value that says it is really another value — the shape a DTO takes on its way
 * into a record.
 */
final class Wrapper implements \JsonSerializable
{
    public function __construct(private readonly mixed $inside)
    {
    }

    public function jsonSerialize(): mixed
    {
        return $this->inside;
    }
}
