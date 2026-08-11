---
paths:
  - "app/Services/Broadcast/**"
  - "app/Models/Broadcast.php"
  - "app/Models/BroadcastDelivery.php"
  - "app/Enums/BroadcastChannel.php"
  - "app/Enums/BroadcastAudience.php"
  - "app/Http/Controllers/AdminDashboard/BroadcastsController.php"
  - "app/Http/Controllers/Mobile/SignageController.php"
---
# The unified publish composer (T-008)

One compose action → the announcements feed, push, the tvOS signage board and
email. Fragmented communication is the loudest complaint in this market
(`docs/recon-2026-08-11.md`): admins retype the same paragraph into four places
and congregants still hear about the event afterwards. Manara already owned all
four channels separately; this is the one action that reaches them.

## It ORCHESTRATES — it never replaces

Every channel keeps its own endpoint, its own model and its own behaviour.

| Channel | What the driver actually does |
|---|---|
| `announcement` | Creates an ordinary `announcements` row + flushes `MobileCache::ANNOUNCEMENTS` — same table, same media collection, same public feed |
| `push` | Creates an ordinary `notifications` row and dispatches the existing `SendMasjidNotificationJob` (which owns OneSignal, its retries and its backoff) |
| `signage` | Publishes to the board, which is a PULL surface served by `GET /api/mobile/masjids/{id}/signage` |
| `email` | Sends `BroadcastMail` through the app's existing mail path to CRM contacts |

Rules that follow from that:

- **Never reimplement a channel here.** If announcements gain a field or a rule,
  the composer inherits it. `StoreBroadcastRequest` VALIDATES the announcement
  leg by running `StoreAnnouncementRequest::rules()` itself, so the two cannot
  disagree about what a valid announcement is.
- **Never change an existing channel endpoint to suit the composer.**
  `tests/Feature/Broadcasts/BroadcastChannelRegressionTest.php` exercises the
  announcements, push and mobile-feed endpoints and fails if their status codes,
  envelopes or rows move.
- The composer mints **no permission**. `Permission::count() === 8` stays pinned
  (`.claude/rules/auth-permissions.md`, `StaffAuthGuardPinTest`).

## THE invariant: a failing channel never rolls back a successful one

The fan-out is **not** wrapped in a transaction, and it must never become one.

A push accepted by OneSignal is on ten thousand lock screens; an email accepted
by the relay is in somebody's inbox. Neither is undoable. A transaction that
"rolled back" on a later failure would roll back only *our record* of what
happened — leaving a database claiming nothing was sent while congregants read
the message, and an admin who then sends it a second time. Partial success is
therefore a **first-class, visible state** (`broadcasts.status = partial`), not
an error to be smoothed away.

- Each `broadcast_deliveries` row is committed on its own.
- `BroadcastDispatcher` catches `Throwable` per channel and continues.
- Re-dispatching is safe: only `pending` and `failed` deliveries are re-attempted,
  so a retry can never double-send a channel that already went out.
- The one thing that IS atomic is composition (the broadcast row + its pending
  delivery rows), because nothing has left the building yet.

`skipped` is not a failure. No registered devices, or an audience where nobody
has an email address, is a fact the admin needs to see — not a red error.

## Authorization is decided UP FRONT; delivery outcomes are per-channel

The endpoint sits under `auth:sanctum + admin + tenant`, deliberately OUTSIDE the
`crm` group and with no `permission:` gate — the Flyer Studio's reasoning
(`routes/admin.php`): broadcasting a notice is content authoring, not the CRM
money path, and gating it on `masjids.crm_enabled` would take announcements and
push away from every masjid that has not bought the CRM.

The exception is the **email** channel, the only one whose recipients come from
`contacts`. Selecting it requires `crm_enabled` **and** `view contacts`, checked
before any channel runs, answering 403 for the whole request. That is not a
contradiction of per-channel isolation: authorization must be knowable in advance
and all-or-nothing, while a delivery outcome is discovered by trying.

The check loops over `BroadcastChannel::readsContacts()` rather than testing for
`EMAIL`, so any future contact-reading channel inherits it.

## Audiences: only what the data supports

- `everyone` — every subscribed device (push), every non-placeholder contact with
  an email (email).
- `contacts` — an explicit set of contact ids, **snapshotted** onto the broadcast
  so a later directory edit cannot rewrite who was addressed.

**Push + a contacts audience is REJECTED at the request boundary.**
`mobile_app_users` has `device_id` and `onesignal_subscription_id` and no
`contact_id` — there is no join from a person to their phone. Accepting the
combination and broadcasting to every device would tell an admin they had sent
something narrow when they had not. Do not "fix" this by widening it; fix it, if
ever, by giving devices an identity.

A `group` audience is the natural third case and is deliberately absent: group
audiences carry guardian-consent rules (`.claude/rules/groups.md`) that deserve
their own task rather than a quiet inheritance.

## Adding a channel

1. A case on `App\Enums\BroadcastChannel` (+ `isAddressable()` / `readsContacts()`).
2. A class implementing `BroadcastChannelDriver`.
3. One line in `BroadcastDispatcher::DRIVERS`.

No migration: `broadcast_deliveries.channel` is a plain string, precisely so a new
channel is never `ALTER TABLE … MODIFY` on a live table
(`.claude/rules/migrations.md`).

## SMS: what exists, and what adding it actually requires

**Nothing exists.** As of this slice there is no SMS provider anywhere in the
application: no package in `composer.json`, no block in `config/services.php`, no
variable in `.env.example`. There is therefore **no `Sms` case and no SMS
driver**, and adding a stub would be actively harmful — an admin who believes a
snowstorm cancellation went out by text when it did not is worse off than one who
can see the channel is unavailable.

Wiring one up needs all five of these, and the last two are the ones that get
forgotten:

1. **A provider + SDK** — Twilio, Vonage, MessageBird, Telnyx or similar, added
   to `composer.json` and configured in `config/services.php` alongside the other
   third-party credentials, env-driven and never hardcoded.
2. **Credentials, machine-to-machine** — account SID / API key + secret, in the
   same shape as the Stripe and OneSignal blocks. Unset must fail soft with a
   clear "not configured" message, the way GitHub dispatch and Google geocoding
   already do.
3. **A per-tenant sender identity** — a shared long code is how a fleet gets its
   whole account blocked. Each masjid needs its own originating number or
   alphanumeric sender, provisioned and stored per-tenant, exactly as OneSignal
   apps are (`masjid_app_publishing`). In the US this now means **A2P 10DLC brand
   and campaign registration per organisation**, which is an onboarding step with
   a human in it, not a config value.
4. **Consent, which the schema does not currently record.** `contacts` has
   `phone`, and that is all — there is **no opt-in column, no consent timestamp
   and no source-of-consent field**. A phone number captured on a school
   admissions form is not consent to receive bulk announcements. Bulk SMS needs
   an explicit, dated, auditable opt-in per contact per purpose, and the audience
   resolver must filter on it, not merely on `phone IS NOT NULL`.
5. **Opt-out, honoured automatically and forever.** STOP/UNSTOP handling via an
   inbound webhook, a suppression list that survives re-import and contact merge
   (`Contact::merge` must carry it), and the required help/opt-out language in the
   message body. Under the TCPA an unhonoured opt-out is a per-message statutory
   liability for the organisation, not a support ticket.

Until 4 and 5 exist as columns and code, an SMS driver would be a compliance
hazard wearing a feature's clothes. The seam is ready; the obligations are not.
