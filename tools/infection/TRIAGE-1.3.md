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
