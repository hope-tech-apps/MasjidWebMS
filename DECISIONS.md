# DECISIONS

_Append-only. Each entry: date · decision · alternatives · rationale._

## 2026-06-24 — Backend-driven content, not hardcoded per masjid
Decision: masjid identity (name, brand, About/Mission/Vision, theme,
donation link) is stored in the DB per `masjid_id` and served to all
consumers, never hardcoded in web/app/backend code. The Al-Fateh →
Burlington rebrand was executed as DB + seeder edits, not string swaps.
Alternatives: hardcode Burlington strings/assets per platform (fastest
for one masjid). Rationale: the system is multi-tenant by design; a
hardcoded brand would have to be found and edited in 4+ codebases for
every future masjid and would drift out of sync. One DB source keeps web
+ apps + tv consistent and makes onboarding a new masjid a data change.

## 2026-06-25 — Single-source content via a serialize-time binder
Decision: the four "content" section types (about_us, mission_vision,
donation, contact_form) render from their dedicated models via
`app/Support/SectionContentBinder`, injected when the V1 PageSection
Resource serializes — rather than storing a second copy in the page
builder's free-form `Section.content` JSON. A `filled()` guard falls
back to stored content if the model is empty (non-destructive).
Alternatives: (a) leave the duplicate section editors and ask admins to
edit twice; (b) migrate the page-builder blob into the models
destructively. Rationale: the page-builder blob is entity-unbound, so
About/Donate prose was being edited twice (web section vs the model the
apps read). Binding at serialize time gives one edit surface, preserves
the exact Nuxt payload shape, and is reversible (drop the binder).

## 2026-06-26 — Per-section `settings.bind` for Burlington's custom layout
Decision: extend the binder with a per-section `settings.bind` directive
so Burlington's About rendered via a generic `image_text_grid` (§13) and
Mission/Vision via `grid_cards` (§14) also pull from `MasjidAbout`,
matching into `content.text` / `items[]` by title keyword while keeping
layout/heading/card-titles. Alternatives: rebuild those pages using the
canonical about_us/mission_vision section types. Rationale: the existing
layout was already approved/live; binding by directive avoided a page
rebuild while still collapsing the edit-twice problem for the real prose.

## 2026-07-23 — Super-Admin masjid onboarding wizard
Decision: turn the manual per-tenant onboarding into one Super-Admin
wizard + a single transactional `OnboardingController@provision`
(`POST /api/admin/onboarding/provision`, own prefix under the admin group,
`super`-gated). One call creates the masjid + theme + about + prayer-calc +
iqama + jumaa + donation link + social links + default feature toggles +
app-publishing config. Per-platform app publishing (`masjid_app_publishing`
table + `MasjidAppPublishing` model) records `managed` (org publishes, paid
tier) vs `byo`; BYO Apple ASC .p8/key-id/issuer-id and Google Play
service-account JSON are stored via Laravel `encrypted` casts, `$hidden`, and
NEVER returned — reads expose only `has_asc_key` / `has_play_service_account`
booleans. Validation is a FormRequest (`ProvisionMasjidRequest extends
BaseFormRequest`) so failures throw the legacy `{status:'failed'}` envelope,
never a raw ValidationException. Wizard posts one nested payload as a real
`FormData` (axios 1.16 drops the global multipart header and lets the browser
set the boundary; Laravel re-parses bracket keys into nested arrays validated
with dot rules); a `feature_keys_provided` flag disambiguates an
all-unchecked selection from an omitted field (multipart drops empty arrays).
Alternatives: reuse `MasjidsController@store` + N follow-up save calls from
the client (multi-request, non-atomic, partial-tenant risk on failure);
JSON body (the app's global content-type is multipart, and nested JSON
doesn't round-trip through the existing form-post convention). Rationale: one
atomic transaction can't leave a half-provisioned tenant; reusing each
config's existing rules/models keeps parity; secrets-as-encrypted-never-echoed
is the security-critical invariant. Scope note: the wizard configures the
"minutes after adhan" iqama model and a single Jumu'ah iqama time; the richer
fixed per-date iqama ranges stay in the dedicated Iqama screen post-onboarding.

## 2026-08-10 — Manara verticals: one core, three org-type packages
Decision: Manara expands beyond masjids into three verticals — Manara
Masjids (existing), Manara Schools (blueprint: al-razi-school-web /
alrazischool.org), Manara Community (blueprint: AlAqsaClinic-Web /
al-aqsaclinic.org; named "Community" not "Businesses" since the pilot is
a nonprofit free clinic). Architecture: a single shared core, NOT forks.
Tenant generalizes to an organization with an `org_type`
(masjid | school | community); existing `masjid_*` naming is retained
short-term as internal tech debt, renamed gradually. A vertical =
(a) a feature-bundle seeder per org_type on the existing feature-toggle
system, (b) vertical section types in the page builder, (c) a
terminology pack for admin labels. Masjid-only modules (prayer/iqama/
Jumu'ah, adhan, Qur'an, azkar/hadith/tasbih, qibla, Hijri) stay behind
the existing per-tenant feature gates and are never loaded for other
org types. Public renderer: extend the existing Nuxt app into the single
multi-tenant renderer (domain → org resolution) rather than rewriting in
SvelteKit or running per-vertical renderers — the renderer is a thin
consumer of /api/v1 section JSON, the section-type investment already
lives there, and a rewrite would create the exact dual-maintenance
fragmentation the one-core strategy exists to avoid; a later framework
migration stays cheap because the section contracts are the interface.
Launch surfaces for Schools/Community: web + admin portal first; TV
signage and mobile follow per vertical via the existing scaffolder once
tenants want them. Both blueprint sites will be re-platformed onto
Manara as pilot tenants of their verticals. Alternatives: separate
codebases per vertical (three of everything, drift); catch-all
"Businesses" naming (misfits nonprofits); new SvelteKit renderer
(rewrite cost + dual-stack window); full surface parity at launch
(delays web validation). Rationale: MasjidWebMS is already a
multi-tenant, feature-flagged, page-builder CMS — verticals are
configuration, and every core improvement then ships to all three.

## 2026-08-10 — Classroom (ClassDojo-esque) features via a core Groups primitive
Decision: Manara Schools will include a ClassDojo-style feature set
(classrooms, rosters, teacher/parent roles, behavior points/awards,
class story feed, teacher↔parent messaging). Architecture: build a
generic **Groups** primitive in core — group + membership roles +
private feed + messaging threads + private access-controlled media —
and layer school semantics (student↔guardian links, points, awards,
portfolios) on top in the School package. Rationale: groups are a
second scoping level (org → group → member) that every vertical needs —
masjid weekend schools/ḥalaqāt and community volunteer teams reuse the
same machinery, so a masjid tenant can enable classrooms without being
a school tenant. Existing infra (OneSignal push, mobile_app_users,
notifications, Pusher webhook) covers the hard parts. Consequences:
(a) Schools ships in two waves — v1 public site + admin (Al-Razi
re-platform, unchanged), v2 Classroom module + parent/teacher mobile
app via the white-label scaffolder, since classroom messaging is
unusable without push/phones; (b) minors' data forces private media
(current gallery model is public-only), guardian consent, and retention
policy into the Groups design from day one. Alternatives: school-only
classroom tables (duplicates feed/messaging when masjids want ḥalaqāt);
integrate/embed ClassDojo itself (no control, no tenancy integration —
and the integrated masjid+school+one-parent-app story is the
differentiator ClassDojo can't match).

## 2026-08-10 — Payments doctrine: tenant is always merchant of record
Decision: across all Manara verticals, the platform NEVER holds, pools,
or disburses funds. Every org is its own Stripe merchant of record via
the existing Stripe Connect Standard linkage (`stripe_account_id` on the
tenant, migration 2026_07_12_000001); all charges use Stripe-hosted
surfaces (Checkout/invoices/subscriptions/Terminal) on the org's own
account, so card data never touches Manara servers or apps (PCI SAQ-A)
and chargebacks/refunds/disputes belong to the org in their own Stripe
dashboard. Corollaries: (a) tuition installments and dues use Stripe
subscriptions/invoices on the org account — Stripe owns retries and
dunning, Manara does not build a payment scheduler; (b) the registration
engine separates registration state (roster, capacity) from payment
state, and payment state transitions ONLY from verified webhooks
(idempotent via `stripe_webhook_events`) — never from client redirects;
(c) financial aid/discounts are pre-checkout price adjustments, never
post-hoc money movement; (d) kiosk/tap-to-pay uses Stripe Terminal /
Tap to Pay on the org's account; (e) Al-Aqsa's PayPal button migrates to
its own Stripe account at re-platform time (one rail); (f) if Manara
monetizes per-transaction later it uses Connect application fees, still
without becoming MoR. Sequencing consequence: the Classroom module
(high-priority, ClassDojo-esque) has ZERO payment surface — points,
feeds, messaging, rosters — so it ships without touching any of this;
deep FACTS-style billing is deliberately last. Alternatives: platform
as MoR with payouts (money-transmitter exposure, disputes land on
Manara); building payment plans in-house (recreates Stripe dunning
badly). Rationale: eliminates licensing, PCI, and dispute liability
structurally rather than procedurally — the architecture the codebase
already chose for donations, now stated as doctrine for every future
money flow.

## 2026-08-10 — Connect onboarding landings are PUBLIC pages, not authed API
Decision: Stripe's Account Link `return_url` / `refresh_url` now point at two
unauthenticated, browser-facing routes in `routes/web.php`
(`connect.return` / `connect.refresh` → `ConnectOnboardingLandingController`,
rendering `resources/views/connect/onboarding-status.blade.php`), declared
before the SPA catch-all and throttled at 20/min. The previous targets were
inside the `auth:sanctum`+`admin`+`tenant`+`crm` admin group, but Stripe
redirects the ORG ADMIN'S BROWSER there with no Sanctum token, so the user was
shown a raw `{"status":"error","message":"Request failed."}` envelope. This was
not cosmetic: on the first live onboarding (Burlington Masjid,
`acct_1U2y2o…`, 2026-08-10) it read as a failure and the admin abandoned the
flow, leaving `external_account` and `tos_acceptance` past due. The old
authed JSON endpoint survives as `GET .../connect/status` (renamed from
`/return`, method `onboardingReturn` → `status`) for the SPA; nothing
referenced the old path, so the rename is safe.
The public pages are deliberately information-thin — masjid name plus
charges/payouts booleans, never `stripe_account_id`, requirement details, or
anything key-shaped (asserted in tests).
`refresh` does NOT mint a replacement Account Link, it tells the user to
request one from their admin: minting from an unauthenticated route would let
anyone generate hosted onboarding for any masjid and submit THEIR OWN bank
account as the payout destination. Alternatives: signed URLs
(`temporarySignedRoute`) — rejected because signature validation compares the
full URL and the app does not configure TrustProxies/forceScheme, so an
https→http scheme flip behind nginx would 403 admins mid-onboarding, a worse
failure than the one being fixed; keeping the routes authed and telling admins
to ignore the error — leaves the abandonment trap in place for every future
tenant. Rationale: the money path's own rule is "never trust the browser
redirect" — the return page is a convenience refresh only and `account.updated`
on the signed webhook remains authoritative, so making the page public costs
nothing in correctness.
Verification note: the suite (`tests/Feature/ConnectOnboardingLandingTest.php`,
8 cases) could NOT be executed on the droplet — its PHP has only `pdo_mysql`,
and the suite runs sqlite in-memory; installing an extension on a production
host for test convenience was rejected. Verified there instead: `php -l` on all
changed files, router matching (`/connect/1/return` → `@complete`,
`/connect/1/refresh` → `@expired`, `/connect/abc/return` and `/dashboard` still
fall through to the SPA closure), and the Blade rendering in all four states
with a regex assertion that no `acct_`/`sk_live`/`whsec_` appears. CI
(.github/workflows/tests.yml) runs the suite for real.

## 2026-09-08 — MEC app: member accounts on `contacts`, and service detail behind an org switch

The MEC app (target `Muslim Education Center`, `org.meccharlotte.app`, masjid 13)
gets accounts. Four decisions, taken together because each one constrains the
next.

**Guest keeps today's app, exactly.** Prayer/iqama times, announcements, events,
azkar, tasbih, qibla, gallery, donate, contact — unchanged and unauthenticated.
Sign-in gates the *breakdown detail* of a service, not the front door. A hard
gate on first open was rejected on two grounds: it costs the walk-up user who
only wants Maghrib, and App Store guideline 5.1.1(v) tells apps without
significant account-based features to work without a login — an app whose home
screen is a prayer timetable is squarely exposed there, and a rejection costs a
full review cycle.

**Members are `contacts`, not a new table.** The August work already built the
whole passwordless stack — `contacts.login_email` / `login_enabled_at` /
`login_revoked_at` / `last_login_at`, `contact_login_codes` (hashed code,
channel, expiry, attempt cap, requester IP), `contact_login_events` for audit,
`Contact implements AuthenticatableContract` minting scoped tokens, and the
custom `sanctum-family` guard that deliberately lets a member session outlive
the 8-hour staff `sanctum.expiration`. A separate members table was rejected:
it would duplicate identity and hand a parent who is also a community member
two logins, which is the same failure the one-login decision for
manara.hopetechapps.com already rejected.

The real new thing is **self-registration**, and it is a new trust boundary.
Contact login today is admin-provisioned — staff call `enable()` to set
`login_enabled_at`. Letting anyone who downloads the app create a row writes
directly into the CRM staff work in. So self-registered contacts carry a
`signup_source` (named in full because `group_memberships` already has a
`provenance` concept) and a `verified_at`, and signup merges on `(masjid_id, login_email)`
rather than inserting: an email already on file must LINK to the existing
contact, never create a shadow record of a person the office already knows.
Staff-curated contacts must stay distinguishable from self-serve ones in every
admin list.

**Entering a service is a tenant switch, so enterable services are orgs, not
`services` rows.** `services` is a content row (`title`, `summary`,
`description`, `text`, plus media) hanging off one masjid. It cannot back an
app context that reloads. A service the member can enter therefore graduates
into a `masjids` row with its own `org_type` — `masjids` already has
`org_type` from 2026-08-11, but has NO `parent_id`, so the hierarchy column is
the missing piece. MEC's eleven services (Mosque, IntelliCor International
Academy, Al-Bayan Quran Academy, Halal Kitchen, Shifa Free Health Clinic, MAS
Immigration Justice Center, Career Programs, Facility Rental, MAS Charlotte,
Islamic Relief, Baitul Hemayah) become children of MEC as each one gets a real
project behind it; the rest stay content rows until then. This is what makes
"MEC app reads data from all the sub-projects" mechanical rather than bespoke.

**Interests drive push.** A `contact_service_interests` pivot, a
`BroadcastAudience::SERVICE` case (the enum is only `everyone | contacts`
today), and one OneSignal tag per interest. No new delivery infrastructure is
needed: `OnesignalService` already targets by tag filter — that is how the
existing `masjid_id` tag works — and `BroadcastChannel` already covers push,
email, SMS, announcement and signage.

### iOS notes, and three traps

The runtime switch is small. `AppConfig.masjidId` is already a computed
property (`AppConfig+MasjidID.swift`) over the per-target `BuildMasjid`
constant, with 18 references across 5 files — 13 of them the path
interpolations in `APIRouter`, a single choke point. So `BuildMasjid.masjidId`
becomes the immutable HOME org and `AppConfig.masjidId` resolves to the
CURRENT org, and every endpoint follows for free.

The cost is state reset, which is why the switch needs the loading screen:
`Settings.shared.masjid`, the cached features/services, and the UserDefaults
cache are all keyed to one org. Rather than invent a second load path, the
switch re-enters the existing splash bootstrap (`SplashViewModel` already loads
masjid + features + settings and reports the heartbeat) with a new id.

1. **The OneSignal `masjid_id` tag must keep pointing at the HOME org.**
   `AppDelegate` sets it once from `AppConfig.masjidId`. If that silently
   becomes the current org, a member browsing IntelliCor stops receiving MEC's
   notifications — a delivery failure with no error anywhere, the shape logged
   in the silent-failure pattern.
2. **`PrayerScheduler` must not carry prayer notifications across a switch.**
   Local notifications are scheduled from the current masjid's prayer times; a
   school or clinic child org has none, and stale Adhan alarms for the wrong
   org would survive the switch.
3. **The drawer legitimately differs per child org** — `Masjid::defaultFeatureKeys()`
   is already per `org_type`, so a school child should not show Qibla. This is
   supported, not new work, but the drawer must rebuild on switch rather than
   persist the parent's list.

Supersedes the narrower "move services to the first page" reading of the
2026-08-18 MEC call: services stop being a drawer entry and become the app's
second axis.

### Built 2026-09-08 — what actually shipped for auth + interests

Migrations `2026_09_08_1600{00,01,02}`: `contacts.signup_source` + `verified_at`
(NULL source = staff-authored, which is every pre-existing row);
`app_signup_codes`; `contact_service_interests`.

**`app_signup_codes` is a separate table from `contact_login_codes` on purpose.**
That column's `contact_id` is a non-nullable constrained FK and the premise of
app sign-up is a first code sent to an address with no contact behind it.
Widening it would have loosened a shipped auth table for a newer, less trusted
flow. Same shape otherwise, so the redeem rules cannot drift.

**Verification sets `verified_at` and NEVER `login_enabled_at`.** This is the
whole separation: `family.active` gates the family realm on `login_enabled_at`,
so a self-registered member's token is refused by every family route without
any new enforcement, while `member.active` (new) gates the member routes on
`verified_at`. Both honour `login_revoked_at` — staff revoked the person, not a
channel, and signing up is not a way back in.

There is no `/register`. Sign-up and sign-in are the same two endpoints, because
a separate registration route could not avoid answering "is this address already
known here?". The contact is created only inside the transaction that burns a
redeemed code, so spraying `request-code` with a dictionary writes expiring code
rows and never a person into the CRM (pinned by
`AppSignupCodeTenantIsolationTest::requesting_a_code_creates_no_contact`).

Linking copies NOTHING from the request — a submitted name is used only when
creating a new contact — or anyone able to receive mail at a known congregant's
address could rename that congregant in the office's own CRM.

`Service` has no `BelongsToMasjid` trait, so `MemberInterestService` hand-scopes
every submitted service id by `masjid_id`. Without it a member could subscribe
to another organisation's service and receive its sends; pinned by
`ContactServiceInterestTenantIsolationTest::a_member_cannot_subscribe_to_another_organisations_service`.

Routes carry `crm`, matching the family realm: contacts ARE the CRM, so member
accounts should not exist where it is switched off.

STILL TO DO: `BroadcastAudience::SERVICE` and the OneSignal tag sync — interests
are stored and served but nothing yet ROUTES on them — plus the iOS side
(`OneSignal.login()` still keys on device id, not contact).

## 2026-09-10 — Staging environment: a second droplet cloned from prod, its own MySQL, no real egress

**Decision.** Staging is a **new droplet created from a snapshot of production**
(same size `s-1vcpu-2gb`, region nyc1, same VPC), named `masjid-staging`, with
**MySQL 8 installed locally on the box** as its database, reachable at
`masjid-staging.hopetechapps.com` and `manara-staging.hopetechapps.com`
(both proxied Cloudflare A records — Universal SSL covers `*.hopetechapps.com`
one label deep only, so two-label names like `staging.masjid.…` are out).
`APP_ENV=staging`; all outbound integrations are blank or sunk (mail → `log`,
OneSignal blank with the service made a no-op when unconfigured, SMS → none,
Anthropic blank, GitHub dispatch unset); Stripe runs on **test-mode** keys with
its own test webhook. Data is a **scrubbed copy of production** produced by an
artisan command that refuses to run outside staging; private-disk files (family
media, documents) are never copied. Deploys: `bin/deploy` accepts `--ref` on
staging only (prod stays ff-only `main`); a local `scripts/ship.sh <env> [ref]`
builds the SPA (`npm run build`, never `build:prod`) and rsyncs it. A visible
STAGING ribbon + `X-Robots-Tag: noindex` appear whenever `APP_ENV` is not
production. Convention: `.claude/rules/environments.md`.

**Alternatives.** (1) *Repurpose droplet 480119186 in place* — rejected: Ubuntu
24.10 is EOL (apt is dead), 1 GB RAM, PHP 8.2 default, no pdo_sqlite/intl; it
would be a false mirror. It should be destroyed once staging is up (owner's
call; it still holds prod DB credentials and live Resend/Anthropic/OneSignal
keys, and until 2026-09-10 it was running prod's queue and cron). (2) *A second
database on the managed cluster* — rejected: DO MySQL users see every schema on
the cluster, one wrong `.env` line points staging at prod, and the cluster is a
single node with no standby that staging load would share. Local MySQL is free,
fully isolated, and lets `migrate:fresh` and the never-run concurrency test
execute. (3) *Local Docker dev on the Mac* — deferred, not rejected: it does not
prove nginx/cron/queue/Cloudflare behaviour, which is where the last month's
prod surprises lived. (4) *Synthetic seed data only* — rejected as the sole
source: the bugs that reached prod were data-shaped (column widths, unique
indexes, timezone round-trips); a scrubbed prod copy catches those.

**Rubric** (request-fit 40 / risk 20 / testability 15 / simplicity 15 /
reversibility 10): clone-from-snapshot + local MySQL 36/18/14/12/9 = **89**;
repurpose-in-place 30/10/10/13/6 = 69; Docker-only 22/16/9/12/10 = 69. Scouts
ran (infra, codebase, external); critic/supervisor phases were folded into this
entry because the risks were concrete and enumerable, not contested.

**Blocked on the owner, deliberately not worked around:** creating the droplet
needs a DigitalOcean API token or console click (doctl here is unauthenticated
and the MCP cannot create droplets), and test-mode Stripe keys come only from
the dashboard. Everything else is built ahead so the box is live within an hour
of those two inputs.

## 2026-09-11 — Forms may take one payment; per-staff codes settle cash (a narrow exception to T-006's "no self-service discount")

**Decision (owner, 2026-09-10).** MEC's Fall Festival (Sat 17 Oct, the
platform's live trial with MEC) takes registrations through the **form
builder**, with **Stripe Checkout on MEC's own connected account**, not
through an Offering. Three existing rules are narrowed, and only these:

1. **A form may take one one-time payment** when its settings turn it on.
   It still takes no seat and has no waitlist or installments; those remain
   the Offering's (`.claude/rules/section-types.md`). The payments doctrine
   (2026-08-10) is unchanged: a direct charge on the organisation's account,
   hosted Checkout only, integer minor units, amounts computed by the server
   and never taken from the body, and "paid" set only by a verified webhook.
2. **Secret per-staff codes.** Each staff member gets their own code for one
   form. A valid code settles that submission as **cash held by that person**
   at the list price, in the same request, with no Stripe call and never a $0
   session. The row is stamped with the holder, so the office can total the
   cash each person owes. This narrowly reverses T-006's "no self-service
   discount hole" (docs/t006-registration-billing-design.md:45 and :79;
   `RegistrationAdjustment`) **for form checkout only**: the offering quote
   still ignores `code` and answers `code_applied: false`
   (`.claude/rules/registration-billing-data.md`). Every guard rail below is
   required:
   - codes are generated on the server;
   - they are stored only as a keyed hash (the `ContactLoginCode` pattern);
   - they are scoped to one form and one tenant;
   - they expire after the event and can be revoked at once;
   - failed attempts are rate-limited;
   - a code never appears in a public payload, a log, a URL or an export.
3. **Cash by code is a staff-asserted settlement.** Like
   `MealOrdersController::markPaid` for pay-at-pickup lunch, it is a carve-out
   from "payment state moves only on verified webhooks". It is recorded
   against a named person and reconciled against their cash, and it is never
   inferred from a client redirect.

**Alternatives.**
- **An Offering with admin-granted adjustments.** Rejected: it has no cash
  walk-up path, its public renderer is not drawn yet, and the owner chose the
  form builder.
- **Last year's Wix click-to-pay page.** Kept only as the fallback, used if
  MEC's Stripe Connect is not live and test-charged by Wed 30 Sep.
- **One shared staff code.** Rejected: the point is knowing who holds the cash.
- **Codes that simply make an entry free.** Not the v1 default. Every code
  entry is cash its holder owes at the list price, which is what makes
  reconciliation possible. A genuine comp (a volunteer, a guest) is an admin
  action afterwards, with a note.

**Rationale.** Most festival walk-ups pay cash at the gate. Per-holder codes
turn "who took the money" from memory into a query, without opening a public
discount path. A leaked code can only register people against its holder's
cash total; reconciliation exposes that and revocation stops it.

**Refinements made during the build (2026-09-11).**
- **Code expiry is explicit, never inferred.** A paying form carries its event day (`settings.payment.eventDate`). A code's default expiry is midnight after that day on the organisation's clock. With no event date, a code cannot be issued without an explicit expiry. A guess taken from `closes_at` or "today" could have killed every code at 00:00 on festival morning.
- **Form checkout is card only** (`payment_method_types: ['card']`). With card only, a completed Checkout Session means the money is settled. A delayed bank debit would have left a "complete" session whose money might never arrive.

## 2026-09-11 — Lunch orders can be marked paid by hand with how they were paid (narrows the 2026-09-10 rule that an online order is marked paid only by Stripe)

**Decision (owner, 2026-09-11).** "When someone gets marked as paid we should
have the option to note how they paid: Zelle, Cash, Masjid Terminal, Stripe." It
came with a question about order #018, a card order taken on the board whose
Stripe page was still open, which nobody could mark paid.

1. **Mark paid always says how.** On the Jummah-lunch board it requires
   `paid_via`: `cash | zelle | terminal | stripe`, shown as Cash, Zelle, Masjid
   Terminal, Stripe (`MealOrder::PAID_VIA`, a nullable `meal_orders.paid_via`).
   It is written beside `marked_paid_by_user_id` by the first press only, so a
   second press is a 200 that rewrites neither. `stripe` means money taken
   through some other Stripe route, such as the masjid's own link or dashboard.
   It is a label staff record, like the others. A payment on the order's own
   Checkout page is still recorded by the webhook alone, leaves `paid_via` NULL,
   and reads as paid online by card. Rows marked paid before today are not
   backfilled.
2. **Any unpaid order that is not cancelled can be marked paid, pickup or
   online, by anyone who runs the board** (admins and lunch volunteers). This
   narrows the 2026-09-10 staff-order rule (8fb78cd, and `MealOrdersController::
   markPaid` as it stood) that an online order is marked paid only by Stripe.
   `payment_method` stays the channel the order came through; `paid_via` says
   how the money came.
3. **The order's own card page is closed first**, under the row lock that
   Payment link and cancelling take
   (`MealOrderCheckoutService::closePageBeforePaidByHand`):
   - an open page is expired and forgotten;
   - a page complete and paid is refused ("already paid by card online");
   - a page complete and unpaid is refused as a bank payment still clearing;
   - a close Stripe refuses is asked about again, and is refused on either
     answer or when the page is still open;
   - if Stripe does not answer, nothing is recorded.
4. **A card payment landing on an order already marked paid by hand is a
   double payment.** `MealOrderPaymentService` records its payment intent id
   only, never rewrites how, who or when, and logs a warning, by ids, so the
   organisation can refund one of the two. The board says so beside the order
   too ("Also paid by card online: refund one in Stripe"), because the log
   reaches only the platform operator.
5. **A press that finds the order already paid says what was recorded, never
   what was chosen** (review, same day). An order already marked paid by hand
   answers 200 with `recorded: false`, and its `data` names the method and the
   person recorded first. An online order the webhook already settled from its
   own page (`MealOrder::paidOnItsOwnPage()`) is refused with a 422, as point 3
   refuses it in the seconds before the webhook lands, so what staff are told
   never depends on how fast Stripe delivers.
6. **Every card page is made on the locked row, the first one included**
   (`MealOrderCheckoutService::checkout`). The first page used to be made after
   the order's own write had committed, so a Mark paid in between found no page
   to close and a live page then landed on a paid order.

**Alternatives.**
- **Keep online orders webhook-only.** Rejected: #018 was paid another way and
  the board had no honest way to say so. The order would have stayed unpaid on
  every total.
- **A free-text note.** Rejected: fixed choices can be counted by method, and
  free text cannot.
- **Default the method to cash.** Rejected: a default is a guess written into a
  money record. A board still on the old bundle sends no method and is told,
  in the refusal it shows, to reload.
- **Mark paid without closing the card page.** Rejected: the customer could
  still pay by card afterwards. Closing first under the lock is the forms'
  take-cash pattern (`FormResponsesController::settleByHand`).
- **Refund a double payment automatically.** Rejected: the organisation is the
  merchant of record, and a refund is its own action in its Stripe dashboard
  (`.claude/rules/stripe-payments.md`).
- **Refuse every press on an order already paid**, as the forms' take-cash does.
  Rejected: two volunteers recording the same cash is ordinary, and the second
  is told what was recorded rather than refused.
- **Keep the 200 for an order paid on its own page.** Rejected: the refusal in
  point 3 would then depend on whether the webhook had landed a second earlier.

**Rationale.** Lunch money arrives in several ways besides the order's own
page, and until today the board could mark only pickup orders paid, without
saying how. Asking at the moment of Mark paid records it while the person who
took the money is standing there. Closing the card page first keeps one order
to one payment, and the webhook warning is the backstop for a payment that
slips past.

## 2026-09-13 — Forms: family prices by number of children, a required card fee, and paying the office (narrows 2026-09-11's "a payer's only say is the yes/no on the card fee")

**Decision (owner, 2026-09-13).** Burlington Islamic Sunday School (BISS)
registers a family on one form: $100 for one child, $170 for two, $250 for
three, $300 for four, $350 for five or more. Card payers always pay the card fee.
A family may instead pay the school office by Zelle, Cash App, Venmo, cash or
check. Five rules follow.

1. **A form may be priced by its number of entries.** `settings.fee.countTiers`
   is a list of `{min, amount, label}`, and `perEntryOfSection` names the section
   that is counted. The tier with the greatest `min` at or below the row count
   wins, whatever order the list was stored in, and 0 rows owe 0. One resolver,
   `Form::priceFor()`, feeds `amount_due`, the cents snapshot, the Stripe line
   ("Form (3 children)") and the emails' tier label. On POST, PUT and
   `form:import` the save is refused when count tiers:
   - sit beside `amount` or date `tiers`;
   - count no section;
   - do not start at `min` 1;
   - have mins that are not strictly ascending;
   - include a tier cheaper than the one before it;
   - or, on a paying form, include a price under 50¢ or in fractions of a cent.
   An unreadable stored schedule refuses entries. It never falls back to a
   cheaper tier. `chargesFee()` reads the count prices.
2. **What the page is sent.** Under count pricing the public fee is
   `{pricing:'count', currency, perEntryOfSection, countTiers}`, with no
   `amount` key and no `tiers` key. The payment block's `unitMinor` is null. A
   renderer that predates this shows no total, rather than "$100 × 3" or
   "$0.00 × 3".
3. **`settings.payment.requireFeeCoverage`.** Every CARD payer covers
   `StripeFees::coverage()`, whatever `cover_fees` says. Staff-code cash and
   office payments carry no fee. The switch does NOT turn `allowFeeCoverage` on:
   an old renderer would draw an optional box for a fee the server adds anyway.
   "The school nets the tier price" holds only at a platform fee of 0
   (production's) and at the platform-wide 2.9% + 30¢.
4. **`settings.payment.officePayment`.** The submit takes `pay_with: card|office`.
   - **An office row** has `payment_method` `office`, is unpaid, and owes the
     tier price with a fee of 0. It makes no Stripe call and skips the return
     origin check. It is emailed at once: received, the amount owed, and
     `officeInstructions`.
   - **When `pay_with` is absent**, the family pays by card if the form can take
     a card right now, otherwise the office. It is never a row with no money leg.
   - **A staff credential** keeps its cash path.
   - **The office counts as payment** for the replay-key guard, the never-free
     quote and the save's paying-form rules. An office option on a form with no
     price is refused.
5. **Settlement still writes cash or external**, so every reader of the method
   keeps working. A new nullable `form_responses.paid_via`
   (`cash|zelle|cashapp|venmo|check`) records how the money came.
   - "Take cash" writes `cash`. "Mark paid" on an office row requires `via`
     (`zelle|cashapp|venmo|check`).
   - Unpaid office rows appear under the `office` filter and show "Owed — paying
     the office" on the roster. A paid one counts once, as cash or external.

**Alternatives.**
- **Per-child pricing with a family discount.** Rejected: there is no cap at
  five, and a discount is the adjustment hole T-006 closed.
- **Widening `allowFeeCoverage` to mean required.** Rejected: the deployed
  renderer would show $250.00 while Stripe charged $257.78.
- **Keeping `office` as the method after payment.** Rejected: the cash totals,
  roster, receipts and filters would all have had to learn it, and one missed
  reader under-reports the books.
- **`amount: null` in the public fee.** Rejected by the renderer lane:
  `Number(null)` is 0.

**Rationale.** Families pay the office as often as they pay by card. Recording how
the money came, at the moment staff mark it paid, keeps the books countable by
method without teaching every reader a new method. One price resolver means the
page, the row, Stripe and the email cannot disagree about a family of three.

**Known limits (Phase 1).**
- An abandoned card checkout followed by an office resubmission is two
  registrations, and an office row cannot be switched to card.
- A required card fee is a surcharge, restricted on debit and prepaid cards and
  in some states. The owner confirms before it goes live.
- The gross-up uses the platform-wide rate, not BISS's own Connect pricing.
- **Unpaid office rows count toward capacity and never lapse.** Nothing expires
  a family that chose the office and never paid. Do not turn `officePayment` on
  for a form with a `capacity` unless someone has a plan to cancel stale rows.
- **Office registrations are limited per email** (abuse review, 2026-09-14).
  Each one emails the typed address and the coordinators and costs nothing, so
  each email address may make `forms.office_per_day` (3) per form per 24 hours.
  The bucket is an HMAC of the lower-cased, trimmed identity email. The next
  registration is a 429 before any row or email, and a replay of a written row
  still gets its answer. Card and staff-code entries do not meet the limit, and
  an office submission with no email meets only the per-connection limiter. The
  check and the charge are not atomic, so concurrent requests can slip one or two
  past it.
- **Count pricing needs a cap:** the counted section must have `maxEntries`, or
  the save is refused, since the top tier is open-ended.

**Refinements from the money review (2026-09-14).**
- **The card fee is optional or required, never both.** The save refuses the
  pair by name. `Form::allowsFeeCoverage()` is false whenever the fee is
  required, so the page is never told `allowFeeCoverage: true` beside
  `requireFeeCoverage: true`.
- **The emails name a tier only when it still prices the row.** A re-quote at
  `submitted_at` must reproduce the owed cents, the rule `lineItems()` already
  follows. After a price edit the amount stands with no label.
- **The replay fingerprint carries what the client chose, not the route the
  server took.**
  - `pay_with` is included only when sent.
  - `fee_covered` is what a card payment of the answers would cover, false for
    a staff entry. Card-row fingerprints are unchanged.
  - A retry after the card came or went replays the first row instead of a 409.
- **The coordinators' email for an unpaid office row** carries the payment line
  "Owed — paying the office".

## 2026-09-14 — School calendar: years and no-school days, off by default, and form choices drawn from it

**Decision.** Burlington Islamic Sunday School needs school dates and a
cleaning-Sunday sign-up whose choices follow them.

1. **Two tenant-scoped tables.** `school_years` has a label, `first_day` and
   `last_day`. The meeting weekday is `first_day`'s and is not stored.
   `school_closures` has `closed_on` and `reason`. `App\Support\SchoolCalendar`
   answers every calendar question on the school's own clock.
2. **The `school_calendar` capability defaults OFF for every org type, schools
   included.** It is switched on for BISS with a SuperAdmin override, and
   SuperAdmins always pass. Al-Razi gets nothing it did not ask for.
3. **Only closures are enforced on the register.** A closed day has no register.
   A closure over existing marks is refused with the count, under a row lock on
   the year that the register save also takes. An organisation with no year is
   unchanged.
4. **Nothing is stranded.** An edit that moves a closure out of its year is
   refused and names the dates. A delete is refused while closures, marks or
   form answers point at the year, and names the counts.
5. **A choice question may take `optionsSource: 'school_meeting_days'`** and
   stores no options. Families are offered open meeting days after today.
   Admin readers label every meeting day, closed ones included. If no days are
   open, every answer is refused.
6. **A choose-any question may set `minSelections` / `maxSelections`** (owner:
   each family picks exactly 2 cleaning Sundays). The counts apply to an answer
   that was given; `required` decides a blank. Fewer open days than the minimum
   is refused as "Not enough cleaning Sundays are open right now".

**Alternatives.**
- **Default the capability on for schools.** Rejected: Al-Razi would get a new
  screen by accident.
- **Store the weekday, or one row per school day.** Rejected: the stored copy
  can disagree, and closing one Sunday should be one write.
- **Cascade a year delete.** Rejected: registers would reopen and answers would
  lose their labels.
- **Copy the days into the form's options at save.** Rejected: a closure would
  not reach the form.
- **Skip the in-list check when the calendar offers nothing.** Rejected: any
  string would be accepted.

**Rationale.** One authority and one lock keep the register, the forms and the
three reads in agreement. Starting OFF means shipping the calendar changes
nothing for any existing organisation.

## 2026-09-15 — A child program org's FORM card payments may charge through its parent's Stripe account (narrows 2026-08-10's "every org is its own merchant of record")

**Decision.** The 2026-08-10 doctrine stays the rule for every org and every money
flow, with one exception. A SuperAdmin may link a child program org's FORM card
payments to its parent's existing Connect account, when the child is a program of
the parent's legal entity. For those payments the PARENT is the merchant of record.

**Consent basis for BISS.** Burlington Islamic Sunday School is a program of
Burlington Masjid (masjid 1). The owner decided on 2026-09-13: "We will use the
Masjid Stripe information that is pre-existing already". The link's audit row
carries a `consent_reference` naming that decision. Before the link is switched on
in production, record here that BISS falls under Burlington Masjid's legal entity
and EIN (or that Stripe confirmed it is acceptable), and that Burlington's account
holder agreed to be merchant of record for BISS registrations, refunds and disputes
included. Without that record, the link stays off.

1. **The link.** `masjids.forms_card_via_masjid_id` (+ `_set_at`, `_set_by`), not
   fillable, on the public directory denylist, no FK. It must equal the child's
   `parent_id`. BISS keeps `stripe_account_id` NULL, so
   `masjids_active_stripe_account_unique` still holds and no `acct_` id is copied.
2. **Who changes it.**
   - Only a SuperAdmin sets or removes it
     (`PATCH /api/admin/masjids/{id}/forms-card-account`). The check is in the
     FormRequest's `authorize()`, so a non-super admin gets a 403 with no
     validation detail.
   - Setting it is refused unless the holder is the parent, live, onboarded and
     not itself linked, the child has no account of its own and nobody charges
     through it, and the SuperAdmin types the holder's exact name plus a consent
     reference.
   - The holder's own admin (manage donations) may revoke it
     (`DELETE .../masjids/{holder}/connect/forms-card-for/{child}`) but never set
     it. That route sits outside the `crm` gate: the link charges whether or not
     the holder's CRM is on, so withdrawing consent must not depend on it.
   - Archiving (soft-deleting) the child keeps its link, as a soft delete is
     reversible. Card is unavailable while it is archived. The holder still sees
     the child and can revoke it, and a SuperAdmin can remove it.
   - Force-deleting the holder removes it.
   - Every change writes an append-only `masjid_forms_card_links_log` row in the
     same transaction.
3. **One resolver.** `FormChargeAccount::for()` answers every Forms card question
   and reads the holder's account live. Any doubt means card unavailable, never a
   different payee. `canAcceptDonations()` is unchanged, so a linked child still
   takes no donations, lunch orders or registrations.
4. **Pinned, matched strictly, disclosed by name.**
   - Each response row pins the account its page was opened on.
   - Linked sessions route on a random `form_charge_ref`, never the public uuid
     (the return URLs still carry it; see Known limits).
   - Inbound events must match the pin and the session or amount.
   - A holder disconnect fails closed.
   - Refunds and disputes flag the row.
   - The child's admins see the holder's name and a ready flag, never its account
     id. A linked org cannot start Connect onboarding (409).

**Alternatives.**
- **Copy Burlington's account id onto BISS.** Rejected: it breaks the unique index,
  routes Burlington's donations to BISS, and turns on every BISS money flow.
- **BISS onboards its own Stripe account.** The owner declined for Phase 1.
- **A general account-sharing table for every money flow.** Rejected: donations,
  lunch and registrations do not need it, and every one of those reads would have
  to change.

**Rationale.** BISS needs card payment for its registration form now, and
Burlington already has a working account. A narrow, audited, SuperAdmin-only
link, checked again on every read, keeps every other org and every other flow
exactly as it was.

**Known limits.**
- Burlington's Stripe dashboard users see BISS payers' email addresses, amounts
  and line items. Code cannot scope that.
- Refunds are manual, in Burlington's dashboard. A refund or dispute flags the
  BISS row but never changes its payment status.
- The card fee gross-up uses the platform-wide rate, not Burlington's Stripe
  pricing. Compare the smoke payment's real fee before `requireFeeCoverage` goes
  live.
- BISS disputes and volume count against Burlington's account, which also carries
  its donations and lunch orders.
- Stripe return URLs of a linked session carry the BISS row uuid, which
  Burlington's Stripe users can read. With the BISS masjid id it reads the payment
  status and a settled row's WhatsApp link, and reopens checkout on an unpaid row.
  Closing it needs the public form page to recover the uuid from submit-time storage.
  Accepted for launch: Burlington's Stripe users are the masjid's own staff.
