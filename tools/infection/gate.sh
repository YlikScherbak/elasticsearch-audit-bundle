#!/bin/sh
# The mutation gate for one set of parts.json, run here the way CI runs it in jobs:
# the plan (a dry run over the whole configuration), then every part, then the summary.
#
#   tools/infection/gate.sh main
#   tools/infection/gate.sh doctrine
#   tools/infection/gate.sh doctrine subscriber   # the plan and one part; the summary then
#                                                 # names the parts that did not run
#
# See tools/infection/gate.php for what the summary refuses and why. The parts run with
# no floor of their own: the floor is the set's, and only the summary can know it.
# Recording reads Infection's whole log, which repeats the source file with every mutant,
# hence no memory limit there.
set -e

cd "$(dirname "$0")/../.."

set_name=$1
shift
config=$(php -r '$m = json_decode(file_get_contents("tools/infection/parts.json"), true); echo $m[$argv[1]]["config"] ?? "";' "$set_name")
[ -n "$config" ] || { echo "parts.json has no set \"$set_name\"" >&2; exit 2; }

log="var/infection/$set_name.json"
out="var/infection/gate"
parts=${*:-$(php tools/infection/gate.php parts "$set_name")}

rm -f "$out/$set_name".*.json

run() {
    name=$1
    shift
    before=$(php tools/infection/gate.php fingerprint "$set_name")
    rm -f "$log"
    code=0
    sh tools/infection/run.sh --configuration="$config" --min-covered-msi=0 "$@" || code=$?
    php -d memory_limit=-1 tools/infection/gate.php record "$set_name" "$name" "$log" "$code" "$before" "$out"
}

run plan --dry-run
for part in $parts; do
    run "$part" --filter="$(php tools/infection/gate.php files "$set_name" "$part")"
done

php tools/infection/gate.php summarise "$set_name" "$out"
