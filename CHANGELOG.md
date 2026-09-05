# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Since 1.0 the public API (see the README) is stable within `1.x`; coming from `0.x`, read
[UPGRADE.md](UPGRADE.md). On the `0.x` line every minor could change the API.

## [Unreleased]

## [1.0.0] - 2026-09-05

The promises stop moving.

1.0 changes no behaviour and no signature: it is 0.12 with the surface frozen. Everything listed
under "What counts as the public API" in the README is stable within `1.x`; everything marked
`@internal` is not, and may change in any release. [UPGRADE.md](UPGRADE.md) collects every step
from `0.x` in one page — from the latest `0.x` there is nothing to do.

What the line arrives with: Doctrine entities audited from their change sets, arbitrary domain
actions recorded on demand, an operation's many saves coalesced into one record per object,
synchronous or Messenger-borne writes that batch, redaction that covers changes and attributes
on every path out, a read API with cursors that do not skip records, extensions that can only
narrow what a viewer sees, and a mapping the bundle creates, checks and can extend. Verified on
PHP 8.1–8.4, Symfony 6.4/7/8 and Elasticsearch 8 and 9, against live clusters, at both ends of
the dependency range.

### Fixed
- **The failure path was outside the privacy boundary.** The record was redacted and then the
  cause's message went into the log line, the exception itself into the PSR-3 context, and the
  raw `Throwable` into `RecordFailedEvent` — where a listener the docblock itself suggests
  writing reads it. A cluster refusing a document quotes the field it refused; an enricher
  quotes what it was enriching from. Now the bundle repeats a cause's message only when the
  cause declares it safe (`SafeExceptionMessage`, which its own declaration errors do), and the
  original stays reachable through `WriteFailedException::getPrevious()`. The earlier rule —
  "it wrapped nothing, so we wrote it" — was a guess, and false for every library that throws
  directly. `redact.failure_details` sets it explicitly; unset, it follows `redact.fields`
- **A redaction rule that names no field is refused**, like a rule naming a base field already
  was. `''` out of a config list, or `'user.'` from a scope somebody meant to finish, was accepted
  and then matched nothing — the same silence, arrived at by a likelier accident
- **The two log lines in `reportFailure()` go through `failure_details` like every other cause.**
  A listener of `RecordFailedEvent` is application code with an enricher's trust level — it was
  handed a record and may have read anything to build its reply — and its exception was logged
  with its message and the object itself, past the policy governing everything else on that path
- **Whitespace around a number is not a change.** `is_numeric(' 12')` is true, so `' 12'` against
  `'12'` was recorded as a quantity that moved from 12 to 12
- **`bulk()` checks its ids before it asks the cluster anything.** A document without an id is a
  mistake in the caller and does not depend on what any HEAD request answers
- **`indexExists()` answers false only when the cluster said 404.** The client suppresses its own
  exception for `HEAD`, and the check was a plain "2xx", so a role without `view_index_metadata`
  (403), a name Elasticsearch will not take (400) and an unhealthy cluster (5xx) all came back as
  "the index does not exist" — and `audit:check` sent the operator off to create an index that was
  already there, during an outage. The status is read now, and anything else is classified the way
  every other answer is; `GatewayInterface`'s `@throws` on the method stops being unreachable
- **Auditing an embedded property is refused instead of silently recording nothing.** Doctrine
  stores an embeddable as columns of its owner and reports them as `address.city`, never as
  `address`, so `#[AuditField]` on the embedded property matched nothing every time and said so
  nowhere. It is now a declaration mistake, and the message names the fields that do exist. The
  same check catches a property Doctrine maps as neither a field nor an association
- **A crossed range is refused on an attribute, as it already was on dates.**
  `whereBetween('total', 500, 100)` sent an impossible range and answered with an empty page —
  which reads as "nothing happened", the one answer an audit query must never give by mistake.
  Bounds of different kinds (a number against a string) are still left to Elasticsearch, since a
  keyword field orders its values as text
- **`redact.failure_details: ~` is accepted.** `enumNode()->defaultNull()` works only because
  defaults are inserted without being finalized, so the value the documentation calls the default
  was the one spelling a user could not write — including the one `config:dump-reference` prints
- **Released records whose write fails no longer strand their finalize failures.** `AuditFrame`
  drained the buffer even when the write threw; the writer's own release path did not, so under
  `on_failure: throw` a comparator failure stayed behind — to surface inside the next operation as
  an event about a record it never wrote, or to be erased by a `reset()` that cannot know better
- **The Elasticsearch client no longer writes the audited document into the application log.**
  `elastic/transport` logs `Headers: … Body: …` at `debug` for the request *and* the response, so
  an environment running at debug — every dev machine, and more production ones than anyone
  admits — put the whole `changes` payload, redacted values included, into the log once per write,
  and a rejected document came back quoted. The wrapper in front of the client only blanked
  passwords in URLs, which is what its name said and all it did. It is now `ClientLogGate`: it
  passes nothing at debug, drops the PSR-7 request and response objects the info lines carry in
  their context (a formatter that serialises context reaches the body through them), and keeps
  method, URL, status and retry count
- **A safe exception no longer smuggles an unsafe one along with it.** `SafeExceptionMessage` is a
  promise about a *message*, and the failure path read it as a promise about the whole object:
  `IndexNotFoundException` names the index in the bundle's own words and carries the cluster's
  exception as `previous`, which is exactly what a log processor walks. Under `failure_details:
  cause` a safe cause with a chain is now repeated as its message without the chain, and
  `FailureReason` carries `causeClass`, so a listener can still tell a missing index from a refused
  document without reading any message
- **A flush somebody else aborted no longer silences every flush after it.** `UnitOfWork::commit()`
  dispatches onFlush, *then* opens the transaction, and only then enters the try whose catch closes
  the manager — so a listener behind this one that throws in onFlush (a validation veto, the usual
  reason) leaves no onClear, no postFlush and an open manager. The nesting counter then read every
  later flush as nested and published nothing: the trail went silent for the rest of the process,
  with nothing anywhere saying why. Nesting is now decided by the transaction level each flush
  started at — an inner flush always starts deeper — so a flush that starts no deeper proves the
  one above it is gone, drops what it had collected (rows the database never took) and says so
- **`FrameResetMiddleware` no longer cuts an open frame on a synchronously routed message.** The
  `ReceivedStamp` guard was meant to tell a consumed message from a dispatch, and it cannot:
  `SyncTransport::send()` re-dispatches through the bus with a stamp of its own. Routing
  `IndexAuditRecords` to `sync://`, or dispatching any domain message from inside `coalesce()`,
  therefore released somebody else's frame mid-operation — phantom intermediate records, and a
  warning blaming a try/finally nobody omitted. A consume that starts with a frame already open
  now leaves it alone
- **A cursor could step over a record whenever the route was an alias.** The index name joined the
  sort tuple only for `any()`, on the reasoning that naming an object type means reading one index.
  It means reading one *route*, and an append-only trail rolls over, so the alias spans the series:
  two records sharing a timestamp and an id — which they can, an application may choose its own —
  sat in different indices and `search_after` skipped one. Every non-consistent read now sorts by
  `_index`, and a token issued for a different sort is refused by the reader with what to do about
  it instead of by the cluster with a 400
- **`raw()` no longer accepts `terminate_after`, and a search that stopped early is a partial
  answer.** The parameter reads the query like any other and was allowed for that reason; what it
  does is make the answer incomplete by construction, which is the one thing this reader promises
  not to return. `terminated_early` in a response now raises `PartialResultException` however it
  got there — an index-level setting can do it too
- **A body given to `raw()` is checked before visibility decides there is nothing to read.**
  `from: -1` threw for a viewer who may see records and passed in silence for one who may not: a
  malformed body is the caller's mistake whoever is looking
- **Lenient hydration covered `loggedAt` and nothing else.** An array where `objectType`, `event`,
  `source` or `objectId` should be — the mangled-reindex document the policy was written for —
  raised "Array to string conversion", and an error handler that promotes warnings (Symfony's, in
  debug and in plenty of production setups) turned that into the one-bad-document-kills-the-page
  exception the policy exists to prevent. Anything that is not a scalar now reads as empty, and an
  unreadable actor as `null`
- **A partial answer is no longer served as if it were the whole one.** Elasticsearch replies with
  what it has when a shard fails or a search runs out of time, and says so in `_shards.failed` and
  `timed_out` — which nobody reads. For a search screen that is the right trade; for an audit trail
  "these are the records" would simply be false. Worst on `iterate()`, which took its next cursor
  from the last hit of a short batch, so everything the failed shard held before that position was
  never read and the export finished looking complete. `find()`, `iterate()` and `raw()` now raise
  `PartialResultException` instead, `iterate()` before the cursor moves
- **`raw()` allows the aggregations it knows rather than refusing the ones it remembered.** The
  boundary is a filter, and an aggregation that steps outside the query — `global`,
  `significant_terms`, `significant_text`, `children`, `parent` — reads documents the filter never
  saw. Naming them one by one meant every aggregation Elasticsearch adds next is allowed by
  default; the list now says which aggregations are inside the boundary, and an unknown name is
  refused
- **`raw()` could be handed a body that escapes its own boundary through `runtime_mappings`.**
  A runtime field may carry the name of a mapped one and shadows it for the whole query, so a
  body could define `source` as a script emitting the value the boundary filters on — and the
  filter would be true of every document. The key is no longer allowed
- **`raw()` counted paging differently from Elasticsearch.** `from` was turned back into a page
  number and multiplied: from 9999 with size 2 reaches row 10001 and passed as "page 5000 × 2".
  A body with no size was validated as one row where the cluster reads ten. Now it is `from +
  size`, and a body carrying both `from` and `search_after` is refused
- **An unreadable bulk item was classified as a permanent refusal.** "Whether these documents
  were written is unknown" was recorded as a failure with status 0, and 0 is in no retry list —
  so the batch went to the failure transport. An unknown outcome now fails the whole response as
  a transport failure, which re-sends it: safe, because every document carries its id
- **A per-item 500 was permanent while a single 500 was retried.** Every 5xx is transient now;
  the same refusal cannot mean two things depending on how many records a flush produced
- **A record that could not be prepared no longer takes its batch down under `on_failure:
  throw`** — the completion loop in `writeAll()` had the same hole the batching loop did
- **The one-by-one transport path keeps the `throw` guarantee.** It stopped at the first failure,
  so the rest of a flush was never attempted — and when a closing frame had just drained into
  it, those records were already out of the buffer
- **A single write reports the record that was actually sent**, not the one that came in:
  `prepare()` may replace it entirely, and the failure event named an object type and attributes
  that never went anywhere
- **A replacement record without an id cannot reach the transport.** A listener may hand back a
  new record on `RecordCreatedEvent`; without an id a redelivery stores it again under a
  generated one. The batch path refused it already; the single path passed `null` through
- **`AuditFrame::release()` drains comparator failures when the write itself fails**, like
  `end()` already did — otherwise they surfaced inside the next operation, as a failure event
  about a record that operation never wrote. Failures from early releases (a remove, a full
  buffer) are now reported when those records are written, so `reset()` cannot erase the failure
  of a record that has already gone out and a broken comparator cannot grow the list unbounded
- **A postFlush that runs application code is inside the failure policy.** A deferred representer
  and `withContext()` ran outside it, so an exception came out of `flush()` — after the commit,
  for a database change that is already real
- **An inverse collection records what joins and leaves it without `trackElements`.** Doctrine
  keeps such a change on the element's own reference back, so the owner is never scheduled and
  its collection never goes dirty; membership was reachable only through element tracking, which
  is a different feature. Membership now follows from the field being audited, and
  `trackElements` governs only what changed *inside* an element
- **`reportFailure()` is failure-safe.** A redactor that threw was called again from the failure
  path and escaped it; a listener that threw replaced the failure being reported and stopped the
  rest of a batch
- **The numeric comparator cannot be made to materialise a hundred megabytes** by a well-formed
  `1e100000000`: an exponent nobody could hold gets no opinion, which costs an extra record at
  worst
- **A comparator that throws while a remove closes its object no longer loses both records.**
  `release()` had the net; the terminal remove path finalized without it, and because the held
  record had already left the buffer, the update *and* the remove were gone — under the policy
  chosen so a business operation carries on
- **One record that cannot be prepared no longer takes its whole batch with it.** Reporting
  raises under `on_failure: throw`, and it was called from inside the loops that prepare records:
  everything prepared before the bad one never reached the request, and everything after it was
  never prepared. A hole the size of `batch_size`, in the policy chosen because a missing entry
  is unacceptable. Both loops now collect and report after the batch has gone
- **A nested `flush()` no longer publishes the outer flush's records early.** 0.11.1 made the
  change-set snapshot survive an inner flush; everything else it collected was still cleared and
  written by whichever `postFlush` came first. With the listener order reversed, an inner flush
  published records belonging to a transaction that had not committed — history describing a
  state the database could still roll back. Only the outermost flush publishes now
- **`FrameResetMiddleware` no longer replaces a handler's exception with an audit one.** The
  handler's exception is what Messenger decides retries, failure transport and alerting by; a
  release that failed while cleaning up after it took its place. The same rule `coalesce()`
  already followed
- **The numeric comparator no longer loses digits through a float.** Scientific notation and
  17-digit integers were canonicalised via `sprintf('%.14F')`, so `9007199254740993e0` equalled
  `9007199254740992`, and `1e-15` equalled zero — *false equals*, which delete a real change from
  the trail. Numbers are now read as text, digit by digit, exponent applied by moving the point
- **A comparator failure kept for the writer no longer leaks into the next operation.** It is
  drained when the frame closes even if the write itself failed, and `reset()` clears it
- **Two objects can no longer share one identity inside a frame.** The key joined object type and
  id with `|` and escaped neither, so `"a|b"` with id `"c"` and `"a"` with id `"b|c"` merged into
  one record
- **`doctrine.enabled` now checks what actually delivers the auditing.** The listener is
  attached through the `doctrine.event_listener` tag, which is **DoctrineBundle's** — with
  doctrine/orm alone the container boots, the listener is built, the tag is collected by nobody,
  and not one entity change is ever recorded. That is the silence `enabled: true` exists to
  refuse, and it was checking for the ORM instead. `auto` now stays quiet unless both are there,
  `true` names the missing one, and a full-kernel test proves the listener reaches Doctrine's
  own `EventManager`
- **An empty object type is refused whichever way an entity declares itself.** The attribute
  refused it; `getAuditObjectType(): ''` did not, and records went out with an unfilterable
  empty type. 0.10 claimed both declarations were held to the same rules — half of that claim
  was untrue, behind two test methods that had been declared inside a fixture class where
  PHPUnit never ran them
- **The bundle's own exception no longer repeats a message it did not write.**
  `WriteFailedException` interpolated the cause's message, so a cluster (or a listener) naming
  the offending value put that value into an exception the bundle logs, and into any place that
  exception is shown — after the record itself had been redacted. A declaration mistake, which
  is the bundle's own words, still reads in full; anything that wrapped a foreign exception is
  named by class, with the detail one `getPrevious()` away
- **A tracked element whose id contains a dot cannot overwrite another element's field.**
  `lines.42.quantity` was both "element 42's quantity changed" and "element `42.quantity` came
  or went"; ids are escaped now, and an id without a dot is written exactly as before
- **A `trackElements` declaration is re-checked when the same class declares different
  collections.** The check was cached per class, while the interface form of a declaration is
  deliberately per instance — a second instance's unsupported collection went unvalidated
- **`narrowIn()` keeps booleans apart from numbers.** PHP's string comparison made `true`, `1`
  and `"1"` one value, so a visibility boundary allowing `[true]` kept a query filtered to `1`.
  Numbers and their spelling still intersect, as Elasticsearch matches them
- **A redaction rule with a dot is a scoped rule, and only that.** `shipment.lines` also acted
  as a plain nested path on every other object type. The grammar is now stated once, in one
  place: no dot means a field on any type, a dot means `objectType.field`
- **`redact: [source]` is refused instead of quietly doing nothing.** The actor is a base field
  chosen when the record is built; a rule could never reach it, and accepting one let somebody
  believe their actor was redacted. The exception says where the choice really lives

### Added
- **The transaction boundary is stated once, with a test behind it.** A new README section says
  what the guarantee covers — the flush's own transaction — and what it does not: an outer
  transaction rolling back leaves the records written. Three tests pin all of it, including the
  frame recipe (`end()` on commit, `reset()` on rollback) that closes the gap for an application
  that owns the wider transaction. A transactional outbox is named as post-1.0 work rather than
  implied to exist
- **`UPGRADE.md`** — every version that asked something of you, and what 1.0 freezes as a
  limitation rather than a bug
- **A full-kernel boot test**: FrameworkBundle and DoctrineBundle present, so what is proven is
  that the tags the bundle declares are *collected* — not merely that its services can be
  built. The two levels are described in CONTRIBUTING, together with the rule that earned them:
  a guard nobody has watched fail is a guard of nothing
- **A cursor token carries its version** (`{"v":1,"s":[…]}`). A token from an older, unversioned
  build is still read; one from a newer version is refused by name instead of being read as
  something it is not
- **`AuditQuery::DEFAULT_MAX_TERMS`** — the filter-list ceiling, named and documented as
  Elasticsearch's default `index.max_terms_count` rather than a bare number in a condition
- **A tracked element inserted with a generated id is represented after the flush**, so a
  representer reading that id (`getId`) records the id rather than the null it had beforehand.
  A removed element is still represented eagerly, while it still has its values

### Changed
- **The Elasticsearch client floor is 8.18**, up from 8.0. Writes are sent with
  `include_source_on_error=false`, which asks the cluster to keep the refused document out of the
  error it returns. The parameter does not exist before 8.18, and an unknown query parameter is a
  400 — which this bundle classifies as a permanent refusal, so on 8.0–8.17 the line meant to
  protect a record would have dropped every one of them. What the parameter does was then measured
  against live 8.19 and 9.1 clusters rather than taken from its name: it suppresses the document
  *source* and leaves `Preview of field's value: '…'` untouched. That fragment is cut by
  `RequestRejectedException::withoutValuePreview()`, which is where the guarantee actually lives,
  and an integration test now proves the value does not survive into the bundle's own exception
- **Indices are created with one replica** instead of none. An audit trail is the last data
  anyone wants living on a single node; a one-node development cluster wants
  `indices.settings.number_of_replicas: 0`, which is now a deliberate choice rather than the
  default
- **`GatewayInterface::bulk()` requires an id per item.** A batch is re-sent whole when the
  cluster asks for it again, and a document without an id would be stored a second time under a
  generated one — the same audit event twice. The writer has always assigned ids; the contract
  now says so, and refuses a batch that would break the retry
- **The mapping comparison behind `audit:check` ignores key order.** Two indices behind one alias
  can spell the same mapping differently — one from a template, one grown by `putMapping` — and
  an order-sensitive comparison called those incompatible
- **An expired point in time is recognised by Elasticsearch's error type**, with the message text
  as a fallback: a human-readable sentence is free to change between versions
- **The README routes both Messenger messages.** A flush that produced several records is sent as
  `IndexAuditRecords` and written in one `_bulk`; routing only `IndexAuditRecord` left the batch
  message on the synchronous bus, so exactly the requests that produce the most audit records were
  the ones still waiting for Elasticsearch — and nothing said so
- **Documented rather than implied**: what redaction does *not* cover (records already written,
  the actor field, `_source` under `dynamic: false`, values nested in free-form arrays); that
  `RecordFailedEvent` reports a failed hand-over, and with the messenger transport an indexing
  failure surfaces in the worker's failure transport instead; that a UUID v7 is random within its
  millisecond rather than ordered by write; and that the existence check before a write is a good
  error rather than a guarantee — `action.auto_create_index` on the cluster is the guarantee, and
  the README now asks for it as part of installing the bundle

- **Docblocks that had drifted from the code they describe**: `BulkResult::hasTransientFailures()`
  said "429 or 503" while the constant is `[404, 429]` plus every 5xx; `InvalidQueryException`
  said it is raised while a query is built, and the gateway also raises it for a 4xx the cluster
  answered a search with; the clock comment offered an alias to override that was never defined;
  `indices.settings` now says that what you write replaces the defaults rather than merging with
  them, which matters the first time another default is added. `AuditWriter::writeCompleted()` is
  gone — nothing called it, the frame goes through `writeManyCompleted()`. The Messenger handlers
  name the third outcome of a write, `IndexNotFoundException`, which is neither retried-by-class
  nor unrecoverable but retried by Messenger's default strategy — right for an index mid-rollover,
  and worth knowing if a custom strategy keys on the class
- **A test that asserted nothing** is now the test its name promised: "an untracked collection
  ignores its elements" changed a quantity, never flushed, and cleared the unit of work, so an
  empty history was guaranteed however the listener behaved — and it talked about one entity while
  using another, whose collection *is* tracked. Rewritten against the fixture the behaviour needs,
  with the other half (membership is recorded without `trackElements`) beside it
- **`@throws` on `GatewayInterface` matches what the implementation raises**, method by method,
  with the two failures common to all of them (an unreachable cluster, a client built for
  asynchronous responses) said once at the top instead of half-listed on each
- **The boot test builds the services defined by class id and the interface aliases too**, and
  asserts both Messenger handlers under `transport: messenger` — a batch message reaching a
  worker with no handler was, until now, only caught in production

## [0.12.0] - 2026-09-05

The API takes the shape it will freeze in: what a field-tested integration had to build around
the bundle now lives in it.

### Added
- **`narrow*()` and `matchNothing()`: the extension family that cannot widen.** A
  QueryExtension almost always means "of what was asked for, only what this viewer may see" —
  and `with*()` REPLACES, so the natural `withObjectIds(...$visible)` threw away the id the
  client asked about and silently widened the result (a field integration shipped that bug: a
  filter answered with every user of the viewer's countries). `narrowObjectIds()`,
  `narrowActors()` and `narrowIn()` intersect instead, and an empty intersection becomes
  `matchNothing()`: the reader answers an empty page with **no request**, replacing the made-up
  ids (`'-'`, `-1`) applications typed to fit each field's mapping. Nothing is **sticky** — once
  an extension says "none of it", no later filter in the chain reopens the answer
- **`whereExists()`, `whereNotExists()`, `whereBetween()`.** term/terms was all a filter could
  say; "records that do not have the field" — what a backfill hunts — meant going around the
  bundle with a bare client. Filters are carried as `Filter` value objects, a shape the kinds
  can grow in without changing the map's type again
- **`AuditReader::raw()`: the escape hatch that keeps the guarantees.** Aggregations ("who
  changed this most", "events by type") need a body `find()` cannot say. `raw($query, $body)`
  runs the QueryExtensions, puts the query's filters on the request as a boundary the body's own
  `query` can narrow but not widen, routes to the query's index — and hands back the raw
  response. Over a query that matches nothing it answers hits and **no `aggregations` key** (an
  empty bucket list cannot be invented without knowing the aggregation), so read those with
  `?? []` — said in the docblock, the README example and a test, because the case shows up
  exactly when a viewer may see nothing
- **`audit:index:sync`: the command audit:check was pointing at but did not have.** "exists but
  lacks mapping for: orderCountry" now has an answer that works: sync adds exactly the missing
  fields (a nested one travels as a partial parent the cluster merges — proven live) and refuses
  to touch anything mapped otherwise than declared, because a changed type is a reindex.
  `GatewayInterface` grew `putMapping()` and `settings()` for it
- **`audit:check` compares the two result windows.** `reader.max_result_window` and the index's
  own `index.max_result_window` must move together; apart, the drift surfaces as a refused deep
  page in production. The check reads the live setting per concrete index (an alias can stand
  for several) and fails by name

### Changed
- **`AuditQuery::$filters` holds `Filter` objects** instead of bare scalars-or-lists — see
  Upgrading. A filter's kind is the `FilterKind` enum, so a kind the translation cannot render
  is unconstructable rather than a `default` arm nobody would notice missing
- **A decorator's `extra` outranks a stored attribute in `AuditEntry::toArray()`.** Extra is
  read-side enrichment (a country code decorated into its name), and toArray() is the read-side
  shape; the old order made such a decorator silently change nothing. Base fields still yield to
  neither; `toDocument()` is the stored shape and never sees extra — the difference between the
  two methods is deliberate and now documented on both
- **`FrameBuffer` accepts any `ValueComparatorInterface`**, so the chain can be decorated or a
  single link injected. Its close-time question falls back to the plain comparison on a null
  ("no opinion") answer, like every other consumer of the interface — the concrete type was
  quietly load-bearing there
- The write path resolves an index through the whole record (`IndexResolver::resolveFor()`,
  internal), so a time-based routing strategy stays an additive change; rotation that must work
  today is a write alias with ILM rollover, as the README's retention section shows

### Upgrading
- Code that read `$query->filters` directly — the intersection helpers this release absorbs —
  finds `Filter` value objects there now (`kind` — a `FilterKind` enum — plus `value`, `values`, `from`, `to`). Extensions
  should not need the map at all any more: `narrowIn()` is the intersection, done right
- A custom `GatewayInterface` implementation must add `putMapping()` and `settings()`
- A decorator's `extra` entry named like a stored attribute now shows up in `toArray()` output
  where the attribute used to. If you relied on the stored value winning, rename the extra key
- Sentinel "match nobody" values (`'-'`, `-1`) keep working — they are ordinary values nobody
  has — but `matchNothing()` says it without knowing the field's mapping, skips the request, and
  survives the field changing type

## [0.11.1] - 2026-09-03

What the first day of field testing found: a change set that dies under a nested flush, and the
three days it takes to see that without a log line.

### Fixed
- **A flush inside somebody else's lifecycle listener no longer empties the record.** The
  change set was read back in `postUpdate`, and by then it may be gone: `UnitOfWork::commit()`
  ends in `postCommitCleanup()`, which empties `entityChangeSets` — of the flush still
  running too. The listener that flushed need not know auditing exists, and the symptom is an
  `update` whose `changes` are `{}`: no error, no failed write, nothing in any log. It is now
  taken in `onFlush`, where Doctrine has just computed it, and the unit of work is still asked
  first so that a `preUpdate` listener's own change (merged in through
  `recomputeSingleEntityChangeSet()`) is not lost either. A flush nested inside a listener
  keeps its own books: the snapshot survives until the OUTERMOST flush ends, because the inner
  one reaches `postFlush` while the outer is still walking its entities. The snapshot covers
  insertions as well as updates: the same wipe reaches `postPersist`, and a `create` has no
  `skip_empty_updates` to hide behind — the history would have said an entity appeared with no
  values at all

### Added
- **The lost change set is reported instead of passing in silence.** Once per flush, at
  warning level, naming the class, the mechanism (`postCommitCleanup`) and the fix (move that
  work to `postFlush`) — and noting that `extraUpdates`, `collectionUpdates`, `orphanRemovals`
  and `collectionDeletions` of the running flush are emptied with it, so more than the history
  may be missing. Finding this without the line took three days. `AuditSubscriber` takes an
  optional `LoggerInterface` for it; the extension wires the one it already gives the writer

## [0.11.0] - 2026-09-01

A check that holds the index to everything the definition declares, boundaries that answer by
name instead of one round trip later, and the whole slice proven against a live cluster.

### Fixed
- **`audit:check` sees past the type.** A `date` whose `format` drifted refuses every record the
  writer sends, and an enricher's nested field that was never mapped filters to nothing — both
  passed a comparison that stopped at the top-level type. The check now holds the index to
  everything the definition declares: the type, the options behind it (`format`,
  `enabled: false`), and the fields inside an object, reported by path
  (`context.ip is keyword, expected ip`). Proven against a live cluster: an index that passed the
  old check refused every document
- **One bad document no longer blocks the page it is on.** A `loggedAt` nobody can parse (written
  by another tool, mangled by a reindex) made `AuditEntry::fromHit()` throw and took the whole
  page with it. Hydration is lenient by policy — now stated on `fromHit()` — and a corrupt
  timestamp reads as the epoch: present, visibly wrong, and not in the way
- **`coalesce()` no longer masks the operation's own exception.** When the operation threw *and*
  writing what the frame held threw too, the close's exception surfaced and the cause was demoted
  to its `previous` — invisible to any `catch` keyed on the cause's type. The operation's
  exception wins; the failed close is logged as an error
- **A fire-and-forget call refuses an asynchronous client too.** `index()`, `createIndex()` and
  `closePointInTime()` read nothing from the response and so never noticed being handed a
  promise nobody waits on: the write may never have happened while the method returned as
  success. They now pass the same guard as every reading call (`NotConfiguredException`)
- **A record dated before 1970 gets a well-formed id.** UUID v7's timestamp field is unsigned,
  and `dechex()` of a negative millisecond count bled a 16-digit two's complement into the id.
  Pinned to the epoch, where the order of prehistory does not matter
- **Reserved attributes are refused at the constructor door as well.** `withAttributes()` threw
  while `new AuditRecord(..., attributes: ['source' => ...])` silently dropped the value from the
  document: the caller believed it was set and the index never saw it

### Changed
- **`doctrine.enabled` defaults to `auto` and an explicit `true` is a promise.** `auto` attaches
  the listener when doctrine/orm is installed and stays quiet when not — what the old default did.
  But `enabled: true` written by hand now *requires* the ORM and fails the boot without it
  (`NotConfiguredException`), the way the messenger transport fails without symfony/messenger: the
  silent alternative is a history discovered missing months later
- **A redaction rule naming a collection covers everything reached through it** (privacy).
  `redact: [lines]` used to cover the collection's own change and not the tracked-element keys:
  `lines.42` ends in an element id no rule can name. Now `lines` hides the membership keys (an
  element came or went, but not what it was) and every field inside; scoped rules
  (`shipment.lines`) scope the same way. Mind that element changes are recorded on the owner, so a
  scoped rule names the owner's object type — documented in the README
- **Boundaries on what a query and a cursor may carry.** A cursor token is capped at 4 KiB and
  its sort values must be scalars or null (null stays legal — legacy indices sort with missing
  values); a structure smuggled in is refused as malformed, as before, just now by rule. A filter
  list (`whereIn()`, `withObjectIds()`, ...) past 65 536 values — Elasticsearch's own
  `index.max_terms_count` — is refused at the boundary with the limit's name, instead of by the
  cluster one round trip later

### Added
- **The whole slice under test against a live cluster**: real entities with their relations, a
  real flush, the real listener, and the reader bringing the history back from a real index —
  with the "throw" policy, so a document the mapping refuses fails the test instead of settling
  into a log. The in-memory double accepts anything; the cluster is the only honest judge of what
  the listener builds. Covers the life of an entity with to-one and to-many relations, element
  tracking against `dynamic: false` (the mapping ends exactly as wide as it started), a frame
  across several flushes landing as one document, a failed flush leaving the index empty, and a
  line moved between owners appearing on both sides. CI runs it against Elasticsearch 8 and 9

### Upgrading
- `doctrine.enabled: true` written explicitly in a configuration without doctrine/orm installed
  now fails the boot instead of silently skipping the listener. Remove the line (or set `auto`)
  to keep the old behaviour; install doctrine/orm to get what the line was asking for
- A redaction rule that names a tracked collection (`lines`, `shipment.lines`) now also covers
  its element keys (`lines.42`, `lines.42.quantity`). If you relied on those staying readable
  while the collection's own change was hidden, name the fields you redact instead of the
  collection

## [0.10.0] - 2026-09-01

A cursor that cannot step over a record, declarations that cannot be silently inert, and a frame
that survives its own transport.

### Fixed
- **A cursor across object types no longer steps over a record.** `any()` reads every routed
  index, and a timestamp with a record id is unique inside one index but not between several: two
  records sharing both — which an application invites the moment it chooses its own record ids —
  made `search_after` walk past one of them. Proven on a live cluster, where the second document
  simply never came back. The index name joins the sort when a query spans indices
- **A composite `objectId` cannot be two entities at once.** The parts were joined with `|` and
  nothing escaped it, so `["a|b", "c"]` and `["a", "b|c"]` both read as `a|b|c` and two histories
  became one. The delimiter and the escape are escaped now; a part containing neither is written
  exactly as before
- **`iterate()` says what it cannot do** instead of silently starting over. A query carrying a
  page or a cursor is refused: the point in time an export opens is not the one those sort values
  came from, and `_shard_doc` belongs to the view that issued it
- **A `trackElements` declaration the listener cannot serve is a mistake, not a silence.** A
  `ManyToMany`, the owning side of a collection, or a field that is no association at all used to
  be accepted and then record nothing. It travels through the failure policy now, like every other
  declaration mistake
- **The invariants hold whichever way an entity declares itself.** `#[Auditable('')]` was refused
  and `getAuditObjectType(): ''` was not; the same for a collection tracking an empty list of
  fields. Both declarations end in `AuditMetadata`, which is where the rules now live
- `whereIn()` refuses a value that is not scalar at the boundary, as `where()` always has, rather
  than letting Elasticsearch report it a round trip later

- **An export survives `iterator_to_array()`.** `yield from` kept each batch's own 0..n keys, and
  without the second argument `iterator_to_array()` overwrites colliding keys: of a five-record
  export, two survived — probed live. Entries yield one by one now
- **A dispatch from inside an open frame no longer ends it.** The reset middleware runs on
  dispatch too, so with the messenger transport the writer's own send — or any message a handler
  dispatched — released the frame mid-operation: phantom intermediate states, and a warning
  blaming a try/finally nobody omitted. It now acts only when a consumed message finishes, and
  only at the outermost one
- **A deleted element answers to the owner its database row had.** Reading the owner from memory
  wrote "removed from B" for a line that was re-pointed and removed in one flush — B never held
  it — and recorded nothing at all for the maker-style `removeItem()` that nulls the back-ref
  before `orphanRemoval` deletes. The old owner is read from the element's change set, where
  Doctrine keeps it
- **A record made only of element changes carries its `alwaysRecord` context**, whether it was
  assembled after the flush or amended there — every history line reads on its own, including the
  ones that say `lines.42.quantity`
- **The inverse side of a ManyToMany is refused as a tracked collection.** It has a `mappedBy`, so
  it passed the shape check and then silently recorded nothing — its elements reach back through a
  collection the unit of work never reports to this side
- **A comparator that throws while a frame closes cannot take the frame with it.** The held
  records had already been taken off the buffer, so one broken comparator lost all of them, raw,
  past the failure policy. The record whose comparator failed goes out unfinalized — noisier,
  never lost — and the mistake is reported the way the same mistake on the way in always was
- **A per-item 404 in a batch is retried**, as the single-record path always did: with rollover
  and the recommended `auto_create_index` guard, a missing index is an index mid-rotation. The
  gateway also forgets such an index in its existence cache, as `index()` already did
- **The password in `client.hosts` stays out of the log.** The Elasticsearch client logs every
  request URL, and hosts with inline credentials — a documented pattern — put the secret in the
  application log once per call, probed live. The logger the bundle hands to the client now blanks
  the userinfo first
- A frame-released overflow batch goes out through the batch transport instead of one request per
  record, a flipped sort direction abandons a cursor the query holds (its values belong to the
  ordering that made them), and numeric strings that overflow a float are nobody's quantity
  instead of all being `INF`

### Added
- **`batch_size`** (default 500) — how many records travel in one `_bulk` request or one Messenger
  message. A flush that produced more is split; before this a flush of ten thousand records was
  one request, and one refused for being too large lost every record in it. Every chunk is tried
  and its failures reported even when an earlier one failed; with `on_failure: throw` the first
  failure is raised after the last chunk, as it always was for one batch
- **`coalescing.on_overflow`** — `release` (what it always did: write what the frame holds and
  carry on) or `throw`, which raises `FrameOverflowException` **past the failure policy**: a
  refusal to grow is the operation's to hear, not a failed write to log, so it reaches the caller
  under `on_failure: log` too, and no record is reported as failed — nothing was tried. What the
  frame had already released before the refusal is still written; in the Doctrine path the
  exception surfaces from `flush()`, after the commit, like everything raised in `postFlush`.
  Releasing loses no record but ends
  the promise of one record per object: an object let go early can produce a second record for an
  operation whose net effect was nothing. Where the trail is read for that promise, being told is
  better than being blurred

## [0.9.3] - 2026-08-30

Backpressure is not a refusal, an unreadable answer is not a success, and redaction covers the
half of a record that is actually searchable.

### Fixed
- **Backpressure no longer deletes audit records.** A cluster answering 429 is asking for the same
  write in a moment; the bundle called it a permanent refusal, so an asynchronous record met by a
  full write queue went to the failure transport and was never retried — precisely when the trail
  is busiest. 429 and 503 are now the retried kind, on the request as a whole and **per item** of
  a `_bulk`, where backpressure usually arrives: a batch holding one transient failure is retried
  whole (every document carries its id and overwrites itself), a batch refused only for good goes
  to the failure transport as before, and the message now names the status Elasticsearch actually
  gave instead of a hard-coded 400
- **A `_bulk` answer that cannot be read is no longer counted as complete success.** Missing or
  truncated `items` made `succeeded()` report every document as written; the response must now
  hold exactly as many items as were sent
- **One failure is reported once.** With `on_failure: throw`, a transport error passed through two
  catch blocks: two `RecordFailedEvent`s, and an exception whose cause was another exception of
  the same kind with the real one buried beneath. Delivery has a single failure boundary now
- **A misconfigured client is not an unreachable cluster.** `NotConfiguredException` raised while
  the bundle talked to Elasticsearch was wrapped as "Elasticsearch is unreachable", sending
  whoever read it to the network instead of the configuration
- **A point in time that could not be closed says so.** Every 4xx was swallowed as "already
  expired"; a 403 or a 429 means the view is still open and holding memory. Only 404 is silence
- **Redaction covers attributes, and cannot be undone by a listener.** Attributes are the indexed
  half of a record, so redacting `changes` alone protected what cannot be searched and left open
  what can. A redacted attribute is now dropped rather than masked — `'***'` in a field the
  mapping calls an integer would have Elasticsearch refuse the whole document. And the record is
  redacted once more after `RecordCreatedEvent`, so a listener that replaces it cannot hand back
  what was removed
- **Two timestamps in the same second are no longer "the same instant".** The comparison used
  whole seconds, so a change made 100 ms after another was recorded as no change at all
- **Two large integers that differ by one no longer compare equal.** `numeric_fields` compared
  through floats, and past 2^53 a real change disappeared. Numbers are compared as canonical
  decimals: `"00012.00"` and `"12.000"` are still one value, `9007199254740992` and its neighbour
  are two
- **A tracked element that changes owner is recorded on both sides.** Moving a line from one
  order to another left no trace at all when none of the line's own fields changed — the
  collections are not dirty and the change lives on the owning association. The owner it left
  records the loss, the owner it joined the arrival; the element's own field delta is left out of
  that flush, because the new owner never held the old value. The Limitations entry that said a
  move "is seen through its new owner only" was describing behaviour the bundle did not have
- **A new owner with tracked children produces one `create`.** The membership found no record to
  join and invented a second, phantom `update` of an owner nobody had updated
- **An object type keeps the name you gave it.** `indices.routing` normalised its keys, so
  `warehouse-stock` became `warehouse_stock` and the writer — arriving with the name the
  application uses — found no route and wrote to the default index without a word
- An empty string is no longer accepted as `client.service`, `message_bus` or
  `doctrine.connection`. The first also defeated the rule that one of `client.hosts` or
  `client.service` must be set
- `audit:check` reads **every** index behind an alias. It took whichever came back first, so a
  member left behind by a rollover could keep an alias looking healthy
- `iterate()` no longer asks for an exact total on every batch — an export paid for a full count
  of the result set per page and never read one
- The cursor's exception says what the code checks: the token is malformed. It never verified
  where a token came from, and said it did

### Added
- **`object_id_type: long`** — Elasticsearch's `integer` is 32 bits and stops at 2 147 483 647,
  which a `BIGINT` primary key outgrows. For numeric identifiers this is the one to choose
- `AuditRecord::withoutAttributes()`, which is how redaction drops what must not be stored

## [0.9.2] - 2026-08-30

One answer to whether a value moved, instead of two that had drifted apart.

### Changed
- **One answer to "did this move".** `ChangeSetBuilder` kept a strict comparison of its own beside
  the comparator chain's, and the two had drifted: an array holding two dates for the same instant
  — a collection snapshot whose representer returns dates — was a change to one and not to the
  other, so what a record said depended on whether a comparator had been injected. The builder now
  takes a comparator that is never null (the chain by default) and falls back to the chain's own
  comparison; its copy is gone. `AuditSubscriber` likewise. Both are `@internal`; nothing an
  application implements or configures changes
- **This is not a no-op on array fields**, and it is worth knowing before the records change
  under you: a `json` column whose keys come back in another order, and an array holding dates
  for the same instant, are no longer recorded as changes — the merged comparison goes key by
  key and compares dates by instant, where the builder compared arrays with `===`. Lists still
  compare by position. Expect **fewer** records on such fields, not different ones; a permissions
  map rewritten in another order used to produce a record saying nothing had changed

## [0.9.1] - 2026-08-30

The Doctrine listener can be built again.

### Fixed
- **The Doctrine listener could not be built in 0.9.0.** The comparator chain the listener was
  wired to is a `ValueComparator`, and the listener asks for a `ValueComparatorInterface`, which
  that class did not implement — so the first flush in any application with entity auditing on
  ended in a `TypeError`. It implements the interface now; nothing else changes, and no
  configuration is affected
- The suite builds **every service the extension defines**, in a kernel, and type-checks every
  definition before unused ones are removed. Compiling proved the wiring and nothing about the
  types: a private service an application reaches through event tags — which is exactly what the
  listener is — was first constructed in production

## [0.9.0] - 2026-08-30

What a day of real use asked for: a record that says where it came from, changes inside the
elements of a collection, and an enricher that sees what was actually written.

### Added
- **`AuditRecord::$origin`** — `AuditOrigin::Doctrine` for what the entity listener built,
  `Manual` for what the application handed to the writer, `Mixed` for a record a frame merged out
  of both. An enricher that should only touch one kind can ask instead of inferring it from the
  actor. Not stored: it is a fact about the write, not about the history
- **`MergedRecordEnricherInterface`** — an enricher that runs once per record immediately before
  it is written, on whatever a frame merged (and on the record itself when no frame was open).
  An ordinary enricher runs per step, so an attribute it computes describes the last save rather
  than the outcome: 1000 → 1040 → 1000 is no change at all, and only an enricher running here
  can say so
- **Changes inside the elements of a collection**, on request:
  `#[AuditField(trackElements: ['quantity'])]`, or `TracksCollectionElementsInterface` beside
  `AuditableInterface`. A record then carries `lines.42.quantity` for a field that changed and
  `lines.42` for an element that appeared or went. It costs a query only when something actually
  changed — the unit of work is asked which entities this flush touches, so an untouched
  collection is never loaded — and an owner Doctrine raised no event for still gets its record.
  It is also how a **inverse** collection reports gaining or losing an element at all
- **`ValueComparatorInterface` now decides what counts as a change on the write path too**, not
  only what a frame drops when it closes. A `datetime_timezone` column compared by instant
  reported a change whenever the zone moved, and the record showed two timestamps that read
  identically; a comparator says "by wall clock here" and no record is written
- `AuditEntry::withChanges()` — for a decorator that makes the change itself readable (a
  permission key as its name), which until now had to be done outside the decorator
- `AuditEntry::toDocument()` — the entry in the shape it has in Elasticsearch (`source`, stored
  timestamp format), symmetric with `AuditRecord::toDocument()`. `toArray()` is unchanged
- `AuditRecord::withAddedAttributes()` — fills gaps without overwriting, for an enricher that
  defers to whatever set the value first
- README: which version a feature arrived in, the tag names behind autoconfiguration, and why an
  `iterable` of them does not autowire into a service of your own

### Fixed
- **`limit()` no longer throws a cursor away.** `after($cursor)->limit(50)` returned the first
  page instead of continuing — silently, because `limit()` went through `page()`, which resets
  the cursor on purpose. Reaching for a page number still does; asking for a different batch size
  does not
- Redaction understands the keys element tracking produces: a rule for `password` covers
  `lines.42.password`, so tracking elements is not a way around it — and so does
  `coalescing.numeric_fields`: a rule for `quantity` covers `lines.quantity`
- **What `onFlush` collected about collection elements is dropped with the rest when a flush
  fails.** The listener kept it across the rollback, so the next flush of the same owner reported
  a line as added that the database never had — the same phantom 0.3 removed for plain records,
  found again on the new path before it shipped
- An owner removed together with its tracked elements gets its `remove` and nothing after it.
  The elements going with it used to be collected as "lost", and an owner whose identifier
  survives the DELETE (assigned, not generated) received an `update` after its own `remove`

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
