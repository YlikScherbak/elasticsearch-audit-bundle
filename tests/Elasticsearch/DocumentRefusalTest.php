<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\DocumentRefusal;
use PHPUnit\Framework\TestCase;

/**
 * How a refused document is described without repeating what the cluster said about
 * it — the privacy boundary that holds on both the single-write and the bulk path.
 *
 * Reached only through those two paths before, and always with an error type present.
 * What was never asked is what it does with an answer that names no type, which is
 * the branch that decides whether the caller gets a sentence or is left to say what
 * it knows itself.
 */
final class DocumentRefusalTest extends TestCase
{
    public function testAnErrorTypeIsNamedAndTheWordingIsNot(): void
    {
        $described = DocumentRefusal::describe('document_parsing_exception', "failed to parse field [email] of type [keyword]: 'alice@example.com'");

        self::assertNotNull($described);
        self::assertStringContainsString('document_parsing_exception', $described);
        self::assertStringContainsString('field "email"', $described, 'the field is lifted out, because it is the actionable half');
        self::assertStringNotContainsString('alice@example.com', $described, 'and nothing else of the wording travels');
    }

    public function testAWordingThisDoesNotRecogniseCostsAFieldNameAndLeaksNothing(): void
    {
        $described = DocumentRefusal::describe('mapper_parsing_exception', 'object mapping for [changes] tried to parse as object');

        self::assertNotNull($described);
        self::assertStringContainsString('mapper_parsing_exception', $described);
        self::assertStringNotContainsString('changes', $described);
    }

    public function testAnAnswerWithNoTypeIsNotDescribedAtAll(): void
    {
        // Null rather than a sentence, and the difference is not cosmetic: the caller
        // knows the status and the index, and a manufactured sentence here would stand
        // in front of what it could have said instead.
        self::assertNull(DocumentRefusal::describe(null, 'anything at all'));
        self::assertNull(DocumentRefusal::describe('', 'anything at all'), 'an empty type names nothing either');
        self::assertNull(DocumentRefusal::describe(['not', 'a', 'string'], 'anything at all'));
    }
}
