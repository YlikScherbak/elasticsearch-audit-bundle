# Elasticsearch Audit Bundle

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
```

Then create the indices:

```bash
bin/console audit:index:create   # creates every configured index with its mapping
bin/console audit:check          # cluster reachable? indices there? every field mapped?
```

`audit:index:create --dump` prints the mapping instead, for when the index is provisioned by
other means (Terraform, an ILM policy, a hand-written template).

The bundle refuses to write to an index that does not exist, because Elasticsearch would
otherwise create it on the fly with a guessed mapping — `loggedAt` as `text`, every read failing
from then on. It checks once per index per process; an index dropped *under* a running worker is
the one case that check cannot see. Close that gap on the cluster, where it belongs, by keeping
Elasticsearch from auto-creating audit indices at all:

```yaml
# elasticsearch.yml — or PUT _cluster/settings {"persistent": {"action.auto_create_index": "-audit_*,+*"}}
action.auto_create_index: "-audit_*,+*"
```

With that in place a write to a missing index is a clean `IndexNotFoundException` whatever the
bundle remembers.

The index has to exist before the first record: **a write to a missing index is refused**
(`IndexNotFoundException`, handled by `on_failure` like any other failure) rather than left to
Elasticsearch, which would create the index on the fly with a guessed mapping — `loggedAt` as
`text`, so every read fails; `changes` indexed field by field, so later documents are rejected
over type conflicts. The check costs one `HEAD` per index per process. The mapping the bundle
creates is `dynamic: false`: a field nobody declared is stored with the document but not
indexed, and `audit:check` reports it, as it does a field mapped with another type than the one
declared (the sign of an index Elasticsearch created on its own — the fix is a reindex).

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

`page(n, limit)` is the familiar one and stops at row 10 000 — Elasticsearch's `from/size`
ceiling. The query refuses a page beyond it with an `InvalidQueryException` that says so, rather
than letting the cluster answer 400. For deep paging, "load more" buttons and exports, page by
cursor instead:

```php
$page = $this->reader->find($query->page(1, 100));
// ... later, for the next page:
$next = $this->reader->find($query->after($page->nextCursor()));
```

The cursor is the sort value of the last entry: `loggedAt` plus the record's id, a time-ordered
UUID, which breaks ties in write order and — unlike Elasticsearch's `_doc` — does not move when
segments merge. It stays valid while new records arrive. To stream everything — an XLSX export,
a backfill — let the reader do the cursor loop:

```php
foreach ($this->reader->iterate(AuditQuery::for('order')->since($start)->oldestFirst(), batchSize: 500) as $entry) {
    $sheet->addRow([$entry->loggedAt->format('Y-m-d H:i'), $entry->actor, $entry->event, json_encode($entry->changes)]);
}
```

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
final class RedactSecrets
{
    public function __invoke(RecordCreatedEvent $event): void
    {
        $record = $event->getRecord();

        if ($record->objectType === 'user' && isset($record->changes['password'])) {
            $event->setRecord($record->withChanges(['password' => new Change('***', '***')] + $record->changes));
        }

        if ($record->event === 'heartbeat') {
            $event->veto();          // not written, not an error
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

`RecordCreatedEvent` fires after the record is complete and enriched, right before it is sent;
`RecordFailedEvent` fires on every failed write, whatever the failure policy.

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
| 0.4 | Coalescing many small changes into one record |
| 1.0 | The API settles |

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
