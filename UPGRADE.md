# Upgrading

Every version that asked something of you, in one place. The CHANGELOG says what changed and
why; this says what to do about it, in the order you would meet it walking up from an early
`0.x` to `1.0`.

If you are on the latest `0.x` and everything below already applies to your code, **1.0 needs
nothing** — it is 0.12 with the promises frozen. See "What 1.0 freezes" at the end.

---

## To 1.0.0

Nothing to do. 1.0 changes no behaviour and no signature: it is the point where the surface
listed in the README's "What counts as the public API" starts carrying a stability promise.

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
