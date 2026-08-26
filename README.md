# Elasticsearch Audit Bundle

> **Work in progress.** The API is being built up release by release on the `0.x` line and
> nothing is stable yet. Watch the [CHANGELOG](CHANGELOG.md) for what each release adds.

A Symfony bundle that records **who changed what** in your application into Elasticsearch:
Doctrine entities audited automatically, arbitrary domain actions logged on demand, many small
changes coalesced into one record, asynchronous writes through Messenger, and a filterable
read API on top — for the moment your audit log stops fitting in a SQL table.

## Requirements

- PHP 8.1+
- Symfony 6.4, 7.x or 8.x
- Elasticsearch 8 or 9 (`elasticsearch/elasticsearch` `^8.0 || ^9.0`)
- A PSR-18 HTTP client when using the version 9 client, which no longer ships one
  (`composer require guzzlehttp/guzzle`)

## Installation

```bash
composer require borsche/elasticsearch-audit-bundle
```

## License

MIT — see [LICENSE](LICENSE).
