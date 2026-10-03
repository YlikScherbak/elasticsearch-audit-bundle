# Mutation triage for 1.3

In the scenarios checked, no differences were found; the mutation run showed differences in
behaviour that were not checked.

That sentence is the state this file starts from. Corpora, the model of the rows and the database
matrix compare the history with what the rows did over the sequences they generate; they say
nothing about what they do not generate. Infection, run so that nothing is skipped
(`tools/infection/gate.php`), changed the listener in 3,842 places, and 400-odd of those changes
were not told apart by any test. This file is where each of them is accounted for before 1.3.0 is
tagged.

## How a mutant is classified

Every mutant ends in exactly one of these:

| Class | Meaning | Closed by |
|---|---|---|
| **test gap** | the behaviour changes and no test says so | a test that fails on the mutant, seen failing on it |
| **code defect** | the mutant shows the code itself wrong | a test red on the code before the fix, the fix, and the test seen red again with the fix neutralised |
| **killed outside coverage** | the whole suite kills it, but coverage does not hand that test to the run | the test made to cover the line (no ignore) |
| **detected by a loop** | the mutant never ends; the timeout is the detection | the loop shown on a named test, and why the loop cannot end |
| **detected by a crash** | the process dies (memory, recursion); no assertion fails | the crash shown on a named test |
| **equivalent** | no input can tell it from the original | a stated argument; a line ignore with that argument |
| **deliberately not tested** | a detail whose test would pin what nobody relies on | a stated reason; a line ignore with it |
| **not yet told apart** | the model did not kill it, and nothing above is shown | nothing: it stays open |

An ignore is allowed only for the two classes that say so, on one line, with the reason beside it.
Not having found a test quickly is not one of them.

The model is asked in two tiers: the standard 60 seeds first; 3,000 only where the mutant needs a
composition of operations that the vocabulary can produce. Each entry says which tier, with the
vocabulary and the seed range.

Identity: Infection's id where the log gives it, and always the file, line, mutator and change,
with the commit the result was seen on. `src/` has not changed since `b958e3b`; every result below
is on that code.

## Traps of the tools

Each of these cost a run before it was seen, and each makes a result mean something other than
what it says.

- **Infection skips, uncounted, a mutant whose covering tests add up to more than the timeout.**
  "N mutants required more time than configured" is the only sign; the score is computed without
  them. The gate refuses any.
- **PHPUnit 12 reads `--exclude-group=a,b` as the name of one group** — it excludes nothing — and a
  group given on the command line replaces phpunit.xml.dist's, so the integration tests ran under
  the mutants. One flag per group.
- **Infection given `--id` more than once runs only the last.** To re-run several mutants, run one
  at a time.
- **`--only-covering-test-cases` trusts coverage to name the killer**, and coverage cannot name a
  test that read a cached answer. Not used.
- **Contention makes timeouts, and a timeout counts as a kill.** The threads are the manifest's.
- **A class declared beside a test class, used by another test file, turns a missing class into a
  kill.** `QueueSender` and `RememberingSender` lived in `OutboxTransportTest.php`; a mutant whose
  covering files included `AuditTransactionTest` and not that one "died" of "class not found". The
  mechanism exists since v1.1.0, and the scores before 8ddb614 are not to be believed without
  measuring again. Measured where it was measured: `RecordId.php` 124 killed of 124 before, 105
  and 18 escaped after; on 1.2.x the Doctrine configuration 698 killed of 706 before, 615 after.
  The new base of 1.3's main configuration is 94.56%, not the 98% this file's runs reported.
- **Infection's JSON log repeats the whole source file with every mutant**: ninety megabytes for one
  file of the listener. Read it with no memory limit, or not at all — the gate keeps only the
  identities and statuses.

## Where the evidence stops

- **1.2.x, one timeout of the main configuration is not identified.** CI 1.2.5 counted 1 timeout and
  2,341 killed; run alone here, the same tree gave 0 and 2,342. That fits a mutant that contention
  pushed past the timeout, but without its id it is not shown to be the same mutant: 1.2.x's CI
  keeps no mutant logs. It does not move the score (both statuses count as detected).
- **Why the parser's part lost its runner twice is not known.** With the mutants limited to 128M it
  finished, and its record has no errors — no out-of-memory in the log. The limit is a working
  condition, not an explanation; a runner lost again is looked into, not taken as the same thing.
- **An out-of-memory is the mutant's only once the unmutated code has passed the same selected
  tests with the same settings.** The whole suite fits in 128M in one process, which is why the
  limit stays; it is not a proof for every subset.

## Timeouts

A timeout counts as a kill, and contention makes them: at eleven threads 31 of the listener's
mutants timed out, at six 11. Each of the 11 was run again on its own, one thread, nothing else
running (`--id`, tree `410c90f`), and then, in a worktree of `5c179d3` with only that mutant applied,
its covering test files one at a time under a 60-second cap — the fastest covering test first.

| Id | Where | Change | Alone, one thread | Shown on | Class |
|---|---|---|---|---|---|
| `3732e7bb…` | `HistoryReplay.php:176` | `<=` → `>` (LessThanOrEqualToNegotiation) | **killed** in 0.49 s | `HowAPublicationGoesOutTest::testAMomentLongerThanABatchGoesOutWholeInItsOrder` fails | test exists; the timeout was contention |
| `847c5868…` | `StatementShape.php:212` | `++$this->at` → `--$this->at` | timeout | `StatementShapeTest` "a literal compared is not exact" **fails** in 0.2 s; the whole file hangs at its eighth test | detected by a test, and by a loop in another; which one Infection meets first is the order |
| `0ff23f3e…` | `AuditSubscriber.php:829` | `$at <= $to` → `$at > $to` | timeout | `RowMemoryTest` hangs past 60 s | detected by a loop: `$at` only grows, so once it starts past `$to` the condition never turns false |
| `50607ce8…` | `StatementLog.php:606` | `$statement <= $upTo` → `>` | timeout | `ExamplesTest::testTheRuntimeDeclarationIsAuditedTheSameWay` hangs | detected by a loop: the same shape |
| `2eeeeee6…` | `StatementLog.php:771` | `$f !== -1 && $f !== null` → `\|\|` | timeout | `ALateFlushBehindAFailedOneTest` hangs | detected by a loop: the condition is true of every value |
| `c327facb…` | `StatementLog.php:568` | `$frame !== -1 && $frame !== null` → `\|\|` | timeout | `RowMemoryTest` hangs | detected by a loop: the same |
| `81db4366…` | `StatementLog.php:794` | `($parent = …) !== null` → `$parent = … === null` | timeout | `DoctrineCoalescingTest` hangs | detected by a loop: `$parent` becomes a boolean, and the walk goes on through `frames[true]` and `frames[false]` — frames 1 and 0 — with nothing that makes it reach a frame whose answer ends it |
| `20529391…` | `StatementShape.php:402` | `++$j` → `--$j` after an escaped quote | timeout | `StatementShapeTest` "nor after an escaped quote" hangs | detected by a loop: `$j` steps back onto the quote it just passed |
| `d7a6d10e…` | `StatementShape.php:201` | `break` → `continue` | timeout | `StatementShapeTest` "an IN beside a key still numbers what follows" hangs | detected by a loop: the end of the value is found again without moving |
| `9be08eb1…` | `StatementShape.php:272` | `break` → `continue` | timeout | `StatementShapeTest` "a literal compared is not exact" hangs | detected by a loop: the end of the conditions is read again without moving |
| `fbe79a10…` | `StatementShape.php:266` | `if (keyword('OR'))` → `if (!keyword('OR'))` | timeout | `StatementShapeTest` "a literal compared is not exact" hangs | detected by a loop: with no AND or OR, nothing is consumed and the loop goes round |

## Crashes in the main configuration

These four are why the main configuration's two scores differ (98.01% as Infection counts, 97.86%
counting only failed tests): the process died, no assertion failed. Each was applied in the worktree
and its test directory run with errors shown.

| Where | Change | Shown on | Class |
|---|---|---|---|
| `Coalescing/NumericNullAsZeroComparator.php:56` | `substr($last, 1)` → `substr($last, 0)` | `tests/Coalescing`: memory exhausted | detected by a crash: `covers()` is asked of `.x` again, which ends in `.x` |
| `Coalescing/NumericNullAsZeroComparator.php:56` | `substr($last, 1)` → `$last` | `tests/Coalescing`: memory exhausted | detected by a crash: the same |
| `Command/SyncIndexCommand.php:122` | `$segments === []` → `!==` | `tests/Command`: memory exhausted | detected by a crash: the recursion loses its end |
| `Command/SyncIndexCommand.php:123` | `return [$head => $property]` removed | `tests/Command`: memory exhausted in `SyncIndexCommand.php:129` | detected by a crash: the same |

## Escaped

The base is CI's run of `6bb3945`, with the false kills gone and nothing skipped: main 2,664
mutants, 145 escaped, 94.56%; Doctrine 3,842, 715 escaped, 81.39%. Worked through in this order:
`AuditSubscriber.php` and `HistoryReplay.php`, then `RowBinding.php`, then the rest.

### AuditSubscriber::executionsTheLogTookBack (the dropped warning's count)

Nine escaped after `0d757de`, which had been the first defect of this triage: two INSERTs of one
class counted as one by the warning about a flush that was rolled back (seven cases, red before,
each half of the fix neutralised red).

| Id | Line | Change | Class |
|---|---|---|---|
| `23929a4e`, `d490b211` | 829 | the loop starts one statement early (+0, −1) | test gap — closed by `WhatAnAbandonedFlushLeavesTest::testAFlushAfterTwoThatDiedIsRecordedAsItself`, written for them; writing it found the code defect below |
| `9401a26b` | 840 | `continue` → `break` | test gap — the case "a row nobody audits, written first, is passed over" |
| `92809252` | 834 | `$shape === null \|\| $binding === null` → `&&` | equivalent: `$entry === null` gives `$shape === null` (line 831), and `$binding` is null only when `$entry` or `$shape` is (line 832) — so `$binding === null` holds exactly when `$shape === null` does |
| `c7ad9331`, `d7f41f62` | 834 | `$binding->kind !== ROW` joined by `&&` | **code defect (diagnostics)**: a change made only in a collection was counted as no record ("0 audit record(s) … dropped" for a tag added) |
| `644423bc`, `7c7c458b`, `1be4c49f` | 832–834 | the fate and the mapping conditions | not reached: a statement of a table no history is about is not kept in the log |

All nine are moot: the count is gone (`4261bdb`, the reviewers' choice). Counting records honestly
would have meant building them a second way for a log line, so the warning now says the changes
are dropped and promises no number. The function and its helper went with it.

### AuditSubscriber::ranDuringAFlush (which warning an unclaimed statement gets)

Ten escaped, every one of them up to `foreach ([] …)` and `return true` → `false`: no test sees the
function answer yes.

| Ids | Line | Change | Class |
|---|---|---|---|
| `6a032332`, `147b7da9`, `6471c985`, `c8acf774`, `ffc9d790`, `9b2ab4cd`, `0d9e9235`, `1a64187a`, `b1e16209`, `c8e77d8e` | 1808–1810 | the loop, the window test, the answer | not reached: the answer only picks which of `NobodysStatement`'s two warnings a statement no flush owns gets, and "while a flush was running" is the one for a hole in claiming. Every Doctrine test asserts in `tearDown()` that no statement went unclaimed. Searched for a road with a probe, application SQL through `$connection->update()` bound to a watched row: from `onFlush` and from `postFlush`, registered before and after the listener, with and without a change of the flush's own, a flush dying after it, and between `flush()` and the application's `commit()`/`rollBack()` with and without savepoints — fourteen shapes. Each one was either claimed by the flush or said to be outside every flush; none reached the hole's warning |

What the probe showed besides, for the reviewers: whether a listener's SQL is "inside the flush"
depends on where it is registered. From an `onFlush` listener after this one, or a `postFlush`
listener before it, the change is claimed and published in the flush's record. From an `onFlush`
listener before it, or a `postFlush` listener after it, the change is outside every flush: the
log says so and the history leaves it out. No value is wrong either way, and the next record
starts from the row as it is. The README's "SQL a listener of yours runs inside the flush" does
not say where inside begins and ends. When the flush dies after such a statement committed on its
own, the change is covered only by the dropped warning.

### AuditSubscriber: unwindTo, forgetThisFlush, beginFlush (where a flush ends)

| Ids | Line | Change | Class |
|---|---|---|---|
| `2dada374` | 1434 | `finally` unwrapped: the late write's state forgotten only when the write did not raise | test gap — under `on_failure: throw` the late write's failure leaves the flush that found it, and the state stayed: the next flush read the late flush's facts again, met them let go, raised a `LogicException`, and its own record was lost. Closed by `ALateFlushBehindAFailedOneTest::testALatePublicationThatRaisesLeavesTheFlushesAfterItTheirOwn` (two flushes after it, each its own record), red under the mutant |
| `ff7b0658` | 1357 | `reportedLostChangeSets = false` → `true` when a flush is forgotten | test gap — "said once per flush" was tested within one flush only; kept past its end, the flag silenced the warning for the life of the worker. Closed by `UnitOfWorkTimingTest::testTheLostChangeSetIsReportedAgainByTheNextFlushThatLosesOne`, red under the mutant |
| `4cc0e39a`, `27039209`, `282569b8`, `62540cf3`, `693c4b22`, `0aef5d0b` | 1367, 1372 | how the operation's window is closed and which windows are kept | not reached: the windows are read only by `ranDuringAFlush()` (above). Keeping the closed ones longer changes nothing either: a later reading starts past their end |
| `1eaa6028` | 1459 | a window opened for every flush, not for every operation | not reached: the same |
| `4cdee426` | 1416 | `?->` → `->` on the manager of the abandoned flush | equivalent: the branch needs an entry on the stack, and every entry is pushed after line 1457 has set it |
| `fa502b43`, `41e714d2`, `35db3e61`, `6e5293d9`, `756d740d` | 1417–1435 | the late write counted or written with the manager of the flush that found it instead of the one that left | **told apart by a probe, and what it found is a code defect** (below): with both managers alive, `fa502b43` and `6e5293d9` give the representer a fresh object and the right label where the code gives a wrong one. No test until the reviewers decide the fix — a test now would pin the defect |
| `45069cc9`, `9b86aba4`, `589b4682`, `aad09556`, `c76ed71d`, `5bae75d9`, `63b7b096`, `2c23aa75`, `0e6d8aae`, `983bd0ef`, `320824e5` | 1266–1293, 1463 | whether an unwound flush counts as one that ran, and whether its share is forgotten | not yet told apart. Argued: from `collectingNow()` the answer is not used, and forgetting decides only `plannedChanges` — the lost-change-set warning — and maps the operation's end empties; from `beginFlush()` it matters only with `collected > 0`, and a record is built only from a statement that stayed done, whose flush `hasDoneAnythingFor()` then answers for. The gap in the argument is an owner popped before the unwinding. For the 3,000-seed run |
| `276a4be2` | 1280 | every unwound flush claims its frame, not only one that planned a collection's rows alone | not yet told apart. The docblock names the risk — a flush that never ran handed the application's next transaction — and the vocabulary has no transaction of the application's own between a refused flush and the next. For the 3,000-seed run, and a word for the vocabulary |

### AuditSubscriber::drafts (what a publishing is made of, and in what order)

| Ids | Line | Change | Class |
|---|---|---|---|
| `4c69420a` | 864 | a skipped empty update ends the reading (`continue` → `break`) | test gap — `DoctrineAuditTest::testASkippedUpdateDoesNotTakeTheRecordsAfterItWithIt` |
| `a47bd519` | 947 | an owner's lines joining its record ends the reading of every other owner's | test gap — `CollectionElementsTest::testEachOwnersLinesJoinItsOwnRecordAndNoOtherIsLeftOut` |
| `2451ffdf`, `a8c564ec` | 890, 1040 | a record placed by where it ended, not where it began | test gap — `DoctrineAuditTest::testACreationDoctrineCompletesLaterStandsWhereItsInsertRan`: a ring of relays, one inserted first and completed by an UPDATE after the others' INSERTs |
| `0df16728` | 936 | a record with lines joined begins where its lines did | test gap — `CollectionElementsTest::testAnOwnersRecordStandsWhereItsOwnStatementRanNotWhereItsLinesDid` |
| `75cea6e2` | 1001 | the same for links | test gap — `WhatAnOwningCollectionPublishesTest::testAnOwnersRecordStandsWhereItsOwnStatementRanNotWhereItsLinksDid` |
| `3f32b51b` | 997 | the key a list joins under without its owner | test gap — `WhatAnOwningCollectionPublishesTest::testEachOwnersLinksJoinItsOwnRecordOfTheFlush` |
| `78a4f26f` | 997 | … without its collection | test gap — `…::testTwoCollectionsOfOneOwnerInOneFlushAreOneRecord` (a shelf's two lists) |
| `05ae3c2e`, `e89fefcf`, `2cdcd875`, `a8d859ca` | 935, 950, 1000, 1015 | `$seen[…]` / `$joined[…] = true` → `false` | **equivalent**: both maps are only asked `isset()`, which is true of `false`. Ignore by line |
| `f9dfb2f8`, `28363bc8` | 940, 1005 | `$run['at'] > $draft['at']` → `>=` | **equivalent**: the two are positions of different statements — the owner's row and an element's row, or a join row — and two statements never share one |
| `f91cc425` | 885 | the sort of the entities' executions removed | **equivalent**: `EntityRowRuns::each()` hands them over in the order of their first statement already (facts in log order; a completion added to its INSERT's execution). Ignore by line, or take the sort out — for the reviewers |
| `67cbe2c9` | 885 | that sort's comparator always −1 (`<=>` of an int and an array) | not equivalent past sixteen executions: PHP's sort then moves elements (measured: from 17 on). The final sort restores the order; what stays moved is which of an owner's records `$byOwner` names last. Seen only with an owner holding two records in one publishing — not yet told apart |
| `4373cc8c`, `be50f3a7` | 940, 1005 | `>` → `<=`: the context taken from whichever of the two ran first | not yet told apart: different only when an element's or join row's statement ran before the owner's own last one in the same flush, and Doctrine's order puts the owner's class first; a completion after the element's would not. For the 3,000 seeds |
| `2d5d22a6` | 1040 | only the right side of the final comparison by where a record ended | not yet told apart: the input is already in order of where records begin, so it shows only for a record appended after the entities' (lines or links only) that began before one of them ended |
| `0e2ead7d` | 997 | the key without its flush | not yet told apart: needs one owner's collection moved in two flushes of one publishing (a nested flush) |
| `2c0a5b0e`, `6d44a9ac`, `98e9c3c7`, `dfd2982b`, `2d7956f2`, `f54abcd4` | 997 | separators dropped or moved | not equivalent, as at `StatementLog:341`: a collection name ending in a digit, or an id that is a string, makes two keys one. The fixtures have neither. For the reviewers: keys as nested arrays rather than strings would take the class of defect away, not only these mutants |
| `2d12f5ae`, `8c748873` | 927, 991 | `$metadata === null \|\| $id === null` → `&&` | not reached: an owner of a tracked collection is audited, and its id is null only for a composite key held by no manager |
| `1168bd9c`, `df1f0cea` | 965, 1028 | `$byOwner[…] ??=` → `=` | not yet told apart: different only when the owner already has a record of another flush in the same publishing (a nested flush) |

The Doctrine tests written here were run on seven cells: SQLite with the lowest dependencies, with
ORM 2.19 and with ORM 2, MySQL and Postgres with DBAL 3 and 4. Two of them were wrong on the first
run, in their premises, not in what they asserted about the history: the order of two classes'
UPDATEs is Doctrine's choice and differs on MySQL and Postgres (one class's rows in the order the
manager holds them is not), and on Postgres with DBAL 3 a key comes from a sequence at `persist()`,
so a smaller key does not mean an earlier INSERT (the INSERTs' parameters say it). Both rewritten;
all seven green, and each test red under its mutants on SQLite.

### AuditSubscriber::assertTrackedCollectionsAreServable (what a tracking declaration is refused for)

| Ids | Line | Change | Class |
|---|---|---|---|
| `a3586bc8`, `215aab52` | 1718 | the arm for "is not an association", and for "is a to-one association", removed | test gap — no fixture made either mistake. `TracksAColumn` and `TracksAReference`, and `WhatARefusedDeclarationSaysTest::testTrackingElementsOfSomethingWithoutAnySaysWhatItIsInstead`, which asks for the whole sentence: without its arm, a column is told it is a to-one association |
| `22f796ca` | 1709 | the remembered key without the class | test gap — every test had one class; `…::testAClassCheckedBeforeDoesNotVouchForAnotherOne` checks a crate and then refuses another class |
| `04dc88e2` | 1753 | the field list read up to its first name that checks out | test gap — `MisspelledTracking` now names `quantity` before `quanitity`, and the existing test of its sentence fails under the mutant |
| `cf8d5195`, `3bd484b7` | 1709 | the key as the class alone, or the suffix before it | **equivalent**: still one key per class, and the one other kind of key in the map is the class with `"\0fields"` |
| `6f2a9878` | 1699 | the early return for a declaration that tracks nothing | **equivalent**: what follows is a loop over that empty list and a key remembered for it |
| `0b3146e9`, `786ffc42`, `8f2ae6f9`, `2599a64d` | 1711, 1712, 1760, 1761 | what is remembered, and the return when it is | **equivalent**: without them the check is asked again, and gives the same answer; `= false` is also `isset()` |
| `4d7f6997` | 1718 | the arm for an inverse side with no mappedBy | not reached: Doctrine's inverse side is the one that has a mappedBy |
| `f943dd3d` | 1718 | the arm for the inverse side of a ManyToMany | not reached: `assertAuditedFieldsAreThere()` refuses the same declaration first (line 1599), and both callers ask it first (606, 1505). `ElementOwnershipTest` asserts that refusal's sentence |

### AuditSubscriber::contextWhere and linkRuns (a link's record)

| Ids | Line | Change | Class |
|---|---|---|---|
| `acc61be7`, `746ba95e`, `8a2f6535`, `e37729f9`, `eff90a78` | 1155 | the context read from a row that is not the owner's | test gap — `WhatAnOwningCollectionPublishesTest::testALinksContextIsItsOwnersRowAndNoOtherRowOfTheFlush`: another article, a consignment under the same key, a crate; each one kills some of them, the three together all five. A first try with a shipment under the same key let two through: its context has no `status`, and the record's own was filled in from elsewhere |
| `92ec0166` | 1152 | `break` → `continue` | **equivalent**: the facts come in the order of the log, so every one after the first past the position is past it too |
| `ec0ea262` | 1151 | `>` → `>=` | **equivalent**: a join row's statement is never an owner's row's |
| `14b0b9f3`, `570a9cc4`, `f98ba6e1` | 1150–1160 | the context as the row stands now, not where the link was written | not yet told apart: different only when the owner's row moves after its link in the same publishing without the link joining that move's record — an owner change in another flush of it. A probe with a postFlush listener's UPDATE after the link: the change became the owner's record and the link joined it |
| `6a5679a1`, `1d130219`, `697e0158` | 1060–1065 | doubt said again, said without its count, its class named twice | test gap — `…::testWhatTheLinksCouldNotBeFollowedThroughIsSaid` now asserts the sentence, with two statements of one class, and that the next flush says nothing |
| `b9c0120c` | 1078 | the key's values instead of its columns in the warning | test gap — the sentence asserted whole in `…::testALinkTheApplicationWritesOutsideEveryFlushIsSaidAndIsNoRecord`. A value in that log line would be one more road out for what redaction keeps in |
| `006e7f36`, `17df3d13` | 1081, 1089 | a run nobody owns, or a list that did not move, ends the reading | test gap — the same test and `…::testAListThatDidNotMoveIsNoRecord`, each with a link of another article after it |
| `88be88dc` | 1088 | the application's comparator asked after the built-in one, which always answers | test gap — `…::testTheApplicationsComparatorDecidesWhetherAListMoved` |
| `bcc92f0c` | 1060 | `array_values()` removed | **equivalent**: counted and its classes read; keys are not |
| `7e8a205b` | 1060 | doubt `>` → `>=` the position read through | not yet told apart. A probe that ran the doubtful statement last of its flush, from a postFlush listener ahead of this one, was green under the mutant; the test written for it was taken out |

### AuditSubscriber: assertAuditedFieldsAreThere and assertTheColumnSaysWhatTheRowHolds

| Ids | Line | Change | Class |
|---|---|---|---|
| `aafc84a4` | 1560 | the remembered key without the class | test gap — `WhatARefusedDeclarationSaysTest::testAClassWhoseFieldsCheckedOutDoesNotVouchForAnotherOne`: a crate, then a post box. Not an article — a declaration by interface is never remembered, which is why a first try passed under the mutant |
| `efbc191e` | 1607 | an audited association ends the reading of the fields | test gap — `PostBox` has an audited association before the property Doctrine does not map |
| `a8e2bb90`, `405f0b71` | 1659 | another field of a versioned entity, or every field, refused as the version | test gap — `ElementOwnershipTest::testAColumnThatMovesOnItsOwnIsRefusedRatherThanAudited` asks for the clause with the field's name; it asked for "version column" |
| `c78bf4ec`, `7f4c172f`, `da3f6f5e` | 1672–1673 | not insertable and generated: their arms, and `false ?? …` | test gap — `NotInsertableColumnOrder`, `GeneratedColumnOrder` (generated on INSERT and written by Doctrine, so it is refused for that and nothing else) |
| `80d912dc`, `42ca6b44`, `edb444ae`, `00efa799`, `d9f90330`, `458fa3d3` | 1560–1632 | the key as the class alone; what is remembered, and the return | **equivalent**, as in the tracked-collections check |
| `c5106440`, `78b95b1f` | 1673, 1674 | `(bool)` removed | **equivalent**: Doctrine holds those flags as booleans in both majors' mappings |

### AuditSubscriber::rememberWhatIsBeingEmptied

| Ids | Line | Change | Class |
|---|---|---|---|
| `b76bf54d`, `a1e81a17` | 486, 500 | a collection with nothing to say ends the reading | test gap — `CollectionElementsTest::testAnEmptyingPassedOverDoesNotTakeTheNextWithIt`: a route's unaudited list, or a shipment's lines Doctrine does not delete, before a crate written past the process whose lines are replaced unread. Doctrine schedules them in the order of its identity map, not of the replacements, which two first tries learned |
| `62ac420a`, `1dc135d6`, `5e315cdd` | 499, 508 | the rows read for a collection Doctrine will not empty, or one already loaded | test gap (cost) — `HowOftenTheListenerAsksTheDatabaseTest::testAnEmptyingTheRowsAlreadySayAsksNothing`, with the one read it is there for |
| `2c8fcb69` | 485 | `\|\|` → `&&`: a declaration that is not there is read | test gap — under "throw" the TypeError refused the flush: `…::testAnEmptyingOfAnOwnerNobodyAuditsIsPassedOverInSilence` |
| `c07c1e96` | 483 | the arms for ORM 2's array mapping and ORM 3's object swapped | **equivalent on the ORM the run installs**: ORM 3's mapping is also `ArrayAccess`. On ORM 2 it would fail; the mutation run does not install it |

### AuditSubscriber: the owners of an element, and the rest of the listener

| Ids | Line | Change | Class |
|---|---|---|---|
| `8dd74d8d`, `3b29d99c`, `d8ec9551`, `3eeae90d` | 438–450, 1506 | an owner reached only through its line not checked, from an update, an insertion or a removal of the line | test gap — `ElementOwnershipTest::testAnOwnerReachedOnlyThroughItsLineIsCheckedToo` (an owner whose tracked field is misspelled, with no event of its own) |
| `294f8331` | 1496 | the owner the element points at dropped, the one it pointed at kept | **found a code defect** (below): the "one it pointed at" was read from `getOriginalEntityData()`, which by onFlush already holds the new owner — so the two entries were always the same and the mutant changed nothing. After the fix it is read from the change set; the case is `…@a line of it moved to an owner that is fine`, red before the fix |
| `9672c382` | 445 | what an insertion planned not remembered | not yet told apart: read only by the lost-change-set warning, which a new row's change set does not lose the same way |
| `1ce8a69e`, `a83abeff`, `202b5e9c` | 1493–1502 | the owners' list not made unique; the first owner ends the reading; the first association ends it | **equivalent** for the first (the check is idempotent); not reached for the others: both owners are of the association's one target class, and no element in the fixtures has an association ahead of its owner's that is skipped |
| `9fdc86c4`, `6670e663`, `5e8f3af4` | 1492, 1522, 1530 | which associations of an element lead to its owner | not yet told apart: different only for an owner that does not hold the element through that association and whose own declaration is wrong |
| `26d077e5` | 281 | `skipEmptyUpdates` on by default | test gap — `DoctrineAuditTest::testAListenerBuiltWithItsDefaultsSkipsAnUpdateOfNothingAudited` |
| `00b005f3` | 554 | the lost-change-set warning for an entity nobody audits | test gap — `UnitOfWorkTimingTest::testALostChangeSetOfAnEntityNobodyAuditsIsNotThisListenersToReport` |
| `e3d31ec2` | 625 | a declaration not checked when its entity is removed | test gap — `WhatARefusedDeclarationSaysTest::testADeclarationIsCheckedWhenItsEntityIsRemovedToo` (refused in `remove()`, where preRemove is raised) |
| `48183f3f` | 791 | a moment cut into calls inside a frame | test gap — `HowAPublicationGoesOutTest::testInAFrameThatRefusesTheOperationNothingPastTheOverflowIsCompleted` also with a batch larger than the moment |
| `6e507d68` | 791 | a batch cut at one more than its size | **equivalent** to what reaches the cluster: the writer cuts what it is handed to its own batch size again |
| `8734191e` | 1122 | a removal given the context of its row | **equivalent**: a removal's draft has no changes and its row's context is gone; a test written for it passed under the mutant and was taken out |
| `731b0792` | 1125 | the context applied through a fresh instance, not the owner | not yet told apart: different only for a declaration by interface that varies by instance in its always-recorded fields |
| `502c32b6` | 1173 | the last failure while building raised, not the first | not yet told apart: under "throw" both are the bundle's same sentence; which one it is shows only with `failure_details: full` |
| `3731ecff`, `ad9e61ad` | 729, 761 | the manager's order; `array_values()` on a run | **equivalent**: both callers pass the same manager twice; a run is built as a list |
| `5ee1afb0`, `e679b8fc` | 344, 347 | what is kept per manager | **equivalent**: the narrowing is idempotent; `isset()` again |
| `800f006d`, `da0a6952` | 825 | the count for no manager | not reached: `beginFlush()` always has one |
| `7462d284` | 1791 | `consume` defaulted to true | **equivalent**: every caller passes it |
| `8df1c95e`, `fcab43e4` | 1244 | claiming what ran with no frame of its own | not reached on DBAL 4, which always nests with savepoints — the run's; the DBAL 3 cells' tests without savepoints are where it is reached |
| `77edd212`, `2b00bf8e`, `9f5f4297` | 1855–1860 | an enum key, and an object key with no identifier | not reached: Doctrine hands a key back as the value behind the case, and an associated key has its identifier by the time a record is built |
| `c07c1e96` | 483 | see above | |
| `2008c7e5`, `ce553d62`, `d9e0f6d5`, `622b66e7`, `ebdd1399`, `ac610dbe`, `952df9bd`, `59ae5ac5`, `cc74ec7d`, `5dbf9dac`, `72b11801`, `6d76930b`, `bf912738`, `d8f66b3a` | 431, 678–697, 1195–1221, 1323–1324 | which entry of the stack a postFlush claims for, when an inner flush hands back, whether a flush planned only collections, and whether one ran | not yet told apart, with the unwindTo group: they change which of a nested flush's states is taken for which, and a flush refused between two of the application's transactions is a word the vocabulary lacks. For the 3,000 seeds with that word |

### ElementFieldRuns

| Ids | Line | Change | Class |
|---|---|---|---|
| `089220e6`, `23ab881c`, `904bb451` | 81 | the warning for a line's place changed outside every flush | test gap — `WhatTheListenerRemembersTest::testALineTheApplicationMovesItselfIsSaidAsAMoveOfItsPlace` |
| `b6e5122f` | 91 | an element whose representer failed ends the reading | test gap — `CollectionElementsTest::testAnElementWhoseRepresenterFailedDoesNotTakeTheRestOfTheFlushWithIt` |
| `4954b786` | 97 | the lines of an owner removed in the flush end the reading | not yet told apart: Doctrine deletes last, so nothing of the same reading follows them but a nested flush's |
| `23aa8e10`, `abb4db75` | 81 | which warning, during a flush or outside | not reached, as `ranDuringAFlush()` (above); tier (b)'s two kills here were warnings of eight worktrees sharing a proxy directory — green alone |
| `0ebd03d1` | 62 | `consume` defaulted to true | **equivalent**: every caller passes it |
| `ed67afe2` | 118 | `MEMBER \|\| field === null` → `&&` | **equivalent**: a membership fact has no field and a fact with no field is a membership |
| `572817c3`, `a2b64dbb`, `caeda453` | 134 | the owner's key without or with a moved separator | **equivalent**: a class name holds no `"`, and the key is JSON, so class and key cannot run into each other |
| `33d6122c`, `53bbe34b`, `e8c95e51`, `727edcba`, `c8d6bfd4` | 152 | the defaults of a run's `since` and `at` | **equivalent**: both are set when the run is opened |

### EntityRowRuns

| Ids | Line | Change | Class |
|---|---|---|---|
| `152a69d3` | 515 | a collection ends the reading of the declaration's fields | test gap — no fixture declared an audited column after an audited collection: `ListFirst`, and `DoctrineAuditTest::testAColumnDeclaredAfterACollectionIsRecorded` (a collection of relays: the tests that count which collections hold a tag, a stop or an author count exactly the ones they name) |
| `9a016442`, `17032edd`, `d510c138` | 152–169 | what the writing reading passes over not let go of, or let go of by the counting one | test gap — `WhatTheListenerLetsGoOfTest::testWhatTheWritingReadingPassesOverIsLetGoOfToo` |
| `b7642d19`, `b534de34` | 226, 248 | a completion ends the reading; its places read one off | **killed by the wider search** (tier (b): seeds 237, 421 and 786 of the default vocabulary); transcribed into the model's sequences — see below |
| `cbb6b843`, `c8894974`, `113`–`126` (`3fafce6c`, `da0ae00e`, `39291695`, `b46e7646`), `2d218010`, `5d316093`, `73e5dd13`, `ff86c13d`, `3fe82b30` | | keys of a generator with none; `consume` passed by every caller; facts and the open execution set together; `isset()` on `false`; a to-one's join columns always a list and a to-many owning one's none; a departed list always passed | **equivalent** |
| `9ce0d0e2`, `a8b5a2e1`, `696b1422`, `a962fa38`, `8fef1221`, `8ffa69cb` | 111, 350, 215 | a statement the log cannot parse; a creation's key without its class or separator | not reached: a fact's statement was parsed to become one; two classes' creations under keys that run into each other in one flush |
| `6aa0d62e`, `56103d99` | 148 | which warning | not reached, as `ranDuringAFlush()` |
| the rest of the method's (`completes()`, `continues()`, the once flags, `related()`, the declaration's always-recorded list) | | | in tier (b)/(c) |

### LinkFacts

| Ids | Line | Change | Class |
|---|---|---|---|
| `847f10f7`, `b853c891`, `3ba1c35c`, `989153b7`, `308238ac` | 89, 106, 357, 367 | `explode()` to three; `isset()` on `false` | **equivalent**: a collection's name holds no `::` |
| `d9164260`, `851fe5c7`, `3b1b1131`, `27c15eb8`, `bd887e6b`, `429b1d4f`, `6c2cc532`, `970febc5`, `01ee01ac`, `bbf76f7b`, `b4637b08`, `9abe6b37` | 133–146 | the order of events at one position | not reached: two events of one owner at one position need one DELETE that is the owner's row going and a target of its own collection going — an owner holding itself; no fixture does |
| the rest | | | in tier (b)/(c) |

### StatementLog (81)

The log was tested through the listener, with SQL as DBAL writes it: upper case, bare, one
savepoint inside one transaction. Asked directly, 74 of the 81 are red: 51 under
`WhatTheLogReadsOfSqlAsWrittenTest` (`3ec129b`: savepoints, releases and rollbacks in lower case
and their look-alikes, a key after a lower-case INSERT, `onlyReads()`, lower-case DELETEs and
INSERTs, a DELETE in a literal, an UPDATE of the join table) and
`WhatTheLogKeepsOfFramesAndOwnersTest` (which frame a flush claims, what a rollback voids and
counts, `claimUnowned()`, what letting go keeps, `size()`, a watch asked for twice, a key of two
columns, a failed or empty join-row INSERT, a count in a string, a statement the log does not
hold, a savepoint opened outside a transaction). **Test gaps**, all of them; every survivor below
was run against both files.

| Ids | Line | Change | Class |
|---|---|---|---|
| `e32f08ca`, `1839d317`, `1bd31703` | 173, 471, 699 | `true` → `false` in a map read only by `isset()` | **equivalent** |
| `76f53404`, `f62486af`, `760f0ac7` | 229, 761, 785 | `voidFrom()` after −1, or `>=` | **equivalent**: sequence numbers start at 1, and every caller passes 0 — the `$after` parameter is dead; for the reviewers |
| `a9090257`, `9eb4abfc`, `3b3dd746`, `139c0b74`, `75ef7ade`, `deedebdd`, `3e3b77ab`, `01ef8c19`, `6c80ade2`, `60a8c85e`, `3caab360`, `ded1ab2f` | 720, 733, 232–233, 745–746, 765 | a frame's `open` flag, or the loops that close frames on a rollback, release or rollback-to | **equivalent**: `open` is written and never read, and `close()` without `committed` writes `false` over `false`. Dead code; for the reviewers — the flag and the three loops can go |
| `1921b0d6` | 291 | `(int)` dropped before `> 0` | **equivalent**: a count in a numeric string compares as a number, `null` as 0 |
| `1c57296d`, `ab1af661` | 398, 468 | a key's values imploded without making them strings | **equivalent**: the middleware sees driver parameters, already converted to scalars |
| `7720dc22` | 794 | `isInside()` stops at frame 0 | **equivalent**: frames are numbered from 1, and the `?? null` ends the walk |
| `25d43f1d` | 727 | `close()` commits by default | not reached by DBAL: it differs only for a savepoint that is the outermost frame — `SAVEPOINT` run by the application outside a transaction, which SQLite answers by beginning one and `RELEASE` by committing it. The log calls such a statement pending for good. For the reviewers: pin the SQLite meaning, or leave it |

### LookRightAfter and NobodysStatement (17)

| Ids | Line | Change | Class |
|---|---|---|---|
| `ec4d37f8` | LookRightAfter 41 | a statement with no place asked after | **equivalent**: no place means a savepoint statement or one of a table no history reads, and a watch's table is one (`WatchedRows::isAHistoryTable()` counts link targets) |
| `a9a2b2de`, `a36c96f7` | 71 | `array_values` dropped | **equivalent**: `fetchAllNumeric()` returns a list of lists |
| `ed6ab06d` | 68 | a key bound as a string, or as an integer | **equivalent** on SQLite, which the gate runs; PostgreSQL casts an untyped parameter |
| `7cff9aba`, `4103b9cf`, `33124d94`, `b317c982`, `b1fcc099`, `b2a992f2`, `ba57a98f`, `c3fda286`, `b519fdca`, `f42f4bb6`, `e34928dd` | 73, 92–98 | what a failed question gives back inside a transaction | not reached: the question is the listener's own SELECT of a join table it has just read, and nothing in the tests makes it fail. The road exists for PostgreSQL, where a failed statement leaves the transaction unusable; on SQLite a savepoint left open is released with DBAL's own. Deliberately not tested — it would take a connection made to fail one SELECT inside a flush on PostgreSQL |
| `2d8f3cd4`, `a01458ce` | NobodysStatement 35, 37 | the warning while a flush ran | not reached, as `ranDuringAFlush()` (above) |

### Code defects found (continued)

- **The owner an element leaves was never checked** (diagnostics, LOW). `aboutTheOwnersOf()` meant to
  check the declaration of the owner a line points at and of the one it pointed at, and read the
  second from `getOriginalEntityData()` — which Doctrine has overwritten with the new values by
  onFlush (measured: a line moved from A to B reads B there; A is in the change set only). A line
  leaving an owner whose declaration is wrong, with no event of the owner's own, was not refused.
  Fixed by reading the change set; red before. A regression of the 1.3 branch: 1.2's
  `holdMembership()` read the change set first and said why in a comment ("computing it refreshes
  the original data to the current values") — the knowledge was in the code and did not survive
  the rewrite. No release had it, so no CHANGELOG line.

### HistoryReplay::rowAt (what a link's target is shown as)

| Ids | Line | Change | Class |
|---|---|---|---|
| `2f8e393c`, `fc32d843`, `20f6156d`, `0b505bac`, `f2716159`, `2c8e74ac`, `476c7203`, `9b4eb769`, `543c60e8` | 1112–1121 | which version of a target's row is shown, or none and the object instead | test gap — `WhatALinksTargetIsShownAsTest`: catalogue items (crate lines, whose rows the history reads), moved after the link by a postFlush listener ahead of this one, the row and the object or the object alone |
| `8fb6460a`, `0333ae78`, `f24856f8`, `3558ae88`, `f4c4e8e6`, `a436d6e3`, `ce1f0daf`, `bc6892aa` | 1112–1122 | the same, for a row taken after the position asked about, a row gone, two versions at one position | not yet told apart; in tier (b) |

A correction of yesterday's finding ("a late record's label from a later moment"): `Author`, the
target in that probe, is a class whose rows the history does not read, and for those the README
says the representer is handed "the object the application holds". The late road widens what
that means — the object as it is a flush later — but it is the documented behaviour, not a defect
against it. `DoctrineAuditTest::testARepresenterDescribesTheObjectAsItStandsWhenTheRecordIsBuilt`
pins the same for tags.
For the reviewers: whether the README should say so about the late road.

### RecordId and IdSequence (main)

| Ids | Line | Change | Class |
|---|---|---|---|
| `4b4bfd20`, `fb2bffed`, `330e0d54`, `9c52ada6`, `24eafdc8` | 79–82 | a random bit read twice, from another part's place | test gap — `RecordIdTest::testNoRandomBitOfAnIdIsAnotherOneAgain`: of 512 ids, no two of the 74 random bits always equal (a false failure 2^-512) |
| `0c116a71`, `60180985`, `b03a2ad7` | 80 | the variant's low bits narrowed or widened | test gap — `…::testEveryVariantNibbleOccurs` (8, 9, a and b among 256), and the 64-id format check |
| `34115c41`, `d64b3980`, `354eb690`, `b637532d`, `eb77e021`, `efc1dd2d` | 95 | where 48 bits of milliseconds end | test gap — `…::testATimestampPastWhatFortyEightBitsHoldIsTheLastMillisecondTheyDo` |
| `33d12095`, `507be0b5`, `01ab4e86`, `a0999437`, `082f3e97` | IdSequence 35 | where a sequence may begin | test gap — `…::testASequenceBeginsWithinItsBits` |
| `db4e88d6`, `6878af71` | IdSequence 39 | a fixed or a narrower random beginning | test gap — `…::testARandomBeginningUsesTheWholeLowerHalf` |
| `1d081760`, `3830ce17`, `e0f7fa09`, `97b9760f`, `86218263` | 69–82 | more random bytes, a longer slice, a last group of 13 | **equivalent**: what is past the 32 digits is cut off |
| `cebe35a6` | 80 | rand_b read from one digit later | **equivalent**: the digit skipped is random and used nowhere else |
| `207b02ac`, `753d2b07`, `630a7811`, `bac0aba8`, `bab5ea10` | IdSequence 39 | the random beginning's bounds moved by one or two | **equivalent** to any test: one value in 2^41 |

### ChangeRedactor (main)

| Ids | Line | Change | Class |
|---|---|---|---|
| `65c6ef76`, `4383da32`, `70f1a69a`, `b26849d6`, `02f23523`, `517ae572`, `1726d2bd` | 136–394 | what a record costs the budget, and where its edge is | test gap — `WhatTheRedactionBudgetCountsTest::testARecordThatCostsTheBudgetIsWalkedAndOneMorePlaceIsNot`, six shapes at their exact cost |
| `095f9409`, `187db02f`, `ee6faec2` | 81 | the smallest limits, and either at nothing | test gap — `…::testTheSmallestLimitsThereAreAreAccepted`, `…::testEitherLimitAtNothingIsRefused` |
| `c775f9fa` | 438 | a rule matching the start of a longer name | test gap, **over-redaction**: "pass" masked "password" — `…::testARuleIsAWholeNameAndNotTheStartOfOne` |
| `8ed88e77` | 364 | a date or an enum walked as a structure | test gap — `…::testADateOrAnEnumIsAValueAndNotAPlaceToLook` |
| `52d1ebe2`, `9ca6f5c3`, `cc50888d`, `d8c50c5f` | 92 | the check for a blank half of a rule | **equivalent**: every rule that trimming changes is refused by the padding check after it, and an empty half by any form of this one; a test written for them passed under each and was taken out |
| `85e40636`, `7f86ba27`, `76d0e2a4` | 86, 169, 207 | a cast on a position `strpos` found; `array_values()` before a spread; a pair walked as any array | **equivalent** |

### FrameBuffer (main)

| Ids | Line | Change | Class |
|---|---|---|---|
| `e826e057`, `e6de3444` | 98 | the default valve | test gap — `WhatTheBufferPromisesAtItsEdgesTest::testTheDefaultValveHoldsTenThousandObjects` |
| `285374b9`, `35b3f744`, `8889bfa7`, `84fca9f0`, `ac1b2f7c` | 187–302 | what a reset says and leaves; a frame that stages everything | test gap — `…::testAResetSaysWhetherAnythingWasThere`, `…::testAfterAResetTheNextFrameIsAnOrdinaryOne`, `…::testAnOrdinaryFrameStagesNothing` |
| `afe269c2`, `310c297d` | 224, 259 | what a refusal counts; a refusal left for the next operation | test gap — `…::testARefusalCountsWhatWasHeldAndWhatWasStaged`, `…::testAFrameLeftOpenOnARefusedOperationDoesNotRefuseTheNext` |
| `1b496142`, `57574a2d`, `1374ff77`, `32550bf8`, `935812e3` | 455–457 | two objects' keys made one | test gap — `…::testTwoObjectsAreNeverHeldAsOne`; the backslash case needed two backslashes, which a first try's literal did not have |
| `c6108d83` | 497 | one comparator failure of several kept | test gap — `…::testEveryComparatorFailureOfAClosingFrameIsKeptForTheWriter` |
| `e2b2a68d`, `d1db9574` | 170, 261 | the return after `forget()` | **equivalent**: releasing what `forget()` emptied returns the same empty list |
| `7c42fa44`, `3385fdbb` | 403, 409 | the first step's fields not marked as moved | **equivalent**: a field that comes back is marked by the step that brings it back |
| `e6d6aced` | 511 | `= true` → `= false` | **equivalent**: read with `isset()` |

### AuditWriter (main)

| Id | Line | Change | Class |
|---|---|---|---|
| `ce71d641`, `4d5f1d1d` | 64 | the default batch | test gap — `WhatTheWriterPromisesAtItsEdgesTest::testTheDefaultBatchIsFiveHundredRecords` |
| `56bc9b58` | 160 | the transaction not told before an immediate write is refused | test gap — `AuditTransactionTest::testAnImmediateWriteCaughtByTheApplicationStillKeepsTheTransactionFromCommitting` |
| `075a847e`, `e5018496` | 441, 433 | a batch that failed whole without the records never sent; an empty batch sent | test gap — `…::testABatchThatFailsWholeReportsTheRecordsThatNeverReachedItToo`, `…::testNothingToSendIsNoRequest` (a frame on nothing, a batch all vetoed: no request — an asynchronous transport would queue an empty message) |
| `b56f82f0` | 495 | a frame's comparator failure told at a later write | test gap — `…::testAComparatorFailureOfARecordLetGoEarlyIsReportedWithIt` |
| `f49e4915` | 557 | a generator's enrichers kept by key | test gap — `…::testEnrichersHandedOverWithTheSameKeyAreEachAsked` |
| `6221df21` | 745 | a live counter let go | test gap — `…::testAMillisecondMetAgainKeepsItsCounterWhileAMomentOfItLives` |
| `27cf7d3e`, `40d8c8db` | 835, 839 | a moment enricher after an ordinary one, or after another moment enricher | test gap — `…::testEveryMomentEnricherIsAskedWhateverComesBeforeIt` |
| `ee61a8ff`, `23bc8d11`, `327bb016`, `dc47195b`, `cfd29e66` | 957, 992–993 | what a failure says when its record cannot be redacted, and with its record | test gap — `…::testAFailureThatCannotBeRedactedIsToldWithoutItsRecordAndWithWhatWentWrong`, `…::testAFailureIsToldWithTheRecordItWasAbout` |
| `1b082d62` | 319 | in a frame when there is none | **equivalent** to what reaches the transport: the writer cuts a whole moment to its batch size |
| `379a6285` | 345 | the early return for nothing to write | **equivalent**: the same empty list stops at line 433 |
| `91308a01`, `4e1de648`, `8ec0b969` | 416, 529, 778 | a record with no id or timestamp | not reached: `complete()` sets both before |
| `510c5bf4`, `825fc53b`, `4e7f48a3` | 557, 735, 889 | `array_values()` on a list read by `foreach`; the latest counter returned by the live one; an early return of nothing taken | **equivalent** |
| `b967ce30` | 761 | `complete()` public → protected | **equivalent**: nothing outside the class calls it; for the reviewers whether it should say so in its visibility |

### The rest of main

| Ids | Where | Change | Class |
|---|---|---|---|
| `3b85757a` | CheckCommand 301 | one drifting window reported of several behind one name | test gap — `WhatTheCheckReportsTest::testAWindowIsReportedForEveryIndexBehindOneName` (a rollover's alias; `InMemoryGateway::$settingsOf` to answer for several) |
| `62e0d69d` | CheckCommand 159 | the connection's two halves asked with `&&` | not reached: the container gives both or neither |
| `63bc1cf9`, `dc8170df`, `a8a084d1` | CheckCommand 169 | the savepoint check's conditions | not reached on the DBAL the run installs: DBAL 4 always nests with savepoints; the DBAL 3 cells check it |
| `95b34b18`, `87fa4a57`, `1f3d2f93`, `65494361`, `78ca98ff`, `fd3f9c46` | OutboxContext | what entering, leaving, spoiling and a reset keep | test gap — `WhatTheOutboxContextRemembersTest` |
| `86e7f571` | OutboxContext 52 | clearing at depth -1 | **equivalent**: at depth 0 nothing is spoiled — `spoil()` keeps nothing there and leaving to 0 clears |
| `706451b4` | AuditTransaction 220 | `commit()` removed | test gap — `AuditTransactionTest::testTheRowAndItsRecordAreCommittedTogether` now asks that no transaction is left open: on one connection uncommitted rows read as there |
| `e4803d21`, `9e8a0f93`, `e9416428` | AuditTransaction 247–249 | the reason and the rollback's reason in the log line | test gap — `WhatARefusedRollbackSaysTest::testAskingForFullDetailStillRepeatsBoth` read the marker anywhere, and the exception carried it |
| `6e47482b`, `c7f837de` | AuditTransaction 199, 229 | asked again; the finally unwrapped | **equivalent**: the check's answer is the same; the inner catch takes every Throwable |
| `bb9177b2`, `d40ffd07`, `8219252d`, `6072428b`, `ea08ee10`, `63285efe` | AuditFrame | comparator failures of a frame that failed to write, of one released, said when they cannot be reported, the first raised | test gap — `WhatAClosingFrameReportsTest` |
| `b923bca0` | AuditReader 580 | a decorator's keys kept | test gap — `AuditReaderTest::testAPageIsAListWhateverKeysADecoratorGaveIt` |
| `4f464123`, `f1edbcdb`, `5bd2b24e`, `969a3267` | AuditReader | `array_values()` on Elasticsearch's lists; a count it gives as a number; -1 for none | **equivalent** |
| `a8c6ce9e` | AuditQuery 450 | the object type left out of the fingerprint | test gap — `AuditReaderTest::testATokenFromOneObjectTypeCannotBeContinuedOnAnother` |
| `086808b5` | AuditQuery 624 | where a token came from kept after its cursor was abandoned | test gap — `…::testAQueryThatLeftItsCursorBehindLeavesWhereItCameFromTooAndReadsFromTheStart`: refused for a token it no longer carried |
| `74636fc0` | AuditQuery 610 | a new cursor losing to the old one | **equivalent**: `after()` gives a page with its cursor, and a page means another search, so a cursor never arrives with the same search |
| `6834168a`, `765699cf` | AuditQuery 458, 118 | filters fingerprinted as objects; actors not re-listed | **equivalent**: the same for one query every time; `nonEmpty()` lists them, as its siblings' ignores say |
| `54e66876`, `dbe18441`, `6300e44a` | AuditEntry | a number read where text is expected; extra replaced | test gap — `WhatAnEntryReadsOfAHitTest` |
| `2781a110` | AuditEntry 99 | `array_values()` on a hit's sort | **equivalent** |
| `5fa99f49` | ValueComparator 51 | a generator's comparators kept by key | test gap — `ValueComparatorTest::testComparatorsHandedOverWithTheSameKeyAreEachAsked` |
| `590177d7` | ValueComparator 50 | `array_values()` on a list read by `foreach` | **equivalent** |
| `2d1c8e39`, `2a0f3744` | FrameResetMiddleware 86 | the reason left out of the line | test gap — `AuditFrameTest`'s logger fills every placeholder now, and the line is asked to have none left |
| `898bea1e`, `abf2e221` | ClientFactory 24, 40 | TLS verification off by default; the log gate not set | not yet told apart: the extension always passes the configured value, and what the client does with a logger needs a client that talks. For the reviewers: the default of a public factory is TLS — whether a test should read it off the built client |
| `3d738fda` | EnricherMapping 51 | `apply()` public → protected | **equivalent**: nothing outside the class calls it |
| `b7a8d80e` | 289 | `catch (NotConfiguredException)` in `writeAll()`'s loop removed | test gap — `writeAll()` settles its moment before the loop, which meets the refusal first, so no test reached the catch; a caller handing in its own `Provenance` (public) does, and the refusal then went through `on_failure: log` as a line. Closed by `WhatAMomentEnricherDescribesTest::testTheRefusalIsNotSwallowedWhenTheMomentWasSettledElsewhere`, red under the mutant. Infection printed this diff without the file's blank lines, so it was applied by hand |

### Code defects found

- **A record under another row's id** (`229e01a`, HIGH, in 1.3 from its start, no release had it).
  The key of a row the database hands out was taken from the order `postPersist` announced rows
  in; a flush that dies in its first `postPersist`, or one started from `postPersist` (announced A,
  C, B for INSERTs A, B, C), moved every key after it. Found by the test written for the 829
  mutants. Fixed by keeping the connection's answer to Doctrine's `lastInsertId()` beside its
  INSERT; pinned by `WhereDoctrineAsksForAGeneratedKeyTest` (the canary, seven configurations) and
  `AKeyBelongsToItsInsertTest` (each document's objectId and values, red before, five
  configurations). The corpora after the fix: 0 documents of 3,000 changed against the base in any
  of the fourteen runs — no generated sequence takes either road.
- **The dropped warning's count** (`4261bdb`, diagnostics): see above.
- **A late record's label from a later moment** (found by the 1417–1435 mutants, NOT FIXED, for the
  reviewers). A watched association's target is handed to its representer as its row stood
  (`HistoryReplay::copyAt()`) only where the replay knows the row; otherwise it is the object the
  manager holds. On the ordinary road that object is what the flush wrote. On the late road it
  is not: the records of a flush whose `postFlush` was swallowed are written in the next flush's
  `onFlush`, and by then the application may have changed the object. Measured: comment c-1's
  author set to Alice, publishing swallowed, `$alice->name = 'Alice (renamed)'`, next flush —
  the late record says `author: null → "Alice (renamed)"`, a name the row got a flush later.
  With the abandoned manager still alive and the next flush on another one, the name is one no
  row ever had (`"Alice (unsaved)"`). The id stays true. The README's "the new one once the
  statement ran" does not hold there. Not checked on 1.2.x. The fix is a choice: read the row
  when the late write runs — before the next flush's statements, so the database still holds
  what the late flush left — or remember the rows of pointed-at classes when a flush settles.

### Tier (a): every escaped mutant against the whole suite

All 841 of CI run 37009575250 (`4261bdb`), each applied to its own worktree and the suite run
without integration, benchmark and budget — the 60 seeds of the model included. 835 escaped, one
diff did not apply (AuditWriter:289, above), five were killed. Three of the five were false: a
Doctrine warning from `ProxyFactory.php:463`, eight worktrees sharing one proxy directory; run
alone, `c8d6bfd4` (ElementFieldRuns:152), `a189ead9` (HistoryReplay:1445) and `4e7f48a3`
(AuditWriter:889) escape. The two real ones:

- `b03a2ad7`, `RecordId.php:80`, `& 0x3` → `& 4`: caught by chance — the broken variant shows on
  half the ids, and the test looked at one. **Test gap**, closed by `RecordIdTest::testItIsAVersion7Uuid`
  looking at 64; red under the mutant three runs out of three.
- `609ee600`, `StatementLog.php:341`, the watch's name without its label: killed by
  `HowOftenTheListenerAsksTheDatabaseTest::testATargetRemovedWhileHeldIsLookedAtRightAfterItsDelete`
  locally, in the container (ORM 3.7.1) and on ORM 3.7.3 — what CI resolved — and escaped in CI.
  (Yesterday's "it escapes Infection locally too" was another mutant of the same line, `$label.$flush`:
  local and CI ids differ, so the diff, not the id, says which.) The test passes under it when the
  watch that comes last is the article's: without the label, the three collections that can hold
  a tag share one name, and the last `watch()` takes it. Their order is the mapping's list of
  classes, which comes from reading the fixtures' directory — most likely in another order on
  CI's filesystem than on this one; not verified there. **Test gap**, closed by a test that does
  not depend on the order:
  `WhatTheConnectionSeesRightAfterADeleteTest::testTwoCollectionsWatchingOneTableInOneFlushAreEachLookedAtUnderTheirOwnLabel`.
- The three other mutants of that line, which drop or move the separator (`546ac524`, `fd0f5fd8`,
  `fe8d46b4`): "x1" of flush 2 and "x" of flush 12 become one name, and a field may end in a digit.
  **Test gap**, closed by `…::testALabelEndingInADigitIsNotTakenForAnotherFlushsWatch`. All four
  are red under the two tests.

### Dead line ignores

Checked with a scratch script, not a part of the gate: for each ignore, whether its method
exists and its line lies inside the method. That only finds the dead; one inside its method but
on another line than meant (`AuditWriter:581` was a comment) took reading each line.

- `infection.doctrine.json5`: all 24 dead — methods gone in 1.3, or lines past the method or past
  the end of the file (AuditSubscriber has 1,864 lines; they named 2,084–3,580). They ignored
  nothing, so no score of 1.3 was raised by them. One was also untrue by then: the `finally` in
  `beginFlush()`, said to be the same as the next line because the late write's failures were
  swallowed — under `on_failure: throw` they are not, which is `2dada374` above. All removed; the
  block says so. Equivalents found by this triage go back one by one with their line.
- `infection.json5`: four of 71 stale — `CheckCommand` 254→294 (CastInt), 281→321
  (UnwrapArrayValues), 83→91 (Coalesce), `AuditWriter` 581→631 (Coalesce). Each was among CI's 145
  escaped at the new line, and each reason still reads true of it; renumbered. Main's base is
  therefore 141 escaped, not 145.

## Killed outside coverage

Mutants the whole suite kills that coverage did not hand to the run. Fixed at the cause where the
cause is found, so that coverage names the test.

- `StatementShape.php:163`, four mutants (Concat, ConcatOperandRemoval ×2, Assignment): killed by
  `RowBindingTest::testASchemaIsPartOfTheTablesName`, which read `UPDATE audit.CrateItem …` second,
  from `StatementShape::read()`'s memo. `tests/EveryTestReadsItsOwnStatements.php` (93b31ca)
  empties the memo before each test; coverage of the line then names three tests, the killer among
  them, and without it names one.

## Words the vocabulary lacks

Paths the model's vocabulary does not produce, found by mutants it did not kill. The input for the
vocabulary after the release.

- **A flush started from `postPersist`.** The model starts nested flushes from `postUpdate`; from
  `postPersist` the rows are announced in another order than their INSERTs ran, which is where the
  key defect lived.
- **A flush that dies in a `postPersist`, with more than one row of a class inserted.** The model's
  dying endings throw elsewhere; this one leaves rows inserted and never announced.
- **A collection changed alone in a flush that dies.** Seen only by the warning, now without count;
  worth having where the history of the flush after it is what is compared.
