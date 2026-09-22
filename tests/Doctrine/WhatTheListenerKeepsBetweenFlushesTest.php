<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * A roll call of the listener's own state, and where each piece of it is let go.
 *
 * Every defect this listener has had since it learned to number its flushes has been one
 * shape: a map that outlived the flush that filled it. A record published under a moment
 * that had already gone; an owner belonging to a flush that never happened; a line inside
 * a collection whose "from" came from a flush that was refused; a removal drafted for one
 * operation and swept up by another. Each was found in the field, fixed, and followed by
 * the next one — because the fix was always about the field that had just been noticed
 * and never about the class of them.
 *
 * So this asks the class instead of the reader: every piece of state either goes when a
 * flush ends, or is named below with the reason it does not. A field added without either
 * fails here, at the time it is added, and the decision it needs is the one nobody made
 * for its predecessors.
 *
 * Deliberately a reading of the source rather than a behaviour. There is no arrangement
 * of fixtures that says "this map is never cleared"; there is only every scenario nobody
 * thought of, which is where all four of them were found.
 */
final class WhatTheListenerKeepsBetweenFlushesTest extends TestCase
{
    /**
     * State that outlives a flush on purpose, and why.
     *
     * @var array<string, string>
     */
    private const OUTLIVES_A_FLUSH = [
        'checkedTracking' => 'a cache of what a class declared, which is fixed for the life of the process: the same names, the same representers, the same tracked fields for every instance.',
        'flush' => 'the counter that hands out the numbers. It only ever goes up, and a number given twice is the whole problem it exists to prevent.',
        'flushingManager' => 'a weak reference to the manager of the flush on the stack, replaced by every flush that begins and read only to tell an abandoned flush from a swallowed one.',
    ];

    public function testEveryFieldIsEitherLetGoWithItsFlushOrSaysWhyNot(): void
    {
        $letGo = $this->fieldsAssignedIn('forgetThisFlush');
        $missing = [];

        foreach ($this->fields() as $field) {
            if (isset(self::OUTLIVES_A_FLUSH[$field]) || \in_array($field, $letGo, true)) {
                continue;
            }

            $missing[] = $field;
        }

        self::assertSame([], $missing, sprintf(
            "these fields of the listener are neither let go when a flush ends nor named as outliving one:\n  %s\n"
            ."Either clear it in forgetThisFlush(), or add it to %s::OUTLIVES_A_FLUSH with the reason it is safe to keep.",
            implode("\n  ", $missing),
            self::class,
        ));
    }

    public function testNothingIsNamedAsOutlivingAFlushThatIsNotThereAnyMore(): void
    {
        $fields = $this->fields();
        $gone = array_values(array_filter(
            array_keys(self::OUTLIVES_A_FLUSH),
            static fn (string $field): bool => !\in_array($field, $fields, true),
        ));

        self::assertSame([], $gone, 'these fields are named as outliving a flush and no longer exist: '.implode(', ', $gone));
    }

    public function testAFieldThatIsBothIsSaidOnceRatherThanTwice(): void
    {
        // A field cleared by forgetThisFlush() and also excused here is two answers to one
        // question, and the excuse is the one that stops being read.
        $letGo = $this->fieldsAssignedIn('forgetThisFlush');
        $both = array_values(array_intersect(array_keys(self::OUTLIVES_A_FLUSH), $letGo));

        self::assertSame([], $both, 'these fields are let go with the flush and also named as outliving one: '.implode(', ', $both));
    }

    /**
     * Every field the listener keeps, minus the ones it cannot keep state in.
     *
     * A readonly property is settled at construction and cannot be per-flush state, so
     * the constructor's dependencies answer for themselves.
     *
     * @return list<string>
     */
    private function fields(): array
    {
        $fields = [];

        foreach ((new \ReflectionClass(AuditSubscriber::class))->getProperties() as $property) {
            if ($property->isStatic() || $property->isReadOnly()) {
                continue;
            }

            $fields[] = $property->getName();
        }

        self::assertNotSame([], $fields, 'the listener was read as having no state at all, which means this is reading the wrong thing');

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function fieldsAssignedIn(string $method): array
    {
        $reflection = new \ReflectionMethod(AuditSubscriber::class, $method);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        $lines = file($file, \FILE_IGNORE_NEW_LINES);

        self::assertIsArray($lines);

        $body = implode("\n", \array_slice($lines, $reflection->getStartLine(), $reflection->getEndLine() - $reflection->getStartLine()));

        self::assertMatchesRegularExpression('/\$this->\w+ = /', $body, sprintf('%s() was read as assigning nothing, which means this is reading the wrong lines', $method));

        preg_match_all('/\$this->(\w+) = /', $body, $found);

        return array_values(array_unique($found[1]));
    }
}
