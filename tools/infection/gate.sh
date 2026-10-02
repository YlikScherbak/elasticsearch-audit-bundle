#!/bin/sh
# The mutation gate for one set of parts.json, run here the way CI runs it in jobs:
# the plan (a dry run over the whole configuration), then every part, then the summary.
#
#   tools/infection/gate.sh main
#   tools/infection/gate.sh doctrine
#   tools/infection/gate.sh doctrine subscriber   # the plan and one part; the summary then
#                                                 # names the parts that did not run
#
# Each run is tools/infection/part.sh, which CI runs too; see tools/infection/gate.php for what
# the summary refuses and why. The parts run with no floor of their own: the floor is the
# set's, and only the summary can know it.
set -e

cd "$(dirname "$0")/../.."

set_name=$1
shift
out=${INFECTION_GATE_OUT:-var/infection/gate}
parts=${*:-$(php tools/infection/gate.php parts "$set_name")}

rm -f "$out/$set_name".*.json

sh tools/infection/part.sh "$set_name" plan
for part in $parts; do
    sh tools/infection/part.sh "$set_name" "$part" "$out/$set_name.plan.json"
done

php tools/infection/gate.php summarise "$set_name" "$out"
