#!/bin/sh
# One part of a set in parts.json — or the set's plan — run and recorded for the summary.
#
#   tools/infection/part.sh <set> plan
#   tools/infection/part.sh <set> <part> [plan-record]
#
# Here it runs Infection in the container (run.sh); with INFECTION_RUNNER=direct it runs the
# Infection installed in tools/infection on this machine, which is how CI runs it. Either way
# the flags are these and no others, and the record says what the run was: its exit code, the
# tree before and after, the threads the manifest gives the set and the CPUs it ran on.
#
# A part is a share of the mutants, never of the tests: --filter names the source files to
# mutate, and every test of the suite stays a candidate to kill them. Narrowing the tests to
# a directory would be faster, and would turn a mutant killed by a test in another directory
# into one that escaped (see ARCHITECTURE.md, the mutation gate).
#
# Given a plan record, a part first asks whether that plan is of this tree, and does not
# spend an hour on mutants the summary would refuse.
set -e

cd "$(dirname "$0")/../.."

set_name=$1
part=$2
plan_record=$3
out=${INFECTION_GATE_OUT:-var/infection/gate}

config=$(php -r '$m = json_decode(file_get_contents("tools/infection/parts.json"), true); echo $m[$argv[1]]["config"] ?? "";' "$set_name")
threads=$(php -r '$m = json_decode(file_get_contents("tools/infection/parts.json"), true); echo $m[$argv[1]]["threads"] ?? "";' "$set_name")
[ -n "$config" ] && [ -n "$threads" ] || { echo "parts.json gives set \"$set_name\" no configuration or threads" >&2; exit 2; }

if [ -n "$plan_record" ]; then
    php tools/infection/gate.php agrees "$set_name" "$plan_record"
fi

if [ "$part" = plan ]; then
    what="--dry-run"
else
    what="--filter=$(php tools/infection/gate.php files "$set_name" "$part")"
fi

log="var/infection/$set_name.json"
before=$(php tools/infection/gate.php fingerprint "$set_name")
rm -f "$log"
code=0

if [ "${INFECTION_RUNNER:-docker}" = direct ]; then
    cpus=$(nproc)
    php -d memory_limit=-1 tools/infection/vendor/bin/infection \
        --configuration="$config" \
        --initial-tests-php-options="-d memory_limit=-1" \
        --threads="$threads" \
        --min-covered-msi=0 \
        --no-progress \
        --no-interaction \
        "$what" || code=$?
else
    # The CPUs the tests run on: run.sh runs them in a container, whose machine is not this one.
    cpus=$(docker run --rm es-audit-infection nproc)
    INFECTION_THREADS=$threads sh tools/infection/run.sh --configuration="$config" --min-covered-msi=0 "$what" || code=$?
fi

# Infection's log repeats the whole source file with every mutant: no memory limit to read it.
php -d memory_limit=-1 tools/infection/gate.php record "$set_name" "$part" "$log" "$code" "$before" "$threads" "$cpus" "$out"
echo "$set_name.$part: Infection exited with $code; recorded in $out"
