<?php

/*
|--------------------------------------------------------------------------
| Staging data scrub — the data map
|--------------------------------------------------------------------------
|
| `php artisan staging:scrub` turns a freshly loaded copy of the PRODUCTION
| database into a safe staging dataset. The command holds the mechanism; this
| file holds the POLICY — which table loses its rows, which column is nulled,
| which column is rewritten and how.
|
| It is a transcription of `artifacts/t040-pii-inventory.md` (2026-09-09), which
| was derived statically from all 167 migrations plus every model's
| `$casts`/`$hidden`. Every row of that document's `action` column appears here:
|
|   - action `DROP ROWS`  -> `drop_rows`
|   - action `null`       -> `null_columns` (or `encrypted_null`, see below)
|   - action `anonymise`  -> `anonymise`
|   - action `keep`       -> `reviewed_keep` when the column name looks like PII,
|                            otherwise documented by OMISSION.
|
| `reviewed_keep` is not decoration. `tests/Feature/StagingScrubCoverageTest`
| walks the real sqlite schema, matches every column against a PII-ish token
| list, and FAILS if a match is neither dropped, nulled, anonymised nor listed
| here with a reason. That is the mechanism that stops a migration shipped six
| months from now from quietly leaking a new personal-data column into staging.
| When you add a column and the suite goes red, decide — do not delete the test.
|
| DETERMINISM. Every fake is derived from the row's own primary key. That buys
| three things at once: unique indexes on email/phone/login_email cannot
| collide, a second run of the command is a no-op rather than a reshuffle, and a
| bug report that says "contact-4182" is traceable back to a prod row id without
| ever storing the person's name.
|
| ADDRESSABILITY. Emails end in `.invalid` (RFC 2606 — the TLD is reserved and
| can never resolve, so a misconfigured mailer cannot deliver) and phones sit in
| the +1 555 01xx fictional range (NANP area code 555 is permanently
| unassignable). The command's verification pass re-reads every such column and
| fails loudly if one value escaped.
|
*/

return [

    /*
    | Rows per UPDATE batch. Prod's biggest tables (`donations`, `contacts`,
    | `group_messages`) are chunked by primary key so one statement never locks
    | a whole table on the staging box's single-core MySQL.
    */
    'chunk' => 5000,

    /*
    | Every staging admin ends up sharing this password (their email becomes
    | `user-<id>@staging.invalid`). It is hashed ONCE per run and written as a
    | literal, not bcrypted per row — bcrypt on ten thousand rows would dominate
    | the runtime. Override in staging's `.env` if you would rather it not be
    | guessable from this file.
    */
    'staging_password' => env('STAGING_SCRUB_PASSWORD', 'staging-only-not-a-real-password'),

    /*
    |--------------------------------------------------------------------------
    | never_write — columns the command must refuse to target
    |--------------------------------------------------------------------------
    |
    | MySQL GENERATED columns. They are not writable at all: an UPDATE naming
    | one is an error, not a silent no-op. They exist because MySQL has no
    | partial indexes, so a conditional unique index is expressed as
    | "generated column + plain unique index" (`.claude/rules/migrations.md`).
    | They follow their SOURCE column automatically, so scrubbing the source is
    | both necessary and sufficient.
    |
    | The command validates its whole write set against this list BEFORE it
    | touches anything, so a future config edit that names one of these fails on
    | the plan rather than half way through a table.
    */
    'never_write' => [
        'masjids.active_owner_user_id' => 'VIRTUAL generated from (user_id, deleted_at); backs masjids_active_owner_unique. Scrub `user_id` — we do not, it is kept.',
        'masjids.active_stripe_account_id' => 'VIRTUAL generated from (stripe_account_id, deleted_at); backs masjids_active_stripe_account_unique. Nulling `stripe_account_id` (below) empties it, and NULL is excluded from the predicate so every row nulling to NULL is safe.',
        'masjid_user.default_key' => 'Generated from (user_id, is_default); enforces one default masjid per user. Both sources are kept.',
    ],

    /*
    |--------------------------------------------------------------------------
    | drop_rows — DELETE FROM, in this order
    |--------------------------------------------------------------------------
    |
    | Ordered CHILD FIRST so foreign keys never have to be disabled. `DELETE`
    | and not `TRUNCATE`: MySQL treats TRUNCATE as DDL, which commits the
    | surrounding transaction and cannot be rolled back, and it refuses outright
    | on a table another table references.
    |
    | The value is the reason, printed by `--dry-run`.
    */
    'drop_rows' => [
        // Broadcast send history: targeting lists (`audience_contact_ids` is a
        // json array of contacts.id), provider message references, and error
        // text that routinely quotes the failing recipient's number.
        'broadcast_deliveries' => 'Provider message ids and error text quoting recipients; cascades from broadcasts, deleted first anyway.',
        'broadcasts' => 'Send history plus explicit audience_contact_ids targeting lists. Staging must start with no sends to replay.',

        // Mobile Contact-Us inbox.
        'contact_us_messages' => 'Free-text inbound messages from app users.',
        'contact_us_accounts' => 'Name/email/phone per mobile app user; mobile_app_user_id is UNIQUE (1:1).',

        // The most sensitive table in the schema. Two of its columns are
        // encrypted, so they could only ever be nulled; nothing references the
        // table, so dropping is strictly better.
        'appointment_request_notes' => 'Encrypted staff notes (body) about an applicant; cascades from appointment_requests.',
        'appointment_requests' => 'Applicant name, phone, email, encrypted date_of_birth and reason, ip, user_agent. Nothing FKs it.',

        // Private-disk attachments. The bytes live under storage/app/private and
        // are NEVER copied to staging (see deploy/staging/DATA-REFRESH.md), so a
        // surviving row points at a file that does not exist and the download
        // endpoints 500 instead of 404. The inventory offers "null the path or
        // delete the row"; deletion is the only option here because
        // `original_name`, `mime_type`, `size_bytes`, `disk` and `path` are all
        // NOT NULL on these three tables — MySQL in strict mode rejects the
        // nulling variant outright.
        'form_response_attachments' => 'Private-disk uploads; the respondent\'s own filename is often "Firstname-Lastname-CV.pdf". All five file columns are NOT NULL, so the row cannot be half-nulled.',
        'group_post_attachments' => 'Private-disk classroom photos of children. All five file columns are NOT NULL.',
        'group_resources' => 'Private-disk staff/parent handouts; per config/groups.php this tree is not covered by any backup target either. All five file columns are NOT NULL.',

        // Live authentication material.
        'contact_login_codes' => 'SHA-256 of a live portal OTP plus requested_ip. Short-lived by design; one-way FK to contacts.',
        'app_signup_codes' => 'Same shape and additionally stores the raw email the code was mailed to.',
        'contact_login_events' => 'The login audit log: actor_name, actor_email, actor_ip, login_email. A RETENTION RECORD in production — never truncate it there; expendable only in the staging copy.',
        'personal_access_tokens' => 'Live Sanctum bearer tokens for real admins and real devices. Polymorphic, nothing FKs it.',
        'password_reset_tokens' => 'Live reset tokens keyed by a real email address — the email IS the primary key.',

        // Send logs and provider ledgers.
        'notifications' => 'The push send-log (title, message, onesignal_message_id). Dropped so a staging admin cannot re-fire a real OneSignal message id and so the in-app inbox starts empty.',
        'sms_suppressions' => 'SAFE ON STAGING ONLY. In production the opt-out deliberately outlives the contact row (.claude/rules/broadcasts.md) and must never be truncated. It is expendable here solely because staging cannot send SMS: no A2P sender, SMS_DRIVER=none, masjid_sms_senders forced to `unregistered` below. If staging ever gains a real provider, MOVE THIS TABLE TO KEEP.',
        'stripe_webhook_events' => 'An idempotency ledger only (stripe_event_id UNIQUE, type, processed_at) — it has no payload column. Dropping is desirable: staging wants its test webhooks processed, not swallowed as duplicates.',
        'provisioning_jobs' => 'callback_token is a live shared secret; github_repo and artifact_url point at real build infrastructure.',

        // Framework runtime state. All of it is regenerated on demand and all of
        // it can hold a snapshot of the very data being scrubbed.
        'sessions' => 'IP addresses, user agents and serialized session payloads including flashed input. Nothing FKs it; everyone simply logs in again.',
        'cache' => 'Cached mobile API payloads — a snapshot of the pre-scrub data.',
        'cache_locks' => 'Lock owners; meaningless off the machine that took them.',
        'jobs' => 'Serialized mail/SMS/push jobs carry their recipients. Also stops staging replaying a real send.',
        'job_batches' => 'Serialized batch options and failed job id lists.',
        'failed_jobs' => 'Serialized payloads plus stack traces, which routinely quote the recipient.',
    ],

    /*
    |--------------------------------------------------------------------------
    | encrypted_null — the ten `encrypted` cast columns
    |--------------------------------------------------------------------------
    |
    | These hold ciphertext produced with production's APP_KEY. SQL cannot
    | re-encrypt, and writing plaintext into them makes every subsequent read
    | throw DecryptException. NULL is the only safe value.
    |
    | Three of the ten sit on tables that `drop_rows` empties. They are listed
    | anyway so this file is a complete answer to "where did each of the ten
    | go?"; the command reports them as covered-by-drop rather than running a
    | pointless UPDATE against an emptied table.
    */
    'encrypted_null' => [
        'users' => ['two_factor_secret'],
        'appointment_requests' => ['date_of_birth', 'reason'],
        'appointment_request_notes' => ['body'],
        'contact_credentials' => ['identifier'],
        'masjid_app_publishing' => [
            'asc_key_p8',
            'asc_key_id',
            'asc_issuer_id',
            'play_service_account_json',
            'onesignal_rest_api_key',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | null_columns — SET <col> = NULL
    |--------------------------------------------------------------------------
    |
    | Every column here is nullable in the schema; the command asserts that
    | before writing, because MySQL in strict mode turns a NOT NULL violation
    | into a failed transaction half way through the run.
    */
    'null_columns' => [

        'users' => [
            'remember_token',           // live "remember me" cookie material
            'two_factor_confirmed_at',  // MUST be nulled with two_factor_secret, or login demands a code nobody can produce
        ],

        'masjids' => [
            'stripe_account_id',    // a live Stripe Connect account. NULL is excluded from masjids_active_stripe_account_unique, so nulling every row is safe; one shared fake acct_… is NOT.
            'tax_id',               // EIN — a real organisation identifier
            'statement_signatory',  // a named person on tax receipts
            'google_maps_key',      // a billable third-party credential
        ],

        'contacts' => [
            'notes',            // free-text staff notes about a congregant
            'password',         // bcrypt of a parent's portal password; NULL = no password set
            'password_set_at',  // must be nulled with `password` or the portal claims one exists
        ],

        'contact_cards' => [
            'note',
        ],

        'contact_credentials' => [
            'notes',
            'document_original_name',   // the uploader's own filename
            'document_path',            // private disk — bytes are not copied to staging
            'document_disk',            // null the whole file tuple together, or documentDisk() is called on a half-row
            'document_mime_type',
            'document_size_bytes',
        ],

        'mobile_app_users' => [
            'onesignal_subscription_id', // the push address of a real handset. Nulling this is what stops staging paging real phones; there is no separate device-token table.
        ],

        'splash_announcements' => [
            'onesignal_iam_id', // a real in-app-message id
        ],

        'donations' => [
            'stripe_payment_intent_id',
            'stripe_checkout_session_id',
            'stripe_charge_id',
            'stripe_balance_transaction_id',
            'stripe_subscription_id',
            'stripe_invoice_id',
            'check_number', // a real cheque number off a donor's account
            'note',         // free-text offline-gift note; often names the donor
        ],

        'donation_receipts' => [
            'payment_reference', // cheque number / external reference. serial_number is KEPT — see reviewed_keep.
        ],

        'donation_subscriptions' => [
            'stripe_subscription_id',  // UNIQUE and nullable — NULL is the only safe scrub
            'stripe_checkout_session_id',
            'stripe_customer_id',      // a real Stripe customer, i.e. a real person
        ],

        'registrations' => [
            'stripe_checkout_session_id',
            'stripe_subscription_id',
            'stripe_subscription_schedule_id',
        ],

        'registration_payments' => [
            'stripe_payment_intent_id',
            'stripe_invoice_id',
            'stripe_charge_id',
            'stripe_balance_transaction_id',
        ],

        'form_responses' => [
            'admin_notes', // staff free text about the respondent
            'device_id',
            'ip_address',
            'user_agent',
            'client_payload_hash', // a keyed digest of the answers, for the double-submit guard; meaningless once `data` is scrubbed
            'stripe_checkout_session_id', // a live Checkout Session on the organisation's account, nulled as registrations and meal_orders null theirs: a NULL opens a fresh page on staging
            'stripe_payment_intent_id',   // a live payment, the same
        ],

        'form_staff_codes' => [
            'bound_device_id', // the phone that claimed a festival staff code — a per-install identifier
        ],

        'masjid_sms_senders' => [
            'phone_number',              // the tenant's real registered A2P 10DLC number
            'messaging_service_sid',     // a live Twilio Messaging Service
            'brand_registration_id',     // a real 10DLC brand registration
            'campaign_registration_id',  // a real 10DLC campaign registration
            'notes',
        ],

        'meal_orders' => [
            'customer_notes', // allergy and other free text
            'stripe_checkout_session_id',
            'stripe_payment_intent_id',
        ],

        'properties' => [
            'notes',
        ],

        'rent_payments' => [
            'check_number', // a real cheque number off a tenant's account
            'note',
        ],

        'masjid_app_publishing' => [
            'onesignal_app_id', // not a secret (it ships in the client), but nulling it keeps staging from addressing the real OneSignal app
        ],

        'flyers' => [
            'source_image_path', // private disk — a janazah portrait
            'cutout_path',
            'rendered_path',
            'cutout_error',      // error text echoes the uploaded filename
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | anonymise — table => column => strategy
    |--------------------------------------------------------------------------
    |
    | Strategies (implemented in App\Support\ScrubStrategies):
    |
    |   first_name   a given name chosen by (id % 16)
    |   last_name    a surname chosen by (id % 16)
    |   full_name    first_name + ' ' + last_name
    |   label:X      "X <id>"
    |   email        "<singular-table>[-<qualifier>]-<id>@staging.invalid"
    |   phone        "+155501" + id zero-padded to 5 (NANP 555 is unassignable)
    |   address      "<id> Staging Way, Springfield, NC 27000"
    |   free_text    a fixed "[scrubbed for staging]" marker
    |   date_shift   shift a date/datetime by ((id % 61) - 30) days
    |   fixed:V      the literal V
    |   digits4      id % 10000, zero-padded to four
    |   device_id    "staging-device-<id>"
    |   password     one bcrypt hash of config('staging_scrub.staging_password')
    |   json_replace replace every VALUE in the json document with a placeholder,
    |                keeping the KEYS (so admin tables still render columns)
    |   json_contacts replace only email-shaped and phone-shaped string values,
    |                recursively, leaving configuration values intact
    |
    | A column may be written as `['strategy' => …, 'where' => …]` when only some
    | rows carry personal data; `where` is a raw fragment that must be valid on
    | both MySQL 8 and sqlite.
    |
    | NULL is preserved by every strategy except `fixed:` — a column that was
    | empty in production stays empty, so "has this contact enabled portal
    | login?" still answers correctly on staging. `fixed:` writes
    | unconditionally because it exists to FORCE a state, not to mask a value.
    */
    'anonymise' => [

        'users' => [
            'name' => 'label:Admin',   // "Admin 12" — a staging admin is identified by their email, not their name
            'email' => 'email',        // users_email_unique — must be row-unique
            'password' => 'password',  // one known hash for every staging admin
            'phone' => 'phone',
        ],

        'masjids' => [
            // `name` is KEPT: UNIQUE + utf8mb4_bin, an organisation name is
            // public directory data, and staging is unusable if you cannot tell
            // the tenants apart. See reviewed_keep.
            'email' => 'email',                        // UNIQUE — row-unique fake so staging never mails a real org inbox
            'phone' => 'phone',                        // UNIQUE **and NOT NULL** — it cannot be nulled, only rewritten
            'stripe_charges_enabled' => 'fixed:0',     // force false so staging cannot believe it may charge
            'stripe_payouts_enabled' => 'fixed:0',
        ],

        'contacts' => [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'phone' => 'phone',                  // feeds PhoneNumber::e164() and the SMS audience resolver — the shape has to stay valid
            'login_email' => 'email',            // UNIQUE(masjid_id, login_email) — row-unique or the UPDATE aborts
            'sms_consent_evidence' => 'free_text', // free text quoting a specific artifact ("web form response #4182")
        ],

        'contact_cards' => [
            'last4' => 'digits4', // UNIQUE(contact_id, last4) — a constant collapses two cards on one contact
        ],

        'mobile_app_users' => [
            'device_id' => 'device_id',        // UNIQUE (utf8mb4_bin) per-install identifier
            'user_agent' => 'fixed:staging',   // NOT NULL, so a constant rather than a null
        ],

        'registration_adjustments' => [
            'reason' => 'free_text', // scholarship reasons quote family circumstances
        ],

        'forms' => [
            // `schema` is field DEFINITIONS and is kept. `settings` can carry
            // notification recipient addresses, so only the address-shaped and
            // phone-shaped values are replaced — blanking the whole document
            // would break form rendering on staging for no extra privacy.
            'settings' => 'json_contacts',
        ],

        'form_responses' => [
            'data' => 'json_replace', // arbitrary tenant-authored answers: names, DOBs, medical notes. A per-key scrub is impossible because tenants author the schema.
            'respondent_name' => 'full_name',
            'respondent_email' => 'email',
            'respondent_phone' => 'phone',
        ],

        'form_staff_codes' => [
            'holder_name' => 'label:Staff', // "Staff 3" — the staff member holding a cash code. The cash totals still group by holder
        ],

        'group_posts' => [
            'body' => 'free_text', // classroom feed text naming children
        ],

        'group_threads' => [
            'subject' => 'free_text', // subjects name the student ("Concern re: <child>")
        ],

        'group_messages' => [
            'body' => 'free_text', // parent<->teacher message text
        ],

        'masjid_sms_senders' => [
            'registration_status' => 'fixed:unregistered', // force the sender un-registered so staging cannot believe it may text
        ],

        'meal_orders' => [
            'customer_name' => 'full_name',
            'customer_phone' => 'phone',
            'customer_email' => 'email',
        ],

        // Academic free text. The numbers around it (marks, attendance counts,
        // hifz ranges) are KEPT — they are the reason staging exists.
        'hifz_entries' => [
            'note' => 'free_text',
        ],
        'behavior_awards' => [
            'note' => 'free_text',
        ],
        'attendance_records' => [
            'note' => 'free_text', // absence reasons quote family circumstances
        ],
        'assignment_scores' => [
            'note' => 'free_text',
        ],
        'lesson_plans' => [
            'reflection_worked' => 'free_text',  // a teacher's post-hoc reflection frequently names a struggling child
            'reflection_improve' => 'free_text',
        ],
        'report_cards' => [
            'teacher_comment' => 'free_text', // the most sensitive free-text field in the school module
        ],
        'report_card_marks' => [
            'comment' => 'free_text',
        ],

        'properties' => [
            'tenant_name' => 'full_name', // a named private individual renting from the masjid
            'address' => 'address',       // a residential address
        ],

        'assistant_feature_requests' => [
            'summary' => 'free_text',
            'details' => 'free_text',
        ],

        'flyers' => [
            'content' => 'json_replace', // janazah flyers carry the deceased's name and family details
        ],

        'masjid_social_media_links' => [
            // Only the WhatsApp_Number rows are phone numbers; the Facebook /
            // Instagram / YouTube / WhatsApp_URL rows are public links worth
            // keeping. UNIQUE(masjid_id, type, value), so the fake stays
            // row-unique via the row id.
            'value' => ['strategy' => 'phone', 'where' => "type = 'WhatsApp_Number'"],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | reviewed_keep — PII-shaped column names that are deliberately NOT scrubbed
    |--------------------------------------------------------------------------
    |
    | Read by StagingScrubCoverageTest. A column whose NAME looks like personal
    | data but whose CONTENT is not must be listed here with a one-line reason,
    | so that "we looked at it and it is fine" is a recorded decision rather than
    | an omission indistinguishable from an oversight.
    |
    | Populated below by `php artisan test --filter=StagingScrubCoverage`.
    */
    'reviewed_keep' => [

        // --- Organisation identity. Public directory data; staging is unusable
        // if you cannot tell one tenant from another.
        'masjids.name' => 'UNIQUE + utf8mb4_bin. An organisation name is public directory data, and every screen in the product is read by asking "which masjid am I looking at?".',
        'masjids.address' => 'The public street address of a place of worship, printed on the mobile app home screen and in Google\'s directory. Not a private residence.',

        // --- Verification timestamps. The token match is on the `email`/`phone`
        // in the column NAME; the value is a timestamp and holds no address.
        'users.email_verified_at' => 'A timestamp, not an address. Kept so the staging login flow behaves like production rather than bouncing every admin into re-verification.',
        'users.phone_verified_at' => 'A timestamp, not a number. Same reason.',
        'masjids.email_verified_at' => 'A timestamp, not an address.',
        'masjids.phone_verified_at' => 'A timestamp, not a number.',
        'meal_menus.collect_customer_email' => 'A boolean toggling whether the lunch order form asks for an address at all. Nulling or flipping it would change which fields staging renders.',

        // --- Reference data shipped with the product. Identical in every
        // installation and in the seeders.
        'cities.name' => 'Reference data from CountriesCitiesSeeder — a city, not a person.',
        'countries.name' => 'Reference data from CountriesCitiesSeeder.',
        'hadith_categories.name' => 'Reference data — the name of a hadith collection category.',
        'mobile_app_features.name' => 'The catalogue of feature tiles in the mobile drawer ("Donate", "Prayer Times"). Product vocabulary.',
        'flyer_templates.name' => 'System flyer template names; `key` is UNIQUE and both are shipped, not tenant-authored.',
        'curriculum_weeks.assessment_note' => 'Curriculum reference data — what a given week of a given subject assesses. Written by the curriculum author, never about a child.',

        // --- Roles and permissions. `Permission::count() === 8` is pinned by a
        // test; renaming either column would break authorisation everywhere.
        'roles.name' => 'Spatie role names (SuperAdmin, MasjidAdmin, teacher, …). Authorisation vocabulary — rewriting it locks every staging admin out.',
        'roles.guard_name' => 'Spatie guard name, always `web`.',
        'permissions.name' => 'Spatie permission names. Permission::count() === 8 is pinned by a test.',
        'permissions.guard_name' => 'Spatie guard name, always `web`.',

        // --- Tenant-authored PUBLIC copy. Written by an admin for publication;
        // scrubbing it makes staging a set of empty screens.
        'groups.name' => 'A class or programme name ("Hifz — Level 2"). UNIQUE(masjid_id, slug) alongside it.',
        'offerings.name' => 'A public programme name on the registration page.',
        'funds.name' => 'A donation fund name ("Zakat", "Masjid Expansion"). It appears on every receipt and in every ledger filter.',
        'forms.name' => 'The public title of a form. `schema` beside it is field DEFINITIONS, also kept; the ANSWERS in form_responses.data are what gets scrubbed.',
        'contact_reasons.name' => 'An admin-managed picklist label behind the contact-us dropdown.',
        'donation_links.message' => 'The blurb on a public donate page, written for publication.',
        'splash_announcements.body' => 'Admin-authored announcement copy shown to every app user. Public by construction; `onesignal_iam_id` beside it IS nulled.',
        'app_version_settings.update_message' => 'The "please update" banner copy shown to every app user.',
        'app_version_settings.maintenance_message' => 'The maintenance banner copy shown to every app user.',
        'meal_menu_items.name' => 'A dish on a public lunch menu.',
        'meal_menu_items.name_ar' => 'The same dish in Arabic.',
        'meal_order_items.item_name' => 'A snapshot of the menu item at order time — what was bought, not who bought it. The buyer\'s name, phone and email on meal_orders are all anonymised.',
        'meal_menus.notes' => 'Operational notes on a public menu ("collect at the side door"). Kept because the pickup flow reads it; if a tenant is ever found using it for customer names, move it to `anonymise` with `free_text`.',
        'properties.name' => 'The masjid\'s own label for a rental unit ("Unit B, 12 Elm"). The person renting it is properties.tenant_name, which IS anonymised, and properties.address, which IS anonymised.',
        'sections.content' => 'JSON page content an admin composed for the public website. Kept so staging renders real pages; the inventory flags it for a spot-check rather than a blanket scrub because it is publication copy.',
        'lesson_plans.body' => 'Curriculum content for a lesson, UNIQUE(group_id, session_date). The two columns on this table that name children — reflection_worked and reflection_improve — ARE anonymised.',
        'contact_credentials.issuing_body' => 'Who issued a professional credential ("State of North Carolina"). An institution, not a person; the licence NUMBER beside it is an encrypted column that is nulled.',

        // --- Spatie media library. This is the logo and app-icon estate on the
        // PUBLIC disk. Emptying it is what wiped the mobile feature drawer on
        // 2026-08-28 — see the incident in root memory.
        'media.name' => 'Public media-library disk: logos, app icons, announcement images. Wiping this table or its names empties the mobile app\'s feature drawer.',
        'media.file_name' => 'The on-disk filename of a public logo or icon; the bytes ARE copied to staging.',
        'media.collection_name' => 'The Spatie collection a file belongs to ("logo", "gallery"). Structural, not personal.',

        // --- JSON explicitly audited as carrying no personal data.
        'prayers.prayers_data' => 'Prayer times for a masjid — astronomy, not people. Audited in artifacts/t040-pii-inventory.md section (c).',
        'prayers.iqama_times_data' => 'Iqama offsets. Audited in the same section.',
        'prayers.jumaa_data' => 'Jumaa slot configuration. Audited in the same section.',

        // --- Documented here even though the token list does not flag it,
        // because getting it wrong is expensive.
        'donation_receipts.serial_number' => 'A GAP-FREE per-masjid sequence allocated by ReceiptService, UNIQUE(masjid_id, serial_number). Never renumber and never delete rows: a hole is something the allocator\'s unique index then fights. The receipt\'s payment_reference IS nulled.',
    ],
];
