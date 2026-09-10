# T-040 — PII inventory for the staging data-scrub

Complete inventory of personal-data columns in MasjidWebMS, for the script that scrubs a
production dump before it is loaded into staging. Derived **statically** on **2026-09-09**
from all 167 files in `database/migrations/` plus `$fillable` / `$casts` / `$hidden` in
`app/Models/`, and from `config/{filesystems,forms,groups,credentials,flyer,media-library}.php`.
No database was queried and no PHP was executed — every column below appears in a migration
or a model in this repo; nothing is inferred from naming conventions alone.

---

## Traps

### (a) UNIQUE indexes a naive `UPDATE … SET col = 'x'` will collide with

Every one of these must be scrubbed with a **row-unique** expression (e.g.
`CONCAT('user', id, '@staging.invalid')`, `CONCAT('+1555', LPAD(id, 7, '0'))`), never a
constant.

| Index / constraint | Table.column(s) | Why it collides |
|---|---|---|
| `users_email_unique` | `users.email` | one constant email for every admin |
| PRIMARY KEY | `password_reset_tokens.email` | email **is** the primary key |
| `personal_access_tokens_token_unique` | `personal_access_tokens.token` | 64-char hash, unique |
| `masjids_name_unique` | `masjids.name` | MySQL collation `utf8mb4_bin` |
| `masjids_email_unique` | `masjids.email` | |
| `masjids_phone_unique` | `masjids.phone` | NOT nullable — cannot be nulled either |
| `masjids_website_link_unique` | `masjids.website_link` | nullable, so `NULL` is the safe scrub |
| `masjids_active_owner_unique` | `masjids.user_id` (live rows) | **MySQL: enforced through the VIRTUAL generated column `active_owner_user_id`.** You cannot write that column; write `user_id`/`deleted_at` and the DB recomputes. SQLite uses a real partial index. |
| `masjids_active_stripe_account_unique` | `masjids.stripe_account_id` (live + non-empty) | same generated-column trick via `active_stripe_account_id`. Nulling `stripe_account_id` on every row is safe (NULL/`''` are excluded from the predicate); setting them all to one fake `acct_…` is **not**. |
| `masjid_user_masjid_id_user_id_unique` | `masjid_user(masjid_id, user_id)` | plus a MySQL generated `default_key` with `UNIQUE(user_id, default_key)` — one default masjid per user |
| `mobile_app_users_device_id_unique` | `mobile_app_users.device_id` | `utf8mb4_bin` collation |
| `contact_us_accounts_mobile_app_user_id_unique` | `contact_us_accounts.mobile_app_user_id` | 1:1 |
| `contacts_masjid_login_email_unique` | `contacts(masjid_id, login_email)` | the parent-portal login identity |
| `contact_cards_contact_id_last4_unique` | `contact_cards(contact_id, last4)` | scrubbing every `last4` to `0000` collapses two cards on one contact |
| `donations_uuid_unique`, `donations_idempotency_key_unique` | `donations` | |
| `donation_subscriptions_uuid_unique`, `…_stripe_subscription_id_unique`, `…_idempotency_key_unique` | `donation_subscriptions` | |
| `donation_receipts_masjid_id_serial_number_unique`, `donation_receipts_donation_id_unique` | `donation_receipts` | serial is **gap-free per masjid** — never renumber |
| `registrations_uuid_unique`, `registrations_idempotency_key_unique` | `registrations` | key is nullable → `NULL` is the safe scrub |
| `registration_payments_idempotency_key_unique` | `registration_payments` | nullable |
| `meal_orders_uuid_unique`, `meal_orders_idempotency_key_unique`, `meal_orders_masjid_id_meal_menu_id_order_number_unique` | `meal_orders` | `order_number` is the pickup code, unique per menu |
| `meal_menus_uuid_unique`, `meal_menus_masjid_id_service_date_unique` | `meal_menus` | |
| `sms_suppressions_masjid_id_phone_e164_unique` | `sms_suppressions(masjid_id, phone_e164)` | rewriting every number to one value collapses the whole opt-out list into one row |
| `masjid_sms_senders_masjid_id_unique` | `masjid_sms_senders.masjid_id` | one sender per tenant; `phone_number` itself is only indexed, not unique |
| `notifications_onesignal_message_id_unique` | `notifications.onesignal_message_id` | nullable → `NULL` is safe |
| `stripe_webhook_events_stripe_event_id_unique` | `stripe_webhook_events.stripe_event_id` | |
| `failed_jobs_uuid_unique` | `failed_jobs.uuid` | |
| `provisioning_jobs_job_id_unique` | `provisioning_jobs.job_id` | |
| `flyers_uuid_unique`, `flyer_templates_key_unique` | flyer tables | |
| `media_uuid_unique` | `media.uuid` | |
| `form_response_attachments_form_response_id_field_unique` | attachments | one file per form field |
| `group_memberships_edge_unique` | `group_memberships(group_id, contact_id, role, guardian_of_contact_id)` | do not rewrite `contact_id`/`guardian_of_contact_id` |
| `group_thread_reads_group_thread_id_user_id_unique`, `group_thread_reads_thread_contact_unique` | `group_thread_reads` | |
| `registrants_registration_id_contact_id_unique` | `registrants` | |
| `contact_service_interests_unique` | `contact_service_interests(contact_id, service_id)` | |
| `attendance_student_day_unique`, `gradebook_score_unique`, `lesson_plan_class_day_unique`, `report_card_period_unique`, `report_card_mark_unique`, `arabic_progress_student_drill_unique`, `curriculum_week_cell_unique`, `behavior_skills_masjid_id_label_unique` | academic tables | all keyed on `group_membership_id` / label text |
| `masjid_social_media_links_masjid_id_type_value_unique` | `masjid_social_media_links(masjid_id, type, value)` | the `WhatsApp_Number` rows are phone numbers |
| `app_version_settings_masjid_id_platform_unique` | `app_version_settings` | not PII, listed for completeness |

### (b) Encrypted columns — **null only, never UPDATE to a literal**

These use Laravel's `encrypted` cast: the column holds ciphertext produced with `APP_KEY`.
An SQL scrub cannot re-encrypt, and writing plaintext into them makes every subsequent read
throw a `DecryptException`. The only safe SQL action is `SET … = NULL`.

| Table | Column | Cast declared in |
|---|---|---|
| `users` | `two_factor_secret` | `app/Models/User.php:130` (also in `$hidden`) |
| `appointment_requests` | `date_of_birth` | `app/Models/AppointmentRequest.php:79` |
| `appointment_requests` | `reason` | `app/Models/AppointmentRequest.php:80` |
| `appointment_request_notes` | `body` | `app/Models/AppointmentRequestNote.php:37` |
| `contact_credentials` | `identifier` (licence number) | `app/Models/ContactCredential.php:122` |
| `masjid_app_publishing` | `asc_key_p8` | `app/Models/MasjidAppPublishing.php:60` |
| `masjid_app_publishing` | `asc_key_id` | `:61` |
| `masjid_app_publishing` | `asc_issuer_id` | `:62` |
| `masjid_app_publishing` | `play_service_account_json` | `:63` |
| `masjid_app_publishing` | `onesignal_rest_api_key` | `:64` |

`appointment_requests.date_of_birth` and `.reason` are declared `text` in the migration
precisely because they carry ciphertext — do not be fooled by the type.

### (c) JSON / payload columns that can embed PII inside

| Table.column | Type / cast | What can be inside |
|---|---|---|
| `form_responses.data` | `json`, cast `array` | **the big one** — every answer to an arbitrary tenant-authored form: names, DOBs, medical notes, addresses. Cannot be scrubbed column-by-column; replace the whole document. |
| `broadcasts.audience_contact_ids` | `json`, cast `array` | an explicit list of `contacts.id` — a targeting list, not names |
| `sessions.payload` | `longText` | serialized session: user id, flashed input (which can include a typed email/phone) |
| `jobs.payload` | `longText` | serialized queued jobs — `SendMasjidNotificationJob`, mail jobs, SMS jobs carry recipient data |
| `failed_jobs.payload` + `failed_jobs.exception` | `longText` | same, plus a stack trace whose arguments frequently contain the recipient |
| `job_batches.options`, `job_batches.failed_job_ids` | `mediumText`/`longText` | serialized batch options |
| `forms.schema`, `forms.settings` | `json`, cast `array` | field definitions and notification recipient addresses in settings |
| `offerings.settings` | `json`, cast `array` | per-offering config |
| `sections.content`, `sections.settings` | `json`, cast `array` | free-form page content an admin typed |
| `flyers.content`, `flyers.palette` | `json`, cast `array` | janazah flyer text — names of the deceased and family |
| `lesson_plans.learning_outcomes`, `.teaching_methods` | `json`, cast `array` | teacher free text |
| `personal_access_tokens.abilities` | `text` | not PII, but goes with the token |
| `cache.value` | `mediumText` | cached API payloads (the mobile masjid/announcement caches) |
| `media.custom_properties`, `.manipulations`, `.generated_conversions`, `.responsive_images` | `string` | Spatie metadata |
| `prayers.prayers_data` / `.iqama_times_data` / `.jumaa_data`, `jumaa_settings.athans`/`.shifts`, `theme_settings.tokens`, `masjid_app_publishing.enabled_platforms`, `page_section.platforms` | `json` | **no** personal data — listed so the scrub script does not waste effort on them |

### (d) File-path columns on the PRIVATE disk vs the public media disk

`config/filesystems.php` maps the `local` disk to `storage_path('app/private')` — a disk with
no `url`. Every uploader below defaults to `local`, so on a staging box these rows point at
bytes that will simply not exist unless the private tree is copied too (and it must **not**
be). Null the path *and* delete the row, or the download endpoints 500 instead of 404.

**Private (`storage/app/private`):**

| Table | Columns | Disk source |
|---|---|---|
| `form_response_attachments` | `disk`, `path`, `original_name`, `mime_type`, `size_bytes` | `config('forms.attachments.disk')` → `FORM_ATTACHMENT_DISK`, default `local` |
| `group_post_attachments` | `disk`, `path`, `original_name`, `mime_type`, `size_bytes` | `config('groups.media.disk')` → `GROUP_MEDIA_DISK`, default `local` |
| `group_resources` | `disk`, `path`, `original_name`, `mime_type`, `size_bytes` | `config('groups.resources.disk')` → `GROUP_RESOURCE_DISK`, default `local` |
| `contact_credentials` | `document_disk`, `document_path`, `document_original_name`, `document_mime_type`, `document_size_bytes` | `config('credentials.document.disk')` → `CREDENTIAL_DOCUMENT_DISK`, default `local` |
| `flyers` | `source_image_path`, `cutout_path`, `rendered_path` | `config('flyer.cutout.disk')` → `FLYER_CUTOUT_DISK`, default `local`; `FlyersController::IMAGE_DISK` / `FlyerCutoutController::IMAGE_DISK` are both the literal `'local'` |

**Public media disk (do NOT wipe — this is the logo/icon estate):**

| Table | Columns | Note |
|---|---|---|
| `media` | `disk`, `conversions_disk`, `file_name`, `name` | `config('media-library.disk_name')` → `MEDIA_DISK`, default `public`. Logos, app icons, announcement and notification images. Truncating this table is what empties the app's feature drawer — leave it alone. |
| `meal_menus` | `flyer_image_url` | a URL string, not a disk path |
| `masjids` | `app_store_link`, `google_play_link` | public URLs |

---

## Tables to DROP ROWS wholesale on staging

Safe to `TRUNCATE` / `DELETE FROM` — nothing downstream references them, or what does is
regenerated:

- **`sessions`** — IPs, user agents and serialized session payloads. Nothing FKs it; everyone
  simply logs in again.
- **`cache`, `cache_locks`** — cached API payloads can hold a snapshot of the very data you
  just scrubbed. Regenerated on first request.
- **`jobs`, `job_batches`, `failed_jobs`** — serialized job payloads and exception traces carry
  recipient names, emails and phone numbers. Also stops staging from replaying a real send.
- **`personal_access_tokens`** — live Sanctum bearer tokens for real admins and real devices.
  Polymorphic, so nothing FKs it.
- **`password_reset_tokens`** — live reset tokens keyed by a real email address (email is the PK).
- **`contact_login_codes`** — OTP hashes plus `requested_ip`. Short-lived by design; only a
  one-way FK to `contacts`.
- **`app_signup_codes`** — same shape and additionally stores the raw `email` it was sent to.
- **`contact_login_events`** — the login audit log: `actor_name`, `actor_email`, `actor_ip`,
  `login_email`. Nothing references it. (In **production** this is a retention record — never
  truncate it there; it is only expendable in the staging copy.)
- **`stripe_webhook_events`** — this is an idempotency ledger only (`stripe_event_id`, `type`,
  `processed_at`); it holds **no payload column**. Dropping it is not just safe but desirable:
  staging wants a test webhook to be processed rather than swallowed as a duplicate.
- **`provisioning_jobs`** — `callback_token` is a live shared secret, and `github_repo` /
  `artifact_url` point at real build infrastructure.
- **`notifications`** — the push send-log. Only a `masjid_id` FK inbound, no children. Drop so a
  staging admin cannot re-fire a real OneSignal message id, and so the in-app inbox starts empty.
- **`broadcasts`** (cascades to **`broadcast_deliveries`**) — send history, `audience_contact_ids`
  targeting lists, provider references and error text. `broadcast_deliveries.broadcast_id` is
  `cascadeOnDelete`, so deleting the parents is enough.
- **`contact_us_messages`**, then **`contact_us_accounts`** — the mobile Contact-Us inbox: free-text
  messages plus a name/email/phone per app user. `contact_us_messages.contact_us_account_id`
  cascades, so parents-last ordering is not even required.
- **`appointment_requests`** (cascades to **`appointment_request_notes`**) — the most sensitive table
  in the schema (applicant name, phone, encrypted DOB and reason, plus staff notes). Two of its
  columns are encrypted and therefore un-anonymisable anyway, and nothing references the table,
  so dropping is strictly better than nulling.
- **`sms_suppressions`** — **safe on staging, and only on staging.** In production the opt-out
  deliberately **outlives the contact row**: the table is keyed on `phone_e164`, has *no* foreign
  key to `contacts`, and rows are *released* (`released_at`) rather than deleted, precisely so a
  merge, a re-import or a `forceDelete` cannot resurrect a number that texted STOP
  (`.claude/rules/broadcasts.md`, "The opt-out OUTLIVES the contact row"). Truncating it on
  staging is nevertheless correct **because staging must not send SMS at all** — no live A2P
  sender, provider adapter stubbed. State that precondition in the scrub script and fail loudly
  if staging is ever pointed at a real provider; if it ever can send, this table moves to KEEP.
  `masjid_sms_senders` is *not* dropped — see below.

**Must be KEPT (dropping breaks referential integrity or destroys a record that must survive):**

- `masjid_sms_senders` — one row per tenant, `masjid_id` unique; the sender registration state
  is config, not history. Null the credential-ish columns instead (see the inventory).
- `donation_receipts` — `serial_number` is a **gap-free per-masjid sequence** allocated by
  `ReceiptService`; deleting rows creates holes the allocator's unique index will then fight.
  Anonymise the provenance columns; keep the rows.
- `donations`, `donation_subscriptions`, `registrations`, `registrants`, `registration_payments`,
  `meal_orders`, `meal_order_items` — parents of receipts / children of contacts and funds; the
  money graph is what staging exists to test.
- `contacts`, `users`, `masjids`, `groups`, `group_memberships` — the spine everything else FKs.
- `form_responses` — `registrations.form_response_id` points at it; anonymise `data` in place.
- `media` — the logo/icon estate. Wiping it empties the mobile feature drawer.
- `contacts.sms_opt_in` / `sms_consent_*` — a consent record. Scrub the *evidence* text if it
  quotes a person, but keep the flag and timestamps so the audience resolver behaves realistically.

---

## Inventory

`action` is one of `anonymise` (rewrite to a synthetic, row-unique value), `null` (set NULL),
`keep` (leave as-is), `DROP ROWS` (the whole table is truncated; column listed for the record).

### users

| table | column | action | note |
|---|---|---|---|
| users | name | anonymise | `CONCAT('Admin ', id)` |
| users | email | anonymise | UNIQUE — must be row-unique, e.g. `CONCAT('user', id, '@staging.invalid')` |
| users | email_verified_at | keep | timestamp only; keeps the login flow realistic |
| users | password | anonymise | set one known bcrypt hash for every staging admin |
| users | remember_token | null | live "remember me" cookie material |
| users | phone | anonymise | row-unique fake, e.g. `CONCAT('+1555', LPAD(id, 7, '0'))` |
| users | phone_verified_at | keep | |
| users | type | keep | role gate — `SuperAdmin`/`MasjidAdmin`/`User`/`teacher` |
| users | two_factor_secret | null | **encrypted cast** — cannot be rewritten, only nulled (also un-enrols 2FA on staging) |
| users | two_factor_confirmed_at | null | must be nulled together with the secret or login demands a code nobody can produce |

### masjids

| table | column | action | note |
|---|---|---|---|
| masjids | name | keep | UNIQUE + `utf8mb4_bin`; an organisation name is public, and staging needs it recognisable |
| masjids | email | anonymise | UNIQUE; row-unique fake so staging never mails a real org inbox |
| masjids | phone | anonymise | UNIQUE and **NOT NULL** — cannot be nulled |
| masjids | address | keep | public directory data |
| masjids | latitude / longitude | keep | public directory data |
| masjids | website_link | keep | public; UNIQUE but nullable |
| masjids | user_id | keep | subject to `masjids_active_owner_unique` (MySQL generated `active_owner_user_id`) — do not rewrite |
| masjids | stripe_account_id | null | live Connect account. NULL is excluded from `masjids_active_stripe_account_unique`, so nulling all rows is safe; one shared fake `acct_…` is **not** |
| masjids | stripe_charges_enabled / stripe_payouts_enabled | anonymise | force `false` so staging cannot believe it may charge |
| masjids | tax_id | null | EIN — real org identifier |
| masjids | statement_signatory | null | a named person on tax receipts |
| masjids | mailing_locale | keep | preference |
| masjids | google_maps_key | null | billable third-party credential |
| masjids | copyright_text | keep | |
| masjids | active_owner_user_id / active_stripe_account_id | keep | MySQL VIRTUAL generated columns — **not writable**; they follow their source column |
| masjids | created_by / updated_by / deleted_by | keep | FKs to `users` |

### masjid_user (pivot — note: the table is `masjid_user`, singular)

| table | column | action | note |
|---|---|---|---|
| masjid_user | masjid_id, user_id | keep | `UNIQUE(masjid_id, user_id)`; the tenant-admin graph |
| masjid_user | role, is_default | keep | MySQL generated `default_key` enforces one default masjid per user |

### password_reset_tokens · sessions · jobs · failed_jobs · job_batches · cache · personal_access_tokens

| table | column | action | note |
|---|---|---|---|
| password_reset_tokens | email | DROP ROWS | email is the PRIMARY KEY; live reset material |
| password_reset_tokens | token, created_at | DROP ROWS | |
| sessions | ip_address | DROP ROWS | |
| sessions | user_agent | DROP ROWS | |
| sessions | payload | DROP ROWS | serialized session incl. flashed input |
| sessions | user_id, last_activity | DROP ROWS | |
| jobs | payload | DROP ROWS | serialized mail/SMS/push jobs carry recipients |
| job_batches | name, options, failed_job_ids | DROP ROWS | |
| failed_jobs | payload | DROP ROWS | |
| failed_jobs | exception | DROP ROWS | stack traces routinely quote the recipient |
| failed_jobs | uuid, connection, queue, failed_at | DROP ROWS | `uuid` is UNIQUE |
| cache | value | DROP ROWS | cached mobile API payloads |
| cache_locks | owner | DROP ROWS | |
| personal_access_tokens | token | DROP ROWS | UNIQUE 64-char hash; a live bearer token |
| personal_access_tokens | name, abilities, tokenable_type, tokenable_id, last_used_at | DROP ROWS | |

### contacts (the CRM spine)

| table | column | action | note |
|---|---|---|---|
| contacts | first_name | anonymise | |
| contacts | last_name | anonymise | |
| contacts | email | anonymise | not unique on its own, but keep it row-unique so it stays a plausible key |
| contacts | phone | anonymise | feeds `PhoneNumber::e164()`, the SMS audience resolver and `contact_cards` lookups — keep the shape valid |
| contacts | notes | null | free-text staff notes about a congregant |
| contacts | login_email | anonymise | `UNIQUE(masjid_id, login_email)` — **row-unique per masjid** or the update aborts |
| contacts | password | null | bcrypt hash of a parent's portal password (`$hidden`); NULL = no password set |
| contacts | password_set_at | null | must be nulled with `password` or the UI claims a password exists |
| contacts | login_enabled_at / login_revoked_at / last_login_at | keep | lifecycle flags; no personal content |
| contacts | sms_opt_in | keep | consent flag — keep so the audience resolver behaves realistically |
| contacts | sms_consent_at | keep | |
| contacts | sms_consent_source | keep | a constant from `Contact::SMS_CONSENT_SOURCES` |
| contacts | sms_consent_evidence | anonymise | free **text** (widened 2026-09-09) quoting a specific artifact, e.g. "web form response #4182" |
| contacts | sms_opted_out_at | keep | mirror of the suppression list |
| contacts | is_placeholder, import_batch, signup_source, verified_at | keep | provenance flags |
| contacts | avatar_character / avatar_tone / avatar_color | keep | one of forty shipped drawings, never an upload |
| contacts | staff_avatar_character / staff_avatar_tone / staff_avatar_color | keep | staff override of the same |

### contact_cards · contact_credentials · contact_service_interests

| table | column | action | note |
|---|---|---|---|
| contact_cards | last4 | anonymise | `UNIQUE(contact_id, last4)` — a constant collapses two cards on one contact |
| contact_cards | brand | keep | |
| contact_cards | note | null | free text |
| contact_credentials | kind, label, issuing_body | keep | e.g. "medical licence", "State of NC" |
| contact_credentials | identifier | null | **encrypted cast** — licence number, null only |
| contact_credentials | issued_at, expires_at | keep | |
| contact_credentials | notes | null | free text |
| contact_credentials | document_original_name | null | the uploader's own filename (`$hidden`) |
| contact_credentials | document_path | null | **private disk** (`storage/app/private`) — bytes are not copied to staging |
| contact_credentials | document_disk / document_mime_type / document_size_bytes | null | null together with the path so `documentDisk()` is never called on a half-row |
| contact_service_interests | contact_id, service_id | keep | join table; `UNIQUE(contact_id, service_id)` |

### contact_login_codes · contact_login_events · app_signup_codes

| table | column | action | note |
|---|---|---|---|
| contact_login_codes | code_hash | DROP ROWS | SHA-256 of a live OTP |
| contact_login_codes | requested_ip | DROP ROWS | |
| contact_login_codes | channel, expires_at, consumed_at, attempts | DROP ROWS | |
| contact_login_events | login_email | DROP ROWS | |
| contact_login_events | actor_name | DROP ROWS | |
| contact_login_events | actor_email | DROP ROWS | |
| contact_login_events | actor_ip | DROP ROWS | |
| contact_login_events | action, actor_user_id, contact_id | DROP ROWS | audit log; keep in prod, expendable in the staging copy |
| app_signup_codes | email | DROP ROWS | raw address the code was mailed to |
| app_signup_codes | code_hash | DROP ROWS | |
| app_signup_codes | requested_ip | DROP ROWS | |

### mobile_app_users · contact_us_accounts · contact_us_messages · notifications · splash_announcements

| table | column | action | note |
|---|---|---|---|
| mobile_app_users | device_id | anonymise | **UNIQUE** (`utf8mb4_bin`) — a per-install device identifier; must stay row-unique |
| mobile_app_users | onesignal_subscription_id | null | the push address of a real handset — nulling it is what stops staging paging real devices. This (plus `device_id`) is the **only** push-token store; there is no separate device-token table |
| mobile_app_users | user_agent | anonymise | **NOT NULL** — set a constant like `staging` |
| mobile_app_users | last_active_at, masjid_id, contact_id | keep | |
| contact_us_accounts | email, name, phone | DROP ROWS | `mobile_app_user_id` is UNIQUE (1:1) |
| contact_us_messages | message | DROP ROWS | free-text inbound message |
| notifications | title, message | DROP ROWS | `message` is TEXT on MySQL (widened 2026-07-24) |
| notifications | onesignal_message_id | DROP ROWS | UNIQUE; a real OneSignal send id |
| splash_announcements | title, body, cta_label, cta_url | keep | admin-authored public copy |
| splash_announcements | onesignal_iam_id | null | real in-app-message id |

### donations · donation_receipts · donation_subscriptions · stripe_webhook_events

| table | column | action | note |
|---|---|---|---|
| donations | uuid | keep | UNIQUE; opaque handle, no PII |
| donations | contact_id | keep | the donor link — anonymise the contact, not the FK |
| donations | idempotency_key | keep | UNIQUE; opaque |
| donations | stripe_payment_intent_id | null | live Stripe object id |
| donations | stripe_checkout_session_id | null | |
| donations | stripe_charge_id | null | |
| donations | stripe_balance_transaction_id | null | |
| donations | stripe_subscription_id | null | |
| donations | stripe_invoice_id | null | |
| donations | check_number | null | a real cheque number off a donor's account |
| donations | note | null | free-text offline-gift note; often names the donor |
| donations | import_batch | keep | provenance label |
| donations | source, payment_method, donated_at, is_zakat, zakat_source | keep | |
| donations | intended_amount / charged_amount / *_fee_amount / net_amount / receipt_eligible_amount | keep | staging needs real money maths. There is **no** `donor_name` column — typed names resolve to a contact |
| donation_receipts | serial_number | keep | gap-free per-masjid sequence, `UNIQUE(masjid_id, serial_number)` — never renumber |
| donation_receipts | donation_id | keep | UNIQUE (one receipt per donation) |
| donation_receipts | payment_reference | null | cheque number / external reference |
| donation_receipts | payment_method, issue_date, amounts, jurisdiction, status | keep | |
| donation_subscriptions | uuid, idempotency_key | keep | UNIQUE, opaque |
| donation_subscriptions | stripe_subscription_id | null | **UNIQUE and nullable** — NULL is the only safe scrub |
| donation_subscriptions | stripe_checkout_session_id | null | |
| donation_subscriptions | stripe_customer_id | null | a real Stripe customer, i.e. a real person |
| donation_subscriptions | contact_id, interval, status, amounts | keep | |
| stripe_webhook_events | stripe_event_id | DROP ROWS | UNIQUE; the idempotency key |
| stripe_webhook_events | type, processed_at | DROP ROWS | **there is no `payload` column on this table** — see "Not found" |

### registrations · registrants · registration_payments · registration_adjustments · offerings · fee_plans

| table | column | action | note |
|---|---|---|---|
| registrations | uuid | keep | UNIQUE, opaque |
| registrations | contact_id, form_response_id, offering_id, fee_plan_id | keep | referential spine |
| registrations | idempotency_key | keep | UNIQUE but nullable |
| registrations | stripe_checkout_session_id | null | |
| registrations | stripe_subscription_id | null | |
| registrations | stripe_subscription_schedule_id | null | |
| registrations | status, payment_status, totals, checkout_expires_at | keep | |
| registrants | registration_id, contact_id | keep | pure join; `UNIQUE(registration_id, contact_id)` |
| registration_payments | stripe_payment_intent_id / stripe_invoice_id / stripe_charge_id / stripe_balance_transaction_id | null | |
| registration_payments | idempotency_key | keep | UNIQUE, nullable, opaque |
| registration_payments | amount_minor, fees, status, paid_at | keep | |
| registration_adjustments | reason | anonymise | free text — scholarship reasons quote family circumstances |
| registration_adjustments | granted_by_user_id, kind, amount_minor | keep | |
| offerings | name, slug, description, settings | keep | public programme copy; `settings` is json, review before trusting |
| fee_plans | label, kind, amounts | keep | |

### forms · form_responses · form_response_attachments

| table | column | action | note |
|---|---|---|---|
| forms | name, slug, description | keep | `UNIQUE(masjid_id, slug)` |
| forms | schema | keep | field definitions, not answers |
| forms | settings | anonymise | json — can carry notification recipient addresses |
| form_responses | data | anonymise | **json / `array` cast — arbitrary respondent answers.** Replace the whole document (e.g. `'{"scrubbed":true}'`); a per-key scrub is impossible because tenants author the schema |
| form_responses | respondent_name | anonymise | |
| form_responses | respondent_email | anonymise | indexed (not unique) |
| form_responses | respondent_phone | anonymise | |
| form_responses | admin_notes | null | staff free text about the respondent |
| form_responses | device_id | null | |
| form_responses | ip_address | null | |
| form_responses | user_agent | null | |
| form_responses | entry_count, amount_due, status, submitted_at | keep | |
| form_response_attachments | original_name | null | the respondent's own filename (a résumé is often `Firstname-Lastname-CV.pdf`) |
| form_response_attachments | path | null | **private disk**; `UNIQUE(form_response_id, field)` on the row |
| form_response_attachments | disk, mime_type, size_bytes, field | null | null together with the path, or delete the row outright |

### groups · group_memberships · group_staff

| table | column | action | note |
|---|---|---|---|
| groups | name, slug, description, kind, arabic_stage | keep | class/programme metadata; `UNIQUE(masjid_id, slug)` |
| group_memberships | contact_id | keep | part of `group_memberships_edge_unique` |
| group_memberships | guardian_of_contact_id | keep | part of the same unique edge — the parent↔child link |
| group_memberships | role, grade_label, joined_at | keep | |
| group_memberships | consent_granted_at, consent_scope | keep | **guardian consent record** — keep so the consent gates behave |
| group_memberships | provenance, confirmed_at, confirmed_by_user_id, source_registration_id | keep | provenance chain |
| group_staff | user_id, role, assigned_by_user_id, assigned_at | keep | `UNIQUE(group_id, user_id)` |

### group_posts · group_threads · group_messages · attachments · resources

| table | column | action | note |
|---|---|---|---|
| group_posts | title | keep | classroom feed copy |
| group_posts | body | anonymise | free text naming children |
| group_posts | author_user_id, retained_until | keep | |
| group_post_attachments | original_name | null | uploader's filename |
| group_post_attachments | path | null | **private disk** — classroom photos of children |
| group_post_attachments | disk, mime_type, size_bytes | null | null with the path, or delete the row |
| group_threads | subject | anonymise | subjects name the student ("Concern re: <child>") |
| group_threads | created_by_user_id, created_by_contact_id, about_membership_id, scope | keep | |
| group_messages | body | anonymise | parent↔teacher message text |
| group_messages | author_user_id, author_contact_id | keep | |
| group_thread_reads | user_id, contact_id, last_read_at | keep | two UNIQUE indexes on this table |
| group_resources | title, description | keep | worksheet metadata |
| group_resources | original_name | null | |
| group_resources | path | null | **private disk**, and per `config/groups.php` this tree is **not** covered by any backup target |
| group_resources | disk, mime_type, size_bytes, visibility | null | null with the path, or delete the row |

### appointment_requests · appointment_request_notes

| table | column | action | note |
|---|---|---|---|
| appointment_requests | applicant_name | DROP ROWS | |
| appointment_requests | phone | DROP ROWS | |
| appointment_requests | email | DROP ROWS | |
| appointment_requests | date_of_birth | DROP ROWS | **encrypted cast** (declared `text`) — could only ever be nulled |
| appointment_requests | reason | DROP ROWS | **encrypted cast** — why someone needs an imam |
| appointment_requests | preferred_window, status, source | DROP ROWS | |
| appointment_requests | ip_address | DROP ROWS | |
| appointment_requests | user_agent | DROP ROWS | 1000-char column |
| appointment_request_notes | body | DROP ROWS | **encrypted cast**; cascades from the parent |
| appointment_request_notes | user_id | DROP ROWS | |

### broadcasts · broadcast_deliveries · sms_suppressions · masjid_sms_senders

| table | column | action | note |
|---|---|---|---|
| broadcasts | title, body, link | DROP ROWS | admin-authored, but the send history should not exist on staging |
| broadcasts | audience_contact_ids | DROP ROWS | **json** list of `contacts.id` — a targeting list |
| broadcasts | audience, audience_service_id, scheduled_at, dispatched_at, status | DROP ROWS | |
| broadcast_deliveries | reference, reference_id | DROP ROWS | provider message ids; cascades from `broadcasts` |
| broadcast_deliveries | note, error | DROP ROWS | error text quotes the failing recipient |
| broadcast_deliveries | channel, status, target_count, delivered_at | DROP ROWS | `UNIQUE(broadcast_id, channel)` |
| sms_suppressions | phone_e164 | DROP ROWS | `UNIQUE(masjid_id, phone_e164)`. Safe **only** because staging must not send — see the drop-rows section |
| sms_suppressions | reason, keyword, released_keyword, provider_message_id, suppressed_at, released_at | DROP ROWS | |
| masjid_sms_senders | phone_number | null | the tenant's real registered A2P 10DLC number |
| masjid_sms_senders | messaging_service_sid | null | live Twilio Messaging Service |
| masjid_sms_senders | brand_registration_id, campaign_registration_id | null | real 10DLC registrations |
| masjid_sms_senders | sender_label | keep | display label |
| masjid_sms_senders | registration_status | anonymise | force `unregistered` so staging cannot believe it may text |
| masjid_sms_senders | notes | null | free text |

### meal_menus · meal_menu_items · meal_orders · meal_order_items

| table | column | action | note |
|---|---|---|---|
| meal_menus | title, title_ar, pickup_instructions, pickup_instructions_ar | keep | public menu copy |
| meal_menus | notes | keep | operational notes; review if a tenant used it for names |
| meal_menus | flyer_image_url | keep | a public URL, not a private path |
| meal_menus | uuid, service_date | keep | `UNIQUE(uuid)`, `UNIQUE(masjid_id, service_date)` |
| meal_menus | notify_service_id, opening_notified_at, allow_sms_optin, collect_customer_email | keep | |
| meal_menu_items | name, name_ar, description, description_ar, price_minor | keep | menu content |
| meal_orders | customer_name | anonymise | |
| meal_orders | customer_phone | anonymise | pickup contact number |
| meal_orders | customer_email | anonymise | nullable — receipt address |
| meal_orders | customer_notes | null | allergy / free text |
| meal_orders | order_number | keep | `UNIQUE(masjid_id, meal_menu_id, order_number)` — the pickup code, not personal |
| meal_orders | uuid, idempotency_key | keep | UNIQUE, opaque |
| meal_orders | stripe_checkout_session_id | null | |
| meal_orders | stripe_payment_intent_id | null | |
| meal_orders | contact_id, status, payment_*, totals, donation_minor, fee_covered_minor, timestamps | keep | |
| meal_order_items | item_name, unit_price_minor, quantity, line_total_minor | keep | menu snapshot, not personal |

### Academic records (school vertical)

| table | column | action | note |
|---|---|---|---|
| hifz_entries | note | anonymise | a teacher's remark about a named child |
| hifz_entries | group_membership_id, heard_by_user_id, corrected_by_user_id | keep | |
| hifz_entries | kind, from_surah/from_ayah/to_surah/to_ayah, quality, major_mistakes, minor_mistakes, recited_at | keep | performance data — the point of staging |
| behavior_awards | note | anonymise | free text about a child's conduct |
| behavior_awards | skill_label, skill_polarity, points, awarded_at, retained_until | keep | |
| behavior_awards | group_membership_id, awarded_by_user_id, revoked_by_user_id, behavior_skill_id | keep | |
| behavior_skills | label | keep | `UNIQUE(masjid_id, label)`; a rubric label, not personal |
| attendance_records | note | anonymise | absence reasons quote family circumstances |
| attendance_records | status, session_date, group_membership_id, marked_by_user_id | keep | `UNIQUE(group_membership_id, session_date)` |
| class_assignments | title, points_possible, scale, assigned_on, created_by_user_id | keep | |
| assignment_scores | note | anonymise | per-student grading remark |
| assignment_scores | points_earned, status | keep | `UNIQUE(class_assignment_id, group_membership_id)` |
| lesson_plans | body | keep | curriculum content; `UNIQUE(group_id, session_date)` |
| lesson_plans | title, subject, grade_label, objective, standard_*, differentiation_*, cross_integration_*, teaching_aids, assessment_* | keep | curriculum, not personal |
| lesson_plans | reflection_worked, reflection_improve | anonymise | a teacher's post-hoc reflection frequently names a struggling child |
| lesson_plans | learning_outcomes, teaching_methods | keep | json arrays of curriculum terms |
| report_cards | teacher_comment | anonymise | the single most sensitive free-text field in the school module |
| report_cards | days_present, days_absent, days_late | keep | |
| report_cards | type, school_year, term, grade_label, published_at, published_by_user_id, created_by_user_id | keep | `report_card_period_unique` |
| report_card_marks | comment | anonymise | per-criterion remark |
| report_card_marks | kind, subject, criterion, level, position | keep | `report_card_mark_unique(report_card_id, subject, criterion)` |
| arabic_letter_progress | drill_id, status, mastered_at, group_membership_id, marked_by_user_id | keep | `arabic_progress_student_drill_unique` |
| curriculum_weeks | grade_label, subject, week_no, quarter, focus, standard_code, assessment_note, source_label | keep | curriculum reference data |

### properties · rent_payments

| table | column | action | note |
|---|---|---|---|
| properties | tenant_name | anonymise | a named private individual renting from the masjid |
| properties | address | anonymise | a residential address |
| properties | notes | null | free text |
| properties | name, monthly_rent, is_active, import_batch | keep | |
| rent_payments | check_number | null | a real cheque number off a tenant's account |
| rent_payments | note | null | free text |
| rent_payments | paid_on, amount, payment_method, import_batch | keep | |

### Platform / infrastructure tables

| table | column | action | note |
|---|---|---|---|
| masjid_app_publishing | asc_key_p8 | null | **encrypted** — an App Store Connect private key |
| masjid_app_publishing | asc_key_id | null | **encrypted** |
| masjid_app_publishing | asc_issuer_id | null | **encrypted** |
| masjid_app_publishing | play_service_account_json | null | **encrypted** — a Google Play service account |
| masjid_app_publishing | onesignal_rest_api_key | null | **encrypted** + `$hidden` |
| masjid_app_publishing | onesignal_app_id | null | not a secret (it ships in the client), but nulling it keeps staging from addressing the real OneSignal app |
| masjid_app_publishing | development_team | keep | Apple team id |
| masjid_app_publishing | ios/android/web_account_mode, enabled_platforms | keep | `masjid_id` is UNIQUE on this table |
| provisioning_jobs | callback_token | DROP ROWS | a 64-char shared secret |
| provisioning_jobs | github_repo, artifact_url, detail, job_id | DROP ROWS | `job_id` UNIQUE |
| assistant_feature_requests | summary, details | anonymise | admin-written free text; can quote congregants |
| assistant_feature_requests | user_id, category, status | keep | |
| flyers | content | anonymise | **json** — janazah flyers carry the deceased's name and family details |
| flyers | palette | keep | json colours |
| flyers | source_image_path, cutout_path, rendered_path | null | **private disk** (`FLYER_CUTOUT_DISK`, default `local`) — a janazah portrait |
| flyers | cutout_error | null | error text can echo a filename |
| flyers | uuid, title, status, cutout_status, created_by | keep | `uuid` UNIQUE |
| flyer_templates | key, name, kind, schema | keep | `key` UNIQUE; system templates |
| media | file_name, name, custom_properties | keep | **public** media disk — logos and app icons. Do not truncate; an empty `media` table is what empties the mobile feature drawer |
| media | disk, conversions_disk, mime_type, size, uuid, model_type, model_id | keep | `uuid` UNIQUE |
| masjid_social_media_links | value | anonymise | the `WhatsApp_Number` rows are phone numbers; `UNIQUE(masjid_id, type, value)` so the scrub must stay row-unique |
| donation_links | link, title, message | keep | public donate-page copy |
| announcements / events / services / pages / sections / masjid_abouts | title, details, text, body, content | keep | admin-authored public content. `sections.content` is json — spot-check before trusting it |
| roles / permissions / model_has_roles / model_has_permissions / role_has_permissions | all | keep | no personal data; `Permission::count() === 8` is pinned by a test |
| countries / cities / hadiths / azkar / tasabih / library_* / hadith_categories / azkar_categories | all | keep | reference data |
| prayers / iqama_time_settings / iqama_time_ranges / jumaa_settings / prayer_calculation_settings / theme_settings / app_version_settings / mobile_app_features / masjid_mobile_app_features / contact_reasons / contact_us_reasons / funds | all | keep | configuration and reference data |

---

## Not found

Tables and columns named in the brief that do **not** exist in this schema:

- **`stripe_webhook_events.payload`** — the table has exactly four meaningful columns
  (`stripe_event_id` UNIQUE, `type`, `processed_at`, timestamps). It is an idempotency ledger,
  not an event archive; the raw Stripe payload is never persisted. Nothing to scrub, and the
  table is on the drop list anyway.
- **`notifications.data`** — `notifications` here is a *custom* per-masjid push log
  (`masjid_id`, `title`, `message`, `onesignal_message_id`). It is **not** Laravel's default
  notifications table: there is no `uuid`, `type`, `notifiable_type/id`, `data` or `read_at`.
- **`masjid_users`** — the pivot is `masjid_user`, singular (`private const TABLE = 'masjid_user'`
  in `2026_08_12_030000_create_masjid_user_table.php`).
- **A dedicated device-token / OneSignal player-id / push-subscription table** — none exists.
  Push identity lives entirely on `mobile_app_users` (`device_id` UNIQUE,
  `onesignal_subscription_id` indexed).
- **`group_attachments`** — there are two differently-shaped tables instead:
  `group_post_attachments` (classroom feed) and `group_resources` (staff/parent handouts).
- **Contact 2FA / contact credentials as an auth table** — contacts have no TOTP. Their auth
  surface is `contacts.password` + `contacts.login_email` and the OTP table
  `contact_login_codes`. `contact_credentials` is unrelated: it stores *professional*
  credentials (licences) for a contact, with an encrypted `identifier` and a private-disk scan.
- **A generic `audit_log` / `activity_log` table** — none. `contact_login_events` is the only
  audit-shaped table in the schema.
- **`donations.donor_name`** — no such column; a typed donor name is resolved to a
  `contacts` row (find-or-create) and only `contact_id` is stored.
