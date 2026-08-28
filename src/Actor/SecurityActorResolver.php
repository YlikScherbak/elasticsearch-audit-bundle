<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Actor;

use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * The authenticated user's identifier from the security token, when there is one.
 * Registered automatically when symfony/security-core is installed.
 *
 * Under switch_user the token belongs to the impersonated user, but the person
 * acting is the one who switched — that is who the record names. An audit trail
 * that attributed an administrator's actions to the account they were looking at
 * would be worse than none.
 *
 * @internal registered when symfony/security-core is installed
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
        $token = $this->tokenStorage?->getToken();

        while ($token instanceof SwitchUserToken) {
            $token = $token->getOriginalToken();
        }

        $user = $token?->getUser();

        if ($user === null) {
            return null;
        }

        $identifier = $user->getUserIdentifier();

        return $identifier === '' ? null : $identifier;
    }
}
