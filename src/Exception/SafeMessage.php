<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Whether a cause's message may be repeated in what the bundle emits — and how to hand
 * one on without the chain behind it.
 *
 * Two things have to hold, and the second one is why this exists rather than a bare
 * `instanceof`: the class declares SafeExceptionMessage, **and** it is one of this
 * bundle's own. A marker interface is public by nature, so on its own it lets any
 * class opt into being trusted — including the enricher whose message the policy was
 * written to keep out of the log. A promise about "sentences we wrote ourselves" can
 * only be made by the code that wrote them.
 *
 * An application that wants foreign messages repeated says so once, in configuration
 * (`redact.failure_details: full`), where it is a decision somebody made rather than a
 * capability a class granted itself.
 *
 * @internal
 */
final class SafeMessage
{
    /**
     * The bundle's own exceptions, named one by one.
     *
     * A namespace prefix was the first shape of this rule and is not a boundary: PHP
     * lets any code declare a class in any namespace, so "starts with ours" is a rule an
     * application can satisfy by choosing a file header. Naming the classes makes the
     * set closed — an exception is safe because this list says so, and a new one is
     * trusted when somebody adds it here on purpose.
     *
     * @var array<class-string, true>
     */
    private const OURS = [
        DeclarationMistake::class => true,
        FailureReason::class => true,
        FrameOverflowException::class => true,
        IndexNotFoundException::class => true,
        NotConfiguredException::class => true,
        PartialResultException::class => true,
    ];

    public static function vouchedFor(\Throwable $e): bool
    {
        return $e instanceof SafeExceptionMessage && isset(self::OURS[$e::class]);
    }

    /**
     * The same refusal, with nothing behind it.
     *
     * For the exceptions that leave a Messenger handler to be *retried*. They keep their
     * class, because that is what a retry strategy reads — but not the cause they were
     * built from: that is usually the client's own exception, whose message is the
     * status line followed by the whole response body, and a document refused for its
     * contents is quoted in there. Retries end eventually, and when they do Symfony
     * flattens the whole chain into an ErrorDetailsStamp and keeps it in the failure
     * transport until somebody removes the message. The permanent path has cut the chain
     * for a while; this is the same cut on the road that is travelled more often.
     */
    public static function withoutTheChain(\Throwable $e): \Throwable
    {
        if ($e->getPrevious() === null) {
            return $e;
        }

        return match (true) {
            $e instanceof TransportUnavailableException => TransportUnavailableException::saying($e->getMessage()),
            $e instanceof IndexNotFoundException => new IndexNotFoundException($e->getMessage(), $e->getCode()),
            // Anything else is not a shape this method knows how to rebuild, and passing
            // it on as it is would be the leak this exists to close.
            default => FailureReason::keepingTheMessageOf($e),
        };
    }
}
