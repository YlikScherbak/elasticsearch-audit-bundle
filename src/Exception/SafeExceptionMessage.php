<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * "This exception's message was written here, out of names and numbers, and never
 * out of a value the audit trail was carrying."
 *
 * The bundle repeats a cause's message in what it logs, dispatches and raises only
 * when the cause says this about itself. Everything else is named by class, with the
 * cause one getPrevious() away — because an exception from the cluster, from an
 * enricher, from anywhere in the application may quote the very value that was just
 * redacted out of the record, and the bundle cannot tell by looking.
 *
 * It was tried by looking: "no previous exception, therefore the message is ours" is
 * false for every library that throws directly, which is most of them. Trust has to be
 * declared, not inferred from the shape of a chain.
 *
 * **And declared by the bundle, not to it.** An empty public interface is an offer as
 * much as a promise: any class could implement it and have its message repeated
 * wherever a cause's is — past the policy that exists precisely because application
 * code may quote what redaction removed. So implementing it is not enough;
 * `vouchedFor()` also asks whether the class is one of this bundle's own, which is the
 * only code that can honestly make the promise. An application that wants a foreign
 * message repeated says so once, with `redact.failure_details: full`, rather than a
 * class at a time.
 *
 * @internal the marker is the bundle's own; it carries no meaning on a class outside it
 */
interface SafeExceptionMessage
{
}
