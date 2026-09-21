# Examples

Working code for each thing the bundle does, in the shape an application would have
it: services that take the bundle's classes in their constructor, entities that
declare what to audit, implementations of the interfaces you are meant to implement.

These are not snippets. They are analysed at the same PHPStan level as the source
and the ones that can be executed are executed in
[`tests/Examples/ExamplesTest.php`](../tests/Examples/ExamplesTest.php) — against
the in-memory gateway for the writer, against a real `EntityManager` for the
entities. A signature that moves breaks the build here before it breaks anybody's
copy-paste.

Everything shown is autoconfigured: implement one of the interfaces, and the bundle
picks it up. The reasoning behind each behaviour is in the
[README](../README.md); this is the code.

## Writing

| What you want | File |
|---|---|
| Record something that is not an entity change — a call, a refused login, a share | [`Writing/RecordingActions.php`](Writing/RecordingActions.php) |
| Audit an entity with attributes: fields, context, an association, a collection | [`Writing/Order.php`](Writing/Order.php), [`Writing/OrderLine.php`](Writing/OrderLine.php), [`Writing/Customer.php`](Writing/Customer.php) |
| Declare the same thing in code, when the field list depends on the instance | [`Writing/Ticket.php`](Writing/Ticket.php), [`Writing/TicketComment.php`](Writing/TicketComment.php) |
| Turn an operation's several saves into one record | [`Writing/ApprovingAnOrder.php`](Writing/ApprovingAnOrder.php) |
| Decide what counts as a change, when the default comparison is too strict | [`Writing/SameDayComparator.php`](Writing/SameDayComparator.php) |

## Reading

| What you want | File |
|---|---|
| Read the history: filters, page numbers, cursors, an export that walks everything | [`Reading/ReadingTheHistory.php`](Reading/ReadingTheHistory.php) |
| Put names on a page without a query per line | [`Reading/NameTheActorDecorator.php`](Reading/NameTheActorDecorator.php) |
| Show a stored value in a form somebody wants to read | [`Reading/ReadableStatusDecorator.php`](Reading/ReadableStatusDecorator.php) |
| Let a viewer see only their part of the history — a boundary that cannot be widened | [`Reading/OnlyWhatThisViewerMaySeeExtension.php`](Reading/OnlyWhatThisViewerMaySeeExtension.php) |
| Serve it over HTTP, including the failures worth mapping | [`Reading/HistoryController.php`](Reading/HistoryController.php) |
| Count rather than list: who, how often, per day | [`Reading/CountingWithAggregations.php`](Reading/CountingWithAggregations.php) |

## Extending

| What you want | File |
|---|---|
| Add what only your application knows, with the mapping the index needs | [`Extending/SalesChannelEnricher.php`](Extending/SalesChannelEnricher.php) |
| The same, but about the operation's outcome rather than one step of it | [`Extending/NetEffectEnricher.php`](Extending/NetEffectEnricher.php) |
| Answer "who did it" where there is no security token | [`Extending/ActingOnBehalfOfResolver.php`](Extending/ActingOnBehalfOfResolver.php) |
| Drop a record, rewrite one, or notice that a write failed | [`Extending/ReactingToRecords.php`](Extending/ReactingToRecords.php) |

## Operating

| What you want | File |
|---|---|
| Every setting, with its default and the reason it exists | [`Operating/configuration.yaml`](Operating/configuration.yaml) |
| Commit the change and its history together | [`Operating/ApprovingAnOrderAtomically.php`](Operating/ApprovingAnOrderAtomically.php) |
| Assert on what your application recorded | [`Operating/AssertingOnTheTrail.php`](Operating/AssertingOnTheTrail.php) |

That file is not a listing anybody typed out: the test suite parses it and puts it
through the bundle's own configuration tree, so a setting that is renamed or removed
fails here rather than in your application.
