# Upgrading

On the `0.x` line every minor may change the API, and Composer does not treat `0.x` minors as
compatible: `^0.4` will not pull in `0.5`. Pin the minor you tested against and read this file
when you move.

## 0.7 → 0.8

Nothing to do unless you referenced the constants or relied on the query object refusing a large
page.

- **`AuditQuery::MAX_LIMIT` / `MAX_WINDOW` are renamed** to `DEFAULT_MAX_LIMIT` /
  `DEFAULT_MAX_WINDOW`. They are what the reader takes when nothing is configured, not limits the
  query enforces.
- **A page larger than the limit, or deeper than the window, is now refused by `AuditReader`
  rather than by `AuditQuery::page()`.** Same exception (`InvalidQueryException`), same "before
  anything reaches Elasticsearch" guarantee, one step later — and the message names the setting.
  Code that caught it around `page()` should catch it around `find()`.
- To show more rows per page, raise `reader.max_limit`; to page deeper, raise
  `reader.max_result_window` **and** the cluster's `index.max_result_window` to match. Remember
  that a decorator then receives that many entries in one call — chunk its lookups.
- **`AuditPage::nextCursor()` now returns null when nothing follows** — an empty page, the last
  page of a numbered run, or a short cursor batch. It used to hand out the last entry's sort
  values regardless, so a "load more" built on it led to an empty page at the end. `hasMore()`
  answers the same question directly.
- **`toArray()['pagination']['nextCursor']` is a string, not an array.** It is the token form
  (`nextCursorToken()`), which is what a client should carry: base64url, safe in a query string,
  and opaque. Read it back with `AuditQuery::afterToken($token)`; `after($array)` is unchanged for
  callers that stay inside PHP. If your frontend stored the old JSON array, its stored cursors
  stop working — they were the sort values of a different response shape anyway; start from page
  one or from a fresh token.
- `pagination` gained `maxReachablePage` and `hasMore`. Nothing was removed, so a client that
  ignores them keeps working.

## 0.5 → 0.6

Nothing to do for an application that uses the bundle through its configuration and services.

Two things to know if you extended it:

- **A custom `GatewayInterface` implementation has to add four methods**: `bulk()`,
  `openPointInTime()`, `searchPointInTime()` and `closePointInTime()`. The in-memory gateway in
  the test suite shows the smallest faithful implementation of each.
- **A custom transport keeps working unchanged.** Batches go through the new
  `BatchTransportInterface`; a transport that implements only `TransportInterface` receives the
  records one `send()` at a time, exactly as before. Implement `sendMany()` to get one request per
  flush.

Two behaviour changes you may notice:

- **`iterate()` opens a point in time.** The export is now a frozen view: records written while it
  runs do not appear in it. If you relied on picking them up, pass `consistent: false`. The cluster
  keeps the view alive for `reader.point_in_time_keep_alive` (default `1m`) between two batches;
  raise it if a consumer of one batch is slower than that, or the next search fails with
  `InvalidQueryException`.
- **A refused document in a batch is reported on its own**, and no longer takes the rest of the
  flush's records down with it. With `on_failure: throw` the exception raised is the first failure,
  after every failure was logged and dispatched as `RecordFailedEvent` — and the accepted records
  of that batch **are written**: with batches, an exception no longer means "nothing was stored".
  Look at `RecordFailedEvent` (or the log) for the exact records that were not.

## 0.4 → 0.5

Nothing to do. New configuration, all optional:

```yaml
borsche_elasticsearch_audit:
  redact:
    fields: []          # add the fields whose values must not be stored
    placeholder: '***'
```

If you redacted by hand in a `RecordCreatedEvent` listener (the pattern an earlier README
showed), move the field names into `redact.fields` and drop the listener: redaction now happens
in the writer, after a frame has merged its steps, so a password change inside `coalesce()` is
recorded as `*** → ***` instead of being dropped as "unchanged" — which is what a listener-side
or enricher-side redaction would make it look like to the frame.

Two behaviour changes worth knowing about, neither of which needs a code change:

- **Arrays inside `changes` are compared element by element and strictly** when a frame decides
  whether a field came back to where it started. Before, `['1']` and `[1]` counted as the same
  value and such a change could be dropped as noise; now it is recorded.
- **A representer naming a method the related object does not have** raises a `LogicException`
  that names the declaration (`#[AuditField(represent: "getNope")] on App\Entity\Comment::$author
  names a method App\Entity\User does not have`) instead of a PHP error from inside a flush. With
  the default `on_failure: log` the flush still goes through and the mistake is in the log.

## 0.3 → 0.4

`AuditFrame` and coalescing are new and off unless you open a frame, so an application that does
not call `coalesce()` behaves as before. If you do open frames:

- **`FrameResetMiddleware` writes what a leaked frame held** (`AuditFrame::release()`) instead of
  dropping it. Use `AuditFrame::reset()` explicitly where the records must not exist.
- **`RecordCreatedEvent` fires once per coalesced record**, not once per step. A listener that
  counted events now sees fewer.
- **`AuditWriter::writeCompleted()`** is the way to write an already-completed record; enrichers
  do not run again. Regular `write()`/`record()` are unchanged.

Fixed in 0.4 and relevant if you upgraded to 0.3 early: record ids generated by 0.3 used two
correlated random bits. They stay valid version 7 UUIDs — no reindexing, no migration.

## 0.2 → 0.3

- **A write to an index that does not exist now fails** (`IndexNotFoundException`, handled by
  `on_failure`) instead of letting Elasticsearch auto-create it with a guessed mapping. Run
  `bin/console audit:index:create` before the first record, and prefer
  `action.auto_create_index: "-audit_*,+*"` on the cluster.
- **`id` became a reserved document field.** An enricher attribute named `id` is refused by
  `AuditRecord::withAttributes()`; rename it.
- **An index created before 0.3 needs `id` added to its mapping before the bundle writes to it.**
  Such an index has no `id` field and, unless you set one, no `dynamic: false` — so the first
  record the bundle writes makes Elasticsearch map `id` as `text`, and every read afterwards fails
  with *Fielddata is disabled on [id]*, because the sort cannot use a text field.
  `unmapped_type: keyword` does not save you: it applies only while the field is **unmapped**.
  Verified on Elasticsearch 9.1. Add the mapping first — it is one call per index, and it is safe
  to run before or after the upgrade, as long as it is before the first write:

  ```bash
  curl -X PUT "$ES/audit_log/_mapping" -H 'Content-Type: application/json'        -d '{"properties": {"id": {"type": "keyword"}}}'
  ```

  Then `bin/console audit:check` confirms the index, and recreating it with
  `audit:index:create` (which sets `dynamic: false`) is the tidier fix when you can afford a
  reindex.
- **`AuditQuery::any()` reads every routed index**, not only the default one. A query that
  expected the default index alone now sees more.
- **`TransportInterface::send()` takes a third argument**, `?string $id`. Custom transports must
  accept and forward it, or a Messenger redelivery will duplicate records.

## 0.1 → 0.2

- **`psr/event-dispatcher` is a hard dependency.** No action needed; it is an interface package.
- **Dates inside `changes` are written in UTC**, like `loggedAt`. Records written by 0.1 kept the
  value's own timezone, so a history spanning the upgrade can show both — nothing needs fixing,
  but a screen that parses them should treat 0.1 records as local time.
- Automatic Doctrine auditing starts as soon as an entity declares itself auditable
  (`AuditableInterface` or `#[Auditable]`) — no entity is audited by accident.
