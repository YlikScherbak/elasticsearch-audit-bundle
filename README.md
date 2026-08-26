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

`AuditEvent::CREATE`, `UPDATE` and `REMOVE` are what the Doctrine integration emits (coming in
0.2). Anything else is up to you: `login_failed`, `order_call`, `google_sheet_shared`. Keep them
stable — they are what you filter the history by.

### Who did it

The bundle asks each registered `ActorResolverInterface` in turn and takes the first answer.
With `symfony/security-core` installed, the security token is asked first. Work that runs
without a token — message handlers, console commands — usually knows who it is acting for;
register a resolver and it is picked up automatically:

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
place to deal with a flaky cluster.

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
`NotConfiguredException`, `IndexNotFoundException`, `TransportUnavailableException`,
`WriteFailedException`.

## The document

```json
{
  "objectType": "order",
  "objectId": 42,
  "event": "update",
  "loggedAt": "2026-08-26 12:00:00",
  "source": "7",
  "changes": { "status": { "old": "new", "new": "paid" } },
  "salesType": 3
}
```

`source` holds the actor. `loggedAt` is always UTC in `yyyy-MM-dd HH:mm:ss`. Everything after
`changes` is an attribute added by an enricher.

## Roadmap

| Release | Adds |
|---|---|
| 0.1 | Recording arbitrary actions, sync and Messenger transports, enrichers, index commands |
| 0.2 | Automatic Doctrine entity auditing (`AuditableInterface`, `#[Auditable]`), PSR-14 events |
| 0.3 | Reading: `AuditQuery` / `AuditReader` with filters, pagination, `search_after`, decorators |
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
