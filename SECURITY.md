# Security policy

## Supported versions

The `0.x` line is under development: fixes go into the next minor, and only the latest one is
supported. Once `1.0` is out, the latest minor receives security fixes.

## Reporting a vulnerability

Please report privately — through
[GitHub's private vulnerability reporting](https://github.com/YlikScherbak/elasticsearch-audit-bundle/security/advisories/new)
or by email to ylikscherbak@gmail.com — and not in a public issue. Include the version, what an
attacker can reach, and a reproduction if you have one. Expect an acknowledgement within a few
days.

## What is in scope

This bundle writes an audit trail and reads it back, so the interesting classes of problem are:

- **Trail integrity** — a way to make a record say something that did not happen, to suppress one
  that did, or to alter one already written.
- **Query injection** — user input reaching Elasticsearch as anything but a value. `AuditQuery`
  accepts values for `term`/`terms`/`range` clauses; field names, index names and scripts are
  never taken from input. A way around that is a vulnerability.
- **Leaking through the trail** — an audit read returning records the viewer may not see. Note
  that access control is the application's job, through `QueryExtensionInterface`: the bundle has
  no notion of who may read what, and an endpoint that forgets to restrict is a bug in the
  application, not here.
- **Secrets in records** — a configured `redact.fields` value that still reaches the index.

## What is not in scope

- An application storing sensitive values in `changes` without configuring redaction.
- A cluster reachable without authentication, or credentials in a repository.
- `on_failure: log` losing records when Elasticsearch is down — that is the documented default;
  use `throw` if a missing record must fail the operation.
