<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Actor;

use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The authenticated user's identifier from the security token, when there is one.
 * Registered automatically when symfony/security-core is installed.
 */
final class SecurityActorResolver implements ActorResolverInterface
{
    /**
     * @param TokenStorageInterface|null $tokenStorage null when the application has no firewall at all
     */
    public function __construct(private readonly ?TokenStorageInterface $tokenStorage)
    {
    }

    public function resolve(): ?string
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        if ($user === null) {
            return null;
        }

        $identifier = $user->getUserIdentifier();

        return $identifier === '' ? null : $identifier;
    }
}
