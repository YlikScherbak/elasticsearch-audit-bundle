<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Extending;

use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;

/**
 * Who did it, when the security token cannot say.
 *
 * Resolvers are asked in turn and the first answer wins; the bundle's own asks the
 * security token, and `actor.fallback` (`system`) is the last word. Register one of
 * these when the work happens where there is no token — a message handler, a
 * console command, an import — or when the token is not the answer you want.
 *
 * **Returning an internal id rather than an email is a decision worth making
 * early.** By default the actor is `getUserIdentifier()`, which in many
 * applications is an email address — and then every record ever written carries
 * personal data in an indexed field, where redaction cannot reach it: `source` is
 * a base field, and a rule naming it is refused rather than quietly ignored.
 * Changing it later cleans tomorrow's records and leaves last year's untouched.
 */
final class ActingOnBehalfOfResolver implements ActorResolverInterface
{
    private ?string $actor = null;

    /**
     * Set where the work is picked up — a Messenger middleware reading a stamp, a
     * console command reading an option, an import naming the person who uploaded
     * the file. This service is request-scoped in the container sense: one process,
     * one answer at a time.
     */
    public function actingAs(?string $internalId): void
    {
        $this->actor = $internalId;
    }

    /**
     * null means "this resolver does not know", not "nobody" — the chain moves on
     * to the next resolver and finally to the configured fallback. Answering with a
     * made-up string here is how a whole day's records end up filed under the wrong
     * name.
     */
    public function resolve(): ?string
    {
        return $this->actor;
    }
}
