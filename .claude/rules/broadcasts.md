---
paths:
  - "app/Services/Broadcast/**"
  - "app/Jobs/SendBroadcastJob.php"
  - "app/Models/Broadcast.php"
  - "app/Models/BroadcastDelivery.php"
  - "app/Enums/BroadcastChannel.php"
  - "app/Enums/BroadcastAudience.php"
  - "app/Http/Controllers/AdminDashboard/BroadcastsController.php"
  - "resources/vue-app/views/dashboard/broadcasts/**"
  - "resources/vue-app/stores/masjid/broadcastsStore.ts"
  - "app/Http/Controllers/Mobile/SignageController.php"
---
# The unified publish composer (T-008)

One compose action → the announcements feed, push and email (and, on the server
only, a signage address no TV app reads: see "Signage reaches no screen" below).
Fragmented communication is the loudest complaint in this market
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
| `signage` | Publishes at `GET /api/mobile/masjids/{id}/signage`, a PULL address **no TV app asks for**. Not offered by the composer since 2026-10-03 |
| `email` | Sends `BroadcastMail` through the app's existing mail path to CRM contacts. The greeting prints a contact's first name only through `MailGreeting` |
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
- Ordinary completed failed/partial sends retain their existing per-channel
  retries. Recovered claims, interrupted broadcasts, and any sending, interrupted
  or not-sent delivery refuse the whole claim, including a stale direct dispatch.
  Unknown outcomes are never called failed or reopened for automatic replay.
- The one thing that IS atomic is composition (the broadcast row + its pending
  delivery rows), because nothing has left the building yet.

`skipped` is not a failure. No registered devices, or an audience where nobody
has an email address, is a fact the admin needs to see — not a red error.

## Scheduled cancellation (2026-10-06)

A future schedule dispatches a delayed `SendBroadcastJob` carrying the broadcast
ID (`BroadcastComposer::send`); no scheduler sweep sends messages. Jobs remain queued
after cancellation. Removing a queue entry is never the guarantee.

- `POST /api/admin/masjids/{masjid_id}/broadcasts/{broadcast_id}/cancel` shares
  the existing auth:sanctum, admin, tenant and capability:broadcasts middleware.
  It adds no permission or contact-reading gate. Scoped foreign IDs are 404,
  including for a SuperAdmin acting inside an organisation's route.
- `BroadcastCancellation` and `BroadcastDispatcher` both re-read the broadcast
  with `lockForUpdate()` inside a short transaction. Eligibility is decided on
  THAT row: status exactly `scheduled`, non-null scheduled_at, whatever the clock
  says, and every delivery is untouched pending (zero target count and null
  references, note, error and delivered_at). Outcomes are freshly read with a
  locking read under the parent lock. Old workers could settle channels while
  leaving the parent scheduled; refuse these rows with "This broadcast cannot be
  cancelled because an earlier attempt is recorded. Some channels may already
  have gone out; check the channel outcomes." Do not rewrite outcomes or audit
  on refusal. Untouched due/overdue rows remain cancellable until the send claim.
- Cancel commits status `cancelled`, server-derived `cancelled_by_user_id` and
  `cancelled_at`, and changes pending delivery rows to `cancelled`. Audit columns
  are nullable with no backfill; the user FK follows the creator's nullOnDelete
  convention. Staging drops the broadcast history, including this audit.
- Dispatch commits status `sending`, `sending_started_at` and a claim token
  BEFORE entering any driver. A cancelled, sending, interrupted or recovered
  row returns without entering any channel; an older job/model copy
  cannot override the decision. Once claimed, cancel returns 409 and fan-out
  continues normally. Never put the channel loop inside this transaction.
- Cancel winning the lock prevents every channel. Send winning it prevents
  cancellation of a partially delivered message. Repeat cancellation returns
  409 with "This broadcast is already cancelled. Nothing will be sent." and
  preserves the original actor/time. Other refusals carry an actionable sentence.
- List, detail, compose and cancel responses include server `cancellable` and
  nullable cancellation audit keys. The predicate uses the already eager-loaded
  deliveries; never add a prior-attempt query per history row. The SPA shows Cancel only from this flag,
  asks "Cancel scheduled broadcast?" / "This broadcast will not be sent on any
  channel.", with "Yes, cancel broadcast" and "Keep scheduled". It blocks
  repeat taps, displays the server message, and refreshes after success/refusal.
  History shows Sending or Cancelled; cancelled deliveries show cancelled.
- Immediate-send payloads preserve their existing shape, including absence of
  blocks without a layout. Failed/partial per-channel retries retain the existing
  dispatcher behavior for normally completed sends. Interruption recovery is
  terminal (below), including when the failed/partial summary can be derived.

Release ordering matters: stop/drain old queue workers before exposing cancel,
apply the nullable migration and new code, rebuild route/config caches and start
workers on the new dispatcher. An old worker does not understand cancelled.
Do not roll back to a dispatcher without the guard while cancelled delayed jobs
remain: that would reopen the send door. No release is performed by this change.

Tags addressed by `scheduled` OR `sending` broadcasts cannot be deleted: each
channel resolves its audience separately, so committing the claim does not free
the tag. Settled/cancelled rows release this guard. Live consent, suppression,
service interests and tag membership still apply at channel time. The newsletter
rollback inventory in deploy/README.md includes sending too; drain workers before
holding deliveries or changing code. Other reader verdicts: DECISIONS.md, pre-ship
review fixes (2026-10-06).

## Interrupted sends (2026-10-07)

A delivery commits `sending` under the parent's row lock BEFORE entering its
channel driver. Results still commit independently. On recovery, completed rows
retain every result; `sending` becomes `interrupted` (outcome unknown), and pending
channels become `not_sent` (never started). Nothing is delivered during recovery.
`BroadcastDispatcher::rollupStatus` remains the only summary derivation: unknown
or not-sent rows produce Interrupted. A recovered claim whose results were all
recorded is also Interrupted, except that the existing success/failure derivation
may honestly show Partly sent. `send_recovered_at` fences replay in that case.
Ordinary completed sends retain their existing sent/partial/failed rules.

- `SendBroadcastJob` serializes a claim token BEFORE handle. Laravel calls
  `failed()` on a fresh deserialized job, so an identity assigned during handle
  would be lost. `failed()` settles only its own still-sending claim; an unrelated
  failure cannot claim another worker's outcome. `failOnTimeout` is explicit.
  Jobs serialized before this change lack the token and wait for the sweep.
- `broadcasts:settle-interrupted`, every five minutes with withoutOverlapping,
  settles only sending claims strictly older than 900 seconds: three times the
  300-second timeout and 2.5 times the database queue's 360-second retry_after.
  Time and status are rechecked under the same parent lock as dispatch/cancel.
  One monitors info line per run records settled/failure counts; it does not
  alert an admin or send a message. A stopped scheduler cannot recover claims.
- Immediate sends run synchronously and can outlive the queue timeout. A
  broadcast-specific, non-expiring `flock` therefore spans fan-out. Recovery
  skips a live process even beyond the age bound; SIGKILL releases its lock.
  The application, queue and scheduler must share this project's single host
  and `storage/framework/broadcast-send-locks`. Never delete/replace active lock
  files: their inode is the liveness proof. They are outside cache/data so cache
  clearing does not replace them. Recovery opens existing files read-only,
  so a root cron can check files owned by the PHP/queue user without changing
  ownership. Recovery creates no files. A new claim with a missing lock inode
  fails closed; investigate missing storage rather than guessing it is dead.
  Moving workers/scheduler to another host requires a distributed liveness guard.
- The nullable migration has no backfill. A legacy sending row's pending channel
  might already have sent externally, so recovery marks it interrupted, never
  not sent. Its minimum age uses updated_at because no claim time was recorded.
  Drain/stop old workers AND old synchronous requests before enabling the sweep:
  those processes have neither durable channel starts nor the new process lock.
  Never restore an old dispatcher while recovered claims remain. Migration down
  refuses to erase existing recovery evidence. No release is performed here.
- Repeated/delayed jobs and direct dispatch refuse interrupted or recovered
  parents and any delivery with sending/interrupted/not_sent status, before any
  driver runs. Conditional result writes and a locked final rollup cannot
  overwrite a recovery outcome. The admin must compose a NEW broadcast if they
  want to send again, after checking every channel. Cancel is a 409 with:
  "This broadcast was interrupted and cannot be cancelled. Check each channel
  before composing a replacement." Terminal rows release the tag deletion guard:
  they no longer resolve an audience. Existing scheduled/sending guards remain.
- History cards show Interrupted; channels show sending, interrupted — outcome
  unknown, or not sent. Unknown/not-sent outcomes have no misleading (0) count.
  The same per-channel states/notes appear in the detail API; the current SPA
  displays outcomes in history cards and has no separate detail route.

Coverage: `BroadcastCancellationTest`, `BroadcastInterruptionTest`,
`BroadcastWorkerDeathTest` (real local SIGKILL at three boundaries and a real
Laravel worker timeout shortened to one second), mounted
`broadcast-cancel-screen.test.ts` (actual store), mysql-group
`BroadcastInterruptionMysqlTest` under tests/Mysql (column widths/nullability and
parent/outcome locking reads), and existing `BroadcastCancellationLocksTest`
under tests/MysqlLocks. MySQL tests were not executed locally. SQLite/process
checks do not prove InnoDB locking, real provider delivery, or deployed browser
behavior.

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

## Channels follow the organisation's MODULES, at compose AND at delivery

Two channels write into data a SuperAdmin can switch off per organisation
(`config/capabilities.php`, kind `module`; DECISIONS.md 2026-09-16):
`BroadcastChannel::requiresModule()` answers `announcement` → `announcements`
and `push` → `push_notifications`, and null for signage, email and SMS. It is an
exhaustive match with no default arm, so a new channel cannot be added without
someone deciding which module it writes into.

- **Compose:** `authorizeChannels()` refuses a channel whose module is off with
  a 403 naming the module's catalogue label, BEFORE the CRM checks and before
  anything is written. The whole request is refused, same all-or-nothing rule
  as the contacts gate.
- **Delivery:** `AnnouncementChannel::deliver` and `PushChannel::deliver` return
  `skipped` with a sentence, before creating their row, when the module is off.
  That is what catches a send SCHEDULED before the switch was flipped:
  `SendBroadcastJob` reaches `deliver()` long after the compose-time check ran.
  Push skips before the `notifications` row too, because that row IS the in-app
  inbox entry the Notifications module owns.
- **No SuperAdmin bypass.** The composer agrees with the organisation's own
  menu; a SuperAdmin who wants to post an announcement for an organisation that
  has Announcements off switches it back on first. Do not "fix" this.
- **Fail-open, always through `Masjid::moduleIsOff()`, never `hasCapability()`.**
  These checks run in plain PHP, not route middleware, so they go live the
  moment `git merge` lands while production can still hold the previous config
  cache. `moduleIsOff` reads a key the loaded config does not know as a module
  as ON; `hasCapability` would read it as OFF and refuse every organisation's
  announcements until the Caches step. `ModulesFailOpenTest` pins it.
- The composer SPA hides a switched-off channel for everyone, so the 403 is the
  boundary, not the usual experience.

## Audiences: only what the data supports

- `everyone` — every subscribed device (push), every non-placeholder contact with
  an email (email).
- `contacts` — an explicit set of contact ids, **snapshotted** onto the broadcast
  so a later directory edit cannot rewrite who was addressed.

- `service` — everyone who asked to hear about ONE service, reaching their
  phones. Added 2026-09-08.

**Push + a CHOSEN LIST of contacts is still REJECTED at the request boundary**,
but the reason has changed and the old one is no longer true. Devices now carry
`mobile_app_users.contact_id` — the "give devices an identity" fix this rule used
to prescribe — set when a member signs in and cleared on sign-out. What has not
changed is that the app is usable WITHOUT an account, so most rows are NULL
forever. An admin who hand-picks fifty contacts and reaches the six who happen to
be signed in has been told they sent something they did not send: the same
failure the original refusal prevented, pointing the other way.

A `service` audience does not have that problem, which is why it is allowed:
it addresses people who opted in THROUGH the app, so holding an account on a
device is intrinsic to the audience rather than an accident that silently
shrinks it.

**The service is snapshotted; its people are not.** `audience_contact_ids`
freezes a chosen list so a later directory edit cannot rewrite who was addressed.
An interest is an OPT-IN, so `audience_service_id` stores only WHAT was addressed
and `BroadcastAudienceResolver` answers WHO at send time — the same principle as
SMS filtering on the consent record rather than on `phone IS NOT NULL`. A member
who withdrew their interest between composing and dispatching is not reached.

The resolver drops four kinds of non-recipient, and each is a way to be wrong:
unclaimed (guest) handsets, members interested in a different service,
soft-deleted contacts (`contact_id` is `nullOnDelete`, which a SOFT delete does
not trigger), and revoked contacts. Pinned by
`tests/Feature/ServiceAudiencePushRoutingTest.php`.

**The AUDIENCE can need the contact directory even when no channel does.**
`BroadcastAudience::readsContacts()` mirrors `BroadcastChannel::readsContacts()`,
and `authorizeChannels()` checks both — so a `service` audience on push inherits
the `crm_enabled` + `view contacts` gate rather than bypassing it, even though
push reads no contacts on its own and no individual contact is ever disclosed.

- `tag` — everyone carrying one CONTACT TAG (App\Models\ContactTag), added
  2026-09-25 for the MEC Wix migration's labels. Stored like `service`:
  `audience_tag_id` names WHAT was addressed and the resolver answers WHO at send
  time. A tag is the office's label, never an opt-in, so it only NARROWS: the
  email opt-out list and the SMS consent record apply to every tagged person
  exactly as to everyone. Push to a tag is refused at the request boundary for
  the CONTACTS reason (a tag names people, most of them signed in on no device),
  and `pushSubscriptionIds()` returns `[]` for a tag audience that reaches it
  anyway. A tag audience with no tag — never chosen, or deleted since
  (`nullOnDelete`) — addresses NOBODY, never everyone; deleting a tag a
  scheduled or sending broadcast addresses is refused. `readsContacts()` is true, so
  it inherits the `crm_enabled` + `view contacts` gate. Pinned by
  `tests/Feature/Broadcasts/TagAudienceTest.php`.

A `group` audience is the natural next case and is deliberately absent: group
audiences carry guardian-consent rules (`.claude/rules/groups.md`) that an
interest toggle does not, and that deserve their own task rather than a quiet
inheritance.

## The newsletter layout is EMAIL's, and the legacy email is frozen

A broadcast may carry `blocks` (heading, text, image, button, divider,
image_row, spacer) that only the EMAIL renders — `App\Services\Broadcast\Newsletter\`
(`NewsletterBlocks` = schema + validation, `RichText` = the allowlist parser,
`NewsletterRenderer` = table rows + text part) into `emails.broadcast-newsletter`.

- **No blocks ⇒ `emails.broadcast`, byte for byte, no text part.** Pinned against
  `tests/fixtures/broadcast-e4c7fc48/`. Never edit that fixture to make a test pass;
  a change to the legacy email is a decision, recorded in DECISIONS.md first.
- **Blocks require the email channel** (422 otherwise). Title and body stay required.
- **Pictures are uploads, never URLs**: `block_images[key]` → media collection
  `Broadcast::BLOCK_MEDIA_COLLECTION` with the `block_key` property, resolved by
  `Broadcast::newsletterBlocks()`. Do not add an image-URL field: it would let a
  newsletter hotlink a tracking pixel into every inbox. A `src` found in a stored
  block is ignored; only the broadcast's own media can address a picture.
- **The stored picture is a re-encoded copy, never the upload** (`NewsletterPicture`):
  EXIF/GPS gone, at most 1104px wide (2 × the 552px column), under a generated file
  name. Not a Spatie conversion: a conversion sits next to the original under a name
  derived from it, which would leave the original, metadata included, one URL edit
  away. Pictures over 36 megapixels are refused at the request, before GD would try
  to hold them in memory.
- **Rich text is re-parsed on store AND render.** Adding an allowed tag or scheme is
  a change to `RichText` plus a case in `NewsletterSanitisationTest`, never a regex.
- **Reader-clickable addresses use `NewsletterBlocks::webUrl()`**, not Laravel's
  `url` rule. That rule accepts ~300 schemes (`Str::isUrl`), including `data:`,
  `file:`, `blob:`, `view-source:`, `chrome:` and `ms-settings:`; it does NOT accept
  `javascript:`, but nothing on that list belongs behind a newsletter link either.
  This covers the "More details" link too: the newsletter prints it only when it is
  a web address, and a send with blocks refuses any other.
- **The rendered email must stay under Gmail's clip** (~102 KB, observed, not
  published): the unsubscribe footer is the last row, so a clipped newsletter hides
  it. The send refuses over `NewsletterBlocks::MAX_EMAIL_BYTES` (100,000); the
  preview warns over 80,000. Both measure `NewsletterPreviewMail`, so they agree.
- **The preview is `BroadcastMail` itself** (`POST .../broadcasts/preview`); the SPA
  holds no copy of the email's markup. Changing the markup means regenerating
  `tests/fixtures/newsletter/` with `UPDATE_SNAPSHOTS=1` and reading its diff.
- **The preview is lenient; the send is not.** The preview renders the blocks that
  are `NewsletterBlocks::complete()` and returns 200 with `errors` (the send's own
  messages) and `warnings`. A 422 from the preview would freeze it, because every
  new block starts empty.
- **Rollback:** `broadcasts.blocks` is additive; roll back the code, never the
  migration while a layout is stored (its `down()` refuses). A newsletter
  SCHEDULED under the new code would be sent by the old code as the plain email
  without its blocks, so hold or cancel those first (`deploy/README.md`).

## Signage reaches no screen (found 2026-10-03)

The tvOS board builds its slides from `GET /api/mobile/masjids/{id}/announcements`
and has never called `/signage` (ios MasjidKit `MasjidEndpoint`: five cases, none
of them signage; true of the released build and of iOS main). The channel was
written on the belief that the app was asking for a board endpoint; the one it
was missing was `/tv-config`. So a broadcast sent to signage alone was reported
`sent` and appeared nowhere. What reaches the lobby TV is the **announcement**
channel, because it creates an `announcements` row.

What follows from that:

- **The composer does not offer signage.** `composerChannels()`
  (`views/dashboard/broadcasts/broadcastPayload.ts`) is the list; the
  announcements feed's hint names the lobby TV for an organisation holding the
  `tv_display` grant. Pinned by `broadcast-payload.test.ts` and the mounted
  `broadcast-composer-screen.test.ts`.
- **The API still accepts it**, for a browser holding the older page, and
  `SignageChannel` answers `sent` with a note saying the TV app does not read the
  channel. `sent` there means "published at that address", nothing more.
  `/signage`, `Broadcast::scopeLiveOnSignage` and their tests are unchanged.
- **Do not put the tick box back until a TV build reads it.** `/announcements` is
  one address and one cache for the iPhone app, the Android app and the TV, and
  nothing in a request tells them apart (the server reads no app key), so a
  lobby-only notice cannot be served through it. It needs a TV app release, and
  three things `/signage` does not have today: a merge with the announcements
  (it answers broadcasts OR announcements, so one notice would replace every
  announcement on the board), a way to take a notice down (broadcasts have no
  delete and the end date is optional), and a rule for a broadcast sent to both
  channels (it would show twice). DECISIONS.md 2026-10-03.

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
a second contradictory row. The single deletion, on both lists, is a staged
import's undo removing rows that same run INSERTED (tracked row by row in
`import_links`): its own precautions always, the opt-outs it copied only with
`--remove-opt-outs` (the run went into the wrong organisation). The Wix
order-history import's undo removes its own hold the same way, tracked in
`historical_import_records`; each undo also checks the row still carries ITS
import's reason, so neither can delete the other's. Staff may lift two email
reasons only, both written by an import for want of consent — the contact
import's `not_opted_in` and the order-history import's `order_history_import`
hold (`EmailSuppression::STAFF_LIFTABLE_REASONS`) — on recorded evidence of
consent (`EmailSuppressionService::liftPrecaution`). Because that permission is
read from the reason alone, **a real opt-out landing on such a hold replaces its
reason** (an unsubscribe link, or a Wix unsubscribe, complaint or bounce the
contact import reads later; `EmailSuppressionService::replacesHold`), keeping
the hold in `held_reason` / `held_since`. Any new writer of a stricter reason
must go through `suppress()` or do the same, or it leaves an opt-out one staff
click from being overridden.

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
