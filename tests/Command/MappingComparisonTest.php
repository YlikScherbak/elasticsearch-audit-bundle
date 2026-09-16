<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Command;

use Borsche\ElasticsearchAuditBundle\Command\MappingComparison;
use PHPUnit\Framework\TestCase;

/**
 * What counts as the same mapping, and what counts as drift.
 *
 * This is what `audit:check` reports and what `audit:index:sync` acts on, so both of
 * its mistakes cost something. Calling a matching field different sends an operator
 * to fix a mapping that is fine — and, through sync, adds a field that is already
 * there. Calling a differing field the same hides the drift that makes a query answer
 * nothing: a `keyword` filter against a field somebody mapped as `text` matches no
 * records at all, and an audit trail answering "nothing happened" is the failure this
 * whole command exists to catch.
 */
final class MappingComparisonTest extends TestCase
{
    public function testAFieldTheIndexDoesNotHaveIsMissing(): void
    {
        $diff = MappingComparison::between(
            ['objectId' => ['type' => 'keyword'], 'tenant' => ['type' => 'keyword']],
            ['objectId' => ['type' => 'keyword']],
        );

        self::assertSame(['tenant'], $diff->missing);
        self::assertSame([], $diff->mismatched);
    }

    public function testAFieldMappedAsSomethingElseIsMismatchedAndNamesBothSides(): void
    {
        $diff = MappingComparison::between(
            ['objectId' => ['type' => 'keyword']],
            ['objectId' => ['type' => 'text']],
        );

        self::assertSame([], $diff->missing);
        self::assertSame(['objectId is text, expected keyword'], $diff->mismatched);
    }

    public function testAnObjectNeedsNoTypeOnEitherSide(): void
    {
        // Elasticsearch does not write "type": "object" for a field that holds
        // properties, and neither does the bundle's own definition. Reading the absence
        // as "no type at all" made every object field on every index look wrong, which
        // is a check nobody can get to green.
        $diff = MappingComparison::between(
            ['changes' => ['properties' => ['status' => ['type' => 'keyword']]]],
            ['changes' => ['properties' => ['status' => ['type' => 'keyword']]]],
        );

        self::assertSame([], $diff->missing);
        self::assertSame([], $diff->mismatched);
    }

    public function testAnObjectWhereSomethingElseWasExpectedIsStillDrift(): void
    {
        // The other direction: the index has a flat field where the mapping says there
        // are properties. Saying so needs a word for "the thing it actually is", and
        // "an object" is that word when the cluster gave no type.
        $diff = MappingComparison::between(
            ['changes' => ['type' => 'keyword']],
            ['changes' => ['properties' => ['status' => ['type' => 'keyword']]]],
        );

        self::assertSame(['changes is object, expected keyword'], $diff->mismatched);
    }

    public function testAFieldTheClusterGaveNoTypeForAtAllIsCalledSomething(): void
    {
        // Neither a type nor properties — a field the cluster describes only by its
        // settings. "changes is , expected keyword" is not a sentence anybody can act
        // on, so there is a word for this too.
        $diff = MappingComparison::between(
            ['changes' => ['type' => 'keyword']],
            ['changes' => ['index' => false]],
        );

        self::assertSame(['changes is an object, expected keyword'], $diff->mismatched);
    }

    public function testAFlatFieldWhereAnObjectWasExpectedIsDriftToo(): void
    {
        // The mapping says this field holds properties and the index says it is a
        // keyword. That is the same drift as the case above read from the other side,
        // and it has to be reported from both — an index created before a field grew
        // sub-fields looks exactly like this.
        $diff = MappingComparison::between(
            ['changes' => ['properties' => ['status' => ['type' => 'keyword']]]],
            ['changes' => ['type' => 'keyword']],
        );

        self::assertSame(['changes is keyword, expected object'], $diff->mismatched);
    }

    public function testANestedFieldIsNamedByItsPath(): void
    {
        // Because that is what an operator types into audit:index:sync's output to find
        // it, and what they would have to search the mapping for.
        $diff = MappingComparison::between(
            ['changes' => ['properties' => ['status' => ['type' => 'keyword'], 'total' => ['type' => 'long']]]],
            ['changes' => ['properties' => ['status' => ['type' => 'text']]]],
        );

        self::assertSame(['changes.total'], $diff->missing);
        self::assertSame(['changes.status is text, expected keyword'], $diff->mismatched);
    }

    public function testAFieldTheIndexHasAndTheMappingDoesNotIsNobodysProblem(): void
    {
        // An index may carry more than the bundle knows about — another application's
        // field, one an enricher used to add. The comparison is about what must be
        // there, not about what may not.
        $diff = MappingComparison::between(
            ['objectId' => ['type' => 'keyword']],
            ['objectId' => ['type' => 'keyword'], 'somethingElse' => ['type' => 'long']],
        );

        self::assertSame([], $diff->missing);
        self::assertSame([], $diff->mismatched);
    }
}
