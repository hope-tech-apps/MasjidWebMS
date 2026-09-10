#!/usr/bin/env bash
#
# provision.sh — turn a CLONE OF PRODUCTION into the staging box.
#
# Run as root ON THE STAGING DROPLET, from the app directory's checkout:
#
#     cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem
#     sudo bash deploy/staging/provision.sh
#     sudo bash deploy/staging/provision.sh --again      # re-run after the first
#
# The droplet is created from a snapshot of production (id 244822223), so it
# already has Ubuntu 24.04, PHP 8.3 + php8.3-fpm, composer, nginx, certbot, the
# app directory, masjid-queue.service and the root cron line. None of that is
# installed here. What IS here is everything that makes the clone STOP BEING
# PRODUCTION:
#
#   * a local MySQL, so it cannot reach the managed cluster's schema
#   * an .env rebuilt from deploy/staging/env.staging.example
#   * a deny-list pass that blanks every live credential the clone inherited,
#     including ones the example does not mention
#   * hostnames of its own, and prod's hostnames removed from nginx and certbot
#   * every inherited private upload, log and backup deleted
#
# It deliberately does NOT:
#   * run migrations         — the database is empty; DATA-REFRESH.md loads it,
#                              and migrating first would create a schema that the
#                              subsequent import then has to fight
#   * run `staging:scrub`    — there is nothing to scrub yet, and running it on
#                              an empty database would report a clean result,
#                              which is the most dangerous possible false signal
#   * touch Stripe           — test keys come from a human (RUNBOOK step 4)
#
# Idempotent: every step checks before it acts, and a second run with --again is
# safe. Without --again it refuses to run on a box that is already staging, so a
# stray re-run cannot wipe a loaded staging database's credentials.
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem}"
WEB_USER="${WEB_USER:-www-data}"
QUEUE_SERVICE="${QUEUE_SERVICE:-masjid-queue.service}"
FPM_SERVICE="${FPM_SERVICE:-php8.3-fpm}"

STAGING_HOSTNAME="masjid-staging"
STAGING_APP_HOST="masjid-staging.hopetechapps.com"
STAGING_ALT_HOST="manara-staging.hopetechapps.com"
STAGING_PORTAL_HOST="portal-staging.hopetechapps.com"

DB_NAME="masjids_staging"
DB_USER="manara_staging"

ENV_BACKUP_DIR="/root/env-backups"
STAGING_CERT_DIR="/etc/ssl/manara-staging"

PROD_VHOST="/etc/nginx/sites-available/masjid.hopetechapps.com"
PORTAL_VHOST="/etc/nginx/sites-available/portal.alrazischool.org"

AGAIN=0

BOLD=$'\033[1m'; RED=$'\033[1;31m'; YELLOW=$'\033[1;33m'; GREEN=$'\033[1;32m'; RESET=$'\033[0m'
say()  { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
info() { printf '    %s\n' "$1"; }
ok()   { printf '%s    ok %s%s\n' "$GREEN" "$1" "$RESET"; }
warn() { printf '%s    !! %s%s\n' "$YELLOW" "$1" "$RESET"; }
die()  { printf '\n%sprovision: %s%s\n\n' "$RED" "$1" "$RESET" >&2; exit 1; }

while [ "$#" -gt 0 ]; do
    case "$1" in
        --again) AGAIN=1; shift ;;
        -h|--help)
            sed -n '2,40p' "$0"
            exit 0
            ;;
        *) die "unknown argument: $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run as root (sudo bash deploy/staging/provision.sh)."

# ===========================================================================
# 0. GUARDS — refuse to run anywhere that is not a fresh clone
# ===========================================================================
# This script rewrites .env, deletes uploads and reconfigures nginx. Every one of
# those is catastrophic on production. The 2026-09-10 discovery that a droplet
# "serving no traffic" was still running prod's queue against prod's database is
# exactly why the checks below look at hostname AND every address the box holds,
# rather than trusting whoever typed the ssh command.
say "Guards"

CURRENT_HOSTNAME="$(hostname)"
info "hostname: ${CURRENT_HOSTNAME}"

# Named hosts that must never be provisioned:
#   masjid-backend-24-04   = PRODUCTION (droplet 586894889)
#   masjid-backend-service = the stale droplet 480119186, which still holds
#                            production DB credentials and live Resend/Anthropic/
#                            OneSignal keys and is scheduled for destruction
case "$CURRENT_HOSTNAME" in
    masjid-backend-24-04)
        die "this is PRODUCTION (hostname masjid-backend-24-04). Refusing." ;;
    masjid-backend-service)
        die "this is the STALE droplet 480119186 (hostname masjid-backend-service).
     It shares production's database. Refusing — destroy it instead
     (deploy/staging/RUNBOOK.md step 7)." ;;
esac

# Collect every address this box answers on: the interfaces, plus DigitalOcean's
# link-local metadata (which is where a RESERVED/floating IP shows up — it is not
# configured on any interface, so `ip addr` alone would miss it).
BOX_IPS="$(ip -4 -o addr show 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | tr '\n' ' ')"
META_URL="http://169.254.169.254/metadata/v1"
for meta_path in \
    "interfaces/public/0/ipv4/address" \
    "interfaces/public/0/anchor_ipv4/address" \
    "interfaces/private/0/ipv4/address" \
    "floating_ip/ipv4/ip_address"
do
    meta_value="$(curl -s --max-time 3 "${META_URL}/${meta_path}" 2>/dev/null || true)"
    case "$meta_value" in
        *[0-9].[0-9]*) BOX_IPS="${BOX_IPS} ${meta_value}" ;;
    esac
done
info "addresses: ${BOX_IPS}"

# 159.65.239.51  production droplet's public IP
# 164.90.253.138 production's RESERVED IP — the one masjid.hopetechapps.com resolves to
# 10.116.0.4     production's private (VPC) IP
# 147.182.210.42 the stale droplet
for forbidden in 159.65.239.51 164.90.253.138 10.116.0.4 147.182.210.42; do
    case " ${BOX_IPS} " in
        *" ${forbidden} "*)
            die "this box holds ${forbidden}, which belongs to production or to the
     stale droplet. Refusing to provision it as staging." ;;
    esac
done
ok "not production, not the stale droplet"

cd "$APP_DIR" || die "no app directory at ${APP_DIR} — is this really the prod clone?"
[ -f .env ] || die "no ${APP_DIR}/.env — nothing to convert."

read_env_value() {
    # Flat KEY=VALUE read; first assignment wins; quotes and trailing comments stripped.
    sed -n "s/^[[:space:]]*$1[[:space:]]*=//p" "${2:-$APP_DIR/.env}" 2>/dev/null \
        | head -n 1 \
        | sed -e 's/[[:space:]]*#.*$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
              -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

CURRENT_APP_ENV="$(read_env_value APP_ENV)"
info "current APP_ENV: ${CURRENT_APP_ENV:-<unset>}"

# WHY this guard: after the first successful run the .env holds a generated
# database password and, later, hand-pasted Stripe test keys. A second
# unqualified run would regenerate/blank both and quietly disconnect the box from
# its own database. --again is the way to say you meant it.
if [ "$CURRENT_APP_ENV" = "staging" ] && [ "$AGAIN" -eq 0 ]; then
    die "this box is already APP_ENV=staging.
     Re-running would rewrite .env (regenerating the DB password and blanking the
     Stripe test keys someone pasted). Pass --again if that is what you want."
fi

# ===========================================================================
# 1. Hostname
# ===========================================================================
say "Hostname"
if [ "$CURRENT_HOSTNAME" = "$STAGING_HOSTNAME" ]; then
    ok "already ${STAGING_HOSTNAME}"
else
    hostnamectl set-hostname "$STAGING_HOSTNAME"
    # /etc/hosts still carries the clone's old name; leaving it makes sudo slow
    # and makes `hostname -f` disagree with `hostname`.
    if grep -q "$CURRENT_HOSTNAME" /etc/hosts 2>/dev/null; then
        sed -i "s/\b${CURRENT_HOSTNAME}\b/${STAGING_HOSTNAME}/g" /etc/hosts
    fi
    grep -q "127.0.1.1" /etc/hosts 2>/dev/null \
        || echo "127.0.1.1 ${STAGING_HOSTNAME}" >> /etc/hosts
    ok "hostname is now ${STAGING_HOSTNAME}"
fi

# ===========================================================================
# 2. Local MySQL
# ===========================================================================
# WHY local and not a second schema on the managed cluster: a DO MySQL user can
# see every schema on the cluster, so one wrong DB_DATABASE line would put
# staging inside production's data with no error anywhere. A local server on
# 127.0.0.1 makes that physically impossible, and it is also the only way to run
# `migrate:fresh` or the never-executed concurrency test.
say "MySQL (local)"

if command -v mysqld >/dev/null 2>&1; then
    ok "mysql-server already installed ($(mysqld --version 2>/dev/null | head -1))"
else
    info "installing mysql-server from the Ubuntu 24.04 archive (MySQL 8.0)..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq mysql-server
    ok "mysql-server installed"
fi

systemctl enable --now mysql >/dev/null 2>&1 || systemctl enable --now mysql.service
systemctl is-active --quiet mysql || die "mysql did not start — check journalctl -u mysql."

# Ubuntu ships bind-address = 127.0.0.1 by default. Assert it rather than assume:
# a server listening on 0.0.0.0 with a generated password is a public database.
if ss -ltn 2>/dev/null | grep -qE '(0\.0\.0\.0|\*):3306'; then
    die "mysql is listening on all interfaces. Set bind-address=127.0.0.1 in
     /etc/mysql/mysql.conf.d/mysqld.cnf and restart before continuing."
fi
ok "mysql listening on loopback only"

# Reuse an existing password when re-running against a database that already has
# data in it; generate one otherwise. Alphanumeric only, so it needs no quoting
# in .env and no escaping in SQL.
EXISTING_DB_HOST="$(read_env_value DB_HOST)"
EXISTING_DB_PASS="$(read_env_value DB_PASSWORD)"
DB_PASS=""
if [ "$AGAIN" -eq 1 ] && [ "$EXISTING_DB_HOST" = "127.0.0.1" ] && [ -n "$EXISTING_DB_PASS" ]; then
    DB_PASS="$EXISTING_DB_PASS"
    info "reusing the existing local database password (--again)"
else
    DB_PASS="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32)"
    [ "${#DB_PASS}" -eq 32 ] || die "could not generate a 32-character password."
fi

# root authenticates over the unix socket on Ubuntu's MySQL, so no root password
# is needed or stored anywhere.
mysql --protocol=socket -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "database ${DB_NAME} and user ${DB_USER} ready"

# Prove the credentials the app is about to be given actually work, here, rather
# than discovering it as a 500 after the first HTTP request.
mysql --protocol=TCP -h 127.0.0.1 -P 3306 -u "$DB_USER" -p"$DB_PASS" -e "USE \`${DB_NAME}\`; SELECT 1;" >/dev/null \
    || die "the generated credentials cannot connect over TCP to ${DB_NAME}."
ok "credentials verified over TCP"

# ===========================================================================
# 3. .env — back up, merge, then deny-list
# ===========================================================================
say ".env"

mkdir -p "$ENV_BACKUP_DIR"
chmod 700 "$ENV_BACKUP_DIR"
ENV_BACKUP="${ENV_BACKUP_DIR}/env.$(date +%Y%m%d-%H%M%S)"
cp -p .env "$ENV_BACKUP"
chmod 600 "$ENV_BACKUP"
ok "old .env backed up to ${ENV_BACKUP} (0600)"

EXAMPLE="${APP_DIR}/deploy/staging/env.staging.example"
[ -f "$EXAMPLE" ] || die "missing ${EXAMPLE} — is this checkout complete?"

WORK="$(mktemp)"
chmod 600 "$WORK"
trap 'rm -f "$WORK"' EXIT

# Start from EVERY key the clone already has. Staging is meant to be
# production-shaped, so APP_KEY, the locale block, BCRYPT_ROUNDS and the
# FLYER_CUTOUT_* paths are inherited deliberately.
cp .env "$WORK"

set_env_key() {
    # Replace the first `KEY=` line in place, or append if the key is absent.
    # Values travel through the environment rather than through -v so that
    # backslashes, slashes and ampersands in URLs and passwords survive intact —
    # a `sed s///` here would mangle every one of them.
    local file="$1"
    SETK="$2" SETV="$3" awk '
        BEGIN { k = ENVIRON["SETK"]; v = ENVIRON["SETV"]; done = 0 }
        {
            if (index($0, k "=") == 1) {
                if (!done) { print k "=" v; done = 1 }
                next
            }
            print
        }
        END { if (!done) print k "=" v }
    ' "$file" > "${file}.new" && mv "${file}.new" "$file"
    chmod 600 "$file"
}

NEEDS_PASTE=""
OVERRIDDEN=0
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in ''|'#'*) continue ;; esac
    case "$line" in *=*) ;; *) continue ;; esac
    key="${line%%=*}"
    value="${line#*=}"
    case "$key" in
        [A-Z_][A-Z0-9_]*) ;;
        *) continue ;;
    esac
    # `<GENERATED>` and `<PASTE …>` are placeholders, never literal values —
    # writing them through would put angle brackets into a config file. Both
    # become empty here; only the <PASTE …> ones are reported at the end, because
    # <GENERATED> is filled in by this script a few lines below.
    case "$value" in
        '<GENERATED>')
            value=""
            ;;
        \<*)
            NEEDS_PASTE="${NEEDS_PASTE} ${key}"
            value=""
            ;;
    esac
    set_env_key "$WORK" "$key" "$value"
    OVERRIDDEN=$((OVERRIDDEN + 1))
done < "$EXAMPLE"
ok "${OVERRIDDEN} keys overridden from env.staging.example"

# The generated database password, which the example can only mark <GENERATED>.
set_env_key "$WORK" DB_PASSWORD "$DB_PASS"

# -----------------------------------------------------------------------
# DENY-LIST — the important safety property of this whole script
# -----------------------------------------------------------------------
# Everything above is a MATCHING exercise: the example lists the keys we thought
# of, and each one gets overridden. This step is the opposite, and it is the one
# that actually protects the outside world: it blanks every key whose NAME looks
# like a credential, whether or not the example mentions it.
#
# It exists because the failure it prevents is silent and total. The clone
# carries production's live Stripe secret (sk_live_, a real connected account
# taking real donations), a Resend key that is already known to have leaked, a
# live Anthropic key, and OneSignal keys that push to every congregant's phone.
# If a future edit to env.staging.example drops one line — or production adds a
# new integration nobody updates the example for — the merge above leaves that
# live credential in place on a box explicitly built for people to experiment on,
# and nothing anywhere reports it.
#
# So the rule is by PREFIX, not by enumeration: anything named like a secret is
# blanked, and a new integration is safe by default rather than safe if
# remembered. The listed prefixes are the ones from the T-040 decision; the
# second group is credentials found in production's own .env on 2026-09-10 that
# the listed prefixes do not reach.
DENY_PREFIXES="STRIPE_ RESEND_ ANTHROPIC_ ONESIGNAL_ PUSHER_ GITHUB_"
# AWS_*        — prod carries S3 credentials even though no media uses the s3 disk.
# VITE_PUSHER_ — the same Pusher app, under a prefix `^PUSHER_` does not match.
# MAIL_PASSWORD/MAIL_USERNAME — the transport credential; inert while MAIL_MAILER
#                is `log`, but a single edited line would make it live again.
DENY_PREFIXES="${DENY_PREFIXES} AWS_ VITE_PUSHER_"
DENY_EXACT="MAIL_PASSWORD MAIL_USERNAME"

DENIED=0
blank_key() {
    set_env_key "$WORK" "$1" ""
    DENIED=$((DENIED + 1))
    info "blanked ${1}"
}

# Iterate over the keys actually present in the merged file, so this reports what
# it really did rather than what it hoped to do.
while IFS= read -r key; do
    for prefix in $DENY_PREFIXES; do
        case "$key" in
            "${prefix}"*)
                # Only touch it if it still has a value — a key already blank
                # should not be announced as a scrub that happened.
                if [ -n "$(read_env_value "$key" "$WORK")" ]; then
                    blank_key "$key"
                fi
                continue 2
                ;;
        esac
    done
    for exact in $DENY_EXACT; do
        if [ "$key" = "$exact" ] && [ -n "$(read_env_value "$key" "$WORK")" ]; then
            blank_key "$key"
        fi
    done
done <<< "$(grep -oE '^[A-Z_][A-Z0-9_]*=' "$WORK" | tr -d '=' | sort -u)"

if [ "$DENIED" -eq 0 ]; then
    ok "deny-list found nothing left to blank"
else
    ok "deny-list blanked ${DENIED} credential key(s)"
fi

# Final assertion: no production secret survived. Checked by VALUE PREFIX, which
# catches a live key that arrived under an unexpected name.
if grep -qE '=(sk_live_|pk_live_|rk_live_|re_[A-Za-z0-9]|sk-ant-|os_v2_)' "$WORK"; then
    die "a live-looking credential is still present in the new .env. Refusing to
     install it. Inspect ${WORK} is gone; re-run and read the deny-list above."
fi
ok "no live-looking credential values remain"

install -o root -g "$WEB_USER" -m 640 "$WORK" "${APP_DIR}/.env"
ok ".env installed (root:${WEB_USER} 0640)"

# Inherited .env backups from PRODUCTION are full copies of prod's live secrets
# sitting on a box people are invited to break. Delete them all, keeping only the
# backup this run just made.
say "Inherited .env backups"
FOUND_BAK=0
for bak in .env.bak* .env.backup* .env.pre-* .env.production; do
    [ -e "$bak" ] || continue
    FOUND_BAK=1
    info "removing ${bak} ($(stat -c %s "$bak" 2>/dev/null || echo '?') bytes) — it is a copy of PRODUCTION's secrets"
    shred -u "$bak" 2>/dev/null || rm -f "$bak"
done
if [ "$FOUND_BAK" -eq 1 ]; then
    ok "inherited .env backups removed"
else
    ok "none present"
fi
info "kept: ${ENV_BACKUP} (this run's backup, 0600, root-only)"

# ===========================================================================
# 4. nginx + TLS
# ===========================================================================
say "nginx"

[ -f "$PROD_VHOST" ] || die "expected ${PROD_VHOST} on the clone; not found."

# Copy the inherited certificate material OUT of /etc/letsencrypt BEFORE certbot
# is told to forget these names. WHY: the staging hostnames are PROXIED Cloudflare
# records under SSL mode `full` (non-strict), so Cloudflare encrypts to the origin
# but never checks the name on the origin's certificate — the inherited
# prod-named cert is a perfectly good server-side cert here, and it is the only
# way to keep :443 up without issuing anything. `certbot delete` would take the
# files with it, so they are copied first and the vhosts repointed.
mkdir -p "$STAGING_CERT_DIR"
chmod 700 "$STAGING_CERT_DIR"
if [ ! -f "${STAGING_CERT_DIR}/fullchain.pem" ]; then
    SRC="/etc/letsencrypt/live/masjid.hopetechapps.com"
    [ -f "${SRC}/fullchain.pem" ] || die "no inherited certificate at ${SRC} — :443 would go down. Stopping."
    cp -L "${SRC}/fullchain.pem" "${STAGING_CERT_DIR}/fullchain.pem"
    cp -L "${SRC}/privkey.pem"   "${STAGING_CERT_DIR}/privkey.pem"
    chmod 644 "${STAGING_CERT_DIR}/fullchain.pem"
    chmod 600 "${STAGING_CERT_DIR}/privkey.pem"
    ok "certificate material copied to ${STAGING_CERT_DIR}"
else
    ok "${STAGING_CERT_DIR} already populated"
fi

# The app vhost: staging names, same default_server, staging cert paths.
# default_server is KEPT (and must remain on exactly one vhost per port) —
# production relies on it to answer any Host out of one document root, and
# staging is supposed to behave the same way.
if grep -q "server_name masjid.hopetechapps.com;" "$PROD_VHOST"; then
    cp -p "$PROD_VHOST" "${PROD_VHOST}.prod-original"
    sed -i \
        -e "s|server_name masjid\.hopetechapps\.com;|server_name ${STAGING_APP_HOST} ${STAGING_ALT_HOST};|g" \
        -e "s|/etc/letsencrypt/live/masjid\.hopetechapps\.com/fullchain\.pem|${STAGING_CERT_DIR}/fullchain.pem|g" \
        -e "s|/etc/letsencrypt/live/masjid\.hopetechapps\.com/privkey\.pem|${STAGING_CERT_DIR}/privkey.pem|g" \
        "$PROD_VHOST"
    ok "app vhost now serves ${STAGING_APP_HOST} ${STAGING_ALT_HOST}"
else
    ok "app vhost already repointed"
fi

# The portal vhost served portal.alrazischool.org — a REAL school's front door.
# Leaving that server_name on a box holding scrubbed data and test Stripe keys
# means a stray DNS change or a spoofed Host header puts real parents on staging.
# Repointed rather than deleted, because PORTAL_HOSTS maps
# portal-staging.hopetechapps.com=14 and that name needs a vhost for SNI.
if [ -f "$PORTAL_VHOST" ]; then
    if grep -q "server_name portal.alrazischool.org;" "$PORTAL_VHOST"; then
        cp -p "$PORTAL_VHOST" "${PORTAL_VHOST}.prod-original"
        sed -i \
            -e "s|server_name portal\.alrazischool\.org;|server_name ${STAGING_PORTAL_HOST};|g" \
            -e "s|/etc/letsencrypt/live/portal\.alrazischool\.org/fullchain\.pem|${STAGING_CERT_DIR}/fullchain.pem|g" \
            -e "s|/etc/letsencrypt/live/portal\.alrazischool\.org/privkey\.pem|${STAGING_CERT_DIR}/privkey.pem|g" \
            "$PORTAL_VHOST"
        ok "portal vhost now serves ${STAGING_PORTAL_HOST}"
    else
        ok "portal vhost already repointed"
    fi
else
    warn "no portal vhost on this clone — skipping (PORTAL_HOSTS will simply not resolve a cert)"
fi

# Nothing may still claim a production hostname.
if grep -rlE 'server_name[^;]*(masjid\.hopetechapps\.com|portal\.alrazischool\.org)[^;]*;' /etc/nginx/sites-enabled/ 2>/dev/null | grep -q .; then
    die "a vhost in sites-enabled still names a PRODUCTION host. Fix it before reloading."
fi
ok "no production hostname remains in sites-enabled"

# robots.txt must reach PHP on staging. public/robots.txt is checked in and
# allow-all; prod's vhost serves it as a static file (try_files $uri wins), which
# is right for production and dead-on-arrival for the environment-aware route in
# routes/web.php that disallows everything off production. Rewrite the location
# in every vhost so the request falls through to index.php. Idempotent: a vhost
# that already has the try_files form is left alone.
ROBOTS_FIXED=0
for vhost in "$PROD_VHOST" "$PORTAL_VHOST"; do
    [ -f "$vhost" ] || continue
    if grep -qE 'location = /robots\.txt[[:space:]]*\{[^}]*try_files /dev/null /index\.php' "$vhost"; then
        continue
    fi
    if grep -qE 'location = /robots\.txt[[:space:]]*\{' "$vhost"; then
        sed -i -E 's|location = /robots\.txt[[:space:]]*\{[^}]*\}|location = /robots.txt  { try_files /dev/null /index.php$is_args$args; }|' "$vhost"
    else
        # No dedicated location at all: add one just before the PHP handler so
        # it wins over the generic `location /`.
        sed -i -E 's|^([[:space:]]*)location ~ \\\.php\$ \{|\1location = /robots.txt  { try_files /dev/null /index.php$is_args$args; }\n\1location ~ \\.php$ {|' "$vhost"
    fi
    grep -qE 'location = /robots\.txt[[:space:]]*\{[^}]*try_files /dev/null /index\.php' "$vhost" \
        || die "could not route /robots.txt to PHP in ${vhost}"
    ROBOTS_FIXED=$((ROBOTS_FIXED + 1))
done
ok "robots.txt routed to PHP in ${ROBOTS_FIXED} vhost(s)"

say "certbot"
# Delete the inherited renewal configuration for the PRODUCTION certificate names.
# WHY: certbot.timer runs twice a day on this clone and would try to renew
# masjid.hopetechapps.com and portal.alrazischool.org from a box that does not
# own them — repeatedly failing, emailing, and competing for the real hosts'
# ACME rate limits and authorisations. The certificate FILES the vhosts use were
# already copied to ${STAGING_CERT_DIR} above, so :443 is unaffected.
if command -v certbot >/dev/null 2>&1; then
    for cert_name in masjid.hopetechapps.com portal.alrazischool.org; do
        if certbot certificates 2>/dev/null | grep -q "Certificate Name: ${cert_name}"; then
            if certbot delete --cert-name "$cert_name" --non-interactive >/dev/null 2>&1; then
                ok "certbot forgot ${cert_name}"
            else
                warn "certbot could not delete ${cert_name} — check manually"
            fi
        else
            ok "certbot has no ${cert_name}"
        fi
    done
    # Belt as well as braces: with no certificates left there is nothing to renew,
    # but a disabled timer means a future certbot install cannot resurrect one.
    if systemctl disable --now certbot.timer >/dev/null 2>&1; then
        ok "certbot.timer disabled"
    fi
else
    ok "certbot not installed"
fi

nginx -t || die "nginx -t failed — NOT reloading. The site is still up on the old config."
systemctl reload nginx
ok "nginx reloaded"

# ===========================================================================
# 5. Inherited data that must not exist on staging
# ===========================================================================
# These are the PRIVATE disk: résumés and admissions documents, classroom feed
# photographs OF CHILDREN, teacher resources, janazah photos, staff certification
# documents. None of it belongs on a test box, none of it is needed to exercise
# any code path, and the scrub command cannot help — it works on the database.
say "Inherited private uploads"
REMOVED_ANY=0
for private_path in \
    "storage/app/private" \
    "storage/app/form-attachments" \
    "storage/app/group-media"
do
    if [ -e "$private_path" ]; then
        REMOVED_ANY=1
        info "found ${private_path}:"
        find "$private_path" -maxdepth 2 2>/dev/null | head -20 | sed 's/^/        /'
        info "  size: $(du -sh "$private_path" 2>/dev/null | cut -f1)"
        rm -rf "$private_path"
        # Recreate the private root itself: config/filesystems.php points the
        # `local` disk at storage/app/private, and a missing directory turns the
        # first upload into an exception instead of a file.
        if [ "$private_path" = "storage/app/private" ]; then
            mkdir -p "$private_path"
            chown "$WEB_USER":"$WEB_USER" "$private_path"
            chmod 750 "$private_path"
        fi
        ok "removed ${private_path}"
    else
        info "not present: ${private_path}"
    fi
done
[ "$REMOVED_ANY" -eq 1 ] || ok "nothing inherited on the private disk"

say "Inherited backups"
if [ -d /var/backups/manara ]; then
    info "found /var/backups/manara ($(du -sh /var/backups/manara 2>/dev/null | cut -f1)) — these are RESTORABLE COPIES of production"
    rm -rf /var/backups/manara
    ok "removed /var/backups/manara"
else
    ok "no /var/backups/manara"
fi
# The staging BACKUP_DESTINATION from env.staging.example, so a backup:run here
# can never prune or overwrite a production set.
mkdir -p /var/backups/manara-staging
chown "$WEB_USER":"$WEB_USER" /var/backups/manara-staging
ok "/var/backups/manara-staging ready"

say "Logs and caches"
# Prod's laravel.log is a record of real people's activity, and on the stale
# droplet the same file had grown to 334 MB. Start empty; LOG_LEVEL=debug will
# fill it quickly enough.
if compgen -G "storage/logs/*.log" >/dev/null; then
    info "clearing $(find storage/logs -maxdepth 1 -name '*.log' | wc -l | tr -d ' ') log file(s), $(du -sh storage/logs 2>/dev/null | cut -f1)"
    rm -f storage/logs/*.log
fi
touch storage/logs/laravel.log
chown "$WEB_USER":"$WEB_USER" storage/logs/laravel.log
ok "storage/logs cleared"

# bootstrap/cache carries the clone's COMPILED config — including production's
# database host and live Stripe secret, baked into config.php. It outranks .env
# at runtime, so leaving it means the new .env is ignored and staging talks to
# the production cluster while every file on disk says otherwise. This is the
# same shape as the stale-bootstrap-cache incident that produced 528 phantom
# 403s in CI.
if compgen -G "bootstrap/cache/*.php" >/dev/null; then
    info "removing $(find bootstrap/cache -maxdepth 1 -name '*.php' | wc -l | tr -d ' ') compiled cache file(s) — they still hold PRODUCTION's config"
    rm -f bootstrap/cache/*.php
fi
chown -R "$WEB_USER":"$WEB_USER" bootstrap/cache
ok "bootstrap/cache emptied"

# ===========================================================================
# 6. Cron and services
# ===========================================================================
say "Scheduler cron"
# Production's root crontab runs schedule:run AS www-data. Running it as root is
# what produced the 2026-08 "banner" bug: root-owned cache files that php-fpm
# (www-data) then could not write. The stale droplet still runs it as root —
# do not copy that.
CRON_LINE="* * * * * cd ${APP_DIR} && sudo -u ${WEB_USER} HOME=/tmp php artisan schedule:run >> /dev/null 2>&1"
if crontab -l 2>/dev/null | grep -q "schedule:run"; then
    if crontab -l 2>/dev/null | grep "schedule:run" | grep -q "sudo -u ${WEB_USER}"; then
        ok "root cron already runs schedule:run as ${WEB_USER}"
    else
        warn "root cron runs schedule:run but NOT as ${WEB_USER} — rewriting it"
        ( crontab -l 2>/dev/null | grep -v "schedule:run"; echo "$CRON_LINE" ) | crontab -
        ok "cron line rewritten to run as ${WEB_USER}"
    fi
else
    ( crontab -l 2>/dev/null; echo "$CRON_LINE" ) | crontab -
    ok "cron line installed"
fi

say "Services"
systemctl restart "$FPM_SERVICE"
systemctl is-active --quiet "$FPM_SERVICE" || die "${FPM_SERVICE} did not come back."
ok "${FPM_SERVICE} restarted"

systemctl restart "$QUEUE_SERVICE"
sleep 2
systemctl is-active --quiet "$QUEUE_SERVICE" || die "${QUEUE_SERVICE} did not come back."
ok "${QUEUE_SERVICE} restarted"

# ===========================================================================
# 7. Record the box's address for scripts/ship.sh
# ===========================================================================
say "Host record"
PUBLIC_IP="$(curl -s --max-time 3 "${META_URL}/interfaces/public/0/ipv4/address" 2>/dev/null || true)"
if [ -z "$PUBLIC_IP" ]; then
    PUBLIC_IP="$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1)"
fi
if [ -n "$PUBLIC_IP" ]; then
    printf '%s\n' "$PUBLIC_IP" > "${APP_DIR}/deploy/staging/host"
    ok "public IP ${PUBLIC_IP} written to deploy/staging/host"
else
    warn "could not determine the public IP — set STAGING_IP by hand on the Mac"
fi

# ===========================================================================
# 8. Report
# ===========================================================================
say "Provisioned"
cat <<REPORT

    hostname            ${STAGING_HOSTNAME}
    public IP           ${PUBLIC_IP:-<unknown>}
    app                 ${APP_DIR}
    APP_ENV             staging
    APP_URL             https://${STAGING_APP_HOST}
    nginx serves        ${STAGING_APP_HOST}, ${STAGING_ALT_HOST}, ${STAGING_PORTAL_HOST}
    database            mysql://${DB_USER}@127.0.0.1:3306/${DB_NAME}  (EMPTY — not migrated)
    .env backup         ${ENV_BACKUP}

    ============================================================
    DATABASE PASSWORD (printed ONCE — it is already in .env):

        ${DB_PASS}

    ============================================================
REPORT

if [ -n "$NEEDS_PASTE" ]; then
    printf '%s' "$RED"
    cat <<PASTE

    !! THESE KEYS ARE BLANK AND MUST BE PASTED BY HAND:
    !!    ${NEEDS_PASTE}
    !!
    !! Stripe TEST-mode keys only (sk_test_ / pk_test_). A live key here would
    !! take real money from a box built for experiments. See RUNBOOK.md step 4.
PASTE
    printf '%s' "$RESET"
fi

cat <<'NEXT_STEPS_EOF'

    NEXT STEPS (deploy/staging/RUNBOOK.md has the detail)

      2. DNS       — from the Mac:  deploy/staging/cloudflare-dns.sh <this box's IP>
                     creates proxied A records for masjid-staging, manara-staging
                     and portal-staging in hopetechapps.com.

      3. DATA      — deploy/staging/DATA-REFRESH.md: load a scrubbed copy of
                     production. That step runs the migrations; this script
                     deliberately did not.

      4. STRIPE    — paste TEST-mode keys into .env, then create a TEST-mode
                     webhook at
                       https://masjid-staging.hopetechapps.com/api/stripe/webhook

      5. SHIP      — from the Mac:  scripts/ship.sh staging <branch>
                     (copy deploy/staging/host down, or export STAGING_IP)

    NOT DONE HERE, ON PURPOSE: migrations, staging:scrub, Stripe.

NEXT_STEPS_EOF
