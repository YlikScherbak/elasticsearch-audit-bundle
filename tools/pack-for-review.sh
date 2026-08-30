#!/usr/bin/env bash
#
# Packs the bundle into one file per review axis, for handing to a model that cannot
# clone a repository.
#
# One axis per conversation. The whole of src/ is around fifty thousand tokens: it fits
# in a large context window and produces a shallow review anyway, because "here is
# everything, find bugs" is not a question anyone can answer. An axis is ten to fifteen
# thousand tokens with its own tests beside it, which is small enough to be read.
#
#   tools/pack-for-review.sh            # writes review/*.txt
#   tools/pack-for-review.sh /tmp/audit # somewhere else
#
# Then open tools/review-prompts.md, take the prompt for that axis, and attach the pack.

set -euo pipefail

cd "$(dirname "$0")/.."
out="${1:-review}"
mkdir -p "$out"

commit=$(git rev-parse --short HEAD 2>/dev/null || echo "not a git checkout")
git diff --quiet 2>/dev/null || commit="$commit, with uncommitted changes"

pack() {
    local name="$1"
    shift

    local file="$out/$name.txt"
    : > "$file"

    {
        # The commit goes in the header so a finding can be checked against the state it
        # was made about, rather than against a tree that has moved since.
        printf 'Bundle: borsche/elasticsearch-audit-bundle @ %s, review axis "%s".\n' "$commit" "$name"
        printf 'Files follow, each after a ===== path ===== line.\n'
    } >> "$file"

    local found=0
    while read -r php; do
        printf '\n===== %s =====\n' "$php" >> "$file"
        cat "$php" >> "$file"
        found=$((found + 1))
    done < <(find "$@" -name '*.php' 2>/dev/null | sort)

    if [ "$found" -eq 0 ]; then
        printf '  %-18s nothing to pack (paths missing?)\n' "$name"
        rm -f "$file"
        return
    fi

    local bytes
    bytes=$(wc -c < "$file")
    printf '  %-18s %2d files, %6d bytes, ~%5d tokens\n' "$name" "$found" "$bytes" "$((bytes / 4))"
}

printf 'Packing into %s/\n' "$out"

# Each axis is a promise the bundle makes, plus the code that has to keep it and the
# tests that claim it does. A reviewer needs all three: without the promise there is
# nothing to falsify, and without the tests it re-reports what is already covered.
pack doctrine          src/Doctrine src/Attribute src/Contract/AuditableInterface.php \
                       src/Contract/TracksCollectionElementsInterface.php tests/Doctrine
pack writer-coalescing src/Writer src/Coalescing src/Transport src/Event \
                       src/Contract/AuditEnricherInterface.php \
                       src/Contract/MergedRecordEnricherInterface.php \
                       src/Contract/ValueComparatorInterface.php tests/Writer tests/Coalescing
pack read-path         src/Reader src/Model tests/Reader tests/Model
pack di-boot           src/DependencyInjection src/ElasticsearchAuditBundle.php src/Command \
                       tests/DependencyInjection tests/BundleBootTest.php tests/Command
pack privacy           src/Privacy src/Model/AuditRecord.php src/Model/Change.php \
                       src/Elasticsearch/IndexDefinition.php tests/Privacy
pack elasticsearch     src/Elasticsearch src/Exception tests/Elasticsearch tests/Integration

printf 'Prompts: tools/review-prompts.md\n'
