#!/usr/bin/env bash
#
# ship.sh — the ONLY sanctioned way to put code on a Manara server.
#
#     scripts/ship.sh staging [ref]      # any branch or sha
#     scripts/ship.sh production         # main only, with a typed confirmation
#     scripts/ship.sh staging my-branch --dry-run
#
# Runs on the Mac. It does the two halves of a deploy that must happen together
# and that a human doing it by hand has repeatedly done only one of:
#
#   1. the SPA — built HERE (there is no node on the servers) and rsynced, and
#   2. the PHP — `sudo bin/deploy` on the box.
#
# `git pull` alone ships no frontend. A deploy that runs bin/deploy without the
# rsync leaves the server rendering an old bundle against new routes.
#
# WHY the verification at the end is not optional: every deploy failure this
# project has had was invisible at the point of failure — a 200 with the wrong
# bundle, a bundle with a hostname baked into it, a chunk that 404s because it
# was rsynced with --delete against a browser holding a cached index. So the
# script finishes by fetching the real sign-in page as a browser would, resolving
# the entry chunk it actually references, and proving that chunk both exists and
# carries no baked hostname.
#
set -euo pipefail

# --------------------------------------------------------------------------
# Host map
# --------------------------------------------------------------------------
# HTTP host = what a browser (and the verification below) asks for.
# SSH host  = where rsync/ssh connect. They differ on production: the public
#             name resolves to the RESERVED IP 164.90.253.138, while the droplet
#             itself is 159.65.239.51 — ship to the droplet, verify the name.
PRODUCTION_HTTP_HOST="masjid.hopetechapps.com"
PRODUCTION_SSH_HOST="159.65.239.51"
STAGING_HTTP_HOST="masjid-staging.hopetechapps.com"

SSH_KEY="${SSH_KEY:-$HOME/.ssh/do_mcp}"
SSH_USER="${SSH_USER:-root}"

# Same absolute path on both boxes — staging is a clone of the prod droplet, so
# the app dir, the queue unit and the cron line are all identical by construction.
REMOTE_APP_DIR="${REMOTE_APP_DIR:-/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem}"
WEB_USER="${WEB_USER:-www-data}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGING_HOST_FILE="$REPO_ROOT/deploy/staging/host"

# --------------------------------------------------------------------------
# Output helpers
# --------------------------------------------------------------------------
BOLD=$'\033[1m'; RED=$'\033[1;31m'; GREEN=$'\033[1;32m'; RESET=$'\033[0m'

say()  { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
warn() { printf '%s!! %s%s\n' "$RED" "$1" "$RESET" >&2; }
die()  { printf '\n%sship: %s%s\n\n' "$RED" "$1" "$RESET" >&2; exit 1; }
ok()   { printf '%s   ok %s%s\n' "$GREEN" "$1" "$RESET"; }

DRY_RUN=0
# run(): the single choke point for everything with a side effect, so --dry-run
# is honest by construction rather than by remembering to guard each call.
run() {
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '   [dry-run] %s\n' "$*"
        return 0
    fi
    "$@"
}

usage() {
    cat <<'USAGE'
usage: scripts/ship.sh <staging|production> [ref] [--dry-run]

  staging      deploys any ref (default main) to masjid-staging.hopetechapps.com
  production   deploys main ONLY, after you type the confirmation phrase

  ref          branch name or sha. Refused on production unless it is "main".
  --dry-run    print every command instead of running it. Builds nothing,
               connects to nothing.

  The staging droplet's IP comes from $STAGING_IP, else from
  deploy/staging/host (written by deploy/staging/provision.sh).
USAGE
}

# --------------------------------------------------------------------------
# Argument validation
# --------------------------------------------------------------------------
ENV_NAME=""
REF=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        -*)        usage >&2; die "unknown option: $1" ;;
        *)
            if [ -z "$ENV_NAME" ]; then
                ENV_NAME="$1"
            elif [ -z "$REF" ]; then
                REF="$1"
            else
                usage >&2
                die "too many arguments (got an extra '$1')."
            fi
            shift
            ;;
    esac
done

[ -n "$ENV_NAME" ] || { usage >&2; die "which environment? staging or production."; }

case "$ENV_NAME" in
    staging|production) ;;
    *) die "unknown environment '$ENV_NAME'. Valid: staging, production." ;;
esac

[ -n "$REF" ] || REF="main"

# WHY production is pinned to main: bin/deploy on the production box refuses
# --ref outright, so shipping a branch there could only ever half-work — the SPA
# would be the branch's and the PHP would still be main's. Refuse here, loudly,
# rather than discover it as a mismatched pair on a live site.
if [ "$ENV_NAME" = "production" ] && [ "$REF" != "main" ]; then
    die "production ships main only (you asked for '$REF').
     bin/deploy refuses --ref on a production host, so the PHP would stay on main
     while the SPA became '$REF' — a split deploy. Ship it to staging first:
         scripts/ship.sh staging $REF"
fi

# --------------------------------------------------------------------------
# Resolve the target
# --------------------------------------------------------------------------
if [ "$ENV_NAME" = "production" ]; then
    HTTP_HOST="$PRODUCTION_HTTP_HOST"
    SSH_HOST="$PRODUCTION_SSH_HOST"
else
    HTTP_HOST="$STAGING_HTTP_HOST"
    # The staging droplet is created by the owner, so its IP is not knowable at
    # commit time. provision.sh writes deploy/staging/host on the box; copy that
    # file down, or export STAGING_IP, rather than editing this script (an edited
    # host map is how a "staging" deploy reaches production).
    SSH_HOST="${STAGING_IP:-}"
    if [ -z "$SSH_HOST" ] && [ -f "$STAGING_HOST_FILE" ]; then
        SSH_HOST="$(tr -d '[:space:]' < "$STAGING_HOST_FILE")"
    fi
    [ -n "$SSH_HOST" ] || die "no staging IP.
     Set STAGING_IP=<ip> or write it to deploy/staging/host
     (deploy/staging/provision.sh prints it, and deploy/staging/RUNBOOK.md
     step 1 tells you where to put it)."
fi

# A staging IP that is a production address means the host map or the file is
# wrong, and the next two steps would rsync a branch build onto the live site.
# WHY both addresses: 159.65.239.51 is the prod droplet, 164.90.253.138 its
# reserved IP; 147.182.210.42 is the stale droplet that still shares prod's DB.
if [ "$ENV_NAME" = "staging" ]; then
    case "$SSH_HOST" in
        159.65.239.51|164.90.253.138|147.182.210.42)
            die "refusing: staging IP resolved to $SSH_HOST, which is a PRODUCTION
     (or prod-DB-connected) address. Fix STAGING_IP / deploy/staging/host."
            ;;
    esac
fi

SSH_TARGET="${SSH_USER}@${SSH_HOST}"
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=15 -i "$SSH_KEY")

say "Shipping"
printf '  environment : %s\n' "$ENV_NAME"
printf '  ref         : %s\n' "$REF"
printf '  http host   : https://%s\n' "$HTTP_HOST"
printf '  ssh target  : %s\n' "$SSH_TARGET"
printf '  app dir     : %s\n' "$REMOTE_APP_DIR"
[ "$DRY_RUN" -eq 1 ] && printf '  mode        : DRY RUN (nothing will be built, sent or run)\n'

# --------------------------------------------------------------------------
# Production confirmation
# --------------------------------------------------------------------------
# WHY a typed phrase and not y/N: this deploys to a site that takes real money.
# A single keystroke is exactly what a mistyped command already gives you.
if [ "$ENV_NAME" = "production" ] && [ "$DRY_RUN" -eq 0 ]; then
    printf '%s\n' "" \
        "${RED}  ############################################################" \
        "  #                  PRODUCTION  DEPLOY                      #" \
        "  #  https://masjid.hopetechapps.com - live donations, live  #" \
        "  #  Stripe, real congregations. Staging first (see          #" \
        "  #  .claude/rules/environments.md).                         #" \
        "  ############################################################${RESET}" \
        ""
    printf '  Type exactly: ship production\n  > '
    read -r CONFIRMATION || CONFIRMATION=""
    [ "$CONFIRMATION" = "ship production" ] || die "not confirmed — nothing was done."
fi

# --------------------------------------------------------------------------
# 1. Build the SPA
# --------------------------------------------------------------------------
# NEVER `npm run build:prod`. It sets VITE_APP_URL=https://masjid.hopetechapps.com,
# which vite.config.js bakes into process.env.APP_URL in the bundle. The SPA then
# addresses that ONE host from every other host it is served on — manara.*,
# portal.alrazischool.org, burlingtonmasjid.com, and staging — so those hosts get
# a bundle that talks to production. Assert the variable is absent from this
# shell too: an exported VITE_APP_URL reproduces the defect through plain
# `npm run build`, and the resulting bundle looks completely normal.
say "Checking the build environment"
if [ -n "${VITE_APP_URL:-}" ]; then
    die "VITE_APP_URL is set in this shell ('${VITE_APP_URL}').
     vite bakes it into the bundle and every non-matching host then calls that
     host instead of itself. Run:  unset VITE_APP_URL   and ship again."
fi
ok "VITE_APP_URL is unset"

# THE SAME VARIABLE, THROUGH THE OTHER DOOR.
# The shell check above covers vite.config.js's `define`, which reads only the
# process environment. But vite ALSO loads these files into
# `import.meta.env`, and four SPA call sites read `import.meta.env.VITE_APP_URL`
# directly (appConfigConstants, FamilyApiService, StudentApiService,
# publicLunchStore). A value in any of them bakes a host into the bundle exactly
# as the shell variable does — and nothing checked them. `vite build` runs in
# production mode, so these are the four it reads. Any non-empty value refuses,
# including a `${REFERENCE}`, which vite expands.
#
# A MISSING .env IS CORRECT, and is said as a fact rather than warned about: no
# worktree has one, the build needs nothing from it, and the only thing it used
# to affect — the tab title — is now set at runtime (core/pageTitle.ts). A
# warning that fired on every correct deploy would teach people to ignore this
# block. Do NOT "fix" a missing .env by copying one in: that is how a
# VITE_APP_URL gets into a build tree in the first place.
for env_file in .env .env.local .env.production .env.production.local; do
    env_path="$REPO_ROOT/$env_file"
    [ -f "$env_path" ] || continue
    env_values="$(grep -E '^[[:space:]]*(export[[:space:]]+)?VITE_APP_URL[[:space:]]*=' "$env_path" \
        | sed -E -e 's/^[^=]*=//' -e 's/[[:space:]]+#.*$//' -e 's/^[[:space:]]+//' -e 's/[[:space:]]+$//' \
                 -e 's/^"(.*)"$/\1/' -e "s/^'(.*)'\$/\1/" || true)"
    while IFS= read -r env_value; do
        if [ -n "$env_value" ]; then
            die "VITE_APP_URL is set in ${env_file} ('${env_value}').
     vite loads ${env_file} into the bundle, so every host but that one would
     call that host instead of itself — the build:prod defect, arriving through
     a file instead of the shell. Empty it (VITE_APP_URL=) or remove the line,
     and ship again."
        fi
    done <<ENV_VALUES
$env_values
ENV_VALUES
done
if [ -f "$REPO_ROOT/.env" ]; then
    ok "no VITE_APP_URL value in this tree's env files"
else
    ok "no .env in this tree — correct: the SPA build reads nothing it needs from one"
fi

cd "$REPO_ROOT"

# --------------------------------------------------------------------------
# THE BUNDLE AND THE PHP MUST AGREE ABOUT THE FRONTEND
# --------------------------------------------------------------------------
# This script builds the SPA from the working tree it lives in, and separately
# tells the server to check out `origin/<ref>`. Nothing made those agree.
#
# On a PHP-only change it does not matter, which is exactly why it survived: the
# gap is invisible on every deploy where it is harmless. On a frontend change it
# is silent and total — the server gets the ref's PHP with this tree's bundle,
# the deploy reports success, every API assertion passes, and the screen the
# change was written to fix is untouched.
#
# THE CONDITION IS NOT SHA EQUALITY. The first version of this guard refused
# whenever the tree and the ref differed, and Abdullah showed it would have
# refused a correct deploy: shipping a PHP-only fix from a feature worktree, with
# zero frontend inputs differing, produced a byte-identical bundle. Refusing
# there blocks most of how this team actually works, and a guard that fires on
# correct behaviour is one people learn to route around — at which point the
# workaround is the habit and it is in place when the difference is real.
#
# So the check is the real question: do the BUILD INPUTS differ between this
# tree and the ref? If they do not, the bundle this tree produces is the bundle
# the ref would produce, whatever the shas say.
#
# NOTE on `resources/`, traced rather than assumed (Abdullah, 2026-09-16).
# `vite.config.js:11` takes exactly two entry points — `resources/js/app.js` and
# `resources/css/app.css` — so the build graph is whatever those two reach.
#
#   resources/views      NOT in the graph. 23 Blade files, and nothing under
#                        js/ vue-app/ css/ sass/ imports from them; the only
#                        references are comments. `EnvironmentRibbon.vue` says it
#                        outright: its env values come from
#                        `vue-app-index.blade.php` at RUNTIME, from the server's
#                        own checkout, and are "NOT read from import.meta.env".
#                        A Blade-only difference genuinely cannot change the
#                        bundle, so refusing on one is conservative, not correct.
#
#   resources/flyer-templates   Evidence contradicts itself. `Flyer.ts:5` says
#                        the templates are "bundled into this app at build time",
#                        but the only real consumer found is
#                        `FlyerTemplatesController.php:32` reading the directory
#                        SERVER-side, and every `Flyer.ts` hit is a comment rather
#                        than an import. One of the two is stale and nobody has
#                        traced which.
#
# Both stay in the list anyway. A conservative refusal on files that almost never
# change is a far better trade than a hole, and narrowing on reasoning rather
# than on a guard that actually fired is the mistake this whole night has been
# made of. When it refuses something correct, drop `resources/views` and keep the
# rest — one line, and by then you will have the evidence.
FRONTEND_INPUTS="resources/ package.json package-lock.json vite.config.js postcss.config.js tailwind.config.js"

# Checked even on a dry run. A dry run exists to say what would happen, and
# "it would refuse" is the single most useful thing it can report.
if true; then
    git -C "$REPO_ROOT" fetch -q origin "$REF" 2>/dev/null || true
    TARGET_SHA="$(git -C "$REPO_ROOT" rev-parse --verify -q "origin/${REF}" \
        || git -C "$REPO_ROOT" rev-parse --verify -q "$REF" || true)"
    LOCAL_SHA="$(git -C "$REPO_ROOT" rev-parse --verify -q HEAD || true)"

    [ -n "$TARGET_SHA" ] || die "cannot resolve '${REF}' to a commit — is it pushed?"

    # 1. Uncommitted build inputs ship regardless of the ref, because they are in
    #    no commit at all. This is the hole one step further back from the one
    #    above, and it cannot be reasoned away by comparing commits.
    # shellcheck disable=SC2086
    if ! git -C "$REPO_ROOT" diff --quiet -- $FRONTEND_INPUTS 2>/dev/null; then
        # shellcheck disable=SC2086
        die "uncommitted changes to build inputs in ${REPO_ROOT}:

$(git -C "$REPO_ROOT" diff --name-only -- $FRONTEND_INPUTS | sed 's/^/       /')

     These would be built into the bundle and are in no commit, so nothing on
     the server will ever match them. Commit or stash, then ship."
    fi

    # 2. The real condition: can this tree build the ref's frontend?
    # shellcheck disable=SC2086
    if ! git -C "$REPO_ROOT" diff --quiet "$LOCAL_SHA" "$TARGET_SHA" -- $FRONTEND_INPUTS 2>/dev/null; then
        # shellcheck disable=SC2086
        die "this tree cannot build ${REF}'s frontend.

     deploying : ${REF} -> ${TARGET_SHA}
     this tree : ${LOCAL_SHA}
     tree path : ${REPO_ROOT}

     Build inputs that differ:

$(git -C "$REPO_ROOT" diff --name-only "$LOCAL_SHA" "$TARGET_SHA" -- $FRONTEND_INPUTS | sed 's/^/       /')

     The SPA is built HERE and the PHP is checked out THERE. Shipping now would
     put this tree's bundle on the server with ${REF}'s PHP, and the frontend
     changes listed above would be missing while the deploy reported success.

     Run this from a worktree checked out at ${TARGET_SHA}."
    fi

    if [ "$LOCAL_SHA" = "$TARGET_SHA" ]; then
        ok "tree is at ${TARGET_SHA:0:8}, the commit being deployed"
    else
        # Said out loud rather than passed over in silence: the shas differ and
        # this deploy is still correct, and the reason is on the record.
        ok "tree ${LOCAL_SHA:0:8} != ref ${TARGET_SHA:0:8}, but frontend inputs are identical — the bundle this tree builds is the bundle ${REF} would build"
    fi
fi

say "Building the SPA (npm run build)"
BUILD_LOG="$REPO_ROOT/artifacts/vue_build_ship_${ENV_NAME}_$(date +%Y%m%d-%H%M%S).log"
if [ "$DRY_RUN" -eq 1 ]; then
    printf '   [dry-run] npm run build   (log -> %s)\n' "$BUILD_LOG"
else
    mkdir -p "$REPO_ROOT/artifacts"
    npm run build 2>&1 | tee "$BUILD_LOG"
    [ -f "$REPO_ROOT/public/build/manifest.json" ] \
        || die "build produced no public/build/manifest.json — see $BUILD_LOG"
    ok "built (log: $BUILD_LOG)"
fi

# --------------------------------------------------------------------------
# 2. Ship the SPA
# --------------------------------------------------------------------------
# NO --delete. The server keeps every historic hash-named chunk on purpose:
# a browser holding a cached index.html asks for chunks by their OLD hashes, and
# deleting them turns a cached tab into a wall of 404s. The directory grows
# (1,240+ chunks on prod today); that is the accepted cost.
#
# Flags are limited to what Apple's openrsync (protocol 29) accepts: -a -z and
# the three --no-* toggles. GNU-only flags (--info=progress2, --outbuf) are not
# available here. --no-perms/--no-owner/--no-group because the local files are
# owned by the Mac user and the chown below is what sets the server-side owner.
say "Shipping public/build/ (rsync, no --delete)"
run rsync -az --no-perms --no-owner --no-group \
    -e "ssh -o BatchMode=yes -o ConnectTimeout=15 -i ${SSH_KEY}" \
    "$REPO_ROOT/public/build/" \
    "${SSH_TARGET}:${REMOTE_APP_DIR}/public/build/"

# php-fpm runs as www-data; files arriving owned by root are readable but any
# later artisan/cache write into the tree is not. This is the same class of bug
# as the 2026-08 root-owned cache files.
say "Fixing ownership on the server"
run ssh "${SSH_OPTS[@]}" "$SSH_TARGET" \
    "chown -R ${WEB_USER}:${WEB_USER} ${REMOTE_APP_DIR}/public/build"
ok "public/build owned by ${WEB_USER}"

# --------------------------------------------------------------------------
# 3. Deploy the PHP
# --------------------------------------------------------------------------
say "Running bin/deploy on the server"
if [ "$ENV_NAME" = "production" ]; then
    # No --ref: the production box refuses it, and main is the only thing it
    # will fast-forward to anyway.
    run ssh "${SSH_OPTS[@]}" "$SSH_TARGET" \
        "cd ${REMOTE_APP_DIR} && sudo bin/deploy"
else
    run ssh "${SSH_OPTS[@]}" "$SSH_TARGET" \
        "cd ${REMOTE_APP_DIR} && sudo bin/deploy --ref $(printf '%q' "$REF")"
fi

# --------------------------------------------------------------------------
# 4. Verify — as a browser, not as ourselves
# --------------------------------------------------------------------------
say "Verifying https://${HTTP_HOST}/auth/sign-in"

if [ "$DRY_RUN" -eq 1 ]; then
    printf '   [dry-run] curl -fsS https://%s/auth/sign-in\n' "$HTTP_HOST"
    printf '   [dry-run] extract /build/assets/app-*.js from that HTML\n'
    printf '   [dry-run] curl -o /dev/null -w %%{http_code} the chunk (expect 200)\n'
    printf '   [dry-run] grep -c masjid.hopetechapps.com in the chunk (expect 0)\n'
    say "Dry run complete — nothing was built, sent or run."
    exit 0
fi

SIGN_IN_HTML="$(curl -fsS --max-time 30 "https://${HTTP_HOST}/auth/sign-in")" \
    || die "could not fetch https://${HTTP_HOST}/auth/sign-in"

# The Blade emits the entry chunk through @vite, so the HTML names the exact file
# the browser will load. Resolving it FROM THE PAGE (rather than from the local
# manifest) is what makes this a real check: it proves the server is serving the
# bundle we just shipped and not an older manifest.
ENTRY_PATH="$(printf '%s' "$SIGN_IN_HTML" \
    | grep -oE '/build/assets/app-[A-Za-z0-9_-]+\.js' \
    | head -n 1)"

[ -n "$ENTRY_PATH" ] || die "the sign-in page references no /build/assets/app-*.js.
     Either the Blade did not render (check the server) or the manifest is stale."
ok "entry chunk: ${ENTRY_PATH}"

ENTRY_URL="https://${HTTP_HOST}${ENTRY_PATH}"
ENTRY_FILE="$(mktemp -t ship-entry)"
# WHY trap and not a plain rm at the end: every die() below exits early.
trap 'rm -f "$ENTRY_FILE"' EXIT

ENTRY_CODE="$(curl -sS --max-time 60 -o "$ENTRY_FILE" -w '%{http_code}' "$ENTRY_URL" || echo 000)"
[ "$ENTRY_CODE" = "200" ] || die "$ENTRY_URL returned HTTP ${ENTRY_CODE}, not 200.
     The page references a chunk the server does not have — the rsync did not land."
ok "chunk returns 200 ($(wc -c < "$ENTRY_FILE" | tr -d ' ') bytes)"

# Grep ONLY this chunk. Never the whole assets dir: it holds 1,240+ historic
# chunks, some built with build:prod years ago, so a directory-wide grep is
# guaranteed to "find" the hostname and tell you nothing about what you shipped.
BAKED="$(grep -c 'masjid\.hopetechapps\.com' "$ENTRY_FILE" || true)"
if [ "$BAKED" != "0" ]; then
    warn "ABORT: the shipped bundle has masjid.hopetechapps.com baked into it (${BAKED} occurrences)."
    warn "This is the build:prod / VITE_APP_URL defect. Every host other than"
    warn "masjid.hopetechapps.com will now call production instead of itself."
    warn "Fix:  unset VITE_APP_URL && npm run build && scripts/ship.sh ${ENV_NAME} ${REF}"
    die "shipped bundle is host-locked — treat this as an incident, not a warning."
fi
ok "no hostname baked into the bundle (0 occurrences)"

say "Shipped"
printf '  %s  <-  %s @ %s\n' "https://${HTTP_HOST}" "$ENV_NAME" "$REF"
if [ "$ENV_NAME" = "staging" ]; then
    printf '  Record the verification in LOG.md before shipping this ref to production.\n'
fi
