# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

On the `0.x` line every minor may change the API; `^0.1` does not pull in `0.2`.

## [Unreleased]

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
