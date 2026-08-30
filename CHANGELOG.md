# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

On the `0.x` line every minor may change the API; `^0.1` does not pull in `0.2`.

## [Unreleased]

## [0.8.1] - 2026-08-30

The bundle can be registered in an application.

### Fixed
- **The bundle can be registered in an application at all.** `Bundle::getContainerExtension()`
  refuses an extension whose alias is not the underscored bundle name, and this one's alias is
  vendor-prefixed — `borsche_elasticsearch_audit` — so every kernel boot ended in
  *Users will expect the alias of the default extension of a bundle to be the underscored version
  of the bundle name*, before a single line of application code ran. The bundle hands over its
  extension itself now. The alias, and with it your configuration key, is unchanged
- The suite boots a real kernel with the bundle in it, which is the only place any of this is
  checked: an extension loaded through a `Processor` proves the configuration tree and nothing
  about the bundle around it. That gap is why 0.7.0 and 0.8.0 shipped unbootable

## [0.8.0] - 2026-08-30

The read path is a deployment's to configure, and a page now says whether anything follows it.

### Added
- **`reader.max_limit` and `reader.max_result_window`** — how large a page may be and how deep
  `page(n, limit)` may reach are now configuration, not constants. Both keep Elasticsearch's own
  defaults (1000 and 10 000), and a screen that shows five or ten thousand rows at once raises the
  first; pages beyond the window need the second raised together with the cluster's
  `index.max_result_window`. A cursor is bounded by neither
- UPGRADE: the 0.2 → 0.3 note about pre-0.3 indices was wrong and is corrected. Such an index does
  **not** keep working: with dynamic mapping still on, the first record the bundle writes maps `id`
  as `text`, and every read then fails with *Fielddata is disabled on [id]* —  `unmapped_type`
  applies only to a field that is unmapped. Verified on Elasticsearch 9.1; the note now carries the
  one-line `PUT _mapping` that has to run before the first write
- **A release workflow that tags only after the checks pass.** `ci.yml` became callable, and
  `release.yml` runs it whole as a gate before creating anything; the tag and the GitHub release
  are made inside that run, with the notes read out of `CHANGELOG.md`. It refuses a version that
  is already tagged or has no changelog section, and `dry_run: true` rehearses the lot without
  creating a thing. `git tag` by hand is no longer part of releasing
- The lowest-dependencies job now runs **PHPStan as well as the tests** — that pairing is where an
  annotation that narrows, or a method the oldest supported version does not have, shows up — and
  static analysis validates `composer.json` with `--strict`
- README: how to chunk a decorator's lookups. A decorator receives as many entries as the page
  holds, and an `IN (...)` of ten thousand ids makes MySQL's range optimizer give up and scan the
  table — worth knowing before raising `max_limit`

- **A page now says whether anything follows it and how far page numbers reach.**
  `AuditPage::hasMore()` is arithmetic for a numbered page (`(page-1) * limit + count < total`)
  and a full batch for a cursor one; `maxReachablePage()` is `min(totalPages, window / limit)`,
  which is the difference between the pages that exist and the pages a client may ask for. Both
  are in `toArray()`, so a screen can draw its pager without knowing the settings. Both follow what
  Elasticsearch returned, not what the decorators left: a decorator that hides entries from a page
  changes what is shown, never whether more follows or where the next page starts
- **A cursor as one opaque string.** `AuditPage::nextCursorToken()` and
  `AuditQuery::afterToken()` carry a page boundary across HTTP: base64url, so it survives a query
  string unescaped, and unread by the client, so what is inside it stays the bundle's business.
  A damaged token is an `InvalidQueryException` naming what it is, not a silently wrong page
- `AuditPage` refuses a limit below 1 at construction. The reader cannot produce one —
  `AuditQuery::page()` refuses it first — but a page assembled by hand used to divide by it and
  fail somewhere far from the mistake
- **«Result window is too large» now says where to raise it.** `reader.max_result_window` is
  checked before the request; the index's own window is not, and an index created before the
  setting was raised refuses the page the reader allowed. The exception carries both halves now

### Changed
- **`AuditPage::nextCursor()` returns null once nothing follows**, and
  `toArray()['pagination']['nextCursor']` is the token string rather than the raw sort array.
  A "load more" built on the old behaviour ended on an empty page; see UPGRADE.md
- **The page-size and window checks moved from `AuditQuery` to `AuditReader`**, where the
  configuration lives; the exception now names the setting to raise. `AuditQuery::page()` still
  refuses a page number or size below 1. `AuditQuery::MAX_LIMIT` and `MAX_WINDOW` are now
  `DEFAULT_MAX_LIMIT` and `DEFAULT_MAX_WINDOW` — they are defaults the reader takes, not ceilings
  the query enforces. Extensions are applied before the check, so what runs is what was checked

### Fixed
- **The oldest supported dependencies pass static analysis too**, which is what the new job
  found: the Doctrine listener takes ORM's own event classes (`PostPersistEventArgs` and its
  siblings) instead of the generic persistence one, and asks the event through reflection
  whether this Doctrine can clear a single class — a question ORM 2 answers and ORM 3 does not
  have. The behaviour of both is unchanged
- The suite declares `guzzlehttp/psr7 ^2.4.5` itself. With the `php-http/discovery` Composer
  plugin no longer allowed to install an implementation behind your back, PSR-7 1.9 leaves the
  Elasticsearch transport with *No PSR-17 url factory found* — worth knowing if you pin old
  dependencies: install `guzzlehttp/psr7 ^2` or `nyholm/psr7` yourself

## [0.7.0] - 2026-08-28

The surface is drawn: what an application may call, and what is the bundle's own machinery.

### Changed
- **The public surface is drawn.** Everything that is machinery rather than API is marked
  `@internal` — `FrameBuffer`, `ChangeSetBuilder`, `AuditMetadataFactory` and `AuditMetadata`,
  `QueryBuilder`, `IndexResolver`, `RecordId`, `SystemClock`, `ClientFactory`, both actor
  resolvers, both value-comparator implementations, the two commands, the two Messenger handlers
  and the DI `Configuration`. On `AuditWriter`, `writeCompleted()`, `writeManyCompleted()`,
  `complete()` and `reportFailure()` are seams for the bundle's own frame and Doctrine listener;
  `record()`, `write()` and `writeAll()` are the API. Nothing moved and nothing was removed —
  this says which parts will carry a promise at 1.0, and which may change in any release
- README: a **«What counts as the public API»** section — what to call, what to implement, what
  to declare with, what to route, and what is machinery

### Added
- Unit tests for the `_bulk` and point-in-time requests, scripted through the PSR-18 client
  underneath the real Elasticsearch client: the ndjson body with an action line per document,
  one existence check per distinct index and no `_bulk` when one is missing, refusals by
  position with the value preview cut, and the point-in-time calls carrying the pit in the body
  with no index in the path. Coverage 94.87% → 96.81%, the gateway 62% → 89%

## [0.6.0] - 2026-08-28

A flush is one request, and an export is a frozen view. Writes go out in batches, and
iterate() reads from a point in time instead of a moving index.

### Added
- **Bulk writes.** A flush that produced fifty records used to cost fifty requests in the tail
  of the web request; it is now one `_bulk` call (`sync`) or one message that becomes one `_bulk`
  call in the worker (`messenger`). The same for a frame closing. `AuditWriter::writeAll()` and
  `writeManyCompleted()` are the batch forms of `write()` and `writeCompleted()`
- **`BatchTransportInterface`** — a transport that can carry a batch; `SyncTransport` and
  `MessengerTransport` implement it. A custom transport that only knows `send()` keeps working:
  the writer falls back to one record at a time
- **Per-record failure handling for batches.** Elasticsearch judges each document on its own, so
  a batch can be partly written; `BulkResult` carries the refused positions and their reasons,
  the writer reports each one (log, `RecordFailedEvent`, and with `throw` the first
  `WriteFailedException` — after every failure was reported). In the worker, a refused document is
  raised as Messenger's `UnrecoverableMessageHandlingException` (with the bundle's
  `RequestRejectedException` underneath, naming the refused positions and reasons), so the message
  goes to the failure transport at once instead of around the retry loop — Messenger retries every
  other exception, and a document the mapping refuses would be refused again. An unreachable
  cluster still propagates as `TransportUnavailableException` and is retried; the ids make that
  harmless. The same holds for the single-record `IndexAuditRecordHandler`
- The value preview Elasticsearch appends to a parsing error (`Preview of field's value: '...'`)
  is cut from every reason the bundle reports — in the log, in `RecordFailedEvent`, in
  `WriteFailedException` and in a failed message. A refused value may be a person's data, and
  the error path is not where it should end up
- **`iterate()` reads from a point in time.** A long export sees the index as it was when the
  export started: records written meanwhile do not appear, and none appears twice because a
  segment merged underneath. Opened before the first batch, kept alive by each search
  (`reader.point_in_time_keep_alive`, default `1m`), closed however the export ends — a `break`
  included. `iterate($query, $batchSize, consistent: false)` searches the live index as before
- `GatewayInterface` gains `bulk()`, `openPointInTime()`, `searchPointInTime()` and
  `closePointInTime()`; `QueryBuilder::build()` adds `_shard_doc` as the last sort key inside a
  point in time, the tiebreaker Elasticsearch recommends there

### Changed
- Doctrine records of one flush and the records a frame releases go out as **one batch** instead
  of one request each. Order within the batch is preserved; failures are reported per record as
  before
- **`GatewayInterface` grew four methods** — an implementation of your own has to add them

## [0.5.0] - 2026-08-27

What the trail keeps, and what it must not. Redaction for values that may never be stored,
the documentation a privacy review and an operations team will ask for, and the code held to
PHPStan level 8 with strict rules and a coverage floor.

### Added
- **Redaction** — `redact.fields` names the fields whose values must never be stored (plainly, or
  scoped as `user.email`); they are replaced with `redact.placeholder` at the moment a record
  leaves the writer, keeping the fact that the field changed. A side that was null or empty stays
  as it was, so "had no password, now has one" is still readable. Applied on the way out — after
  the enrichers, after a frame has merged its steps, and on the failure path — so a frame still
  sees the real values and records a password change as the change it is, while neither the
  document, `RecordCreatedEvent`, `RecordFailedEvent` nor `WriteFailedException` carries the
  value. Covers the top-level fields of `changes`; a value inside a free-form array or an
  attribute is the caller's to keep out. `ChangeRedactor::redact()` is the class behind it
- **README: «Audit records and personal data»** — redaction, why the default actor may be an email
  address and how to make it an id, retention through ILM or `delete_by_query`, and erasure
  recipes (`_update_by_query` pseudonymising the actor, since `changes` is not searchable)
- **README: «Index mapping and rotation»** — the ILM policy, template and write-alias recipe;
  `indices.default` may be an alias, which is all rollover needs
- **README: «Performance»** and **«Limitations»** — what each transport costs, where enrichers and
  decorators belong, and an honest list: DQL bypasses the listener, embeddables and inverse
  collections are not tracked, `iterate()` has no point-in-time, frames are per process, a mapping
  is forever
- **`CONTRIBUTING.md`, `SECURITY.md`, `UPGRADE.md`** and issue/PR templates
- A coverage job in CI with a floor (`tools/coverage-floor.php`, also `composer test:coverage`),
  since PHPUnit reports coverage but will not fail on a drop

### Changed
- **Static analysis is PHPStan level 8 with strict rules**, nothing suppressed. Two findings were
  real: arrays inside `changes` are now compared **element by element and strictly**, so `['1']`
  becoming `[1]` counts as the change it is instead of being dropped as coalescing noise; and a
  `#[AuditField(represent: ...)]` naming a method the related object does not have now raises a
  `LogicException` that names the declaration, rather than a PHP error from inside a flush
- An asynchronous Elasticsearch client is refused with `NotConfiguredException` instead of a
  method-not-found error: every call the bundle makes needs its answer

## [0.4.0] - 2026-08-27

One operation, one record. A business operation that saves several times now leaves one
history entry per object, with the values before and after the whole thing.

### Added
- **Coalescing** — `AuditFrame::coalesce(fn () => ...)` (or `begin()`/`end()`) around a business
  operation that saves several times records each object **once**, with the earliest `old` and the
  latest `new` of every field. A field that moved and came back is dropped, and an update in which
  nothing moved is not written: `1000 → 1040 → 1000` leaves no record, `1000 → 1040 → 995` leaves
  `1000 → 995`. A field whose two sides were the same in every step never moved — that is a context
  field, what `#[Auditable(alwaysRecord: ...)]` produces — and it survives coalescing, so a merged
  record reads like the ones written outside a frame. A `create` followed by updates stays one
  `create`; a `remove` is terminal. Frames nest — only the outermost writes. The merged record
  carries the first step's timestamp, actor and id and the last step's attributes; enrichers run
  once per step, when a record enters the frame. `write($record, immediately: true)` bypasses an
  open frame
- **`ValueComparatorInterface`** — the application decides what counts as unchanged; comparators
  are autoconfigured and asked in order. `coalescing.numeric_fields` registers one that treats
  `null`, `''`, `'-'` and `0` as the same value for the listed fields — named as `quantity` for
  every object type or `stock.quantity` for one. A value that is neither a number nor "nothing"
  makes it defer to the strict comparison, so two different words never look equal
- **`AuditWriter::writeCompleted()`** — writes a record that already went through the completion
  pass; what `AuditFrame` releases when it closes, and what keeps enrichers from running twice
- **`FrameResetMiddleware`** (Messenger) — closes a frame a handler left open so one buggy handler
  cannot swallow the next message's history, and **writes** what it held (`AuditFrame::release()`),
  because a record reaches the frame only after the save behind it went through. `reset()` remains
  for the case where the records must not exist
- Configuration: `coalescing.enabled` (`false` keeps `AuditFrame` injectable and working — the
  buffer simply holds nothing, so turning the feature off is a config change and not a
  refactoring), `object_types` (types held while a frame is open; empty means all),
  `numeric_fields`, `max_held` (a frame holding more objects releases what it has)

### Changed
- `RecordCreatedEvent` fires for the coalesced record when a frame is open, not for every step
- With `on_failure: throw` a write that fails inside a frame surfaces from `end()` / `coalesce()`,
  not from the `flush()` that produced it

### Fixed
- **Record ids wasted entropy** (since 0.3.0): `RecordId::v7()` used the same two random bits for
  the UUID variant and for `rand_b`, and left eight other random bits unused — 60 random bits
  where the format allows 62, with two of them correlated. The layout now takes each bit from one
  place only. Ids already written are unaffected: they are valid version 7 UUIDs, unique in
  practice, and nothing needs reindexing
- **A partial `clear()` no longer discards records of a flush that is still running.** On ORM 2,
  `$em->clear(SomeClass::class)` clears one class while the rest of the flush commits; the
  listener dropped everything it had collected, losing history that did happen. It now keeps
  those records — unless the manager is closed, which means the flush failed and its records
  describe a state the database never reached. (ORM 3 has no partial clear; a failed flush still
  drops everything, as `close()` performs a full clear.)

## [0.3.0] - 2026-08-26

The history can be read back. Together with 0.1 and 0.2 this is the complete write → read
loop; what follows are refinements.

### Added
- **`AuditQuery`** — an immutable query: `for(objectType)` / `any()`, `withObjectIds()`,
  `withEvents()`, `withActors()`, `withIds()`, `between()` / `since()` / `until()`, `where()` /
  `whereIn()` on enricher attributes, `oldestFirst()` / `newestFirst()`, `page(n, limit)` or
  `after(cursor)`. Options (`withOption()`) carry application parameters for extensions.
  Invalid input — empty lists, a limit over 1000, a page past Elasticsearch's 10 000-row window,
  a base field passed to `where()` — is an `InvalidQueryException` before anything is sent
- **`AuditReader`** — `find(AuditQuery): AuditPage` and `iterate(AuditQuery, batchSize)`, a
  generator that follows the cursor through every matching entry (exports, backfills). Reads the
  index the object type routes to. Does not swallow failures: an unreachable cluster or a missing
  index is an exception, unlike the writer
- **`AuditEntry` / `AuditPage`** — typed read models with `toArray()` for JSON endpoints;
  `AuditPage::nextCursor()` feeds `AuditQuery::after()`
- **`QueryExtensionInterface`** — the application rewrites the query (`country → withActors(...)`,
  visibility rules) in terms of `AuditQuery`, never Elasticsearch. Autoconfigured, runs on every read
- **`RecordDecoratorInterface`** — attaches display data to a whole page at once (names for ids);
  stored nowhere, computed on read. Autoconfigured
- **`QueryBuilder`** — every condition is a `bool.filter` clause (no scoring); sort is `loggedAt`
  plus the record id as tiebreaker (`unmapped_type: keyword`, so an index from 0.1/0.2 without
  the field still reads); `track_total_hits` so the total is exact past 10 000
- **Record ids** — every record gets a UUID v7 built from its timestamp (`AuditRecord::$id`,
  `withId()`, `RecordId::v7()`), stored as `id` (keyword) and used as the document `_id`. Time-ordered,
  so it is a stable cursor tiebreaker (`_doc` moves when segments merge); known before the write,
  so a Messenger redelivery after a timeout overwrites its own document instead of duplicating it
- **`RequestRejectedException`** — Elasticsearch answered with a 4xx other than 404: a document that
  does not fit the mapping, missing permissions, a rate limit. Previously reported as
  `TransportUnavailableException` ("unreachable"), which sent people looking at the network
- `audit:check` compares the *type* of every expected field with the live mapping, not just its
  presence — `loggedAt is text, expected date` is what an index Elasticsearch created on its own
  looks like — and reports a failing index without giving up on the others
- Index names in `indices.default` / `indices.routing` are validated at compile time against
  Elasticsearch's rules (lowercase, no wildcards or special characters, not starting with `-_+`)

### Changed
- **A write to a missing index is refused** (`IndexNotFoundException`, subject to `on_failure`)
  instead of letting Elasticsearch auto-create the index with a guessed mapping — `loggedAt` as
  `text` (every read then fails with 400), `changes` indexed field by field (mapping growth and
  rejected documents on type conflicts), enricher attributes as `text` (`term` filters miss).
  Verified on ES 9.1. The gateway checks existence once per index per process (one `HEAD`) and
  forgets the answer when a write comes back 404. An index dropped under a running worker is the
  one case that check cannot see; the README shows how to close it on the cluster
  (`action.auto_create_index: "-audit_*,+*"`)
- **`AuditQuery::any()` searches every routed index at once**, not only the default one, so a
  type living in its own index is part of "everything"
- **`id` is now a reserved document field**: an enricher attribute named `id` is refused by
  `AuditRecord::withAttributes()` — rename it
- **The index mapping is `dynamic: false`**: a field no enricher declared is stored but not
  indexed, rather than mapped by guess. Declare it in `mapping()` — `audit:check` tells you when
  you did not. Existing indices are not changed; recreate or update their mapping to opt in
- **Doctrine records are written after the commit.** The listener collects records in
  `postPersist`/`postUpdate`/`postRemove` and sends them in `postFlush`; `onClear` drops what a
  failed flush collected. A rolled-back flush no longer leaves phantom history. Inside an outer
  transaction (`wrapInTransaction`) records still go out when the inner `flush()` ends. With
  `on_failure: throw` the exception now surfaces from `flush()` after the commit. The listener
  is registered for `postFlush` and `onClear` in addition to the four lifecycle events
- **A mistake in an audit declaration no longer aborts the flush** with the default policy: the
  listener hands build errors (unknown `alwaysRecord` field, association without a representer,
  an identifier it cannot represent) to `AuditWriter::reportFailure()` — logged under `log`,
  `WriteFailedException` under `throw`. `WriteFailedException::$record` is therefore nullable
- **`SecurityActorResolver` names the impersonating user** under `switch_user`
  (`SwitchUserToken::getOriginalToken()`), not the impersonated account
- `TransportInterface::send()` and `IndexAuditRecord` gained a third parameter, the document id.
  Custom transports must accept it (`?string $id = null`); messages queued by 0.2 are still handled
- `AuditQuery::where()` / `whereIn()` / `withOption()` replace an earlier value for the same key
  instead of silently keeping it, so a `QueryExtension` can narrow a filter the caller set
- A 400 from Elasticsearch on a search (a stale cursor, an unmapped sort) is an
  `InvalidQueryException` carrying Elasticsearch's reason, not "unreachable"
- `AuditReader::iterate()` takes the cursor and the stop condition from the hits Elasticsearch
  returned, so a decorator that drops entries cannot end an export early
- An entity whose identifier includes an association (a join entity with payload) is audited;
  the associated entity is represented by its own identifier
- `Change` stores a backed enum by its value and a pure enum by its name; `json_encode` would
  have refused the latter and the record would have been lost
- `id` joined the reserved document fields: it cannot be used as an attribute or a `where()` name

## [0.2.0] - 2026-08-26

Entities audit themselves. Declare what to record and every `flush()` writes the history.

### Added
- **Doctrine integration** — a listener on `postPersist`, `postUpdate`, `preRemove`, `postRemove`
  records `create` / `update` / `remove` for declared entities. The remove is built in `preRemove`,
  while the entity still has its identifier, and written in `postRemove`. Enabled when
  `doctrine/orm` is installed; `doctrine.enabled: false` turns it off, `doctrine.connection`
  picks the connection
- **Two ways to declare**: `AuditableInterface` (`getAuditObjectType()`, `getAuditedFields()`
  with closures for associations, `getAlwaysRecordedFields()`) or the attributes `#[Auditable(type,
  alwaysRecord)]` and `#[AuditField(represent)]`. Both reduce to the same metadata; attributes are
  found on parent classes too, so Doctrine proxies work
- **`ChangeSetBuilder`** — scalars from the unit-of-work change set; to-one associations through
  their representer (old from the change set, new from the entity); to-many as the represented
  snapshot against the current contents, loading a lazy collection first so the old side is real;
  `alwaysRecord` fields as `old == new`. Two dates for the same instant are not a change, and
  neither is Doctrine's `null → null` on insert
- **`skip_empty_updates`** (default `true`) — an update touching no audited field records nothing.
  Always-recorded fields give context to a change but do not count as one
- **PSR-14 events** — `RecordCreatedEvent` (replace the record or `veto()` it before it is sent)
  and `RecordFailedEvent` (every failed write, whatever the policy). Dispatched when an event
  dispatcher is available
- Identifiers may be ints, strings, `Stringable` (Uuid, Ulid) or backed enums; composite keys
  join with `|`

### Changed
- Dates inside `changes` are now written in **UTC**, like `loggedAt`, instead of in their own
  timezone — the two were inconsistent in 0.1
- `psr/event-dispatcher` is a hard dependency (it is an interface package with no code)

## [0.1.0] - 2026-08-26

The write path. Nothing reads yet, nothing watches Doctrine yet — this release is the
core every later one builds on, released early so the document layout and the extension
points can settle before the Doctrine integration lands on top of them.

### Added
- **`AuditWriter`** — `record(objectType, objectId, event, changes, attributes, at, actor)` for
  domain actions, `write(AuditRecord, immediately)` for a record you built yourself. Fills in the
  UTC timestamp and the actor when the caller leaves them out
- **`AuditRecord` / `Change` / `AuditEvent`** — an immutable record whose `toDocument()` is the
  Elasticsearch body: `objectType`, `objectId`, `event`, `loggedAt` (UTC, `yyyy-MM-dd HH:mm:ss`),
  `source` (the actor), `changes` (`{old, new}` pairs or free-form data) plus top-level attributes.
  Attributes cannot shadow the base fields
- **Transports** — `sync` writes in the request; `messenger` dispatches an `IndexAuditRecord`
  carrying plain arrays and `IndexAuditRecordHandler` writes it from the worker.
  `write($record, immediately: true)` bypasses the queue for the one record that must be visible now
- **`AuditEnricherInterface`** — the application adds the denormalised, filterable attributes
  only it knows about, together with their mapping; implementations are autoconfigured
- **`ActorResolverInterface`** — resolvers are asked in turn; the security token's user identifier
  comes first when `symfony/security-core` is installed, then `actor.fallback` (`system`)
- **Failure policy** — `on_failure: log` (default) logs a failed write and carries on, so the audit
  log can never take the business operation down; `on_failure: throw` raises `WriteFailedException`
- **Index routing** — `indices.default` plus `indices.routing` per object type, so a chatty type
  gets its own index without the application knowing about indices
- **`audit:index:create`** — creates every routed index with the base mapping and the enrichers'
  fields; existing indices are left untouched; `--dump` prints the definition instead.
  `objectId` is mapped as `keyword` by default (`indices.object_id_type: integer` when every
  audited type has numeric ids); `changes` is stored but not indexed (`enabled: false`)
- **`audit:check`** — cluster reachable, every index present, every base and enricher field mapped
- **Typed exceptions** under one `AuditException` marker: `NotConfiguredException`,
  `IndexNotFoundException`, `TransportUnavailableException`, `WriteFailedException`
- **`GatewayInterface`** — the six Elasticsearch calls the bundle needs, so the 8.x and 9.x clients
  are supported by the same code and tests run against an in-memory gateway
- Elasticsearch 8 and 9, PHP 8.1–8.4, Symfony 6.4 / 7 / 8, verified in CI against live clusters
