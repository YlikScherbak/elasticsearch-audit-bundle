---
name: Bug report
about: Something records the wrong thing, or nothing at all
labels: bug
---

**What happened, and what you expected instead**

**Versions**
- bundle:
- PHP / Symfony:
- Elasticsearch cluster / `elasticsearch/elasticsearch` client:
- Doctrine ORM (if the entity path is involved):

**Configuration** (the `borsche_elasticsearch_audit` block, secrets removed)

```yaml
```

**The record, or the absence of one**

What the document in Elasticsearch looks like, or the query that returned nothing:

```json
```

**Reproduction**

The smallest sequence that shows it — the entity declaration and the change you made, or the
`record()` call. If a frame or Messenger is involved, say so: those change when records are written.
