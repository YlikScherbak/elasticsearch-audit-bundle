# Upgrading

Every version that asked something of you, in one place. The CHANGELOG says what changed and
why; this says what to do about it, in the order you would meet it walking up from an early
`0.x` to `1.0`.

If you are on the latest `0.x` and everything below already applies to your code, **1.0 needs
nothing** — it is 0.12 with the promises frozen. See "What 1.0 freezes" at the end.

---

## To 1.0.0

A pre-release audit found things that had to be right before the surface froze, so 1.0 is not
quite "0.12 with a promise". These can ask something of you:

**The Elasticsearch client floor is 8.18** (`^8.18 || ^9.0`), **and so is the cluster's**. Writes
are sent with `include_source_on_error=false`, asking the cluster to keep the refused document out
of the error it returns; the parameter does not exist before 8.18, and an unknown query parameter
is a 400 the bundle reads as a permanent refusal — on an older cluster every audit record would be
dropped. If you pinned `elasticsearch/elasticsearch:^8.0`, move the pin to `^8.18`, and check the
cluster version too: this is the one requirement here that the client alone cannot satisfy.

**An `#[AuditField]` on an embedded property now fails at the first flush.** It never recorded
anything — Doctrine reports an embeddable's columns as `address.city`, never as `address` — so
this turns silence into a message naming the fields that do exist. Audit those names instead. The
same check refuses a property Doctrine maps as neither a field nor an association.

**`whereBetween()` refuses a crossed range**, the way `between()` already did for dates. If you
built ranges from user input without ordering the bounds, an impossible one used to come back as
an empty page and is now an `InvalidQueryException`.

**The Elasticsearch client's `debug` output no longer reaches your log.** It carried the request
and response bodies — that is, the audited document — so on any environment running at debug the
values redaction removes were in the log anyway. If you were reading those lines to debug a
request, use the cluster's own slow log or a proxy; the bundle will not carry a payload into a
log again. The `info` lines stay, minus the PSR-7 objects their context used to carry.

**Cursor tokens issued before 1.0 cannot be continued.** Every cursor read outside a consistent
`iterate()` now sorts by the index name as well, so a token carries three sort values where it
used to carry two. A token from an older version is refused with an `InvalidQueryException` saying
to start from the first page — a page number is unaffected, and a token lives as long as somebody
keeps clicking "next", so in practice this is visible only across a deploy.

**`AuditReader::find()`, `iterate()` and `raw()` now raise `PartialResultException`** when
Elasticsearch answers with part of a result (a failed shard, or a search that ran out of time).
Previously that answer was returned as though it were complete, and an export took its next
cursor from the last hit of a short batch. If you would rather show what there is, catch the
exception at the call site — but do not do it in an export.

**`doctrine.enabled: true` now also requires doctrine/doctrine-bundle.** The entity listener is
attached through DoctrineBundle's `doctrine.event_listener` tag; with doctrine/orm alone it was
built and never attached, and auditing silently did nothing. If your application has the ORM
without DoctrineBundle, install and register it — or set `enabled: false` (or `auto`, which
stays quiet) if you did not want entity auditing after all.

**An entity declaring `getAuditObjectType(): ''` now fails instead of writing records with an
empty type.** If any declaration returned an empty string, it was producing history nobody could
filter; give it a name.

**`WriteFailedException::getMessage()` no longer repeats the cause's message** when the cause
wrapped a foreign exception (the cluster's, a listener's) — the detail is in `getPrevious()`.
Code matching on the text of the outer message should read the previous exception instead. A
declaration mistake still reads in full.

**`redact: ['source']` is now refused at boot.** It never did anything: the actor is chosen when
the record is built. Remove the rule, and return the identifier you want from an
`ActorResolverInterface`.

**A failed write no longer repeats the cause's message** in the log line or in
`RecordFailedEvent` when redaction is configured — the cause is named by class, and the original
is reachable through `WriteFailedException::getPrevious()`. Set `redact.failure_details: full`
to keep the old behaviour, or `cause` to have it whether or not you redact anything. Code that
matched on the text of a logged message or of `$event->reason->getMessage()` should read the
previous exception instead.

**`transport: messenger` now also requires FrameworkBundle** (or your own wiring of the handlers
to a bus). The Messenger component alone leaves `messenger.message_handler` collected by nobody:
the container booted, the handlers existed, and every dispatched record failed in a worker.

**An audited inverse collection now records membership without `trackElements`.** If you audited
such a field and relied on it recording nothing until element tracking was switched on, you will
start seeing `documents.42`-style entries. That is what the field was always documented to do.

**`reader.max_limit` may not exceed `reader.max_result_window`** — a page that size could never
be read, and the pair is refused at boot rather than at the first deep query.

**`AuditReader::raw()` now refuses a body it cannot vouch for.** A `global` aggregation, a
top-level `knn`, `runtime_mappings` (a runtime field can shadow the very field a visibility rule
filters on), an unknown top-level key, or a `size`/`from` past the reader's limits are
rejected with a message naming what and why. If you were relying on any of those, the query's
visibility rules were not applying to them — which is the reason for the change. Aggregate inside
the query, or go to the cluster directly and take on the boundary yourself.

**A custom `GatewayInterface` or `BatchTransportInterface` implementation gets `id` as a
non-nullable string** in every bulk item, and `bulk()` refuses an item without one.

**New indices are created with one replica.** Existing indices are untouched. On a single-node
development cluster set `indices.settings.number_of_replicas: 0`, or the index stays yellow.

Three more are fixes you do not have to act on, but should know about: a tracked element whose
identifier contains a dot is now escaped in the flattened key (ids without dots are unchanged);
a redaction rule containing a dot is read strictly as `objectType.field` — if you were relying on
`shipment.lines` also hiding `shipment.lines.*` on *other* object types, name those separately;
and the frame's internal identity escapes `|`, so two objects whose type and id spelled the same
joined string are no longer merged.

**Worth doing while you are here:** if `action.auto_create_index` on your cluster does not
exclude the audit indices, add it (see the README). The bundle's existence check gives a good
error, but only the cluster can make a guessed mapping impossible — an index dropped or rolled
over between the check and the write is a window no client can close.

## To 0.12.0 — the shape of a query

**`AuditQuery::$filters` holds `Filter` objects.** They used to be bare scalars or lists.

```php
// before — reading the map directly to build an intersection
$current = $query->filters['orderCountry'] ?? null;
$allowed = is_array($current) ? array_intersect($current, $visible) : $visible;
$query = $query->whereIn('orderCountry', $allowed ?: ['-']);

// after — the intersection is the API
$query = $query->narrowIn('orderCountry', $visible);
```

A `Filter` carries `kind` (a `FilterKind` enum), `value`, `values`, `from`, `to`. Most code that
read the map was building an intersection by hand; `narrowIn()`, `narrowObjectIds()` and
`narrowActors()` do that, and answer an empty intersection with `matchNothing()` — an empty page
and no request, instead of a made-up id typed to fit the field's mapping.

**Visibility rules in a `QueryExtension` should move from `with*()` to `narrow*()`.** Not
required, but `with*()` replaces: a rule written as `withObjectIds(...$visible)` throws away what
the client asked for and *widens* the result. This is the mistake the family exists to prevent.

**A custom `GatewayInterface` implementation must add `putMapping()` and `settings()`.** If you
do not implement the interface yourself, nothing to do.

**A decorator's `extra` now outranks a stored attribute in `AuditEntry::toArray()`.** If you
relied on the stored value winning over an `extra` of the same name, rename the extra key.
`toDocument()` is unchanged and still never sees `extra`.

## To 0.11.0 — declarations that mean what they say

**`doctrine.enabled: true` without doctrine/orm now fails the boot.** The default is `auto`
(listen when the ORM is installed, stay quiet when not) — which is what the old default did.
Remove the explicit `true`, or install doctrine/orm to get what the line was asking for.

**A redaction rule naming a tracked collection covers its element keys.** `redact: [lines]` now
also hides `lines.42` and `lines.42.quantity`. If you wanted only the collection's own change
hidden, name the fields instead of the collection.

**`audit:check` is stricter** — it compares mapping options and nested fields, so an index that
passed before may now report drift. That drift was always there; run `audit:index:sync` for
missing fields, reindex for a changed type.

## To 0.10.0 — cursors and identifiers

**Cursors issued before the upgrade are refused** when a query spans indices (`any()`): the sort
gained `_index`, so an old token carries two values where three are expected and Elasticsearch
rejects it outright. Nothing is lost — clients start from the first page. A cursor within one
object type is unaffected.

**`iterate()` refuses a query carrying a page or a cursor** instead of silently starting over.
Pass an unpaged query; to resume where an export stopped, narrow it (by `since()`, say).

**Composite `objectId` values are encoded differently.** Parts are joined with `|` as before,
but `|` and `\` inside a part are now escaped, so `["a|b", "c"]` and `["a", "b|c"]` are no longer
the same id. Histories of composite-key entities written before the upgrade are addressed by the
old form; the CHANGELOG entry says how to find them.

**A `trackElements` declaration the listener cannot serve is now an error** (through the failure
policy) rather than a silent no-op: a `ManyToMany`, the owning side of a collection, or a field
that is not an association at all. If one is reported, the tracking it promised was never
happening.

**`whereIn()` refuses a non-scalar value** at the boundary instead of letting Elasticsearch
report it a round trip later.

## To 0.9.0 — the reader's settings

`AuditQuery`'s page-size and window constants became reader settings (`reader.max_limit`,
`reader.max_result_window`) in 0.8. If you were reading the constants, read the configuration
instead; `AuditQuery::DEFAULT_MAX_LIMIT` and `DEFAULT_MAX_WINDOW` remain as the defaults.

`ValueComparator` implements `ValueComparatorInterface`, and a comparator registered as a
service is asked in order — the first opinion wins, `null` defers.

## Before that

`0.2` through `0.8` were pre-integration releases; every change is in the CHANGELOG, and no
production installation predates them.

---

## What 1.0 freezes

From 1.0 the surface listed under **"What counts as the public API"** in the README carries a
stability promise: it does not change in a way that breaks you within the `1.x` line.

Everything marked `@internal` is not part of it — `IndexResolver`, `QueryBuilder`,
`ChangeSetBuilder`, `AuditSubscriber`, the commands, `EnricherMapping`, `MappingComparison` and
the rest. They may change in any release; if your code touches one, that is worth knowing now.

Known limitations that 1.0 freezes as limitations, not bugs:

- **The transaction boundary** is the flush's own. An outer transaction rolling back leaves the
  records written — see "The transaction boundary, exactly" in the README for the recipe that
  closes the gap, and for why a transactional outbox is post-1.0 work.
- **`raw()` over a query that matches nothing** answers hits and no `aggregations` key; read
  aggregations with `?? []`.
- **Hydration is lenient**: a corrupt `loggedAt` reads as the epoch rather than failing the page.
