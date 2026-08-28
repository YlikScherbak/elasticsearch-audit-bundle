# Elasticsearch Audit Bundle

[![CI](https://github.com/YlikScherbak/elasticsearch-audit-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/YlikScherbak/elasticsearch-audit-bundle/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-8.1%20%E2%80%93%208.4-777bb4)
![Symfony](https://img.shields.io/badge/symfony-6.4%20%7C%207%20%7C%208-000000)
![Elasticsearch](https://img.shields.io/badge/elasticsearch-8%20%7C%209-005571)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

> **Work in progress.** The API is being built up release by release on the `0.x` line; see the
> [CHANGELOG](CHANGELOG.md) for what each release adds and what is still to come. On `0.x`,
> `^0.1` does **not** pull in `0.2` — pin the minor you tested against.

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

```yaml
# config/packages/borsche_elasticsearch_audit.yaml
borsche_elasticsearch_audit:
  client:
    hosts: ['%env(ELASTICSEARCH_URL)%']  # or: service: my_es_client (an Elastic\Elasticsearch\Client)
  indices:
    default: audit_log                    # every record goes here...
    routing:                              # ...unless its object type is routed elsewhere
      auth: audit_auth_log
    object_id_type: keyword               # or "integer" — only if EVERY audited type has numeric ids
  transport: sync                         # or "messenger" (see below)
  on_failure: log                         # or "throw"
  actor:
    fallback: system                      # recorded when nobody is authenticated
  redact:
    fields: [password, token]             # values replaced before anything is written
  reader:
    max_limit: 1000                       # largest page; raise for screens showing thousands of rows
    max_result_window: 10000              # how deep page/limit may reach; match index.max_result_window
```

Then create the indices:

```bash
bin/console audit:index:create   # creates every configured index with its mapping
bin/console audit:check          # cluster reachable? indices there? every field mapped?
```

`audit:index:create --dump` prints the mapping instead, for when the index is provisioned by
other means (Terraform, an ILM policy, a hand-written template).

The index has to exist before the first record: **a write to a missing index is refused**
(`IndexNotFoundException`, handled by `on_failure` like any other failure) rather than left to
Elasticsearch, which would create the index on the fly with a guessed mapping — `loggedAt` as
`text`, so every read fails; `changes` indexed field by field, so later documents are rejected
over type conflicts. The check costs one `HEAD` per index per process. The mapping the bundle
creates is `dynamic: false`: a field nobody declared is stored with the document but not
indexed, and `audit:check` reports it, as it does a field mapped with another type than the one
declared (the sign of an index Elasticsearch created on its own — the fix is a reindex).

An index dropped *under* a running worker is the one case that per-process check cannot see.
Close that gap on the cluster, where it belongs, by keeping Elasticsearch from auto-creating
audit indices at all — a write to a missing index is then a clean `IndexNotFoundException`
whatever the bundle remembers:

```yaml
# elasticsearch.yml — or PUT _cluster/settings {"persistent": {"action.auto_create_index": "-audit_*,+*"}}
action.auto_create_index: "-audit_*,+*"
```

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
    enabled: true              # set false to keep the writer and drop the listener
    skip_empty_updates: true
    connection: default        # the Doctrine connection the listener attaches to
```

Records are built during `flush()`, while Doctrine still knows the change sets, and **written
once the transaction has committed** (`postFlush`). A flush that fails half-way leaves no trace
in the history, and a rolled-back order never shows up as created. Inside an outer transaction
(`wrapInTransaction()`) the records are sent when the inner `flush()` finishes, since nothing
later would tell the listener the transaction ended. With the default `on_failure: log` an
unreachable cluster costs you a history entry, never the transaction.

> **With `on_failure: throw`, read this twice.** The `WriteFailedException` surfaces from
> `flush()` *after* the commit: the data **is** in the database, the history entry is not. Code
> that catches exceptions around `flush()` and treats them as "the save failed" — showing an
> error, retrying, rolling back something else — will be wrong about that. Catch
> `WriteFailedException` separately, or keep `log` and alert on `RecordFailedEvent` instead.

A mistake in an audit declaration — `alwaysRecord` naming a field that is not audited, an
association without a representer — is handled by the same policy: logged and skipped by
default, fatal to the flush with `throw`. Composite identifiers are joined with `|`; an
identifier that is itself an entity is represented by that entity's identifier.

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
```

A value that is neither a number nor "nothing" is left alone — two different words must not
look equal — so `numeric_fields` is safe on a column that sometimes holds text.

Anything else — case-insensitive strings, rounding — is a `ValueComparatorInterface` you
register; it is asked first and may defer with `null`.

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

### Two ways to page

`page(n, limit)` is the familiar one, and it is bounded twice: by how large a page may be
(`reader.max_limit`, default 1000) and by how deep `from + size` may reach
(`reader.max_result_window`, default 10 000 — Elasticsearch's own default). The reader refuses a
query beyond either with an `InvalidQueryException` naming the setting, rather than letting the
cluster answer 400.

Both are properties of your deployment, not of the bundle. A screen that shows five thousand rows
at a time needs the first raised; pages beyond the window need the second raised **together with
the cluster's** `index.max_result_window`, because a `from` deeper than that is a queue of
`from + size` hits held on every shard:

```yaml
borsche_elasticsearch_audit:
  reader:
    max_limit: 10000          # a page of ten thousand rows
    max_result_window: 50000  # five such pages; raise index.max_result_window to match
```

For deep paging, "load more" buttons and exports, page by cursor instead — it has no ceiling:

```php
$page = $this->reader->find($query->page(1, 100));
// ... later, for the next page:
$next = $this->reader->find($query->after($page->nextCursor()));
```

The cursor is the sort value of the last entry: `loggedAt` plus the record's id, a time-ordered
UUID (millisecond precision), which breaks ties in time order and — unlike Elasticsearch's `_doc` — does not move when
segments merge. It stays valid while new records arrive. To stream everything — an XLSX export,
a backfill — let the reader do the cursor loop:

```php
foreach ($this->reader->iterate(AuditQuery::for('order')->since($start)->oldestFirst(), batchSize: 500) as $entry) {
    $sheet->addRow([$entry->loggedAt->format('Y-m-d H:i'), $entry->actor, $entry->event, json_encode($entry->changes)]);
}
```

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

        return $query->withActors(...($ids ?: ['-']));   // no operators → match nobody, not everybody
    }
}

// in the controller:
$query = AuditQuery::for('order')->withOption('country', $request->query->get('country'));
```

Because extensions see every query, they are also the place for **visibility rules** — restrict
to the actors the current user may see, and no endpoint can forget to. Setting an attribute or
option a second time replaces the first value, so an extension can narrow a filter the
controller already set.

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

`extra` is never stored — it is computed on read, so a renamed user shows the current name.
Both extensions and decorators are picked up automatically when they are registered as services.

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
With `symfony/security-core` installed, the security token is asked first. Under `switch_user`
that is the **impersonating** user — the administrator who acted, not the account they were
looking at. Work that runs without a token — message handlers, console commands — usually
knows who it is acting for; register a resolver and it is picked up automatically:

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
did not answer), `RequestRejectedException` (it answered and refused — a document that does not
fit the mapping, missing permissions, a rate limit; retrying will not help), `InvalidQueryException`
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
of `changes`** by name: a secret inside a free-form array, or in an attribute, has to be kept out by
the code that puts it there. For anything conditional — redact only for this tenant, only outside
the office — listen to `RecordCreatedEvent` and rewrite or `veto()` the record there.

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
you cannot revise in place: switching between `keyword` and `integer` needs a reindex, so decide
once, at the start.

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
  never reports changes; declare the owning side (`ManyToOne`, or the owning `ManyToMany`).
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

The bundle is on the `0.x` line, where every minor may change the API — but the surface is
already settled, and this is the part that will carry a stability promise at 1.0:

**Call these**
`AuditWriter::record()`, `write()`, `writeAll()` · `AuditReader::find()`, `iterate()` ·
`AuditFrame::coalesce()`, `begin()`, `end()`, `reset()`, `release()` · the models you build and
receive — `AuditRecord`, `Change`, `AuditEvent`, `AuditQuery`, `AuditEntry`, `AuditPage`,
`BulkResult` · `FailurePolicy` · every exception under `AuditException` · the two PSR-14 events.

**Implement these**
`AuditableInterface` · `AuditEnricherInterface` · `ActorResolverInterface` ·
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
