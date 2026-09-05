# Elasticsearch Audit Bundle

[![CI](https://github.com/YlikScherbak/elasticsearch-audit-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/YlikScherbak/elasticsearch-audit-bundle/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-8.1%20%E2%80%93%208.4-777bb4)
![Symfony](https://img.shields.io/badge/symfony-6.4%20%7C%207%20%7C%208-000000)
![Elasticsearch](https://img.shields.io/badge/elasticsearch-8%20%7C%209-005571)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

> **Stable since 1.0.** The surface listed under [What counts as the public
> API](#what-counts-as-the-public-api) carries a stability promise within `1.x`. Coming from a
> `0.x` release, [UPGRADE.md](UPGRADE.md) is the one page to read; the
> [CHANGELOG](CHANGELOG.md) has the reasoning behind every change.

A Symfony bundle that records **who changed what** in your application into Elasticsearch:
Doctrine entities audited automatically, arbitrary domain actions logged on demand, many small
changes coalesced into one record, asynchronous writes through Messenger, and a filterable read
API on top — for the moment your audit log stops fitting in a SQL table.

## Why this exists

Every application ends up with an audit log, and most of them start as a table. That works until
it does not: the table becomes the largest one in the database, every filter needs another index,
"show me everything this user touched last month" becomes a report nobody wants to run in
production, and the write on every save starts to show up in response times.

The existing Symfony options (`simplethings/entity-audit`, Gedmo `Loggable`) write to SQL and
solve a different problem — reverting an entity to an earlier revision. This bundle is for the
other need: a **searchable history**, kept out of the primary database, that also records the
things which are not entity changes at all — a call placed, a login refused, a file shared.

It was extracted from a CRM where the same mechanism had quietly become a library: adding audit
for an external Google Drive integration took one constant and a subscriber, and the existing
history screen showed the new events without a change.

## Requirements

- PHP 8.1+
- Symfony 6.4, 7.x or 8.x
- Elasticsearch 8 or 9 (`elasticsearch/elasticsearch` `^8.0 || ^9.0`). The client's major
  version must match the cluster's: a 9.x client is refused by an 8.x cluster
  (`Accept version must be either version 8 or 7`), so pin it —
  `composer require elasticsearch/elasticsearch:^8.0` for an 8.x cluster
- With the version 9 client, a PSR-18 HTTP client — it no longer ships one:
  `composer require guzzlehttp/guzzle`

## Installation

```bash
composer require borsche/elasticsearch-audit-bundle
```

Symfony Flex registers the bundle. Without Flex, add it to `config/bundles.php`:

```php
Borsche\ElasticsearchAuditBundle\ElasticsearchAuditBundle::class => ['all' => true],
```

## Configuration

This file describes `main`. A setting the latest release does not have yet is marked **(since 0.8)** — on an older tag Symfony answers an unknown key with *Unrecognized options … under "borsche_elasticsearch_audit"*, so check the tag you actually installed.

```yaml
# config/packages/borsche_elasticsearch_audit.yaml
borsche_elasticsearch_audit:
  client:
    hosts: ['%env(ELASTICSEARCH_URL)%']  # or: service: my_es_client (an Elastic\Elasticsearch\Client)
  indices:
    default: audit_log                    # every record goes here...
    routing:                              # ...unless its object type is routed elsewhere
      auth: audit_auth_log
    object_id_type: keyword               # or "long" — only if EVERY audited type has numeric ids
  transport: sync                         # or "messenger" (see below)
  batch_size: 500                         # records per _bulk request or Messenger message (since 0.10)
  on_failure: log                         # or "throw"
  actor:
    fallback: system                      # recorded when nobody is authenticated
  redact:
    fields: [password, token]             # values replaced before anything is written
  reader:                                 # both keys since 0.8
    max_limit: 1000                       # largest page; raise for screens showing thousands of rows
    max_result_window: 10000              # how deep page/limit may reach; match index.max_result_window
```

Then create the indices:

```bash
bin/console audit:index:create   # creates every configured index with its mapping
bin/console audit:index:sync     # adds fields an existing index lacks; never changes what is mapped (since 0.12)
bin/console audit:check          # cluster reachable? indices there? every field mapped? windows aligned?
```

`audit:index:create --dump` prints the mapping instead, for when the index is provisioned by
other means (Terraform, an ILM policy, a hand-written template). When an enricher grows a field
*after* the index was created — the usual story behind `audit:check`'s "lacks mapping for" —
`audit:index:sync` adds exactly the missing fields (a nested one travels as a partial parent the
cluster merges) and refuses to touch anything mapped otherwise than declared: a changed type is a
reindex, and no command should pretend it is not.

The index has to exist before the first record: **a write to a missing index is refused**
(`IndexNotFoundException`, handled by `on_failure` like any other failure) rather than left to
Elasticsearch, which would create the index on the fly with a guessed mapping — `loggedAt` as
`text`, so every read fails; `changes` indexed field by field, so later documents are rejected
over type conflicts. The check costs one `HEAD` per index per process. The mapping the bundle
creates is `dynamic: false`: a field nobody declared is stored with the document but not
indexed, and `audit:check` reports it, as it does a field mapped with another type than the one
declared (the sign of an index Elasticsearch created on its own — the fix is a reindex). The
comparison goes past the type (**since 0.11**): the options behind it and the fields inside an
object are checked too, so a `date` whose `format` drifted — an index that refuses every record
the writer sends — or an enricher's nested field that was never mapped is reported by its path
(`context.ip is keyword, expected ip`) instead of passing as healthy.

**The check is a courtesy, not the guarantee — set the guarantee on the cluster.** Between the
`HEAD` that says the index is there and the write that follows it, the index can be dropped or
rolled over, and Elasticsearch will then create it from the write with a guessed mapping. No
client-side check can close that window; only the cluster can, and it takes one setting:

```yaml
# elasticsearch.yml — or PUT _cluster/settings {"persistent": {"action.auto_create_index": "-audit_*,+*"}}
action.auto_create_index: "-audit_*,+*"
```

With that in place a write to a missing index is a clean `IndexNotFoundException` whatever the
bundle remembers, and a guessed mapping cannot happen at all. An index template does the same job
for one pattern, without touching a cluster-wide setting — verified against Elasticsearch 9, where
the write then fails with *"composable template forbids index auto creation"*:

```json
PUT _index_template/audit
{ "index_patterns": ["audit_*"], "allow_auto_create": false }
```

**Treat this as part of installing the bundle**, not as hardening to get to later —
the existence check exists to give a good error, not to be the only thing standing between you
and an index Elasticsearch invented.

## Recording an action

```php
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;

final class CallController
{
    public function __construct(private AuditWriter $audit) {}

    public function place(Order $order, Request $request): Response
    {
        // ...
        $this->audit->record(
            objectType: 'order',
            objectId: $order->getId(),
            event: 'order_call',
            changes: ['phone' => $phone, 'duration' => new Change(null, 42)],
        );
    }
}
```

Every record stores the object type and id, the event, a UTC timestamp, the **actor** and the
**changes**. The actor is resolved for you — the authenticated user's identifier when there is
one, `actor.fallback` otherwise — and `changes` can hold `Change` objects (`old`/`new` pairs,
which a history screen can render as a diff) or any JSON-serialisable data you want to show
alongside the event.

Timestamps and the actor can be given explicitly, e.g. when importing history:

```php
$this->audit->record('order', 42, 'update', at: $importedAt, actor: 'legacy-import');
```

### Events are just strings

`AuditEvent::CREATE`, `UPDATE` and `REMOVE` are what the Doctrine integration emits. Anything
else is up to you: `login_failed`, `order_call`, `google_sheet_shared`. Keep them stable — they
are what you filter the history by.

## Auditing Doctrine entities

Declare what to record and the bundle listens to `flush()`: a `create` record when the entity is
inserted, an `update` with `old`/`new` for every audited field that changed, a `remove` with the
identifier the entity had. Two ways to declare, treated identically:

```php
use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;

#[ORM\Entity]
#[Auditable(type: 'article', alwaysRecord: ['status'])]
class Article
{
    #[ORM\Column, AuditField]
    private string $title;

    #[ORM\Column, AuditField]
    private string $status = 'draft';

    #[ORM\Column]
    private int $views = 0;                       // not audited: changes here record nothing

    #[ORM\ManyToOne, AuditField(represent: 'getName')]
    private ?Author $author = null;               // stored as the author's name, not the object

    #[ORM\ManyToMany(targetEntity: Tag::class), AuditField(represent: 'getLabel')]
    private Collection $tags;                     // stored as ['php'] → ['php', 'elasticsearch']
}
```

```php
use Borsche\ElasticsearchAuditBundle\Contract\AuditableInterface;

class Article implements AuditableInterface
{
    public function getAuditObjectType(): string { return 'article'; }

    public function getAuditedFields(): array
    {
        return [
            'title' => null,                                    // scalar
            'status' => null,
            'author' => fn (Author $a) => $a->getName(),        // to-one, through a representer
            'tags' => fn (Tag $t) => $t->getLabel(),            // to-many, each element represented
        ];
    }

    public function getAlwaysRecordedFields(): array { return ['status']; }
}
```

Use the attributes when a static declaration reads well; use the interface when you need a
closure (attributes can only name a method on the related object) or the field list depends
on state.

What gets recorded, and what deliberately does not:

- **Associations are stored through their representer** — a name, an id, a small array. Storing
  the related entity itself is neither possible nor useful in a history.
- **Two dates for the same instant are not a change.** Doctrine compares objects by identity, so
  re-assigning `new DateTimeImmutable('2026-08-26 10:00')` looks like a change to it; the record
  skips it.
- **`alwaysRecord` fields** appear on every update as `old == new`, so each history line is
  readable on its own (the order's status next to the field that changed). They give context to
  a change; they do not make one — an update that touched only unaudited fields records nothing
  (`doctrine.skip_empty_updates`, default `true`).
- **Collections** are recorded as the snapshot against the current contents, only when dirty. A
  lazy collection is loaded first, so the `old` side is real, not empty.
- **Removes carry no changes**, only the identifier — which is captured in `preRemove`, while the
  entity still has one.

Values are read through Doctrine's metadata, so entities need no getters. Identifiers may be
ints, strings, `Stringable` (Uuid, Ulid) or backed enums; composite keys are joined with `|`.

```yaml
borsche_elasticsearch_audit:
  doctrine:
    enabled: auto              # auto (default): listen when doctrine/orm is installed;
                               # false drops the listener and keeps the writer; true
                               # requires doctrine/orm and fails the boot without it (since 0.11)
    skip_empty_updates: true
    connection: default        # the Doctrine connection the listener attaches to
```

Records are built during `flush()`, while Doctrine still knows the change sets, and **written
once the transaction has committed** (`postFlush`). A flush that fails half-way leaves no trace
in the history, and a rolled-back order never shows up as created. With the default
`on_failure: log` an unreachable cluster costs you a history entry, never the transaction.

### The transaction boundary, exactly

The guarantee is about **the flush's own transaction**, and this is the whole of it:

| What happens | What the history says |
|---|---|
| `flush()` commits | the records are written, after the commit |
| `flush()` fails and rolls back | nothing is written |
| an **outer** transaction around the flush rolls back | **the records were already written** |

The third row is the limitation, stated plainly rather than implied: `postFlush` fires when the
inner `flush()` finishes, and no later event tells the listener that a wider transaction ended,
so a rollback of `wrapInTransaction()` (or a hand-rolled `beginTransaction()`) leaves the index
describing a state the database rolled back. There is an executable test that asserts exactly
this — it exists to fail the day the behaviour changes, not to bless it.

When the application owns the wider transaction, close the gap with a frame — the same
`AuditFrame` that coalesces, used here for its other property, that nothing leaves until the
frame does:

```php
$this->frame->begin();
$this->em->getConnection()->beginTransaction();

try {
    // ... several flushes ...
    $this->em->getConnection()->commit();
    $this->frame->end();       // committed: now the history may speak
} catch (\Throwable $e) {
    $this->em->getConnection()->rollBack();
    $this->frame->reset();     // rolled back: drop what never happened
    throw $e;
}
```

`end()` writes what the frame held; `reset()` drops it. Both recipes are covered by tests. What
this does **not** give you is atomicity between the database and Elasticsearch — nothing can,
short of a transactional outbox, which is **post-1.0 work** and not present today. A cluster
that is unreachable at `end()` still costs a history entry under `on_failure: log`.

> **With `on_failure: throw`, read this twice.** The `WriteFailedException` surfaces from
> `flush()` *after* the commit: the data **is** in the database, the history entry is not. Code
> that catches exceptions around `flush()` and treats them as "the save failed" — showing an
> error, retrying, rolling back something else — will be wrong about that. Catch
> `WriteFailedException` separately, or keep `log` and alert on `RecordFailedEvent` instead.

A mistake in an audit declaration — `alwaysRecord` naming a field that is not audited, an
association without a representer — is handled by the same policy: logged and skipped by
default, fatal to the flush with `throw`. Composite identifiers are joined with `|`; an
identifier that is itself an entity is represented by that entity's identifier.

### Changes inside the elements of a collection

A to-many field records which elements it has. What changes *inside* an element — a line's
quantity — is a change to the element, and Doctrine reports it as such: the collection is not
dirty, and the owner's history never mentions it. Ask for it and it is recorded (**since 0.9**):

```php
#[ORM\OneToMany(mappedBy: 'shipment', targetEntity: ShipmentLine::class)]
#[AuditField(represent: 'getLabel', trackElements: ['quantity'])]   // or trackElements: true
private Collection $lines;
```

```php
class Shipment implements AuditableInterface, TracksCollectionElementsInterface
{
    public function getTrackedCollections(): array { return ['lines' => ['quantity']]; }
}
```

The record then carries one entry per element, keyed by its identifier:

```json
"changes": {
  "lines.42.quantity": { "old": 1, "new": 7 },
  "lines.51":          { "old": null, "new": "bolt" },
  "lines.17":          { "old": "gadget", "new": null }
}
```

— a field that changed, an element that appeared, an element that went. Which is also how a
tracked **inverse** collection reports what it gained and lost at all: the inverse side is never
dirty, so without tracking a line added to it leaves no trace (see Limitations).

Three things worth knowing:

- **It costs a query only when something changed.** The unit of work already knows which entities
  this flush touches; a collection whose elements nobody touched is never loaded, and never asked
  about.
- **An owner nobody touched still gets a record.** Doctrine raises no event for an entity it has
  nothing to `UPDATE`, so that record is built after the flush from what was collected during it.
- **`trackElements: true` takes every field of the element that changed**, so name the fields
  unless you mean all of them. Redaction understands these keys: a rule for `password` covers
  `lines.42.password`. Associations of an element are left out — representing one needs a
  callable, and an element has nowhere to declare it.

## One operation, one record

Some operations save several times on their way to their result. A stock movement in the CRM
this bundle came from reverses the old state in one `flush()` and applies the new one in the
next; each flush fires `postUpdate`, so the history showed a pair of mirror-image records —
`1000 → 1040`, then `1040 → 1000` — for an edit that changed nothing, and intermediate values
(negative stock, half-applied totals) nobody ever meant to be visible.

Open a **frame** around the operation and the history gets one record per object with the
values before and after the whole thing:

```php
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;

final class MoveStockHandler
{
    public function __construct(private AuditFrame $frame, private StockService $stock) {}

    public function __invoke(MoveStock $command): void
    {
        $this->frame->coalesce(fn () => $this->stock->move($command));
    }
}
```

While the frame is open, records are held instead of written and merged per object: the
**earliest `old`** and the **latest `new`** of every field survive. When the outermost frame
closes:

- a field that moved and came back is dropped — `1000 → 1040 → 1000` leaves nothing;
- `1000 → 1040 → 995` becomes one record, `1000 → 995`;
- a field whose two sides were the same in every step never moved: that is a context field
  (`alwaysRecord`), and it stays, so a coalesced record reads like any other;
- an update in which nothing moved is not written at all — context alone is not history;
- a `create` followed by updates stays one `create`, with the final values;
- a `remove` is terminal: what was held for that object goes out first, then the remove.

The record keeps the timestamp, actor and id of the **first** step — the operation began there —
and the attributes of the last one. Enrichers run once per step, when the record enters the
frame, not again when it leaves.

Frames nest — a product move inside an order status change — and only the outermost writes.
`begin()`/`end()` are there for code that cannot wrap a closure; keep them in a `try`/`finally`.
`write($record, immediately: true)` bypasses an open frame.

### What counts as "unchanged"

Two questions are asked about every field. *Did it move?* — plainly, whether the two sides
differ at all (dates by instant, arrays by value, everything else strictly); a field that never
moved is context and is kept. *Did it end where it started?* — asked about the merged pair, and
this is where the application gets a say. Some data disagrees with a strict answer: for a stock
quantity, `null`, `''` and `0` are the same thing. Name those fields and the bundle compares
them as numbers:

```yaml
borsche_elasticsearch_audit:
  coalescing:
    enabled: true             # false: frames still work, they just hold nothing
    numeric_fields: [quantity, reserve, 'stock.onWay']   # a field on every type, or on one
    object_types: []          # hold every type while a frame is open; or list the ones to coalesce
    max_held: 10000           # safety valve: a frame holding more objects releases what it has
    on_overflow: release      # or "throw" (since 0.10): refuse the operation instead of coalescing less
```

A value that is neither a number nor "nothing" is left alone — two different words must not
look equal — so `numeric_fields` is safe on a column that sometimes holds text.

Anything else — case-insensitive strings, rounding — is a `ValueComparatorInterface` you
register; it is asked first and may defer with `null`.

**Since 0.9 the same comparators answer the first question too**, so a rule is written once and
holds wherever it matters. The case that made this necessary: a `datetime_timezone` column
compared by instant reports a change whenever the zone moves, and the record then shows two
timestamps that read identically — a comparator that compares by wall clock stops the record
from being written at all, instead of leaving it to be filtered out afterwards.

### Frames in workers

The frame lives in a service, and a worker shares services across messages. A handler that
throws between `begin()` and `end()` — or forgets `end()` — would leave the frame open and
swallow the next message's history. `FrameResetMiddleware` closes that door: after every
message it closes whatever is still open and **writes** what it held, with a warning that names
the missing `try`/`finally`. Written, not dropped: a record only reaches the frame once the save
behind it went through, so those changes are in the database whether the handler finished or
not — and a gap in an audit log is harder to notice than a record too many. For the rare
operation whose records must not exist, `$frame->reset()` drops them on purpose.

```yaml
framework:
  messenger:
    buses:
      messenger.bus.default:
        middleware:
          - Borsche\ElasticsearchAuditBundle\Coalescing\Messenger\FrameResetMiddleware
```

With `on_failure: throw`, a write that fails surfaces from `end()` (or `coalesce()`), not from
the `flush()` that produced the record.

## Reading the history

```php
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;

$page = $this->reader->find(
    AuditQuery::for('order')
        ->withObjectId(42)                          // one object's history...
        ->withEvents('update', 'order_call')        // ...or by event
        ->withActors('7')                           // who
        ->between($since, $until)                   // when (either side may be null)
        ->where('salesType', 3)                     // any attribute an enricher added
        ->whereIn('warehouseId', [1, 2])
        ->whereExists('orderCountry')               // has the attribute (since 0.12)
        ->whereNotExists('legacyRef')               // does not have it — what a backfill looks for
        ->whereBetween('total', 100, 500)           // inclusive range; either bound may be null
        ->page(2, 50)                               // newest first by default; ->oldestFirst()
);

$page->entries;          // list<AuditEntry>: id, objectType, objectId, event, loggedAt, actor, changes, attributes, extra
$page->total;            // exact
$page->totalPages();
$page->toArray();        // ['items' => [...], 'pagination' => [currentPage, limit, total, totalPages, nextCursor]]
```

`AuditQuery::any()` reads across object types — every index the configuration routes to, in one
multi-index search, so a type that lives in its own index is not left out. Every filter is an exact
match on an indexed field, so queries stay fast at millions of records; a filter on a base field
uses its named method, an attribute uses `where()`.

Hydration is deliberately **lenient**: writing is strict — the mapping refuses what does not fit —
but reading meets whatever the index actually holds (documents written by another tool, a mangling
reindex, a legacy format), and one bad document must not turn a page of nineteen good ones into an
exception. A missing field reads as its empty value; a `loggedAt` nobody can parse reads as the
epoch (**since 0.11**) — present and visibly wrong rather than in the way.

### Two ways to page

`page(n, limit)` is the familiar one, and it is bounded twice: by how large a page may be
(`reader.max_limit`, default 1000) and by how deep `from + size` may reach
(`reader.max_result_window`, default 10 000 — Elasticsearch's own default). Both settings exist
**since 0.8**; before that the same two numbers were constants on `AuditQuery`, and a page past
them was refused with no way to say otherwise. The reader refuses a
query beyond either with an `InvalidQueryException` naming the setting, rather than letting the
cluster answer 400.

Both are properties of your deployment, not of the bundle. A screen that shows five thousand rows
at a time needs the first raised; pages beyond the window need the second raised **together with
the cluster's** `index.max_result_window`, because a `from` deeper than that is a queue of
`from + size` hits held on every shard. The two windows drifting apart is a bug that surfaces on
a deep page in production, so `audit:check` compares them (**since 0.12**): an index whose own
window is below `reader.max_result_window` fails the check by name.

```yaml
borsche_elasticsearch_audit:
  reader:
    max_limit: 10000          # a page of ten thousand rows
    max_result_window: 50000  # five such pages; raise index.max_result_window to match
```

Reading across object types with `any()` and a cursor also sorts by the index name (**since
0.10**), because a timestamp and a record id are unique inside one index and not between several
— two records sharing both used to make `search_after` step over one of them.

A page says how far the numbers go, so a screen can tell "pages there are" from "pages you can
ask for": `$page->totalPages()` and `$page->maxReachablePage()` (**since 0.8**), the second bounded
by the window. `$page->hasMore()` answers whether to draw a "next" at all — by arithmetic when the
page came from `page()`, and from a full batch when it came from a cursor.

For deep paging, "load more" buttons and exports, page by cursor instead — it has no ceiling:

```php
$page = $this->reader->find($query->page(1, 100));
// ... later, for the next page:
$next = $this->reader->find($query->after($page->nextCursor()));
```

`nextCursor()` is null once nothing follows, so a "load more" never leads to an empty page. Across
an HTTP boundary hand out the string form instead — `$page->nextCursorToken()` (**since 0.8**), which
is what `toArray()` puts in `pagination.nextCursor` — and continue with `$query->afterToken($token)`.
The token is base64url, so it needs no escaping in a query string, and it is opaque on purpose: a
client hands it back unread, which leaves what is inside it free to change. A token that comes back
damaged is an `InvalidQueryException`, not a silently wrong page.

The cursor is the sort value of the last entry: `loggedAt` plus the record's id, a time-ordered
UUID (millisecond precision), which breaks ties in time order and — unlike Elasticsearch's `_doc` — does not move when
segments merge. It stays valid while new records arrive. To stream everything — an XLSX export,
a backfill — let the reader do the cursor loop:

```php
foreach ($this->reader->iterate(AuditQuery::for('order')->since($start)->oldestFirst(), batchSize: 500) as $entry) {
    $sheet->addRow([$entry->loggedAt->format('Y-m-d H:i'), $entry->actor, $entry->event, json_encode($entry->changes)]);
}
```

> **A cursor needs the record id to be there.** The sort is `loggedAt` plus the record id, and
> the id is what makes the pair unique — which is what stops `search_after` from stepping over a
> record. Documents written by an older tool that never stored `id` sort as
> `[timestamp, null]`, and several of them sharing a timestamp are indistinguishable to the
> cursor: Elasticsearch may then skip or repeat one. `unmapped_type` keeps such a query running;
> it cannot make the tuple unique. If you are reading an index written before this bundle,
> backfill `id` from each document's `_id` once, and cursor paging is exact again. A consistent
> `iterate()` is unaffected — its point in time adds `_shard_doc`, which is unique inside the
> view.

`iterate()` starts a traversal of its own and refuses a query that carries a page or a cursor
(**since 0.10**): the point in time it opens is not the one those sort values came from, and
`_shard_doc` means nothing inside another view. To resume where an export stopped, narrow the
query — by `since()`, say — and start again.

`iterate()` reads from a **point in time**: the index as it was when the export started. Records
written while it runs are not in it, and no record shows up twice because a segment merged
underneath — the two ways a long walk over a live index goes wrong. The view is opened before the
first batch, kept alive by every search for `reader.point_in_time_keep_alive` (default `1m`), and
closed however the export ends, a `break` included. If a consumer of one batch takes longer than
that, raise the keep-alive; if you want the live index instead — a tail that should pick up what
arrives — pass `consistent: false`.

### Filters your application defines

A history screen filters by things the bundle knows nothing about: operators of a country, the
current user's own team, what the viewer is allowed to see. Carry such parameters as **options**
and turn them into real filters in a `QueryExtensionInterface` — it speaks `AuditQuery`, never
Elasticsearch, and runs on every read:

```php
use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;

final class CountryFilter implements QueryExtensionInterface
{
    public function __construct(private UserRepository $users) {}

    public function extend(AuditQuery $query): AuditQuery
    {
        if (!$query->hasOption('country')) {
            return $query;
        }

        $ids = $this->users->idsInCountry($query->option('country'));

        return $ids === [] ? $query->matchNothing() : $query->narrowActors(...$ids);
    }
}

// in the controller:
$query = AuditQuery::for('order')->withOption('country', $request->query->get('country'));
```

Because extensions see every query, they are also the place for **visibility rules** — restrict
to the actors the current user may see, and no endpoint can forget to.

An extension almost always means "of what was asked for, only what this viewer may see", and that
is **`narrow*()`, not `with*()`** (both **since 0.12**). `with*()` and `where*()` REPLACE a filter
of the same name — they build the query — so a visibility rule written as
`withObjectIds(...$visible)` throws away the id the client asked about and silently *widens* the
result: the one mistake a boundary must not make. `narrowObjectIds()`, `narrowActors()` and
`narrowIn()` INTERSECT with whatever the query already carries, and an intersection that comes up
empty becomes `matchNothing()`: the reader answers with an empty page and **no request at all** —
no more made-up ids (`'-'`, `-1`) typed to fit the field's mapping. `matchNothing()` is sticky by
design: once one extension has said "none of it", no later filter in the chain can widen the
answer back open.

### Aggregations and everything else: raw()

"Who changed this object most", "events by type over a month" — ordinary questions a history
answers with **aggregations**, which `find()` cannot say. `AuditReader::raw($query, $body)`
(**since 0.12**) is the escape hatch that does not escape the guarantees: the QueryExtensions
run, the query's filters become the request's boundary (a `query` inside `$body` is kept, nested
so it can narrow but never widen), and the index is the one the query routes to. The body is
otherwise yours — `aggs`, `size: 0`, whatever the endpoint needs — and the response comes back
raw. Without it, the first aggregation reaches for the bare client and quietly loses the
visibility narrowing.

```php
$response = $this->reader->raw(
    AuditQuery::for('order')->withObjectId(42),
    ['size' => 0, 'aggs' => ['actors' => ['terms' => ['field' => 'source']]]],
);

$buckets = $response['aggregations']['actors']['buckets'] ?? [];   // ?? [] — see below
```

Read the aggregations defensively. When an extension closed the query down to
`matchNothing()`, the reader answers **without a request**, and that answer has hits and no
`aggregations` key at all — an empty bucket list cannot be invented without knowing which
aggregation was asked for. Reaching straight for `$response['aggregations'][...]` breaks exactly
when a viewer is allowed to see nothing, which is the case least likely to be tested.

### Making a page readable

Records store identifiers. A `RecordDecoratorInterface` receives the whole page and attaches what
a screen wants — one query per entity type, not one per line:

```php
use Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface;

final class ActorNames implements RecordDecoratorInterface
{
    public function __construct(private UserRepository $users) {}

    public function decorate(array $entries): array
    {
        $users = $this->users->findIndexedByIds(array_unique(array_filter(array_map(fn ($e) => $e->actor, $entries))));

        return array_map(
            fn (AuditEntry $e) => $e->withExtra(['actor' => $users[$e->actor] ?? null ? ['id' => $e->actor, 'name' => $users[$e->actor]->getName()] : null]),
            $entries,
        );
    }
}
```

`extra` is never stored — it is computed on read, so a renamed user shows the current name. When
what needs to be readable is the change itself — a permission key that should read as its name, a
status code as its label — `withChanges()` replaces them (**since 0.9**); `withExtra()` is for
what the record does not have, `withChanges()` for what it has in a form nobody wants to read.

Both extensions and decorators are picked up automatically when they are registered as services,
through autoconfiguration. A service that is not autoconfigured needs the tag by hand:
`borsche_elasticsearch_audit.enricher`, `.decorator`, `.query_extension`, `.actor_resolver`,
`.value_comparator`. Autowiring an `iterable` of them into a service of your own is not something
Symfony does on its own — ask for the tag: `#[TaggedIterator('borsche_elasticsearch_audit.decorator')]`.

### An endpoint

```php
#[Route('/api/history', methods: ['GET'])]
public function history(Request $request, AuditReader $reader): JsonResponse
{
    $query = AuditQuery::for($request->query->getString('objectType', 'order'))
        ->page($request->query->getInt('page', 1), min(100, $request->query->getInt('limit', 20)));

    if ($id = $request->query->get('objectId')) {
        $query = $query->withObjectId($id);
    }

    if ($cursor = $request->query->getString('cursor')) {
        $query = $query->afterToken($cursor); // page numbers no longer apply
    }

    try {
        return $this->json($reader->find($query)->toArray());
    } catch (InvalidQueryException $e) {
        return $this->json(['error' => $e->getMessage()], 400);
    }
}
```

Unlike the writer, the reader does not swallow failures: an unreachable cluster is a
`TransportUnavailableException`, a missing index an `IndexNotFoundException` — map them to the
HTTP status you want.

## Reacting to records

Two PSR-14 events, dispatched when an event dispatcher is available:

```php
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;

#[AsEventListener]
final class ShapeTheTrail
{
    public function __invoke(RecordCreatedEvent $event): void
    {
        $record = $event->getRecord();

        if ($record->event === 'heartbeat') {
            $event->veto();          // not written, not an error
        }

        if ($record->objectType === 'order' && !$this->tenants->auditsDetails($record)) {
            $event->setRecord($record->withChanges([]));   // this tenant keeps the fact, not the diff
        }
    }
}

#[AsEventListener]
final class CountAuditFailures
{
    public function __invoke(RecordFailedEvent $event): void
    {
        $this->metrics->increment('audit.write_failed', ['type' => $event->record->objectType]);
    }
}
```

`RecordCreatedEvent` fires after the record is complete, enriched and redacted, right before it
is sent — inside a frame, once for the coalesced record; `RecordFailedEvent` fires on every failed
write, whatever the failure policy. Both see the redacted record, so a listener can queue or log
it without a second thought. (Fields that must never be stored belong in `redact.fields`, not in a
listener — see «Audit records and personal data».)

### Who did it

The bundle asks each registered `ActorResolverInterface` in turn and takes the first answer.
**Your resolvers are asked before the built-in one**, which is registered at priority `-100`
precisely so that it cannot get in their way: the recipe below for keeping an email address out
of the index — a resolver returning the internal id — only works because the application answers
first. Raise a resolver's priority above `-100` to sit behind another one of your own; the
security token is what answers when nobody else does.

With `symfony/security-core` installed and no resolver of your own, the actor is the security
token's `getUserIdentifier()`. Under `switch_user` that is the **impersonating** user — the
administrator who acted, not the account they were looking at. Work that runs without a token —
message handlers, console commands — usually knows who it is acting for; register a resolver and
it is picked up automatically:

```php
use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;

final class ImpersonationActorResolver implements ActorResolverInterface
{
    public function __construct(private ActingUserHolder $holder) {}

    public function resolve(): ?string
    {
        return $this->holder->currentUserId();  // null when unknown → next resolver, then the fallback
    }
}
```

## Adding what only your application knows

A record carries the generic facts. Anything you will want to **filter the history by** later —
the sales channel of an order, the warehouse of a stock movement, the tenant — is an attribute
the application adds at write time through an enricher. The enricher also declares the mapping
of the fields it adds, so `audit:index:create` knows their types and `audit:check` notices when
an index predates the enricher:

```php
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

final class OrderAttributesEnricher implements AuditEnricherInterface
{
    public function __construct(private OrderRepository $orders) {}

    public function supports(AuditRecord $record): bool
    {
        return $record->objectType === 'order';
    }

    public function enrich(AuditRecord $record): AuditRecord
    {
        $order = $this->orders->find($record->objectId);

        return $record->withAttributes(['salesType' => $order?->getOffer()?->getSalesType()?->getId()]);
    }

    public function mapping(): array
    {
        return ['salesType' => ['type' => 'integer']];
    }
}
```

**When an enricher runs matters.** An `AuditEnricherInterface` runs the moment a record is
created — before a frame merges it with the other saves of the same operation. That is right for
a fact about the step (which request, who was authenticated) and wrong for a fact about the
outcome: a quantity that goes 1000 → 1040 → 1000 ends up as no change at all, while an enricher
that ran on the last step has already written `quantityChanged: true`, and the record then
contradicts itself. For those, implement `MergedRecordEnricherInterface` (**since 0.9**) — the
same three methods, run once per record immediately before it is written, on whatever the frame
merged, and on the record itself when no frame was open:

```php
final class QuantityChanged implements MergedRecordEnricherInterface
{
    public function supports(AuditRecord $record): bool { return $record->objectType === 'stock'; }

    public function enrich(AuditRecord $record): AuditRecord
    {
        return $record->withAttributes(['quantityChanged' => array_key_exists('quantity', $record->changes)]);
    }

    public function mapping(): array { return ['quantityChanged' => ['type' => 'boolean']]; }
}
```

`withAttributes()` replaces what is already there; `withAddedAttributes()` (**since 0.9**) fills
gaps only, for an enricher that defers to whatever set the value first.

**Where the record came from.** `$record->origin` (**since 0.9**) is `AuditOrigin::Doctrine` for
what the listener built, `Manual` for what the application handed to the writer, and `Mixed` for a
record a frame merged out of both — so an enricher that should only touch one of them can ask
instead of guessing from the actor. It is not stored: it is a fact about the write, not about the
history.

Attributes land beside `objectType`, `event`, ... at the top level of the document, which is
what makes them filterable. `changes` is deliberately **not indexed** (`enabled: false`): its
shape differs per object type and per field, and indexing it would blow the mapping up over time.

## Writing asynchronously

```yaml
borsche_elasticsearch_audit:
  transport: messenger
  message_bus: messenger.default_bus   # the default
```

```yaml
# config/packages/messenger.yaml
framework:
  messenger:
    routing:
      'Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord': async
```

The request now only pays for the dispatch; a worker writes the document. The message carries
plain arrays, so it serialises with any Messenger serializer and survives a deploy that changes
the model. Failures in the worker propagate on purpose — Messenger's retry strategy is the right
place to deal with a flaky cluster — and a retry is safe: the document is written under the
record's id, so a redelivery after a timeout overwrites the same document instead of adding a
second one.

A record that must be visible before the request ends can bypass the queue:

```php
$this->audit->write($record, immediately: true);
```

## When Elasticsearch is down

By default (`on_failure: log`) a failed write is logged at `error` level with the record's type,
id and event, and the caller carries on. An audit log must never take the business operation
down with it — losing one history entry is better than losing the order that entry was about.

Set `on_failure: throw` when the opposite holds (compliance logs): the failure surfaces as a
`WriteFailedException` carrying the record.

Everything the bundle throws implements `Borsche\ElasticsearchAuditBundle\Exception\AuditException`:
`NotConfiguredException`, `IndexNotFoundException`, `TransportUnavailableException` (the cluster
did not answer, or answered 429 or 503 — backpressure is not a refusal, and a write that met it is
retried), `RequestRejectedException` (it answered and refused — a document that does not
fit the mapping, missing permissions; retrying will not help), `InvalidQueryException`
(a query the bundle or Elasticsearch rejected), `WriteFailedException`.

## The document

```json
{
  "id": "01a03df1-0200-7c3e-9a1b-5f6d7e8f9a0b",
  "objectType": "order",
  "objectId": 42,
  "event": "update",
  "loggedAt": "2026-08-26 12:00:00",
  "source": "7",
  "changes": { "status": { "old": "new", "new": "paid" } },
  "salesType": 3
}
```

`id` is the document's `_id` as well: a UUID v7 built from `loggedAt`, so ids sort in time order
(pass your own with `withId()` when you have a natural one). `source` holds the actor. `loggedAt`
is always UTC in `yyyy-MM-dd HH:mm:ss`. Everything after `changes` is an attribute added by an
enricher.

## Roadmap

| Release | Adds |
|---|---|
| 0.1 | Recording arbitrary actions, sync and Messenger transports, enrichers, index commands — done |
| 0.2 | Automatic Doctrine entity auditing (`AuditableInterface`, `#[Auditable]`), PSR-14 events — done |
| 0.3 | Reading: `AuditQuery` / `AuditReader` with filters, pagination, `search_after`, decorators — done |
| 0.4 | Coalescing many small changes into one record — done |
| 0.5 | Redaction, PII and retention docs, ILM recipe, level 8 + strict rules, coverage floor — done |
| 0.6 | Bulk indexing, point-in-time exports |
| 1.0 | The API settles |

## Audit records and personal data

An audit log is the one place in an application that keeps **every version of every value, on
purpose, for years**. That is what makes it useful and what makes it the first thing a privacy
review asks about. None of the following is legal advice; it is what the bundle gives you to
work with.

**Some values must never be stored.** Name them and they are replaced before anything leaves the
process — the fact that the field changed is kept, the value is not:

```yaml
borsche_elasticsearch_audit:
  redact:
    fields: [password, token, 'customer.cardNumber']   # plain or scoped as objectType.field
    placeholder: '***'
```

A side that was `null` or empty stays as it was, so "had no password, now has one" is still
readable (`false` and `0` are values and are hidden like any other). Redaction is applied at the
moment a record leaves the writer — after your enrichers, after a frame has merged its steps, and
on the failure path — so it also covers what enrichers put into `changes`, a frame still sees the
real values and records a password change as a change, and neither `RecordCreatedEvent`,
`RecordFailedEvent` nor `WriteFailedException` carries the value. It covers the **top-level fields
of `changes`** and the **attributes** by name (**since 0.9.3**; a redacted attribute is not written
at all rather than masked, because an attribute is a mapped field and `'***'` where the mapping says
integer would have Elasticsearch refuse the whole document). A secret inside a free-form array still
has to be kept out by the code that puts it there.

For **tracked collection elements** the rule names a field, not a path: `password` also covers
`lines.42.password`, and a rule naming the collection covers everything reached through it —
`lines` hides the membership keys (`lines.42`: an element came or went, but not what it was) and
every field inside (**since 0.11**). Mind the scope: element changes are recorded **on the owner**,
so a scoped rule names the owner's object type — `shipment.price` covers `lines.42.price` on a
shipment's record; `line.price` covers nothing, because no record has `line` as its object type.

A listener may replace the record on
`RecordCreatedEvent`, and what it hands back is redacted again, so a listener that reaches for the
entity a second time cannot undo the policy. For anything conditional — redact only for this tenant, only outside
the office — listen to `RecordCreatedEvent` and rewrite or `veto()` the record there.

**What redaction is not.** It runs when a record leaves the writer, and that is the whole of it:

- **It does not reach what is already written.** Adding `user.email` to the rules today cleans
  tomorrow's records and leaves last year's ten million untouched. A request to erase existing
  data is a reindex or a delete-by-query against the index — the bundle has no eraser, and this
  is a deliberate gap rather than an oversight: rewriting history from inside the thing that
  records history is not a power the writer should have. Plan it as an operational procedure.
- **It cannot reach the actor.** `source` is a base field, chosen when the record is built, and a
  rule naming it is refused (**since 1.0**) rather than accepted and ignored. If your users are
  identified by an email address, that address is in an indexed field on every record they ever
  touched — return an internal id from an `ActorResolverInterface` instead, as above. The same
  applies to `objectId`: it is an identifier, not a place for a name or a phone number.
- **`dynamic: false` is not a privacy boundary.** An attribute nobody declared is not *indexed*,
  and it is still *stored* in `_source` — as is everything inside `changes`, which is stored with
  indexing disabled. "Not searchable" and "not kept" are different things.
- **It covers the top level of `changes` and the attributes, by name.** A value nested inside a
  free-form array (`['profile' => ['password' => …]]`) is not seen by a rule naming `password`:
  the rule matches the field, which here is `profile`. Keep secrets out of free-form payloads, or
  flatten them into fields the rules can name.

**Who the actor is, is a choice.** By default the actor is `getUserIdentifier()`, and in many
applications that is an **email address** — which means every record carries personal data in an
indexed field. Register an `ActorResolverInterface` that returns the internal id instead:

```php
public function resolve(): ?string
{
    $user = $this->tokenStorage->getToken()?->getUser();

    return $user instanceof User ? (string) $user->getId() : null;   // an id, not an email
}
```

**Retention: decide how long, and let Elasticsearch enforce it.** With an ILM policy the cluster
deletes what is past its time without anybody remembering to (see the next section). Without ILM,
a scheduled command is enough, since `loggedAt` is indexed:

```bash
curl -X POST "$ES/audit_log/_delete_by_query?conflicts=proceed" -H 'Content-Type: application/json' -d'
{"query": {"range": {"loggedAt": {"lt": "2024-01-01 00:00:00"}}}}'
```

**Erasure requests.** A person appears in the trail in up to three places: `source` (they acted),
`objectId` (they were the object — an audited `User`), and values inside `changes`. `changes` is
stored but not indexed, so you cannot search by it — which is why the two indexed fields are the
handles you use:

```bash
# what the trail holds about them
curl "$ES/audit_log/_search" -H 'Content-Type: application/json' -d'
{"query": {"bool": {"should": [
  {"term": {"source": "4711"}},
  {"bool": {"filter": [{"term": {"objectType": "user"}}, {"term": {"objectId": "4711"}}]}}
]}}}'

# pseudonymise rather than delete, when the trail itself has to stay
curl -X POST "$ES/audit_log/_update_by_query?conflicts=proceed" -H 'Content-Type: application/json' -d'
{"query": {"term": {"source": "4711"}},
 "script": {"source": "ctx._source.source = params.pseudonym; ctx._source.changes = new HashMap();",
            "params": {"pseudonym": "erased:4711"}}}'
```

Deleting audit records can collide with other obligations (financial trails, security incident
history). Pseudonymising the actor and dropping `changes` keeps "something happened, and when"
while removing the person — usually the better trade, but that is a decision for your case.

**What not to put in `changes` in the first place.** Anything you would not want in a JSON
document that is copied into every backup and replica: secrets, full documents, base64 blobs.
Enrich with an id and resolve it on read through a `RecordDecorator` instead — decorated data is
computed, never stored.

## Index mapping and rotation

An audit index grows forever, so plan for rotation before the first million records. The bundle
writes to whatever name `indices.default` (or a routing entry) holds, and **that name may be a
write alias** — which is all ILM needs:

```bash
# 1. the policy: roll over daily or at 50 GB, delete after a year
curl -X PUT "$ES/_ilm/policy/audit" -H 'Content-Type: application/json' -d'
{"policy": {"phases": {
  "hot": {"actions": {"rollover": {"max_primary_shard_size": "50gb", "max_age": "1d"}}},
  "delete": {"min_age": "365d", "actions": {"delete": {}}}}}}'

# 2. the template, with the mapping this bundle expects
bin/console audit:index:create --dump > mapping.json    # settings + mappings, enricher fields included
curl -X PUT "$ES/_index_template/audit" -H 'Content-Type: application/json' -d'
{"index_patterns": ["audit_log-*"], "template": {
  "settings": {"index.lifecycle.name": "audit", "index.lifecycle.rollover_alias": "audit_log"},
  "mappings": { … from mapping.json … }}}'

# 3. the first index, carrying the write alias the bundle and ILM both use
curl -X PUT "$ES/audit_log-000001" -H 'Content-Type: application/json' -d'
{"aliases": {"audit_log": {"is_write_index": true}}}'
```

Then leave `indices.default: audit_log` as it is: writes go to the current index behind the
alias, reads cover every index behind it, and `audit:check` verifies the mapping through it.
`audit:index:create` sees the alias as existing and leaves it alone.

Two things to keep in mind. `audit:check` compares the mapping of the index the alias resolves to,
so run it after a rollover if you changed an enricher. And `object_id_type` is a mapping decision
you cannot revise in place: switching between `keyword`, `long` and `integer` needs a reindex, so
decide once, at the start. For numeric identifiers reach for **`long`** (**since 0.9.3**):
`integer` is 32 bits and stops at 2 147 483 647, which a `BIGINT` key eventually walks past.

## Performance

- **A flush is one request.** The records one `flush()` produces — or one frame releases — travel
  together: one `_bulk` call with the `sync` transport, one message that becomes one `_bulk` call
  in the worker with `messenger`. Fifty audited entities in a flush cost one round-trip, not fifty.
- **The default `sync` transport still pays that round-trip inside the request.** Fine for entity
  edits at human pace; switch to `transport: messenger` for anything that writes in bulk, and the
  request pays only for the dispatch.
- **`changes` is not indexed**, so a wide record costs storage and nothing else. Attributes are
  indexed, so add them for what you filter by and nothing more.
- **Enrichers run once per record.** A repository call in an enricher is a query per record: keep
  the value on the entity, or cache it per request. Decorators are the opposite — they receive a
  whole page and should load in one query per entity type.
- **A decorator receives as many entries as the page holds** — up to `reader.max_limit`. At a
  thousand that is a comfortable `IN (...)`; at ten thousand it is not. MySQL's range optimizer
  gives up somewhere around a thousand values (`range_optimizer_max_mem_size`) and falls back to
  a full table scan, which turns a fast page into tens of seconds. Deduplicate the ids and load
  in chunks:

  ```php
  public function decorate(array $entries): array
  {
      $ids = array_values(array_unique(array_filter(array_map(fn ($e) => $e->objectId, $entries))));
      $orders = [];

      foreach (array_chunk($ids, 500) as $chunk) {
          foreach ($this->orders->findSummaries($chunk) as $row) {   // array rows, not entities
              $orders[$row['id']] = $row;
          }
      }

      return array_map(fn (AuditEntry $e) => $e->withExtra(['order' => $orders[$e->objectId] ?? null]), $entries);
  }
  ```

  Load arrays rather than entities while you are there: a wide entity hydrated ten thousand times
  costs more than the query did.
- **Reads are exact-match filters** with no scoring, and the sort is `loggedAt` plus the record
  id: both are indexed keywords, so paging stays fast at millions of records. Use `after()` /
  `iterate()` rather than deep `page()` — past `reader.max_result_window` (10 000 by default,
  and by Elasticsearch's own default) a jump to a far page is refused, and raising it costs heap
  on every shard.
- **The default index has one shard and no replica.** That is a starting point for a dev cluster,
  not a production setting: give the template the shard and replica counts your cluster wants.

## Limitations

Honest list, so nothing surprises you in production:

- **Doctrine events are the only source of automatic records.** A DQL `UPDATE`/`DELETE`, a raw SQL
  statement or `Query::getResult()` with a bulk update bypasses the unit of work, and nothing is
  recorded. Audit those paths explicitly with `AuditWriter::record()`.
- **Embeddables are not audited** as fields of their owner; audit the owning entity's scalar
  fields, or record the change yourself.
- **Only the owning side of an association is dirty-tracked.** A `OneToMany` inverse collection
  never reports changes of its own; declare the owning side (`ManyToOne`, or the owning
  `ManyToMany`) — or track its elements (**since 0.9**), which is answered from the unit of work
  and so does not depend on which side is dirty. An element that moves from one owner to another is
  recorded on both sides (**since 0.9.3**) — the one it left and the one it joined — read from the
  owning association's change set, which is where Doctrine keeps it.
- **A point in time costs the cluster memory while it is open.** `iterate()` holds one for the
  duration of the export; an export that is abandoned without the generator being destroyed keeps
  it until `reader.point_in_time_keep_alive` runs out. Iterate to the end, or let the generator go.
- **Frames live in one process.** Two workers handling parts of the same business operation
  produce a record each; nothing coordinates coalescing across processes.
- **This is not entity-audit.** There is no revert, no "restore the entity as of yesterday": the
  trail is what happened, not a version store.
- **`on_failure: throw` surfaces after the commit** for Doctrine records — see the warning in the
  Doctrine section.
- **Coalescing holds records in memory** until the frame closes (`max_held`, default 10 000
  objects, then it releases what it has).
- **A mapping is forever.** `object_id_type`, and any enricher field type, can only be changed by
  reindexing.

## What counts as the public API

Since 1.0 this is what carries a stability promise: it does not change in a way that breaks you
within the `1.x` line. Everything marked `@internal` is outside it and may change in any
release — see [UPGRADE.md](UPGRADE.md) for the list and for the limitations 1.0 freezes as
limitations rather than bugs.

**Call these**
`AuditWriter::record()`, `write()`, `writeAll()` · `AuditReader::find()`, `iterate()`, `raw()` ·
`AuditFrame::coalesce()`, `begin()`, `end()`, `reset()`, `release()` · the models you build and
receive — `AuditRecord`, `Change`, `AuditEvent`, `AuditOrigin`, `AuditQuery`, `Filter`,
`FilterKind`, `AuditEntry`, `AuditPage`,
`Cursor`, `BulkResult` · `FailurePolicy` · every exception under `AuditException` · the two PSR-14 events.

**Implement these**
`AuditableInterface` · `TracksCollectionElementsInterface` · `AuditEnricherInterface` ·
`MergedRecordEnricherInterface` · `ActorResolverInterface` ·
`QueryExtensionInterface` · `RecordDecoratorInterface` · `ValueComparatorInterface` ·
`TransportInterface` / `BatchTransportInterface` · `GatewayInterface`, if you have a reason to
speak to Elasticsearch differently.

**Declare with these**
`#[Auditable]`, `#[AuditField]`, and the configuration tree.

**Route these**
`IndexAuditRecord` and `IndexAuditRecords`, the Messenger messages.

Everything else — `FrameBuffer`, `ChangeSetBuilder`, `AuditMetadataFactory`, `QueryBuilder`,
`IndexResolver`, `RecordId`, `ClientFactory`, the actor chain, the commands, the message
handlers, the DI classes — is machinery, marked `@internal`, and may change in any release. The
same goes for the handful of `AuditWriter` methods marked `@internal`: `writeCompleted()`,
`writeManyCompleted()`, `complete()` and `reportFailure()` are how the frame and the Doctrine
listener talk to the writer, and they skip steps a caller would want.

## Contributing

```bash
composer install
composer test                      # unit tests
composer phpstan
docker compose up -d es8           # or es9
AUDIT_ES_URL=http://localhost:9208 composer test:integration
```

## License

MIT — see [LICENSE](LICENSE).
