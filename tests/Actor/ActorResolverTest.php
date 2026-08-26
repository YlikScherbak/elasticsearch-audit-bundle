<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Actor;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Actor\SecurityActorResolver;
use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ActorResolverTest extends TestCase
{
    public function testChainTakesTheFirstAnswer(): void
    {
        $chain = new ChainActorResolver([self::answering(null), self::answering(''), self::answering('42'), self::answering('99')], 'system');

        self::assertSame('42', $chain->resolve());
    }

    public function testChainFallsBackWhenNobodyKnows(): void
    {
        $chain = new ChainActorResolver([self::answering(null)], 'cron');

        self::assertSame('cron', $chain->resolve());
    }

    public function testSecurityResolverUsesTheUserIdentifier(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));

        self::assertSame('alice', (new SecurityActorResolver($storage))->resolve());
    }

    public function testSecurityResolverNamesTheImpersonatingUserNotTheImpersonated(): void
    {
        $admin = new UsernamePasswordToken(new InMemoryUser('admin', null), 'main');
        $storage = new TokenStorage();
        $storage->setToken(new SwitchUserToken(new InMemoryUser('alice', null), 'main', ['ROLE_USER'], $admin));

        self::assertSame('admin', (new SecurityActorResolver($storage))->resolve(), 'who really acted — the impersonated user did nothing');
    }

    public function testSecurityResolverIsSilentWithoutAToken(): void
    {
        self::assertNull((new SecurityActorResolver(new TokenStorage()))->resolve());
    }

    private static function answering(?string $actor): ActorResolverInterface
    {
        return new class($actor) implements ActorResolverInterface {
            public function __construct(private readonly ?string $actor)
            {
            }

            public function resolve(): ?string
            {
                return $this->actor;
            }
        };
    }
}
