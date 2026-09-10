# Staging data refresh — production copy → scrubbed staging dataset

This is step 3 of [`RUNBOOK.md`](./RUNBOOK.md), and it is the only document that
owns the sequence. Run it when staging is first built and again whenever its data
goes stale. Nothing here touches production except one **read-only** `mysqldump`.

The whole point of the exercise is the last two commands: a production copy is
not staging data until `staging:scrub` has run over it and said so.

> **Never run `php artisan staging:scrub` anywhere but the staging box.** It
> refuses on four independent conditions — `APP_ENV=staging` (checked twice: the
> value the framework booted with *and* `config('app.env')`, which is what a
> `config:cache` artefact froze, so a box running on production's compiled
> config refuses rather than picking a winner), a database whose name contains
> `staging`, a `DB_HOST` that is not DigitalOcean's managed cluster, and an
> explicit `--i-understand-this-destroys-personal-data` flag — and each refusal
> names the guard that failed. If you find yourself wanting to weaken one, stop:
> the command is doing its job.

---

## Before you start

| | |
|---|---|
| Production | `masjid.hopetechapps.com`, MySQL 8.4 on DigitalOcean's **managed cluster** |
| Staging | `masjid-staging.hopetechapps.com`, MySQL 8 **local to the droplet**, database `masjids_staging` (see `env.staging.example`) |
| Time | 10–20 minutes, most of it the dump and the load |
| Blast radius on prod | one `SELECT`-only dump. No writes, no locks that block writers. |

**The `mysql-client` package may not be installed on the production droplet.**
The app connects through PHP's `pdo_mysql`, which does not need the CLI, so a
freshly provisioned box often has neither `mysqldump` nor `mysql`. Install it
before you start — it adds no service and opens no port:

```sh
ssh -i ~/.ssh/do_mcp root@<prod-ip>
apt-get update && apt-get install -y mysql-client
```

---

## 1. Dump production (read-only)

Run this **on the production droplet**, which already holds the managed
cluster's credentials in its `.env`. Do not copy those credentials anywhere else.

```sh
ssh -i ~/.ssh/do_mcp root@<prod-ip>
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem

# Read the connection out of the app's own .env rather than retyping it.
export $(grep -E '^DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=' .env | xargs)

mysqldump \
  --single-transaction \
  --no-tablespaces \
  --set-gtid-purged=OFF \
  --default-character-set=utf8mb4 \
  --host="$DB_HOST" --port="$DB_PORT" \
  --user="$DB_USERNAME" --password="$DB_PASSWORD" \
  "$DB_DATABASE" \
  | gzip > /root/prod-$(date +%F).sql.gz

ls -lh /root/prod-*.sql.gz
```

Why each flag, because dropping one of them is how this goes wrong:

- **`--single-transaction`** takes the dump inside one consistent snapshot on
  InnoDB instead of locking the tables. Prod keeps serving traffic throughout,
  and donations recorded mid-dump are either wholly in or wholly out — never a
  receipt without its donation.
- **`--no-tablespaces`** stops `mysqldump` needing the `PROCESS` privilege,
  which DigitalOcean's managed users do not have. Without it the dump aborts on
  the first table with `Access denied … PROCESS privilege(s)`.
- **`--set-gtid-purged=OFF`** stops the dump embedding a `SET @@GLOBAL.GTID_PURGED`
  statement. The managed cluster uses GTIDs; staging's standalone MySQL does not,
  and the import fails outright on that line.
- **`--default-character-set=utf8mb4`** — several columns are `utf8mb4_bin`
  (`masjids.name`, `mobile_app_users.device_id`). Anything narrower mangles
  Arabic names on the way out.

---

## 2. Copy the dump to staging

Straight from prod to staging over the VPC, so the file never lands on a laptop:

```sh
# still on the production droplet
scp -i ~/.ssh/do_mcp /root/prod-$(date +%F).sql.gz root@<staging-private-ip>:/root/
```

If the two boxes cannot reach each other, relay it through your machine and
**delete the local copy the moment the load succeeds** — until the scrub runs,
that file is a full copy of every congregant's personal data.

---

## 3. Load it into `masjids_staging`

On the **staging** droplet:

```sh
ssh -i ~/.ssh/do_mcp root@<staging-ip>
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem

# Sanity check before anything destructive: this must say masjids_staging.
grep -E '^(APP_ENV|DB_HOST|DB_DATABASE)=' .env
```

Stop the queue worker first. A worker that picks up a job carried in from
production's `jobs` table will try to send a real push or a real email to a real
person before the scrub has had a chance to empty that table.

```sh
systemctl stop masjid-queue

mysql -u root -e 'DROP DATABASE IF EXISTS masjids_staging; CREATE DATABASE masjids_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
gunzip -c /root/prod-$(date +%F).sql.gz | mysql -u root masjids_staging

php artisan migrate --force
```

`migrate --force` is not optional and is not a no-op: staging usually runs ahead
of production, so the dump's schema is production's. Running the migrations here
is also the cheapest real rehearsal of the next production deploy — a migration
that dies half way (a MySQL identifier over 64 characters, a `VARCHAR` too narrow
for live data) fails here, on a copy, instead of on prod.

---

## 4. Dry run the scrub

```sh
php artisan staging:scrub --dry-run
```

This writes nothing — it is exempt from the confirmation flag for exactly that
reason — and prints two tables: every table whose rows will be **deleted** with
the row count and the reason, and every column that will be **rewritten** with
the strategy that will rewrite it.

Read it. In particular:

- the deleted-rows list should include `sessions`, `jobs`, `failed_jobs`,
  `personal_access_tokens`, `password_reset_tokens`, `appointment_requests`,
  `broadcasts`, `notifications` and `sms_suppressions`;
- the rewritten-columns list should include `contacts.email`, `contacts.phone`,
  `contacts.login_email`, `users.email`, `masjids.phone` and
  `form_responses.data`;
- `donation_receipts.serial_number`, `masjids.name`, `funds.name` and the whole
  `media` table should appear **nowhere** — those are deliberately kept.

If a table you expected is missing, do not improvise a `DELETE`. Fix
`config/staging_scrub.php`, which is the reviewed data map, and let
`StagingScrubCoverageTest` check your work.

---

## 5. Run it for real

```sh
php artisan staging:scrub --i-understand-this-destroys-personal-data
```

Expect, in order: a line per emptied table with its row count, a line per
scrubbed table with its column and write counts, then the verification block:

```
  deleted  sessions                         1,284
  deleted  jobs                                 0
  …
  scrubbed contacts                        6 columns, 18,204 writes
  scrubbed users                           6 columns, 84 writes
  …

  Verifying.
  verified 41 column(s): every address ends in .invalid, every phone is +1 555-01xx.

  Done. 14,902 rows deleted across 24 tables; 61,338 column writes across 26 tables.
```

**The verification block is the acceptance criterion, not the exit code.** It
re-reads every column that was anonymised with the `email` or `phone` strategy,
and then — independently of the config, because the config is the thing that
could be wrong — sweeps every text column of `contacts` and `users` for an `@`
that is not on a `.invalid` domain. If it prints `VERIFICATION FAILED`, the
command exits non-zero and **the database is not safe to hand to anyone**: fix
the config, drop the database and start again from step 3. Do not "just delete
the offending rows".

Spot-check by hand as well, because a green run you did not look at is a green
run you have to trust:

```sh
mysql -u root masjids_staging -e "
  SELECT email, phone, login_email FROM contacts LIMIT 5;
  SELECT COUNT(*) AS leaks FROM contacts WHERE email NOT LIKE '%.invalid';
  SELECT COUNT(*) AS receipts FROM donation_receipts;
  SELECT COUNT(*) AS media_rows FROM media;"
```

`leaks` must be `0`. `receipts` and `media_rows` must be non-zero — an empty
`media` table is what empties the mobile app's feature drawer, and missing
receipts mean the gap-free serial sequence has been broken.

Then bring the worker back:

```sh
systemctl start masjid-queue
php artisan cache:clear   # the `cache` table was emptied; this clears any file/redis layer too
```

Finally, delete the dump from both boxes. It is the last unscrubbed copy:

```sh
rm -f /root/prod-*.sql.gz            # on staging
ssh -i ~/.ssh/do_mcp root@<prod-ip> 'rm -f /root/prod-*.sql.gz'
```

---

## 6. Storage — copy the public tree only

The database is half the story. Files live on two different disks and they are
treated in opposite ways.

**Copy — the public media disk** (`config('media-library.disk_name')`, default
`public`). Logos, app icons, announcement and notification images, gallery
photos. These are already published on the public internet, the `media` rows
that reference them are kept by the scrub, and without them staging renders a
masjid with no identity.

```sh
# from the production droplet
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem
rsync -az --delete \
  storage/app/public/ \
  root@<staging-private-ip>:/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem/storage/app/public/

# on staging, once:
php artisan storage:link
chown -R www-data:www-data storage/app/public
```

**NEVER copy the private disk.** `config/filesystems.php` maps `local` to
`storage_path('app/private')`, a disk with no public URL, and every uploader in
the product defaults to it:

| Never copy | What is in it |
|---|---|
| `storage/app/private/**` (all of it) | the parent tree of everything below |
| `form-attachments/**` | whatever a respondent uploaded — résumés, ID scans, medical letters |
| `group-media/**` | classroom feed photos **of children** |
| `group-resources/**` | staff and parent handouts; per `config/groups.php` this tree is not covered by any backup target either |
| credential documents | scanned professional licences |
| flyer cutouts / source images | janazah portraits of the deceased |

Nothing on staging will 404 as a result: the scrub **deletes the rows** of
`form_response_attachments`, `group_post_attachments` and `group_resources`
outright, and nulls the `contact_credentials.document_*` and `flyers.*_path`
columns, precisely so that no endpoint is ever handed a path to bytes that are
not there. That is the difference between a 404 and a 500.

---

## 7. What the scrub does not do

- **It does not change `.env`.** Mail is `log`, SMS is off, OneSignal and
  Anthropic are blank and Stripe is in test mode because staging's `.env` says
  so (`env.staging.example`), not because of anything this command did.
- **It does not reset passwords you can log in with.** Every staging admin ends
  up with the same password — `STAGING_SCRUB_PASSWORD` in staging's `.env`,
  defaulting to the value in `config/staging_scrub.php` — and an email of
  `user-<id>@staging.invalid`. Look the id up in the `users` table.
- **It does not run migrations.** Step 3 does that, and the order matters: the
  scrub's data map is written against the CURRENT schema, so scrubbing a
  not-yet-migrated dump can silently skip a column that the migration is about
  to add.
- **`sms_suppressions` is emptied, and that is safe only here.** In production
  that table is the STOP list and deliberately outlives the contact row. It is
  expendable on staging solely because staging cannot send SMS. If staging ever
  gains a real A2P sender, move the table to `keep` in
  `config/staging_scrub.php` before the next refresh.

---

## When a new migration adds a personal-data column

`tests/Feature/StagingScrubCoverageTest` walks the real schema and fails if a
column whose name looks like personal data is neither dropped, nulled,
anonymised, nor listed in `reviewed_keep` with a reason. When it goes red on
your migration, add the column to `config/staging_scrub.php` — and if you are
keeping it, write the sentence in `reviewed_keep` that says why its content is
not personal data. That sentence is the whole control.
