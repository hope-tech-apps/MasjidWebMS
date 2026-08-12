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
four channels separately; this is the one action that reaches them. T-009 added a
fifth, SMS — the channel T-008 deliberately left out until the consent
obligations underneath it existed; see the SMS section at the end.

## It ORCHESTRATES — it never replaces

Every channel keeps its own endpoint, its own model and its own behaviour.

| Channel | What the driver actually does |
|---|---|
| `announcement` | Creates an ordinary `announcements` row + flushes `MobileCache::ANNOUNCEMENTS` — same table, same media collection, same public feed |
| `push` | Creates an ordinary `notifications` row and dispatches the existing `SendMasjidNotificationJob` (which owns OneSignal, its retries and its backoff) |
| `signage` | Publishes to the board, which is a PULL surface served by `GET /api/mobile/masjids/{id}/signage` |
| `email` | Sends `BroadcastMail` through the app's existing mail path to CRM contacts |
| `sms` | Texts the CONSENTING part of the CRM contact audience, from the tenant's own registered A2P 10DLC sender, through a provider adapter (T-009) |

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

The exception is the channels whose recipients come from `contacts` — **email**
and, since T-009, **SMS**. Selecting either requires `crm_enabled` **and**
`view contacts`, checked before any channel runs, answering 403 for the whole
request. That is not a contradiction of per-channel isolation: authorization must
be knowable in advance and all-or-nothing, while a delivery outcome is discovered
by trying.

The check loops over `BroadcastChannel::readsContacts()` rather than testing for
`EMAIL`, so any future contact-reading channel inherits it. SMS is the proof that
this was worth doing: it picked up the gate by answering `true` to that one
predicate, and `BroadcastsController` did not change.

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

## SMS (T-009): the channel, and the obligations underneath it

This section used to explain why SMS was deliberately absent and list the five
things wiring it up would require. All five were built, so it now records the
rules instead. **The consent infrastructure is not part of the channel — it sits
BENEATH the channel**, in `contacts`, `sms_suppressions` and
`masjid_sms_senders`, and it is what makes the channel legal to operate rather
than merely functional.

The channel itself really was three lines: an enum case, a driver, one entry in
`BroadcastDispatcher::DRIVERS`. Everything below is what had to exist first.

### THE INVARIANT: a phone number is not consent

**The audience resolver filters on a CONSENT RECORD, never on
`phone IS NOT NULL`.** A contact with a number and no consent is `skipped` from
the count — never texted, never counted as a failure. That is the whole reason
this channel did not ship with T-008: a number captured on an admissions form is
a fact about a person, not a decision they made.

Consent is a record with four parts (`contacts`, added by
`add_sms_consent_to_contacts_table`):

| Column | Meaning |
|---|---|
| `sms_opt_in` | the affirmative; `false` for every row that predates the column |
| `sms_consent_at` | WHEN — server time, never client-supplied |
| `sms_consent_source` | HOW — a constant from `Contact::SMS_CONSENT_SOURCES` |
| `sms_consent_evidence` | the specific artifact ("web form response #4182") |
| `sms_opted_out_at` | a MIRROR of the suppression list, for display |

`Contact::hasSmsConsent()` requires **all** of the first three plus a null
opt-out. A flag with no timestamp and no source — the shape a careless bulk
UPDATE produces — reads as NO consent. Under the TCPA the organisation carries
the burden of proving prior express written consent; "opt_in = 1" proves nothing.

The source is a **constant set** rather than free text because free text produces
forty spellings of "website" and cannot answer "show me everyone whose consent
came from the admissions form" three years later, which is the question a demand
letter asks. The free-text `evidence` sits beside it: the constant makes consent
queryable, the evidence makes it provable. Both, not either. The column is a
plain string, so adding a source is never `ALTER TABLE … MODIFY`
(`.claude/rules/migrations.md`).

`sms_reply_start` is the one source this application writes by itself, from the
inbound webhook. **An admin may not claim it** (`StoreSmsConsentRequest`
excludes it) — it means the subscriber texted START from their own handset.

### The opt-out OUTLIVES the contact row

`sms_suppressions` is keyed on `phone_e164` and has **no foreign key to
`contacts`**. That is the entire design:

- `ContactsController::merge` `forceDelete()`s the absorbed contact;
- the donation importer mints and destroys placeholder contacts;
- a CSV re-import happily recreates somebody deleted last month.

Every one of those would resurrect a number that said STOP as a clean,
messageable record if the opt-out lived on the contact row. It does not. **A
suppression cannot be defeated by editing the directory**, and
`SmsConsentService::grant()` refuses to consent a suppressed number at all —
only the subscriber can undo it, by texting START back.

Rows are **released, never deleted**. A START stamps `released_at` and leaves the
row standing: the history of an opt-out is the evidence it was honoured, and the
unique index over `(masjid_id, phone_e164)` makes a re-STOP an update rather than
a second contradictory row.

Suppression is **per tenant**, because consent is: STOP is a reply to one
registered number, each masjid has its own, and unsubscribing from your masjid
was never unsubscribing from the school across town.

### Merge takes the MORE RESTRICTIVE state, and only when the numbers match

`SmsConsentService::reconcileOnMerge()` runs inside the merge transaction, before
the force-delete. The rule, and it is deliberately not "transplant the source's
consent":

- **Different numbers (or either missing): nothing transplants.** Consent was
  given for the SOURCE's number. Moving it onto a survivor carrying a different
  number would manufacture permission to text somebody who never gave it — the
  single most damaging thing that code could do.
- **Same number: the survivor takes the more restrictive state.** An opt-out on
  either side wins and keeps the EARLIER date. Only if neither opted out, and the
  survivor has no consent of its own, does the source's record move across —
  with its ORIGINAL timestamp and source, because a merge is not a new act of
  consent and re-stamping it "now" would fabricate provenance.
- **Always:** the survivor is re-checked against the suppression list.

### A tenant with no approved sender CANNOT send

`masjid_sms_senders` holds one row per masjid: its number or Messaging Service,
its 10DLC brand/campaign ids, and a `registration_status`. Only `approved` sends.
`pending` does not — "the paperwork is in" is not carrier permission, and the gap
is measured in days.

**There is no shared fallback number anywhere in this feature, and there must
never be one.** Putting several tenants' unregistered traffic on one long code
gets the number filtered and then the whole provider account suspended, taking
SMS away from every organisation on the platform including the ones that did it
properly. A missing sender is a `failed` delivery with a sentence naming 10DLC
registration — not a skip, and not a silent fallback.

Registration state is recorded by a **SuperAdmin** (`PUT
/masjids/{id}/sms-sender`, `super` middleware), never by a masjid admin: it is a
commercial act performed on the organisation's behalf using the platform's
provider account, and a self-serve "we're approved" toggle is exactly how
unregistered traffic reaches the carriers.

### Required message content is CODE, not documentation

`SmsBodyComposer` prepends the sender identity and appends the opt-out sentence
to **every** outbound message. An admin cannot compose them away, and when the
body exceeds `services.sms.max_body_length` it is the ADMIN'S TEXT that is
truncated — never the identity, the link, or "Reply STOP to unsubscribe."

### Inbound STOP/START: signature-verified and FAIL CLOSED

`POST /api/sms/webhook` (`routes/api.php`), outside auth and throttle like the
Stripe webhook, gated only by the provider's HMAC signature. **No signing token
configured ⇒ every request is rejected**, including opt-outs.

That asymmetry is deliberate. The endpoint also accepts opt-IN keywords, so an
unverified version would let anybody re-subscribe a number that opted out —
turning the compliance machinery into the violation. It is outside throttle for
the same reason: a rate-limited opt-out is an unhonoured opt-out, which is a
per-message statutory liability for the organisation. The handler is idempotent
(suppressing a suppressed number updates one row), so provider retries are free
and no dedup table is needed.

Keywords are matched on the **whole trimmed body**, case-insensitively, the way
carriers match them — a substring match would suppress somebody who wrote "please
don't stop the announcements". The tenant is resolved from the `To` number,
unbound, which is why an approved sender's `phone_number` must be stored in
E.164 even when it sends through a Messaging Service.

The webhook answers an empty TwiML document and **composes no reply**. The
carrier-mandated STOP and HELP auto-replies are the provider's Advanced Opt-Out,
configured once by the operator on the Messaging Service.

### Provider seam

`App\Services\Sms\SmsProvider` — `TwilioSmsProvider` (the real one),
`NullSmsProvider` (the default when nothing is configured: it REFUSES, it never
reports a phantom send) and `LogSmsProvider` (opt-in local development). The
Twilio adapter speaks the provider's **HTTP API through Laravel's `Http` client
rather than `twilio/sdk`**: the whole need is one form-encoded POST, and the SDK
would add thousands of files to `composer.lock` while taking away `Http::fake()`.
No dependency was added for this feature.

`SMS_DRIVER` is `none` in `phpunit.xml` and `.env.testing`, and unset everywhere
else. **No test can put a message on a carrier network**, and an unconfigured
deployment fails soft on the request that asked to send — it never errors at
boot.

The value is `none` and not `null` because `env()` converts the literal string
"null" to PHP null, which would read as unset and silently re-enable provider
auto-detection.

### What an operator MUST do before a tenant can send its first message

Everything here is outside the application on purpose; steps 1–4 involve a human
and a carrier, and none of them can be automated away.

1. **Provider account.** Create the account, then set `TWILIO_ACCOUNT_SID` and
   `TWILIO_AUTH_TOKEN` in the environment. Leave `SMS_DRIVER` unset — the factory
   selects Twilio once the credentials are present.
2. **A2P 10DLC BRAND registration for the organisation**, in the provider console:
   legal business name, EIN/business number, address, an authorised contact.
   Days, and it can be rejected.
3. **A2P 10DLC CAMPAIGN registration** against that brand, describing the use
   case (announcements/notifications), with sample messages that **include the
   sender identity and the opt-out sentence** this application generates, and a
   link to the organisation's published privacy policy and message disclosure.
4. **A Messaging Service** with the organisation's number in its pool, attached
   to the approved campaign, with **Advanced Opt-Out enabled** so the mandated
   STOP/HELP auto-replies are sent provider-side.
5. **Point the Messaging Service's inbound webhook at
   `POST {app}/api/sms/webhook`** (`route('sms.webhook')`). If a proxy rewrites
   the URL, also set `TWILIO_WEBHOOK_URL` to the exact public URL, or every
   delivery fails signature verification.
6. **Record the outcome** with `PUT /api/admin/masjids/{id}/sms-sender`
   (SuperAdmin): the E.164 `phone_number`, the `messaging_service_sid`, the
   `sender_label` the carriers approved, the brand/campaign ids, and
   `registration_status: approved`. Only now can the tenant send.
7. **The organisation collects consent itself**, per contact, and records it via
   `POST /api/admin/masjids/{id}/contacts/{contact}/sms-consent` with the source
   and the evidence. There is no bulk "opt everyone in" path and there must never
   be one.

Steps 2–4 are commercial, take days, and can be rejected or later suspended;
until step 6 the channel refuses every send with a message that says so.
