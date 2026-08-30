# Handing the bundle to a model for review

Two commands:

```bash
tools/pack-for-review.sh          # writes review/<axis>.txt
```

Then start **one conversation per axis**: paste the *Rules* block below, paste the axis prompt,
attach that axis's pack. Do not merge two axes into one conversation, and do not ask for a review
of the whole bundle — `src/` alone is around fifty thousand tokens, which fits in a large context
window and still produces a list of naming suggestions, because "here is everything, find bugs" is
not a question anyone can answer.

Current pack sizes (they grow with the bundle):

| Axis | Files | Tokens |
|---|---:|---:|
| `doctrine` | 16 | ~21k |
| `writer-coalescing` | 34 | ~29k |
| `read-path` | 20 | ~23k |
| `di-boot` | 10 | ~17k |
| `privacy` | 6 | ~7k |
| `elasticsearch` | 22 | ~21k |

---

## Rules (paste this first, every time)

> You are reviewing part of a PHP library. I will give you the promises this part makes and the
> code that has to keep them, tests included.
>
> Your task is not to review the code. It is to find **where the code breaks one of the stated
> promises**. For every finding give exactly this, in this order:
>
> 1. **The code**, quoted — five to fifteen lines. Not a line number: quote what you read.
> 2. **The scenario**: which sequence of calls or which data, and what happens instead of the
>    promise. Concrete values, not "could potentially".
> 3. **The test that would catch it**: its name and what it asserts.
>
> A finding without point 2 is not a finding — drop it yourself before answering.
>
> Do not propose renames, type hints, doc blocks, formatting, "consider extracting", or anything
> whose failure mode is aesthetic. If a promise holds, say so in one line and move on.
>
> Then answer separately, and take your time over it: **which of the tests I gave you would still
> pass if the behaviour were wrong?** Name them and say what they fail to pin down.
>
> Look especially for these, each of which has already happened in this codebase at least once:
>
> - state that outlives a single operation and is not reset when that operation is rolled back;
> - a divergence between two supported major versions of a dependency;
> - types that match while a DI container is compiled and do not match when the service is built;
> - a decision taken from decorated or filtered data where the raw answer was the truthful one;
> - the same rule implemented in two places, which have since drifted apart;
> - a hook that runs at the wrong moment of a lifecycle — before a merge that changes the answer,
>   or after the identifier it needs has been cleared.

---

## Axis: `doctrine`

> The promises:
>
> - Records are built while the unit of work still knows the change sets, and written only after
>   the transaction committed. **The history never describes a state the database did not reach**:
>   a flush that fails rolls back, the manager is cleared, and what was collected is dropped.
> - An update whose audited fields did not change is not recorded (`skip_empty_updates`).
> - A tracked collection records what changed **inside** its elements, keyed `lines.42.quantity`,
>   and elements gained or lost, keyed `lines.42`. **It costs a query only when something actually
>   changed** — an untouched collection is never loaded.
> - An owner Doctrine raised no event for still gets its record, built after the flush.
> - Doctrine ORM 2 and 3 are both supported, and the listener behaves identically on both.
> - What counts as a change is the comparators' answer, not Doctrine's.

## Axis: `writer-coalescing`

> The promises:
>
> - A frame merges the records one business operation produces across several saves into one per
>   object: earliest old, latest new, and a field that ended where it started is dropped. An
>   update in which nothing moved at all is not written.
> - `AuditEnricherInterface` runs when a record is created; `MergedRecordEnricherInterface` runs
>   once per record immediately before it is written, on whatever the frame merged — and on the
>   record itself when no frame was open, so the behaviour does not depend on whether a caller
>   opened one.
> - `on_failure: log` means a failed write is logged and the application continues; `throw` means
>   it raises. Neither loses a record silently.
> - A flush that produced fifty records is one `_bulk` request, and one refused document does not
>   take the other forty-nine with it.
> - A record released by a frame does not go through the enrichers a second time.
> - `origin` says which part of the application produced the record, and a merged record does not
>   claim an origin it does not have.

## Axis: `read-path`

> The promises:
>
> - `hasMore()` is arithmetic for a numbered page and a full batch for a cursor one, and it follows
>   what Elasticsearch returned, not what the decorators left: **a decorator that hides entries
>   must not end a walk early or skip the records after the one it hid**.
> - `nextCursor()` is null once nothing follows, so a "load more" never leads to an empty page.
> - `maxReachablePage()` is how far page numbers reach — the window divided by the page size.
> - A page larger than `reader.max_limit`, or deeper than `reader.max_result_window`, is refused
>   before the request with an exception naming the setting to raise. A cursor is bounded by
>   neither.
> - A cursor token is opaque, survives a query string unescaped, and a damaged one is refused
>   rather than half-understood.
> - `iterate()` reads from a point in time: records written while it runs do not appear, and no
>   record appears twice because a segment merged underneath.
> - Paging by number and paging by cursor return the same entries in the same order.

## Axis: `di-boot`

> The promises:
>
> - The bundle can be registered in any Symfony 6.4 / 7 / 8 application, and **every service the
>   extension defines can actually be built** — including the ones an application never fetches
>   itself, such as the Doctrine listener, which is reached through event tags.
> - The configuration key is `borsche_elasticsearch_audit`, and the extension's alias matches it.
> - Every extension point is picked up by autoconfiguration; a service that is not autoconfigured
>   can be tagged by hand with the documented tag names.
> - Switching `transport: messenger` on changes where the write happens and nothing else; with
>   `doctrine.enabled: false` no listener is registered at all.
> - The configuration tree refuses what it cannot honour (an index name Elasticsearch would
>   reject, a keep-alive that is not a time value, a client with neither hosts nor a service).

## Axis: `privacy`

> The promises:
>
> - A field named in `redact.fields` never reaches Elasticsearch with its value, **by any path**:
>   a Doctrine change set, a hand-written record, a merged frame, a tracked collection element
>   (`lines.42.password` is covered by a rule for `password`), or a bulk batch.
> - Redaction happens on the way out, not when the record is built — a frame has to see the real
>   values to know that a field moved — and the fact that the field changed survives redaction.
> - A side that was null or empty stays as it was: masking nothing would invent a value.
> - The refused value of a rejected document never appears in an exception message or a log line.
> - `changes` is stored but not indexed, and the mapping is `dynamic: false`, so a field nobody
>   declared cannot appear in the index by accident.

## Axis: `elasticsearch`

> The promises:
>
> - A 404 on a named index is a missing index, any other 4xx is a request Elasticsearch refused,
>   and everything else — connection errors, 5xx — is an unreachable cluster. Each is its own
>   exception type and the application can tell them apart.
> - The bundle never lets Elasticsearch create an index by accident: a write to a missing index is
>   refused rather than guessing a mapping.
> - A `_bulk` request reports refusals **by position**, so the caller knows which document failed,
>   and the ones that succeeded are not reported as failures.
> - A point in time is closed however an export ends, a `break` included.
> - Both Elasticsearch 8 and 9 are supported through the same code.
