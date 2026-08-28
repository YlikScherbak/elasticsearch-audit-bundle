<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Actor;

use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;

/**
 * Asks each resolver in turn and takes the first answer; falls back to a fixed
 * identifier ("system" by default) so a record always says who — or what — did it.
 *
 * @internal the chain behind the actor resolvers; implement ActorResolverInterface to take part in it
 */
final class ChainActorResolver implements ActorResolverInterface
{
    /**
     * @param iterable<ActorResolverInterface> $resolvers
     */
    public function __construct(
        private readonly iterable $resolvers,
        private readonly string $fallback = 'system',
    ) {
    }

    public function resolve(): string
    {
        foreach ($this->resolvers as $resolver) {
            $actor = $resolver->resolve();

            if ($actor !== null && $actor !== '') {
                return $actor;
            }
        }

        return $this->fallback;
    }
}
