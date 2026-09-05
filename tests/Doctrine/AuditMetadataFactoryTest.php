<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadata;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Comment;
use PHPUnit\Framework\TestCase;

final class AuditMetadataFactoryTest extends TestCase
{
    public function testInterfaceDeclarationIsReadAsIs(): void
    {
        $metadata = (new AuditMetadataFactory())->for(new Article('x'));

        self::assertNotNull($metadata);
        self::assertSame('article', $metadata->objectType);
        self::assertSame(['title', 'status', 'publishedAt', 'author', 'tags'], array_keys($metadata->fields));
        self::assertTrue($metadata->isAlwaysRecorded('status'));
        self::assertIsCallable($metadata->fields['author']);
    }

    public function testAttributeDeclarationBuildsRepresentersFromMethodNames(): void
    {
        $metadata = (new AuditMetadataFactory())->for(new Comment('1', 'x'));

        self::assertNotNull($metadata);
        self::assertSame('comment', $metadata->objectType);
        self::assertSame(['body', 'approved', 'author'], array_keys($metadata->fields));
        self::assertNull($metadata->fields['body']);
        self::assertSame('alice', $metadata->fields['author'](new Author('alice')));
        self::assertTrue($metadata->isAlwaysRecorded('approved'));
    }

    public function testAttributesOnAParentClassApplyToSubclassesSuchAsProxies(): void
    {
        $proxy = new class('1', 'x') extends Comment {
        };

        $metadata = (new AuditMetadataFactory())->for($proxy);

        self::assertNotNull($metadata);
        self::assertSame('comment', $metadata->objectType);
        self::assertArrayHasKey('body', $metadata->fields);
    }

    public function testARepresenterNamingAMethodThatDoesNotExistSaysWhereItWasDeclared(): void
    {
        $metadata = (new AuditMetadataFactory())->for(new Misrepresented());

        self::assertNotNull($metadata);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('#[AuditField(represent: "getNope")] on '.Misrepresented::class.'::$author names a method');

        $metadata->fields['author'](new Author('alice'));
    }

    public function testUndeclaredObjectsAreNotAuditable(): void
    {
        $factory = new AuditMetadataFactory();

        self::assertNull($factory->for(new Author('x')));
        self::assertFalse($factory->isAuditable(new \stdClass()));
    }

    public function testAlwaysRecordedMustBeAnAuditedField(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditMetadata('x', ['a' => null], ['b']);
    }

    public function testAttributeTypeMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Auditable('');
    }

    public function testAuditFieldDefaultsToNoRepresenter(): void
    {
        self::assertNull((new AuditField())->represent);
    }

    public function testTheInvariantsHoldWhicheverWayAnEntityDeclaresItself(): void
    {
        // The attribute refused these and the interface did not, though both claim to
        // describe the same thing. AuditMetadata is where both arrive, so it is where the
        // rules belong.
        //
        // This test spent three releases inside the fixture class below, where PHPUnit
        // never ran it — and the half it was guarding was never implemented. Hence the
        // rule now standing in CONTRIBUTING: run a new guard against the fix removed,
        // and see it fail, before believing it guards anything.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty object type');

        new AuditMetadata('', ['title' => null]);
    }

    public function testAnInterfaceDeclarationWithAnEmptyTypeIsRefusedTooAtTheFactory(): void
    {
        $entity = new class implements \Borsche\ElasticsearchAuditBundle\Contract\AuditableInterface {
            public function getAuditObjectType(): string
            {
                return '';
            }

            public function getAuditedFields(): array
            {
                return ['title' => null];
            }

            public function getAlwaysRecordedFields(): array
            {
                return [];
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty object type');

        (new AuditMetadataFactory())->for($entity);
    }

    public function testACollectionThatTracksNoFieldOfItsElements(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tracks no field of its elements');

        new AuditMetadata('shipment', ['lines' => null], [], ['lines' => []]);
    }
}

/**
 * A declaration whose representer names a method the related object does not have.
 * Not an entity: the factory reads attributes, and needs no mapping to do it.
 */
#[Auditable(type: 'misrepresented')]
final class Misrepresented
{
    #[AuditField(represent: 'getNope')]
    public ?Author $author = null;

}
