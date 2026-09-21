<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

/**
 * An enricher that describes the moment rather than the record.
 *
 * An ordinary {@see AuditEnricherInterface} runs when the record is written. For almost
 * every record that is also when the change happened — but not for the one case this
 * whole part of the bundle is built around: a flush whose publishing was swallowed has
 * its records written by the next flush to come along, which is another request,
 * another user and possibly another day. The record's own timestamp and actor are
 * settled where the change was seen and travel with it; an enricher reading the current
 * request cannot, because by the time it runs the current request is somebody else's.
 *
 * So this one is not handed a record and is not asked about one. It is asked, once,
 * what the moment looks like — the route, the request id, the tenant, the console
 * command — and the answer travels with the records of that moment and is applied to
 * each of them:
 *
 *     final class RouteEnricher implements MomentEnricherInterface
 *     {
 *         public function __construct(private readonly RequestStack $requests)
 *         {
 *         }
 *
 *         public function describe(): array
 *         {
 *             $request = $this->requests->getCurrentRequest();
 *
 *             return $request === null ? [] : ['route' => (string) $request->attributes->get('_route')];
 *         }
 *
 *         public function mapping(): array
 *         {
 *             return ['route' => ['type' => 'keyword']];
 *         }
 *     }
 *
 * Three things follow from being about the moment rather than the record:
 *
 * - **it is about every record of that moment.** There is no supports() and no object
 *   type scoping, because there is no record to judge and the moment is the same one
 *   for all of them. A field only some records should carry is what an ordinary
 *   enricher is for;
 * - **what it returns is not overwritten.** An ordinary enricher that sets the same
 *   attribute has its value discarded and the attempt logged — otherwise the enricher
 *   running at write time would put the later request's route back, which is the thing
 *   this interface exists to stop. An attribute the *caller* set on the record is a
 *   different matter and wins: the moment fills in what is missing;
 * - **it must not throw.** There is no record to report a failure against and nothing
 *   to abandon, so a failure is logged and that enricher contributes nothing. A flush
 *   does not lose its history because a route lookup did not work.
 *
 * Implementations are picked up automatically (the interface is autoconfigured), by the
 * same tag as every other enricher.
 */
interface MomentEnricherInterface extends DeclaresAuditFieldsInterface
{
    /**
     * What this moment looks like, as attributes to put on every record of it.
     *
     * Called once per moment — per flush for the Doctrine listener, per record for a
     * record written on its own — and not per record of a batch, so it is the right
     * place for something worth reading out of the request and the wrong place for
     * anything that depends on which record it will end up on.
     *
     * Return [] when there is nothing to say; that is what a console command's request
     * context amounts to, and it is not a failure.
     *
     * **Return values, not something that can change afterwards.** What comes back is
     * kept until the records of this moment are written, which for a flush whose
     * publishing was swallowed is a different request entirely — and an array holds an
     * object by reference, so an object whose fields move between here and there
     * describes neither moment. Read what you need and hand back the reading. Nothing
     * enforces this: the type says `mixed`, because a value may legitimately be a nested
     * array, and there is no way to say "an array of things that cannot change".
     *
     * @return array<string, mixed>
     */
    public function describe(): array;
}
