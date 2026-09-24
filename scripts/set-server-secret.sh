#!/usr/bin/env bash
#
# set-server-secret.sh — put ONE secret into production's .env, safely.
#
#     scripts/set-server-secret.sh CLOUDFLARE_STUDIO_TOKEN
#
# Run it in your own terminal. It asks for the value with input hidden, so the
# secret never appears in a chat, on a command line (argv is visible in `ps` on
# both machines), or in shell history. The value travels to the server on ssh's
# stdin and is written there.
#
# WHY A SCRIPT AND NOT A TEXT EDITOR. Editing this .env by hand has taken
# production down twice (see .claude/rules and NOTES.md):
#   - a root `mv` of an edited copy replaced the file's inode with a root-owned
#     one, www-data could no longer read it, and the next config:cache failed with
#     an error that blamed APP_KEY;
#   - a malformed line, frozen in by config:cache, made every request a 500.
# So this writes THROUGH the existing inode (owner and mode survive), backs the
# file up and reads the backup back before touching it, proves www-data can still
# parse the whole file afterwards, and restores the backup byte for byte if not.
#
# It deliberately does NOT run config:cache. Nothing that reads a brand-new key is
# live yet, the next deploy caches config anyway, and config:cache on a live box is
# the exact operation that caused the second outage. Code must read the value
# through config(), never env(): env() returns null once config is cached.
#
# Overrides, for testing against a copy rather than the real file:
#   MANARA_PROD_SSH   ssh target            (default 159.65.239.51)
#   MANARA_HOSTNAME   expected hostname     (default masjid-backend-24-04)
#   MANARA_ENV_FILE   .env path on the box  (default the production app's .env)

set -euo pipefail

KEY="${1:-}"
if [[ ! "$KEY" =~ ^[A-Z][A-Z0-9_]*$ ]]; then
  echo "usage: $0 KEY_NAME        (UPPER_SNAKE_CASE, e.g. CLOUDFLARE_STUDIO_TOKEN)" >&2
  exit 2
fi

HOST="${MANARA_PROD_SSH:-159.65.239.51}"
EXPECT="${MANARA_HOSTNAME:-masjid-backend-24-04}"
ENV_FILE="${MANARA_ENV_FILE:-/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem/.env}"

if [ -t 0 ]; then
  read -rsp "Paste the value for $KEY (input is hidden), then press Return: " VALUE
  echo
else
  IFS= read -r VALUE          # piped, for testing only
fi

if [ -z "${VALUE:-}" ]; then
  echo "Nothing entered. Nothing was changed." >&2
  exit 1
fi
# The set every token this is meant for uses. Anything with whitespace, quotes, '#',
# '$' or a backslash needs .env quoting rules, and getting those wrong is how a
# single paste becomes a site-wide 500. Refuse rather than guess.
if [[ ! "$VALUE" =~ ^[A-Za-z0-9._:/+=-]+$ ]]; then
  echo "REFUSED: the value has characters that need quoting in .env (spaces, quotes, #, \$ or \\)." >&2
  echo "Nothing was changed. If the value is right, this one needs a human edit." >&2
  exit 1
fi

REMOTE=$(cat <<'REMOTE'
set -euo pipefail
IFS= read -r VALUE

if [ "$(hostname)" != "$EXPECT" ]; then
  echo "REFUSED: this is $(hostname), not $EXPECT. Nothing was changed."; exit 1
fi
[ -f "$ENV_FILE" ] || { echo "REFUSED: $ENV_FILE does not exist. Nothing was changed."; exit 1; }

owner_before=$(stat -c '%U:%G %a' "$ENV_FILE")
# Second-resolution stamps collide when two runs land in the same second, and the
# later backup silently replaces the earlier one. The pid keeps every backup.
stamp=$(date -u +%Y%m%dT%H%M%SZ)-$$
bak="$ENV_FILE.bak-$stamp"
cp -p "$ENV_FILE" "$bak"
cmp -s "$ENV_FILE" "$bak" || { echo "REFUSED: the backup did not read back. Nothing was changed."; exit 1; }

# Build the new content, then write it THROUGH the existing inode with `cat >`.
# The value goes to awk through its environment, never its argv.
if grep -q "^${KEY}=" "$ENV_FILE"; then
  action="replaced"
  V="$VALUE" K="$KEY" awk 'index($0, ENVIRON["K"] "=") == 1 { print ENVIRON["K"] "=" ENVIRON["V"]; next } { print }' \
    "$bak" > "$ENV_FILE"
else
  action="added"
  { cat "$bak"
    [ -n "$(tail -c1 "$bak")" ] && printf '\n'
    printf '%s=%s\n' "$KEY" "$VALUE"; } > "$ENV_FILE"
fi

restore() { cat "$bak" > "$ENV_FILE"; echo "ROLLED BACK: $1 The file is exactly as it was."; exit 1; }

[ "$(grep -c "^${KEY}=" "$ENV_FILE")" = "1" ] || restore "the key is not present exactly once."
[ "$(stat -c '%U:%G %a' "$ENV_FILE")" = "$owner_before" ] || restore "the owner or mode changed."

# The whole file must still parse, AS www-data, the user that reads it in production.
# That one check catches both outages: an unreadable file and an unparseable one.
app_dir=$(cd "$(dirname "$ENV_FILE")" && pwd)
code_dir=/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem
parsed=$(cd "$code_dir" && sudo -u www-data php -r '
  require "vendor/autoload.php";
  $v = Dotenv\Dotenv::createArrayBacked($argv[1], basename($argv[2]))->load();
  echo array_key_exists($argv[3], $v) && $v[$argv[3]] !== "" ? "ok" : "missing";
' "$app_dir" "$ENV_FILE" "$KEY" 2>/dev/null || true)
[ "$parsed" = "ok" ] || restore "the file no longer parses as www-data (got: ${parsed:-an error})."

echo "OK: $KEY $action in $ENV_FILE."
echo "    Owner and mode unchanged ($owner_before). Parses as www-data."
echo "    Backup: $bak"

if [ "$KEY" = "CLOUDFLARE_STUDIO_TOKEN" ]; then
  # Checked FROM THE SERVER, so an IP filter on the token is tested for real. The
  # token goes to curl as a header on stdin, never on its command line.
  status=$(printf 'Authorization: Bearer %s\n' "$VALUE" \
    | curl -s -H @- https://api.cloudflare.com/client/v4/user/tokens/verify \
    | python3 -c 'import sys,json; d=json.load(sys.stdin); print((d.get("result") or {}).get("status") or ("error: " + str((d.get("errors") or [{}])[0].get("message"))))' 2>/dev/null || echo "no answer")
  zones=$(printf 'Authorization: Bearer %s\n' "$VALUE" \
    | curl -s -H @- "https://api.cloudflare.com/client/v4/zones?per_page=50" \
    | python3 -c 'import sys,json; d=json.load(sys.stdin); r=d.get("result") or []; print(str(len(r)) + " zones: " + ", ".join(z["name"] for z in r)) if d.get("success") else print("zone list refused: " + str((d.get("errors") or [{}])[0].get("message")))' 2>/dev/null || echo "no answer")
  echo "    Cloudflare says the token is: $status"
  echo "    It can see $zones"
fi
REMOTE
)

printf '%s\n' "$VALUE" | ssh -T "$HOST" \
  "EXPECT=$(printf '%q' "$EXPECT") ENV_FILE=$(printf '%q' "$ENV_FILE") KEY=$(printf '%q' "$KEY") bash -c $(printf '%q' "$REMOTE")"
status=$?
unset VALUE
exit $status
