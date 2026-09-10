#!/usr/bin/env bash
#
# cloudflare-dns.sh — point the staging hostnames at the staging droplet.
#
#     deploy/staging/cloudflare-dns.sh <ip>
#     deploy/staging/cloudflare-dns.sh <ip> --dry-run
#
# Creates or updates three PROXIED A records in hopetechapps.com:
#
#     masjid-staging.hopetechapps.com     the app / API host
#     manara-staging.hopetechapps.com     the platform host
#     portal-staging.hopetechapps.com     the branded school portal door
#
# WHY PROXIED (orange cloud), when production's masjid.* is DNS-only:
# the zone's Universal SSL certificate covers `hopetechapps.com` and
# `*.hopetechapps.com` — one label deep — so all three names above are already
# covered at the edge, with nothing to issue and no wait. The zone's SSL mode is
# `full` (NOT `full (strict)`), so Cloudflare encrypts to the origin without
# validating the origin certificate's name; the staging box can therefore keep
# serving production's copied certificate and never run certbot. A DNS-only
# record would bypass all of that and require a real certificate on the origin
# for each name — which a proxied record cannot even validate.
#
# Names with TWO labels (staging.masjid.hopetechapps.com) are NOT covered by that
# wildcard and would break; that is why every name here is a single label.
#
# IDEMPOTENT: each name is looked up first. Found -> PUT (update in place, so the
# record keeps its id and any downstream reference). Absent -> POST.
#
set -euo pipefail

ZONE_NAME="hopetechapps.com"
ZONE_ID="859eddb9bce48f4f35e6197f6c0b8e15"
TOKEN_FILE="${CLOUDFLARE_TOKEN_FILE:-$HOME/.cloudflare-token}"
API="https://api.cloudflare.com/client/v4"

# Every name this script is allowed to touch. See the guard below: the suffix
# check is what actually enforces it, this list is what it creates.
RECORD_NAMES="masjid-staging manara-staging portal-staging"

# The one string that makes a write safe. NOTHING is written to a record whose
# name does not end in this. The zone holds masjid.hopetechapps.com (production's
# API host, TTL 60), manara.hopetechapps.com, the Pages CNAMEs, the Zoho MX/SPF/
# DKIM records and three CAA records — a wrong PUT here would take production, or
# the company's email, off the internet.
REQUIRED_SUFFIX="-staging.${ZONE_NAME}"

BOLD=$'\033[1m'; RED=$'\033[1;31m'; GREEN=$'\033[1;32m'; RESET=$'\033[0m'
say()  { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
info() { printf '    %s\n' "$1"; }
ok()   { printf '%s    ok %s%s\n' "$GREEN" "$1" "$RESET"; }
die()  { printf '\n%scloudflare-dns: %s%s\n\n' "$RED" "$1" "$RESET" >&2; exit 1; }

usage() {
    cat <<'USAGE'
usage: deploy/staging/cloudflare-dns.sh <ip> [--dry-run]

  <ip>        the staging droplet's public IPv4 address (provision.sh prints it
              and writes it to deploy/staging/host on the box).
  --dry-run   print the exact HTTP requests that would be sent, and send none.
              Reads are still performed so the plan is real.
USAGE
}

IP=""
DRY_RUN=0
while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        -*) usage >&2; die "unknown option: $1" ;;
        *)
            [ -z "$IP" ] || { usage >&2; die "too many arguments."; }
            IP="$1"; shift ;;
    esac
done

[ -n "$IP" ] || { usage >&2; die "no IP given."; }

# Shape check first: a malformed value would be rejected by the API anyway, but a
# plausible-looking wrong value (a hostname, a v6 address) is worth catching here
# where the message can say so.
printf '%s' "$IP" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' \
    || die "'$IP' is not an IPv4 address."

# WHY: pointing a *-staging name at production is the one mistake that would make
# these records dangerous — traffic aimed at staging would land on the live site,
# and anyone testing would believe they were on staging while taking real money.
case "$IP" in
    159.65.239.51|164.90.253.138)
        die "$IP is PRODUCTION. Refusing to point a staging hostname at it." ;;
    147.182.210.42)
        die "$IP is the stale droplet 480119186, which shares production's
     database. Refusing." ;;
esac

command -v jq   >/dev/null 2>&1 || die "jq is required."
command -v curl >/dev/null 2>&1 || die "curl is required."

[ -f "$TOKEN_FILE" ] || die "no token at ${TOKEN_FILE}."
TOKEN="$(tr -d '[:space:]' < "$TOKEN_FILE")"
[ -n "$TOKEN" ] || die "${TOKEN_FILE} is empty."

cf() {
    # $1 method, $2 path, $3 (optional) json body
    local method="$1" path="$2" body="${3:-}"
    if [ -n "$body" ]; then
        curl -sS -X "$method" "${API}${path}" \
            -H "Authorization: Bearer ${TOKEN}" \
            -H "Content-Type: application/json" \
            --data "$body"
    else
        curl -sS -X "$method" "${API}${path}" \
            -H "Authorization: Bearer ${TOKEN}"
    fi
}

cf_ok() {
    # Cloudflare answers 200 with success:false for most real failures, so the
    # body is the only honest status.
    printf '%s' "$1" | jq -e '.success == true' >/dev/null 2>&1
}

say "Zone"
# This token is ACCOUNT-owned. /user/tokens/verify returns "Invalid API Token"
# for it even though it works — do not use that endpoint to test it. Reading the
# zone is both the real permission check and the thing we need anyway.
ZONE_RESP="$(cf GET "/zones/${ZONE_ID}")"
cf_ok "$ZONE_RESP" || die "cannot read zone ${ZONE_ID}: $(printf '%s' "$ZONE_RESP" | jq -c '.errors // .')"
ACTUAL_ZONE="$(printf '%s' "$ZONE_RESP" | jq -r '.result.name')"
[ "$ACTUAL_ZONE" = "$ZONE_NAME" ] \
    || die "zone id ${ZONE_ID} is '${ACTUAL_ZONE}', not '${ZONE_NAME}'. Refusing."
ok "zone ${ACTUAL_ZONE} (${ZONE_ID})"

say "Records"
info "target IP: ${IP}"
[ "$DRY_RUN" -eq 1 ] && info "mode: DRY RUN — no POST or PUT will be sent"

for short in $RECORD_NAMES; do
    FQDN="${short}.${ZONE_NAME}"

    # The guard. Belt and braces with RECORD_NAMES above: if someone edits that
    # list, this is what still stops a write to a production record.
    case "$FQDN" in
        *"$REQUIRED_SUFFIX") ;;
        *) die "refusing to touch '${FQDN}' — it does not end in '${REQUIRED_SUFFIX}'." ;;
    esac

    LOOKUP="$(cf GET "/zones/${ZONE_ID}/dns_records?type=A&name=${FQDN}")"
    cf_ok "$LOOKUP" || die "lookup of ${FQDN} failed: $(printf '%s' "$LOOKUP" | jq -c '.errors // .')"

    RECORD_ID="$(printf '%s' "$LOOKUP" | jq -r '.result[0].id // empty')"
    CURRENT_IP="$(printf '%s' "$LOOKUP" | jq -r '.result[0].content // empty')"
    CURRENT_NAME="$(printf '%s' "$LOOKUP" | jq -r '.result[0].name // empty')"

    # ttl 1 = "automatic", which is the only value Cloudflare accepts on a
    # proxied record.
    BODY="$(jq -nc --arg name "$FQDN" --arg content "$IP" \
        '{type:"A", name:$name, content:$content, ttl:1, proxied:true,
          comment:"Manara staging (T-040) — managed by deploy/staging/cloudflare-dns.sh"}')"

    if [ -n "$RECORD_ID" ]; then
        # Re-verify the NAME that came back, not just the id. A lookup that
        # somehow matched a different record must never be written through.
        [ "$CURRENT_NAME" = "$FQDN" ] \
            || die "lookup for ${FQDN} returned a record named '${CURRENT_NAME}'. Refusing to update it."
        case "$CURRENT_NAME" in
            *"$REQUIRED_SUFFIX") ;;
            *) die "refusing to update '${CURRENT_NAME}' — not a staging name." ;;
        esac

        if [ "$DRY_RUN" -eq 1 ]; then
            info "[dry-run] PUT /zones/${ZONE_ID}/dns_records/${RECORD_ID}"
            info "[dry-run]   ${BODY}"
            info "[dry-run]   (currently ${CURRENT_IP})"
            continue
        fi
        RESP="$(cf PUT "/zones/${ZONE_ID}/dns_records/${RECORD_ID}" "$BODY")"
        cf_ok "$RESP" || die "update of ${FQDN} failed: $(printf '%s' "$RESP" | jq -c '.errors')"
        ok "updated ${FQDN}: ${CURRENT_IP} -> ${IP}"
    else
        if [ "$DRY_RUN" -eq 1 ]; then
            info "[dry-run] POST /zones/${ZONE_ID}/dns_records"
            info "[dry-run]   ${BODY}"
            continue
        fi
        RESP="$(cf POST "/zones/${ZONE_ID}/dns_records" "$BODY")"
        cf_ok "$RESP" || die "creation of ${FQDN} failed: $(printf '%s' "$RESP" | jq -c '.errors')"
        ok "created ${FQDN} -> ${IP}"
    fi
done

say "Resulting staging records"
for short in $RECORD_NAMES; do
    FQDN="${short}.${ZONE_NAME}"
    RESULT="$(cf GET "/zones/${ZONE_ID}/dns_records?name=${FQDN}")"
    if cf_ok "$RESULT"; then
        printf '%s' "$RESULT" | jq -r '
            if (.result | length) == 0 then "    \("(none)")"
            else .result[] | "    \(.name)  \(.type)  \(.content)  proxied=\(.proxied)  ttl=\(.ttl)"
            end'
    else
        info "    ${FQDN}: could not re-read"
    fi
done

if [ "$DRY_RUN" -eq 1 ]; then
    say "Dry run complete — nothing was created or changed."
else
    say "Done"
    info "Proxied records propagate immediately at the edge."
    info "Verify:  curl -sI https://masjid-staging.${ZONE_NAME}/  | head -1"
fi
