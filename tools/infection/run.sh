#!/bin/sh
# Mutation testing, in a container because Infection needs a coverage driver.
#
#   tools/infection/run.sh                       # everything infection.json5 lists
#   tools/infection/run.sh --configuration=infection.doctrine.json5   # the listener
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
#
# The initial run is the whole suite under coverage, and the image's php.ini holds it to 128M,
# which the suite outgrew in 1.3 (a test of the listener's memory, five thousand entities a
# flush); -d on Infection's own process does not reach the process it starts. CI's setup-php sets
# no limit. The mutants' runs are each a few tests without coverage, and fit.
#
# Six threads rather than all of them (INFECTION_THREADS=n to choose), because a timeout
# counts as a kill: on HistoryReplay.php, eleven threads on a twelve-thread machine called
# eight mutants timeouts that six threads, the same mutants and the same tests, saw escape.
# --only-covering-test-cases runs the test cases covering the mutated line rather than the
# whole files they are in: on the same file, at six threads, every one of the 670 mutants
# had the same status with it as without it, in 19 minutes rather than 27.
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
    -v "$root/infection.doctrine.json5:/app/infection.doctrine.json5" \
    -v "$root/tools/infection/gate.php:/app/tools/infection/gate.php" \
    -v "$root/var:/app/var" \
    -w /app \
    es-audit-infection \
    php -d memory_limit=-1 tools/infection/vendor/bin/infection \
        --initial-tests-php-options="-d memory_limit=-1" \
        --only-covering-test-cases \
        --threads="${INFECTION_THREADS:-6}" \
        --no-progress \
        --no-interaction \
        "$@"
