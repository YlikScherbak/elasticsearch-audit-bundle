#!/bin/sh
# Regenerates tests/Golden/documents/ — one document per released version, written by
# that version's own code.
#
#   tools/old-documents/run.sh            # every version in VERSIONS below
#   tools/old-documents/run.sh v1.0.0     # just one
#
# Run it when a version is added to VERSIONS, and never to "fix" a failing test: a
# fixture that changes because the current code changed is the test doing its job. The
# promise these pin is that today reads what those versions wrote, and that promise is about
# documents already sitting in somebody's index — regenerating them from today's code
# would pin today's shape twice and nothing else.
set -e

# One per shape a reader has to cope with, not one per tag: every version from v0.3.0 to
# today writes the same document for the same record, so seven copies of it would be six
# files nobody can tell apart.
#
#   v0.1.0  before records carried ids - the shape the cursor code still warns about,
#           because search_after cannot tell two of them apart
#   v0.3.0  the id arrives, and the document has not changed shape since
#   v1.0.0  where the promise starts: 1.x reads what this wrote
#   v1.2.1  today, as the control - if this one ever stops matching the others, the
#           format moved and the three above say what it moved away from
VERSIONS="v0.1.0 v0.3.0 v1.0.0 v1.2.1"

cd "$(dirname "$0")/../.."
root=$(pwd)
out="$root/tests/Golden/documents"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$out"

for version in ${1:-$VERSIONS}; do
    mkdir -p "$work/$version"
    git archive "$version" src | tar -x -C "$work/$version"

    php "$root/tools/old-documents/capture.php" "$work/$version/src" "$version" > "$out/$version.json"

    echo "$version -> tests/Golden/documents/$version.json"
done
