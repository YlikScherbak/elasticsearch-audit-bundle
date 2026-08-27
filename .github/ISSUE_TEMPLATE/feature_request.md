---
name: Feature request
about: Something the bundle should be able to do
labels: enhancement
---

**What you are trying to record, read or control**

**How you solve it today** — an enricher, a listener, a query extension, a fork, nothing?

**Why it belongs in the bundle rather than in an extension point**

The bundle knows about audit records, Elasticsearch and Doctrine; anything domain-specific is
meant to go through `AuditEnricherInterface`, `QueryExtensionInterface`,
`RecordDecoratorInterface`, `ActorResolverInterface` or `ValueComparatorInterface`. If your case
does not fit one of those, say which one came closest and where it fell short — that is usually
the more interesting answer.
