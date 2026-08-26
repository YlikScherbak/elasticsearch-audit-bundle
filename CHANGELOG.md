# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

On the `0.x` line every minor may change the API; `^0.1` does not pull in `0.2`.

## [Unreleased]

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
