<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

/**
 * Answers "who is doing this?" for records that do not name an actor themselves.
 *
 * The default implementation asks the security token; applications running work
 * in message handlers or console commands, where there is no token, register their
 * own resolver (a request-scoped "acting on behalf of" holder, for example).
 */
interface ActorResolverInterface
{
    /**
     * The identifier to store in the record, or null when this resolver does not know.
     */
    public function resolve(): ?string;
}
