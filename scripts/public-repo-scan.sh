#!/usr/bin/env bash
# public-repo-scan.sh — refuse private values in lines ADDED to this public repo's
# docs, rules and fixtures.
#
# WHY: MasjidWebMS is public, and a pushed branch is published the moment it is
# pushed. Docs, rule files and captured test fixtures are where private values
# leak: a live Stripe id copied from a dashboard, a parent's real email address in
# a captured webhook payload. The code review cannot be the only thing standing
# between those and the internet.
#
# Only ADDED lines are scanned (git diff BASE...HEAD), so the repo's existing
# history never fails this; only the change being pushed can.
#
# usage: scripts/public-repo-scan.sh [BASE] [--strict]
#   BASE      a commit to diff from (default: the merge base with origin/main).
#             An all-zeros BASE (a push that created a branch) falls back to it.
#   --strict  also refuse school names and currency amounts. For the tuition
#             work, whose docs must use generic names and no prices; not the CI
#             default, because other work names client schools in DECISIONS.md
#             on purpose.
#
# Always refused:
#   - Stripe ids that look real: a known prefix followed by 14+ id characters,
#     unless the id says it is fake (contains "test", "fake", "example",
#     "sample" or "xxx"). Fixtures use scrubbed ids like pi_fake_000001.
#   - Email addresses outside the reserved domains (example.com/.net/.org and
#     any .test, .example, .invalid or .localhost name) and the platform's own
#     system addresses (@hopetechapps.com and its subdomains, e.g. the
#     notifications@ sender). Those name a service, not a person.
set -euo pipefail

BASE=""
STRICT=0
for arg in "$@"; do
    case "$arg" in
        --strict) STRICT=1 ;;
        *) BASE="$arg" ;;
    esac
done

# No base, a push that created the branch (all zeros), or a base this clone does
# not have (a force-push rewrote it away): diff from the merge base with main.
if [ -z "$BASE" ] || [ "$BASE" = "0000000000000000000000000000000000000000" ] \
    || ! git rev-parse --verify -q "$BASE^{commit}" >/dev/null; then
    git fetch -q origin main 2>/dev/null || true
    BASE="$(git merge-base HEAD origin/main)"
fi
BASE="$(git rev-parse --verify "$BASE^{commit}")"

PATHS=(docs .claude/rules DECISIONS.md ASSUMPTIONS.md tests/fixtures)

# Added lines only, with the file each came from, for the message.
added="$(git diff --unified=0 --no-color "$BASE"...HEAD -- "${PATHS[@]}" \
    | awk '/^\+\+\+ b\//{file=substr($0,7); next} /^\+[^+]/{print file ": " substr($0,2)}')"

if [ -z "$added" ]; then
    echo "public-repo-scan: no added lines in docs, rules or fixtures since ${BASE:0:8}."
    exit 0
fi

problems=""

stripe_ids="$(printf '%s\n' "$added" \
    | grep -E '\b(acct|cus|evt|we|pi|ch|py|in|sub|sub_sched|seti|pm|cs|re|dp|txn|ba)_[A-Za-z0-9]{14,}\b' \
    | grep -viE '\b[a-z_]+_[A-Za-z0-9]*(test|fake|example|sample|xxx)[A-Za-z0-9]*\b' || true)"
[ -n "$stripe_ids" ] && problems+=$'\nStripe ids that look real (scrub them to fake ids):\n'"$stripe_ids"$'\n'

emails="$(printf '%s\n' "$added" \
    | grep -oE '^[^:]+: .*' \
    | grep -E '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' \
    | grep -vE '@([A-Za-z0-9-]+\.)*(example\.(com|net|org)|[A-Za-z0-9-]+\.(test|example|invalid|localhost))\b' \
    | grep -vE '@(example\.(com|net|org))\b' \
    | grep -vE '@([A-Za-z0-9-]+\.)*hopetechapps\.com\b' || true)"
[ -n "$emails" ] && problems+=$'\nEmail addresses outside the reserved domains:\n'"$emails"$'\n'

if [ "$STRICT" -eq 1 ]; then
    schools="$(printf '%s\n' "$added" | grep -iE 'al-?razi|burlington|\bBISS\b|\bMEC\b|muslim education center|al-?aqsa|intellicor|nafis' || true)"
    [ -n "$schools" ] && problems+=$'\nSchool or client names (strict mode: use generic names):\n'"$schools"$'\n'

    amounts="$(printf '%s\n' "$added" | grep -E '\$[0-9]{2,}|\$[0-9]{1,3},[0-9]{3}' || true)"
    [ -n "$amounts" ] && problems+=$'\nCurrency amounts (strict mode: prices live in settings rows, never in commits):\n'"$amounts"$'\n'
fi

if [ -n "$problems" ]; then
    printf 'public-repo-scan: refused. This repository is public.\n%s' "$problems" >&2
    exit 1
fi

echo "public-repo-scan: clean ($(printf '%s\n' "$added" | wc -l | tr -d ' ') added lines checked since ${BASE:0:8}$([ "$STRICT" -eq 1 ] && echo ', strict'))."
