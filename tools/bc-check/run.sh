#!/bin/sh
# What this release would break for somebody already using the bundle.
#
#   tools/bc-check/run.sh                 # the last tag against HEAD
#   tools/bc-check/run.sh v1.1.0 v1.2.0   # any two references
#
# **It reads git, not the working tree.** "HEAD" is the last commit, so uncommitted
# work is invisible to it — a check run before committing says what the last commit
# would break, which is not the same question and is easy to misread as a green light.
#
# Development dependencies are installed on both sides on purpose: symfony/messenger is
# optional for this bundle, and without it the checker cannot resolve the interface
# FrameResetMiddleware implements — seven "skipped" lines and a red exit code that mean
# nothing at all.
set -e

cd "$(dirname "$0")/../.."
root=$(pwd)

case "$root" in
    /[a-z]/*) root="$(echo "$root" | sed 's|^/\([a-z]\)/|\1:/|') " ;;
esac

from=${1:-$(git describe --tags --abbrev=0)}
to=${2:-HEAD}

MSYS_NO_PATHCONV=1 exec docker run --rm \
    -v "$(echo "$root" | tr -d ' '):/repo" \
    -w /repo \
    es-audit-infection \
    php tools/bc-check/vendor/bin/roave-backward-compatibility-check \
        --from="$from" \
        --to="$to" \
        --install-development-dependencies
