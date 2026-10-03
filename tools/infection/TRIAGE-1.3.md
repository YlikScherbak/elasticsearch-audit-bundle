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

### AuditWriter (main)

| Id | Line | Change | Class |
|---|---|---|---|
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
