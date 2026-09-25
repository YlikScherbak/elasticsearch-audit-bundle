# Changes to the oracle of the search

`WhatTheHistorySaysAgainstWhatTheRowsDidTest` holds the history to what the rows did. Its
oracle reads the database and never the bundle: a change to it is a change to what the
bundle is held to, and one made to fit the code is how a search goes blind. So every change
is listed here with the decision that asked for it. A change that cannot name one is not a
change to make.

| Commit  | What changed in the oracle | The decision it follows |
|---------|----------------------------|-------------------------|
| 3f670e2 | Readings taken around a flush nested in another, not only around a step (`checkpoints`). | Decision A (2026-09-23): the history describes each flush, not the operation. |
| 0ebdf90 | A line waiting for its INSERT is not a phantom (`holdsAPhantom()`), asked of the unit of work and not of the id. | None needed: an assumption of the generator's (id means row), wrong where a sequence hands out ids; the same seeds now draw the same sequences everywhere. |
| 22954c8 | Readings before every statement that writes, on the connection that runs it, and the readings of what a rollback undid dropped. | UPDATE then DELETE of one row in one flush is two facts (2026-09-24, both reviewers): the oracle saw only the edges of flushes and netted such a pair. |
| cdb06b2 | A line moved and changed by one statement: the change is said about the crate it arrived at. | The move rule (2026-09-24, both reviewers and the owner; UPGRADE 1.3): the change goes with the arrival, its old side the line's value. Eight of 3000 sequences, kept in TELLS_APART. |
| (step 4, vocabulary) | A change of a line's quantity is not said when the row has no crate after it, moved or not: the branch for a line that did not move said it under crate "", which is no crate. | The rule that a change inside a line no crate owns belongs to no crate (`testAChangeInsideALineWhoseRowHasNoOwnerBelongsToNoCrate`), held for a move to nowhere since cdb06b2 and now for a line that stays nowhere. Reached first by the widened vocabulary: after a refused move back, Doctrine believes a loose line is in the crate, and 'change a line' changes its quantity alone. Two of 3000 sequences, both that shape. |
