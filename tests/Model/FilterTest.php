<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\Filter;
use Borsche\ElasticsearchAuditBundle\Model\FilterKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a filter refuses to be built out of.
 *
 * Both refusals here exist for the same reason: an audit query that answers with an
 * empty page reads as "nothing happened", and that is the one wrong answer a history
 * must never give by accident. A range whose bounds are crossed and a list with
 * nothing in it can only ever answer that way, so they are refused where they are
 * written rather than answered from the cluster.
 *
 * The judgement is deliberately narrow. Two numbers order the same way everywhere and
 * so do two dates, but two strings do not — PHP reads "10" and "9" as numbers while an
 * Elasticsearch keyword orders them as text, and nothing here knows which the field
 * is. Refusing on PHP's reading would turn perfectly good ranges into errors.
 */
final class FilterTest extends TestCase
{
    public function testAListWithNothingInItIsRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('matchNothing');

        Filter::in([]);
    }

    public function testAValueThatIsNotScalarIsRefusedAndNamed(): void
    {
        // Named, because a list of values is usually built from a mapping over entities
        // and the mistake is one forgotten ->getId(). "A value to filter by is a
        // stdClass" says which line to look at; a cluster-side parse error does not.
        try {
            Filter::in(['a', new \stdClass(), 'c']);
            self::fail('a non-scalar value should have been refused');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('stdClass', $e->getMessage());
        }
    }

    public function testAListIsKeptAsAListWhateverKeysItArrivedWith(): void
    {
        // array_filter upstream of a filter leaves holes in the keys, and a map is not a
        // JSON list: it encodes as an object and the terms query stops being a terms
        // query.
        $filter = Filter::in([3 => 'a', 7 => 'b']);

        self::assertSame(['a', 'b'], $filter->values);
    }

    /**
     * @return iterable<string, array{int|float|string|\DateTimeInterface|null, int|float|string|\DateTimeInterface|null, bool}>
     */
    public static function ranges(): iterable
    {
        yield 'numbers the right way round' => [1, 10, true];
        yield 'numbers the wrong way round' => [10, 1, false];
        yield 'the same number twice' => [5, 5, true];
        yield 'floats the wrong way round' => [1.5, 1.4, false];
        yield 'an int and a float, crossed' => [2, 1.99, false];

        yield 'only a lower bound' => [10, null, true];
        yield 'only an upper bound' => [null, 1, true];

        yield 'dates the right way round' => [new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), true];
        yield 'dates the wrong way round' => [new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable('2026-01-01'), false];
        yield 'the same instant twice' => [new \DateTimeImmutable('2026-06-01 12:00:00'), new \DateTimeImmutable('2026-06-01 12:00:00'), true];

        // Strings are left alone on purpose: the field decides how they order, and this
        // does not know the field.
        yield 'strings PHP would read as crossed' => ['10', '9', true];
        yield 'strings in any order at all' => ['zebra', 'aardvark', true];

        // Mixed kinds are the same question with no answer: a number against a date has
        // no ordering worth refusing on.
        yield 'a number against a date' => [5, new \DateTimeImmutable('2026-01-01'), true];
    }

    #[DataProvider('ranges')]
    public function testWhichRangesAreImpossibleOnTheirFace(
        int|float|string|\DateTimeInterface|null $from,
        int|float|string|\DateTimeInterface|null $to,
        bool $allowed,
    ): void {
        if (!$allowed) {
            $this->expectException(InvalidQueryException::class);
        }

        $filter = Filter::between($from, $to);

        self::assertSame(FilterKind::Between, $filter->kind);
    }

    public function testARangeWithNoBoundsAtAllIsNotARange(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('at least one bound');

        Filter::between(null, null);
    }

    public function testACrossedRangeSaysWhichBoundsItMeans(): void
    {
        try {
            Filter::between(new \DateTimeImmutable('2026-12-31T00:00:00+00:00'), new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
            self::fail('a crossed range should have been refused');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('2026-12-31T00:00:00+00:00', $e->getMessage());
            self::assertStringContainsString('2026-01-01T00:00:00+00:00', $e->getMessage());
        }
    }
}
