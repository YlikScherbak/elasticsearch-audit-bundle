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
savepoint inside one transaction. Asked directly, 58 of the 81 are red under
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
| `25d43f1d` | 727 | `close()` commits by default | not reached by DBAL: it differs only for a savepoint that is the outermost frame — `SAVEPOINT` run by the application outside a transaction, which SQLite answers by beginning one and `RELEASE` by committing it. The log calls such a statement pending for good. For the reviewers: pin the SQLite meaning, or leave it. **The finding they asked for (D, 2026-10-04):** current behaviour — the log opens the savepoint as the outermost frame and the RELEASE closes it, so the log is out of a transaction again; what ran inside is read as what the rows hold from then (the next flush's record starts from it) and said as SQL outside every flush; a ROLLBACK TO it voids it. Its fate reads "pending" rather than "committed", and nothing reads that difference: only "void" is told apart. Expected contract: the same. No defect; pinned by `ASavepointOutsideATransactionTest` (SQLite only: PostgreSQL refuses such a SAVEPOINT, MySQL keeps nothing of it in autocommit). The mutant is **equivalent** |

### LookRightAfter and NobodysStatement (17)

| Ids | Line | Change | Class |
|---|---|---|---|
| `ec4d37f8` | LookRightAfter 41 | a statement with no place asked after | **equivalent**: no place means a savepoint statement or one of a table no history reads, and a watch's table is one (`WatchedRows::isAHistoryTable()` counts link targets) |
| `a9a2b2de`, `a36c96f7` | 71 | `array_values` dropped | **equivalent**: `fetchAllNumeric()` returns a list of lists |
| `ed6ab06d` | 68 | a key bound as a string, or as an integer | **equivalent** on SQLite, which the gate runs; PostgreSQL casts an untyped parameter |
| `7cff9aba`, `4103b9cf`, `33124d94`, `b317c982`, `b1fcc099`, `b2a992f2`, `ba57a98f`, `c3fda286`, `b519fdca`, `f42f4bb6`, `e34928dd` | 73, 92–98 | what a failed question gives back inside a transaction | not reached: the question is the listener's own SELECT of a join table it has just read, and nothing in the tests makes it fail. The road exists for PostgreSQL, where a failed statement leaves the transaction unusable; on SQLite a savepoint left open is released with DBAL's own. Deliberately not tested — it would take a connection made to fail one SELECT inside a flush on PostgreSQL |
| `2d8f3cd4`, `a01458ce` | NobodysStatement 35, 37 | the warning while a flush ran | not reached, as `ranDuringAFlush()` (above) |

### StatementShape (35)

The reader was tested with the shapes the persisters write and a few look-alikes. Cases added to
`StatementShapeTest`'s table — SQL tight against its punctuation, empty and escaped literals, a
number, a minus and a division, underscores, a lower-case `and`, block comments, a reserved word
as a bare column, a word after the last condition, a parenthesis left open, statements with a
part missing — and one for the cache (`testAStatementReadIsStillRememberedAfterAnotherWellWithinTheBound`):
23 of the 35 are red, **test gaps**. `622aa7f0` (line 199, `!== null` folded into the `??`) loops
for ever on the open parenthesis: Infection counts that as a timeout, detected.

| Ids | Line | Change | Class |
|---|---|---|---|
| `38adf250` | 133 | `placeholder()` protected | **equivalent**: only the reader calls it. For the reviewers: it can be private, with `keyword()`, `symbol()`, `name()`, `identifier()`, `value()`, `where()` whichever the class's own methods alone call |
| `6d58d29c` | 401 | `''` inside a literal never an escaped quote | **equivalent**: `'it''s'` read as `'it'` and `'s'` — two literals where there was one, and a value is skipped literal by literal |
| `2c22036c` | 379 | a quoted name searched for its closing quote one place on | not reached: differs only for an empty quoted name, `""`, which PostgreSQL and MySQL refuse |
| `7a868d00`, `646c693a` | 411, 436 | a literal's token without its kind | **equivalent**: a literal is only ever passed over, and nothing reads the kind of what is passed over |
| `7d0f476c`, `fcb7bb98` | 424 | a comment told by the character before, not after | **equivalent** for SQL a database runs: `--` and a closed `/* */` are refused either way, at the first character or at the last |
| `9bbb7a62`, `082809eb` | 428, 435 | the first-character guard of a word or a number dropped | **equivalent**: the anchored pattern after it decides the same |
| `f43f09e6`, `a0683944` | 443, 444 | a symbol's text from group 1 | **equivalent**: group 1 is the whole match |

### WatchedRows (27)

Red now: 179, the seven of 185 (a join table in a schema and in mixed case), 242, 252 (`821d3414`,
by hand: its diff no longer applies past the fix below) — `WhichRowsAreWatchedTest`, a mapping of its
own under `tests/Doctrine/Shop` and `ShopPlate` — the one-to-one apart, as ORM 3.0.0 cannot load an owning one, and skipped there (tables in a schema, a link that is no history, a line's collection
before its owner, an inverse one-to-one, a subclass held and a held subclass's root).

| Ids | Line | Change | Class |
|---|---|---|---|
| `2802eeea`, `d7654f66`, `097437ed`, `efaf8c44`, `e0e70282` | 50, 61, 76, 122, 198 | a cache not kept | **equivalent** in what is decided; cost only |
| `c8e9afc1`, `5e6d8e4c`, `6f2c65e9`, `c03a3388` | 175, 185, 193, 142 | `true` → `false` in a map read by `isset()` | **equivalent** |
| `ae4625be`, `694b9fe6`, `69f3f51f`, `49577eea`, `50a6842e`, `ed943cf6` | 84, 130, 170 | a mapped superclass or an abstract class not passed over; `array_values` | not reached / **equivalent**: no fixture has a mapped superclass (the class says so); an abstract class's `newInstance()` throws and is caught; a filter's keys are not read |
| `dfde30e5` | 184 | a join table's name `\|\|` empty | **equivalent**: Doctrine always names it |
| `ea32e22b` | 262 | `return false` in the catch | **equivalent**: the method returns false after it |

**Code defect found (LOW, 1.3 only, fixed `15e23c4`)**: `decide()` watched a class whose to-one had
the name an audited collection is mapped by, though the collection held another class — a fee of a
shipment, beside its lines. Its rows were remembered and its statements kept for a history nobody
writes; a probe found no wrong record or warning. Now the collection's target must be the class or
one of its hierarchy, both ways; each half seen failing alone.

### RowMemory, JoinRowMemory, JoinRowsQuery (92)

23 red under the tests as they stand (`rememberTheRowsOf()` 203–207, `settle()` 519, and 17 of
JoinRowsQuery, with `testEveryColumnIsNamedAsTheMappingQuotesItWhicheverSideItIsOn` and
`testAJoinTableTheMappingCannotSayAllOfIsNotAsked`). Of the rest:

| Ids | Line | Change | Class |
|---|---|---|---|
| `66b90840`, `f2aad7b5` | RowMemory 114, 115 | the rows scheduled for deletion not remembered | **killed outside coverage**: ORM 2.19 only takes them out of the identity map at `remove()`; red in the ORM 2.19 cell (3 failures), as is `c07c1e96` (AuditSubscriber 483) with 7 |
| `06c473f0`, `c4ec3fd9`, `8a35fd9b`, `4fa74c3e`, `d46d5c78`, `75f21a74` | RowMemory 282–283, 375–382 | the links of an owner going whose join columns do not cascade not read; `cascades()` | not yet told apart, and **for the reviewers**: with the read taken out, an owner going changes no fact, doubt or state and no record (`ALinkThatDoesNotCascadeTest`, `…::testAnOwnerGoingWhoseJoinColumnsDoNotCascadeHasItsLinksReadAndTakenAsFacts`) — since 5.3c the join rows Doctrine deletes right before its owner's row are the removal's. The read may be one SELECT per removed owner for nothing. **Since (E, 2026-10-04): the read is gone, and its mutants with it.** The reviewers asked for the other mechanism and for the scenario where the read might be the only source: Doctrine deletes the join rows by the owner's key right before its row, in the same transaction — both stand or fall together (`ALinkThatDoesNotCascadeTest::testAnOwnerWhoseDeleteTheDatabaseRefusesKeepsItsLinksAndItsHistory`: the row's DELETE refused, the flush rolled back, the team and its link stand, no record); a join-row DELETE standing while its owner's is taken back needs SQL of the application's between them, and then Doctrine is not removing the owner and the read never ran (`WhatTheLinksSayAsAListTest::testLinksTakenRightBeforeAnOwnersDeleteTakenBackStayTheirOwnMove` is that case, unchanged). The case the read was for — an owner another process wrote, its collection never loaded — now asks nothing and says only that it went, with nothing it could not follow (`…::testAnOwnerGoingAsksNothingOfItsLinks`, red before). The FK-off corpus the other reviewer asked for cannot reach the read: the model's world has no owner whose join columns do not cascade — said rather than run |
| `bbb303f9`, `74a5273d`, `3ef913a2`, `9559bc40`, `f64cbd02`, `00e1ff04`, `653281a2`, `e2faf3f8`, `a80d9da2`, `2656a909`, `6a825917`, `7dce50cb`, `eca40afe` | RowMemory 170, 645–711 | a foreign key's value in a remembered row: of a target keyed by an association, past a collection, through a type | not yet told apart: an entity's own record takes its old values from elsewhere (`AReferenceToARowKeyedByAnAssociationTest`, lockers and cards, green under all thirteen); the elements' run with an owner keyed by an association was added at the end and not probed |
| the other 48 (caches and counters 89, 422–448, `statementsRead`, `size`; `settle`/`remember` weak references 91, 240, 250, 525–527, 605; chunk sizes 152; 156, 161, 165, 185, 189, 194, 211, 232, 238, 243, 251, 303, 338, 341, 471, 615; JoinRowsQuery 122, 128) | | | not yet told apart |

**Code defect found (fixed, 1.3 only)**: `aboutTheOwnersOf()` put the owner an element points at and
the one it pointed at through `array_unique(…, SORT_REGULAR)` — a comparison with `==`, field by
field. Before `ed32e37` the two were the same object; since, they are the two owners of a moved
element, and where the first field compared is a collection, PHP walks owner → elements → owner
until it ends the process: *Fatal error: Nesting level too deep*. Found by the fixtures added for the
mutants (`MemberAccount`, whose collection is declared before its key). Fixed by telling the owners
apart by identity; `AReferenceToARowKeyedByAnAssociationTest::testAnEntryMovedBetweenTwoAccountsLeavesOneAndJoinsTheOther`
dies under the old line. My own regression of the morning, through a line written on 2026-09-24.

### SQL with comments is not read (found 2026-10-04, FIXED: `e41a29c`, `e01c2c9`, README in the next)

The reviewers chose to take comments out outside literals and quoted names, by the connection's
dialect (`#` on MySQL only), with what the rules leave in doubt said as doubt and never passed over
as a read; a middleware's order documented besides, not instead. `ReadableSql` reads a copy; the log
keeps each statement as it ran. Guarded end to end
(`WhatAQueryTaggerLeavesOfTheHistoryTest`: the same history with no tagger and with a comment
before, after and to the end of the line, each outside the observer and between it and the
driver) and in the log and the replay (`WhatACommentLeavesOfAStatementTest`, `ShadowHistoryTest`);
each part seen failing with it taken out. Green on the seven cells. The finding as it stood:

Found by the mutants of `HistoryReplay::replay()` line 193 (the pattern that tells an unread
statement that writes from one that does not). The log reads a statement only as the persisters
write it; `StatementShape` refuses comments, the savepoint patterns want the whole statement, and the
"writes but unread" pattern is anchored at the start. Measured:

- by hand, against the fixtures' mapping: `/* why */ UPDATE CrateItem SET quantity = 2 WHERE id = 2`
  and `WITH x AS (…) UPDATE CrateItem …` are neither a fact nor a doubt — a watched row changed in
  silence; `update CrateItem c set …` (an alias) is doubt, as meant;
- end to end, with a middleware of the application's around the observer that comments every
  statement (what a query tagger does — sqlcommenter, OpenTelemetry's Doctrine instrumentation with
  its option on): a comment **after** each statement — no record at all of a create or an update,
  only "could not be followed" warnings; a comment **before** each statement — no record and **no
  warning**: the whole history gone in silence. Without the middleware, the same flushes give
  their records.

It needs the tagger outside the observer: the bundle tags its middleware `doctrine.middleware`
with no priority. 1.2.x read Doctrine's change sets and does not have it — a regression of the
1.3 branch. Ways out, for the reviewers to choose: strip comments outside literals before the log
reads a statement (one place; savepoints, shapes and the "unread" pattern all mend); strip only a
leading and a trailing one; or document the order and have `audit:check` say so. The probe's
middleware and the test-case hook are kept aside (scratchpad `commented-sql/`), not committed: a
test now would pin the defect.

### Dead code taken out (F of the reviewers' plan, 2026-10-04)

Each a class of 1.3's or marked internal, so no public contract: StatementLog's `open` flag
nothing read, the loops of `rolledBack()`, `release()` and `rollBackTo()` that wrote false over
false, and `voidFrom()`'s `$after` every caller passed as 0; ChangeSetBuilder's change-set arm of
`withAlwaysRecorded()`, which no caller reached (5.4's remains); RowBinding's `among()` and
`inHierarchy()` — `of()` reads every mapping, the root of each hierarchy among them, and the one
test that used `among()` for a table in a schema now reads the `Shop` mapping. The sort in
`drafts()` stays, as the second reviewer asked. Fingerprints of the model — documents and
queries — identical before and after on 1,000 sequences of the default vocabulary and 1,000 of
5.3's. Their mutants go with them: StatementLog `a9090257`, `9eb4abfc`, `3b3dd746`, `139c0b74`,
`75ef7ade`, `deedebdd`, `3e3b77ab`, `01ef8c19`, `6c80ade2`, `60a8c85e`, `3caab360`, `ded1ab2f`,
`76f53404`, `f62486af`, `760f0ac7`; ChangeSetBuilder `1dbd887d`, `883e36c2`, `43270aee`,
`5d51d1ff`; RowBinding `f35a22f4`, `1aa8bb81`, `e120f442`, `74a9e982`, `89fd2f81`.

### The last 193: HistoryReplay, RowBinding, LinkRuns, LinkFacts, EntityRowRuns, RowIdentity, ChangeSetBuilder (2026-10-04)

Every one against the whole Doctrine suite as it stands: 4 red (HistoryReplay `95d5a713` 463,
`1d9ea283` 967, `337e810f` 1149, `fb088d73` 1299 — closed by the tests of this triage), 189 not.
Tier (b) had run all of them against 2,000 seeds of the model with no kill. Then:

- **Test gaps, closed**: HistoryReplay 193 `c1354d7d` and 194 `1c0af2ce`, `f41191f6` — a write that
  cannot be read is doubt in lower case, with its table quoted, and where it cannot name the table
  (`ShadowHistoryTest::testAWriteThatCannotBeReadIsDoubtWhereItWritesAWatchedTable`); RowBinding 140,
  149, 185, 186, 211 (×2), 215 — an UPDATE of an owner's column or of a join table is no emptying and
  no target taken out, an emptying by a to-one declared after others, an inexact WHERE on a join
  table (`RowBindingTest`, six cases).
- **For the reviewers**: HistoryReplay 193 `07fb9e35` (the caret) — the mutant is the better
  behaviour: it makes `/* … */ UPDATE watched …` doubt instead of silence; see "SQL with comments".
  RowBinding 250–256 (`f35a22f4`, `1aa8bb81`, `e120f442`, `74a9e982`, `89fd2f81`): `inHierarchy()`
  changes nothing while `among()` is given every mapping — the root is always among them, and
  `among()` has no other caller: a seam and a rule that can go. ChangeSetBuilder 123–124 (`1dbd887d`,
  `883e36c2`, `43270aee`, `5d51d1ff`): the change-set arm of `withAlwaysRecorded()` is unreachable —
  its one caller with a change set, `build()`, hands it a declaration without always-recorded
  fields; the `$changeSet` parameter is dead.
- **Deliberately not tested**: the reason a doubt or a binding carries, read only by the tests'
  shadow — HistoryReplay 198 (`373d055b`, `00deabfb`, `53b10853`), RowBinding 89 (3) and 219 (9).
- **Equivalent**: defaults every caller passes (HistoryReplay 158 `f5a70d18`, 159 `042324c2`, 168
  `af339386`); `$this->log?->` where facts are made only inside `replay()`, after the log is set
  (336 `44f91824`, 394 `36b00d72`, 403 `601cc4dc`, 525 `f613cd77`, 1208 `5439188e`); keys of lists
  read by `foreach` or made a list again (323 `66fc8acf`, 365 `c8c70b7c`, 378 `a75b2d0f`, 463
  `4283701e`, 603 `b574ac19`); casts the comparison makes anyway (241 `f8b9c813`, 511 `b5511170`,
  1228 `e32d3298`); maps read by `isset()`/`array_keys()` (1029 `bacb9707`, 1267 `e9540ccc`, LinkRuns
  122 `36300b66`); caches (884 `7ce508f8`, 1157 `118efc65`, 729 `5f262861`); RowBinding 122
  `78edd2b8`, 159 `3a9bfaed`, 179 `c3ce6a84`, 237 `f318dfdb` (a version field without versioning, a
  referenced column or a join column without its name — the mapping never has one); RowBinding 207
  `2419511a`, `3333c99a` (both keys picked is what makes the columns the same); ChangeSetBuilder 123
  `c9ba90cc` (the context holds every always-recorded column the row has).
- **Not reached**: HistoryReplay 417 `f3405c15`, 468 `d56b625a` (an exception for a fact asked of a
  place never filled, and for a manager gone — a caller's mistake); 1250 `edc2125b`, 753 `824f44e8`,
  1004 `313bf209` beyond what the database cells below say (a count in a string is a driver's for
  counts past an integer); 1473 (3) — a key that is a backed enum, which no fixture has.
- **Killed outside coverage**: HistoryReplay 745 `9ab1e512`, 755 `9537630d`, 790 `aad5f8b4` — red on
  MySQL (DBAL 4), which counts the rows an UPDATE changed rather than the ones it found
  (`AnUpdateThatReachedNoRowTest`, `ShadowHistoryTest`, `WhatANestedFlushLeavesOfTheOuterOneTest`).
  Not red on MySQL: 729 `052a74b9` (the platform test negated), 755 `5766f978`, 756 `97559c8c`, and
  753, 1004, 1250 — not yet told apart. On PostgreSQL (DBAL 4) none of the ten is red: it counts
  the rows it found, as SQLite does. The gate runs SQLite only; the three are in the matrix's MySQL
  cells, which CI runs — whether the gate should count them is for the reviewers.

  **Since (2026-10-04, B of the reviewers' plan):** the rule is the bundle's, not MySQL's, and now
  runs where the gate does. `HistoryReplay` takes what an UPDATE's count is of as an optional
  argument (null, the platform's, as before); `WhatAnUpdatesCountMeansTest` replays the same
  statements counted both ways on SQLite, and pins separately which platform counts which
  (MySQL changed, PostgreSQL and SQLite found). The reviewers' other way — a test middleware
  forging the count of one UPDATE — was not taken: the count alone does not reach the rule, which
  asks the platform, and a platform cannot be forged under a live SQLite connection. MySQL's cells
  stay the proof on the real thing. Recorded "killed on MySQL" (CI 37184194983's matrix, the three
  tests above). On SQLite now, by hand on today's code (the targeted Infection run was stopped:
  with one test, HistoryReplay's ~1,300 mutants spend an hour in time-outs): the gone row's return
  removed (`9ab1e512`), the condition negated (`9537630d`), the found-rows return removed, the
  rule never or always applied, a missing count read as none — six of six red. `aad5f8b4`'s line
  is gone with the defect below.

  **Code defect found by it (MEDIUM, MySQL only, in 1.3 from its start, no release had it):** the
  rule believed MySQL's "no row changed" only for a row's own audited fields. A line of a
  collection is no audited row, so its UPDATE was read as any other: a crate's line that **another
  process deleted**, then changed through Doctrine, came out as a change of `items.N.quantity` — a
  line that was not there, in the crate's history. PostgreSQL and SQLite were right, by their
  count. Measured on MySQL (`AnUpdateOfALineThatWasGoneTest::testALineAnotherProcessDeletedChangesNothingEither`,
  red before, green after; a line the application deleted on the same connection was right
  already — the log hears that DELETE). Fixed by believing MySQL's count as it is said: none
  changed means the row is as it was, line or audited row, whatever is remembered of it. The rule
  it replaces read the statement "as any other" where what is remembered agreed nothing moved —
  which wrote an expression's column as unknown in a row the database had just said was unchanged.
  A row nothing remembered, counted none, stays doubt on MySQL (there may have been one) and
  nothing on the engines that count what they found
  (`WhatAnUpdatesCountMeansTest::testAnUpdateThatCountedNoneOfARowNothingRememberedIsDoubtOnlyWhereNoneMayBeARowThatWasThere`).
- **Not yet told apart** (the rest, about 110): HistoryReplay 187, 232, 252, 287, 289, 312, 393 (2),
  394 `c986ae11`, 447 (3), 496, 525 `ae14d94a`, 544, 603 `9bd7d2fa`, 611, 626, 638, 654 (2), 709, 813, 832, 839, 858, 876, 885, 966, 972 (2), 1031, 1066, 1067,
  1071, 1073, 1097, 1100, 1142, 1146 (3), 1221 (2), 1257, 1264, 1268, 1304, 1336, 1367, 1449, 1462,
  1463; RowBinding 148 `de305c8d`, 207 `7838e574`, `a0909469`, 242 `34b1697a`; LinkRuns 70, 90, 136
  (2), 143, 151, 152, 169, 189, 196, 201 (3), 212, 213; LinkFacts 75, 126 (3), 170 (2), 236, 288, 291
  (2), 299 (2), 385, 390, 392 (5), 399, 407; EntityRowRuns 248 (2), 314 (3), 397, 424 (2), 437, 438,
  443, 485, 493, 530 (2); RowIdentity 43, 48, 52. Among them, worth a fixture each: an `old`/`new`
  flag handed to a representer (LinkRuns 151–152, EntityRowRuns 437–438 — whether the target is shown
  as it stood once or each time), the order of a list of mixed numeric and string keys (LinkRuns 201),
  and RowIdentity's derived and composite keys, which the new `MemberAccount` fixtures reach but did
  not tell apart.

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
| `898bea1e`, `abf2e221` | ClientFactory 24, 40 | TLS verification off by default; the log gate not set | test gap — `ClientFactoryTest::testTheClientChecksTheClustersCertificateUnlessToldNotTo` reads `verify` off the built client's Guzzle, `…::testTheClientLogsThroughTheGateAndOnlyWhenGivenALogger` its logger; each seen failing with the default flipped, the call removed, the gate left out. The finding the reviewers asked for (2026-10-04): verification is **on** by default, in the configuration (`ssl_verification`, `defaultTrue()`, pinned by `ConfigurationTest`) and in the factory; off only with `client.ssl_verification: false`. No defect; the option is now described in its `info()` and in the README, with what turning it off gives up |
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

## G: the escaped of CI 37197603893 (`24d23d8`), by behaviour

**For the reviewers, the summary (2026-10-05).** Counted from the tables below, and for
AuditSubscriber with the 69 that keep a class given against 4261bdb: of about 460 escaped,

| Class | Mutants | What it means |
|---|---|---|
| test gap, closed | 86 | a test written for it, red under it (each probed: the mutant applied, the test failing) |
| equivalent | ~235 | with the reason in its row: an invariant of the supported code, PHP's own semantics, or a reader that reads nothing the mutant changes |
| not reached | ~41 | with the invariant or the missing fixture named |
| dead code, taken away | 14 | defaults, fields and parameters no caller used; their mutants are gone with them |
| H (nested keys) | 7 | the separators of keys that are nested arrays now |
| killed elsewhere | 6 | red in a cell the gate does not run: DBAL 3 without savepoints (3), ORM 2.19 (2), MySQL (1) |
| for the reviewers | 6 | the late record's label (5, a code defect found against 4261bdb, not fixed) and a write behind a common table expression (1, below) |
| open | 57 | searched and not told apart; 38 of them the flush stack of AuditSubscriber |

Code defects found in G: **MEDIUM**, a link a mapped superclass declares left no record (a 1.3
regression; fixed, `85f6e97`); **LOW**, a write behind a common table expression is no doubt (fixed
after the reviewers chose (a), below). Measured on `8e15dc2` (CI 37216755055): Doctrine 4,041 mutants, 359
escaped, 25 timed out, **91.12 %** (90.50 % counting only what a test failed on), floor 94; main
98.16 %. Not measured since: H. On the threshold the two
of you differ (R1: the measured value with its reason, 94 for 1.4; R2: 94 stays the condition) —
the open 57 are what stands between.

The reviewers' G: every mutant still escaped after A–F gets a class, grouped by what it changes,
"not reached" only with an invariant of the supported code. Measured there: Doctrine 4,048 mutants,
460 escaped, 23 timed out, 88.64 % (88.07 % counting only what a test failed on). The ids below are
that run's; the earlier sections' are 4261bdb's, and the two are matched by file, mutator and diff.

### RowBinding (22)

| Ids | Line | Change | Class |
|---|---|---|---|
| `31321aae`, `c47f54a8`, `ea0fb293`, `198037eb`, `53167af9`, `6dfe01dd`, `81bc976a`, `8e4c243d`, `a9ec028d`, `bb673421`, `bbdf12db`, `d2670637` | 80, 210 | the reason a statement is left unbound | test gap — the reason is the binding's to say, and is now said: `RowBindingTest::testAStatementItCannotBindSaysWhy` (it was classed "deliberately not tested" against 4261bdb; pinning it costs nothing) |
| `8aee2111`, `fd444a46`, `b8e912f9`, `f10b6c61` | 139, 198, 233 | a key or a foreign key of two columns read as one | test gap — no fixture had one: `Bay` (keyed by code and aisle) in the `Shop` mapping, its own join rows, a `Rack`'s join rows to it, a `Slot`'s foreign key to it (`…::testAKeyOfTwoColumnsIsNamedByBoth`) |
| `f380eb23` | 198 | both keys picked are enough, whatever else the WHERE names | test gap — `…::'a join row's key and another condition do not prove the row'` |
| `858726c0`, `1d5ef5a2`, `31d08685`, `3f3d04d5` | 113, 150, 170, 228 | a version field without versioning; a join column without the column it references; a join table with no columns on a side; a join column without its name | **equivalent**, by Doctrine's completion of a mapping: it sets a version field only with versioning, completes every join column with its referenced column and name, and a join table with both sides' |
| `e7a54efc` | 198 | `owner \|\| element` before the columns are compared | **equivalent** by construction: the columns are the same only when both keys were picked |

### AuditMetadata, RowIdentity, JoinRowsQuery, the log's new lines, the middleware (16)

| Ids | Line | Change | Class |
|---|---|---|---|
| `e1188b37` | AuditMetadata 56 | a field of an element named by no name let through | test gap — `AuditMetadataFactoryTest::testAFieldOfAnElementIsNamedByAName` |
| `87aa1fff`, `91c0887c` | AuditMetadata 89 | a collection tracked as `false` among the tracked ones; the list's keys | test gap — `…::testACollectionWhoseElementsAreNotTrackedIsNoneOfTheTrackedOnes` |
| `7c90e87c`, `5a4b8732` | RowIdentity 43, 52 | a key of two columns followed by one; the entity held not returned unless a reference was asked for | test gap — `WhichEntityAForeignKeyNamesTest` |
| `c97c76db` | JoinRowsQuery 128 | an owner keyed by two columns selected by one | test gap — `WhatTheJoinRowsHeldTest`, an owner of two columns |
| `b6783bb2` | Dbal4/ObservedConnection 53 | a failed `exec()` not in the log | test gap — `WhatTheConnectionShowsTheLogTest::testAStatementThatFailsWithoutParametersIsRecordedToo` (exec and query) |
| `376fa815` | StatementLog 674 | an INSERT anywhere in the statement taken for its own | test gap — `WhatACommentLeavesOfAStatementTest::testAKeyIsNotTakenForAStatementWithAnInsertInItsValues` (comments are taken out, literals kept) |
| `cdf96c70` | ObservingMiddleware 54 | the log told no dialect | **equivalent on SQLite** — SQLite's rules and the ones all share read alike — and **killed on MySQL**: `WhatAQueryTaggerLeavesOfTheHistoryTest`, a hash comment, MySQL only (red there, two failures) |
| `58d66810` | AuditMetadata 49 | `!== true \|\| !== false` before `=== []` | **equivalent**: an empty list is neither, so the first half decides nothing — a guard that can go |
| `86f01d2d`, `dbae68c6` | JoinRowsQuery 122 | `array_fill()` from −1 or 1 | **equivalent**: `implode()` reads no keys |
| `fdb755d0` | RowIdentity 48 | the key converted through its field's type for an association too | **equivalent**: an association field has no type, and the conversion hands the value back |
| `2c22036c` | StatementShape 379 | a quoted name searched for its closing quote one place on | test gap (was "not reached": SQLite takes an empty quoted name) — `StatementShapeTest`, an empty quoted name |
| `fcb7bb98`, `7d0f476c` | StatementShape 424 | a comment told by the character before | **equivalent**, and since A2 by an invariant: every reader of a statement in the bundle reads `ReadableSql`'s copy, with its comments taken out, so a comment outside a literal never reaches this tokenizer |
| the other eight of StatementShape | 133, 401, 411, 428, 435, 436, 443, 444 | as classed against 4261bdb (the file did not change) | **equivalent** |
| `b5a40063`, `67a21e20`, `263ef12c` | StatementLog 302, 696, 712 | `(int)` dropped; `break` → `continue` past the statements let go; `true` → `false` in a map intersected by its keys | **equivalent**: a count in a numeric string compares as a number; the statements are in order, so every one after is past it too; `array_intersect_key()` reads no values |

### WatchedRows (17), and a defect found on the way

No fixture had a mapped superclass, which three of its rules are about. Two are added —
`Labelled`, abstract, declaring a `Poster`'s audited stickers, and `Badged`, one that can be made
and declares itself audited — and they found a **code defect (MEDIUM, a regression of the 1.3
branch, 1.2.5 records it; fixed `85f6e97`)**: `RowBinding::of()` read every mapping, mapped
superclasses among them, and bound the join rows of a link a mapped superclass declares to the
superclass, a class with no rows and no history — adding or taking off a poster's sticker left no
record at all. Doctrine names the join column after the superclass (`labelled_id`), so the
superclass's mapping was the first to fit. Now a mapped superclass owns no rows
(`ALinkAMappedSuperclassDeclaresTest`, red before).

| Ids | Line | Change | Class |
|---|---|---|---|
| `ae4625be` | 84 | a mapped superclass not passed over for the owners of a target's links | test gap — red with `Badged`: it would be a second owner of the board's links (`…::testASuperclassThatDeclaresItselfAuditedIsStillNoOwnerOfRows`) |
| `694b9fe6`, `69f3f51f`, `49577eea`, `50a6842e`, `ed943cf6` | 130, 170 | a mapped superclass or an abstract class not passed over for what is shown as it stood and which tables are history | **equivalent**, now against both superclasses: an abstract one cannot be made (`newInstance()` throws and is caught), and one that can declares what its entity declares — the same target pointed at, the same join table; it adds only a table name of its own no statement names |
| `2802eeea`, `d7654f66`, `097437ed`, `efaf8c44`, `e0e70282` | 50, 61, 76, 122, 198 | a cache not kept | **equivalent** in what is decided |
| `c03a3388`, `c8e9afc1`, `5e6d8e4c`, `6f2c65e9` | 142, 175, 185, 193 | `true` → `false` in a map read by `isset()` | **equivalent** |
| `dfde30e5` | 184 | a join table's name `\|\|` empty | **equivalent**: Doctrine always names it |
| `13949965` | 265 | `return false` in the catch | **equivalent**: the method returns false right after |

### LookRightAfter (15), NobodysStatement (2), ElementFieldRuns (13)

No test made the look fail, and none had a key that is not a number: the whole road of a refused
look was untested, on every database.

| Ids | Line | Change | Class |
|---|---|---|---|
| `7cff9aba`, `4103b9cf`, `ba57a98f`, `b2a992f2`, `b317c982`, `b1fcc099`, `e34928dd`, `f42f4bb6`, `c3fda286`, `b519fdca` | LookRightAfter 73, 92, 97, 98 | a refused look inside a transaction not rolled back to its savepoint, or the statements that do it changed | test gap — `ALookThatFailsTest` (the look refused by the database below the observer: exactly `SAVEPOINT`, `ROLLBACK TO SAVEPOINT`, `RELEASE SAVEPOINT`, the flush commits, the doubt is said); green on all four database cells |
| `ed6ab06d` | LookRightAfter 68 | a value that is no number bound as one, and a number as text | test gap — `WhatALookBindsTest`: a key `'abc'` bound as a number is 0, and finds the holder of `'0'`. The other half, a number bound as text, is **equivalent** on the three databases: each compares `'5'` with an integer column as 5 |
| `ec4d37f8` | LookRightAfter 41 | the return for a statement the log did not keep | **equivalent** by an invariant: the log keeps no position only for a savepoint's statements (never a `DELETE`) and for a table no history is about, and a watched table is one — the target of a watched link is a history table (`WatchedRows::isAHistoryTable()`, `linksTo()`), and the manager that watches has flushed, so its tables are kept. Without the return the loop has nothing to ask |
| `a9a2b2de`, `a36c96f7` | LookRightAfter 71 | `array_map('array_values')`, `array_values()` taken away | **equivalent**: `fetchAllNumeric()` gives a list of lists |
| `33124d94` | LookRightAfter 93 | a refused look outside a transaction rolled back to a savepoint all the same | **equivalent**: outside a transaction no savepoint was taken, the `ROLLBACK TO` and `RELEASE` fail on every database and are caught, and in autocommit a failed statement leaves nothing behind. Reached only by a watch that outlived its flush |
| `2d8f3cd4`, `a01458ce` | NobodysStatement 35, 37 | the warning said while a flush ran dropped; both warnings said | test gap — `NobodysStatementTest`, of the rule alone: the statement no flush claimed while one ran is a hole no flush test reaches while the listener works, which is why it is a warning |
| `4954b786` | ElementFieldRuns 97 | `continue` → `break` past a removed owner's lines | test gap — `WhatAnOwnersRemovalLeavesOfTheNextFlushTest`: a line of another owner deleted after the removed one's lost its record |
| `0ebd03d1`, `23aa8e10`, `abb4db75` | ElementFieldRuns 62, 81 | `$consume`'s default; `$duringAFlush` tested against null | **dead code, taken away**: the one caller passes both. Both are required now, and the mutants are gone with them |
| `e8c95e51`, `53bbe34b`, `727edcba`, `c8d6bfd4`, `33d6122c` | ElementFieldRuns 152 | the `?? 0` of a run's positions | **dead code, taken away**: every run has its changes, its positions and its context, set where the run is made. `33d6122c` (`'at' => 0`) also asked whether a run's last position is observable: it decides only the context, when the run joins an owner's record and ran after it, and within one flush nothing changes the owner's row after the record's last statement — the row the line saw is the record's; and a link run that joins the line's record runs after it (Doctrine writes join rows after every entity's UPDATE), so it gives its context either way. Ordering reads `since`, which is set |
| `ed67afe2` | ElementFieldRuns 118 | `MEMBER \|\| field === null` → `&&` | **equivalent** by construction: a member's fact is the only one with no field (`HistoryReplay::member()`, `FIELD` always names one). The second half stays for the type |
| `572817c3`, `caeda453`, `a2b64dbb` | ElementFieldRuns 134 | the owner's name in a string: operands swapped or the separator dropped | goes with **H** (nested keys instead of strings). Until then: swapping is equivalent, and dropping the `\|` collides only for two owner classes whose names differ by a trailing digit, each with a key that makes up the difference |

### JoinRowMemory (13)

| Ids | Line | Change | Class |
|---|---|---|---|
| `5c267775` | 188 | an account's `takenAt` | **dead code, taken away** (`e7c9077`): no reader asked where the log stood when a link account was taken, and `settle()` took a position only to write it there |
| `4b076fc2` | 188 | a holder read with no key in its account | test gap — `WhatTheJoinRowsHeldTest::testSettlingKeeps…` says the whole account of a holder read |
| `3900a9fe` | 253 | `continue` → `break` past an account let go at settling | test gap — the same test, with the ones let go first |
| `a385a405` | 91 | `?->` → `->` on an account with no object yet | test gap — `…::testAHolderReadIsTheAccountOfTheOwnerThatComesForItsLinksLater` (an error before) |
| `dfc64843` | 91 | an owner loaded again not taking the account over | test gap — `…::testAnOwnerLoadedAgainIsTheAccountsObjectFromThenOn`: settling let the account go with the dead object, and the next flush would ask again |
| `49864ae3` | 238 | `&&` → `\|\|` for an owner with no key | test gap — `…::testAnOwnerWithNoKeyThatIsGoneBySettlingIsLetGo` (an error before) |
| `d8a6a1fa` | 189 | `??= null` → `= null` | **equivalent** by an invariant: an object is kept only beside an account (lines 92, 117, 240, and `settle()` keeps them together), and this line runs only where there is none |
| `10747716` | 240 | `??=` → `=` for an owner with no key when its links were remembered | **equivalent**: its key is new — its INSERT handed it out in this window — so no other object of that row can be beside an account for it |
| `16d5fe8b` | 250 | the key from the account and from a new owner's, in the other order | **equivalent**: both are the key of the same row, the account's id being made from it |
| `bb797b37` | 194 | `(string)` dropped from an array key | **equivalent**: PHP makes a string of digits an integer key anyway |
| `a6e705d0` | 166 | `array_values()` dropped | **equivalent**: `JoinRowsQuery::holdersOf()` reads only the values |
| `b59f136f`, `4283a305` | 152 | parts of 499 or 501 targets | test gap — no test had more than one part at all: `…::testTheHoldersOfManyTargetsAreReadFiveHundredAtATime` (500 targets one question, 501 two, a holder of the last part read as one of the first's; the docblock promises "a question for every five hundred targets") |

### LinkRuns (16)

| Ids | Line | Change | Class |
|---|---|---|---|
| `2f6024da`, `e99b5b6a` | 136 | the runs not sorted, or sorted by a comparator that always says "less" | test gap — `WhatTheLinksSayAsAListTest::testContributionsAreSaidInTheOrderTheyBeganAcrossOwners`: an owner's second contribution after another owner's first. (A first try with one contribution each did not fail: the facts come sorted, so the owners are already in the order their first contributions began) |
| `afd80889`, `c4f66f00` | 151, 212 | the old list shown as the rows were once its first statement ran; the object removed asked before the row | test gap — `…::testAWatchedTargetGoneIsShownAsItsRowStoodAndNotAsTheObjectWasLeft`: a relay renamed on the object and removed, Doctrine writing no UPDATE of a row it deletes |
| `61ae9bde` | 169 | a flush begun right after the last statement read as begun between | test gap — `…::testAFlushBegunRightAfterAContributionsLastLinkDoesNotSplitIt` |
| `8f22585f` | 201 | keys compared as numbers where only one is | test gap — no fixture had a target keyed by text: `Ticket` (keyed by a code, some of them digits) and `TicketBook`; `…::testKeysOfTextAreComparedAsTextWhereEitherIsNoNumber` |
| `291f1303` | 143 | the association in what a run says | **dead code, taken away** (`d74f422`): no reader asked for it |
| `fcdc89d8` | 90 | a mark at the statement's own position applied after it | **equivalent** by an invariant of LinkFacts: marks are at the owner's own row statements and position 0, and no fact is made there — an INSERT of the owner's row is no link statement, and at its DELETE the row's event comes before a target's at the same position (a row that is its own target) and leaves nothing to take |
| `96367b85` | 152 | the new list shown as the rows were before its last statement | **equivalent** by the same: a contribution's statements write join rows or delete a target, and a target deleted is in no new list, so no target in it has its row changed by the last statement |
| `36300b66` | 122 | `true` → `false` in a map read by `isset()` | **equivalent** |
| `c247f4f8` | 70 | `(string)` dropped from an array key | **equivalent**: PHP makes a string of digits an integer key anyway |
| `340f5449` | 196 | `array_values()` dropped before `usort()` | **equivalent**: `usort()` gives a list |
| `14697271`, `e1dfdaf5` | 201 | one `(float)` dropped where both are numeric | **equivalent**: PHP 8 compares a numeric string with a float as numbers |
| `0625219d` | 213 | the managed entity asked before the object removed | **equivalent**: an object removed is no longer managed once its DELETE ran, and one persisted again before is no removal |
| `74d2409a` | 189 | `?->` → `->` on the owner's declaration | **equivalent** by an invariant: a link is watched only for an audited association (`WatchedRows::areLinksWatched()`), so its owner always has a declaration; and either way the failure goes through `$failed` |

### EntityRowRuns (34)

| Ids | Line | Change | Class |
|---|---|---|---|
| `b9cf1acc`, `92ab3d4d` | 248 | the places of a completion's facts not turned back | test gap — `WhatTheListenerLetsGoOfTest::testTheUpdateThatCompletesACreationIsLetGoOfWithIt`: the completing UPDATE's fact was kept whole after its record was written |
| `6786ccef`, `ef0bc825` | 437, 438 | a reference's old side shown once the first statement ran, the new side before the last | test gap — `WhatTheLogMustKeepOfTheEventsTest::testAReferenceToTheRowItselfIsShownAsItStoodOnEachSide`: a relay that was, or becomes, its own next, renamed by the same UPDATE |
| `770036b8` | 493 | a reference to a row an earlier DELETE of the reading took not shown as it stood before it went | test gap — `…::testAReferenceToARowAnEarlierFlushDeletedIsThatRowAsItStoodBeforeItWent` (no foreign key; skipped on PostgreSQL, whose keys a session cannot switch off) |
| `c09682af`, `3153a06e` | 314 | the loop asking whether a flush began between a creation and its completion, not run | **killed on DBAL 3** (`orm_cell_probe`, es-audit-dbal3): `WhatAnEntitysRowsSayOfItsRecordsTest::testAReferenceGivenThroughAFlushRightAfterTheCreationIsAChange`, without savepoints. On DBAL 4, where the mutation run is, a nested flush always has a frame and an owner of its own, so the check of the flush above decides first: measured, the two statements are owned by 2 and 3 |
| `085d24a7` | 397 | the next position *or* no flush begun | **killed on DBAL 3**, as above: `…::testTwoChangesOfAJoinedRowEachOfOneTableAreTwoExecutionsWithoutSavepoints` (three failures) |
| `3f8684ca` | 314 | the loop asking one position further, past the completion | **open**: two attempts to begin a flush right after the completing UPDATE and before its flush is read (a second flush in the application's transaction; a `postFlush` listener ahead of the audit one) did not fail on it. Not classed as equivalent until the reason is found |
| `c8894974` | 94 | `each()`'s `$consume` default | **dead code, taken away**: `each()`'s two callers pass every argument; its defaults are gone (`of()` keeps its own, for the readings of the tests) |
| `cbb6b843` | 80 | `iterator_to_array(…, true)` | **equivalent**: a generator's own keys run 0, 1, 2, … as it yields |
| `80e76eaf`, `4087a61e`, `4f4b022d`, `d245b722`, `db994545` | 111, 113, 116, 126 | `?->` on the shape of a fact's statement; `(string)` of its table; `&&` → `\|\|` of two variables set together; `true` → `false` in a map read by `isset()` | **equivalent**: a row fact is made only while its statement's shape is read (`HistoryReplay`), so the shape and the table are never null here; `$facts` and `$open` are set and cleared together |
| `1aa246e0`, `06548cc7` | 148 | `$duringAFlush` tested against null | **equivalent** by the callers: the listener always passes it; null comes only from `of()`, read by tests without `$consume`, where this line is not reached |
| `0cfd063f` | 494 | `?->` → `->` on the objects removed | **equivalent** by the callers, the same way: the listener always passes them |
| `b4842471`, `eb940a6c`, `91792d8f`, `339ed24a` | 215 | a row's name in a string | goes with **H** |
| `e090bad1`, `c6a6250d`, `50bc4596` | 324, 325, 329 | `&&` → `\|\|`; `(array)` dropped; `true` → `false` in a map read by `isset()` | **equivalent**: only an owning to-one association has join columns at the mapping's top, so the others add nothing, and for that one the entry is always a list |
| `7ddba305` | 343 | `true` → `false` for a column the INSERT wrote with a literal | **equivalent**: the value is read only as "not null", which both are |
| `7cb8361c` | 350 | `\|\|` → `&&` | **equivalent**: an UPDATE fact's statement always has a shape, and an UPDATE always assigns something |
| `f9c2e4e2`, `e52d7e37` | 424 | `\|\|` → `&&` among three guards | **equivalent**: none of them is ever true — a row fact is made only for a class with a declaration (`HistoryReplay::rowFact()`), with its whole key, and of an INSERT, UPDATE or DELETE, each of which has an event |
| `ae0310ba` | 443 | a new instance before the managed entity | **equivalent**: the builder reads the object only for an always-recorded field the row has no column of, and the context is the row's; an association, which has no column, is skipped |
| `30ba6192` | 485 | no return for a null key | **equivalent**: a null key finds nothing on every road, and `byForeignKey()` answers null for it |
| `1eb142c1`, `3bd7857d` | 530 | the always-recorded fields not filtered to the row's, or not a list | **equivalent**: `withAlwaysRecorded()` skips an association, the only kind the filter takes out, and reads the values only |

### LinkFacts (37)

| Ids | Line | Change | Class |
|---|---|---|---|
| `827db691` | 385 | an account's statements undone first to last | test gap — `WhatTheLinksSayAsAListTest::testAnAccountTakenAfterALinkWasWrittenAndTakenAgainIsUndoneLastFirst`. A first version did not reach it: the owner already had an account from before, so nothing was undone. Now the account is let go of first (the object cleared, a flush settling it) |
| `44291fb4` | 236 | a target gone said as doubt only for an owner with an account *and* no holders read | test gap — `WhatTheListenerSeesOfACascadeTest::testOnceAListIsNotKnownEveryTargetThatGoesIsDoubtForItsOwner` |
| `4110e3fc`, `866b9ebf`, `dcd51525` | 288, 291 | the counting of a join table's statement ended by a target gone by its row; counting facts of other positions | test gap — `WhatTheJoinRowsSayTest::testTheJoinTablesStatementIsCountedByItsOwnFactsAfterATargetThatWentByItsRow` |
| `c9bff9c7`, `b73c6753`, `54cca641`, `5e30482b`, `0288692f`, `c3cdf59b`, `b262ab2b`, `05bdc1a7` | 389–399 | `\|\|` → `&&` among the guards of undoing | **equivalent** by an invariant: what an account holds to undo are the positions of statements bound to that owner's join rows (`HistoryReplay::linkPositionsOf()`), so each is in the log, read, bound, of that association and owner; the only one not undoable, all of an owner's rows taken at once, has no element, and the last guard returns for it whichever earlier one is mutated. `…::testAnAccountTakenAfterEveryLinkWasTakenCannotBeUndoneAndIsNotKnown` pins that case, which no test had |
| `f70f6dd3` | 407 | `(int)` dropped | **equivalent**: PHP 8 compares a numeric string with 0 as a number |
| `d9164260`, `851fe5c7`, `3b1b1131`, `27c15eb8`, `429b1d4f`, `bd887e6b`, `01ee01ac`, `970febc5`, `6c2cc532`, `bbf76f7b` | 133–144 | which of two events at one position comes first | **equivalent**: two events of one owner share a position only where its row is a target that went — a row that is its own target class, as a corner shelf is. Doctrine has deleted that owner's own join rows before its row, so its list is empty or unknown there, and either order leaves it so |
| `b4637b08`, `9abe6b37` | 146 | the first start at −1 or 1 | **equivalent**: every fact is at a position of 1 or more |
| `8b969fb3`, `974a2695` | 170 | the facts not made a list; another owner's emptied facts taken too | **equivalent**: the list is sorted, and so made one, before it is read. Another owner's emptied facts are never right before this owner's DELETE: Doctrine deletes this owner's own join rows between |
| `c67c9a84`, `e18db39c` | 299 | the facts not sorted, or sorted by a broken comparator | **equivalent**: their one reader groups them by owner, each owner's already in the order they ran, and orders what it makes by position (`LinkRuns`, pinned above) |
| `c7628561` | 126 | the owner's key from its statement before its account | **equivalent**: both are the owner's key, and every reader converts it through the identifier's type |
| `847f10f7`, `b853c891`, `3ba1c35c`, `989153b7`, `308238ac`, `3f7c786f` | 89, 106, 357, 367, 75 | `explode(…, 3)`; `true` → `false` in maps read by `isset()`; `<` → `<=` | **equivalent**: neither a class name nor a field name has `::` in it; a fact's statement is never the DELETE of its owner's row, so the two positions are never equal |

### RowMemory (48)

| Ids | Line | Change | Class |
|---|---|---|---|
| `66b90840`, `f2aad7b5` | 114, 115 | the rows about to be removed not remembered at preFlush | **killed on ORM 2.19** (`orm_cell_probe`, es-audit-orm219, two failures of `WhatTheListenerRemembersTest`): 2.19 takes them out of the identity map at `remove()`. On 2.20 and 3, where the mutation run is, the identity map still holds them |
| `2ac5dbac`, `a286c2b8` | 211, 216 | `continue` → `break` past a row already remembered; `?->` → `->` on a replay not made yet | test gap — `WhatAnEmptiedCollectionSaysAboutItsLinesTest::testACollectionNothingLoadedIsReadPastALineAlreadyRemembered`: one line remembered, one the application wrote, the first flush the listener sees among the cases |
| `1e53fdbe` | 216 | the replay running not told of a row read | **open**: the same test with a replay kept across a flush in the application's transaction did not fail on it |
| `4a661015`, `50627fbf`, `9925a9c7`, `de255a3d`, `da21bd48`, `2db8aa28` | 589, 624, 647, 660, 667 | a row remembered of a reference Doctrine holds nothing of; `continue` → `break` past a collection; a foreign key to a row keyed by an object or by an association | test gap — `WhatARowRememberedFromDoctrineHoldsTest` (new fixture `Satchel`, pointing at the Sku-keyed `Pouch`; `Locker` → `MemberCard`): no test had a row remembered from Doctrine's memory rather than from its INSERT |
| `a0ec5987`, `12801b50`, `076ee050`, `d803edcc` | 403, 542 | what a replay let go of read not counted; `size()` | test gap — `RowMemoryTest::testWhatAReplayLetGoOfReadStillCountsAmongTheStatementsRead`, and a size asserted before settling |
| `d27152f3`, `4cbec281` | 251, 301 | the failures of reading the links dropped; only the first failure of reading the holders returned | test gap — `WhatTheListenerSaysOfAQuestionThatFailsTest` (the questions refused by the database: one failure said, then three) |
| `d96a84ef` | 396 | `??=` → `=` | **dead code, taken away**: the one caller passing a position passed the log's own; `replayed()` takes none now |
| `b5a16668`, `d63cc505` | 232 | the `$flush` default | **dead code, taken away**: the one caller passes it |
| `5686048d` | 89 | a new `WatchedRows` before the one given | **equivalent**: it decides from the mapping alone; the one given is a shared cache |
| `ee3ebad6` | 156 | `\|\|` → `&&` | **equivalent** by the caller: this runs only for an audited inverse collection with orphanRemoval, whose elements are always watched (`WatchedRows::decide()`), and an inverse side's `mappedBy` is always an association |
| `164c18a1`, `cc03a315`, `eecd13fc`, `e9a28ece`, `362b9103` | 165, 185, 189, 623, 634 | guards on join columns | **equivalent**: Doctrine completes every join column with its name and referenced column, and only an owning to-one association has join columns at the mapping's top |
| `6ca08650`, `eca40afe` | 161, 170 | an owner keyed by two columns read by its first; the owner's key converted the other way | test gap, **not written**: no fixture has an inverse orphanRemoval collection of an owner keyed by two columns or by a type that converts. Today such an owner's lines are not read before an emptying, and it is doubt |
| `8317afec` | 641 | `continue` → `break` after a null reference's first column | **not reached**: no fixture has a nullable foreign key of two columns in the main mapping |
| `9ac9948f` | 673 | a derived key's own key converted the other way | **equivalent** for every fixture (a derived key over an integer, whose database value is itself); not tested over a converting type |
| `2fc4465e`, `26ee4f4a`, `8de6bebb` | 619, 685, 671 | `\|\|` → `&&` before converting a value; `(string)` dropped | **equivalent**: DBAL's types give null for null, every field has a type, and a field name is a string |
| `dc641cb8`, `edeee574`, `2a6860e7`, `f1e80c04`, `9a54330b`, `0c66492a`, `eb010b9f`, `17f0e5e5` | 238, 243, 251, 336, 339, 399, 422 | `array_values()` dropped; `&&` → `\|\|` on an owner a collection always has; `true` → `false` in maps read by `isset()`; `?->` on a manager set with the replay; `replayedApart()`'s position | **equivalent**: the spread renumbers integer keys; a scheduled collection always has an owner and a field name; the manager is null only with the replay, which the line before asks about; the one caller asking `replayedApart()` for less replays the rest itself before reading |
| `d5fa0382`, `e12d46c4` | 445 | the key and the class tested by `\|\|` | **equivalent**: at postPersist the key is there (its INSERT ran), and an object kept for a class nobody watches is held by no row |
| `2097b891`, `f3bf97a0`, `83f6ede3` | 499–501 | the objects a DELETE left behind not let go | **equivalent** in what is recorded: such an object is passed over by `remember()`, and a row that is back has its own object from its INSERT; they are only held longer |
| `98b60ff4` | 579 | `?->` → `->` for a row held with no object | **not reached**: a row is held with no object only when read for an emptying, which deletes it in the same flush |

### HistoryReplay (92)

All 92 run against the whole Doctrine suite as it stands after the groups above: 4 red
(`4864bbd6` 1041, `22b4e97d`, `dc372555`, `8fcfd0cd` 1131 — the self-referencing relay of
EntityRowRuns' tests). Five more killed by tests written for them, by hand.

| Ids | Line | Change | Class |
|---|---|---|---|
| `4864bbd6`, `22b4e97d`, `dc372555`, `8fcfd0cd` | 1041, 1131 | a DELETE's version of the row not kept; which version is the row at a position | test gap, closed above (`WhatTheLogMustKeepOfTheEventsTest::testAReferenceToTheRowItselfIsShownAsItStoodOnEachSide`) |
| `62882ec5`, `66dad2db` | 842, 849 | `continue` → `break` past a reference, or past a column written as it was | test gap — `ALineMovedAndChangedByOneUpdateTest` (a listener's own UPDATE; Doctrine writes fields before references, so its own never reaches it) |
| `09f0e782` | 297 | only the first position an account holds | test gap — `WhatTheLinksSayAsAListTest::testAnAccountTakenAfterTwoLinksWereWrittenIsUndoneOfBoth` |
| `c605b0d2` | 1267 | an emptying's lines not listed by key | test gap — `WhatAnEmptiedCollectionSaysAboutItsLinesTest::testAnEmptyingListsItsLinesByTheirKeysWhicheverWasRememberedFirst` |
| `c6308174` | 1274 | a representer that failed for one line forgotten | test gap — `…::testAnEmptyingARepresenterFailsForIsNotWrittenAndIsSaid` (new fixtures `Drawer`, `Sock`) |
| `c1090888` | 201 | the caret of the pattern that tells a write | **for the reviewers** — see "A write behind a common table expression" below |
| `5a41dcb2`, `c0063ec5`, `16403fa1`, `086dc650` | 162, 163, 1167, 894 | defaults built afresh; caches | **equivalent**: they decide from the mapping alone |
| `f37f8691` | 172 | `??=` → `=` | **equivalent**: the bundle's callers ask up to the log's position; the one caller asking for less (a test) replays the rest before reading |
| `89f09a7c`, `7d79e7ed`, `84749b44`, `369b0960`, `8ced9b6a`, `f4b079ba`, `6b877b17` | 192, 240, 619, 1231, 1377, 1459 | `\|\|` → `&&` on guards | **equivalent**: `RowBinding::of()` always returns a binding, a row's binding always names its class, a join row's and an emptying's their class and association, and the declaration `represent()` asks for is the one `collectionOf()` found |
| `674c3534`, `774a43dd`, `02552f07`, `4de10d15`, `d3a6047c`, `20a8fcd2`, `38702258`, `723048e8`, `32d02daf` | 249, 761, 1014, 1260, 634, 646, 1238, 519, 504 | casts and `strval` dropped | **equivalent**: DBAL counts in integers here, PHP 8 compares a numeric string as a number, and array keys of digits are integers anyway |
| `da2a27e1` | 295 | an account's positions not sorted | **equivalent**: their one reader sorts them itself (`LinkFacts::undone()`, `rsort`) |
| `ae685092`, `0a351237`, `b681eee7`, `496bd941`, `1924cac2`, `c1e2fb7a` | 331, 373, 386, 471, 611, 895 | keys kept, or a list not made one | **equivalent**: the facts are a list kept in place (`letGoOf()` writes over a place, never removes one), and the readers read values |
| `d2385456`, `c2a46b31`, `25d979a2`, `017983a3`, `e7af4000` | 344, 402, 411, 533, 1218 | `?->` → `->` on the log | **equivalent**: facts are made only inside `replay()`, after the log is set |
| `b8e9f9f0`, `e3c101e9` | 402, 533 | the `flush` of a fact not kept | **equivalent**: `eachFact()` writes it again from the log as it hands a fact out, and a released fact is past every reading |
| `cdbef648`, `3c326306` | 425, 476 | `throw` dropped | **not reached**: a fact asked of a place never filled, a manager gone — a caller's mistake |
| `6cec904a`, `479f63a5`, `e28e846f` | 455 | `letGoOf()`'s guard | **equivalent**: the places it is given are always filled, and letting go of a fact again writes the same nulls |
| `ce532db6`, `e2ee54ef` | 662 | a target gone by its row counted 0 or 2 | **equivalent**: the count is read only of a join table's own statement |
| `8c8bed58`, `64d7bcee` | 1039, 1277 | `true` → `false` in a map read by `isset()` | **equivalent** |
| `00f9cef1` | 886 | facts made for a class nobody audits | **equivalent** in what is recorded: such a fact makes no record (`EntityRowRuns::recordOf()`, no declaration) |
| `4c0b1b0f`, `75264ca3`, `bb6ab0c9`, `556c6b80`, `a5b0d93b`, `9febb66d`, `5cb60f2d`, `52c74463`, `1644278c`, `e7bdfe22` | 976–1083, 1314, 1472, 1473 | guards on join columns and on converting a value | **equivalent**: only an owning to-one association has join columns at the mapping's top, always a list; a null converts to null |
| `148ba6a7`, `30f45406`, `08abb008`, `46a5b11b`, `f2e14531` | 1132, 1152, 1156 | `break` → `continue` among ordered versions; versions kept of a class nobody shows; the first version at 0, ±1 | **equivalent**: the versions are in order, so every one after is later too; nobody asks for the versions of such a class; positions start at 1, and the first version's is only compared with `>` |
| `9e662660`, `9ba74dd5`, `7b46b3c0` | 1483 | a backed enum's key | **not reached**: no fixture has a key that is a backed enum (as against 4261bdb) |
| `eb0bb27c`, `97f13ff2` | 823, 868 | `continue` → `break` past an owner a line keeps; a field said as the line's own where it has an owner on one side | **not reached**: no fixture has a line of two tracked collections through two columns, or a line that is itself audited |
| `189f28e8`, `785505e5`, `981c7169`, `55a64a2a`, `63b1120b`, `9f70ec36`, `2376e900`, `3f5d720d`, `54921399`, `f0de70fd`, `9f7d31fa`, `9bfe894a`, `8d12c5c3`, `67d582a8`, `590733cb` | 260, 320, 552, 717, 1278, 782, 1107, 1110, 1122, 1124, 1128, 1346, 1455, 611 | forgetting a row taken after a statement; learning a row; the context of a row not known; versions of an inserted or emptied row; a reached-nothing UPDATE's empty fact (survives on MySQL too, measured); a reference over a derived key; which version before any move; an unaudited owner's emptying; a table of a root's own; a doubt at the position last read | **open**: not told apart in this pass. One attempt (a doubt at the last position of a reading, said again by the next) did not fail on `590733cb` |

#### A write behind a common table expression (for the reviewers)

Found by `c1090888` (the caret): `WITH gone AS (SELECT ? AS id) DELETE FROM Article WHERE id IN
(SELECT id FROM gone)` is logged (it is no read), cannot be read, and — not beginning with
INSERT, UPDATE or DELETE — is neither replayed nor doubt: the article's row is still held as
there, and nothing says the history may be missing what the statement did. The comment above the
line says a write that cannot be read is doubt. Measured on SQLite, red
(`AStatementThatOnlyMentionsAWriteTest`, kept aside in the scratchpad, not committed: it would pin
the defect). Severity LOW: the application's own SQL, in a form Doctrine never writes; 1.2.x read
change sets and had no such road. Ways out: (a) a logged statement beginning with `WITH` that names
INSERT, UPDATE or DELETE anywhere outside a literal is doubt, of the table it names where it can
— a `WITH … SELECT` quoting such a word would be doubt too, wrongly but loudly; (b) drop the caret,
as the mutant does — the same, for every unreadable statement; (c) document it. Recommended: (a).

**Decided and FIXED (2026-10-05).** Both reviewers: (a), by lexical tokens — a write token
outside literals and quoted names, no replay of what the statement did. `StatementShape::
writtenBehindAWith()` reads the words of a statement that begins with WITH (comments already
taken out), skipping '…' with '' inside, E'…' with a backslash, $tag$…$tag$, "…", `…` and […];
an INSERT, UPDATE or DELETE that is a word of its own is a write of the table named after it
(INTO, FROM, ONLY passed over; a schema kept; a name it cannot read is "any table"), except
FOR UPDATE, FOR NO KEY UPDATE, ON CONFLICT DO UPDATE and ON DUPLICATE KEY UPDATE, which are a
lock or an INSERT's other arm. `HistoryReplay` makes it doubt of that table where the history
watches it, and of any table where the name is not read; a table nobody audits is nothing, as for
every other statement. The whole road holds: such a statement is logged (it is no read), kept by
the filter of history tables (it has no shape), and replayed. Guards
(`AWriteBehindACommonTableExpressionTest`): the write into an audited table is the warning, red
before the fix; a DELETE in a value and a column named "DELETE" are none; a write behind WITH
into a table nobody audits is none. Each part of the fix neutralised in turn — literals not
skipped, quoted names not skipped, the branch gone, every name taken for unreadable — makes the
test red. `StatementShapeTest` reads 29 statements. Green on SQLite, the ORM 2.19, ORM 2 and
DBAL 3 cells, and MySQL and PostgreSQL under DBAL 3 and 4 (the test writes its key in, as
PostgreSQL types a bound one in a CTE as text; and deletes behind WITH, as MySQL takes no WITH
before an INSERT).

### AuditSubscriber (118)

Of CI 37216755055 (`8e15dc2`). 69 match a mutant already classed against 4261bdb by file,
mutator and diff (`g_inventory.py`, `NOW=ci-8e1`) and keep that class: 40 equivalent, 29 not
reached, each with its reason in the sections near the top. The other 49 — the flush stack, the
order and joining of drafts, the context of a link's record, the owners an element is checked
for, and the late write — were "not yet told apart" there.

**The search, this time.** A differential probe, not committed (`ZzStackProbeTest`, scratchpad):
thirteen scenarios of the stack — a flush refused in, between and after the application's
transactions; refused with only a collection's rows planned and followed by the application's
own transaction or SQL; a publishing swallowed, then the next flush, in and out of a transaction,
and with only a collection's rows; a nested flush refused, refused in a transaction, refused
with only a collection's rows; two nested flushes in a row; a swallowed publishing followed by a
refused flush — each with and without savepoints, their documents and log lines held to what the
code gives. All 49 survive it on DBAL 4 and on DBAL 3 (es-audit-dbal3, where without savepoints
nested flushes share a frame). With the model's 2,000 seeds before it, that is the evidence.

| Ids | Line | Change | Class |
|---|---|---|---|
| `fa502b43`, `41e714d2`, `35db3e61`, `6e5293d9`, `756d740d` | 1417–1435 | the late write with the manager that found it | **for the reviewers**, as before: they tell apart the code defect "a late record's label from a later moment" (Code defects found), not fixed until the reviewers choose the fix |
| `2008c7e5`, `d9e0f6d5` | 678, 686 | the stack read from the bottom; `break` → `continue` | **equivalent** by an invariant: the levels on the stack strictly increase — `beginFlush()` unwinds every entry at or above a level before it pushes one there — so at most one entry has the level asked for |
| `ac610dbe`, `ebdd1399`, `952df9bd` | 697 | the second half of "is an inner flush over" mutated | **equivalent**: `collectingNow()` answers NO_FLUSH exactly when the stack is empty, so with a manager the second half says what the first does; without one — a manager that is no EntityManager — the ORM never calls |
| `622b66e7` | 695 | the finishing flush not taken as committed | **equivalent**: the flush finishing at this level either ran a statement, which the log answers for (`hasDoneAnythingFor()`, its frame claimed two lines up), or has nothing to publish |
| `ce553d62`, `59ae5ac5`, `cc74ec7d`, `276a4be2`, `5dbf9dac`, `72b11801`, `6d76930b`, `45069cc9`, `9b86aba4`, `589b4682`, `aad09556`, `c76ed71d`, `5bae75d9`, `63b7b096`, `2c23aa75`, `0e6d8aae`, `983bd0ef`, `320824e5`, `bf912738`, `d8f66b3a`, `9672c382` | 431, 445, 680, 1195–1293, 1323, 1324, 1463 | which flush on the stack claims a frame, counts as having run, or is forgotten; what a flush planned | **open, searched**: no road found by the probe above or the model. The argument against 4261bdb stands (the answers of `unwindTo()` are read only where a record is built from a statement that stayed done, whose flush the log answers for; forgetting decides only the lost-change-set warning) and its gap with it: an owner popped before the unwinding |
| `67cbe2c9`, `4373cc8c`, `be50f3a7`, `2d5d22a6`, `0e2ead7d`, `1168bd9c`, `df1f0cea` | 885–1040 | the drafts' order past sixteen executions; context from whichever ran first; the key a list joins under without its flush; `??=` → `=` | **open, searched**: each needs an owner with two records of one publishing (a nested flush writing the same owner), which the probe's two nested flushes did not make |
| `14b0b9f3`, `570a9cc4`, `f98ba6e1`, `7e8a205b` | 1060, 1150–1160 | a link's context as the row stands now; a doubt at the last position read | **open, searched**, as against 4261bdb |
| `731b0792`, `502c32b6` | 1125, 1173 | the context through a fresh instance; the last failure raised | **open**: a declaration by interface varying by instance; which of two equal sentences is raised, seen only with `failure_details: full` |
| `02636704`, `455af5af`, `c0364a90`, `9dd3b17f` | 1494–1541 | which associations of an element lead to its owner | **open**: different only for an owner that does not hold the element through that association and whose declaration is wrong; no fixture |

So AuditSubscriber stands at 69 + 11 classed and 38 open: the flush stack is where this
gate's search ends. A word the model lacks — a transaction of the application's own around and
between its flushes — is what the remaining stack mutants need, and adding one redraws every
seed (the corpora's baseline with it); for the reviewers whether that belongs to 1.3 or 1.4.

## The roll-call of CI 37279462334 (`9fdd578`)

The reviewers asked for the classes of **this** run's 346 escaped and 25 timed out, not an
estimate carried from another tree; and that a missing fixture be a gap of the testing, not
unreachability — which only an invariant of the supported code proves. `rollcall.py`
(scratchpad) matches each of this run's mutants to the mutant of the same file, mutator and diff
nearest its line in each earlier run (`4261bdb`, `6bb3945`, `24d23d8`, `8e15dc2`), and reads the
class off the newest place in this journal that names one of their ids. The ids below are this
run's; a row here supersedes what an earlier section says of the same mutant.

### The count

| Class | Escaped | What it rests on |
|---|---|---|
| equivalent | 221 | each with its reason in its row: an invariant of the supported code, PHP's semantics, or a reader that reads nothing the mutant changes (242 at the roll-call; 21 taken back when each exclusion was checked on its line, below) |
| open | 85 | searched and not told apart: AuditSubscriber 54 (the flush stack, the operation's windows, the drafts of an owner with two records), HistoryReplay 26, EntityRowRuns 1, RowMemory 3, LinkRuns 1 |
| gap, not written | 18 | a form no fixture has — a gap of the testing, listed with what would reach it |
| not reached | 11 | an invariant of the supported code, named in its row |
| killed elsewhere | 7 | red in a cell the gate does not run, confirmed there: DBAL 3 without savepoints 4, ORM 2.19 3 — not added to the SQLite score |
| killed since the run | 3 | by `WhatALateRecordShowsOfAnUnwatchedTargetTest`, written after it |
| no longer made | 1 | `placeholder()` made private, so no visibility mutant is made of it |
| **all** | **346** | |

The 25 timed out: 23 a loop that never ends under the mutant, 2 killed by a test that a slower
one ran ahead of (below). The ObservingMiddleware mutant red on MySQL (`cdf96c70` of `24d23d8`)
is among the equivalents: on SQLite it changes nothing.

### Changed by the criterion

| Ids | Line | Change | Class |
|---|---|---|---|
| `e7828d2e`, `ef1920f0` | AuditSubscriber 927, 991 | a draft for an owner with no id | **gap, not written** (was "not reached"): the id is null for a composite key no manager holds, which no fixture has |
| `5c70d686` | AuditSubscriber 1512 | `continue` → `break` past an association that is not the owner's | **gap, not written** (was "not reached"): no element in the fixtures has such an association ahead of its owner's |
| `eadb26f2` | RowMemory 641 | `continue` → `break` after a null reference's first column | **gap, not written** (was "not reached"): no nullable foreign key of two columns in the main mapping |
| `9e662660`, `9ba74dd5`, `7b46b3c0` | HistoryReplay 1483 | a backed enum's key | **gap, not written** (was "not reached"): no fixture is keyed by a backed enum |
| `eb0bb27c`, `97f13ff2` | HistoryReplay 823, 868 | an owner a line keeps; a line audited itself | **gap, not written** (was "not reached"): no fixture has either |
| `ee732dde`, `ca428c2b`, `eef0ae11`, `c741bdc2`, `c9318311`, `316d474c`, `8b025d81`, `8d013dfb`, `c421ba2f`, `350fa84e` | AuditSubscriber 1818–1820 | `ranDuringAFlush()`: the loop, the window, the answer | **open, searched** (was "not reached"): fourteen shapes of the application's SQL in and around a flush were searched for the hole's warning, and none reached it — a search, not an invariant |
| `88fb375d`, `8c668dab`, `2c5aa8bd`, `157575c5`, `b28707a4`, `8558a472`, `9c384b71` | AuditSubscriber 1366, 1371, 1458 | how an operation's windows are kept | **open, searched** (was "not reached"): read only by `ranDuringAFlush()`, above |
| `84eadb27` | AuditSubscriber 1243 | `claimUnowned()` not called | **killed on DBAL 3** (`orm_cell_probe`, es-audit-dbal3: 14 failures), as the reason against 4261bdb foresaw |
| `2a50193f` | AuditSubscriber 1243 | `$last` before what was claimed through | **equivalent**: `$last` is never null, and claiming again what is claimed changes nothing — `claimUnowned()` claims only what no flush owns |
| `d71a0fb8`, `52d53374` | EntityRowRuns 148 | `$duringAFlush` tested against null | **equivalent**, as above (it was counted with "not reached" for a word of its reason) |
| `a5b0d93b` | HistoryReplay 1077 | `continue` → `break` in the walk over a row's associations when it is copied | **gap, not written** (was "equivalent", found wrong when each exclusion was checked on its line): `break` stops at the first association that is no owning to-one, so a reference declared after a collection is not set on the copy, and a representer reading it reads null. No fixture shows a row of such a class as it stood |
| `75264ca3`, `bb6ab0c9`, `52c74463` | HistoryReplay 982, 1314 | `&&` → `\|\|` and `\|\|` → `&&` in the guards on one join column | **gap, not written** (was "equivalent", found wrong on its line): "always a list" holds, "of one column" does not — a foreign key of two columns then gives its first column for the whole, and an inverse one-to-one's null reaches `count()` and `reset()`, a TypeError. No fixture has a composite reference among the fields a row is copied with |
| `9febb66d` | HistoryReplay 1081 | `&&` → `\|\|` before a reference's one column | **gap, not written** (was "equivalent", found wrong on its line): a reference of two columns is then read by its first |
| `fdb755d0` | RowIdentity 48 | the two arms of the conversion swapped | **gap, not written** (was "equivalent", found wrong on its line): the reason covered the association arm only; under the mutant an identifier that is a field is looked up unconverted, which differs for a type whose PHP value is not its database value (a UUID). No fixture is keyed by such a type through `byForeignKey()` |
| `082809eb` | StatementShape 435 (now 613) | `&&` → `\|\|` before a number | **gap, not written** (was "equivalent", found wrong on its line): with `\|\|` a digit short-circuits the match, and the token is the previous match's text, the position moving by its length — after `id =`, the `12` is read as two literals `=`. No statement the persisters write has a number in it |
| `d3a6047c`, `20a8fcd2`, `774a43dd`, `02552f07`, `4de10d15` | HistoryReplay 634, 646, 761, 1014, 1260 | `(int)` taken off a count compared with `===`/`!==` later | **open** (was "equivalent", the reason was a driver's, not DBAL's): `rowCount()` is `int\|numeric-string` by DBAL's contract; that every driver of this support gives a string only past `PHP_INT_MAX` is so of their code, not promised. For the reviewers: normalising the count once where it is logged would make these casts unneeded, and the mutants with them |
| `38702258` | HistoryReplay 1238 | `(string)` taken off the column of an emptying | **open** (was "equivalent"): the null it would differ by needs a WHERE with no column, which the binding rules out — the caller's, not PHP's |
| `1644278c`, `e7bdfe22`, `e1273a6b`, `9f35630e` | HistoryReplay 1472, 1473; RowMemory 619, 685 | a null converted through its field's type | **open** (was "equivalent"): DBAL's own types give null for null, but no contract makes an application's type do so |
| `0609be94` | LinkRuns 212 | the managed entity asked before the object removed | **open** (was "equivalent"): another object can be managed under the key of one removed — behaviour, not a contract |
| `4c0b1b0f`, `5cb60f2d` | HistoryReplay 976, 1083 | `\|\|` → `&&` before an association is copied | **open** (was "equivalent"): the first lets an owning to-one the declaration does not name into the copy's values, the second a join column the row has not got — what the copy then shows is behaviour |
| `38adf250` | StatementShape 133 | `placeholder()` protected | **no longer made**: only its own class calls it, so it is private now, as the reviewers asked of a caller's invariant |
| `a82b7d6b`, `6e6790e1`, `e0c20ac8` | AuditSubscriber 1416, 1434 | the late write with the manager of the next flush, not the one whose flush it was | **test gap, closed** (was "for the reviewers"): the reviewers kept the documented bound for 1.3 — a target of a class the bundle does not read is the object the application holds when the representer runs — so the code as it is, is the contract, and `WhatALateRecordShowsOfAnUnwatchedTargetTest` pins it: the late record names the abandoned manager's object, renamed and unsaved; red under each |
| `61493358`, `37b3f040` | AuditSubscriber 1422, 1434 | the count with the next flush's manager; the second argument of `publish()` | **equivalent**: the count asks only whether anything was collected, and the log's records are the same whichever manager of one mapping reads them; `publish()` takes the manager of its first argument, and the second only where the first is none |
| `c1090888` | HistoryReplay 201 | the caret of the pattern that tells a write | **open, for the reviewers**: (b) was refused, so the caret stays; what the mutant changes is the next finding below — writes that begin with neither INSERT, UPDATE, DELETE nor WITH |

### For the reviewers: writes that begin with another word (found by the roll-call)

The caret's mutant points at the same road as the common table expression, by another door. Two
statements that write a watched table, begin with none of INSERT, UPDATE, DELETE and WITH, and are
not read, measured on SQLite, red (kept aside in the scratchpad, not committed):
`REPLACE INTO Article (…) VALUES (…)` (MySQL and SQLite) — the article's row replaced, no
doubt; and `INSERT OR REPLACE INTO Article …` (SQLite), which begins with INSERT but whose table the
pattern reads as `OR` — no doubt either. By the same shape, untested: `MERGE INTO …`
(PostgreSQL 15+). Severity LOW, as for the CTE: the application's own SQL, in forms Doctrine never
writes. Ways out: (a') the CTE's lexical rule for every statement that is not read — a write token
(INSERT, UPDATE, DELETE, REPLACE, MERGE) outside literals and quoted names makes it doubt of the
table named after it, with the CTE's exceptions (a lock, an INSERT's other arm) and `OR …` passed
over; (c) document the forms that are not followed. Recommended: (a'), one rule for the CTE and
these, by the tokens the CTE's fix already reads.

**Decided and FIXED (2026-10-05): (a').** One rule for every statement the reader cannot read,
the CTE's among them: `StatementShape::writesItCannotRead()` (it replaces
`writtenBehindAWith()`, and the pattern with the caret is gone). A write is a word of its own,
outside literals and quoted names, among INSERT, UPDATE, DELETE, REPLACE, MERGE and TRUNCATE; the
words that may stand before its table are named, not skipped at will — MySQL's modifiers
(LOW_PRIORITY, DELAYED, HIGH_PRIORITY, QUICK, IGNORE), INTO, FROM, ONLY, TABLE, and SQLite's OR
followed by one of its five words (REPLACE, IGNORE, ABORT, FAIL, ROLLBACK); after OR with any
other word, or wherever the name cannot be read, the table is "any", and the statement is doubt.
Not a write of its own: FOR UPDATE, FOR NO KEY UPDATE, ON CONFLICT DO UPDATE, ON DUPLICATE KEY
UPDATE, a foreign key's ON DELETE and ON UPDATE (the schema's DDL is logged too), a MERGE's THEN
arms (the MERGE names the table), REPLACE(…) the function. Nothing is made of what a statement
did. TRUNCATE was not among the forms set out above; it is the same road — a watched table
emptied, no doubt — and the same rule closes it.

The parser (`StatementShape::read()`) returns null for every one of these forms — `OR` was the
table only of the old pattern of the doubt, not a shape — so the rule, which runs where nothing
was read, covers `INSERT OR REPLACE` too; `AWriteInAFormNotReadTest` proves it end to end.
Regressions (`AWriteInAFormNotReadTest`), each red on the code before (a'), each where its
database has the form: REPLACE (SQLite, MySQL), INSERT OR REPLACE (SQLite), MERGE (PostgreSQL 15
and later — **run on PostgreSQL 16, DBAL 3 and 4**), TRUNCATE (MySQL, PostgreSQL; SQLite has
none). `StatementShapeTest` reads 47 statements, the forms above and the words that are no write.
Green on SQLite with ORM 2.19, ORM 2 and DBAL 3, and on MySQL and PostgreSQL under DBAL 3 and 4.

### New with H

| Ids | Line | Change | Class |
|---|---|---|---|
| `68efdeae`, `fd9633a2`, `67a1dfad`, `5c57997e` | AuditSubscriber 935, 950, 999, 1014 | `true` → `false` in the nested maps read by `isset()` | **equivalent** |
| `9956d6dd`, `7b3e834e` | AuditSubscriber 965, 1027 | `??=` → `=` | **open, searched**, as `1168bd9c` and `df1f0cea` were: needs an owner with two records of one publishing |
| `61ce5d74` | ElementFieldRuns 134 | `(string)` dropped from `json_encode()` | **equivalent**: an owner's key read from a row always encodes |
| `d5fb4f65`, `e260a257` | HistoryReplay 401 | `\|\|` → `&&` on a fact's kept parts | **equivalent**: a fact is let go of whole — its columns, values and fields are null together or none is (`letGoOf()`) |

### The 25 timed out, classed again

| Ids | Line | Change | Class |
|---|---|---|---|
| `9d35866a`, `3aacdb62`, `cc50f6ca`, `3ccf2a59`, `cd1fba25`, `828a2876`, `30e7ac36`, `d77bd104`, `6ad6b717` | ReadableSql 81–202 | a return dropped where a quote or a comment does not end; the position set to, or moved back by, a length; `++$i` → `--$i` | **a loop that never ends**: the reader's position stops advancing or goes back, and the same character is read for ever |
| `622aa7f0`, `301130cb`, `30959a5f`, `12587642`, `6b0745f1`, `de6337d5`, `4aafec10` | StatementShape 199–272, 402 | the token loop's test assigning a boolean; `break` → `continue`; `++` → `--`; AND and OR not consumed | **a loop that never ends**, the same way |
| `3af0364b`, `fbaf14a7`, `c8940668`, `5a6b1056`, `fa1fa7a4` | StatementLog 586, 711, 794, 817 | the walk up the frames' parents with `&&` → `\|\|`, negated, or its parent read as a boolean | **a loop that never ends**: the walk's test is always true |
| `cd4dd75a`, `9c6f225a` | StatementLog 624, HistoryReplay 174 | `<=` → `>` in a loop counting up to a position | **a loop that never ends** once it is entered past its end |
| `437dbaac`, `5a4b8732` | RowIdentity 51, 52 | the entity the manager holds not returned | **killed**: `WhichEntityAForeignKeyNamesTest` is red under each, by hand (`hand_probe.py`); in the run a slower test met it first and ran past the timeout |

Every timeout is detected; none is an equivalent hidden by a time limit.

## H: keys as nested arrays, and the README

The maps keyed by parts joined with `|` are nested arrays now: `AuditSubscriber::drafts()`
(an owner's record by type, then id; a list's joining by type, id, collection, flush —
`4f36c65`), `DepartedObjects` (root class, then row key — `6e54fa5`, `cccf59b`), `ElementFieldRuns`
(an owner's runs by class, then its key — `d58f7f9`) and `EntityRowRuns` (a row's creation by
class, then id — `d58f7f9`). No part can run into the next one any more, and the separator's
mutants (ElementFieldRuns 134, EntityRowRuns 215, AuditSubscriber 997) go with them. Two kinds of
join stay, on purpose: a row's key (`HistoryReplay::keyOf()`, the composite key's columns joined
— a normalisation of one identifier, which every reader compares as one value, kept apart from
the maps), and a watch's name in `StatementLog::watch()`, the label and the flush joined by a NUL,
which no class or field name can hold.

The README says now (Limitations, "A flush is the only source") which listener's SQL is inside a
flush — `onFlush` after the bundle's, a lifecycle event of the flush, `postFlush` before it — and
which is outside; and (the late road) that a late record's labels of a class the bundle does not
watch are of the object as it is when the record is written.

## Exclusions from the count

The reviewers' rule: a mutation leaves the count only if it is equivalent by PHP's semantics or by
a Doctrine or DBAL contract, each by a record of its own in `tools/infection/exclusions.json` —
file, `Class::method`, mutator, line, the line's text, the mutation's diff, the ground, and the
argument in three steps (what it changes → what bounds it → why the same) — checked on the code
as it is now. `gate.php excluded` holds every record to the plan made **without** exclusions: its
text and method must be those of its line, its diff must be that of exactly one mutation there,
and an Infection rule (`mutator` + `Class::method::line`) that would take more mutations than its
records argue for is refused. The summary then states the plan before exclusions, the mutations
left out with their grounds, and requires the plan run to be that plan less exactly them.

Of the 241 equivalents `rollcall.py` classed, 86 rested on PHP or on Doctrine; the other 155 — on an
invariant of this bundle's own code (94) or on what a reader reads (61) — stay in the count as
survivors, as agreed. Of the 86:

| | Mutations |
|---|---|
| excluded, by PHP | 40 |
| excluded, by Doctrine or DBAL | 18 |
| taken back on their line (rows above: 7 gaps, 13 open, 1 made private) | 21 |
| refused by the gate — the rule would take more mutations than are argued for: AuditMetadata 49 `LogicalAnd`, HistoryReplay 517 `UnwrapArrayMap`, JoinRowsQuery 122 `DecrementInteger` and `IncrementInteger`, LinkFacts 89 and 357 `IncrementInteger`, RowBinding 152 `IncrementInteger` | 7 |
| **all** | **86** |

The 7 refused stay in the count as survivors: each is still equivalent as its row says, but the
line holds a second mutation of the same mutator that is not, and Infection cannot tell them apart.

Two things were learnt on the way. A record carried from the roll-call by the nearest line of the
same diff landed on code written since (`StatementShape` 417, a word of (a′), where the mutant is no
equivalent): records are now carried by the file's own diff from `9fdd578`. And 21 reasons written
for a group did not hold for every member of it — "always a list" is not "of one column"; a
driver's habit is not DBAL's contract.

The one ignore of `infection.doctrine.json5` (`ReadableSql::of::39`) argued from this bundle's own
reading and is gone; that mutant is a survivor now.

## The roll-call of CI 37312316628 (`7c0c15c`)

The first run with the exclusions: 4212 planned, 58 excluded, 4154 counted; 3801 killed, 325
escaped, 28 timed out — 92.18% (91.50% counting only what a test failed on), floor 94; main 98.16%.
Without the exclusions the same run is about 90.9% (90.2%). `rollcall.py` against the runs before:

| Class | Escaped |
|---|---|
| equivalent (each in its row above; none of the 58) | 162 |
| open | 84 |
| gap, not written | 18 |
| not reached (an invariant) | 11 |
| killed elsewhere (DBAL 3 without savepoints, ORM 2.19; and `c07c1e96`, `cdf96c70`, which the script read as "killed") | 8 |
| new: the lexer of (a′) (40) and `read()`'s INSERT (1) | 41 |
| new: HistoryReplay 214, below | 1 |
| **all** | **325** |

The 41 new: every case of `writesItCannotRead()` had its write inside the literal, so where a
literal or a quoted name ends was never asked. Cases with a write after one (8bf0c17), run locally
under Infection on `StatementShape.php`: all 41 red or never ending. The 3 new timeouts: two
`++$j` → `--$j` in `pastALiteral()` (a loop that never ends), and `ReadableSql::of()`'s early return
taken away — every statement then read a character at a time, which the slow tests do not finish.

### For the reviewers: the doubt names the first watched table only (found by the roll-call)

`fd772229`, HistoryReplay 214, `$watched ??= $class` → `=`. Under (a′) a statement that writes two
watched tables gives one doubt, of the class of the first. On PostgreSQL, `WITH gone AS (DELETE FROM
Comment … RETURNING id) DELETE FROM Article …` takes both rows, and the warning says
"1 statement(s) of …\Comment" only; a statement naming first a table it cannot read and then
Article says Article, where the first could be any table. The doubt is kept — a warning is given;
what it says is wrong. Probe: `scratchpad/ZzMultiTableProbeTest.php.red`,
`testADoubtOfTwoWatchedTablesNamesBoth`, red on PG16. LOW, 1.3 only (the rule is (a′)'s).

Options: (a) the doubt of every class named, one doubt per statement with the classes joined, so the
count stays one per statement; (b) null — "an unknown table" — whenever the names are not all of one
class or one cannot be read. **Recommended: (a)**, with null among them if a name cannot be read:
it says what is known and no less.

### For the reviewers: a write of several tables is named by its first (found on the way)

MySQL's multi-table forms — `UPDATE scratch s JOIN Article a … SET a.title = …`,
`UPDATE scratch s, Article a SET …`, `DELETE s, a FROM scratch s JOIN Article a …`,
`DELETE FROM s, a USING …` — are read as a write of the first name only (`scratch`, or the alias `s`),
and so is `DELETE a FROM Article a JOIN …` of the alias alone. A watched table behind an unwatched
one, or behind an alias, is then **no doubt at all**: on MySQL 8 the probe's UPDATE changes an
Article's title and the history says nothing
(`testAMultiTableUpdateOfAWatchedTableBehindAnUnwatchedOneIsDoubt`, red). Not (a′)'s regression —
the caret rule before it took the first name too — but a hole in what 1.3 promises of a write it
cannot read. MEDIUM: a silent miss, on a form the application writes and the persisters never do.

Options: (a) null, any table, for an UPDATE whose first name is followed by `,` or a join before
`SET`, and for a DELETE with names before its `FROM` or a `,` after its first name; (b) read every
name of those forms, aliases resolved. **Recommended: (a)** — the rule only says doubt, and (b)
would be a parser of MySQL's join syntax for a warning.

### Decided and FIXED: A and B (cf0dff2)

As the reviewers chose, (a) for both. B: a comma or a join at the write's own depth — not in a
subquery, not in another expression of a WITH, not after the statement's end — before an UPDATE's
SET or after a DELETE's first name, names before a DELETE's FROM (an alias among them), and a
TRUNCATE of a list (PostgreSQL's, found on the way), are any table; a DELETE … USING other tables,
an UPDATE … FROM them and MySQL's index hints keep their table. A: one doubt per statement, of
every watched class named, with an unknown table among them that a name read after it does not
take back. End to end on MySQL 8 (the five forms, and two statements in one with the unknown first)
and PostgreSQL 16 (two watched tables in one statement, DELETE … USING); each of eight
neutralisations of the fix turned a test red.

### For the reviewers: a watched table named otherwise than its mapping (found on the way)

The application's write of a watched table is followed, or doubt, only when it names the table
exactly as the mapping does. `HistoryReplay::watchedClassOf()` compares the name as written, case
and schema included, and both the statements read and the ones not read go through it:

| Database | Silent: no record, no doubt |
|---|---|
| PostgreSQL 16 | `UPDATE article …`, `UPDATE ARTICLE …` (an unquoted name is folded: the same table), a WITH … UPDATE article, `UPDATE public.Article …` |
| SQLite | the same four, with `main.Article` |
| MySQL 8 | `UPDATE audit4.Article …` (lower case is another table on Linux, and refused) |

Probe: `scratchpad/ZzTableNameCaseProbeTest.php.red`, red in each cell as above; `UPDATE Article`
as mapped is followed. Not (a′)'s — the comparison is older — but the same promise: a write of a
watched table this does not follow is doubt. **MEDIUM**, a silent miss; on PostgreSQL the natural
spelling of hand-written SQL is the lower case one.

Options: (a) a name not quoted compares without case, and a qualified name compares by its last
part — doubt, never a replay, when it is not the mapping's own spelling; (b) resolve the name as the
platform does (folding, `search_path`, `lower_case_table_names`). **Recommended: (a)** — the rule
only has to say doubt, and a wrong doubt costs a warning where a wrong miss costs history.

## The main set's line ignores, held to the same record (P4)

`infection.json5` ignored 70 rules (52 lines, some under several mutators). As the reviewers asked,
none was carried over by right: the plan of the main set made without them (2746 mutations, none
skipped, timeout 100000) shows they took **83** mutations, and each was read on its line now.

**19 left out by a record** in `tools/infection/exclusions.json`, every one argued from PHP, and
`gate.php excluded main` holds them against that plan: NumericNullAsZeroComparator 124 (`(int)` on a
numeric string added to an int), 114 ×5 (the defaults united with a match that sets every group, the
last one being mandatory), 131 (`>=` where the padding is then empty); AuditPage 104 ×2 (the key
read for an empty array); ElasticsearchGateway 420, 448 (a map read by `isset()`), 526 (a read under
`??` on a scalar answers null); FrameBuffer 363, 405 (`array_values()` of a filtered list of one);
CheckCommand 321 and CreateIndexCommand 113 (what `implode()` joins); IndexResolver 67 (the inner
`array_values()` before `array_unique()` and an outer one); Cursor 221 and AuditReader 459 (`(string)`
where `%s` or a concatenation converts alike).

**64 back in the count**, by what their argument rested on:

| Rested on | Mutations | Lines |
|---|---|---|
| Elasticsearch's answers | 12 | the depth of `json_decode()` of an error body (gateway 520, 683, ±1); the status range of `call()` (629); `(int)` on a setting (CheckCommand 294); `array_values()` of the hits (AuditReader 155); `??` of a cluster's name and a mapping's type (CheckCommand 91 ×2, MappingComparison 72); the first guard of `refusedTheParameter()` (247) |
| a token's input, which the client hands in | 4 | the depth of `json_decode()` of a cursor (Cursor 86, 180, ±1) |
| Messenger | 8 | the code 0 of `UnrecoverableMessageHandlingException` (three handlers' lines, ±1), the chain's bound `$step < 8` (MessengerTransport 77 ×2) |
| this bundle's own code and its callers | 33 | `array_unique()` in `bulk()`; `Filter::crossed()` ×2; the comparator's guards ×4 and its `trim()` ×4, `<=` and `-` (126, 132); `comparable()` ×8; `narrowIn()`; AuditReader 158 and 470; Cursor 217; CreateIndexCommand 107, 113 (`array_filter()`); AuditWriter 631; the variadics ×6 (below) |
| **wrong, by PHP itself** | 7 | ClusterVersion 34 `CastInt` ×3 — one of the three casts is under `===`, where `"8" === 8` is false; `DecrementInteger` ×3 — one reads the major for the minor; FrameBuffer 356 `Coalesce` — the mutator swaps the operands, and `[] ?? $this->moved[$key]` is always `[]` |
| **all** | **64** | |

The variadics (AuditQuery 103, 113, 126, 219, 235, 545) are counted with the bundle's own: "PHP hands a
variadic over as a list" is not so for a call with named arguments, which gives it string keys —
what keeps them a list is that no caller does that, a caller's invariant. None of the 64 is argued
away; each will be what the next run says of it — killed, or a survivor in the count — and the main
set's score is measured with all of them before its floor is decided.

The 55 Doctrine records were carried from `7c0c15c` by each file's diff; the three whose lines the
count's normalisation took away (HistoryReplay 262, LinkFacts 407, StatementLog 302 — `(int)` on a
count) went with them, and the rest hold against the Doctrine plan made without exclusions (4311
mutations, none skipped).

## The measure of CI 37429405590 (`c35b13c`), with P1–P4

The strict count is the gate's now (killed by a test, of the covered; a timeout and an error are
shown and not counted).

| | main | doctrine |
|---|---|---|
| planned, without exclusions | 2746 | 4311 |
| excluded, each by its record | 19 (PHP 19) | 55 (PHP 38, Doctrine 17) |
| counted | 2727 | 4256 |
| killed / escaped / timed out / errored | 2618 / 105 / 0 / 4 | 3932 / 294 / 30 / 0 |
| **strict** | **96.00%** (2618 of 2727) | **92.39%** (3932 of 4256) |
| as Infection counts | 96.15% | 93.09% |
| floor in parts.json (unchanged) | 97: 28 short | 94: 69 short |

Main's 105 escaped: the 49 of every run before, and **56 of the 64** the old ignores had taken —
the other 8 were killed, among them ClusterVersion's two that were wrong by PHP. FrameBuffer 356
(`[] ?? $this->moved[$key]`, always `[]`) escaped: what a held record's moved fields are is a gap
of the testing, not written.

Doctrine's 294: 162 equivalent, 79 open, 18 gap, 11 not reached, 8 killed elsewhere, and **16 new**
of P2 and P3's code: the run is on SQLite, and the unknown table beside a known one was red on MySQL
only; a count's anchors and digits had no case of a sign, a fraction or zeros; a comma after an
index hint, a join in lower case and OR after TRUNCATE had none. All but one are red or never end
since a7b6d2c (Infection on StatementShape, StatementLog and ElementFieldRuns; HistoryReplay 221's
two by hand); `$depth = 0` → `-1` is equivalent — depths are only compared with each other — and the
bundle's own, so it stays in the count. The 5 new timeouts are loops that never end.

Static analysis was red on PHPStan 2.3.0, which CI resolves and the local tree did not have: a
foreach that overwrote the variables of the one before it in `AuditWriter::keeping()` (72fd1e7).

## The floors, decided (after CI 37437717403, `900b52b`)

Measured on the candidate: main 2619 of 2728 killed, **96.00%** strict (96.15% as Infection
counts); Doctrine 3945 of 4255, **92.71%** strict (93.42% as Infection counts). Skipped 0 and not
covered 0 in both — the covered are all that were planned, less the excluded. The reviewers fixed
the floors at the measure, exactly and with no room: `"96.00"` and `"92.71"` in `parts.json`
(`"92.72"` is out of reach by a ten-thousandth, and is not the floor). A floor goes up only.
**The goal for 1.4: Doctrine at 94, strict**, beside the word about the application's
transaction.

A killed mutant that times out on a slower runner takes 0.024 of a point from Doctrine with no
change of code. No room is given for it: the refusal now says, from the statuses, whether the
timed out would have reached the floor — then it is the timeout's or the threads' to mend, never
the floor's — or whether the escaped are more than the floor allows (a9ec7c5; ARCHITECTURE says
it in one line).

### Decided and FIXED: C (9a37a11)

As both reviewers chose, (a), with the bounds the second drew. The exact match by the mapping's
spelling stands as it was. Only where it finds nothing is a candidate looked for: a name read part
by part, each with whether it was quoted — a dot inside a quoted name is the name's — whose last
part is a watched table's or join table's, unquoted in any case, quoted exactly. A candidate is
doubt of *a table named like* that class's, and nothing more: the statement is not bound,
replayed or remembered as the mapping's, and no record is made under that class or id — the
regression holds both (`AWriteOfATableNamedOtherwiseTest`, red under each of eight
neutralisations; all five forms green on PostgreSQL 16 and SQLite, the schema's on MySQL 8, where
the other case is another table and refused). The log keeps a statement by its name's last part
too, which a qualified one needed to reach the replay at all. Held further, before the measure:
a join table named otherwise (`ARTICLE_TAG`) is doubt of its owner's class; an unaudited class's
table in another case (`TAG`) is no doubt; a watched class after unwatched ones among the mapped
(`CRATE`) is found; `UPDATE SET SET …` is no table called SET. The candidates are not made unique
where the message, which alone reads them, makes them so.

Left as the reviewers drew it: a quoted name in another case than the mapping's (`"article"` for
`Article`) is no candidate — on PostgreSQL that is the folded table itself, written quoted, and it
goes unsaid; a false doubt for another table of the same name, in another schema or, on MySQL with
`lower_case_table_names=0`, in another case, is accepted.

### CI 37452642914 (`327e80b`): Doctrine 5 short, and why

main 2620 of 2728, 96.04% strict — through its floor. Doctrine 4022 of 4343, **92.61%**, five
mutations short of 92.71; 292 escaped against 280 of the run before, the timeouts 29 against 30.
The 14 escaped not in the journal: C's code had no case of two names like watched tables in one
statement (HistoryReplay 220), none read the text of its doubt (242 ×7), and a qualified column
was read by no case since tables have their own reader (StatementShape 159 ×3) — all eleven red by
hand since, under new cases; `$depth = 0` → `-1` and `return $classes` with one item (the message
makes them unique) are the bundle's own equivalents and stay in the count; ReadableSql 39 escaped
again.

**For the reviewers: the refusal's reason was wrong here.** It said the timed out would reach the
floor, and so they would — 29 of them, as every run has had, 23 loops that never end. The five
short were escaped of new code. Telling "a killed one timed out" from "more escaped" by the
statuses of one run alone cannot work while a run has loops among its timeouts; it needs a
baseline — the escaped and the timed out of the run the floor was measured on, kept beside the
floor — and then the refusal says which of the two grew. Recommended: keep the two counts of the
measured run in `parts.json` beside the floor, and say the reason from their difference.

### Decided and DONE: statuses by name, a refusal that claims no cause, kills made deterministic

On CI 37458201712 (`adfd434`) main's code was the code of `327e80b`, and a killed mutant had become
an errored one: 2620 → 2619 killed, 4 → 5 errored, 96.04% → 96.00%, on the floor. Both reviewers
asked, before the tag:

- **Statuses by name** (eaaa0b7). Each part's record keeps every mutant's status, and the end of
  what an errored or timed-out one left of its run; the summary writes `<set>-statuses.json` as an
  artifact, red or green; `gate.php transitions` compares two runs mutant by mutant.
- **A refusal that says no more than it knows** (eaaa0b7). The guessed reason is gone. `parts.json`
  keeps beside each floor the counts of the run it was measured on, with the run and a hash of its
  plan; the refusal gives the difference when the plans are one, says that they are not when not,
  and ends: the cause is not established, compare the mutants' statuses by name.
- **Kills that do not depend on the machine** (this commit). Infection run locally with every
  status kept named the errored of main: NumericNullAsZeroComparator 56 ×2 (`substr($last, 0)` and
  the unwrapped `substr`) and SyncIndexCommand 122–123 ×2 — each a call that recursed for ever under
  the mutant, so the process died, with a test or not before it, as the stack of the machine went.
  Both are loops now, with the same results: `covers()` asks the last segment once (it has no dot),
  `partial()` walks the path down and wraps it up by `array_reduce`. Run again: 0 errored, and the
  mutants of both are killed by an assertion; two cases were added for what the recursion had
  covered unseen — a scoped rule for a field in a collection, and a path two objects deep.

The base in `parts.json` is of `adfd434`'s plans; this commit changes main's (2747 → 2763 planned),
so its run will say that its counts are not compared, until the base is the run the tag is on.

### FrameBuffer 356: the gap closed (ad03de9)

`[] ?? $this->moved[$key]` on a REMOVE: the held record went out as if no field had moved, and a
field that went and came back stayed as context. A frame with one field 1 → 2 → 1 and another 5 → 6,
then a REMOVE, now says the record goes out with the second alone — red under the mutant.

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
