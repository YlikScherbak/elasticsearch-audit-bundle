# Contributing

Bug reports, questions and pull requests are welcome.

## Running the checks

```bash
composer install
composer test          # unit tests; needs pdo_sqlite for the Doctrine ones
composer phpstan       # level 8 plus strict rules, on src
composer test:coverage # needs pcov or xdebug; fails below the floor CI enforces
```

Without a coverage driver on your machine, the CI image does the job (it has no Composer, so
call PHPUnit and the floor check directly; `vendor/` comes from your local install):

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli sh -c \
  'pecl install pcov >/dev/null && docker-php-ext-enable pcov \
   && php vendor/bin/phpunit --coverage-clover coverage.xml \
   && php tools/coverage-floor.php coverage.xml 94'
```

The Doctrine tests build a real `EntityManager` on an in-memory SQLite database. If your CLI has
no `pdo_sqlite` they are skipped, which is easy to miss — enable the extension, or run
`php -d extension=pdo_sqlite vendor/bin/phpunit`.

Two tests cover behaviour that only exists in Doctrine ORM 2 (a partial `clear()`); they skip on
ORM 3 and run in the lowest-dependencies CI job.

## Integration tests

The `integration` group talks to a real cluster and is excluded from the default suite. Both
Elasticsearch majors are supported and the client's major has to match the cluster's, so the
compose file offers one of each:

```bash
docker compose up -d es9          # or es8, on port 9208
AUDIT_ES_URL=http://localhost:9209 composer test:integration
```

CI runs the same tests against live Elasticsearch 8.19 and 9.1, each with its matching client.

## What a change needs

- **A test that fails without it.** For a bug, the test comes first and reproduces the report.
- **Green on the whole matrix**: PHP 8.1–8.4 × Symfony 6.4/7/8, plus the lowest-dependencies job.
  Watch out for `psr/log` 1, where `LoggerInterface::log()` has no type on `$message`.
- **A CHANGELOG entry** under `[Unreleased]`, saying what changes for someone using the bundle —
  not what changed in the code.
- **Nothing suppressed.** No `@phpstan-ignore`, no baseline: if the analyser complains, the code
  or the types are wrong.

## What belongs in the bundle

The bundle knows about audit records, Elasticsearch and Doctrine. It does not know about your
domain: anything application-specific goes through an extension point —
`AuditEnricherInterface` (attributes to write), `QueryExtensionInterface` (filters to read by),
`RecordDecoratorInterface` (data to display), `ActorResolverInterface` (who acted),
`ValueComparatorInterface` (what counts as unchanged). A pull request that adds a domain concept
to the core will be asked to become one of those instead.

## Style

PSR-12, `declare(strict_types=1)` in every file, `final` unless the class is meant to be
extended, constructor property promotion, no annotations where a type will do. Comments explain
**why** — the code already says what.
