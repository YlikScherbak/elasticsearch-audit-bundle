**What changes for someone using the bundle**

**Why**

**How it is covered**

- [ ] A test that fails without this change
- [ ] `composer test` and `composer phpstan` green (level 8 + strict rules, nothing suppressed)
- [ ] `CHANGELOG.md` entry under `[Unreleased]`, written for a consumer
- [ ] Integration tests run against a live cluster, if the change touches the Elasticsearch calls
