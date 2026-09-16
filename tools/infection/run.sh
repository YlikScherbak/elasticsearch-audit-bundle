#!/bin/sh
# Mutation testing, in a container because Infection needs a coverage driver.
#
#   tools/infection/run.sh                       # everything infection.json5 lists
#   tools/infection/run.sh --filter=BulkResult   # one class, while fixing its tests
#   tools/infection/run.sh --show-mutations      # print each escaped mutant's diff
#
# Build the image first, and again whenever composer.json changes:
#
#   docker build -t es-audit-infection -f tools/infection/Dockerfile .
#
# Only the project's own files are mounted; vendor/ comes from the image, for the reason
# written at length in the Dockerfile — through a bind mount the runs are slow enough
# that mutants time out, and a timeout counts as a kill, so the score goes up with how
# slow the machine is.
set -e

cd "$(dirname "$0")/../.."
root=$(pwd)

case "$root" in
    /[a-z]/*) root="$(echo "$root" | sed 's|^/\([a-z]\)/|\1:/|')" ;;  # Git Bash to a path Docker takes
esac

mkdir -p var/infection

MSYS_NO_PATHCONV=1 exec docker run --rm \
    -v "$root/src:/app/src" \
    -v "$root/tests:/app/tests" \
    -v "$root/examples:/app/examples" \
    -v "$root/phpunit.xml.dist:/app/phpunit.xml.dist" \
    -v "$root/infection.json5:/app/infection.json5" \
    -v "$root/var:/app/var" \
    -w /app \
    es-audit-infection \
    php -d memory_limit=-1 tools/infection/vendor/bin/infection \
        --threads=max \
        --no-progress \
        --no-interaction \
        "$@"
