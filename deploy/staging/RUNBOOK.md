# Staging runbook — bringing `masjid-staging` up

Owner-facing. Follow it top to bottom once; afterwards only steps 3 and 5 recur.

Decision and rationale: `DECISIONS.md`, 2026-09-10 "Staging environment".
Standing rules: `.claude/rules/environments.md`.

| | |
|---|---|
| host | `masjid-staging.hopetechapps.com` (+ `manara-staging`, `portal-staging`) |
| droplet | created in step 0 from **snapshot 244822223** (a live snapshot of production, taken 2026-09-10 04:08, no downtime) |
| database | MySQL 8.0 **on the box**, `masjids_staging` — never the managed cluster |
| app dir | `/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem` |
| ssh | `ssh -i ~/.ssh/do_mcp root@<ip>` (key `claude-mcp`, `MD5:66:ad:6c:84:20:37:a9:eb:9c:94:f1:e3:37:40:b9:06`) |

---

> **Status 2026-09-10 — staging is LIVE.** Steps 0–3 and 5–6 were executed
> (droplet 599239838 · 157.230.212.38; DNS; data refreshed and scrubbed; SPA
> shipped and verified; ribbon/noindex confirmed through Cloudflare). What
> remains is **step 4 (Stripe test keys + test webhook)**, owner-only, and the
> optional **step 7** (destroy the stale droplet 480119186). Every fix the first
> real run forced is already folded into `provision.sh`, `env.staging.example`
> and `DATA-REFRESH.md`; the sequence in `LOG.md` (2026-09-10) records what
> broke and why.

## Step 0 — What is blocked on you

Two things cannot be done for you. Everything else is already written and tested.

### 0a. Create the droplet

`doctl` on this Mac is installed but **unauthenticated**, and the DigitalOcean
MCP server has no create-droplet tool. So this needs either a DO API token in
your shell or four clicks in the console.

**With `doctl`** — authenticate once (`doctl auth init`, paste a token with
write scope), then:

```sh
doctl compute droplet create masjid-staging \
  --image 244822223 \
  --size s-1vcpu-2gb \
  --region nyc1 \
  --vpc-uuid 6d7eef52-b54d-4a7e-9787-0ae1a312281d \
  --ssh-keys 66:ad:6c:84:20:37:a9:eb:9c:94:f1:e3:37:40:b9:06 \
  --tag-name staging \
  --enable-monitoring \
  --wait
```

Then note the public IP it prints:

```sh
doctl compute droplet list masjid-staging --format ID,Name,PublicIPv4,Status
```

**In the console instead:** Create → Droplets → **Snapshots** tab → pick the
2026-09-10 snapshot of `masjid-backend-24-04` → region **NYC1** → size **Basic /
Regular / 2 GB / 1 vCPU** (`s-1vcpu-2gb`) → **VPC Network:** the same VPC as
production (`6d7eef52-b54d-4a7e-9787-0ae1a312281d`) → **Authentication: SSH
keys**, tick `claude-mcp` → Hostname `masjid-staging` → Create.

Two of those fields matter more than they look:

- **The same VPC.** Not because staging should reach the managed database (it
  must not, and provision.sh points it at a local MySQL) but because a droplet
  outside the VPC cannot be moved into one later without rebuilding it.
- **The SSH key.** A snapshot of production carries production's
  `authorized_keys`, so you would probably get in anyway — but the droplet's
  sshd still has `PermitRootLogin` and `PasswordAuthentication yes` inherited
  from prod (a standing open item in `STATE.md`), and adding the key at create
  time is what makes the password path irrelevant.

> The clone boots believing it is production: same `.env`, same nginx server
> names, same certbot renewals, and a **queue worker and cron pointed at the
> production database**. That is the shape of the 2026-09-10 incident where a
> droplet "serving no traffic" was pushing prayer notifications from prod's
> jobs table. **Run step 1 promptly, and do not leave the box up overnight
> un-provisioned.**

### 0b. Get Stripe TEST-mode keys

From the Stripe dashboard with **Test mode ON**:

- `pk_test_…` and `sk_test_…` (Developers → API keys)
- a **test-mode webhook** and its `whsec_…` (step 4 has the endpoint and events)
- a **test-mode Connect webhook** and its own `whsec_…`

Copy each one with Stripe's copy-to-clipboard **icon**. A hand-selected copy of
a live key once produced 66 of 107 characters plus a trailing `$`, passed a
prefix check, and went live (`NOTES.md`, 2026-08-10). Verify locally:

```sh
pbpaste | tr -d '\n' | wc -c
```

---

## Step 1 — Provision the box

```sh
ssh -i ~/.ssh/do_mcp root@<new-ip>
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem
sudo bash deploy/staging/provision.sh
```

It refuses to run if the hostname is `masjid-backend-24-04` (production) or
`masjid-backend-service` (the stale droplet), or if the box holds
`159.65.239.51`, `164.90.253.138`, `10.116.0.4` or `147.182.210.42`. Re-running
later needs `--again`.

What it does: hostname → local MySQL + `masjids_staging` + a generated password →
`.env` rebuilt from `env.staging.example` and then **deny-listed** so no live
credential survives → nginx repointed at the staging names → certbot told to
forget production's certificate names → inherited private uploads, logs, backups
and `bootstrap/cache` deleted → cron and services restarted.

**It deliberately does not migrate and does not run `staging:scrub`.** The
database is empty when it finishes; step 3 fills it.

Two things to capture from its output:

1. **The database password.** Printed once. It is already in `.env`; you only
   need it to run `mysql` by hand.
2. **The public IP**, which it also writes to `deploy/staging/host` on the box.
   Copy that file to the Mac so `scripts/ship.sh` can find it:

   ```sh
   scp -i ~/.ssh/do_mcp root@<ip>:/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem/deploy/staging/host \
       deploy/staging/host
   # or simply:  export STAGING_IP=<ip>
   ```

---

## Step 2 — DNS

From the Mac (uses the token at `~/.cloudflare-token`):

```sh
deploy/staging/cloudflare-dns.sh <ip> --dry-run   # look first
deploy/staging/cloudflare-dns.sh <ip>
```

Creates or updates three **proxied** A records: `masjid-staging`,
`manara-staging`, `portal-staging`. Proxied on purpose — the zone's Universal
SSL already covers `*.hopetechapps.com` one label deep and its SSL mode is
`full` (non-strict), so there is nothing to issue and the origin keeps serving
production's copied certificate. The script refuses to touch any record whose
name does not end in `-staging.hopetechapps.com`.

Check:

```sh
curl -sI https://masjid-staging.hopetechapps.com/ | head -1
```

---

## Step 3 — Load scrubbed data

Follow **[`DATA-REFRESH.md`](./DATA-REFRESH.md)** (written by the W2 slice). That
document owns the export → import → `php artisan migrate --force` →
`php artisan staging:scrub` sequence and the verification that the scrub
actually ran. Do not improvise it; the scrub is what makes the box safe to hand
to anyone.

Repeat step 3 alone whenever staging's data goes stale.

---

## Step 4 — Stripe test keys and webhooks

Paste the keys from step 0b into `.env` on the box:

```sh
ssh -i ~/.ssh/do_mcp root@<ip>
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem
nano .env      # STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET,
               # STRIPE_CONNECT_WEBHOOK_SECRET
sudo -u www-data HOME=/tmp php artisan config:clear
sudo -u www-data HOME=/tmp php artisan config:cache
```

`config:cache` matters: the compiled config outranks `.env` at runtime, so a
paste without it changes nothing and the failure looks like a bad key.

**Never** run `php artisan config:show services.stripe` — it prints the secret.

### The webhook endpoint

```
https://masjid-staging.hopetechapps.com/api/stripe/webhook
```

(`routes/api.php` → `Route::prefix('stripe')->post('webhook', …)`. It is outside
auth and outside throttle; the HMAC signature is the only gate, so the `whsec_`
must be right or every delivery is a 401.)

### The events to subscribe

`StripeWebhookController::handle` matches on exactly these; everything else is
acknowledged and ignored:

| event | what it drives |
|---|---|
| `checkout.session.completed` | donation, meal order **and** registration completion |
| `payment_intent.succeeded` | the same three, for non-Checkout payments |
| `checkout.session.expired` | releases a held registration seat |
| `invoice.payment_succeeded` | recurring donations and registration installments |
| `invoice.payment_failed` | registration dunning |
| `customer.subscription.deleted` | cancels a recurring donation / registration plan |
| `subscription_schedule.completed` | an installment commitment billed out in full |
| `account.updated` | **Connect** — syncs a connected account's status |

`account.updated` arrives on the **Connect** webhook (events *on connected
accounts*), which is why there are two endpoints and two signing secrets. Point
both at the same URL; the controller picks the right secret by trying each in
turn.

With the Stripe CLI instead of the dashboard:

```sh
stripe login                       # test mode
stripe listen --forward-to https://masjid-staging.hopetechapps.com/api/stripe/webhook \
  --events checkout.session.completed,payment_intent.succeeded,checkout.session.expired,invoice.payment_succeeded,invoice.payment_failed,customer.subscription.deleted,subscription_schedule.completed,account.updated
```

---

## Step 5 — Ship code

From the Mac:

```sh
scripts/ship.sh staging main
scripts/ship.sh staging feature/my-branch
```

It builds the SPA with `npm run build` (never `build:prod`), rsyncs
`public/build/` **without `--delete`**, chowns it to `www-data`, runs
`sudo bin/deploy --ref <ref>` on the box, then fetches the sign-in page,
resolves the entry chunk it references, and proves that chunk returns 200 and
has no hostname baked into it.

`bin/deploy` accepts `--ref` on staging because staging's `.env` says
`APP_ENV=staging`; the same script on production refuses it.

---

## Step 6 — Verification checklist

Run this the first time, and after any change to the staging configuration.
Record the result in `LOG.md` — `.claude/rules/environments.md` makes that
record the precondition for shipping the same ref to production.

| # | check | how | expected |
|---|---|---|---|
| 1 | **ribbon** | open `https://masjid-staging.hopetechapps.com/auth/sign-in` | a visible STAGING marker on every page (W1) |
| 2 | **noindex** | `curl -sI https://masjid-staging.hopetechapps.com/ \| grep -i x-robots` | `X-Robots-Tag: noindex` present |
| 3 | | `curl -s https://masjid-staging.hopetechapps.com/robots.txt` | disallows everything |
| 4 | **https URLs** | `sudo -u www-data php artisan tinker --execute='echo route("sms.webhook");'` | starts `https://masjid-staging…`, not `http://` (this is the `FORCE_HTTPS` check) |
| 5 | **sign-in** | sign in as a staging admin | dashboard loads, no CORS errors in the console |
| 6 | **lunch order** | place an order on `/jummah-lunch/1` with card `4242 4242 4242 4242` | order appears on the admin board; the charge is in Stripe **test** mode |
| 7 | **webhook** | Stripe dashboard → test webhook → recent deliveries | 200s, not 401s |
| 8 | **no mail left the box** | `grep -c "Illuminate\\\\Mail" storage/logs/laravel.log` | > 0 — the message was **logged**, meaning `MAIL_MAILER=log` is in force |
| 9 | | `journalctl -u masjid-queue --since today \| grep -i resend` | nothing |
| 10 | **not touching the managed DB** | `ss -tn \| grep 25060` | **no output** — the single most important line in this table |
| 11 | | `sudo -u www-data php artisan db:show \| head -5` | host `127.0.0.1`, database `masjids_staging` |
| 12 | **no push** | `grep -ci onesignal storage/logs/laravel.log` | no send attempts; OneSignal is blank and the scrub NULLed every subscription id |
| 13 | **scrub really ran** | as DATA-REFRESH.md specifies | every email `@*.invalid`, every phone `555…` |
| 14 | **cron as www-data** | `crontab -l \| grep schedule:run` | contains `sudo -u www-data` |
| 15 | **queue alive** | `systemctl is-active masjid-queue` | `active` |

If #10 produces output, stop and fix `.env` before anything else: staging is
connected to production's database.

---

## Step 7 — Destroying the old droplet 480119186

**Recommendation, not an instruction — it is your call.**

Droplet `480119186` / `masjid-backend-service` / `147.182.210.42` serves no HTTP
and is 7 weeks and 100+ commits behind. It is not, however, idle: until
2026-09-10 its queue worker and root cron were running **against production's
database**, sending prayer pushes alongside prod from 7-week-old code. Its `.env`
still holds production's database credentials and live Resend, Anthropic and
OneSignal keys. It runs Ubuntu 24.10, which is **end of life** — `apt` cannot
patch it. It costs money every month to be a liability.

Once staging is verified (step 6), there is nothing it does that staging does not
do better.

**Archive first — what is actually on that box (checked 2026-09-10).** The
two helper scripts older notes place there are NOT on 147.182.210.42:
`manara-onboard-link.sh` no longer exists anywhere, and `manara-stripe-live.sh`
lives on PRODUCTION at `/root/manara-stripe-live.sh` (it validates a pasted
Stripe key's charset and exact length — 107 for modern `_live_51…` keys — and
exists because a truncated key went live once). What the stale box still holds
that is worth keeping is already under `/root/archive-pre-staging/` there
(its last `.env`, keys blanked, plus the crontab it ran); pull that down before
destroying:

```sh
mkdir -p ./deploy/staging/archive
scp -i ~/.ssh/do_mcp -r root@147.182.210.42:/root/archive-pre-staging ./deploy/staging/archive/
scp -i ~/.ssh/do_mcp root@159.65.239.51:/root/manara-stripe-live.sh ./deploy/staging/archive/
```

`deploy/staging/archive/` is gitignored on purpose — the `.env` in it still
carries the managed-database credentials.

Then:

```sh
# take a final snapshot first — it is cheap and makes this reversible for weeks
doctl compute droplet-action snapshot 480119186 --snapshot-name pre-destroy-480119186 --wait
doctl compute droplet delete 480119186
```

Also **rotate** the credentials that box held: the production `RESEND_KEY`
(already a standing open item — it was exposed in a 2026-08-26 transcript), the
Anthropic key and the OneSignal REST key. Destroying the droplet does not
un-copy them.

---

## How to reverse

Nothing in this runbook changes production. Each step undoes independently.

| what | how to undo | what it costs |
|---|---|---|
| **Step 5, a bad ref on staging** | `scripts/ship.sh staging main` | nothing; staging is disposable |
| **Step 4, wrong Stripe keys** | blank them in `.env`, `config:clear && config:cache` | checkouts throw again, which is the safe state |
| **Step 3, bad data** | re-run DATA-REFRESH.md, or `migrate:fresh` + `demo:seed-school` | staging data is not authoritative, ever |
| **Step 2, DNS** | delete the three `*-staging` records in Cloudflare (`hopetechapps.com` → DNS → they are commented "Manara staging (T-040)") | staging becomes unreachable; nothing else notices — no Worker route, no Pages domain and no app references those names |
| **Step 1, provisioning** | `.env` is at `/root/env-backups/env.<timestamp>`; the untouched vhosts are at `/etc/nginx/sites-available/*.prod-original` | but the box is now a clone pointed at prod again — if you restore it, **shut down `masjid-queue` and root cron first**, or you recreate the 480119186 problem |
| **Step 0, the droplet** | `doctl compute droplet delete <id>` | you are back where you started; the snapshot 244822223 remains |
| **the whole idea** | destroy the droplet, delete the DNS records, and revert this directory | production never had a staging dependency; `bin/deploy` on production behaves exactly as it did before T-040 (`--ref` is refused there) |

The one thing that is **not** reversible: private uploads deleted by
provision.sh are gone from *that box*. They are untouched on production, which
is where they belong — see `.claude/rules/private-uploads.md`.
