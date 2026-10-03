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

## 2026-09-16 — Organisation switches: default-on modules beside the opt-in grants, every flip audited

**Decision.** A SuperAdmin can switch a screen off for one organisation. The trigger is
Burlington Islamic Sunday School (org 18, a school with no website): Web Pages Management,
Announcements, Events, About Us, Photo Gallery, Notifications, Contact Requests, Programs, Zakat
Calculator and Jummah Lunch are noise for its office. No existing organisation changes until a
switch is flipped.

1. **Two kinds in `config/capabilities.php`.** Grants stay opt-in: `web_pages`, `jummah_lunch`,
   `school_calendar`, `crm`, `assistant`, and the new `form_editing` (off everywhere). Twelve
   modules are ON for every org type: `website`, `announcements`, `events`, `about_us`, `gallery`,
   `push_notifications`, `contact_requests`, `programs`, `zakat`, `broadcasts`, `flyer_studio`,
   `impact_report`. Their labels are the sidebar titles. Storage is the existing
   `capability_overrides`, with no backfill.
2. **Absent means on, and a module read fails open.** `Masjid::moduleIsOff()` says "off" only for
   a key in `Masjid::MODULE_KEYS` that the loaded config also knows as a module. The admin
   payload's `capabilities` stays grants-only; the new `modules_off` lists what is off. Neither
   deploy order, nor the stale config cache between `git merge` and `config:cache`, hides a
   screen or refuses an intake.
3. **Admin side only.** Every module's admin API carries `capability:<key>`, and SuperAdmins pass.
   The side doors follow the organisation with NO SuperAdmin bypass: the Broadcasts announcement
   and push channels (at compose and at delivery) and the Assistant's announcement, event and flyer
   tools. The admin header search is the one exception: it drops switched-off announcements and
   About Us for the organisation's own admins, while a SuperAdmin still finds them (the sidebar
   lists the screen under "Switched off"). Public intake that feeds a switched-off screen refuses rather than
   silently dropping: contact-us answers 403 with a sentence, and program sign-up answers like a
   CRM-off tenant. Public and mobile READS never follow a module.
4. **`website` is not `web_pages`.** `web_pages` means the organisation's own admins may edit the
   site (off by default). `website` means the organisation has a site (on by default). The Web
   Pages routes need both. The owner's sidebar now reflects the organisation: Burlington, MEC and
   Al-Razi keep Web Pages Management in it, while Jummah Lunch and School Calendar move to a
   "Switched off for {org}" list wherever the organisation lacks them.
5. **Form editing without the website builder** (owner, 2026-09-16: yes). A standalone editor
   opens from Form Responses. The forms WRITE API (store, update, destroy) takes `web_pages` OR
   `form_editing`; reads, responses, staff codes and the public submit stay ungated. There is no
   delete button, because `FormsController::destroy` does not guard page placements.
6. **Page builder.** The palette stays global. `SectionType::requiresModule()` (exhaustive, no
   default arm) and a `module_off_note` computed from the ORGANISATION's switches tell whoever is
   building the page what a section will not be able to show.
7. **Every flip is audited.** `masjid_capability_changes` (append-only, no FKs) takes a row for
   every catalogue, CRM, Assistant and directory-listing flip, no-ops included, inside the save's
   transaction, plus a `Log::warning`. `GET /api/admin/masjids/{id}/capabilities` (SuperAdmin
   only) serves the switch panel: groups, defaults, overrides, live-section counts and the last
   25 flips.

**Alternatives.**
- **Reuse the app-drawer pivot (`masjid_mobile_app_features`).** Rejected: it governs the mobile
  app, has a crash history and a cache, and its keys are not admin screens.
- **A separate module registry with its own column.** Rejected: it needs a generated TypeScript
  mirror (no PHP on the dev machine), its middleware failed open on unknown keys, and it mapped
  screens by URL prefix.
- **Put modules in `capabilities` and let the SPA's `=== true` hide them.** Rejected: the built
  assets travel separately from the PHP, so the SPA can reach production first, and every
  default-on screen would vanish until the backend caught up.
- **One `web_pages` key for both questions.** Rejected: Burlington (owner-run site, `web_pages`
  off) and BISS (no site) are the same data state.
- **Split the CRM switch.** Deferred: it moves the family portal and public registration gates.
- **Tie Zakat to Stripe state (`canAcceptDonations`).** Rejected: the calculator is not a payment.
- **Filter the page-builder palette by module.** Rejected: it contradicts the global-palette rule,
  and attach mode bypasses it anyway.
- **Let the SuperAdmin bypass the side doors.** Rejected: delivery and the organisation's menu
  must agree. The owner cannot post the Announcements channel for an organisation with
  Announcements off without switching it on first.

**Rationale.** Default-on modules on the catalogue that already exists reuse one reader, one
writer, one gate and one lint. Absent-means-on makes shipping inert for every existing
organisation; only org 18 is expected to hold module overrides after rollout.

**Known limits.**
- Not switchable yet: the app-drawer screens and the CRM's parts (one `crm` switch covers
  Families, Classrooms, Import Roster and Teachers). Services, Splash, Donation link, Giving,
  Properties, Appointment Requests and the prayer tabs became switches in wave 2 (next entry).
- A new organisation starts with every module its type is offered switched on, so the owner flips
  each one per organisation.
- Switching a content module off leaves what is already published visible on the website and in
  the app, with no editor. `in_use` counts page sections only, not app content.
- Zakat off does not stop the public calculator, which answers from the last stored price.
  Programs off 404s shared offering links.
- A generic section bound to About Us through `settings.bind` gets no `module_off_note`.
- Rollback: flip the switch back (audited). For code, `git revert` the commits on main and ship;
  production `bin/deploy` only fast-forwards and refuses `--ref`.

## 2026-09-16 — Organisation switches, wave 2: prayer times, giving and the masjid screens

**Decision.** Seven more screens become modules, on the same catalogue, gate and ledger as wave 1.
The owner's answers of 2026-09-14 pick every branch. No existing masjid changes until a switch is
flipped.

1. **Seven modules, appended in this order:**
   - `prayer_times` (new group `prayer`): the Details screen's Prayer Calculation, Iqama Settings and
     Jumaa Settings tabs, placed in the panel by a config `where`. One switch; Jumu'ah is not split
     out (owner Q6).
   - `splash`, `services`, `donation_link`.
   - `giving`: the Giving Dashboard with Fund Detail, Donation Funds, Donations, Recurring Donations
     and Year-End Statements.
   - `properties`: rent is not a gift.
   - `appointment_requests`.

   Donation link stays apart from Giving (Q7): MEC and NAFIS have a link and no Stripe.
2. **Any org type can have them; outside masjids they start off** (Q1). `Masjid::MODULE_DEFAULTS`
   holds per-type defaults. Every module is on for a masjid. `splash`, `services`, `donation_link`,
   `giving` and `properties` are off for a school or community organisation until a SuperAdmin
   switches one on.
   - `modules_off` keeps its meaning: offered here and switched off.
   - The new `modules_on` lists not-offered modules a SuperAdmin switched on. The SPA lets a
     masjid-only menu item through for that one organisation.
   - The gate says "not switched on", not "switched off", for a module the org type is not offered.
   - On a stale config, `moduleIsOff` answers the type's default with overrides unread.
   - There is no protective-override migration. The pre-flight shows schools 14, 16 and 18 hold no
     rows behind these screens. Their admins lose only typed-URL API access to screens their menu
     never showed.
3. **Gates.**
   - Money and appointment gates sit inside `crm`, per prefix.
   - Services gates everything except its index. Broadcasts, Friday lunch and About Us read the
     index and keep working (Q8).
   - Never gated: Stripe Connect and the forms-card Stop button, zakat settings, offerings and fee
     plans, contacts show, the Impact Report, Mobile App Features, `prayer-calculation/options`.
4. **Money already charged is never refused.**
   - Webhooks, receipts and receipt emails never check a module.
   - `GivingSwitch::noteArrivalIfOff` logs a warning once per donation or monthly commitment.
   - While Giving is off, the app's checkout refuses and its funds list is empty (Q2: yes).
   - Refusing gifts for CRM-off organisations (Q2b) is not part of this change.
5. **Giving cannot be switched off while a monthly gift can still charge** (Q4: block).
   - Counted: gifts Stripe has linked that are not cancelled, and a gift marked cancelled here that
     Stripe says it is still billing (only the Stripe dashboard can stop that one).
     - Stripe is asked only about a cancelled row with a gift booked after its `canceled_at`. The
       local timestamps cannot decide it, because a late or replayed invoice webhook also books
       after a cancel. If Stripe cannot be asked, the row counts.
     - This check is stricter than the plan, which read only the local status (risk 10). The owner
       is told.
   - The panel gets a 422 naming the count, and no ledger row is written.
   - Monthly-gift checkout pages opened in the last 24 hours block too, with their own 422 that says
     wait, never cancel: the admin cancel marks an unlinked row cancelled and leaves its page
     payable, so Stripe would then bill a gift marked cancelled. There is no "switch off anyway";
     the flip goes through once the pages expire.
   - The member recurring-giving verbs stay untouched.
   - A switch never cancels, pauses or changes a gift.
6. **Stripe Connect moves; it is never switched.** With the CRM on, the Details screen shows an
   Online payments tab for every school or community organisation, and for a masjid while its
   Giving is switched off (`showsOnlinePaymentsTab`; owner, 2026-09-14, see Known limits). Every
   pointer to Connect (FormBuilder, the SuperAdmin forms-card dialog, the switch panel) asks
   `connectPlace` and names the place by its sidebar title.
7. **Manara's prayer pushes follow Prayer times** (Q3: yes). `prayers:send-due`,
   `prayers:daily-resync` and the iqama-save sync skip a switched-off organisation. The public reads,
   the TV board and both apps' local schedulers are untouched.
8. **Appointment Requests is a switch**, with the wave-1 intake refusal on its public form.
   - Mobile App Features is not a switch: it IS the app-drawer switch.
   - The Hadith, Adhkar and Tasbih library is not a switch either: platform content behind `super`
     routes.
   - Their sidebar hygiene is a separate task (Q10).
9. **Receipts for a non-masjid.**
   - PDFs drop "intangible religious benefits" and state 501(c)(3) status only with a tax ID.
   - The emails cannot see a tax ID, so they state neither.
   - Masjid output is byte-identical, pinned against the c0f6a72 blades.
10. **Splash off freezes the editor only.** A live splash runs to its end date (Q9).
11. **Facts before a flip.** `App\Support\ModuleFacts` prints live counts for Giving, Prayer times
    and Splash under each switch and in its confirm dialog.
12. **No override outlives its code.**
    - To revert: flip the keys back on while the code is live.
    - The revert commit carries an idempotent migration that strips the keys, with NULL-actor
      ledger rows.
    - Before any re-ship, check that no override names a wave-2 key.

**Alternatives.**
- **Fold Donation link into Giving.** Rejected (Q7).
- **A separate Jumu'ah switch.** Deferred (Q6).
- **Warn instead of blocking on live monthly gifts.** Rejected (Q4). Resume and change-amount would
  then have had to follow Giving.
- **Keep app checkout open while Giving is off, logging arrivals or refusing only monthly gifts.**
  Rejected (Q2).
- **Put Stripe Connect behind Giving.** Rejected: Friday lunch, program fees and form card payments
  charge through the same account.
- **Protective `true` overrides for the schools at deploy.** Not needed: they hold no data there.

**Rationale.** The masjid screens were masjid-only in the menu but open to any org's admins by
typed URL, and money moved through them with no per-organisation off switch. Per-type defaults make
the menu and the API agree. `modules_on` lets a SuperAdmin hand one school one screen without
inventing a second catalogue. The money rules come from one principle: Manara refuses to OPEN new
money for a switched-off organisation, and never refuses money that already moved.

**Known limits.**
- **Frozen data with no editor:**
  - fixed iqama times stop at their last end date;
  - khateeb and khutbah titles go stale;
  - a live splash runs to `ends_at`;
  - the donation link and services list keep publishing.
- **Phones re-arm prayer alerts on their own, unevenly.** Android re-arms daily from its cache. iOS
  arms 6 days ahead and re-arms on open, or when iOS grants a background refresh, so an iPhone left
  unopened can stop alerting after about 6 days. Iqama times saved while the switch is off reach
  iPhones only on the next open. The panel and the catalogue description say so.
- **Money can still arrive after a flip:** one-time checkout pages opened in the previous 24 hours,
  a checkout that raced the flip, and delayed bank debits. All are booked, receipted and logged.
- **Anyone can hold the Giving switch-off.** The app checkout needs no login, so a monthly-gift
  checkout page opened just before a flip refuses it until 24 hours after that page opened. Opening
  pages again and again holds it for as long as that goes on. There is no override (Q4: block), and
  expiring open Checkout Sessions from Manara is not built.
- **Admins still see gifts** as giving history on a member's record and in Impact Report totals.
- **Org 1 has 7 `donation_links` rows** behind a `hasOne`, so which one serves depends on
  database order.
- **RESOLVED 2026-09-14: school and community admins with the CRM on had no clean Stripe Connect
  screen** (Al-Razi 14 and BISS 18). B10 showed Online payments at a non-masjid only while Giving was
  switched on there, and FormBuilder's pointer sent everyone else to the Giving Dashboard, whose
  funds and stats calls answer "Giving is not switched on for this organisation."
  - **Owner decision (2026-09-14, verbatim):** "yes show the online payments tab for schools too".
    The question named schools and community organisations, so it applies to every org type that
    is not masjid.
  - **The rule now:** the tab renders when `crm_enabled && (org type is not masjid || modules_off
    includes giving)` (`showsOnlinePaymentsTab`, resources/vue-app/core/access/orgAccess.ts). A
    masjid is unchanged: the tab only while its Giving is off.
  - **Pointers:** `connectPlace` answers `online_payments`, `giving_dashboard` (a masjid with Giving
    on) or null (no CRM, so no screen can show the panel), and `connectPlaceTitle` names it by
    sidebar title ("School Details › Online payments"), never "Settings". FormBuilder links to it
    (`#online-payments` opens the tab in a cold new browser tab once the organisation loads), says
    the CRM is needed when there is no place, and the SuperAdmin forms-card dialog computes the
    PARENT's place from the parent's own record. On the switch panel, Giving off at a non-masjid
    says Stripe setup stays where it is.
  - **The panel's words follow the org.** Where the org takes no gifts (a masjid with Giving off, a
    non-masjid Giving was not switched on for), StripeConnectPanel says "card payments", not
    "donors can give". On the tab it explains a 403 (no `manage donations`) instead of an empty
    pane.
  - **Linked child orgs (BISS, org 18, linked to Burlington Masjid, org 1).** What the tab shows,
    from the code at 245777c:
    - `connect/status` carries `forms_card_via` for a linked org
      (StripeConnectController.php:99, `FormsCardAccountController::viaSummary`). The panel's state 0
      wins over every other state: "Card payments for forms go through {holder}", a ready or refused
      badge, and no Connect or Resume button.
    - Onboarding is refused for a linked org: 409 in StripeConnectController.php:44-49, and a
      `LogicException` backstop in StripeConnectService.php:39-41.
    - `FormChargeAccount::for()` (app/Services/Stripe/FormChargeAccount.php:68-87) checks the link
      FIRST (:74). A linked org never charges on an account of its own. It charges on the holder's
      account only while `chainProblem` (:167-202) finds nothing: the child has no account of its
      own (:173), the link equals `parent_id` (:185), the holder is live, not itself linked, has an
      `acct_` id and can charge.
    - If a linked child ever had its own account, card would be REFUSED (`has_own_account`), never
      sent to the child. The only way there is a race: the onboarding link check (StripeConnectController.php:44) is not locked,
      and the id is written after Stripe's account create (StripeConnectService.php:49), so a link
      set in that window is possible. Pages already opened stay pinned to the holder
      (`form_responses.charge_account_id`).
    - `masjids_active_stripe_account_unique` (2026_08_20 migration :88, :107, :127-128) is unique
      on live, non-empty `stripe_account_id`, so the holder's id can never be copied onto the child.
    - A link is refused while the child has an account (`linkProblem` → `has_own_account`).
    - **So the tab never invites a linked child to move its form card payments.** No backend field
      was needed. The `has_own_account` wording was corrected: it is only ever read back for a
      linked org, and the old text said its forms charge on its own account, which they do not.
  - **Still open:** an UNLINKED child org (a parent exists, no link yet, or a link revoked) is
    offered "Connect with Stripe". Connecting makes its forms charge on its own account, and a later
    link to the parent is refused (`has_own_account`) until a SuperAdmin clears that account. The
    tab does not warn about this.
  - **The offerings hint for `org_cannot_collect` follows the org too (2026-09-14).** It said
    "Finish Stripe onboarding from the Donations screen", a screen Al-Razi (14) and MAS Youth
    Charlotte (19) do not have. `registrationStateHint` (useOfferingDisplay.ts) now names
    `connectPlaceTitle(connectPlace(...))` ("on the Giving Dashboard" or "under School Details ›
    Online payments"), or says the CRM is needed when there is no place. For a linked org
    (`forms_card_via_masjid_id` set, BISS 18) it says program fees cannot take cards: offerings ask
    `Masjid::canAcceptDonations()` (app/Models/Masjid.php:868, OfferingRegistrationState.php:226,
    OfferingsController.php:346), which reads the org's own account only, and the link covers forms
    (FormChargeAccount.php:14-15). It suggests a free plan or the Manara contact, never onboarding,
    which answers 409 for a linked org (StripeConnectController.php:44-49). FormBuilder's card
    warning keeps "on the Giving Dashboard" for a masjid with Giving on.
- **A SuperAdmin who opens a not-offered screen by typed URL sees no switched-off notice.**
- **Public checkout, the funds list and the appointment intake still ignore `crm_enabled`** (Q2b is
  not built).

## 2026-09-14 — App members can delete their account: the login goes, the office's record stays

**Decision.** A member who signed in through an organisation's app can delete the account from the
app (`DELETE /api/mobile/masjids/{home}/me`) or from a public page (`/account-deletion`). Both run
one service, `App\Services\Member\MemberAccountDeletion`. The owner's answer (2026-09-14, side-menu
spec decision 7): "Remove login, keep office records." This is stage S1a of the side-menu spec; the
R0 app builds call it.

1. **What always happens.**
   - Every token the contact holds is deleted.
   - Every handset it claimed is released (`mobile_app_users.contact_id` back to NULL). The device
     row stays and still receives broadcasts to everyone.
   - Its service interests are deleted, and its outstanding sign-in codes (by contact and by
     address).
   - `verified_at`, `login_enabled_at`, `password` and `password_set_at` are cleared.
   - A `Log::warning` records the outcome, the reasons a record was kept and the counts, with no
     address in it.
2. **When the contact itself is deleted.** Only when app sign-up created it (`signup_source = 'app'`,
   the one value MemberSignupService writes) AND the office holds nothing about the person:
   - no office-filled column on the contact (phone, notes, placeholder or import batch, SMS consent,
     avatars, a family login, a revocation or a password), and an `email` still equal to the
     `login_email` sign-up wrote;
   - no row by contact id in contact cards, credentials, login events, donations, recurring gifts,
     group memberships (either side of a guardian edge), group messages, threads, thread reads, meal
     orders, registrants or registrations (receipts hang off donations);
   - no form response or appointment request from the same address in the same organisation;
   - no broadcast that named the contact as a recipient.

   Otherwise the contact stays exactly as the office wrote it, and only the login goes. When a
   family login was on, the access history gets a `revoked` row with no actor. `login_revoked_at` is
   never set, so the person can sign up again later.
3. **Outside `crm`.** `DELETE .../me` and `DELETE .../me/device` (moved) sit in their own group with
   `auth:family`, `member.active` and `family.tenant`. An organisation can switch its CRM off after
   people signed up, and App Store 5.1.1(v) and Google Play both require deletion wherever sign-up
   exists. Signing in, claiming a handset and interests stay behind `crm`.
4. **Every body from these routes carries `data`.** Success is `{"status":"success","data":{}}`. The
   shared JSON renderer's 401, 403 and 429 for these two routes gain `data: {}` through a `respond()`
   hook keyed on the route names `mobile.member.me.*`. The member sign-in 410 carries `data: {}` too.
   Other API error bodies are unchanged.
5. **The public page** (Google Play's required web link).
   - Tenant-neutral, on this app's host, before the SPA catch-all, CSRF-protected, readable without
     scripts.
   - The organisation picker is the app directory (`Masjid::listed()`), nothing more.
   - Asking for a code answers the same page, and mails a code, for every address.
   - The code is an app sign-in code with the purpose inside the HMAC: a sign-in code cannot confirm
     a deletion and a deletion code cannot sign anybody in.
   - Only after the right code does the page say whether there was an account and whether the office
     keeps records.
   - Its two POSTs use the app door's own `member-login` and `member-verify` limiters, so the two
     doors share one allowance per address.

**Alternatives.**
- **Also erase contacts the office created that hold no records** (decision 7's alternative).
  Rejected by the owner: a contact staff typed is the office's, even when empty.
- **Soft-delete the contact.** Rejected: the person's name and address would stay in the CRM, which
  is not deletion.
- **Set `login_revoked_at`.** Rejected: that is the office's lever, and it would bar the person from
  ever signing up again.
- **Keep `DELETE /me` behind `crm`.** Rejected: switching the CRM off would make deletion impossible
  for existing members (spec critique M5).
- **A separate code table, or a `purpose` column.** Rejected: a new column breaks sign-in between
  deploy and migrate. The purpose in the digest needs no schema change and keeps one set of TTL and
  attempt rules.
- **Mail a code only when the address has an account.** Rejected: the request's timing would then say
  which addresses have accounts.
- **Add `data` to every API error body.** Rejected: other clients parse those bodies today.

**Rationale.** `contacts` is the CRM, and several foreign keys cascade from it, so a member's button
must never remove a gift history, a guardian edge or a roster row. What belongs to the person always
goes: the login, the sessions, the phone's link to them and their notification choices.
`MemberAccountDeletionCoverageTest` walks the schema and fails when a new contact column or
`contact_id` column is not classified, so the office-data list cannot silently fall behind.

**Known limits.**
- A kept contact keeps its `login_email`, so the office can see which address signed in and can turn
  a family login back on.
- A name staff corrected after sign-up is not detectable. Such a contact, with nothing else on file,
  is erased.
- Recurring gifts are not cancelled. A donor with a live monthly gift keeps it, and so keeps the
  contact.
- `email_suppressions` and `sms_suppressions` are keyed on the address and survive, by design.
- The page offers only directory-listed organisations. A member of an unlisted one deletes from the
  app or asks the office.
- A contact created inside a child organisation by the older MEC TestFlight build is deleted through
  that organisation (in the page, if it is listed). R0 builds keep the member realm on the home
  organisation.
- Rollback: `git revert` on main and ship. There is no migration.

## 2026-09-14 · Account deletion, fix round 1: who may delete, and whose family login it ends

**Decision.**
- The member realm now requires a `member` token (`member.token`, EnsureMemberToken) on both member
  route groups. It runs after `member.active` and refuses with 403 and `data:{}`. A child's hand-off
  token or a family-portal token minted on the same contact can no longer delete the parent's account
  or release their phone.
- App sign-in no longer links an address to a contact through the office's `email` column when that
  contact already has a different `login_email`. The reader of a household mailbox gets a contact of
  their own instead. So a member token's contact always has the redeemed address as its `login_email`.
- Member tokens are now named `member-token:login-email` (Contact::MEMBER_TOKEN_FOR_LOGIN_EMAIL).
  `MemberAccountDeletion::delete()` takes the proven address. The office-granted family login (its
  `login_enabled_at`, password, codes, family and hand-off tokens) ends only when that address is
  `login_email`. The same holds when the contact has no second address that could have been proved.
  Otherwise only the app account goes: member tokens, handsets, interests and `verified_at`. The log
  records `family_login_kept`.
- The public page names the apps and publisher (config `member.account_deletion`) and says what is kept
  and for how long. It makes no numeric log-retention promise until `ACCOUNT_DELETION_LOG_RETENTION_DAYS`
  is set.

**Alternatives.**
- Also match `email` on the web page. Rejected: the other reader of a household mailbox could then
  sign that parent out of the app. The only member it would help holds a pre-change token, which
  expires within 30 days and can still delete in the app.
- Record the proven address in a new token column. Rejected for now: it needs a migration, and the
  token name carries the one fact needed.

**Known limits.**
- Needs owner confirmation: the publisher name "Hope Tech Inc." and the log retention period.
- A pre-change member token on a contact whose `email` differs from its `login_email` keeps the
  family login when deleted, even if that member really did prove `login_email`. This is the safe
  direction, and such tokens expire within 30 days.
- A household-address sign-in now creates a second contact with that `email`. That was already true
  for a household address two contacts share.

## 2026-09-14 · Account deletion, fix round 2: older sessions follow the owner's rule, and what still needs the owner

**Decision.**
- A member token minted before this deploy is named `member-token`, so it cannot say which address it
  proved. Deleting through one now ends the family login exactly as the owner decided on 2026-09-14:
  every token, `login_enabled_at`, the password and the family codes go, and a `revoked` event is
  written.
  - This supersedes round 1's known limit "A pre-change member token ... keeps the family login".
  - Round 1 kept the login to protect another parent who reads a household mailbox. The owner never
    agreed to that exception, and it contradicted "revoke all family tokens; clear login".
- The exception now applies only to a caller that proves an address other than `login_email`.
  - Neither door does that today: the app passes `login_email` or nothing, and the page matches
    `login_email` only. So every real deletion ends the family login.
  - The branch stays as a guard for a future door that matches `email`. A service-level test pins it.
- Round 1's narrowing of `MemberSignupService::resolveContact` stays on this branch, flagged below as
  needing the owner. A new test pins the ordinary case it must not break: a contact with the office's
  `email` and no `login_email` still links on sign-in, adopts the address, and gets no duplicate.

**Needs the owner before merge.**
1. **The legacy exception.**
   - Put it as: "Members who signed in before this update, and whose office record has a different
     email, would keep the family portal login for up to 30 days after deleting their app account.
     Accept?"
   - If accepted: in `MemberAccountDeletion::delete()`, drop `$provenAddress === null` from
     `$endsFamilyLogin` (round 1's behaviour, 5034db3). Flip
     `an_older_session_that_cannot_say_which_address_it_proved_ends_the_family_login_as_the_owner_decided`,
     and record the answer here.
2. **The sign-in narrowing.** This supersedes part of 2026-09-08, "an email already on file must LINK
   to the existing contact".
   - An address that matches only a contact's `email`, when that contact already has a different
     `login_email`, now gets a new app contact instead of the link.
   - The cost: a second contact for that person in the CRM, and "Your monthly giving" does not show
     gifts recorded on the office contact.
   - If declined: remove `->whereNull('login_email')` from `resolveContact` and delete
     `signing_in_with_a_household_address_does_not_become_the_parent_whose_login_it_is_not`. The
     deletion rule above does not depend on it.
3. **Page facts for Play.**
   - The developer name exactly as the Play listing shows it. The config default "Hope Tech Inc." came
     from the assistant email template; the Stripe account has the same name, but it was not checked
     against Play.
   - A server-log retention period.

**S1a deploy notes (in order; none of this has been done).**
- **CI.** Rsync this branch to /root/manara-ci, excluding bootstrap/cache. Run MemberAccountDeletionTest,
  AccountDeletionPageTest, MemberAccountDeletionCoverageTest, MemberRecurringGivingTest,
  AppSignupCodeTenantIsolationTest and ModuleSideDoorsTest, then the full suite. Confirm the box ran
  this code by file hash, not by exit code.
- **Staging.** Drive `DELETE /api/mobile/masjids/{home}/me` and `/account-deletion` with the home org's
  `crm_enabled` on, then off. Confirm `mobile_app_users.contact_id`, the tokens and `login_enabled_at`
  from the database.
- **Production .env.** Once the owner answers item 3, set `ACCOUNT_DELETION_PUBLISHER` and
  `ACCOUNT_DELETION_LOG_RETENTION_DAYS` through the parse-check and auto-rollback edit, never a bare
  `config:cache`.
- **Store gate.** Before any R0 submission, link https://masjid.hopetechapps.com/account-deletion from
  each app's privacy policy and from Play's data-safety deletion field.
- **Announce with the deploy.**
  - The sign-in narrowing (item 2), if it stays.
  - Deleting an app account also ends that person's family portal login.
  - Office staff can turn the portal login back on.

## 2026-09-15 · App rate limits: per phone and per network, instead of per IP

**Problem.** Seen on staging during the 2026-09-15 iPhone walk-through. Two limiters keyed on the IP alone
sat in front of a first launch:
- `throttle:device` was `Limit::perHour(10)->by($request->ip())`, covering POST and PUT `/api/mobile/user`,
  the heartbeat and `GET /user/masjid`.
- `throttle:mobile` was `Limit::perMinute(60)->by($request->ip())` around every `/api/mobile` route, and it
  runs first.

Every phone behind one public address shares one IP: a masjid's Wi-Fi, a festival venue, carrier NAT. One
iOS launch sends about ten requests. It awaits the app-config gate, then registration, the masjid, its
features and its prayer settings together. A heartbeat follows, then the home screen's iqama settings,
announcements and events. A refused awaited payload leaves the iPhone on the splash screen. So everyone
on the network shared ten device calls an hour and about six launches a minute. The `throttle:mobile` 429
was also `{status, message}` with no `data`, which the iPhone cannot decode.

**Decision.** Four limiters. Within each one, the limits are checked left to right:

| Limiter | Routes | Per phone / minute | Per phone / hour | Per network |
|---|---|---|---|---|
| `mobile` | every `/api/mobile` route (outermost) | 60, only when the request names its device | none | 1800 / minute |
| `mobile-checkout` | POST `/masjids/{id}/donations/checkout` | none | none | 60 / minute |
| `device` | POST, PUT `/api/mobile/user` | 10 | 60 | 600 / hour |
| `device-activity` | POST `/user/heartbeat`, GET `/user/masjid` | 20 | 120 | 1200 / hour |

- **"Per phone"** is the request's `device_id` (body or query), or else an `X-Device-Id` header, hashed with
  the IP. With the IP in the key, a leaked device id cannot be used to lock a phone out from somewhere else.
  No shipped app sends the header. It is read so a future build can name its phone on read routes.
- **A request that names no device.** In `device` and `device-activity` it is keyed on the IP alone, and all
  four endpoints reject it at validation anyway. In `mobile` the per-phone layer is left out entirely, because
  an IP fallback there would bring back the old shared bucket. Only the network ceiling applies.
- **The order matters, and only within one limiter.** Laravel stops at the first refusing limit without
  counting the later ones in the same limiter. Limiters nest, though: `mobile` counts a request before
  `device` sees it. So:
  - A phone that names itself and loops costs its network at most 60 requests a minute of the `mobile`
    ceiling. After that, `mobile`'s per-phone layer refuses it without adding to the network count.
  - Calls refused by `device` or `device-activity` never reach that limiter's own network ceiling. They
    have already been counted by `mobile`, within that 60.
  - Today the apps name their device only on the device and member routes. A phone looping on a read
    route (masjid, features, prayers) counts straight against the network ceiling. It would have to send
    1800 a minute, 30 a second, to refuse its neighbours.
- **Refusal.** 429 with `{status: "error", message, data: {}}` and Laravel's `Retry-After` headers.
  `data` is there because the iPhone decodes every mobile body through `Response<T>`, where `data` is
  non-optional. The message is the same for every layer: "Too many requests just now. Please wait a few
  minutes and try again."
- **Config.** The numbers live in `config/mobile.php` (env-overridable). The provider repeats every
  default, so a config cache that predates the file still applies exactly these numbers.

**Why these numbers.**
- **The network ceiling on every app route (`mobile`, 1800 a minute).** At about ten requests per launch
  (above), that is roughly 180 phones opening the app in the same minute on one address: the crowd leaving
  Jummah, or arriving at the Fall Festival gate. The old limit allowed about six. It is 30 requests a second
  from one address. It has not been load-tested against the droplet; the launch reads (masjid, features,
  prayer settings) are served from `Cache::remember`.
- **Per phone on every app route (`mobile`, 60 a minute).** Six launches' worth. It applies only to
  requests that name their device.
- **Donation checkout (`mobile-checkout`, 60 a minute per IP).** Every call writes a pending donation and
  opens a Stripe session. This is what checkout had under the old group limit, so raising the group
  ceiling loosens nothing here.
- **Registration.** Both apps register once per install: iOS stores the returned id, and Android sets a
  flag and retries only after a failure. A phone therefore needs one or two calls an hour. Each NEW
  `device_id` inserts a `mobile_app_users` row. Repeating an id that already exists inserts nothing and
  returns a 500 (see "Not changed here").
  - 10 per minute stops a retry loop within seconds.
  - 60 per hour is many times real use, so reinstalls and flaky networks are never refused.
  - 600 per hour per network fits 300 people installing at the Fall Festival within the hour, each with
    one retry. It also caps an id-rotating script at 600 junk rows an hour from one address.
- **Heartbeat and lookup.** Neither inserts a row: the heartbeat updates an existing one (and does
  nothing for an unknown id), and the lookup only reads. Both follow launches rather than installs.
  - iOS sends a heartbeat 3 s after every launch and again when its push subscription changes.
  - Android sends one when the process starts and on subscription changes.
  - Current builds never call `/user/masjid`; it stays in this bucket for older installs.
  - Hence the looser per-phone numbers, and a network ceiling twice the registration one.
- **No per-network per-minute guard in the device limiters.** `mobile` already puts one around the whole
  `/api/mobile` group.

**Alternatives.**
- **Raise the per-IP number alone.** One looping phone could still use up a whole venue's allowance. This
  is still partly true for read routes, which carry no device id (see "The order matters").
- **Key on `device_id` alone.** A script inventing ids would be unlimited, and anyone holding a phone's
  id could exhaust that phone's bucket.
- **Key `mobile` on the IP plus the User-Agent.** Phones of one model on one OS version send the same
  User-Agent, so a crowd would still share buckets, and a script can send any User-Agent it likes.
- **Take the endpoints out of throttling.** Every new device id inserts a row, so a script inventing ids
  would be unlimited.
- **Keep one bucket for all four routes.** Heartbeats scale with launches, so a busy Jummah hour would
  spend the registration allowance.

**Not changed here, and needs the owner.**
1. **This raises the public app API's abuse limit from 60 to 1800 requests a minute per address.** That is
   the point of the change, but it is the owner's call before production. That ceiling is also the only
   thing that stops one handset looping on a read route from refusing its network. The complete fix is for
   both apps to send `X-Device-Id` on every request, which is an app change on iOS and Android.
2. **Registering an id that already exists is a 500.** `mobile_app_users.device_id` is unique, and
   `MobileAppUsersController::store` calls `create()` inside a catch-all. This predates the branch, and
   the limiters count those calls like any other. It matters for Android, which keeps one stable id and
   re-registers on each launch until a registration succeeds. If a registration reaches the server but its
   response is lost, every later launch gets a 500 and the "registered" flag is never set.
3. **There is no TrustProxies configuration, so `$request->ip()` is the connecting address.** That is
   right only while nothing proxies the API. If `masjid.hopetechapps.com` is ever put behind a proxy
   (Cloudflare's orange cloud, for example), every IP-keyed limiter would pool unrelated users onto the
   proxy's addresses. Confirm before relying on the per-network numbers.

**Deploy notes (none of this has been done).**
- **CI.** Rsync this branch to `/root/manara-ci`, excluding `bootstrap/cache`. Run
  `MobileDeviceThrottleTest`, `PublicMasjidDirectoryTest`, `DonationFlowTest`, `TvConfigEndpointTest` and
  `TenancyCanaryTest`, then the full suite. Confirm the box ran this code by file hash. None of these has
  been run for this branch.
- **Staging.**
  - Check `php artisan route:list --path=api/mobile -v`. POST and PUT `/user` should show
    `throttle:device`; heartbeat and `/user/masjid` should show `throttle:device-activity`; the donation
    checkout should show `throttle:mobile-checkout`.
  - Send 100 GETs to `/api/mobile/app-config` from one address within a minute. None should be refused;
    the old limit refused the 61st.
  - Register one device, then PUT it ten more times within a minute. The last PUT should be a 429 with
    `"data":{}`.
- **Production.** Only with the owner's OK, since this loosens an abuse limit. The new config file needs
  no `.env` change, and a refreshed config cache must go through the parse-check path.

## 2026-09-17 — The app's menu comes from the organisation switches (supersedes 2026-09-16's "Mobile App Features is not a switch", DECISIONS.md:886, and narrows "Public and mobile READS never follow a module", DECISIONS.md:766)

**Decision.** `GET /api/mobile/masjids/{id}/menu` derives the mobile app's side menu and tab bar
from the organisation module switches, one profile per organisation the app may switch into. A
module therefore now decides TWO things — an admin screen and, where it has one, a row of the app
menu — where until today it decided only the first.

1. **A module is no longer admin-only.** `Masjid::MODULE_KEYS` gains five app-only modules —
   `quran`, `hadith`, `adhkar`, `qibla`, `tasbih` — that have no admin screen and no sidebar item at
   all. They carry `surface => 'app'` in `config/capabilities.php`, which is how the switch panel
   places a row for something with nowhere to live in the admin. Their only effect is one row of the
   app menu.
   - This retires 2026-09-16's line "Mobile App Features is not a switch: it IS the app-drawer
     switch" (:886). The pivot still serves the legacy `/features` list to every installed build and
     is still the app-drawer switch for those builds; it stops being the app-drawer switch at S2b,
     when the cutover moves each row to its module.
   - It also narrows "Public and mobile READS never follow a module" (:766). `/menu` is a public,
     unauthenticated mobile read and it follows the switches — that is the entire endpoint. Every
     other public and mobile read is unchanged, `/features` included.
2. **Visibility is switch-only, and nothing else.** Never "and the donation link has a URL", never
   "and Stripe is onboarded". The app's fallback menu is built from the legacy `/features` when
   `/menu` is unavailable, and it cannot know those things — a kill switch is only worth having if
   what it falls back to is the same menu. `Masjid::moduleIsOff()` fails OPEN, so a stale config
   cache during a deploy can only ever SHOW a row.
   - One documented asymmetry: `/menu` shows Announcements when only Events is on, because the
     drawer entry opens both. Legacy id 10 means Announcements alone.
   - No labels, no icons, no per-user data. Labels and icons are the clients'. On 2026-08-28 a
     server-driven icon list emptied the drawer on every phone.
3. **Two levers, and they are not the same lever.**
   - `php artisan app-menu:kill` makes `/menu` answer 404 for every organisation within a minute.
     Both apps read that as "menu unavailable" and fall back to `/features` + `/orgs`. No `.env`
     edit and no `config:cache`: an emergency control must not be able to cause a bigger outage
     than the one it is fixing.
   - `app_version_settings.navigation` decides which SHELL one organisation's app draws, per
     platform, without a release. Emitted inside `data.ios` / `data.android` of the per-masjid
     app-config, OMITTED when null, read from the HOME organisation's row, applied at the next cold
     launch. Four spellings are accepted — `menu` and `legacy` (the server's vocabulary) and
     `side_menu` and `tabs_drawer` (what the clients were compiled with) — because both clients map
     an UNKNOWN value to the NEW shell, so rejecting a spelling the apps would have honoured turns
     this into a save that reports success and changes nothing.
4. **What `navigation = legacy` does NOT roll back, verbatim from `config/app_menu.php`:**

   > What `legacy` rolls back, honestly: the menu, the store and the drawer. NOT the Android
   > single-activity merge, NOT the iOS HomeView de-nesting and NOT the in-place switch — the legacy
   > shell shares all three. Rolling those back needs a new build, which is what this lever is worth
   > as a release gate.

   This is stated to the owner, not only recorded here. It changes what the flag is worth: it is a
   layout lever, not a rollback of the R1 shell.
5. **The kill row stays SET on production until S2b.** `/menu` then 404s for every device, both
   client lanes ship internal builds freely on the legacy adapter, and the pre-S2b divergence
   between `/menu` (switches) and `/features` (pivot) — production already differs on Qur'an for
   organisations 1 and 13 — cannot reach a tester. Cheaper than gating every build.
6. **Installed builds do not change.** `GET /features` keeps its body, its row order and its icon
   fallbacks; the GLOBAL `/app-config` keeps returning `{"status":"success","data":{}}`; `/orgs`
   gains a theme block and nothing else. `navigation` being omitted while null is what keeps every
   current per-masjid app-config body byte-identical after the deploy.
7. **Telemetry, because the retirement needs evidence and there was none.**
   - `mobile_app_users` gains `app_platform`, `app_version`, `app_build`, filled from the
     `X-Manara-App` header or the body. AN ABSENT VALUE NEVER NULLS A STORED ONE, so the count of
     phones still on an old build cannot erase its own evidence. Nothing authorises on them.
   - `app-telemetry:builds` groups active devices and prints a null build as `pre-R1`.
   - `CountLegacyFeaturesHit` counts each SERVED `/features` response per organisation per day,
     split `tagged` (an R1 build falling back) and `untagged` (a build shipped before R1). It runs
     in `terminate()` inside a catch-all: it can never change or fail that payload.
     `app:legacy-features-report` writes ONE `Log::warning` a day — warning, because production runs
     `LOG_LEVEL=warning`.
   - **Amended 2026-09-17: our own canary is not counted.** `tenancy:canary` probes `/features` as
     organisation 1 about six times a day with no `X-Manara-App`, so every probe was an untagged hit
     on the organisation S3b is gated on. The middleware now skips any request carrying `X-Canary`
     (`App\Support\Canary\CanaryHeader`, the same constant the canary sends). Reports for days
     before that reached production overstate organisation 1's untagged count by the canary's hits.
   - Zero is a floor, not a proof. A cache flush, a restarted box and a day the report did not run
     all look identical to silence.
8. **`app-features:cutover-plan` ships a week before the migration it describes**, read-only, so the
   owner's clock starts early. It prints per organisation and legacy id the pivot value, the
   switch-derived value, the override it would write and what switching that module off also means,
   and raises four findings: an app row off over live content or open intake (blocking), Donate off
   while Giving still has money attached (blocking), an organisation with no pivot rows (a notice —
   `[]` today, eleven rows after, the only change an installed build can see), and a worship row off
   at a masjid (a notice). It checks its own promise: the capability ledger and pivot row counts
   before and after.

**Alternatives.**
- **Keep the app drawer on the pivot and leave the switches admin-only.** Two switches for one
  concept, in two places, is what produced an organisation whose admin screen is on and whose app
  row is off with nobody able to say which was meant. The cutover plan exists because that has
  already happened.
- **Server-driven labels and icons.** Tried, and it is what emptied every drawer on 2026-08-28.
  Labels also have to be localisable, which a server list cannot do.
- **Make `navigation` an enum column.** Accepting the two client spellings would then be a
  migration rather than a validation rule, and the failure that matters here is a lever that saves
  cleanly and changes nothing.
- **Gate every client build instead of leaving the kill row set.** More work per build, and it
  fails open on the build somebody forgets.
- **Delete `/features` now.** No evidence supports it, which is the reason for the counter.

**Not changed here, and needs the owner.**
1. **The four cutover conflict classes.** Resolutions go in `config/app_feature_cutover.php` as
   `org => key => show_in_app | hide_everywhere` and are committed with the S2b migration. The plan
   refuses nothing by itself; the migration refuses to run while a blocking finding is unresolved.
2. **`navigation` is a layout lever, not a rollback.** Item 4. Say it out loud before treating it as
   a release gate.

**Deploy notes (none of this has been done).**
- The whole of S1 is additive. Three migrations, all nullable or new tables.
- **The `/menu` kill row is a NUMBERED DEPLOY STEP, not a thing to remember** (item 5). `AppMenu::killed()`
  reads "no row" as NOT killed and the migration seeds nothing, so a deploy that does not run this ships
  `/menu` LIVE — the opposite of the decision. Immediately after `bin/deploy`, and before telling anyone
  the stage is up:

  ```
  php artisan app-menu:kill --reason="S1: the menu stays dark until S2b" --by="<name>"
  curl -s -o /dev/null -w '%{http_code}\n' https://masjid.hopetechapps.com/api/mobile/masjids/13/menu   # expect 404
  ```

  The second line is the step. The first can succeed against a box whose cache still says live for up to
  60 seconds (`AppMenu::KILL_CACHE_TTL`), so the command's exit status is not the proof — the endpoint is.
  `s1-prod-postcheck.sh` asserts the 404 for the same reason.
- `app:legacy-features-report` needs the system cron already running `schedule:run`.

## 2026-09-16 — App members sign in with an email and password; one password per person

**Decision.** The owner (2026-09-16, reviewing the MEC app): "It should have a simple email
password sign in. Or create account button for that flow." His answers: sign in with email and
password; "Create an account" asks for first name, last name, email and password and confirms the
email once with a code; "Forgot password?" emails a code.

1. **No register endpoint, still.** "Create an account" is `request-code`, then `verify-code` with
   the name and a `password`. "Forgot password?" is the same two calls with only the `password`.
   The password is written in the transaction that burns the code, through
   `FamilyPasswordService::set()`.
2. **`POST /api/mobile/masjids/{id}/auth/password`** signs in. Success is the `verify-code` 200.
   Every failure is the `verify-code` 410, byte for byte, and costs one hash comparison. The
   contact must resolve through the code door's resolver, be allowed member access, have
   `verified_at` and a password, and have the submitted address as its `login_email`.
3. **One password per person.** `contacts.password` is shared with the parent portal. Setting it
   from the app replaces the portal password and ends every other session the contact holds,
   family and hand-off tokens included. It never sets `login_enabled_at`.
   **Provenance, stated because the two halves differ.** The shared password is the owner's: the
   option he chose read "One password per person, shared with the parent portal, since both use
   the same contact record." Ending the other sessions is NOT something he stated — it comes from
   the 2026-09-16 sign-in contract, which routes the write through `FamilyPasswordService::set()`.
   It was described to him afterwards, with the note that a parent who creates an app account is
   signed out of the portal, and he did not object; that is not the same as deciding it.
4. **A password belongs to the address it was chosen under.** Not setting `login_enabled_at` was
   not enough on its own. The app can link an office guardian through a household `email` and let
   whoever reads that mailbox choose a password. When the office then enabled the portal at the
   parent's own address, the address changed but the password and `verified_at` stayed, and that
   password opened both password doors at an address nobody had proved (review finding R1,
   reproduced on the droplet). So `FamilyAccessService` now clears `password`, `password_set_at`
   and `verified_at` whenever it moves a login to a different address, and on the holder when it
   gives an address to someone else (with their tokens), and writes `password_cleared` naming the
   operator. Re-typing the same address clears nothing. A code sign-in that gives an address-less
   contact an address drops any password left on it. The admin modal warns before a change of
   address, and the access history labels `password_set` / `password_cleared` (they read
   "Enabled" before).
5. **The rule is the portal's:** twelve characters (`family.password.min_length`) and the breach
   check, from one method (`SetFamilyPasswordRequest::strength()`). It is checked before the code
   is read, so a short password spends nothing.
6. **Deleting an account still erases what the app created.** `password` and `password_set_at`
   moved from office columns to sign-up columns, and `password_set` is written to the access
   history only for a contact with a family login. Otherwise every account created with a password
   would be kept on deletion.

**Alternatives.**
- **A `/register` endpoint.** Rejected for the reason it was rejected on 2026-09-08: it would say
  whether an address already has an account here.
- **Separate app and portal passwords.** Not offered as a separate choice; the option the owner
  chose specified one password shared with the parent portal.
- **Refuse "Create an account" for an address that has an account.** Rejected: saying so is the
  oracle. The app tells people who already have an account (portal included) to use Sign in or
  Forgot password.
- **Record `password_set` for every contact.** Rejected: that row is an office record to
  `MemberAccountDeletion`, and the portal's access history is about a login the office granted.

**Known limits.**
- A parent who has only ever used the portal must use "Forgot password?" once before the app's
  password sign-in works, because the app requires `verified_at`.
- A parent whose sign-in address the office changes loses their password and must sign in with a
  code at the new address (and choose a password again). That is the price of item 4.
- The refusal's words are about codes ("That code is no longer usable"), because the two doors must
  not differ. The apps show their own sentence for a 410 at the password door.
- The sign-in 429 has no `data` key, as before. The iPhone app cannot decode it.

## 2026-09-17 — "Your password was set": one email to the login address whenever a contact's password is set

**Decision.** Every time a contact's password is written, one short email goes to that contact's
`login_email`. The owner chose this on 2026-09-17, in the coordinator's interview
(`/tmp/manara-plans/ship-plan-2026-09-17.md`): the option **"Yes, send it"**, which read "One short
email to the account's address after any password is set."

1. **Scope: contacts only.** The one password per contact that `FamilyPasswordService::set()`
   writes, reached from three doors: the app's create-account and forgot-password (`verify-code`
   with a `password`) and the family portal's own set-password (`PUT .../password`). Staff
   passwords are out of scope.
2. **One sender, after the commit.** `set()` registers `PasswordSetNotice::afterCommit()` as the last
   step of its transaction. `DB::afterCommit()` waits for the OUTERMOST transaction, which on the app
   doors is the one that burns the code, and Laravel drops the callback when that transaction rolls
   back. So nothing is sent for a refused `verify-code` (wrong, spent or replayed code, a revoked
   contact), a 422 (the request rules, a blank name), or a rolled-back write. The address and the
   time are read inside the transaction.
3. **Nothing in it opens anything.** No password, code, token or link. The first name in the
   greeting is printed only when it looks like a name (`MailGreeting`, which the sign-in code mail
   uses too): the public registration form lets a stranger store a web address as a first name next
   to someone else's address (review finding F1, fixed the same day). It says which organisation,
   which address, when (in the organisation's timezone with PHP's zone abbreviation, which for a
   zone that has none is a UTC offset such as "+03"; UTC if the organisation has no zone), and what
   to do if it was not you. That last sentence names the app only when the person has proved the
   address to it (`verified_at`), and "sign in to the family portal with an emailed code and choose
   Change my password" only when the office has a live family login for them. Then "contact
   {organisation}". Not every organisation has an app or a portal, so naming one they lack would be
   a made-up claim. `verified_at` does not mean the person's installed app has "Forgot password?":
   Android builds before `feat/r1-owner-feedback` have no password sign-in at all. The sentence
   always ends with "contact {organisation}", which works for everyone.
   The subject is the same for everyone ("Your password was set") and does not name the
   organisation, like the sign-in code's. The From name is the organisation, so a lock screen or
   inbox list that shows the sender still names it. The generic subject only keeps the subject
   from naming it a second time. It says "set", not "changed": that is true for a first
   password too, and it does not say whether a password existed before. On an address the app has
   just linked, an earlier password may have been chosen under someone else's address. From name is
   the organisation, the address is `MAIL_FROM_ADDRESS`, and replies go to the organisation's email
   when it is valid, as for the sign-in code. There is a plain-text part as well as the HTML.
4. **Sent inline, not queued.** It holds no secret, so the reason is not `FamilyLoginCodeMail`'s.
   The reasons: it is a security notice, and a queued one waits on the `database` worker, so a
   worker that is down or behind delivers it hours late (`TwoFactorResetMail` is unqueued for the
   same reason); a failed queued mail stays in `failed_jobs` with the family's address and first
   name; and the cost is one mail API call (prod uses `MAIL_MAILER=resend`) on a request that has
   already paid for a bcrypt hash. The price is no retry.
5. **A failed send never fails the change.** The send is wrapped. On failure the password stays set,
   the response is the same success, and `Log::warning('password set notice delivery failed')`
   records the contact id, the organisation id and the exception class. A Resend call that never
   answers counts as a failure because every Resend call has a time limit (`ResendWithTimeouts`:
   5 s to connect, 10 s in all). Laravel's own Resend client has none. Without the limit, a hung call
   would outlast nginx's 60 s, the response would be a 504 with nothing logged, and the PHP-FPM
   worker would stay stuck (review finding F2, fixed the same day). Warning, because production
   runs `LOG_LEVEL=warning`. No address and no exception message, because a transport error can
   quote the recipient.
6. **Removing a password sends nothing.** `FamilyPasswordService::clear()` has two callers. The
   portal's "Remove it" needs a signed-in session, and removing a password grants nothing: sign-in
   codes still go only to the mailbox. The other caller is a code sign-in in the app that gives an
   office contact its login address and drops a password left from another address. An email there
   would go to the newly adopted address and tell its reader the record had a password under some
   other address. On a household address that is a disclosure about another person, the R1
   population from 2026-09-16. The office clearing a password when it moves a login
   (`FamilyAccessService`) does not go through `clear()`, sends nothing, and is recorded in the
   access history with the operator's name.

**Alternatives.**
- **Queue it like most mail.** Rejected for the reasons in item 4.
- **Send it from the controllers.** Rejected: two senders for one fact, and the app's controller
  cannot see the transaction commit. `set()` is the only writer, so it is the only sender.
- **"Your password was changed" when one existed.** Rejected for the reason in item 3.
- **Also notify on removal.** Rejected for the reasons in item 6. If the owner wants the portal's
  "Remove it" to send an email, that belongs in `FamilyPasswordController::destroy`, never in
  `clear()`.

**Known limits.**
- No retry. If the mail provider is down at that moment, this notice is lost and a warning is
  logged.
- A successful `verify-code` with a password now also waits on one mail API call. Only a correct
  code gets there, so the extra time says nothing to someone without the code. If Resend is slow or
  silent, that wait is at most 10 s, and then the warning is logged. The limit applies to every mail
  sent through Resend, queued or not.
- `BroadcastMail` and `GroupUpdateNudgeMail` printed a stored name in their greeting without
  `MailGreeting`. Fixed the same day (owner: "Fix them too"); see the next entry.
- English only, like the sign-in code mail, although the portal has an Arabic mode.
- Nobody can use this to flood an inbox: every app send needs a code from that same inbox, and the
  portal door needs a signed-in session behind `throttle:family`.

Pinned by `tests/Feature/PasswordSetNoticeTest.php`.

## 2026-09-17 — Broadcast and class emails print a stored name only when it looks like a name

**Decision.** `BroadcastMail` and `GroupUpdateNudgeMail` print the stored name in
"Assalamu alaikum {name}," only through `MailGreeting`, as the sign-in code and password emails do.
A value that does not look like a name is left out and the greeting is "Assalamu alaikum,". The
owner chose this on 2026-09-17, in the coordinator's interview
(`/tmp/manara-plans/ship-plan-2026-09-17.md`): the option **"Fix them too"**, which read "Apply the
same small check to both emails, so a name that isn't a real name falls back to 'Assalamu
alaikum,'."

1. **Why.** The public registration form saves any first word as a first name next to any address
   (finding F1 in the entry above). Production also has imported contacts whose name fields hold
   pieces of an email address. A broadcast goes to every contact with an address. Most mail apps
   turn a web address or an email address into a link, and here it would sit in a genuine email
   from the organisation.
2. **Where.** Both classes clean the name in their constructors. `BroadcastMail` is queued, so the
   raw value never reaches `jobs.payload` or `failed_jobs`. Its `content()` checks the name again,
   so a broadcast that the old code queued before the deploy is greeted safely too. The class email
   is sent from inside `SendGroupNotificationJob` and is not queued itself. It builds its greeting
   in `build()`, and its view prints `$greeting` instead of its own if/else.
3. **What is checked.** The class email greets by first and last name together
   (`GroupNotificationRecipientResolver`), and by `users.name` when it notifies a teacher. The check
   covers the whole string: an email address in the last name drops the whole name, and so does a
   full name longer than 40 characters. The broadcast greets by first name only.
4. **What counts as a name** (all four mails; review finding G1, fixed the same day). The first
   version allowed a combining mark anywhere after the first letter and only looked at the character
   right after a full stop, so a zero-width mark after each dot got through:
   "www.[U+034F]evil.[U+034F]example" and "paypal.[U+FE0F]com" were printed, and the
   reader saw the address. `MailGreeting` now leaves the name out when it holds any Unicode
   default-ignorable character or one of the two letters or marks that look like a full stop
   (U+A4F8, U+1D16D; ICU's confusables data), when a combining mark follows anything but a letter or
   a mark, or when a full stop comes before a letter with or without marks between. Arabic vowel
   marks, accents typed as separate marks and Devanagari vowel signs still print.
   `PasswordSetNoticeTest` checks the list against ICU's data for every code point (it skips if
   ext-intl is missing; the droplet's PHP has it). Letters that look like "/" or ":" (a Japanese
   "ノ", a Devanagari visarga) are allowed: real names use them, and with no full stop they cannot
   spell an address.
5. **What production holds** (owner: "also check stored first names that look like URLs").
   A read-only count on 2026-09-17 (prod at `138ae37`, live contacts only, no names read out):
   - 522 live contacts: org 1 (Burlington) 503, org 14 (Al-Razi) 17, orgs 2 and 13 one each.
   - **No stored first or last name holds "://" or "www."**, and none holds an invisible character or
     a full-stop lookalike. Only three values have a full stop before a letter, and all three are
     email-address pieces (below). Nothing the first version of the check printed is dropped by
     this one.
   - First names left out: 2 of 522, both in org 1 and both imported. One is a whole email address
     (the contact has an email, so broadcasts now greet it with "Assalamu alaikum,"). The other holds
     "&" and has no email.
   - Last names left out: 206 of 486, all in org 1. 189 are imported placeholders shaped
     "{word} {number}" (placeholders get no broadcast, and none has an email). The other 17 hold
     "/", "(", ")", "&", or "@" and a domain (2, both with an email). All but one were imported;
     that one came from the Jummah lunch sign-up. Only the class email prints a last name, it goes
     only to a live family login, and org 1 has none, so no class email reaches any of them today.
   - Orgs 13 and 14 hold all 11 contacts with a login address (1 and 10) and lose no name. `users.name`: 22 staff
     names, none left out.

**Alternatives.**
- **Clean names when they are saved** (registration form, imports). Not done here: the rows already
  stored would still reach these emails.
- **No name in these greetings at all.** Not chosen: the owner picked the fallback, which keeps the
  name for real names.

**Known limits.** These mails still print a stored or typed name without `MailGreeting`. Each needs
its own decision, because the greeting is not the only place the name or other typed text appears:
- `FormSubmissionReceipt`: the name typed into a public form, sent to the address typed into the
  same form. It also lists every attendee name typed into that form.
- `DonationReceiptMail` and `AnnualStatementMail`: the contact's first and last name ("Valued donor"
  when both are blank). The attached PDF prints the same name after "Dear".
- `ContactRequestReply`: the name typed into the public contact form. The email also quotes the
  original message. It is sent only when an admin replies.
- `TwoFactorResetMail` and `AccountAccessMail`: `users.name`, a staff account's name. Only a
  signed-in dashboard user writes it (an admin, or the staff member on their own profile).

Pinned by `tests/Feature/BroadcastAndNudgeGreetingTest.php`, and for `MailGreeting` itself by the
greeting cases in `tests/Feature/PasswordSetNoticeTest.php`.

## 2026-09-18 — An order can be changed after it is placed: by the customer until the cutoff, by staff at any time (extends 2026-09-11's mark-paid rule; nothing here marks money as taken)

**Decision.** A Jummah-lunch order's items can be changed after it has been
placed, through two doors.

1. **The customer, on the link they already hold** — `PATCH /api/v1/lunch-orders/{uuid}`,
   the same unbound `masjid-id` idiom and the same uuid-as-capability as the
   status page, on the tighter `lunch-order` limiter because it writes and can
   move a payment page. The body is the FULL set of lines after the edit; a
   quantity of 0 removes one. It is refused, with a sentence they can act on,
   once ordering has closed (`now >= ordering_closes_at`, the cutoff the kitchen
   counts plates against), once the order is paid or refunded, and if it was
   cancelled. An order may never be emptied: cancelling is a conversation with
   the masjid, not an empty basket.
2. **Staff, on the board** — `PATCH .../jummah-lunch/menus/{menu}/orders/{order}/items`,
   inside the existing `capability:jummah_lunch` group, `admin` middleware, NO new
   permission (`Permission::count()` stays 8). **No cutoff**: the requests staff
   actually get — "can you make that three?" — arrive after ordering closes, and
   handling them is the point. Deliberately NOT in `routes/lunch.php`: changing
   an order somebody has already paid for is not a volunteer's call.

**A PAID order may be edited by staff, and no edit ever settles money.**
`payment_status`, `paid_at`, `paid_via` and who recorded the payment are never
touched. What the order's total was when the money landed is recorded once, by
the first edit after payment (`meal_orders.settled_total_minor`), and the
difference is published as `balance_minor` on every admin payload: positive is
still owed by the customer, negative is owed back. The board's
`revenue_paid_minor` now sums what SETTLED, not the new price of the food. NULL
means nothing has been edited since the money came, so no row was backfilled.

**One pricing path, not three.** The public page and the staff board each had
their own copy of the "resolve the items, cap them, price them" loop; the edits
would have been a third and a fourth. It is now `App\Support\LunchOrderLines`,
and the two existing callers use it — prices, totals and refusals unchanged. The
body still never prices anything: it carries ids and quantities, and
`validated()` drops everything else. The one deliberate difference between doors
is the kitchen's cap: the public ORDER page trims to it (as it always has), while
both edits and staff entry refuse and say so.

**The donation stands and the card fee follows.** `donation_minor` is the one
amount the customer chose, so an edit to the food never touches it.
`fee_covered_minor` is recomputed with the placing-time formula
(`StripeFees::coverage`) ONLY for an order that was already covering the fee; an
order that never covered it does not start.

**An unpaid order's open Stripe page holds the OLD amount**, so it is closed
before the new total is written (`MealOrderCheckoutService::closePageBeforeRepricing`,
the sibling of `closePageBeforePaidByHand`), under the same row lock every other
money path takes. If Stripe will not close it, or reports it paid or clearing,
**nothing is changed at all** — two payable amounts for one order is the failure
this prevents. For the customer a new page for the new total is made immediately,
so an edit never silently removes their only way to pay; staff use "Payment link"
as they already do.

**Every edit is recorded** in `meal_order_edits` (masjid, order, actor
customer|staff, the staff user when there is one, and the lines and money before
and after), written in the same transaction, so no edit commits without its row.
A request that changes nothing records nothing.

**Alternatives.**
- **Cancel and re-order.** Rejected: it loses the order number the kitchen has
  already written down, and for a paid order it means a refund and a second
  charge for what is usually one extra plate.
- **Let the customer cancel from the same link.** Not built: an order that
  vanishes after the kitchen has counted plates is the masjid's decision.
- **Let the edit re-charge or refund the difference.** Rejected outright. The
  balance is a fact staff act on; no endpoint here may move money.
- **Let lunch volunteers edit too.** Not now — see above.

Pinned by `tests/Feature/MealOrderEditTest.php` (the cutoff a minute either side,
a paid order refused to the customer and taken by the board, the cap, an item
from another menu and from another organisation, a crafted price ignored, the
last plate, the donation untouched, the fee moving only when it was already
covered, the audit row and its actor, and the Stripe page for the old amount)
and, for the new table's tenant scoping, `MealOrderTenantIsolationTest`.

## 2026-09-18 — The two edit screens, and why the ORDER PAGE is told what it may do rather than working it out

**Decision.** The two endpoints above are reached from two screens, and neither
screen decides anything about money or permission for itself.

**The customer's order page** (`views/lunch/LunchOrderStatus.vue`) gains a
"Change my order" control that steps the quantity on each line and saves the
FULL set. It cannot add an item the order does not already have: the order link
is not the menu, and someone wanting something new can place an order.

Whether the control appears at all is the SERVER's answer, not the page's. The
public order payload now carries `can_edit` and `edit_notice` — the notice being
the exact sentence a refused `PATCH` would have answered with — so a closed or
paid order shows the reason where the button would be, in the server's words.
The page could work out "paid" and "cancelled" from fields it already had, but
never the cutoff, and a control that only ever 409s is worse than none. Each
line also carries `meal_menu_item_id`, because the edit body names lines by id
and the page previously held only the snapshotted NAMES.

**A line whose menu item was deleted** has no id left (`nullOnDelete`), so it
cannot travel back in a full-set body: saving would drop it and quietly reduce
the order. Such an order reports `can_edit: false` with its own sentence. This
is a display answer; `update` still asks everything again, and again on the
locked row.

**The figure shown while editing is labelled as settled on save.** The page sums
the unit prices it was given; the server re-prices every line and recomputes the
card fee, and the totals shown after saving are the server's. Both languages
carry every new string (`lunchI18n.ts`, 60 keys each); the server's own
sentences are shown in English as they come, like menu item names, so the page
can never tell a customer something the endpoint did not.

**The staff board** (`views/dashboard/JummahLunchView.vue`) gains "Edit items" on
an order row — quantities per line, any available item added, still working
after the cutoff. On a paid order the editor says plainly that saving does not
move the payment, shows what actually settled, and names what would be owed or
owed back; afterwards `balance_minor` stays on the row ("Owes $X — not
collected" / "$X owed back — refund by hand") until somebody settles it by hand.
Lines the shared pricing would refuse — item deleted or marked unavailable — are
named before Save rather than discovered through a refusal. Administrators only,
matching the route: a LunchStaff is never shown the button and the store refuses
it.

Pinned by the four `MealOrderEditTest` cases covering the order page's own read
(the ids on each line, the cutoff sentence, the paid sentence, and the deleted
item), each proved to fail against a payload that answers `can_edit` blindly or
drops the item id.

## 2026-09-21 — Staff invite links last 7 days; resets stay 60 minutes (SUPERSEDES 2026-09-17)

**This reverses an earlier answer. Read both before changing either.**

- **2026-09-17**, in Abdul-Rahman's owner interview (`/tmp/manara-plans/ship-plan-2026-09-17.md`):
  asked whether invites should get their own longer lifetime, the owner answered
  **"Keep 60 minutes"**, against the recommendation of 7 days.
- **2026-09-21**, in Abdul-Lateef's session, after the BISS teacher meeting where teachers were
  told to watch for a set-up email: offered "Keep 60 min, lean on Forgot password" or "Longer,
  for invites only", the owner chose **"Longer, for invites only"**.

The 2026-09-21 answer stands. Reason given in context: teachers opening an invite hours later
is the ordinary case, and the MEC admins' invites all died unopened on 2026-09-15.

**Shape of the decision** — `feat/invite-links-7-days`: invites get their own broker and
table (`invites`, `account_invite_tokens`, 7 days); Forgot password stays on `users` /
`password_reset_tokens` at 60 minutes. NOT a longer expiry on the shared table: a token
records nothing about why it was minted, and raising the shared expiry is what made every
admin's reset link live 72 hours from 2026-09-16 (which, because the scheduled revert stalled,
ran about 7 hours past its bounded window — see Abdul-Rahman's 2026-09-19 recovery).
One live link per person across both tables.

**Alternatives rejected:** keep 60 min (the 2026-09-17 answer — superseded); raise the shared
expiry (lengthens every reset); a `kind` column on the shared table (every reader would have
to honour it, and one that forgot would accept a reset as an invite).

## 2026-09-21 — Teacher shortcuts: mark all letters, whole surah, running points totals (narrows groups.md "no class-wide endpoint")

BISS teacher feedback, applied Manara-wide, teacher realm only.

- **Mark all letters mastered** — `PUT .../members/{id}/letters/master-all` (`teacher.teaches:arabic`, body
  `alphabet`). "All" is the class stage's syllabus on that track — the progress denominator — never further up
  the qāʿidah. It writes the same `arabic_letter_progress` cells through `moveTo()` as the single mark; drills
  already mastered keep their `mastered_at` and `marked_by_user_id`, and no note is touched. One transaction.
  The screen asks for confirmation first. Rejected: a stored "knows all letters" flag (a second truth beside the
  cells, which every reader would have to consult).
- **Whole surah** — `whole_surah` on the existing `POST .../hifz` (still `teacher.teaches:quran`); no new route,
  no column. The request fills in `from_surah:1 .. from_surah:last` from `QuranIndex` before validation, so the
  row is an ordinary full range and HifzProgress reads it unchanged. `whole_surah` in the payload is DERIVED
  (`HifzEntry::isWholeSurah()`), so a hand-typed full range reads as whole too. A contradicting range sent
  alongside is a 422, not a guess.
- **Running points totals** — `GET .../awards/totals`, leaders only (a guardian is refused even though the
  query would constrain them). Per current student, in ROSTER order, no rank field; each number is the net
  `SUM(points)` the per-student summary and the family summary report, negatives included, revoked excluded
  (a test compares them). A class figure is the sum of those rows. This is the "teacher's overview … a list of
  per-student rows a leader is already entitled to" that `.claude/rules/groups.md` §1 foresaw; the no-leaderboard
  rule is unchanged: nothing sorts children by points and nothing here reaches a family.

## 2026-09-21 — Reactions and read receipts on teacher ↔ family messages (owner request, Manara-wide)

**Asked:** reactions on messages, exactly 🤲 👍 💯 ❓ (🤲 is the "Ameen"), one of each per
person per message, toggled; and read receipts both ways — the teacher sees a parent read it,
the parent sees the teacher read theirs, the office sees read status. Marked read on opening,
not on listing.

**Shape** — branch `feat/message-reactions`:

- `group_message_reactions` (masjid, message, `reaction` key, `user_id` XOR `contact_id`),
  two unique keys (one per principal column; NULLs partition them on both drivers). The set is
  the PHP constant `GroupMessageReaction::REACTIONS`; the server 422s anything else and the
  model refuses it too. PUT adds, DELETE removes — two idempotent verbs rather than a toggle,
  so a double tap or a second tab cannot flip the answer back. No notification. **(The "no notification" was reversed 2026-09-29: see "Class story engagement" at the end of this file — a tap still sends nothing, but the author gets a content-free hourly digest.)**
- **The gate is replying's gate.** Route write gate (`permission:manage contacts` / 
  `teacher.leads` / family guard) → `mayReceiveThread()` → not closed; the message is resolved
  through the thread, so another family's message id is a 404 even from a thread you may read.
- **Receipts are the existing bookmark**, made exact: `group_thread_reads.last_read_message_id`
  = the newest message the reader was actually served, forward-only. Opening a thread moves it;
  the list never does. There is deliberately no "mark read" route — only somebody the thread
  was shown to can move their bookmark.
- **Who sees whose names** is one class, `App\Support\GroupMessageSignals`: staff see everyone;
  a parent sees staff names only, other parents' reactions as a count, other parents' reading
  not at all.
- **Counted lists:** the family realm's write list grows by two (13 routes) and the teacher
  realm's by two, both argued in the guard tests. `group_message_reactions.contact_id` is an
  OFFICE record for account deletion (like `group_thread_reads.contact_id`), and both new
  message-id columns are `reviewed_keep` in the staging scrub.

**Admin view:** the group's Conversations tab (`GroupThreadsTab.vue`) shows read status under
every message and lets an admin who may read the thread react. That tab still only opens for
an admin who is in the group (GroupAudience) — an off-roster admin sees neither the thread nor
its receipts, unchanged.

**Alternatives rejected:** a per-message receipt table (the bookmark already records it, and
per-message rows would multiply writes by thread length); timestamps alone (whole seconds, and
page one of a long thread would "read" the rest); showing parents each other's reads (discloses
class membership and co-guardian activity); an open emoji picker (owner chose the four).

**Open for the owner:** an office admin who opens a thread shows to the family as "Seen by
<admin name>" — the office IS the school, but say if only teachers should count.

## 2026-09-21 — Parent portal: Urdu, Pashto, Dari and Spanish beside Arabic (labels machine-drafted)

Owner: "Add them, portal labels too." Two surfaces, both extended:

- **Content translation** (the Translate button over what teachers write):
  `config('translation.languages')` is now `['ar','ur','ps','fa-AF','es']`, and a tag is only
  offered if `App\Support\PortalLanguage` also describes it (prompt name + direction) — an
  operator who lists an undescribed tag gets a 422, not a bare code in the prompt. The response
  now carries `data.dir`. The prompt keeps Qur'anic/du'a Arabic verbatim and adds no honorifics.
  The cache is keyed per target, so each extra language is a separate purchase per paragraph.
- **Portal labels**: one file per new locale under `resources/vue-app/views/family/locales/`,
  every one headed **MACHINE-DRAFTED, not reviewed by a fluent speaker**, with
  `reviewed: false` in `FAMILY_LANGS`. A native `<select>` (`FamilyLangPicker.vue`, endonyms)
  replaces the English/العربية toggle on the five screens.

**Dari is `fa-AF`, not `prs`.** `prs` is the ISO 639-3 code and Windows' locale, but CLDR aliases
it: `Intl.getCanonicalLocales('prs')` → `fa-AF` in browsers and Node, so a stored `prs` would be
rewritten by the date formatter and reach the cache as a second tag for one language. The client
normalises `prs`/`prs-AF`/`fa*` to `fa-AF`; the server accepts only `fa-AF`.

**Dates:** every RTL locale pins Western digits (`nu-latn`), as Arabic always did; Pashto and Dari
also pin `ca-gregory`, because CLDR's default for both is the Solar Hijri calendar ("30 Sunbula
1405" beside a Gregorian school calendar).

**English-mode default target:** the first of the browser's languages we offer, else Arabic — so a
parent whose phone is not set to one of the new languages sees exactly what they saw before.
Switching language drops translations already on screen rather than re-buying them unasked.

Pinned by `FamilyTranslationTest` (accept ×5 with dir + prompt name, refuse ×10 near-misses,
undescribed/withdrawn config tags) and `FamilyLanguagesMirrorTest` (TS↔PHP direction and order,
key coverage, `{x}` slots, the MACHINE-DRAFTED banner, religious terms kept).

**Follow-up, same day — owner: "Yes add Nastaliq, and Dari is fine."** Browsers set to `fa`/`fa-IR`
keep being offered Dari. Urdu gets Noto Nastaliq Urdu (OFL 1.1), self-hosted from
`@fontsource/noto-nastaliq-urdu` so it rides `font-src 'self'` and no parent's IP goes to a font CDN.
Only the Arabic-script subset at weight 400 (159 KB woff2; the 212 KB woff is emitted as a fallback
but modern browsers never fetch it); no bold, `font-synthesis: none`. Named only under `:lang(ur)`
and `[data-tx-lang="ur"]` (set by FamilyClass/FamilyHome while an Urdu translation is showing, so an
English portal translating into Urdu gets it too), so no other language downloads it. Staff text shown
as written and the Arabic letter chips (`lang="ar"`) keep the ordinary face inside an Urdu page.
Pinned by `FamilyUrduFontTest`.

## 2026-09-21 — Weekly-school settings: a shorter report card, lesson plan and marking scale, per organisation, SuperAdmin-only

**Decision.** Burlington Islamic Sunday School (org 18) meets once a week and teaches
Qur'an, Islamic Studies and Arabic. Three per-organisation settings, OFF for every
organisation (Al-Razi, org 14, unchanged), ON for org 18. Owner's words, 2026-09-21: report
card "Reuse Al-Razi's"; behaviours "Keep it"; lesson plan drop "Differentiation section, STEM
line, Exit ticket" plus the standards; gradebook "Teacher picks per assignment"; calendar
"Sundays only"; setup changes "Only you for now".

1. **Three grants in `config/capabilities.php`, group `school`, `listed_when_off => false`.**
   `report_card_core_subjects`, `short_lesson_plan`, `simple_marking`. Grants, not modules:
   each is off until decided, and the only writer is the existing SuperAdmin-only
   `PATCH .../capabilities/{key}` (in-controller 403 for anyone else, one ledger row per
   flip). The switch panel shows them under "School" with no SPA change. One reader:
   `App\Support\SchoolSettings`.
2. **Report card.** `ReportCardTemplate::forGrade(..., coreOnly: true)` returns `CORE` only —
   Qur'an, Islamic Studies, Arabic Language with Al-Razi's criteria, no Grammar, no grade-band
   subjects — at every grade. Learning Behaviours are added as always.
3. **Lesson plan.** `SchoolSettings::HIDDEN_LESSON_PLAN_FIELDS` = the two standard fields, the
   five Differentiation fields, `cross_integration_stem`, `assessment_exit_ticket`. **Hidden
   means not shown and not written:** `LessonPlanController::save` leaves those columns as they
   are (a plan from before the switch keeps them; a client that still sends them fills
   nothing). None was ever required. The index serves `hidden_fields`; the SPA drops the fields,
   any section left empty (Differentiation, Assessment), the week grid's Standard and
   Differentiation columns, the "verify standard codes" note, and prefill of the code.
4. **Gradebook.** A third scale, `simple` (Excellent 3 / Good 2 / Needs work 1,
   `App\Support\SimpleMark`), stored like levels in `points_earned` with `points_possible`
   forced to 3. Which scales a teacher may choose is the organisation's: levels + points
   everywhere (as before), **points + simple** with `simple_marking` (the owner: "a score or a
   simple scale"; levels are Al-Razi's rubric). Editing work keeps the scale it already has.
   Every payload carrying such a mark carries its word (`mark_label`) and the key
   (`simple_marks`); the summaries gain `simple` = a count per word + missing, and **no mean
   and no percentage** (points and levels summaries are filtered by scale, so a "Good" never
   reaches a denominator). Moving marked work onto or off `simple` is refused (422 on `scale`);
   points↔levels behaves as before.
5. **Calendar: nothing new was needed.** `SchoolCalendar` already makes the meeting weekday the
   first day's (2026-10-11, a Sunday, for BISS) and admins already mark off-Sundays as closures
   behind `school_calendar`, which org 18 has. The one gap was the lesson-plan week grid, which
   was Monday–Friday and whose "Copy to the rest of this week" would have written a Sunday plan
   onto five weekdays. The lesson-plans index now serves `meeting_weekdays` from the calendar
   (null with no calendar: Al-Razi keeps Mon–Fri), and the copy button needs two or more days.
6. **Org 18 is switched on by a data migration**
   (`2026_09_21_120000_switch_on_sunday_school_settings_for_biss`): only if row 18 is a
   non-deleted school whose name contains "Sunday School"; sets only keys its
   `capability_overrides` does not already name; one NULL-actor ledger row per key it set;
   idempotent. Anything else logs one warning and writes nothing.

**Alternatives.**
- **Let the org's own admins change them.** Rejected: owner, "Only you for now".
- **A `settings` JSON column or a separate school-settings table.** Rejected: the capability
  catalogue already has the storage, the writer, the ledger and the panel.
- **Store the three words as a string column.** Rejected: levels already live in
  `points_earned` with the same "never a denominator" guard; a second storage shape would be a
  second set of reads to keep right.
- **Null the hidden lesson-plan fields on save.** Rejected: switching the setting on would then
  erase what older plans said on their next save.
- **A "Sundays only" calendar setting / refusing off-weekday registers.** Not built: the
  calendar already expresses Sundays-only, and the register rule (only closures are enforced)
  stays as the school-calendar rules file states.

**Known limits.**
- "Score (% + letter, as today)": the gradebook has never shown a percentage or a letter
  grade. A score is points ("8 of 10"), as today. Adding % or letters would be new and was not
  built.
- A stale config cache during a deploy reads every setting as off (grants fail closed); a
  report card prepared in that window gains grade-band rows that are then never removed.
  BISS has no report cards before its first quarter ends.
- The Team & Access screen lists a setting that is on as a chip, like any grant.

## 2026-09-23 — The office reads the gradebook; it does not mark
Decision: the admin console's classroom screen gains a **Gradebook** tab
served by three GETs that mount `Teacher\GradebookController` unchanged
(`/assignments`, `/assignments/{id}`, `/members/{id}/grades`, all
`permission:view contacts`). No admin route exists for `store`, `update`,
`destroy` or `saveScores`, and `AdminGradebookReadTest` pins their absence
along with the names-only payload, the gate and the tenant boundary.
Alternatives: (a) a second admin controller — rejected, two implementations
of one gradebook drift, and this is the mirror of `ArabicLettersController`,
which the teacher realm already reuses the other way round; (b) full CRUD for
admins — rejected: entering a mark mails the family
(`GradebookController::announceMarks`) and stamps `scored_by_user_id`, so an
office screen that could do it would put a name on a judgement nobody in the
room made. Rationale: the school asked to *see* the gradebook as
administration; seeing it is the whole ask, and the read is the half with no
blast radius. Payloads stay the teacher realm's names-only ones — narrower
than the console shows elsewhere, never wider.

## 2026-09-23 — The teacher's Letters tab reads the class, not just the child
Decision: `TeacherClass.vue` calls `GET .../letters` (the class overview)
whenever the Letters tab opens, the track changes, the stage changes or the
teacher comes back from a child. Alternatives: pass the ladder down on the
group payload — rejected, it would be a second source for something the
letters endpoint already answers, and the stage summary and per-child counts
would still be missing. Rationale: the tab previously fetched nothing until a
child was opened, so a teacher saw a bare list of names and the category
picker (The Letters / Short Vowels / Sukūn & Shadda / Tanwīn / Long Vowels)
appeared only if `group.arabic_stages` happened to be present — while the
office's copy of the same tab had the ladder, the summary and every child's
x/28. The endpoint was already mounted in the teacher realm and fenced by
`teacher.leads`: this adds a read a teacher was always entitled to, and no new
authority. Verified live on 2026-09-23 in the QA sandbox (org 17): five
categories listed, and moving the class from The Letters to Short Vowels
re-read the class and moved the denominator from 28 to 112.

## 2026-09-23 — The office's attendance log shows counts, never a rate
Decision: `/masjid/attendance` and its two GETs report present / late / absent /
excused and a denominator called `registers`, and compute no percentage
anywhere. `registers` is the UNION of the days a child's class took a register
while they were enrolled and the days they hold a mark on.
Alternatives: (a) "present X of Y school days" — the number the office will ask
for, refused: three different situations produce a missing mark (the register
was never taken, it was taken and the child was skipped, there was no school),
and dividing by school days silently converts all three into absence on a screen
a parent may be shown. Al-Razi cannot even define Y — it has no `school_years`
row and the `school_calendar` capability defaults off. (b) `max(clipped days,
marks)`, which is what shipped first and what the review caught: a mistyped
`joined_at` pushes a real mark outside the clip, and the max() refilled that
hole with an unrelated unmarked day, printing "2 marked of 2 registers" directly
above a register the child was never marked on. A union makes
`marked + unmarked <= registers` structural. `AdminAttendanceLogTest::
a_mark_outside_the_clip_and_a_skipped_day_are_both_counted` fails under max()
and passes under the union.
Rationale: this screen's only claim is that its numbers are the record. A
denominator it cannot defend is worth less than no denominator. When a school
enters its year and closures, a rate against school days becomes definable and
can be added deliberately — for that school, and said in those words.

## 2026-09-23 — Office reads of teacher work mount the teacher's own controller
Decision: the office's lesson-plan and files tabs mount
`Teacher\LessonPlanController@index` and `Teacher\ResourcesController@index|download`
unchanged under `permission:view contacts`, GETs only — the third and fourth
uses of the pattern the gradebook set this morning. The files list deliberately
carries the staff-only files as well as the ones shared with families, because
the office is the school's staff and is the desk that answers for what a parent
can see; `AdminSchoolOfficeReadsTest` pins that, so a later reader who sees the
family realm's `visibleToFamilies()` scope beside this mount does not conclude
the office one forgot it.
Alternatives: a second admin controller per feature — rejected, two
implementations of one read drift; admin writes — rejected, a lesson plan
carries `author_user_id` and a file set to `families` mails every guardian in
the class.

## 2026-09-24 — Feature keys are matched by their normalised form, never raw
Decision: anything that matches a configured feature key (config/verticals.php
bundles, a posted `feature_keys`) against `mobile_app_features.key` compares
`MobileAppFeature::normaliseKey()` (lower-case ASCII letters and digits only), and
a posted key is rewritten to the catalogue's own spelling before `exists`
validation. The SPA wizard mirrors it in `core/helpers/featureKey.ts`.
Alternatives: rename production's `qur’an` (U+2019) key to `quran` (a production
data change; the Play build routes features by NAME and other readers were not
audited); or match by id (Studio's approach, but the wizard and config speak keys).
Rationale: production's Qur'an key is curly-quoted because the 2025-12-09 backfill
missed that one row, so exact matching provisioned every new masjid with Qur'an
off while every test (seeded with `quran`) passed. The mobile endpoint already
normalised this way; one shared normaliser makes every path agree. No data fix:
a read-only check found no masjid-vertical org unambiguously affected (MEC's row
was changed on 2026-08-07, after creation).

## 2026-09-24 — /api/v1/settings keeps serving `google_maps_key`
Decision: keep it, documented and pinned (`WebsiteSettingsMapsKeyTest`), rather than
remove it as the Studio W1 plan's §7 first suggested.
Alternatives: drop it from the public payload like the mobile directory does.
Rationale: the renderer loads Burlington's styled Maps JavaScript API map with it,
so the key reaches every visitor's browser regardless; removing it would switch a
live tenant to the keyless embed and hide nothing. Protection is the key's Google
Cloud restriction (HTTP referrers + Maps JavaScript API), an owner check outside
this repo. Only org 1 sets a key on production.

## 2026-09-24 — Studio W1 S1: calls made where the plan was silent
Decision: `CapabilityCatalogue::visibility(key, def, orgType)` returns one of
HIDDEN / NOT_OFFERED / DEFAULT / OPTIONAL, and hidden entries are never served;
`resolve()` accepts only real PHP booleans (string coercion stays with the request,
per shipping.md, in S8); the real platforms for `studio_preselect_with` are read from
`ProvisionMasjidRequest`'s `platforms.*` rule (no shared constant exists); Studio
tests live in `tests/Feature/Studio/`; StudioAccessTest finds every
`api/admin/studio` route from the router for the 401 checks, and a new route must add
its SuperAdmin call to `StudioAccessTest::calls()` or the test names it.
Rationale: each follows the nearest existing pattern (MasjidsController::capabilities
for entry fields); recorded because the plan left them open.

## 2026-09-24 — Studio W1 S3 review: calls made where the plan was silent
Decision: a stored `reserved` row can never change status (MasjidDomain's `saving`
invariant), and `DomainProbe::confirm()` stamps only `last_checked_at` on one, so R4
("never advanced") holds whatever S7 path calls the probe; releasing a reservation is
removing the row. A `failed` row that the probe matches becomes `manual`, because our
site answering with the org's id is the same R24 proof a pending row needs. The import
reads each map value the way the renderer's `toRecord` does (a bare id or `{id, ...}`),
so the in-git map feeds it unflattened. The import stays a dry run unless `--execute`;
the S3 spec and runbook now say so, and the runbook reserves `www.alrazischool.org` and
`parents.alrazischool.org` too. The by-host limiter's 429 carries `no-store` like the
controller's answers. HostName anchors its regexes with `\z`, so a newline inside a host
never passes as part of a label.
Rationale: each came from the S3 review; recorded because the plan did not decide them.

## 2026-09-24 — Studio W1 S6: calls made where the plan was silent
Decision: `ProvisionContext` (the plan names it but never defines it) carries one
field, `actorId`, typed `int|string|null` and taken from `Auth::id()` uncast, because
it is written to `masjids.created_by` and echoed in the response the extraction must
not change. The controller calls the provisioner from a full closure with
`use (&$invitations)`, not the plan's `fn () =>`: an arrow function captures by value,
so the literal form would drop every invitation without an error. The byte-identity
pin is `ProvisionResponseSnapshotTest`, whose fixtures in
`tests/fixtures/provision-snapshot/` were recorded from the unrefactored controller
(commit 90e4d182) and are re-recorded only with `PROVISION_SNAPSHOT_RECORD=1`, a run
that always fails so it cannot pass for a check.
Rationale: each keeps the legacy endpoint's rows, mail and response identical, which
is the slice's whole contract.

## 2026-09-24 — Studio W1 S6 review: what the byte-identity pin could not see
Decision: the snapshot's id labels keep a key's JSON type (`users#1` for an int,
`users#1:string` otherwise), and every case provisions next to an existing organisation
that has a row in each per-organisation table, so the new org is `masjids#2`. The
fixtures were re-recorded with 90e4d182's controller swapped into the CI tree, not from
the refactored code, and the refactored code then matched them unchanged. The promises
one recording cannot show (only a SuperAdmin reaches `/onboarding/*`, the invited
admin's stored credential is unguessable, a supplied `user_id` beats `admin.email`,
BYO secrets are kept only for a selected platform, invitations leave only after commit
and never for a rolled-back provision) are explicit tests in
`ProvisionWizardGuaranteesTest`, each killed by its mutation. The path-scoped rules for
the provisioning body (`directory-listing.md`, `verticals.md`) now load for
`app/Support/Studio/`.
Rationale: the type-blind labels filed 5 and "5" under one key, so the `actorId`-uncast
promise above had no test; with only one organisation, "the new org" and "the first
org" got the same label; and a session editing only the provisioner loaded neither rule.

## 2026-09-24 — Live preview: a signed preview mode on a host that serves no tenant
Decision: the page tool's preview is the real renderer in an iframe, loaded from
the renderer project's own `*.pages.dev` host at `/__manara/preview/<path>?mp=<token>`.
The prefix is covered by no cache rule, so Nitro routes it to the uncached
catch-all renderer; a `render:before` hook verifies a five-minute HMAC token
Laravel signs (org, surface, path, admin origin), takes the tenant from the token,
rewrites the path and stamps no-store, noindex and `frame-ancestors <admin origin>`.
Unsaved edits reach it only by `postMessage` from that admin origin and are laid
over the Pinia store in the browser. Save purges the org's `MANARA_PAGE_CACHE`
keys through a signed `POST /__manara/purge` on the renderer, called after the
response. Contract: `docs/live-preview.md`.
Alternatives: (a) `?preview=` on the tenant's own URL — rejected, a cached route
stores every distinct query under its own key and `shouldBypassCache` cannot be
passed through JSON route rules; (b) preview on the tenant's own domain — rejected,
the tenant would come from the Host (W1's code), the admin would need every
tenant host in `frame-src`, and a Studio draft with no host could not be shown;
(c) server-side draft storage read by the renderer — rejected, it needs a new
authenticated read path and persists unsaved content; (d) giving Laravel KV
credentials — rejected by the brief; (e) purging the whole cache on every save —
rejected, it hands every client admin a lever over every other tenant's cold renders.
Rationale: every live tenant host keeps exactly today's code path, the uncached
path is chosen by Nitro's own routing rather than by a flag, and one preview
origin is one entry in the admin CSP and one in the postMessage allowlist.

## 2026-09-24 — Preview gates are the save routes' gates, one mint route per surface
Decision: `pages/preview-session` sits inside the `capability:web_pages` +
`capability:website` group, `theme/preview-session` beside the theme save
(admin + tenant only), `splash-announcements/preview-session` inside
`capability:splash`. The token names its surface and the renderer accepts only
that surface's overrides.
Alternatives: one mint route under the pages gate — rejected, a MasjidAdmin who
may edit the theme but not the pages (web_pages off, e.g. Burlington) could not
preview the theme they can save; one ungated mint route — rejected, the brief asks
for tokens issued only to people allowed to edit.
Rationale: whoever may save a surface may preview it, and no one else; placing the
route in the save route's group makes that true by construction.

## 2026-09-24 — Renderer preview/purge work merges to `main` only
Decision: follow W1 — `manara-renderer` (branch `main`) gets preview and purge;
`cloudflare-migration` / `mec-web` is not touched, so `mec-web.pages.dev` keeps its
5-minute window.
Alternatives: port to both branches as the payload fix was — rejected, W1 fixed
`cloudflare-migration` as a control that is not redeployed.
Rationale: one live renderer to reason about; MEC's Manara host is on `main`.

## 2026-09-24 — A second purge pass, and "immediately" means about a minute on KV
Decision: a save purges at once (after the response) and again 75 s later on the
queue (`PurgeRendererCacheAgain`, unique per organisation). The owner is told that on
Cloudflare KV a saved change reaches visitors elsewhere in about a minute, not on the
next request.
Alternatives: one pass only — rejected, measured on staging: a page warmed in another
region seconds before a save was missing from KV's eventually consistent listing and
would have lived its full 5 minutes; a strongly consistent page cache (Durable Object)
— not built, it is new infrastructure and an owner decision.
Rationale: measured, not assumed — the fresh render appeared 64 s after a staging save,
well before the entry would have expired, against up to 5 minutes plus a
stale-while-revalidate request before this work.

## 2026-09-24 — Purges leave the request: a coalesced first pass, and a second pass that trails the last save
Decision: `renderer.purge`'s `terminate()` only calls `RendererPurgeScheduler::afterSave`, which
records the save time and queues two jobs. `PurgeRendererCache` runs 3 s later and is unique per
organisation until it starts, so a burst (a section reorder is one request per section) shares one
purge. `PurgeRendererCacheAgain` is unique for up to 30 minutes. It `release()`s itself until 75 s
have passed since the most recent save, then purges. Each pass makes at most `MAX_CALLS` (2) calls,
following the renderer's key cursor. This supersedes the "second pass 75 s later" entry above,
whose job was a throttle: a save inside an earlier save's window got no second pass of its own.
Alternatives: purge inline in `terminate()` (holds a PHP-FPM worker on the renderer, and fans out one
full list-and-delete per request); a debounce by re-dispatching with a new delay (a unique job
cannot be re-dispatched while it is pending, and a non-unique one fans out again).
Rationale: the KV list and delete budget is account-wide and the plan is unknown, so the cost must be
bounded per save burst: about 2 lists and 2 × the organisation's keys in deletes. Review findings 4,
5, 8, 10 and 17.

## 2026-09-24 — Saves of read-time bound content purge too
Decision: `renderer.purge` is also on the details, About Us, donation-link, contact-reasons, forms,
offerings and fee-plans groups: the sources `SectionContentBinder` reads into public pages.
Alternatives: leave them on the 5-minute expiry (the earlier open question).
Rationale: the owner wants Save to go live (decided through the point session, 2026-09-24). The route
table pin lists the groups by prefix, so a missing one fails the suite.

## 2026-09-24 — The signed preview path is the decoded path; the URL carries it encoded
Decision: the token's `p` is the decoded slug path. `PreviewToken::encodePath` percent-encodes each
segment for the iframe URL, and the renderer compares with h3's decoded `event.path`. Both sides
refuse `%` and list the forbidden characters explicitly: PHP's `\s` without `/u` was ASCII-only
while JavaScript's matched U+00A0, U+2028 and U+FEFF.
Alternatives: sign the encoded form (both sides would need a browser-exact encoder).
Rationale: an Arabic slug minted a token and never previewed (review finding 1). One canonical form,
pinned by a shared Arabic vector.

## 2026-09-24 — L5 ships with the preview, and typography is rebuilt only when a font changes
Decision: the Brand Studio font and header/footer controls stay tied to the preview being available.
`core/helpers/themeTokens.ts` rebuilds `tokens.typography` only when a font select changed, and
keeps the saved stylesheet's families for any face left as "Current". Colours alone post no tokens.
Alternatives: gate L5 on its own flag (the review's other option).
Rationale: fonts and header/footer style are in the owner's scope, so the point session chose to fix
the bug and ship L5 (review finding 7). Changing only Header style no longer drops a custom heading
font from every public page.

## 2026-09-24 — The preview frame is sandboxed, and a reload heals itself
Decision: the iframe carries `sandbox="allow-scripts allow-same-origin allow-forms allow-popups
allow-popups-to-escape-sandbox"`, with no top navigation. The renderer's sanitiser keeps only
`_blank` or no target. A second `load` of the same frame element (a reload, or Nuxt's chunk-error
reload) makes the pane wait 8 s for `ready`, then open one new session per 30 s, then show the
error. The element's first `load` is never treated as a reload, because it can fire after `ready`.
Alternatives: sanitiser only, or sandbox only (one layer each); treat any `load` after `ready` as a
reload (the first version, found here: the first document's `load` can follow hydration when images
finish late, which would have looped the pane).
Rationale: review findings 2 and 3. The renderer strips `mp` from the frame's URL, so a reloaded frame
is not a preview any more and needs a fresh session.

## 2026-09-24 — Dragging to reorder the menu keeps publishing on drop
Decision: no preview step for menu order. A drop saves at once, as before, and that save purges.
Alternatives: a staged reorder with its own Save button.
Rationale: the owner kept today's behaviour (decided through the point session, 2026-09-24), and the
doc now says so instead of claiming menu order is previewed (review finding 12).

## 2026-09-24 — Studio W1 S4: calls made where the plan was silent
Decision: the `header` block's label is written `page.{slug}` and resolves to the label of
the page it opens. A button between starter pages never opens a page the public site does
not serve: a banner keeps its place and loses only that button; any other section whose
target page was omitted is omitted; one whose target page is written inactive is written
inactive with an open `linked_page` placeholder (a new hint), which is what happens to
`school.*`'s Apply call to action while the Admissions page waits for tuition and a
reviewed form. `{page_id}` and `{form_template}` leaves are null in a plan and listed under
the section's `refs`, for S8's writer to fill once the rows exist. `StarterSite::plan()`
throws on any template literal STRUCTURAL does not allow, so the no-invention rule holds at
run time as well as in `StudioLayoutPresetsTest`. A plan never pre-fills bound prose (the
layouts recon's layout-preview did): it holds exactly what S8 writes, and each placeholder
says whether it is `open`. The preview uses the draft's own preset when it is one of its
org type's, otherwise the default, and says which (`web.preset_source`); `web.approved` is
true only for the draft's own choice with `layout.approved_at` set (R27). `palette`,
`web_tokens` and `platform_contrast` are null until all four colours are chosen, as the
draft resource's `palette` already is (R25). Preview `answers` are held to the autosave's
rules (`StudioPreviewRequest` extends `UpdateStudioDraftRequest` without `lock_version`).
The unsaved organisation takes its switches from `CapabilityCatalogue::resolve()`: column
grants on their columns, every other key stored only where it departs from its default at
creation, as S8 will store it. `tvos.header_title` is the name, which is what the board
draws when tv-config sends none. `TvConfigSnapshotTest`'s fixture was recorded from the
controller at 9a412074, before its constants became public, and is re-recorded only with
`TV_CONFIG_SNAPSHOT_RECORD=1`, a run that always fails.
Rationale: each keeps the preview, the gate and S8's writer on one derivation and keeps a
new client's site from publishing a dead link or a word nobody gave; recorded because the
plan left them open.

## 2026-09-24 — Studio W1 S7: calls made where the plan was silent
Decision: `masjid_domains` gains one nullable `stage_started_at` timestamp, stamped when a
row enters `awaiting_nameservers` or `provisioning`; the 28-day and 72-hour clocks run
from it, because `created_at` would fail a row added before the token landed on its first
tick with one. The six-hour activation-check limit and the once-only Pages retry are
`Cache::add` markers per row, not columns. A managed or already-active zone's id is
recorded only once the CNAME is created or adopted, so a row refused by a DNS conflict
keeps no `cf_*` id and stays deletable (the DELETE rule is the plan's: any `cf_*` id,
`cf_zone_created` or `source = imported` is a 409). `ensureCname` judges only A, AAAA and
CNAME records at the name (TXT/MX/CAA neither conflict nor get touched), filters with
`name.exact` per the current API reference, and treats codes 81053/81057/81058 or the
words "already exists" as the create race. With a token, `imported` and `manual` rows are
promoted by one Pages GET and are never failed or written for, whatever Cloudflare says;
`reserved` and `failed` rows are never advanced (no probe either). `domains:reconcile`
also picks up `active` rows not yet seen serving, for the probe. "No-op without a token"
means no Cloudflare request and no selection of imported/manual/reserved rows; a
Studio row still moving is probed on its own host, which is how a hand-attached host
goes live. The domain routes live under `api/admin/masjids/{masjid_id}/domains` as the
plan names them, so StudioAccessTest (which walks `api/admin/studio/*`) does not cover
them; `MasjidDomainsAdminRoutesTest` walks them from the router instead. The domain check
adds `zone_status` only when the token is configured, so its tokenless answer stays
byte-identical to S3's. phpunit.xml pins `CLOUDFLARE_STUDIO_TOKEN` blank with
`force="true"`, so no CI tree's `.env` can hand the suite a real token.
Rationale: each keeps S7's two promises (honest without the token, and never a write for
a live tenant's row) where the plan did not say how.

## 2026-09-24 — Studio W1 S7 review: dead ends and races the first cut left
Decision: a failed row is always told to fix the cause and press Check now (which starts
it again from pending); "remove this domain" is offered only when `deletableThroughStudio()`
is true, and the attacher's failure texts name the cause only, so none of them sends an
operator to a DELETE that answers 409 or a re-add that answers 422. DELETE takes the
attacher's own lock (`DomainAttacher::lockFor`) and judges the row re-read inside it; while
a step holds the lock it answers 409 "try again", because a step keeps what it made in
Cloudflare in memory until its one save. Check now (`DomainAttacher::restart`) resets the
row under the same lock, judged on the row re-read inside it, and forgets the row's
Pages-retry and activation-check markers, so a new stage gets its own retry. A zone
POST that got no answer or a 5xx is remembered per row (a `Cache::add` marker, kept up to 28
days); a zone found on a later tick that was made no earlier than that attempt (five
minutes' clock allowance) is recorded as `cf_zone_created`. Without the token, a custom
apex is told to add the domain to Cloudflare and move its nameservers (with the MX and
28-day warnings), not to use a CNAME or ALIAS elsewhere: the Pages custom-domains page
(read 2026-09-24, last updated 2026-04-21) says an apex must be a zone on the account.
Rationale: each closes a way the operator's instructions or the `cf_*` record could stop
matching what exists in Cloudflare. A cleared cache degrades the zone marker to "found",
the state before it existed.

## 2026-09-24 — A portal invite is a family credential, not a reuse of the staff broker
Decision: "Send portal invite" mints its own 256-bit token into a new `contact_portal_invites`
table (HMAC-SHA256 at rest, keyed on `APP_KEY`), read by
`App\Services\Family\FamilyInviteService`. It does NOT go through the framework password broker
that `account_invite_tokens` and `App\Services\Auth\AccountAccessService` use.
Alternatives: (a) add a `contacts` broker to `config/auth.php` and reuse `AccountAccessService`;
(b) extend `account_invite_tokens` with a `masjid_id`.
Rationale: `PasswordBroker::createToken()` takes a `CanResetPassword` and the table's PRIMARY KEY is
the email address. A parent is a `contacts` row, not a `User`, and a family sign-in address is
unique only PER TENANT (`contacts_masjid_login_email_unique`; a globally-unique credential address
would answer "is this family also at that other school?"). Either reuse would make one school's
parent collide with another's and hand the broker a principal it would look for in `users`. What IS
copied is every property that made the staff invite safe: seven days, single use, fragment-only URL.

## 2026-09-24 — The parent arrives SIGNED IN, not at a set-a-password step
Decision: redeeming an invite mints an ordinary family session (`Contact::createFamilyToken()`,
`FAMILY_TOKEN_ABILITIES`, the `family` guard's own expiry) and lands the parent on their portal
home. No password is asked for, then or ever, by this path.
Alternatives: mirror the staff invite and land on "choose a password".
Rationale: a staff account IS a password and has no other way to exist. The family realm's premise
is the opposite and is already written down — "200 families cannot be issued passwords and a school
office cannot run a reset desk"; the credential is the mailbox, and a password is an optional
convenience a parent may choose later from inside the portal (`FamilyPasswordService`, which
deliberately has no admin twin). Making a password the price of entry re-imposes the friction this
feature exists to remove, at the one moment a parent is most likely to give up. Nothing in
`config/family.php` is loosened: the session is the same one `verify-code` mints, aged by the same
guard, and refused on its next request by `family.active` the moment the office revokes.

## 2026-09-24 — This is the one family email that carries a link
Decision: `FamilyPortalInviteMail` contains a click target. `FamilyLoginCodeMail` stays link-free.
Alternatives: mail the sign-in URL with no token and ask the parent to request a code (today's
behaviour, done by hand).
Rationale: the code mail's "a click target would be a phishing pattern to train families into"
governs the ROUTINE act, repeated for years, and is untouched. An invite is sent once, by a named
member of staff, to a parent who does not know the portal exists and has no page open to type into.
The measured cost of not having one: ten family logins enabled at Al-Razi, five never used. The
trade is bounded by single use, seven days, one live link, and the office's revoke switch.

## 2026-09-24 — The token is bound to the contact AND to the address it was mailed to
Decision: `contact_portal_invites.login_email` is part of the credential. Redemption re-reads
`contacts.login_email` and refuses on any difference; `FamilyAccessService` additionally stamps
`invalidated_at` on re-address, on revoke and on an address release.
Alternatives: bind to the contact only and rely on revocation.
Rationale: an office re-addresses a login exactly when the old mailbox was wrong, was a stranger's,
or belonged to a parent who has separated from the family. Both halves stay, for the reason
`revoke()` already gives for deleting tokens beside a middleware that would also refuse them: the
re-read covers rows nobody remembered to stamp, and the stamp covers a future caller that reaches
redemption by some other path. Each half is asserted alone, with the other undone
(`the_liveness_check_alone_refuses_a_revoked_login`, `the_bound_address_alone_refuses_a_moved_login`).

## 2026-09-24 — The send throttle is a row count, not a rate limiter
Decision: `config('family.invite.sends_per_hour_per_contact')` (3) is enforced by counting
`contact_portal_invites` rows created in the last hour for that contact, inside the service. The
redemption endpoint additionally carries a per-IP `throttle:family-invite` (20/hour).
Alternatives: a named rate limiter keyed on the contact, like `family-login`.
Rationale: the same call `contact_login_codes.attempts` makes. A limiter lives in the cache, a cache
flush is an ordinary deploy step, and what is bounded here is a real family's mailbox filling with
working keys to their child's records. Keyed on the CONTACT rather than the actor, because two
administrators sending five each is the same flood as one sending ten. The per-IP limiter on the
redemption side is not what makes the token unguessable (2^256 is); it bounds a retry loop.

## 2026-09-24 — Eligibility is re-checked at SEND time, and it is `enable()`'s own check
Decision: `FamilyAccessService::assertMayHoldAFamilyLogin()` became public and
`FamilyInviteService::issue()` calls it. A contact whose standing has lapsed keeps a working
credential but cannot be sent a fresh link.
Alternatives: trust the grant, since `enable()` already checked.
Rationale: standing lapses without revoking anything — a ward deleted, a guardian edge removed by
ordinary roster work — and this class argues at length that revoking there would burn a family's
sign-in every term. Keeping an existing credential alive and refusing to mint a NEW key to it is
the distinction those two arguments draw together. Sharing the method rather than copying the rule
is what keeps the invite door from being looser or stricter than the enable door.

## 2026-09-24 — A failed send is its own exception type, caught before the refusal
Decision: `FamilyInviteService::deliver()` wraps any send failure in
`App\Services\Family\InviteDeliveryFailed`, and `ContactFamilyLoginController::invite()` catches
that FIRST, answers 500 with a fixed sentence, and logs the cause. The transport's own message is
never carried to the screen.
Alternatives: catch `Symfony\Contracts\...\TransportExceptionInterface` first; let it fall through.
Rationale: found while reviewing this change rather than in production, and it would have shipped
silently. `Symfony\Component\Mailer\Exception\TransportException` extends `\RuntimeException`, which
is the type this controller answers 422-with-the-message for, because that is how the service's
REFUSALS reach an operator. A relay outage would therefore have been reported as "something is
wrong with this member", carrying a message that routinely quotes the recipient address and the
relay's response — the same leak the `QueryException` catch above it already exists to prevent, and
which was measured on that one. Catching the Symfony interface would work today and re-break the
moment a `Mail` decorator throws something else; wrapping at the point of failure makes the
ordering local and explicit. Pinned by
`FamilyPortalInviteTest::a_mail_failure_is_a_500_that_leaves_the_record_untouched`, which also
asserts the rollback: no invite row, no `invite_sent` row, and the parent's earlier link still works.

## 2026-09-24 — `contact_portal_invites` is LOGIN plumbing, not an office record
Decision: the table is in `MemberAccountDeletion::LOGIN_RECORDS`, so an invite never keeps a
contact alive against their own "Delete account".
Alternatives: `OFFICE_RECORDS` (the defensive choice).
Rationale: the row holds a keyed digest, an address and three timestamps — nothing the office is
keeping ABOUT the person. The act of granting access is office data and already sits in
`contact_login_events`, which is in `OFFICE_RECORDS`. That also makes the classification
outcome-neutral: a contact can only hold an invite row if somebody enabled their sign-in, and
enabling always wrote a `contact_login_events` row, so such a contact is kept by that list whatever
this one says. Classified honestly rather than defensively, and the pinned list in
`MemberAccountDeletionCoverageTest` was updated in the same commit, as that file requires.

## 2026-09-24 — A class file is addressed: whole class, named students, or staff only
Decision: `group_resources.visibility` gains a third value, `students`, and
`group_resource_recipients` (masjid_id, group_resource_id, group_membership_id)
names the set. `App\Support\GroupAudience::readableResourcesQuery()` /
`mayReceiveResource()` is the ONE place that turns an audience into rows, and
both the family LISTING and the family DOWNLOAD go through it — a query
constraint, never a response filter, so a forbidden row is never fetched and its
filename and size never enter a payload. The office console mounts the teacher
controller and therefore gets the leader answer (every file), which is the same
rule rather than an exemption.

**NO BACKFILL, and this deliberately contradicts the brief.** The brief said
today's behaviour is "visible to everyone who can see the class" and asked for
every existing row to be backfilled to whole-class. That premise is wrong about
this codebase: `visibility` has been `staff | families` since
`2026_09_08_120300_create_group_resources_table`, defaults to `staff`, and every
existing row already records a deliberate choice. Backfilling to `families`
would PUBLISH every file a teacher had marked private — the exact leak that
migration's docblock names three guards against. So: the column default stays
`staff`, no row is rewritten, and `students` is purely additive. Alternative
considered and rejected: honour the brief literally. Rationale: a migration that
widens an audience it cannot read cannot be undone by the people it exposed.

**A TARGETED FILE IS STILL FEED-CONSENT-GATED**, where a participant thread, a
behaviour award and a ḥifẓ entry about the same child are not. The three of those
are records ABOUT a child that the parent is obviously entitled to; a handout is
something the school SENDS, and the family resources surface has been gated on
`DISCLOSURE_FEED` since it shipped. Making targeted files consent-free would mean
`Family\ResourcesController` granting reads where it currently 403s — a widening
nobody asked for. `GroupNotificationRecipientResolver::consentedWardGuardians()`
exists so the nudge matches: a guardian who has not consented is neither mailed
about a targeted file nor shown one. **Open for the owner:** if a report card for
one child should reach a non-consenting parent, this is the line to move, and it
is one clause in `readableResourcesQuery()` plus the controller's gate.

**Removing or withdrawing a student NARROWS, never widens.** Two paths, both
tested: (a) `left_on` (withdrawal) — the recipient row survives, but the guardian
edge leaves with the child (`GroupMembership::updated`), so the family's standing
ends and no other family gains anything; (b) `delete()` (removal from the roster)
— `group_membership_id` CASCADES, so the claim goes with the row. A `students`
file whose last recipient has gone reaches STAFF ONLY. There is deliberately no
"no recipients means everyone" branch anywhere; the empty set is the empty
audience, and the office screen says "No students left" rather than a blank.
Alternative rejected: `nullOnDelete()`, which leaves a row naming nobody.

**Recipients are memberships, not contacts**, exactly as `behavior_awards` and
`hifz_entries` name their subject: a membership is (person, group), so a
recipient cannot name a child who is not on THIS roster, and the "is this parent
this child's?" question resolves from the guardian edges that already answer it.
`ResourcesController::resolveRecipients()` re-reads every id through
`$group->memberships()->participants()->current()` and refuses the WHOLE request
on any miss — a dropped element would let a teacher believe a file had been
addressed to somebody it had not.

## 2026-09-24 (later) — Consent does NOT gate a file addressed to one child
Owner's ruling, verbatim: **"yes bypass the consent gate for files addressed to
one child"**. This REVERSES the call recorded in the entry above, which shipped
earlier the same day, and closes ASSUMPTIONS #14.

The reasoning is the one the rest of the platform already uses: a file naming ONE
child is a disclosure about that child to their own guardian — the same shape as
a behaviour award (T-013), a ḥifẓ entry (T-014) and a participant thread
(T-005c), none of which consult consent, for the reason
`.claude/rules/groups.md` states as "consent gates broadcasts, not a parent's
view of their own child". The earlier entry treated a handout as something the
school SENDS and therefore a broadcast. Against a real case it is not: the
document is a report card, and the gate locked the parent out of it and told them
nothing.

**Exactly what changed, and nothing else:**

- `students` files: readable by a guardian of a NAMED child with NO consent
  record. `staff` unchanged. Another family's targeted file unchanged — absent
  from the listing, 404 that names nothing.
- `families` (whole-class) files: **UNCHANGED, still consent-gated.** A
  class-wide handout is classroom-wide content and keeps the class story's rule.
- **Leaving the class still ends both.** Consent and departure were ONE flag
  (`standingIn()['feed']`), which is why the first implementation could not have
  one without the other. `standingIn()` now returns `current` — "holds a row that
  has not left", with no consent clause — beside `feed`. The `families` branch
  asks `feed`; the `students` branch asks `current`. Two questions, two flags;
  the docblock says so, because a reader reaching for the wrong one is how this
  drifts back.

**The gate had to leave the controller, not be duplicated.**
`Family\ResourcesController` was calling `authorizeDisclosure(DISCLOSURE_FEED)`
over the whole surface, so it 403'd the listing before the audience query ran and
hid a targeted file `readableResourcesQuery()` was willing to serve. Both calls
are gone; the controller now 403s only when the audience returns null (no
standing in the group at all) and otherwise serves the constrained query, which
is the shape `Family\BehaviorAwardsController::readable()` has always had. A
consent branch in a controller could only ever disagree with the one in
`GroupAudience`, and this one already was.

**Consequence worth stating:** a family that has LEFT now gets an empty 200 on
the listing where they used to get a 403, because their rows are retained so they
are still `in_group`. What they can SEE is unchanged — nothing.
`withdrawing_a_student_ends_their_claim_and_widens_nothing` was updated to assert
the empty 200, deliberately.
## 2026-09-24 — Video gets its own config block, its own upload bag, its own everything
Decision: `config('groups.media.video')` — a separate mime allowlist
(`video/mp4,video/quicktime,video/webm`), size ceiling (100 MB), per-post count (1),
retention window (90 days) and playback TTL (10 min) — plus a second top-level upload bag,
`GroupPostFormRequest::VIDEO_UPLOAD_KEY = 'videos'`, validated by `videoRules()` beside the
untouched `imageRules()`.
Alternatives: widen `groups.media.mime_types` and raise `max_size_kb` (one bag, one rule, far
less code); a `kind` column on the attachment tables to tell the two apart.
Rationale: the four image keys are a SINGLE SHARED DEFINITION read by the class story, the
conversations *and* — by name, in its own comment — the resource library's sibling block.
Adding `video/mp4` and 100 MB there would have made a **100 MB image** legal on every one of
those surfaces, on a 2 GB droplet. `GroupVideoAttachmentsTest::a_video_sent_in_the_image_bag_
is_refused` and `::a_photo_sent_in_the_video_bag_is_refused` are the two halves of that
guarantee. No `kind` column: `mime_type` is sniffed from the bytes and is already
authoritative, and a second column could disagree with it.

## 2026-09-24 — Playback is a short-lived, viewer-bound, RELATIVE signed ticket
Decision: video is played through `GroupMediaPlaybackController` behind
`signed:relative` + `throttle:240,1`. An authenticated `POST .../attachments/{id}/playback`
on each realm's own controller mints the URL; the signed handler then **re-binds the tenant
from the URL, re-resolves the whole ownership chain link by link, re-applies the CRM gate,
re-resolves the named viewer from the database and re-asks `GroupAudience`** before a byte
leaves. It answers with a manual `Range`-aware stream (`App\Support\PrivateMediaStream`).
Alternatives, and why not:
  - **Keep `Storage::download()`.** It is a `StreamedResponse` with `attachment` disposition
    and no `Accept-Ranges`, so `<video>` cannot seek and must buffer the whole file. At
    100 MB that is not slow, it is broken.
  - **Keep the bearer-token blob fetch the photos use.** A `<video>` element issues its own
    requests and cannot be given an `Authorization` header; fetching 100 MB into memory
    before the first frame is the same failure by another route.
  - **A permanent private URL behind a session cookie.** The realms are token-based; there is
    no cookie, and a durable URL is exactly what `.claude/rules/private-uploads.md` forbids.
  - **Mint the ticket inside the list payload.** Its ten minutes would start when the page
    rendered rather than when somebody pressed play, and a playable URL would sit in whatever
    holds that payload. The payload carries a `playback_ticket_path` — a path to ASK — instead.
  - **An ABSOLUTE signed URL.** Built from `config('app.url')`, which is not the origin the
    SPA is served from on the second host (see `SecurityHeaders`), so playback would be a
    cross-origin media load into the CSP and CORS allowlists that have already cost this
    project two outages. Relative signatures are host-independent, and they match what the
    SPA already does: `VITE_APP_URL` is deliberately left EMPTY at build time
    (`resources/vue-app/core/types/declarations/env.d.ts`, and the build is run as
    `env -u VITE_APP_URL npm run build`) so every API call is same-origin on whichever host
    serves the page. A relative ticket is the only form that keeps that true, and
    `default-src 'self'` in `SecurityHeaders` — which `media-src` falls back to — then covers
    it on every host without another allowlist entry.
  - **Symfony's `BinaryFileResponse`** for the range arithmetic: it needs a local path, so it
    would be one code path in production and a hand-written fallback for any other disk —
    meaning the one the suite exercises is not the one that ships. One path, tested.
Rationale: this is the minimum that keeps all three private-upload guarantees (no permanent
public URL, chain re-resolved, consent re-checked **at access time**) while letting a browser
seek. `::withdrawing_consent_stops_the_next_range_on_a_ticket_already_minted` is the proof.
**The cost, stated:** within the ticket's lifetime the URL is a bearer credential — anyone
holding the string can watch. That is why the window is ten minutes and why IMAGES WERE NOT
MOVED ONTO IT: a photo loads fine as a blob and gains nothing from a weaker arrangement.

## 2026-09-24 — Video retention is a column on the ATTACHMENT, not a second parent window
Decision: `group_post_attachments.retained_until` / `group_message_attachments.retained_until`,
nullable, stamped only for video (90 days) by `App\Support\GroupMedia::retainedUntilFor()`,
swept by a fourth pass in `groups:purge-feed` that deletes THROUGH THE MODEL.
Alternatives: shorten the whole post's window when it carries a video (takes the words and
the photos with it); a separate `groups:purge-video` command.
Rationale: retention lived only on the parent, so a clip inside a post inherited the post's
365 days. Null stays the default, so every existing and future photograph is untouched and
still dies exactly when its parent does — the change is additive rather than a policy applied
retroactively. One sweep, not two, for the reason the threads and the behaviour awards were
folded in: retention over a group's content is one policy.

## 2026-09-24 — The picker holds one list; the CALLER splits the two bags
Decision: `TeacherPhotoPicker` (and the office story tab) accept photos and video through one
control and one `File[]`; the upload helpers split by `file.type` into `images[]` / `videos[]`.
Every renderer branches on `mime_type` to `<video controls preload="metadata">`.
Alternatives: two pickers; one bag and a server-side sort.
Rationale: a teacher choosing "three photos and the recital" should not have to find two
buttons, but the server must keep two rules. Nothing transcodes or thumbnails anything —
there is no ffmpeg on the droplet, so `preload="metadata"` is the only poster there is.

## 2026-09-24 — The office conversations box takes photos and one video too, and the picker moved
Decision (owner, same day): the office/admin conversations compose box gets the same attachment
control the teacher screens have — photos AND one video, one picker. `TeacherPhotoPicker.vue`
moved to `components/partials/GroupMediaPicker.vue` and is now used by both realms;
`groupThreadsStore` sends multipart with the two bags, keyed from `meta` rather than literals.
Alternatives: a second picker component in the dashboard tree (rejected — the copy is the one
that stops getting the fix); importing `@/views/teacher/...` into a dashboard view (rejected —
a cross-realm import that reads as an accident).
Rationale: **no server change was needed, and that was verified rather than assumed** —
`AdminDashboard\GroupThreadsController::storeMessage` already calls `$this->uploads($request)`,
which reads both bags, and `StoreGroupMessageRequest`/`StoreGroupThreadRequest` already carry
`mediaRules()`. The office box was text-only because no client ever sent files, not because the
server refused them. Parents still attach NOTHING: `StoreFamilyMessageRequest` validates `body`
only, and that is untouched. Send is enabled by text OR an attachment, matching the server's
`required_without_all`; staged files are cleared on a successful send, on a thread switch, on a
group switch and when the new-conversation modal reopens, and NOT on a failed send.

## 2026-09-24 — Playback admits a masjid OWNER, not only a `masjid_user` row
Decision: `GroupMediaPlaybackController::viewer()`'s staff branch accepts either
`masjids.user_id === $user->id` or a `masjid_user` membership.
Alternatives: require the pivot row (what it did first); synthesise a membership.
Rationale: a BUG, found by building the office path and pinned by
`office_staff_attach_a_video_to_a_conversation_message_and_play_it`. `App\Support\TenantResolver`
has two ways in, and its own docblock records that `masjids.user_id` is set by factories, seeders
and two provisioning controllers that write no membership — so "every organisation provisioned
since" has an owner with no row. Checking only the pivot let an office admin list a video, open
the download endpoint and mint a ticket, and then be refused the bytes for owning the school.
Removing the ownership arm fails that test with a 403 where 206 is expected.

## 2026-09-24 — Studio W1 S5 (stage A): calls made where the plan was silent
Decision: the drafts list asks for `?status=all`, because the Status column links a provisioned
draft to its organisation and the endpoint defaults to open drafts only. The Identity panel's
organisation types, terminology and prayer choices come from `GET /onboarding/options`, the
endpoint the wizard reads, so Studio holds no copy of `config/verticals.php`. Nothing is
pre-chosen on a new draft: no colours (R25), no calculation method, no iqama offsets, no
timezone; a platform starts on the Managed account mode, as in the wizard. Next from
Foundation needs the organisation type, the name, four colours, one platform, and the logo when
web is chosen (the plan names only the logo rule; the rest are what Steps 1 and 2 read). The
autosave sends each changed section whole and drops blanks (`''`, null, empty objects), which the
server reads as absent; a step change is saved at once with any pending sections. A 409 disarms
the autosave until "Reload draft"; a 422 or network failure waits for the next edit or Retry.
The logo sampler's candidates are saved to `brand.extracted` only on upload, never on load, so
opening a draft never writes. `prepareLogo` redraws an over-cap PNG or JPEG as PNG too (the plan
says "any other type"), and draws an SVG at the 2048 px cap. `appLabels.ts` copies iOS from
`origin/main` 8e5191f and Android from `feat/r1-owner-answers` aeac265, the only branch with the
R1 tab bar `StudioPreview::ANDROID_TABS` cites.
Rationale: each keeps a draft to what the operator entered and keeps Studio off an eighth copy of
the feature list; recorded because the plan left them open.
Measured: `vue-tsc --noEmit` at b81980da reports 105 errors (vue-tsc 2.2.12 on the repo's
TypeScript 5.7.3), not the 29 the plan cites; this slice adds none.

## 2026-09-24 — Studio W1 S5 (stage B): calls made where the plan was silent
Decision: the feature step writes the full map of served keys (R9) as soon as its catalogue is
there on an armed draft: a stored boolean is kept, an unset key starts on `default_at_creation`, or
on when a `preselect_with` platform is chosen, and a stored key the catalogue no longer serves is
dropped. Preselect therefore applies to keys the operator has not set; a platform added later does
not flip a stored switch, and the row says "Suggested with …" instead. Leaving Features forward is
blocked until its catalogue has loaded (StudioView), because the step is blocked behind Retry. Each
layout card's thumbnail is the preview endpoint's plan of THIS draft with that preset swapped in
(`studioDraftStore.previewPreset`, `presetPreviewBody`), not the preset payload, so cards show the
sections the client's switches keep and the client's own words; nothing is saved by it. "Show in
preview" writes `layout.preset` and clears `approved_at`; "Approve this layout" writes both, and a
preset from another organisation type is not treated as chosen. The website frame reads section
words by content field (title/heading, subtitle/description, text, button_text, links' labels),
never by section type, draws the first section of a page as its banner, shows each open
placeholder's admin hint, and follows `theme_layout` through `themeTokens.styleFromTokens`. The
frames draw in greys until the four colours exist (R25). The app colours the apps hard-code live in
`appLabels.ts` beside the words and are held equal to StudioPreview's constants by a test. Phone
frames draw at no more than 0.6 scale so the sticky column fits a laptop screen. The platform list
moved from the Platforms panel to `core/studio/platforms.ts` so the panel, the feature step and the
preview name platforms alike.
Rationale: each keeps the SPA on the server's one derivation (R19) with no copy of keys, labels,
presets or section types; recorded because the plan left them open.
Deviations from the plan's wording, following the code: iOS menu items' `parts` is an object of
module => bool (`AppMenu::sections`, app/Support/AppMenu.php:359), not a list, and Studio.ts now
says so.

## 2026-09-24 — Studio W1 S5 review: the feature map follows the draft, and the autosave is its own module
Decision: the stored feature map is carried, not frozen (supersedes stage B's "a platform added
later does not flip a stored switch"). The draft still stores the full map of served keys (R9)
and the server has no field for which keys the operator touched, so they are told apart by where
they sit: `carryChoices` (core/studio/featureChoices.ts) empties the map when the organisation
type changes (a masjid's worship switches are not a school's; the new type starts from its own
defaults), and when the platforms change it moves every switch still on the starting value the
old platforms gave it onto the new platforms' starting value, keeping any switch the operator
moved. The store does this (`syncFeatureChoices`), not the feature step, because both answers
change on Foundation where the step is not mounted; when the platforms move under a stored map
without this type's catalogue loaded, the store fetches it. The one choice this cannot keep is an
operator's "off" for a Web-suggested switch after Web is removed and added again: it then looks
untouched and comes back on, suggested. The autosave's timing and rules moved to
core/studio/autosave.ts (the store keeps the answers, the lock version and the one PATCH) so
node can test them; a step change now names the step the operator is on when they move back
before the previous step's save answers. The website mockup lists only active pages in its menu
and footer (core/studio/sitePages.ts), as PagesController serves them. `prepareLogo` keeps a
wide logo's short side at the server's 96 px, letting the long side pass 2048 up to the server's
8000, and refuses a logo too thin for both with a sentence; the two limits are held equal to
`config('studio.logo.min_px')` and StoreStudioDraftLogoRequest by StudioSpaSourceTest. The
sticky preview column stops below the fixed header through `--dash-header-height`, which
DashboardLayout now publishes from the height it already measures (FlyerStudioView's sticky
column has the same `top: 1rem` and is left as it is, outside this slice). Rebased onto
8b5787da: BackendApiRoutes keeps S7's domain routes and S5's Studio routes with the domain check
listed once, and `StudioDomainCheck`/`StudioDomainCheckRequest` are now S7's
`MasjidDomainCheck`/`MasjidDomainRequest`, which carry the Cloudflare cases and `zone_status`.
Rationale: each closes a way the draft, the preview or the upload could say something other
than what Step 3 will create or the server will take; recorded because the first cut chose
otherwise.
Measured: `vue-tsc --noEmit` (vue-tsc 2.2.12 on TypeScript 5.7.3) reports 105 errors at
8b5787da and 105 at this commit, the same set.

## 2026-09-24 — Studio W1 S8 (stage A): provision from a draft, and the calls the plan left open
Decision (the four the plan asks this slice to record):
- **Two ledger policies.** The single switch (`MasjidsController::setCapability`) still ledgers a
  no-op flip (`CapabilityChangeLedgerTest`), because a SuperAdmin pressed a button on a live org.
  `CapabilityWriter::applyAtCreation` ledgers only DEPARTURES from `defaultAtCreation`, because a
  key left at its default was not decided about, and a Studio org must stay as sparse as a
  wizard-made one (R9). A CRM choice is a departure like any other: the row is born at
  `capabilities.crm.provision_default` and the writer ledgers the change (R26).
- **Iqama.** On the Studio path iqama is displayed only when the client gave times: the draft's
  "client has not given iqama times" tick (`prayer.iqama_given = false`) is sent as
  `show_iqama_times = false`. The wizard's invented 20/10/10/5/10 schedule, shown by default,
  stays on the wizard's path only (`OrganisationProvisioner`, iqama block). (Tightened by the S8
  review fixes below: an untouched panel is hidden too, and a partial set is refused.)
- **Donation labels.** "Donation Link" / "Donate Now" (written when a donation link comes without
  wording) stay: they are interface words, not facts about a congregation, and D8's list is
  history, scholars, programmes and numbers. `StudioStarterSiteServedProvenanceTest` allows exactly
  these two, plus SectionContentBinder's own mission/vision card words ("Our Mission", "Our
  Vision") and item types (`mission`, `vision`), and nothing else that is not a fact, a label, a
  page path, a structural value or the org's own media URL.
- **Jumu'ah.** The 13:30 default is still stored. The web does not draw it; W2/W3 must not show it
  unless it was supplied.

Calls made where the plan was silent:
- `settings.studio` is `{version: 1, preset, slot, placeholders: [{field, kind, hint, essential,
  source?}]}` on EVERY starter section. Whether a placeholder is open is never stored (it is a
  function of the content and the bound rows) and neither is the hint's sentence (a key into
  `studio_layouts.hints`). `StarterPlaceholders::publicSettings` strips it on the public path.
- `StudioProvisioning::provision` returns a `StudioProvisionResult` (the org, the context, the
  after-commit report) rather than a bare `Masjid`, because the 201 body needs all three. What the
  provisioner's optional steps did travels on `ProvisionContext` (`capabilitiesApplied`,
  `starterSite`, `domains`), leaving `create()`'s signature as the wizard and the demo fixture call it.
- Studio always sends `capabilities` (an empty map when the draft never reached Step 1), so a
  Studio org is always born through the switches with its pivot derived from them, and the 201
  always carries `capabilities_applied` (the SPA treats its absence as an old backend).
- The web deliverable (`layout_preset`, `web_domain`) is flattened from the draft only when web is
  selected; the request refuses either without web, and a custom domain without a slug, rather
  than dropping it.
- A host row is written with `waiting_on = token` when the Cloudflare token is blank (what the
  attacher writes on its first pass), so the 201 says so before the job has run. The attach job is
  dispatched only by Studio, after the commit. A direct POST to the wizard's endpoint carrying a
  slug gets its rows but no immediate job; `domains:reconcile` advances them within five minutes.
- The brand gate also refuses a name another organisation has. `masjids.name` is unique in the
  database and the wizard's rules never checked it, so a duplicate was a 500 naming nothing;
  Studio answers 422 on `name`. The wizard's own behaviour is left as it was.
- The logo bytes are copied from the draft's private disk into
  `storage/app/private/studio-tmp/{draft}-{random}/` before the transaction, and medialibrary adds
  from those copies with `preservingOriginal()` (kept as the plan requires; with copies as the
  source it is belt and braces, not the only thing that makes a retry possible).
- The 409 is answered before validation as well as under the lock: a second provision of the same
  answers would otherwise fail validation (its email now belongs to the first org) and read as
  "fix your answers" instead of "this exists".
- `StudioDraftProvisionPayloadTest` was edited: it pinned S2's "slug, description and capabilities
  are not yet request keys", which this slice makes them.
Alternatives: a `config('studio.appliers')` registry (R10, rejected by the plan); storing
`open`/`hint_text` in the marker (stale the moment an admin types the About text); a nullable
`capabilities_applied` (indistinguishable from an old backend).
Rationale: each keeps a Studio org identical to what the wizard would make from the same answers
except for the draft-only writes R10 names, and keeps every live tenant's payloads byte-identical
(`LivePublicPayloadsUnchangedTest` compares against recordings the base fe390d7d wrote).

## 2026-09-24 — Studio W1 S8 (stage B): Step 3 in the Studio SPA, and the calls the plan left open
Decision:
- **Where the store credentials live (R7).** In `StepGenerate`'s own `reactive`, never in the
  draft store, whose answers are autosaved. `ByoCredentialsFields` owns no copy (it emits each
  keystroke); `store.provision(secrets)` puts them in the provision body through
  `core/studio/provision.ts provisionBody` and nowhere else; they are blanked once an organisation
  exists and dropped with the page. S5's `StudioSpaSourceTest::no_studio_file_names_a_store_credential`
  forbade any Studio file to name one, which Step 3 cannot satisfy, so it became
  `only_step_3s_credential_files_name_a_store_credential`: exactly `core/studio/provision.ts` and
  `generate/ByoCredentialsFields.vue` may, and `draftAnswers.ts` and `autosave.ts` must not read the
  provision module. The plan's contract for that test ("the autosave body never contains a
  SECRET_KEYS field") is unchanged, and `studio-provision.test.ts` checks the autosave body directly.
- **The Provision gate** is R27's three items worded exactly as `StudioBrandGate` words its 422,
  plus two the server would also refuse or silently default: each selected BYO platform's
  credentials (the wizard's `required_if` rules), and Step 1's feature map. A draft that never
  opened Features has no map, and `StudioProvisioning` then sends an empty one, so every switch
  would start at its default unseen; the button says "Open Features" instead.
- **Before the POST the autosave is flushed**, because the server provisions the draft it holds; if
  the flush fails or conflicts, nothing is sent and the step says the answers are not saved.
- **A 201 marks the draft provisioned in the SPA** (autosave disarmed for good, the list row Live)
  rather than reloading it, so the results survive a failed reload, and a backend that did not
  mark the draft cannot be offered a second provision. A 201 without `capabilities_applied` is
  shown as an error naming the organisation, never as success; a 409 reloads the draft (read only)
  and says nothing new was created, with no retry; a lost answer says a retry is safe, because the
  server answers 409 for a draft it already provisioned.
- **The invitation line** is ticked only when `invites_sent > 0` and `invites_failed == 0`. With
  none sent and none failed it says why in the provisioner's terms: an existing `user_id` is not
  invited, and a draft without `admin.email` names no one to invite. A reopened provisioned draft
  does not know, and says so.
- **The web address** after provisioning is S7's `StudioDomainAttachPanel` for the new
  organisation, so Check now and "Open live site" (a link only once `live_url` exists, R24) are
  S7's own; the step itself never links a host.
- A confirm dialog precedes the POST, because it creates a real organisation and sends mail.
- `core/studio/steps.ts` loses `GENERATE_AVAILABLE`; Generate opens under the same Foundation gate
  as Features and Layout, and its heading takes focus like theirs.
- The account-mode labels moved to `core/studio/platforms.ts` (`ACCOUNT_MODE_OPTIONS`), shared by
  the Platforms panel and the review.
Alternatives: credentials in the Pinia store (autosave-adjacent state, visible to devtools and
every Studio component); reloading the draft after a 201 (loses the one-time report on a failed
GET); letting Provision through without a feature map (the org would be born at defaults nobody
reviewed).
Rationale: every sentence on the results screen restates the server's report, the credentials
have exactly one path out of the browser, and a provision can only ever be offered once.
Measured: `vue-tsc --noEmit` (vue-tsc 2.2.12 on TypeScript 5.7.3) reports 106 errors at ce3e945e
(stage A; stage A changed no SPA file, so 106 is this tree's base, not the 105 recorded at
8b5787da) and the same 106, line for line, with this slice.

## 2026-09-24 — Studio W1 S8 review fixes: iqama's truth, a draft that changes under Provision, and Step 3's answer
Decision:
- **Iqama, completed.** Studio always sends `show_iqama_times` (`StudioDraft::showsIqama`): true
  only for a masjid (an absent type reads as one, as the request reads it) with at least one offset
  and no "not given" tick. The request then requires all five (`ProvisionMasjidRequest::
  iqamaIncomplete`, 422 naming the missing prayers), and Step 3 says the same sentence as a
  blocker (`provision.ts iqamaBlockers`). So an untouched panel is hidden, Fajr alone is refused
  rather than shown beside four invented times or hidden with the one the client gave, and all
  five are shown. On Studio's path (`show_iqama_times` sent) an offset nobody gave is the column's
  own 0, never 20/10/10/5/10; it is never shown, because iqama is then hidden. The wizard never
  sends the key and keeps its `true` and its fallbacks (ProvisionIqamaTruthTest::the_legacy_path_is_unchanged).
- **Jumu'ah stays as recorded.** The 13:30 default is still stored on both paths: the S8 contract
  records that call (docs/manara-studio-w1.md, "Jumu'ah"), so the review's suggestion to extend
  the iqama rule to it was not taken here.
- **A draft that changed is refused, not provisioned.** Under the lock the draft must still have
  the `lock_version`, `logo_path` and `logo_sha256` it was read with (a logo upload does not move
  the version), and Step 3 sends the `lock_version` it reviewed (optional in
  `StudioProvisionRequest`). Otherwise `StudioDraftChanged`: 409 `{status:'conflict', message,
  data:{draft_id, provisioned_masjid_id: null}}`, nothing written, and the SPA reloads the draft
  and says to review and press again. A null `provisioned_masjid_id` is how the SPA tells it from
  "already provisioned".
- **Races answer what is true.** A 422 from validation or the brand gate re-reads the draft: now
  provisioned (a twin committed after the pre-check) is the 409 naming the organisation; gone is
  a 404. A draft discarded before the lock is a 404, not a 500.
- **Account modes only for selected platforms.** `toProvisionPayload` sends `apps[p]` only for a
  platform in `platforms.platforms`; a "Bring your own" left on an unticked platform made the
  wizard's `required_if` demand credentials Step 3 has no field for. The provisioner then stores
  the default `managed` mode for the unselected platform.
- **Capability keys are top-level config keys.** Looked up with `array_key_exists` on
  `config('capabilities')`, as `UpdateStudioDraftRequest` does; `config("capabilities.{$key}")`
  read a dotted key as a path.
- **Malformed wizard input is a 422.** `org_type` is read as a string only when it is one before
  rules() uses it, and `slug` and the web-domain keys `bail` at `string`, so an array never reaches
  a `(string)` cast (PHP's warning, Laravel's 500).
- **Step 3's answer.** "Not created" only on the controller's own 500 envelope; a 404 says the
  draft is gone; no answer, a proxy's 502/504 or any other status is `unknown` ("press Provision
  again to find out": a created organisation answers 409). While a provision runs the steps, the
  stepper, Back/Next and the logo are locked (`store.editable`), and leaving asks first (the
  browser's prompt, and a route-leave dialog), because the results exist only in that answer. The
  invitation line names the administrator the draft held when Provision was pressed
  (`Invitee`, carried in the outcome). The answer takes focus (`outcomeFocusId`). Pages are
  "switched on", never "live", until the domain panel confirms serving. A provisioned draft's logo
  is not fetched (its bytes are deleted after the commit); the Brand panel says it is on the
  organisation.
- **The store's order is testable.** `saveThenPost` (flush, then check the save, then post) and
  `clearsSecrets` are pure functions in `provision.ts`, unit-tested; the source test pins that the
  store posts only through the one and the step clears by the other, and the credential guard now
  removes the two allowed expressions exactly instead of skipping their lines.
Alternatives: hiding iqama silently whenever any offset is missing (drops times the client gave);
rebuilding the payload from the locked row (would provision answers nobody reviewed); returning a
bare 201 when the response body fails after the commit (the SPA's wording now covers it, and a
partial body would need its own type).
Rationale: the organisation is made only from answers someone reviewed and saved, a Studio org
never shows a time the congregation did not give, and every sentence on Step 3 is one the server's
answer supports.
Measured: `vue-tsc --noEmit` (vue-tsc 2.2.12, TypeScript 5.7.3) reports 106 errors at fe390d7d and
at 41ea90d3 in this environment, and the same 106, line for line, with these fixes.

## 2026-09-24 — Studio W1 S9: CORS and payment-return origins from `masjid_domains`, and the calls the plan left open
Decision: `App\Http\Middleware\HandleCorsWithDomains` replaces `HandleCors` in the global stack
and adds `MasjidDomain::corsOrigins()` (the `corsAdmitted()` rows) to `config('cors.allowed_origins')`
for a request whose `Origin` the static list does not already name; `FormPaymentReturn::allowedOrigin`
also accepts `https://<host>` of a `corsAdmitted()` row of the form's own organisation. The static
env lists stay the base and are never narrowed. Calls the plan left open:
- **The merged list is seen by the CORS decision only.** The plan says "set it for this request";
  `HandleCors` loads `config('cors')` into the CorsService before running the stack, so the
  middleware puts the static list back before `$next` and again in `finally`. Why:
  `JummahLunchOrdersController::returnUrlsFor` trusts `config('cors.allowed_origins')` as a Stripe
  return allowlist with no organisation check, so leaking the merged list into it would let one
  organisation's confirmed host become the lunch return address for another's order; and a
  long-lived app (tests, workers) must not carry one request's list into the next. Pinned by
  `CorsDomainOriginsTest::the_merged_list_is_seen_by_the_cors_decision_only`.
- **HandleCors' skip callbacks are honoured before any read**, in the parent's own order (skip,
  path, Origin, static list), so a request the parent would ignore costs nothing.
- **No confirmed rows means the parent runs unchanged**, not with a rewritten config.
- **A payment-return table read that throws refuses** (warning logged). The CORS side falls back to
  the static list, which is also the safe direction; for a Stripe return, "safe" is "no".
- **Only a bare lower-case `https://host` is looked up** for a payment return (no `http`, port,
  path, trailing dot or upper case): the stored host is normalised and a browser on our site sends
  exactly that, so normalising the header could only widen the match.
- **`base()` takes the masjid id as a required second argument** (`base($request, $masjidId,
  $context)`), so a caller cannot forget it; the submit passes `$form->masjid_id`, the reopen
  `$row->masjid_id`.
- **The S9 gate was re-checked against §8 OQ6 during review (2026-09-24), and OQ6 was corrected.**
  OQ6 said `CORS_ALLOWED_ORIGINS` holds 11 origins; the list this slice was briefed with holds 12,
  the 12th being `https://preview.manara.hopetechapps.com`, which the owner added for the live
  preview (`docs/live-preview.md` step 5, "owner, 2026-09-24"). That difference is expected, not a
  reason to re-plan: a 12th static origin takes the parent's untouched path. OQ6, the S9 facts and
  the S9 "Verify in production" list now say 12, preview included; `FORMS_PAYMENT_RETURN_ORIGINS`
  is unchanged (only `https://sundayschool.burlingtonmasjid.com`). Unknown, needs investigation
  until the ship: this session had no production access, so the counts above are the briefing's,
  not a read. The ship's preflight must read both lists through the app (`config('cors.allowed_origins')`,
  `config('forms.payment_return_origins')`, never by editing `.env`), record the values here, and
  stop if either differs from OQ6.
Alternatives: mutating config for the whole request (the plan's literal wording; widens the lunch
return allowlist across organisations); reimplementing `HandleCors` to hand the merged list straight
to the CorsService (duplicates framework code the parent already maintains).
Rationale: S9 must be invisible for every live origin: every live origin is on production's static
list, so it takes the parent's path untouched, headers and `Vary` included, with no table or cache
read. Mutation-checked: each of 13 mutations (registration removed, static/path/wildcard checks
removed, catch removed, either restore removed, `served()` for `corsAdmitted()`, the save-forget
removed, the payment masjid match/scope/lookup/catch/origin pattern loosened) fails at least one of
the new tests. The review found four more that survived, now killed: the submit passing `$form->id`
and the reopen passing `$row->form_id` (the controller-wiring tests in `FormPaymentReturnDomainTest`,
on a form whose id is the other organisation's id), a TTL of a day (the trashed-org test travels a
literal 301 seconds), and an `/i` on the payment-return host pattern (the rejected shapes are now
asserted to run no query, because SQLite's case-sensitive `=` masked it).

## 2026-09-25 — Lunch: a paid order's customer adds plates by paying the difference; the order-link email
Decision (owner, 2026-09-24): before the cutoff, a customer may change a PAID lunch order on its
link. The new total is set against what was paid (`MealOrder::settledMinor`): lower is a 422
`paid_reduce` (no automatic refunds); the same is applied at once through `MealOrderEditor`,
audited as the customer's; higher changes nothing and opens a Checkout Session for exactly the
difference on the org's connected account (card only, expires at min(cutoff, 24h)), parked on a
pending `meal_order_top_ups` row. Inside 30 minutes of the cutoff it is a 422 `too_close_to_cutoff`.
Only `checkout.session.completed` for `metadata.kind` = `lunch_top_up`, routed before the order
path, applies it, after matching the account's masjid, the top-up id, the session id, the order
uuid, the metadata masjid id, `amount_total` and `payment_status: paid`. An order that moved meanwhile
(total or settled changed, cancelled, no longer paid, the top-up superseded, or the menu re-prices
to another total) keeps its plates and records the money (`settled_total_minor` += amount), so the
board shows it owed back; the top-up is `conflict` and a warning is logged. An order email
(`LunchOrderConfirmation`, queued, only when the order holds an address) goes on placing a
pay-at-pickup order, on an online order's payment, and as "updated" on an applied top-up; each is
claimed once (`meal_orders.confirmation_sent_at`, `meal_order_top_ups.notified_at`).
Calls the spec left open:
- The Stripe Checkout email is kept only where the menu asks for an email
  (`collect_customer_email`): an org that switched the field off chose not to hold addresses.
- Any customer change to a paid order closes an open top-up page first, the swap included; a page
  Stripe reports as complete refuses the change (`topup_confirming`).
- A top-up page never lives under 31 minutes (Stripe's floor measured on its side), so for a cutoff
  30 to 31 minutes away it can outlive the cutoff by under a minute; a payment there still applies.
- `meal_order_top_ups.idempotency_key` (written before the Stripe call) and `notified_at` were added
  beyond the spec's column list; `meal_orders.site_origin` remembers the allowlisted origin the
  order was placed from, because the card order's email is sent from the webhook.
- The payment intent carries `kind` and `top_up_id` but no `order_uuid`, and
  `payment_intent.succeeded` for a top-up is acked and ignored.
Rationale: the money is always recorded, the plates move only on a verified payment of exactly the
quoted difference, and no path that routes by `order_uuid` can read a top-up as the order's payment.

## 2026-09-25 — Lunch top-up review fixes (money, security, UX lenses on c20dae1d)
Decisions taken on the review findings; the owner's rules (no automatic refunds, email optional)
are unchanged.
- **A payment after ordering ended is a conflict, not an edit.** The webhook records the money
  and adds no plates when the menu is no longer `open`, or when the payment was made after
  `ordering_closes_at`. "Made" is the event's own `created` time (passed from
  `StripeWebhookController`), so a retry delivered after the cutoff for a payment made before it
  still applies. Changing or deleting a menu also closes, best effort, every top-up page that
  would outlive ordering (`MealOrderCheckoutService::closeTopUpsOutliving`).
- **A paid order whose dish prices moved is not changed online** (`paid_prices_moved`), rather
  than keeping the paid unit price for old plates and the menu price for new ones. Mixed
  prices on one line do not fit the line schema (one `unit_price_minor` per line), and the
  refusal is the conservative choice: the masjid settles it by hand.
- **A paid order with any balance open is not changed online** (`paid_balance_open`), whichever
  way the balance runs. The app cannot see a refund made in Stripe, so "what was paid" may no
  longer be true, and a change priced against it could re-spend refunded money.
- **A top-up applies only to the exact order it was priced against.** `base_fingerprint`
  (`MealOrderEditor::fingerprint`, sha256 of every line and money column) is stored on the
  top-up and compared under the lock; a same-total staff swap is now a conflict instead of being
  overwritten. New column by its own migration (090002), so a database that already ran 090000
  still gets it.
- **One open top-up, enforced under the lock.** A pending top-up found under the order lock
  refuses a new page or a swap (`order_moved`); staff edits and cancelling a paid order close the
  customer's page first.
- **A completion that cannot be recorded closes the top-up** as `rejected` (unpaid, or another
  amount or currency), so the order is not held on "still being confirmed"; a later success on
  that page is recorded as a conflict.
- **A top-up under $0.50** is refused by name (`topup_too_small`); Stripe will not charge it.
- **Card fees on a top-up are not covered by the customer**, even when they covered the fee on
  the order: the top-up charges exactly the difference in total, and the masjid absorbs the
  Stripe fee on it. `fee_covered_minor` stays the record of what was actually charged.
- **The idempotency key on a top-up covers the SDK's retries of one call only.** It is set in the
  same transaction as the row, so a failed attempt rolls both back and a retry is a new top-up;
  the earlier claim that a retry reuses the session was wrong.
- **The customer is emailed when a paid top-up was not applied** ("We received your payment"),
  once per top-up (`notified_at`), only when the order has an address.
- **The order page reads `last_top_up_status`** (a status only) and `topup_open_until` instead of
  guessing from totals; the PATCH is also capped at 10 an hour per order uuid.
- **Correction:** staff-entered board orders that are paid through a staff payment link DO get
  the confirmation email (with the order link) when they hold an address, contrary to the build
  report. Kept as built: the customer paid online and the link is theirs. **Owner to confirm.**
- Deferred: the email greeting still carries the customer's name, and there is no per-address
  send cap (the pay-at-pickup email goes to any typed address, 12 an hour per IP per masjid, as
  FormSubmissionReceipt does). The unpaid-order preview on the page still sums stored prices
  while the server re-prices from the menu (pre-existing; the server's total comes back on save).

## 2026-09-25 — Iqama: one resolver for the website, the stored prayer rows and the dark-device push

Context: MEC (org 13) is moving to fixed Dhuhr 1:45 / Asr 5:30 / Isha 8:45 with Fajr +20 and Maghrib +5,
before 2026-10-31. The branch's first two commits keep the offsets on a Specific Time Ranges save. Review
found the server still had three copies of the iqama rule, and they disagreed.

- **`App\Support\IqamaResolver` is the server's only iqama rule** (tests/fixtures/iqama-resolution.json):
  on Specific Time Ranges, a range covering the prayer's day wins for that prayer; otherwise adhan + that
  prayer's offset, including after the last range ends (MEC on 2026-11-01). `IqamaTimeSettingResource`
  (website), `PrayersController::iqamaTimes` (the stored `prayers.iqama_times_data` the apps receive) and
  `prayers:send-due` (the dark-device push) all ask it. Before, the push and the stored column were
  adhan + offset only, so MEC's dark devices would have been told "the iqama time for Dhuhr has arrived"
  at adhan + 10 while its website said 1:45.
- **The day a range is tested against is the prayer's own day** in the masjid's calendar: the prayers row's
  `date` for the push and the stored column, today in the masjid's zone for the website. A late Isha
  after UTC midnight still belongs to its day. MasjidKit's `fixedIqamaTime(for:on:)` does the same.
- **A fixed time is its clock time on that day in the masjid's zone**, so 1:45 PM stays 1:45 PM on the
  wall across both daylight-saving changes (17:45 UTC in EDT, 18:45 UTC in EST).
- **The mode is asked before any range by the apps' stored rows and the push, NOT by the website payload.**
  The first cut of this branch also made `IqamaTimeSettingResource` send null `specific_time_ranges` for a
  masjid on Minutes After Adhan, because the website prints any non-null value there without reading `type`.
  Review rejected that: live organisations on Minutes After Adhan must see byte-identical times, and whether any
  of them still holds a covering range was never checked. The payload now asks
  `IqamaResolver::coveringTime()` (mode-agnostic, byte-identical to production), pinned by
  `the_website_still_shows_a_stored_range_for_a_masjid_on_minutes_after_adhan`. So a Minutes After Adhan
  masjid with a covering range still disagrees website vs apps, exactly as on production today. **Owner call,
  open:** run read-only on production `SELECT s.masjid_id, COUNT(*) FROM iqama_time_settings s JOIN
  iqama_time_ranges r ON r.iqama_time_setting_id = s.id WHERE s.iqama_type = 'minutes_after_adhan' AND
  r.end_date >= CURDATE() GROUP BY s.masjid_id;` If it is empty, switching the resource to `fixedTime()` changes
  nobody; if not, the owner decides per org. `ModuleFacts` now says this honestly for such a masjid
  ("stored ... but not in use: the apps and prayer reminders show minutes after adhan, while the website still
  shows a stored time"); the Assistant's `mode_explained` ("stored but NOT in use") is left as it was.
- **The mode is read from the raw column, not the enum cast**: a bad stored value would make the cast throw
  inside the every-minute push loop for every masjid; an unknown mode reads as offsets, as before.
- **Unchanged on purpose:** a masjid with no iqama row still gets no backstop push at all (adhan
  included) and still stores iqama == adhan; the push still never reads `iqama_times_data`.
- **The push's once-a-day guard is keyed on the prayer's own day** (`SendDuePrayerNotifications::guardKey`,
  the prayers row's `date`), not the UTC date of the instant. With fixed times the instant can jump backwards a
  day: MEC's last fixed Isha, 8:45 PM EDT Sat 10-31, is 00:45 UTC Nov 1, and Sun 11-01's adhan + 10 is about
  23:4x UTC the same UTC date, so the 26-hour guard swallowed Sunday's push (review lead, confirmed; pinned by
  `the_isha_iqama_the_day_after_a_fixed_range_ends_is_pushed_although_both_fall_on_one_utc_date`). For a masjid
  on Minutes After Adhan the only pushes that change are ones the old key wrongly suppressed (an instant drifting
  earlier across UTC midnight). A push sent by the old code shortly before the deploy, for a prayer whose row
  date differs from its UTC date (a New York Isha in EDT, a June Maghrib), could go out once more under the new
  key; this is closed by WHEN the backend is deployed (deploy step 1 in the follow-up section below), not by
  code. Checking the legacy key as well was rejected: it would keep the suppression bug alive for 26 hours
  around every such day and leave dead code behind.
- **A fixed time is only placed in the masjid's OWN zone** (`IqamaResolver::placesFixedTimes`). A blank, unknown
  or UTC-named `masjids.timezone` (the column's default for every masjid that predates it) keeps the push and the
  stored column at adhan + offset, which is what they did before this branch, and the push logs one warning per
  masjid per day at warning level (production's LOG_LEVEL). Placing 1:45 PM at 13:45 UTC would push hours away from
  the 1:45 PM the website prints. The website payload only uses the zone for "today" and is unchanged.
- **Other organisations on Specific Time Ranges change on purpose.** The comment in SaveIqamaSettingsRequest names
  Burlington and NAFIS Apex as on that mode. Their dark-device iqama pushes and stored `iqama_times_data` move from
  adhan + offset (0 for their fixed prayers) to the fixed times their website and apps already show; that is the
  fix, not a side effect, and restricting it to MEC was rejected because the task is one rule for every consumer.
  What was NOT checked (no production reads from a builder): that each such masjid has a real IANA `timezone`
  (if it does not, the zone guard above keeps its old behaviour) and which of their ranges cover the coming
  weeks. **Before deploy, owner-approved read-only:** `SELECT m.id, m.name, m.timezone, MAX(r.end_date) FROM
  masjids m JOIN iqama_time_settings s ON s.masjid_id = m.id LEFT JOIN iqama_time_ranges r ON
  r.iqama_time_setting_id = s.id WHERE s.iqama_type = 'specific_time_ranges' GROUP BY m.id, m.name, m.timezone;`
  and record the result here.
  **Result, 2026-09-25 21:4xZ (point session, read-only on masjid-backend-24-04):** (a) Minutes-After-Adhan orgs holding a
  range that ends on or after today: none, so the resource switch changes no live org. (b) Specific-Time-Ranges orgs:
  Burlington Masjid (1, America/New_York, last range ends 2026-09-30) and NAFIS Apex Mosque (5, America/New_York, 2026-12-31).
  Both have a real IANA timezone, so neither gets the "timezone is not its own" warning. Burlington's ranges run out on
  2026-09-30: from 10-01 it is on adhan + offset unless new ranges are entered (true before and after this branch).
- **`ModuleFacts` asks the mode** (`IqamaResolver::usesRanges`): "Fixed iqama times are set until ..." only on
  Specific Time Ranges, byte-identical there; on Minutes After Adhan it says the ranges are stored but not in use.
- **The MEC apply script's backstop prerequisite now probes the new code by behaviour**
  (mec-wix-migration/wave3/1.4-iqama/iqama-fixed-times.php; that folder is not a git repo, the edit is recorded
  here). The old probe grepped the command for `timeRanges|specific_time|IqamaResol`, which any bare mention
  would satisfy. It now requires `App\Support\IqamaResolver`, fed MEC's own offsets and the ranges the script
  writes (unsaved models), to put Dhuhr/Asr/Isha at MEC's fixed time on the first and last range day and back at
  adhan + offset the day after; the command (comments stripped) to call `IqamaResolver::for(` and `->iqamaAt(`;
  and `guardKey`'s fourth parameter to be `$day`. Its "Backstop vs MEC" table measures the deployed resolver (0 min
  every day) when the probe passes, and adhan + offset before. `inTimezone()`, the save() probe and the SPA
  caption probes are unchanged. Evidence: harness-backstop-probe.php and its output file next to the script
  (passes on this branch; refuses with a named reason when the guard key or zone placement is reverted).

Alternatives: resolving on the website only and leaving the push as a documented gap (the apply script
already allowed an owner's acceptance) was rejected because the push would contradict the site for every
dark device from the first day. Hiding a Minutes After Adhan masjid's stored ranges from the website (the
first cut) was withdrawn in review, above: correct in itself, but it changes a live payload nobody has
checked, and that is the owner's call.

### 2026-09-25 — Iqama review follow-up: deploy steps, which overlapping range wins, and the pins

**Deploy steps for this branch (whoever ships it; recorded here because nothing enforces them):**
1. **Run the backend deploy (bin/deploy) between 12:00 and 17:00 UTC** (8 AM to 1 PM EDT, 7 AM to noon EST).
   The push guard's key moves from the instant's UTC date to the prayers row's date, and the dedupe is only
   `Cache::has` on the new key. The two keys differ only for a prayer whose instant falls on a different UTC
   date from its local day, which for a masjid at UTC offset o happens only between 00:00 UTC and |o| hours
   after it (west of UTC) or in the last o hours before it (east). In 12:00-17:00 UTC that is impossible for
   every offset from UTC-12 to UTC+6, so no push the old code sent can be re-sent under the new key. The same
   window also keeps clear of the other deploy-moment double: an organisation on Specific Time Ranges whose old
   adhan + offset push and new fixed-time push fall on one evening under different keys (Isha after 8 PM EDT);
   a daytime Dhuhr/Asr pair shares one key, so at worst that prayer's push goes out once, at the old time, on
   deploy day. Rejected: checking the legacy key for the first 26 hours (needs a deploy-date constant nobody
   knows yet, then a second deploy to remove it, and the key formats are identical, so it is easy to get wrong).
2. **Backend first, verified, then the SPA bundle.** The new Iqama Times screen always sends the five offsets,
   0 included, on Specific Time Ranges too. The production request rule is `min:1` for any offset that is
   present, so the new bundle on the old backend would 422 every save for Burlington and NAFIS Apex (they store
   0). Verify the backend before the bundle: on the QA sandbox organisation (never a real one), save Specific
   Time Ranges with an offset of 0 through the API and see it stored. The frontend ships separately from
   bin/deploy (build from `git archive`, rsync without `--delete`, never build:prod).
3. **Rollback is the reverse:** the SPA bundle first, then the backend. Rolling the backend back under the
   new bundle recreates step 2's failure.

- **Two covering ranges for one prayer: the first by id wins, and the relation now says so.**
  `IqamaTimeSetting::timeRanges` is `->orderBy('id')`. The resolver, the website payload and every app take the
  first covering range in the list they are given, and the admin save does not refuse overlaps, so without an
  ORDER BY the winner was whatever order the database returned. Within one prayer that already was id order
  (MySQL's `(iqama_time_setting_id, salah)` index and SQLite both end in the primary key), so no resolved time
  changes. What can change is how the prayers interleave in `iqama.time_ranges` on
  `/api/mobile/masjids/{id}/prayers/settings` and the admin GET (salah order through the index, now save
  order); every consumer groups by prayer (the SPA's watch, the resolvers), so nothing reads it. The
  Assistant's list tool orders by prayer then date and now calls `reorder()` first. Pinned by
  `IqamaResolverAgreementTest::when_two_ranges_cover_a_day_the_first_saved_wins_everywhere` (the relation's
  ORDER BY, the resolver, the push and the website).
  **Not done here: a case in tests/fixtures/iqama-resolution.json.** That file is copied byte-for-byte into
  the iOS, tvOS and Android repos, which assert against their copy; a case added only here would diverge it.
  Adding the overlap case is a four-repo change for the owner to schedule. Refusing overlapping ranges in
  SaveIqamaSettingsRequest was also rejected: an organisation with an overlap already stored could not save
  its screen until it found and fixed it, and nobody has checked whether any has.
- **The Iqama Times screen's save body and date parsing live in `views/dashboard/iqamaSettingsForm.ts`**
  (`iqamaSavePayload`, `parseLocalDate`, `formatDate`, `SALAH_KEYS`) so node --test can pin them
  (resources/vue-app/tests/iqama-settings-form.test.ts, run in America/New_York). The view's load gate on the
  offset rows and its submit guard stay in the view and are pinned by a source probe in the same file, as
  the lunch tests do for their i18n file.
- **Tests added for the review's surviving mutants**, each shown to fail with its mutant applied on
  /root/manara-ci-b7 and pass without it: the stored `iqama_times_data` on a range's last day and the day
  after, and a June Isha after UTC midnight (the row's day, not the adhan's UTC date); a stored offset set to
  0 on Specific Time Ranges and a blank one kept (`filled`, not truthiness); a first save with no row and no
  offsets is 0/0/0/0/0; no zone warning for a masjid on Minutes After Adhan; the zone test over UTC, Etc/UTC,
  GMT, blank and an unknown name.

## 2026-09-25 — `video` section type (MEC's home-page clip): the upload rule is per type AND field
Decision: `SectionType::VIDEO` (`video`), content `video_url`, `poster_url`, `title`, `caption`,
`layout` (player | banner), `max_width` (full | container | narrow, player only), `background_color`,
exactly as mec-wix-migration `wave3/2.5-home-video/VIDEO-SECTION-PLAN.md` and its `home-video.php`
assert. Both `getImageFieldsForSectionType` copies map it to `['video_url', 'poster_url']`, so the
MP4 travels the image path into `section_images` (no conversions registered, so it is stored as
uploaded). The four section requests take their per-file rules from the new
`Concerns\ValidatesVideoSection::sectionUploadRules()`: the image rule for every file, except a
`video` section's `video_url`, which is `mimetypes:video/mp4|max:25600`. `validateVideoContent()`
refuses a `layout` or `max_width` outside the renderer's words. Not listed in `withoutRenderer()`:
the renderer (burlington-masjid-site `feat/video-section`, `Video.vue`) was built first and must ship
first.
Calls the plan left open:
- **The section type is resolved the embed rule's way** (`ValidatesEmbedContent::resolvedSectionType`,
  declared `abstract private` in the new trait so the dependency is stated): an update that omits
  `section_type` is judged by the stored type, so an MP4 can be added to an existing video section and
  still cannot be added to an existing image section by leaving the type out.
- **`mimetypes`, not `mimes`**: it reads the file's bytes (finfo), so a JPEG renamed `clip.mp4` is
  refused. `video/quicktime` (.mov) and WebM are refused on purpose: MP4 (H.264 + AAC) is the one
  format every browser plays, and the editor says so before upload.
- **The editor uses a bare file input**, against the editors' "always ImageDraggableInput" idiom: that
  component reads the file into a `data:` URL (24 MB of string for MEC's 18 MB clip) and accepts only
  images. `video_url` in the content is never set from the chosen file; the preview is an object URL
  held in the editor, and the server writes the stored URL. The client check
  (`core/helpers/sectionVideoFile.ts`) mirrors the server's 25 MB / MP4 rule so the admin is told
  before an upload is refused.
- **The image rule's duplicated `webp,webp` is written once**; the accepted set is unchanged.
- **The palette counts** in `SchoolSectionTypesTest`, `CommunitySectionTypesTest` (LATER_TYPES) and
  `OfferingSectionTypeTest::the_palette_gained_exactly_one_type` (27 → 28) are updated on purpose:
  each suite pins an exact count so a vanished type fails, and `video` is the one added since.
Unknown, needs investigation: what the iOS and Android apps do with an unknown `video` section. The
API passes `platforms` through without filtering (`PageSectionResource`); MEC's placement is
`["web"]`, but whether each app honours that before this goes on a page the apps load is not known.

## 2026-09-25 — `video` section review fixes: `media-src`, the upload NAME, and a tenant-scoped type lookup
Decision (three calls, each pinned by a test shown to fail without it):
- **`SecurityHeaders` gains `media-src 'self' blob:`**, plus APP_URL on a second host / proxied page
  (`$ownMedia`, set beside `$ownImg`). Without it `<video>` fell back to `default-src 'self'`, which
  never matches `blob:` and does not name APP_URL on the second host, so the video editor's preview
  (an object URL for a chosen file, APP_URL/storage for a stored one) was always refused. `data:` is
  left out: nothing here plays media from one. The directive only widens what `default-src` already
  allowed, so every other `<video>`/`<audio>` (group video playback is a signed RELATIVE URL) is
  unaffected. `SecurityHeadersPreviewFrameTest::BASELINE` gains the line on purpose; the new
  `SecurityHeadersMediaSrcTest` asserts it on the SPA shell itself (`withoutVite()`), on both hosts.
  Alternative: drop the preview (the admin then uploads 18 MB blind).
- **Every section upload rule pins the extension as well as the bytes**: `video_url` is
  `mimetypes:video/mp4|extensions:mp4`, every other file `mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp`.
  The media library keeps the client's file name on the public disk (DefaultFileNamer) and the web
  server picks the Content-Type from the extension, so real MP4 or JPEG bytes uploaded as `x.html`
  would be served as a page on this app's own origin. The image half predates the video type; it is
  fixed here because it is the same trait. `extensions` lower-cases, so `IMG_1.JPG` still passes; a
  `.jfif` or `.jpe` JPEG is now refused (it was accepted by bytes alone) and must be renamed.
  The SPA's `sectionVideoFileProblem` checks the `.mp4` name too, so its "a file refused here is
  refused by the server, never the reverse" promise still holds.
  Alternative: rename server-side (`usingFileName(uuid.ext)`): removes the class of bug without
  refusing anything, but changes every stored section file name and URL, and the review asked for a
  422 on `clip.html`.
- **`ValidatesEmbedContent::resolvedSectionType()` reads the stored type only from this tenant's
  sections** (`TenantContext`, else the route's `masjid_id`, as `embedMasjid()` does); another
  tenant's id resolves to null like a missing one. `Section` has no global scope and the lookup runs
  in validation, before the controller's `$masjid->sections()->findOrFail()`, so another org's video
  id used to let an MP4 through to the 404 while its image id answered 422: the response said what
  the other tenant's section is. The embed rule shares the function and gets the same fix.
Also pinned (tests only, mutation review P1-P9): the 25 MB ceiling is accepted at the limit and at
MEC's 18 MB clip; a `video_url` file on a non-video section is refused; the library update takes a
replacement MP4; `layout` / `max_width` are checked by all four writers, with `narrow` accepted and
non-strings (`true`, `0`) refused; unidentifiable bytes and a real text file named `.mp4` are refused.

## 2026-09-25 — Contact tags, and the staged Wix contact and form-message importers (MEC migration B1)
Owner decisions this builds on (mec-wix-migration/DECISIONS.md): "Build tags in Manara", "Everyone, most
blocked", "Stage, apply before move", "Import, marked answered", members deferred. Calls made where they were silent:

**Tags (Manara-wide).**
- **A tag is a label, never consent.** Nothing reads a tag to decide whether to email or text anyone; a
  `tag` broadcast audience only narrows, and the opt-out list and SMS consent record apply to every tagged
  person as to "everyone". Alternative (a tag as a mailing list with its own opt-in) rejected: it would be a
  second consent system beside `email_suppressions`.
- **Tag audience resolved at send time** (`audience_tag_id`, like `audience_service_id`), not snapshotted like
  a chosen contact list: a scheduled send reaches whoever carries the tag when it goes, which is what "send to
  Volunteers" means in every mailing tool. **Push + tag is refused** (the chosen-list reason), and a tag
  audience whose tag is gone addresses nobody; deleting a tag a scheduled broadcast addresses is refused (422).
- **"Same name" = `name_key`** (lower-cased, whitespace collapsed) with a unique index per organisation,
  because production collates utf8mb4_bin. Not `Str::slug`: it empties an Arabic name.
- **Links carry no masjid_id**, only `import_batch`: every write resolves the tag and the contacts through the
  tenant scope first. Bulk tag/untag is all-or-nothing: one foreign or deleted contact id 404s the request.
  Untag is a POST (`/contacts/remove`), like every other bulk write with a body. Cap 1000 ids.
- **Office data**: `contact_tag_links` is in `MemberAccountDeletion::OFFICE_RECORDS` (a tagged app member who
  deletes their account keeps the office's record); a merge moves the absorbed contact's tags to the survivor.
- Same two permissions as the directory (`view contacts` / `manage contacts`), inside `crm`; no permission minted.

**`wix:import-contacts` (App\Services\Imports\WixContactImport).**
- **Mailable = SUBSCRIBED and deliverability VALID, on every Wix record carrying the address** (the stricter
  record wins). Deliverability NOT_SET is not VALID, so it is suppressed. Everything else gets a suppression
  in advance with a new reason: `complaint`, `imported_opt_out` (UNSUBSCRIBED), `bounce`, `not_opted_in`
  (NOT_SET, PENDING, INACTIVE...). The two opt-outs are written whatever happens to the contact (matched,
  skipped, created); the two precautions only for a contact the import created — a person already on the
  Manara list got there through Manara, and a Wix "never subscribed" is no reason to silence them here.
  (SUPERSEDED by the review fixes below: the owner's text names every contact, so precautions apply to all.)
- **Existing contacts are never edited**, not even to fill a blank (departs from the migration plan's "blanks
  filled only"): the import adds only tags and suppressions to them, which keeps the undo exact and never
  writes somebody else's address onto a household record.
- **Matching**: by normalised email (oldest live non-placeholder contact); by phone ONLY for a Wix contact with
  no email and only when exactly one live contact has the number (households share phones, not emails); a
  contact the office deleted (soft-deleted, or since an earlier run) is not recreated, but its opt-outs apply.
- **Re-runs** look up `import_links` first. A contact the import created is updated from the fresh pull unless
  its values no longer match the SHA-256 fingerprint the import last wrote (then an admin edited it, and the
  edit is kept). A precaution is never released: an address Wix now calls SUBSCRIBED is COUNTED ("suppressed
  earlier, subscribed now") for the owner, because only the subscriber may release a suppression.
- **Undo (`--undo=<batch>`)** hard-deletes the contacts the run created (a soft delete would leave every
  imported person's details behind and collide with a corrected re-run), removes its tag assignments
  (including on matched contacts) and the tags it created that nothing else carries, and its links. It KEEPS
  every email and SMS suppression — rows are released only by the subscriber and never deleted — which is the
  one respect in which it does not remove "exactly what it created". (SUPERSEDED below: undo removes the
  precautions the run inserted.) It refuses in full, naming contact ids,
  once a created contact is the office's record by MemberAccountDeletion's lists (plus a login or a broadcast).
- Phone-only contacts have no address to suppress; no SMS consent is ever written; a Wix SMS UNSUBSCRIBED
  becomes an `sms_suppressions` row (reason `manual`). Site members come across as contacts only, with a notes
  line saying they had a login; no invitation. Other emails, other phones and postal addresses go into notes.
- Label names come from `--labels` (Wix label definitions); without it a tag is named from its key. An
  existing tag with the same key is reused; two labels differing only in case become one tag.
- Output is counts only; refusals name ids and tables. The importer is allow-listed in
  `EmailUnsubscribeTest`'s readers-of-the-suppression-list guard, with its reason.
- Estimated dry run against an EMPTY organisation, computed locally from the 2026-09-25 export (a Python
  re-statement of the rule, not the command): 3,951 records -> 3,905 people (46 merged), 1,562 mailable,
  1,287 not_opted_in, 606 bounce, 384 imported_opt_out, 17 complaint, 49 without email. Org 13's real numbers
  depend on its existing contacts and come from the command's dry run on the fresh pull.

**`wix:import-form-messages` (App\Services\Imports\WixFormMessageImport).**
- Designed for the CSV Wix's Forms & Submissions screen exports (header row, one column per field label, a
  submission date); the columns are unknown until MEC exports, so headers are matched by alias then keyword,
  `--map="Header=field"` overrides, unmatched columns are kept in the message text, and the dry run prints
  the mapping (header names only). Dates are read in the organisation's timezone (`--timezone`,
  `--date-format` for an ambiguous column); an unreadable row refuses the whole write.
- Each sender gets the same rows the website form creates (a `mobile_app_users` row with an `import-wix-…`
  device id and no push subscription, and a `contact_us_accounts` row), one per address. `created_at` is the
  submission date; `answered_at` is the import time with no staff name (it was handled on Wix). Reason is
  "{form} (old website)", `show_to_users` false. No notifier, no reply, no contact created.
- Idempotent on the export's submission id, else a SHA-256 of form, address, date, sender and text. Undo
  removes the run's messages and the senders left with none; refused once staff replied to one.

## 2026-09-25 — Wix importer review fixes (MEC migration B1, 24 confirmed findings)
- **Precautions apply to every contact, matched ones included.** The owner's binding rule ("Everyone, most
  blocked": every contact not SUBSCRIBED or not deliverable, bounced named) was not silent, so the earlier
  carve-out for matched contacts is gone. Safe because of the next two bullets: the badge says "not opted in
  (imported)" rather than "unsubscribed", and staff can lift that one reason. The dry run shows the impact on
  its own row ("of which precautions on contacts already in Manara"), so the owner sees it before apply.
- **Staff may lift `not_opted_in`, and only that reason** (`POST /contacts/{id}/email-consent`, `manage
  contacts`, evidence REQUIRED, written onto the row as `release_source = staff_recorded_consent`,
  `release_evidence`, `released_by_user_id`; additive migration). Reason: the person an import silenced never
  receives a broadcast, so the subscriber's own link can never reach them. `bounce` stays subscriber-only: a
  relay bounce and a Wix bounce share the reason and cannot be told apart. The form-123 sign-up path (plan
  item 3.1) is not built yet; when it is, a consenting submission is the second caller of
  `EmailSuppressionService::liftPrecaution` with its own `release_source`. Alternative (let staff lift any
  import-written row) rejected: an imported UNSUBSCRIBED is the person's request.
- **Undo removes the precautions the run INSERTED.** Each suppression row a run inserts is linked in
  `import_links` (kinds `email_suppression`, `sms_suppression`; external_id is the row id, so no address is
  copied). Undo deletes (not releases: a released row would read as somebody's decision) the linked rows still
  in force with reason `not_opted_in`/`bounce`. Opt-outs copied from Wix are kept, the ONE documented
  exception, with their links kept too, so `--undo=<batch> --remove-opt-outs` (now or later) removes them for
  a run written into the wrong organisation. Rows that existed before the run, or were released since, are
  never touched. SMS opt-outs removed that way clear the mirrored date but never restore `sms_opt_in`.
- **A released row is the person's newer decision.** plan/apply skip any address or number with a row in any
  state; released ones are counted ("Released in Manara ... Wix status not applied"). Before, suppress()
  re-suppressed a released row, overriding a re-subscribe with stale Wix data.
- **"Stay mailable" excludes addresses Manara already suppresses**; those are counted on their own row, which
  replaces the old "suppressed earlier, subscribed now" row for every action, not only re-runs.
- **Undo refuses once the office has written to an imported contact**: the fingerprint now covers notes as well
  as name/email/phone, and every `MemberAccountDeletion::OFFICE_COLUMNS` value the import does not write
  (SMS consent, family login, avatars...) holds the contact, except the SMS opt-out date the run's own SMS
  suppression mirrored. A tag assignment made by ANY import run is not office data (was: only this run's).
- **Undo across re-runs.** Undoing a run also deletes every link any later run holds to the contacts it
  erases (a later duplicate would otherwise be skipped forever as "deleted in Manara"). A run that UPDATED a
  contact an earlier run created records it (kind `contact_update`, previous fingerprint only) and its undo
  refuses, because the replaced values were not kept; undoing the creating run first removes the contact and
  unblocks it. Alternative (store the previous values to restore them) rejected: it copies personal values
  into `import_links` for a case the staged, apply-once plan makes rare.
- **Batch names are single-use per organisation**, across `import_links`, `contacts.import_batch` (Wix and
  roster importers) and `contact_tag_links.import_batch`; both Wix commands refuse a reused `--batch` before
  writing.
- **Form-import keys are HMACs under APP_KEY**, and imported device ids are random: a plain SHA-256 of an
  address is reversible from any address list, and a device id derived from it let anybody who knew the rule
  reach the imported sender through the public contact-us and device endpoints. Rotating APP_KEY between two
  runs makes the second see new senders (apply-once, recorded not engineered around). `import_links` rows are
  dropped on staging.
- **SPA**: the tag/untag/list URLs and the composer's payload and push guard moved into pure modules
  (`contactTags.ts`, `broadcastPayload.ts`, `emailOptOut.ts`) so `npm run test:spa` pins them.

## 2026-09-25 — Wix order history: imported as HISTORY, never as money Manara processed

Owner (MEC migration, round 2, binding): past orders → **"Import everything"** — all 685 Wix
store orders (2017-08 → 2026-05, $39,739) and 149 Wix Events orders (2024, $3,923.24 paid) into
Manara's donation and registration history, "marked as historical/offline (paid via
Square/PayPal/Wix, NOT Manara/Stripe) so nothing looks like a payment Manara took, and no
receipts or emails are sent on import". Built as `crm:import-wix-orders`
(`App\Services\Crm\WixOrderHistoryImporter`, reader `App\Support\WixOrderExport`).

- **A third source, `historical`, on both ledgers** (`Donation::SOURCE_HISTORICAL`,
  `Registration::SOURCE_HISTORICAL`), plus `historical_order_id` pointing at a new
  `historical_orders` row per imported order (provider, Wix order number, lines, totals).
  Alternatives: (a) reuse `source = offline` like `crm:import-ledger` — rejected, because
  offline gifts are editable, receiptable and counted in every total, which is exactly what the
  owner ruled out; (b) keep Wix orders only in a separate table — rejected, the owner asked for
  them in donations and registrations history.
- **Mapping.** Giving products (Zakat-ul-Fitr, iftar tiers and sponsorships, 10 Meals in
  Ramadan, Qurbani, shelter/fence, student sponsorships, bread for Syria) → one donation per line
  into a fund matched by name, else created **inactive and non-receiptable** (Zakat-ul-Fitr is
  type `fitra`; every other created fund is `general` — naming iftar or Qurbani `sadaqah` would be
  a ruling). Ticket products (Eid/Fall festivals per year, Kid's Hajj Simulation, the Hajj/Eid
  bazaar, the Career DNA workshop, the 2018 summer sessions) and every Wix Events order → one
  registration per order on an **unpublished** offering (`is_active` false) sharing one inactive
  intake form and one inactive fee plan; paid = `confirmed`/`paid` with one settled ledger row
  (the Wix fee added at checkout is in it, and on the order's `fee_minor`); Wix's abandoned
  checkouts (41 canceled, 2 declined) = `cancelled`/`canceled`, no ledger row. **Festival food
  tickets and the 2021 prayer rugs** fit neither ledger: a purchase is not a gift and a food
  ticket is not a seat, so they live on `historical_orders.lines` only (`order_only`). An
  unrecognised product name blocks the whole import rather than being guessed. Coupons
  (Intellicor, May 2026) are `code` adjustments.
- **Processor.** Wix Events names PayPal per order; a card there does not say whose terminal, so
  it is `wix` + method `card`. The Stores projection has NO per-order payment column (Wix reported
  Square 410 / PayPal 275 only in aggregate), so every store order is `wix` ("paid at the Wix
  checkout, Square or PayPal") unless the pull that feeds the real run adds a `paymentProvider`
  column, which the reader then uses per order. Nothing guesses a processor.
- **Excluded from everything that reports money received or what Manara did**, by one scope
  (`Donation::withoutHistorical()`) or the registration source: the giving dashboard
  (DonationMetrics — history only when `source=historical` is chosen, so header and rows agree),
  the ledger and its CSV (same default), receipts (ReceiptService declines; issue/edit answer a
  422 naming the Wix history), annual statements, impact figures (donations, confirmed
  registrations, program fees), the Giving module's "gifts recorded" fact, and the contact's
  `giving_total` (history summed apart as `historical_giving_total`, shown "plus $X on the old Wix
  site"). A historical registration cannot be cancelled (`RegistrationException::historicalRecord`).
  Nothing is sent: rows are written with Eloquent in one transaction; no mailer, notifier, queue,
  Stripe client or renderer purge is reached (pinned with Mail/Notification/Queue fakes).
- **Contacts.** Linked by email case-insensitively (the oldest if several share it). A buyer with
  no contact gets one, and the address gets an `order_history_import` suppression — a HOLD, not an
  opt-out — unless the organisation already has a suppression row for it (a released row is the
  person's own request to be mailed and is left alone). **Order of the two imports: contacts
  FIRST.** Then nearly every buyer is an existing contact carrying their Wix consent and is linked;
  a hold is written only for a buyer the contact import did not bring over. The contact import
  (`WixContactImport`, branch feat/mec-contacts-import) never releases a suppression, so if the
  order import ran first a buyer SUBSCRIBED on Wix would stay held (it errs towards not mailing;
  the dry run warns whenever it would create contacts). **Known wording limit:** the directory
  badge reads "Emails: unsubscribed <date>" for a hold.
- **No buyer detail beyond the contact link is stored**: no address, phone, buyer note or Wix
  Events checkout answer (the 2024 zoo trip asked for emergency contacts). Notes name products,
  amounts, the Wix order number and the processor only. The command prints counts and money only.
- **Idempotent** on `(masjid_id, source, order_number)`; **undo** (`--undo=<batch>`, dry unless
  `--execute`) removes the batch's orders, donations and registrations, then the scaffolding it
  recorded in `historical_import_records` — each piece only if nothing outside the batch uses it
  and its `updated_at` has not moved since the import. A contact the Wix contact import has filled
  in since is KEPT (and its hold with it), unlike `schools:import-roster --rollback`, which refuses
  the whole batch: here the order history is what is being undone.
- **Timestamps are the order's**: `created_at` of donations and registrations and `paid_at` of the
  ledger row are the Wix order instant, `donated_at` the organisation-local date — so every
  created_at window ("gifts in the last 12 months", impact periods) sees the order where it
  happened, not on import day.
- Expected run on the 2026-09-25 export (computed from the raw files, aggregates only): 339
  donations $27,464.00 (Zakat-ul-Fitr 273 / $13,381, Iftar 50 / $11,568, Qurbani 4 / $900, General
  12 / $1,615); 491 registrations (448 paid, 43 cancelled) $12,918.24 across 15 historical
  offerings; 36 order-only lines $3,280.00; total $43,662.24 = paid orders, reconciles; 575
  distinct buyer emails. Not yet dry-run through PHP against the real export: PHP runs only on the
  droplet and the raw export may not leave the Mac. Apply together with the contact import, before
  the domain move, from the fresh read-only pull.
- **Where the order points at a contact.** A contact merge carries `historical_orders.contact_id`
  to the survivor with the donations (otherwise the force-delete nulls it while its gifts move on);
  MemberAccountDeletion classes the column as an OFFICE record, like `donations`, so an app member
  deleting their account keeps the contact the order history is filed under. The importer is on
  EmailUnsubscribeTest's allow-list: it writes and reads holds, and gates no send.

## 2026-09-25 — Wix order history review fixes (contract, safety and mutation lenses on 536e1843)

Sixteen confirmed findings, each with a test that fails without its change. The calls that were
not mechanical:

- **Contacts first is ENFORCED, by the order import, not by the contact import.** A plan that
  would create held contacts blocks (dry run too, exit 1) until the Wix contact import has linked
  a contact in the organisation, read from its own `import_links` rows (source `wix`, kind
  `contact`, branch feat/mec-contacts-import); `--without-contact-import` accepts the holds.
  Alternative: have `wix:import-contacts` lift an `order_history_import` hold when Wix says
  SUBSCRIBED — rejected here because that code lives on the other branch, and it would be the
  first place that import releases a suppression, a rule worth its own review. With the block,
  no hold can be written ahead of the consent it would pre-empt. Until that branch is merged the
  table does not exist, the answer is "has not run", and the test creates a stand-in with the
  migration's columns (`markWixContactImportRan`), which is skipped once the real one exists.
- **Imported orders are readable on the contact record** (`historical_orders` on
  `GET /contacts/{id}`, shown as "Orders on the old Wix site": Wix order number, date, processor
  or why nothing was paid, each line with where it went, total). That is where a line kept on
  the order alone is seen. Not built: a giving-dashboard view of order-only lines; they are
  purchases, not gifts, and the import prints their count and total.
- **Merge carries imported ticket registrations** (`source = historical`) with the orders and
  gifts. A LIVE registration's payer is still not moved and still nulls on the merge's
  force-delete: pre-existing, touches the registration money paths, left for its own change.
- **Wix Events items are classified like store products**: the bracelets and zoo tickets are
  seats, "Food Purchase" (MEC Community Connect, the Events twin of the store's food tickets) is
  order-only, and an unknown item blocks. An Events order with no seat counts the fee Wix added
  at checkout with the order-only money, so it still reconciles.
- **A store order is paid only when the export says so**: `stores/orders.json` must carry
  `paymentStatus` and `refundedUSD`; only `PAID` with 0 refunded is read as paid, and anything
  else is a problem naming the order, not a guessed "canceled" (a refund or an unpaid order is a
  decision about what to record, not a mapping). The 2026-09-25 pull has neither column (its
  free-text note says every order was PAID), so the real dry run blocks until the pull that feeds
  the apply run adds them (ASSUMPTIONS #16).
- **A deleted contact still counts.** Linking reads trashed contacts too: a live one wins, an
  address only a deleted contact holds is linked to it and left deleted (neither restored nor
  re-created; counted as "linked to a contact deleted in Manara"). Undo keeps an import hold while
  any contact, deleted or not, still holds the address, because a restore does not recompute the
  opt-out mirror.
- **SPA wording moved into `core/helpers/donationMethod.ts`** (`receiptNote`,
  `historicalGivingNote`, the Wix order labels) so the choice of sentence is under
  `npm run test:spa`; the templates only render it.

Expected run on the 2026-09-25 export, recomputed from the raw files (aggregates only), once the
pull adds the two columns: donations unchanged (339, $27,464.00); registrations 484 (443 paid, 41
cancelled) $12,856.68, one fewer historical offering (MEC Community Connect); order-only 41 lines
$3,341.56 ($3,340.00 of lines and $1.56 of Wix fees on the five paid food purchases); total still
$43,662.24.

## 2026-09-25 — Broadcast newsletter layout (MEC migration, "Build rich layout first")
Decision: a broadcast's EMAIL can carry an ordered list of blocks — heading, rich text, picture
(alt text required, optional link), button, divider, two pictures side by side, space — stored as
ONE nullable JSON column `broadcasts.blocks`, rendered by `App\Services\Broadcast\Newsletter\*`
into `emails.broadcast-newsletter` plus a text/plain part. Calls made where the brief was silent:
- **One JSON column, not a child table.** A broadcast is never edited after composing, so blocks
  are only ever read and written whole and in order; a table would add ordering columns, a second
  write in the compose transaction and a guessable id per block for no query anyone runs.
- **The legacy email is a separate, untouched template.** A broadcast without blocks renders
  `emails.broadcast` exactly as before and gains NO text part — pinned byte for byte against the
  blade frozen at e4c7fc48 (`BroadcastLegacyEmailUnchangedTest`, committed before any change).
  Adding the text part to legacy mail too would be a deliverability win but changes what every
  existing sender sends; it is a one-line change in `BroadcastMail::content()` if the owner wants it.
- **Title and body stay required.** They are what the feed, push, the board and SMS carry; in the
  newsletter email the body is the opening paragraph under the greeting, then the blocks, then the
  existing "More details" link, then the unchanged unsubscribe footer. The composer image, when one
  is attached, stays the email's top picture (alt = the title).
- **Blocks without the email channel are refused (422)**, not stored and ignored.
- **Pictures are uploaded with the send, never addressed by URL.** `block_images[<key>]` files become
  media rows in a NEW collection `broadcast_blocks` (so one never becomes the feed or push picture,
  which read `MEDIA_COLLECTION` first) tagged `block_key`; the email's address comes from the public
  disk's configured URL, pinned to `SiteUrl` if the disk yields a path. No admin-typed image URL
  exists, so no newsletter can hotlink a tracker. SVG is refused (it can carry script). Limits:
  10 pictures × 8 MB per upload (inside production's 100M). The stored and emailed copy is
  RE-ENCODED (review fix, below), not the upload.
- **Rich text is parsed and re-written, never cleaned up.** `RichText` keeps only p/lists and
  strong/em/u/a (http, https, mailto); everything else is unwrapped or dropped with its content
  (`<img>` included). It runs on store AND on render, so a hand-edited row is held to the same rules.
  The editor pastes as plain text. Button and picture links are http(s) only, through
  `NewsletterBlocks::webUrl()`: Laravel's `url` rule accepts ~300 schemes (`data:`, `file:`, `blob:`,
  `view-source:`, `chrome:`, `ms-settings:` among them). An earlier version of this entry said it
  accepts `javascript:`; it does not — `javascript` is not in `Str::isUrl`'s list — but the others
  are no better behind a newsletter link.
- **The live preview is the server's own render.** `POST /broadcasts/preview` builds the real
  `BroadcastMail` (stores nothing, sends nothing); unuploaded pictures are addressed at the reserved
  `https://preview.invalid/...` and the SPA swaps in its local copy (image data URLs only). With no
  blocks it returns the legacy email, because that is what the send would produce. Shown in an iframe
  with an empty `sandbox`.
- **Email-client safety:** tables only, inline styles on every element, a declared background on
  every cell (inverting dark modes), `color-scheme` meta + a `prefers-color-scheme`/Outlook.com
  `[data-ogsc]` palette as an enhancement, MSO ghost table for Outlook's width, two-picture rows stack
  under 620px. The accent is #1f7a41, darker than the legacy #2f9e57, because white text on #2f9e57 is
  ~3.3:1 and fails WCAG AA; the legacy email keeps its colour.
- **Reorder is up/down buttons**, reachable by keyboard and screen reader, not drag alone.
- **A refused send (422) keeps the composer open.** Before, the composer left for the list after
  ANY outcome, which with a newsletter would throw away the whole layout over one missing alt text.
  A 422 stored nothing, so staying is safe; every other failure still leaves as before, because
  after a 500 part of the send may already have gone and a second press would send it twice.
- Not built: RTL/Arabic newsletter direction (`lang="en"`, left-aligned), saved templates or
  "duplicate last newsletter", campaign landing pages (Wix `/so/...`), per-link click tracking.
- **Review fixes (same day).**
  - *The preview is lenient.* It renders the blocks that are complete and returns 200 with the send's
    own `errors` and a size `warning`; a 422 froze it, because every new block starts empty. The send
    still refuses on any error.
  - *Pictures are re-encoded before storage* (`NewsletterPicture`): EXIF/GPS stripped (orientation
    applied first), at most 1104px wide (2 × the 552px column) at quality 80, stored under a generated
    UUID name so a personal file name never reaches a public URL. Not a Spatie conversion: that is
    written beside the original under a derived name, leaving the untouched original one URL edit
    away. Animated GIFs are kept as uploaded (GD keeps one frame; GIF has no EXIF). Pictures over
    36 megapixels are refused at the request, because GD holds every pixel and production PHP-FPM
    has 128M (the decode raises the limit for its own duration, sized from the header).
  - *Gmail clipping.* The rendered email must be ≤ 100,000 bytes of HTML (refused at send, warned in
    the preview above 80,000): Gmail clips at ~102 KB (observed behaviour, not a published limit) and
    the unsubscribe footer is the last row.
  - *"More details" link.* The newsletter prints it only through `webUrl()`, and a send with blocks
    refuses a non-web link. The legacy email and its rule are unchanged.
  - *The admin's preview copy of each picture is scaled in the browser* (≤ 1104px JPEG) so the preview
    frame does not carry megabytes of base64 on every refresh; the upload is still the original.
  - *Rollback.* `broadcasts.blocks` is additive and the old code ignores it, so a rollback is a code
    rollback only. The migration's `down()` now REFUSES while any row has blocks: dropping it loses
    every sent layout, and a newsletter scheduled under the new code would be delivered by the old code
    as the plain email with its blocks missing. Before rolling back, find those with
    `SELECT id FROM broadcasts WHERE blocks IS NOT NULL AND status IN ('scheduled','pending')` and hold
    or cancel them (`deploy/README.md`, "Rolling back the newsletter layout").
Rationale: MEC sends weekly multi-block Wix campaigns (reports/cms.md §4) and the owner chose to
build the layout before the domain move; the constraints above keep every existing sender's email
unchanged and keep admin input from becoming markup in 2,800 inboxes.

## 2026-09-25 — Accepted payment methods (Manara-wide)

Owner's settled answers (MEC migration, 2026-09-21): "build a Manara-wide 'accepted payment methods
+ how to pay' setting now", offline payments are "Mark as Paid with the indication how they paid";
Halal Kitchen: "Build ordering in Manara" — "Pickup at MEC, 48h, office confirms".

**Payment methods.**
- One row per ACCEPTED method (`organisation_payment_methods`, `BelongsToMasjid`), no enabled flag:
  a switched-off method with instructions still attached is the text that gets shown by mistake.
  Vocabulary `App\Support\PaymentMethods`: card, cash, check, zelle, bank_transfer, other. Plain
  strings, never an enum. The admin screen replaces the whole set in one PUT (JSON; `methods` must
  be present, so a lost field cannot clear the set), in order; "other" must be named.
- `card` means the organisation's OWN Stripe Connect account through Manara, and is published only
  while `canAcceptDonations()` holds (`AcceptedPaymentMethods::publicList`, the one reader). The
  admin payload says `card_ready` so a saved-but-unpublished card is visible as such.
- Public read: `GET /api/v1/payment-methods` (own limiter, `payment-methods`), and inside the
  kitchen catalogue payload.
- "Mark as paid" vocabularies were extended, not replaced: `MealOrder::PAID_VIA` gains check,
  bank_transfer, other; `FormResponse::PAID_VIA` / `PAID_VIA_EXTERNAL` gain bank_transfer, other.
  A test pins that every `PaymentMethods::OFFLINE` key is recordable in both modules. Mark paid
  does NOT refuse a method the organisation does not advertise: staff record what happened.
  Alternatives: one shared `paid_via` vocabulary replacing both (rewrites stored meanings and the
  forms cash totals); reading the organisation's list at Mark paid (refuses reality). Donations'
  offline entry (`payment_method`) and registrations (no Mark paid by design) are untouched.
- Pinned lists updated on purpose: `MealOrderMarkPaidTest` (vocabulary + refusal sentence),
  `FormOfficePaymentTest` (refusal prefix + `meta.payment.paid_via`).

## 2026-09-25 — Halal Kitchen ordering for MEC (a catalogue mode of the lunch module)

**Kitchen = a catalogue mode of the lunch module, not a new module.**
- `meal_menus.kind` (`dated` default | `catalogue`), `pickup_lead_hours`, `notify_emails`;
  `service_date` made nullable (a catalogue has none; the unique (masjid, service_date) index still
  means one menu per Friday because NULLs never collide). Reuses MealOrder, pricing
  (`LunchOrderLines`, CAP_REFUSE: catering trays are refused over a cap, never trimmed), the Stripe
  checkout, the webhook and Mark paid. Alternatives: separate kitchen tables (a second order/Stripe
  path to keep correct); catalogue as a dated menu with a sentinel date (would be served as Friday).
- Every "this Friday" reader is fenced: `menu()` filters `dated()`, the lunch `store()` refuses a
  catalogue uuid, the lunch PATCH refuses a kitchen order (`EDIT_KITCHEN`, code `kitchen`), the SMS
  opening announcement skips catalogues, `LunchOrderMailer::confirmation` hands a kitchen order to
  `KitchenOrderNotifier`. Kind is fixed at creation (absent from the update rules).
- Rides the `jummah_lunch` capability. Alternative: a new capability (catalogue, org switches,
  app menu and cutover plans all pin the set). MEC is switching Friday lunch on anyway (plan 6.4).
- Public door `Api\V1\KitchenOrdersController`: `GET kitchen-menus/{uuid}`, `POST kitchen-orders`,
  `GET kitchen-orders/{uuid}`, `POST kitchen-orders/{uuid}/checkout`, on the lunch limiters.
  Lead time and booking window (90 days) are the SERVER's clock, read in the organisation's
  timezone; the payload carries the window as wall-clock strings for the picker. The methods are
  the organisation's accepted ones narrowed by the menu's two switches; an organisation listing
  none takes no kitchen order (no invented default). Card needs a CORS-trusted origin (the return
  must reach the renderer's kitchen page, never the admin app's Friday page) and is refused before
  anything is written otherwise.
- Office confirmation: a kitchen order stays `pending` until staff move it to confirmed / ready /
  picked up; `MealOrder::markPaid` no longer auto-confirms a kitchen order (a Friday order still
  is). `confirmed_at` + `confirmed_by_user_id` are written the first time only, on the locked row.
  The customer is emailed once (`customer_confirmed_sent_at`), not when it was only recorded at
  pickup. Card orders are paid at placement, before confirmation; if the office declines, it
  cancels and refunds in Stripe by hand (the owner's standing "no automatic refunds").
- Office notification: `KitchenOrderNotifier::placed` emails the organisation's own address and
  `notify_emails` beside it (review fix below; first written as "else") when the order becomes real — at placement for offline, on the
  webhook's payment for card, so an abandoned card page notifies nobody — claimed once on
  `office_notified_at`. The customer gets "we received it; the office will confirm". Links go to
  the renderer page on the recorded trusted origin, else no button (there is no admin-app page for
  a kitchen order). `notify_emails` is nulled on staging (`config/staging_scrub.php`).
- Stripe's return for a kitchen order is the kitchen page on every path, not only the public
  door's: `MealOrderCheckoutService::openPage` defaults a kitchen order's success/cancel URLs to
  `KitchenOrderLink` (the remembered trusted origin), so a replacement for an expired page and the
  board's "Payment link" do not send a card payer to the admin app's Friday-lunch page. An order
  with no trusted origin (taken by phone) keeps the old default: there is no kitchen page to send
  them to. Alternative: pass return URLs from each caller (the expired-page replacement inside
  `checkout()` has no request to read an origin from).
- Staff can take a kitchen order by phone on the board (pickup required, not held to the public
  lead time); the board shows `pickup_at_local` on the organisation's clock and a Confirm button.
- Seed: `php artisan kitchen:seed-catalogue {masjid} [--apply --expect-name=]` from
  `database/data/mec-halal-kitchen.json` (32 dishes, MEC's words and prices verbatim, the Wix
  duplicate "Kunafeh" $65 dropped per plan 6.3). Dry run by default; --apply needs the exact name;
  never overwrites (a same-titled menu refuses); creates a DRAFT with no pickup line (MEC's own
  words go there). Not a migration: one organisation's content, run once, by an operator.
- Deferred on purpose: Arabic "how to pay" text; the optional extra and fee coverage on kitchen
  orders; customer self-edit of a kitchen order; a kitchen-specific capability.

## 2026-09-25 — Kitchen and payment-methods review fixes (19 confirmed findings)

**Paying late is placing late.** The office first hears of a card order when the webhook records
its payment, so the 48-hour notice is held at payment as well as at placement.
- `POST kitchen-orders/{uuid}/checkout` refuses once the menu stops taking orders, and — on the
  locked row, in `MealOrderCheckoutService::checkout` with `kitchen_lead_time` — once
  `pickup_at − lead time` is under `KITCHEN_PAGE_MIN_MINUTES` (31) away. Every kitchen page (the
  first, a replacement for an expired one, the board's) carries `expires_at` = min(24h − 1 min,
  deadline): `kitchenPageExpiresAt`. The 31 is Stripe's 30-minute floor plus the top-up's minute
  of travel time; a page is refused rather than allowed to outlive the deadline.
- The public door's deadline is pickup − lead time; the BOARD's (Payment link, a staff card order)
  is the pickup itself — the phone-order rule already recorded: the office is not held to the
  public notice. Alternative: one deadline everywhere (the office could not send a link for an
  order it agreed to make tomorrow).
- A card order whose pickup leaves no time to pay is refused BEFORE it is written, naming the
  earliest pickup card can take (lead time + 31 minutes, rounded up to a whole minute). An order
  paid to the office is still fine at exactly the lead time. `can_pay_online` now says what the
  endpoint would do (open menu, time left), so the order page offers no button sure to be refused.

**Phone orders paid to the office** (`MealOrdersController::store`, catalogue only). Staff choose
`payment_method` from the organisation's accepted methods (`AcceptedPaymentMethods::rows`, served
to the board as `payment_methods` via `staffList`, card marked `ready`). Not narrowed by the menu's
`allow_pay_at_pickup` (it governs the website); card still needs the menu's online switch and a
ready Stripe account, because the board's Payment link is refused without them and a card order
with no way to re-make its page is worse than a refusal. An offline choice is saved unpaid with
`preferred_payment`, opens no page, and calls `KitchenOrderNotifier::placed` exactly as the public
door does for an offline order (the office list and the customer hear the same messages whichever
door the order came through). Extras: the fee is only covered on card; the optional extra follows
the menu as before. A Friday order taken on the board is unchanged: always card.

**An unpaid card kitchen order is not work for the office.** `updateStatus` refuses confirmed /
ready / picked-up on the locked row for a kitchen order that is online, unpaid and never confirmed
(`CONFIRM_UNPAID_CARD`: "use Mark paid first"), and `KitchenOrderNotifier::confirmed` never emails
such an order whoever calls it. The board shows "Card not paid yet" and no Confirm button
(`kitchenBoard.ts cardNotPaid`). Known edge, accepted: a never-confirmed unpaid card order that
the office CANCELLED cannot be restored (restore is "confirmed", which this refuses, and Mark paid
and Payment link refuse a cancelled order); the office takes a new order instead. When Stripe page
creation fails at placement the order comes back with the 422, and the renderer sends the customer
to that order (`classifyKitchenPlace` → `savedUnpaid`, `?cancelled=1`) instead of letting them
place a second. That held only for a refusal (a `RuntimeException`) until the gate pass: Stripe's
own `ApiErrorException` extends `\Exception`, so a Stripe outage fell to the outer catch and came
back as a bare 500 with no order. `store()` now answers any failure to open the page the same way,
with a fixed public sentence (`PAGE_NOT_OPENED`) and the error recorded (`Errors::publicMessage`).
The Friday door (`JummahLunchOrdersController::store`) has the same shape and is left for its own
change.

**Who hears about an order.** `officeRecipients` always includes the organisation's own address,
first and as a visible To, beside at most five typed addresses (was: the typed list INSTEAD of
it). `notify_emails` is administrator-only: `MealMenusController::withoutAdminOnly` drops it for a
LunchStaff login on create and update (dropped, not refused, so the shared form still saves the
rest), and the board hides the field from volunteers. Alternative: an admin-only endpoint for one
field (a second save path for the same menu form).

**The organisation's words for a method.** `AcceptedPaymentMethods::labelFor` names a customer's
chosen method in the office email and the staff confirmation, and the board payload carries
`preferred_payment_label`; the vocabulary's word is the fallback only when the organisation has no
row for it (a method it stopped accepting keeps the word it was placed under).

**Offline gifts record bank transfer.** `Donation::OFFLINE_PAYMENT_METHODS` is the one allow-list
for both offline-gift requests (appended `bank_transfer`; the receipt says "Bank transfer"), and
the pinning test now covers donations beside meals and forms; `DonationEditLegacyMethodTest` records and corrects a bank-transfer gift through the endpoints,
so the requests themselves are pinned, not only the constant.

**Registrations, a deviation from the plan's 6.2 verification, flagged to the owner.** The plan
says "Mark as Paid on a form, a registration and a lunch order all record the method". The
`registrations` module still has no Mark paid: `.claude/rules/registration-billing-data.md` and
`StoreRegistrationRequest` keep every money field out of it on purpose (a registration is a Stripe
Checkout Session; `meal_orders.paid_via` "is NOT the precedent to copy here"). MEC's own festival
tickets (6.1) are a FORM with staff codes, whose entries do record how they paid
(`FormResponse::PAID_VIA`, now including bank transfer and other), so MEC's case is covered. The
registrations module proper is left as it is and recorded as an open owner question (ASSUMPTIONS
17), not silently superseded.

**The seed has an undo.** `kitchen:seed-catalogue {masjid} --undo --menu=<id>` (dry run unless
`--apply`, which needs `--expect-name`) deletes that one catalogue and its dishes — only a
catalogue of that organisation carrying the file's title, never one with an order. `--apply`
prints the exact undo line. The "already seeded" check includes soft-deleted menus and says how to
clear one, so deleting the draft on the board and re-running cannot make a second copy.

Tests added for the mutants that survived the review (M01–M04, M07, M09–M12, M14, M18; R01, R03,
R05; P1, P2), each shown to fail with its mutant applied. The renderer's wall-clock test now sets
`TZ=America/New_York` itself, so it pins `timeZone: 'UTC'` on a UTC CI runner too; the kitchen
page's default method and the order page's polling moved into `kitchenDefaultMethod` and
`kitchenPollDelay` so they can be pinned.

## 2026-09-25 — Ramadan giving through forms: a price per quantity, prices by answer, and reserved dates
Owner's call for MEC ("Form with payment"): Zakat-ul-Fitr per person x N and iftar sponsorship
levels, some of which reserve one evening, as Manara forms paid through the organisation's own
Stripe Connect account. The calls the brief left open:

- **A quantity is a number question the fee names** (`settings.fee.perQuantityOf`), not a new
  field type. The unit is the flat amount (or the date step in force), the quantity is the whole
  number answered, and the server multiplies them (`Form::priceFor()`); the client never sends a
  total, and one sent is ignored. The question must be required (unless a level asks it), flat
  (not in a repeatable section), and bounded 1..`Form::MAX_QUANTITY` (1000, the same ceiling count
  prices already put on a family size); `FormSchema` adds `integer|min:1|max:` so 2.5 people is
  refused. Beside `perEntryOfSection` or count prices the rule is unreadable and prices nothing
  (refused, never under-charged). Alternative: a `quantity` field type; rejected because the
  renderer and the builder already know number questions, and a new type is a renderer change.
- **Levels are priced by the answer to one choice question** (`settings.fee.byChoice`: the
  question and one price per option value, each optionally `perQuantity` and `reservesDate`).
  It replaces the flat amount, date steps, count prices and per-entry charging on that form
  (the save refuses them together), and every option must be priced. Only `form:import` sets it
  up; the builder shows it read-only and saves it back untouched. A level's unused answers (a
  Quarter Iftar's people count, an Individual Iftar's date) are dropped before the row is stored.
- **The breakdown is a snapshot**: `unit_price_minor`, `price_quantity`, `price_label` on
  `form_responses`, written from the same quote as `amount_due_minor`, never recomputed.
  `FormResponse::priceBreakdown()` hides one that no longer multiplies to the amount. The
  receipt and the coordinator email show "$17.00 x 4" and drop the "people registered" count on
  these forms (it would read 1 for four people).
- **One date, one sponsor, enforced by the database.** `form_date_reservations` keeps
  `reserved_on` for ever and `holding_on` (the same date, NULL once released) under
  unique(form_id, holding_on). The submit claims the date under the form's row lock, so two
  payers queue and the second gets a 422 on the date question; anything around the lock meets
  the index (`FormDateTaken`, also a 422). A Quarter Iftar takes the whole evening off the list:
  MEC's Wix note asked sponsors to email for availability of "your day", so one day per sponsor
  is the reading; `mecToFill` asks MEC to confirm before import.
- **When an abandoned payment releases its date**: an unpaid card registration holds it for the
  page's life (30 min) + 1 min slack + 15 min grace, renewed by each "Return to payment". After
  that it has lapsed: the date is offered again, and the next payer who asks releases it
  (lazily, no scheduler). A late payment on a lapsed hold nobody took still gets its date; one
  on a date already taken is recorded (money is never refused), logged as a warning by ids and
  shown on the admin board as a conflict to refund or rebook; "Return to payment" on such a row
  is refused. Office and cash registrations never lapse; a cancelled one stops protecting its
  date at once. Alternative: a scheduled sweep; rejected because it can free a date seconds
  before a late webhook arrives, and lazy release gives the same availability.
- **The date list is a second options source** (`reservable_dates`: the form's
  `settings.reservation.dates` from today on the organisation's clock, less held dates). It
  needs no school calendar, and the builder's source picker does not offer it (it has no editor
  for the list).
- **Admin visibility**: GET `.../responses/reservations` (the board: each date's state and
  holder, plus conflicts), the reservation on the response detail, and the breakdown on list and
  detail rows. Scoped through the route's masjid; another organisation's form is a 404.
- **The public payload publishes no single total for these forms** (`unitMinor` null, the unit
  as `unitMinorEach`, levels as `choicePrices`), so the current renderer shows the questions and
  no live total rather than a wrong one. A live "$17 x 4 = $68" on the public page is a renderer
  change, not made here.
- **MEC's two form files** (`database/forms/mec-zakat-ul-fitr.json`,
  `database/forms/mec-iftar-sponsorship.json`) carry only MEC's Wix names and prices (Zakat-ul-Fitr
  (Per Person) $17; Individual $18, Quarter $450, Half $950, Full $1900; source: the migration's
  `reports/stores.md`, as the brief's `commerce.md` does not exist). Both import switched off;
  the evenings list is empty and every 2027 figure is a `mecToFill` item, never invented.

## 2026-09-25 — Ramadan giving review fixes (contract, safety and mutation lenses)
Review of 0f932352 + a9148813. What changed from the entry above, and the calls made:

- **Prices on the public page, from the server** (`App\Support\FormPriceLabels`). The renderer
  draws a price only from `unitMinor` or `fee.amount`, which these forms do not publish, so no
  price appeared anywhere. The published copy of the schema now carries them: each priced
  option of the level question reads "Quarter Iftar ($450.00)" / "Individual Iftar ($18.00
  each)", and the quantity question's help starts "$17.00 each.". Written from the same fee
  rule the server charges by, so the page cannot quote one price and charge another; the
  stored schema, the answers and the receipt's level name are untouched. Alternative: prices
  typed into MEC's form files; rejected because they drift from `byChoice` the first time MEC
  changes a price. Still no live "$17 × 4 = $68": that is the renderer reading
  `choicePrices` / `unitMinorEach`, a burlington-masjid-site change not made here. When the
  renderer does, it should stop drawing these labels' prices twice.
- **Staff codes are refused on quantity and choice forms** (`StoreFormRequest::staffCodeProblems()`,
  and `Form::takesStaffCodes()` false for such a form stored another way, so `staffEntry` is
  never published). The renderer's staff button reads "Record $0.00" without `unitMinor`.
  Lifted when the renderer can price them.
- **`entry_count` is the quantity priced** on these forms (`FormSchema::entryCount()` =
  `priceFor()['entries']`): Zakat for four people is 4 on the list, the roster, the collect
  button and Impact's people count. Capacity counts responses, not entries, so nothing else
  moves. The emails still hide "People registered" there (a Quarter Iftar is not one person).
  The list shows the unit × quantity under the amount only on these forms (`meta.price_breakdown`).
- **The "$15.00 × 3" email line is only on quantity and choice forms.** Every other paying
  form's receipt and coordinator email are exactly as before (pinned by
  `an_existing_per_entry_form_sends_the_emails_it_always_did`); the snapshot columns are still
  written on every paying row, for the admin detail.
- **A level's unused answers are dropped before validation**, not after, so an Individual
  Iftar naming a taken evening, or a Quarter Iftar with "0" people, is never refused over a
  question its level does not ask.
- **An unpaid hold ends 120 minutes after submission** (`FormReservations::HOLD_LIMIT_MINUTES`),
  however often "Return to payment" is used. A new page whose 46-minute hold would run past
  that is refused whole (`EXPIRED`), rather than opened with a shorter hold, so nobody is sent
  to a page that can still take money after their date was offered to others; the status
  read stops offering the button. 120 is the review's own example and unvalidated with MEC.
  Alternative: a renewal count; rejected because the deadline also bounds a page left open.
- **A payment that lands after its date went elsewhere is told to the people involved**, not
  only the platform log: the payer's receipt says the date could not be kept and the
  organisation will be in touch (`lostDate`), and the coordinators' email carries a "Date
  conflict" row. The responses list counts conflicts (`meta.reservation_conflicts`) and the
  folded board header shows the count. No new column: the conflict is derived from the
  reservation and the row, so it cannot drift from them.
- **Restoring a cancelled registration asks for its date again** under the form lock
  (form, then row: the submit's order), and is refused with a 422 naming the date when
  someone else holds it. A conflict is now any live registration without its date that
  someone must act on: paid after losing it, or cancelled-then-restored some way round the
  screen (`FormReservations::isConflict()`); an abandoned card page is not one.
- **Builder fee assembly is a pure function** (`buildFee()` / `preservedFeeOf()` beside
  `feePricingOf()` in `formFeePricing.ts`), so the load-then-save round trips are tested
  without mounting FormBuilder.vue.

## 2026-09-25 — Ramadan giving: the renderer half, and staff codes stay refused
- **The public page now prices these forms live** (burlington-masjid-site branch
  `feat/form-quantity-display`): it reads `quantityField`, `unitMinorEach`, `choiceField` and
  `choicePrices`, shows "$17.00 × 4 = $68.00" in a live region, asks the quantity and the date
  only of the level that uses them, and draws a level whose dates are all taken as closed. It
  draws `FormPriceLabels`' labels as published and adds no price, so each level's price appears
  once. The submit still sends answers only; `Form::priceFor()` stays the only price charged.
  The date question is found by `optionsSource: reservable_dates`; nothing new is published.
- **Staff codes stay refused on quantity and choice forms.** The entry above lifted them "when
  the renderer can price them"; it now can for display, but a staff cash entry is money a
  holder owes, recorded in the same request, and nothing about the staff path was reviewed for
  answer-priced forms (the collect figure, the replay fingerprint, a date hold that never
  lapses). Lifting it is its own change with the owner's say. Until then the page's "Staff
  entry" link says staff cash entry is not available on such a form, rather than vanishing.
- **FormPaymentCheckoutTest is byte-identical to e4c7fc48 again.** The breakdown assertion on a
  staff cash entry moved to
  `FormQuantityPaymentTest::a_staff_cash_entry_on_a_per_entry_form_snapshots_its_breakdown_too`.

## 2026-09-25 — Review of the renderer half: a level's own questions are required by the level only
- **A schema `required` on a choice form's quantity or date question is set aside**
  (`FormSchema::levelQuestions()`). The renderer never draws either question for a level that
  does not ask it and posts it empty; `withoutUnusedPriceAnswers()` drops it; but the validator
  still applied the schema's own `required`, and the save accepts `required: true` there
  (`StoreFormRequest::quantityProblems()` requires it only off choice forms, and
  `reservationProblems()` returns before looking on them). So such a form would refuse every
  Quarter Iftar. Now `applyPriceRequirements()` alone requires them, per level, in its words.
  MEC's import has `required: false` on both, so nothing live changes. Alternative: refuse
  `required: true` at save; rejected because a form stored some other way (an import, an
  older save) would still refuse, where this holds however the schema got in. Pinned by
  `FormDateReservationTest::a_level_is_not_refused_over_a_question_the_schema_marks_required_but_the_level_does_not_ask`.
- **The page total on a quantity form is exact only within a date tier.** `unitMinorEach` is the
  unit in force when the page was rendered, and a tier boundary passing purges no cached page, so
  for about one cache life (300 s) after a tier's `until` the page can show the old unit; the charge
  is always `Form::priceFor($data, now)` and Stripe's page shows it before payment. Accepted:
  MEC's Zakat-ul-Fitr has no tiers, and per-entry `unitMinor` has behaved the same since
  2026-09-11. Recorded in burlington-masjid-site DECISIONS.md with the alternatives.

## 2026-09-24 — Studio W2/W3: the owner's answers to the plans' open questions

Put to the owner in an interview after the W2/W3 plans' first draft
(`docs/manara-studio-w2.md` §8, `docs/manara-studio-w3.md` §8, where each is
recorded against its question).

**Apps**

- New apps are `com.hopetechapps.<slug>` on both platforms.
- The managed Apple account is the team NAFIS and MEC ship under. The managed
  Play console is the one that holds `com.app.masajid`.
- The legacy "Generate Apps" button stops signing and uploading, like Studio (D10).
- Burlington, NAFIS and MEC move to their own OneSignal apps later, in a plan
  of their own, once the first Studio client is live.
- One Firebase project serves every client's Android push.

**TV and prayer display**

- Burlington's Apple TV board takes the D11 board (iqama countdown, events,
  appeal) on its next TV release.
- A Jumu'ah time the client never supplied is hidden everywhere. The
  prayer-settings payload emits `jumaa_is_default: true` only when it is true,
  and the board and both phone apps honour it.

**Arabic**

- The owner reviews the Arabic starter labels. Studio offers Arabic only after
  that review.

**Domains**

- For a client's own domain, `www` serves and the apex redirects.
- A host is re-confirmed daily. A Studio host is demoted after three misses
  over at least 72 hours. Imported hosts are never demoted automatically; the
  owner is emailed instead.
- Pages ceiling notices go out at 50, 70, 85 and 95 percent.

**Web export and handover**

- A web export is frozen code on live Manara data. Its hosting is chosen per
  client, like the apps' account question, with Hope Tech's Cloudflare as the
  default.
- A handover is a zip. A continuing client gets the config repo only. A
  one-and-done client gets a standalone build, with MasjidKit vendored in
  under a licence the owner will provide.

**Repos and runners**

- The MasjidKit refactor ships slice by slice.
- BYO store credentials are still collected, per D1.
- Macs: a dedicated self-hosted Mac that holds no other keys. The
  organisation stays on GitHub Free; the owner's personal Pro plan does not
  apply to it.
- Client repos are named `manara-<slug>-ios`, `-android` and `-web`.
- Existing clients move to their own repos one at a time, Burlington last.
- A dedicated machine account holds the read tokens.
- Exports keep other tenants' inert configuration.
- LLM-written copy is planned after W3.

**MasjidWebMS** becomes a private repository. The owner changes the
visibility.

**Setup the owner has taken on:**

- `OPS_ALERT_EMAIL`;
- the OneSignal organisation key and `ONESIGNAL_ORG_ID`;
- the Cloudflare redirect-rules scope;
- the GitHub pull-request setting, the two ops repos, the repo-ops App, the
  machine account and `GITHUB_OPS_TOKEN`;
- rotating the unused Google key in the iOS repo;
- the dedicated build Mac.

**Still open:**

- who holds each Android upload key;
- whether GitHub Packages can grant a new repo read access by API;
- which distribution certificate store-ops uses;
- the handover licence text;
- the S2b cutover's blocking rule (ASSUMPTIONS.md:37).

Rationale: each answer is the owner's; the plans' recommended defaults were
taken except for export hosting (per client, not always the client's own) and
the one-and-done handover (a standalone vendored build), which the plans now
carry as contracts (W3 S17, S18).

## 2026-09-27 — Lesson plans: one per class, per day, per subject
Decision: an Al-Razi teacher asked for a plan per subject per day (combined-grade
homerooms, several subjects a day); the owner said to add it. The unique key is
now (group_id, session_date, subject_key), `lesson_plan_class_day_subject_unique`,
where `subject_key` is a NOT NULL column the model derives on every save
(subject trimmed, whitespace collapsed, lower-cased; '' for no subject), the
`contact_tags.name_key` shape. A plan with no subject is the day's one general
plan, so a school with no pacing guide works as before. The day view addresses a
plan by id (POST creates and refuses a subject the day already has with a 422
naming it; PUT/DELETE `/lesson-plans/{plan_id}`, resolved through the teacher's
own class). The id-less PUT stays for an older open tab, meaning what it meant
there: the day's plan for the subject sent; else, when the day holds exactly one
plan, THAT plan (the old screen sends its subject edits as a rename, so upserting
on the new subject would leave a duplicate); else a new plan. "Copy to the rest
of this week" therefore never uses it: it rewrites each day's plan for the
subject by id, or POSTs one. The id-less DELETE removes a day's plan only while
it has exactly one (409 otherwise). The subject key is lower-cased with the
SIMPLE case mapping, so it is never longer than the 64-character subject. The office
tab and the records export list every plan of a day with its subject.
`down()` refuses, naming the plan ids, while any class-day holds two plans.
Alternatives: a STORED generated column COALESCE(subject, '') (the rules'
example) — rejected: it cannot carry the case/whitespace rule without a
MySQL-only expression, SQLite cannot ADD a STORED column, and the index would
then mean different things on the two drivers; a unique key on the nullable
`subject` — rejected, NULLs never collide, so two general plans would pass;
dropping the id-less routes — rejected, a tab open across the deploy would 404.
Rationale: the smallest change that keeps every existing reader right and makes
"the same subject twice" a refusal with a sentence rather than an overwrite.

## 2026-09-27 — Responses list: "Checked in" for the door, and status changed from the list
- **The door's "Collected" reads "Checked in" wherever a person reads it** (the list column,
  its badge and "Check in" button, the filter, the detail's label and buttons, the toasts, the
  undo question, and the collect / uncollect answers: "Checked in.", "Already checked in.",
  "Check-in undone.", "This registration was not checked in."). The owner read "Mark
  collected" beside "Paid by card $60.00" as money not yet taken; it is the door
  (`collected_at`, stamped by the first press, refused until settled). The route, the
  `collected` filter values and the `collected_at` / `collected_by` fields keep their names.
  The two CSV exports keep their "Collected" headers: a spreadsheet already reading them by
  name is not broken for a word. Pinned by
  `FormResponsesMoneyAdminTest::bracelets_are_stamped_by_the_first_press_and_undo_clears_them`.
- **Each list row's Status is a select that saves on change** (since: saved once chosen, never on
  an arrow-key step; see "review fixes" below), through the detail's own PUT
  (`FormResponsesController::update()`), so a cancel from the list closes a card page and
  answers exactly as a cancel from the detail does. The row is patched where it stands, not the
  page re-read, as the door's actions are: on a list filtered or sorted by status a slip stays in
  view to be put right. Moving into Cancelled is asked first, in sentences each tied to what the
  code does (`formResponseStatus.ts::cancelQuestion()`): no card refund, an open card page
  closed, cash kept in the cancelled column, no check-in or payment while cancelled, no email, a
  reserved date offered to others, capacity not freed. Leaving Cancelled is not asked: a restore
  whose date is taken is refused by the server with the date named, and the select reverts.
  Pinned by `resources/vue-app/tests/form-response-status.test.ts`.

## 2026-09-27 — Responses list status select: review fixes
- **A status is saved once it is chosen, never on an arrow-key step** (WCAG 3.2.2). In Chrome
  and Edge on Windows, and in Firefox, ArrowUp/ArrowDown, Home/End, the Page keys and
  type-ahead letters on a closed select change it and fire `change` on every press; saving
  on `change` saved each status passed through, and a cancelled registration was restored by
  arrowing past New. `formResponseStatus.ts::statusSelectController()` now holds a change
  made by those keys ("Not saved: Enter saves, Esc undoes"), saves it on Enter or when focus
  leaves the select, and puts the saved status back on Escape. A status picked from the open
  list (mouse, or Alt+ArrowDown / F4 / Space then Enter) is saved at once, as before.
  Rejected: a Save/Undo button beside each select (a second click on every change, which is
  what the owner asked to be rid of) and a custom listbox (a native select is already
  keyboard- and screen-reader-operable). Pinned by `form-response-status.test.ts`.
- **An inline status change takes `busyRowId`, the one lock every row action takes, from the
  question to the answer.** Every row's select, every door and payment button, and the row's
  View wait while it runs, so two answers can never land on a row out of order, and no
  second SweetAlert (which shows one popup at a time) can replace a cancel question or a
  must-act answer. "Close its card payment page again" runs after the lock is let go, since it
  is a row action of its own (`askTriageAnswer()`). Delete waits on the same lock. The detail
  modal now starts Save from the status it fetched, not the list row's.
- **The PUT carries `expected_status`** (the status the list showed). `update()` answers 409
  `status_changed` and writes nothing when the locked row has moved, so a list read before
  another admin's cancel cannot quietly restore it; the screen re-reads the row and says so.
  Optional: the detail modal and older clients send none and behave as before. Pinned by
  `FormResponsesAdminTest::a_status_change_from_a_list_read_before_another_admins_cancel_is_refused_and_changes_nothing`.
- **The cancel question says only what the code will do.** An open card page: the server
  "tries to close" it and says when it cannot; one known to be beyond checking
  (`page_unreachable`) is said not to be closable until it expires. A card payment taken
  through another organisation: only that organisation can refund it, as
  `FormChargeAccount::refundInstruction()` says; one already refunded in full is not sent to be
  refunded again, one refunded in part says how much. A registration with a payment is never
  deleted, so for it the capacity line says cancelling does not free a place, instead of
  pointing at a delete the screen refuses. The capacity claim is pinned on the server by
  `FormResponsesAdminTest::cancelling_a_registration_keeps_its_place_and_only_deleting_frees_one`.
- **A cancel or restore from the list re-reads the reserved dates whenever the form reserves
  them, folded or not**: the board's conflict badge shows while folded, and cancelling a
  conflicting registration is how a conflict is resolved.
- **No DOM test harness was added for the view.** The SPA's tests run on `node --test` with no
  jsdom or @vue/test-utils; adding them is a dependency decision beyond this fix. The decisions
  the view made inline now live in `formResponseStatus.ts` and are tested with fakes, and the
  template and script wiring is pinned by reading `FormResponsesView.vue` as text, as
  `newsletter-blocks.test.ts` does. Each of 37 mutations (one at a time, in a copy) failed
  the suite.

## 2026-09-27 — The Friday lunch door answers a Stripe failure with the saved order, not a 500

Decision: `JummahLunchOrdersController::store()` now catches any failure to open the card page
(after its existing `RuntimeException` refusal catch) and answers 422 with the saved, unpaid order
and a fixed sentence (`PAGE_NOT_OPENED`), recording the error through `Errors::publicMessage`, which
logs it at ERROR. This is the Halal Kitchen door's handling (2026-09-25 review fixes), which that
entry left "for its own change". Before, Stripe's `ApiErrorException` (it extends `\Exception`, not
`RuntimeException`) fell to the outer catch: a bare 500 with no order, while the order sat saved and
unpaid. Only `store()` changed; `update()` and the top-up path already catch `\Throwable` or Stripe's
`ExceptionInterface`, and `MealOrderCheckoutService` is untouched.

The sentence differs from the kitchen's on purpose. The kitchen's says to try paying again from the
order; a Friday order page has no pay button, so this one names the two ways that exist (pay at
pickup, which staff record with Mark paid, and a payment link from the board) and says not to order
again, because the SPA order page (`LunchOrderPage.vue`, `publicLunchStore.placeOrder`) keeps the
customer on the form on any non-2xx, so a second press places a second order.

Alternatives: (a) catch only `Stripe\Exception\ExceptionInterface`: narrower, but a non-Stripe
failure after the order is saved would still be a 500 with no order, the same harm; the kitchen door
chose `\Throwable` and the doors should not differ. (b) Pass Stripe's own message through: refused,
it is not written for a customer.

Known and not done here: the SPA should send the customer to the saved order on a 422 that carries
one (the kitchen renderer's `savedUnpaid`), rather than keeping them on the form. That is a
frontend change beyond this fix; the sentence covers it until then.

Test: `JummahLunchOrderFlowTest::a_card_order_saved_when_stripe_fails_comes_back_with_the_order_instead_of_a_server_error`
(422, the sentence, the saved order's uuid, unpaid, no session id, one ERROR log naming the Stripe
exception). Fails on main with a 500.

## 2026-09-27 — `fix/lunch-edit-dead-stripe-link` (f9ef0836) needs no integration: it is already on main

Decision: nothing cherry-picked. f9ef0836 was reported unmerged, and it is (its branch is not an
ancestor of main), but its patch reached main as bd6a093f: same subject, same author date
(2026-09-18 13:42:45), and an identical `git patch-id` (8a63c17d). Main's `MealOrderEditor::apply()`
still carries the repair (`$closedSessionId`, `forgetClosedPage()`, the
`lunch.edit.page_closed_but_edit_failed` / `lunch.edit.dead_page_not_cleared` logs), and the test
`MealOrderEditTest::an_edit_that_fails_after_closing_the_page_does_not_leave_a_dead_link_on_the_order`
is on main and runs in the full suite. The two later edits to the editor (1eaf4c4e, ae9a8820, the
paid-order top-up) did not move the close or the repair: the close still happens only for an UNPAID
order's session inside the transaction, and the catch still clears exactly the id it closed.
Cherry-picking would add an empty commit or conflict with nothing to gain. The branch
`fix/lunch-edit-dead-stripe-link` can be deleted by whoever owns branch housekeeping.

## 2026-09-27 — Review of the lunch-door fix: database and garbled-Stripe failures leave the refusal branch, and both catches are pinned

Decision: in both public card doors (`JummahLunchOrdersController::store()` and the kitchen door,
`KitchenOrdersController`), `\PDOException` (which covers Eloquent's `QueryException`) and Stripe's
`ExceptionInterface` are caught BEFORE the
`RuntimeException` refusal catch and answered with the fixed `PAGE_NOT_OPENED` sentence through
`Errors::publicMessage` (logged at ERROR). The earlier entry's "any other failure gets the fixed
sentence" was not true: `QueryException extends PDOException extends RuntimeException`, and so does
the SDK's `Stripe\Exception\UnexpectedValueException` (thrown on an unreadable API response). Both
took the verbatim refusal branch, so a lock wait or deadlock inside `checkout()`'s own transaction
(it has no retry count) handed the customer the SQL, and a garbled Stripe answer handed them
Stripe's words, with nothing logged either way.

This is the order the codebase already uses wherever a service call sits beside a refusal catch
(`update()` on this controller, `MealOrdersController`, `ContactFamilyLoginController`), so the
doors now match it. `MealOrderCheckoutService` is untouched: its refusals stay plain
`RuntimeException`s worded for the customer.

Alternatives: (a) answer only exact-class `RuntimeException` verbatim (`$e::class ===
RuntimeException::class`), which would also cover `ModelNotFoundException`: broader, but a rule
nobody else in the codebase follows, and a findOrFail on an order saved a moment earlier is not a
failure worth a new idiom. (b) Catch `QueryException` only, as those callers do: narrower than it
looks, because `DB::transaction()` opens with `beginTransaction()`, which rethrows the driver's raw
`PDOException` (not wrapped) on any failure that is not a lost connection.

Tests, each run against a mutant that removes what it pins (logs on the droplet under
`/root/manara-ci-lunchdoor-mut/storage/logs/mut-*.txt`):
- `JummahLunchOrderFlowTest::a_card_order_saved_when_its_page_cannot_open_comes_back_with_the_order_and_a_fixed_sentence`,
  a data provider over Stripe unreachable, Stripe answering garbage, the database failing
  mid-checkout (a `QueryException`, and a bare `PDOException`), and a non-Stripe, non-Runtime
  `ErrorException` (the last pins the `\Throwable` choice in the entry above: without that catch,
  a 500).
- `JummahLunchOrderFlowTest::a_card_order_the_masjid_cannot_take_yet_is_refused_in_the_services_own_words_with_the_saved_order`:
  the Connect gate in `preflight()` refuses after the order is saved; 422, the service's sentence,
  the saved uuid, and no ERROR log. Without the `RuntimeException` catch, the broad catch would
  silently turn this refusal into the fixed sentence and every other lunch-door test stayed green.
- `KitchenOrderFlowTest::a_card_order_whose_page_fails_inside_checkout_gets_the_fixed_sentence_not_the_failures_own_words`,
  the database and garbled-Stripe cases for the kitchen door.

## 2026-09-27 — Staff consent lifts the Wix order-history hold; the contact import still does not
The order-history import (`crm:import-wix-orders`) holds the address of every Wix buyer it has to
create (`EmailSuppression::REASON_ORDER_HISTORY_HOLD`, `order_history_import`). Its docblock said "a
later consent decision may lift" such a hold, but the staff consent path lifted only `not_opted_in`,
and the member record badged the hold as "Emails: unsubscribed", which is untrue.
- **Staff may lift an order-history hold exactly as they lift `not_opted_in`.** New
  `EmailSuppression::STAFF_LIFTABLE_REASONS = [not_opted_in, order_history_import]`, read by
  `EmailSuppressionService::liftPrecaution`: same endpoint, same required evidence, same
  `release_source = staff_recorded_consent`, `release_evidence`, `released_by_user_id`, same 422 for
  every other reason. Both reasons mean "an import had no consent on record", never "the person
  asked". Pinned by `ContactEmailConsentTest::staff_can_lift_an_order_history_hold_exactly_as_they_lift_a_not_opted_in_precaution`
  and `every_reason_but_the_two_import_precautions_is_refused_to_staff_including_any_added_later`
  (sweeps every `REASON_*` constant, so a reason added later is refused until decided).
- **The badge says "Emails: held (imported order, no consent on record)"** with the same "Record
  consent to email" action (`emailOptOut.ts`, pinned in `email-opt-out.test.ts`).
- **`order_history_import` does NOT join `PRECAUTION_REASONS`.** Every use checked: (1)
  `WixContactImport::counts()` filters its own planned reasons, which can never be the order hold, so
  membership would change nothing there; (2) `WixContactImport::undo()` deletes a row its batch linked
  in `import_links` when the reason is in `PRECAUTION_REASONS`. Neither import can naturally name the
  other's row (each inserts only where the address has no row at all), so today the lists only
  matter as the SECOND key: each undo deletes a row only when its own record names it AND the reason
  is its own (`WixOrderHistoryImporter` checks `reason === order_history_import`). Kept disjoint, a
  provenance record that ever pointed at the other import's row still cannot delete it; merged, the
  contact-import undo would delete an order hold its record happened to name. The shared meaning
  ("staff may lift") got its own constant instead. Pinned both ways with a forged record:
  `WixContactImportTest::undo_never_deletes_an_order_history_hold_even_when_its_run_record_names_the_row`,
  `WixOrderHistoryImportTest::undo_never_deletes_a_contact_import_precaution_even_when_its_record_names_the_row`.
  Alternative (add it, since it is "an import's precaution" in words): rejected for the reason above.
- **The contact import does not lift an order hold, even when Wix says SUBSCRIBED and VALID.** It
  counts it instead, on a new dry-run/apply row "of which held by the Wix order-history import (staff
  can record consent)" under "SUBSCRIBED on Wix, but suppressed in Manara (kept suppressed)". Why not
  lift: a RELEASED row is read in three places as the person's own newer decision — the contact
  import's plan never re-suppresses it, the order import never holds over it, and staff consent only
  looks at rows in force. A row released by an import would inherit that meaning, so a later Wix pull
  saying UNSUBSCRIBED could never suppress the address again: the dangerous direction. Making it safe
  needs a new release source that every one of those readers excludes, a re-suppress path, and undo
  of the release — a wide change to an importer that has not run in production yet, for a rare case:
  the contact import is enforced to run first, so it arises only under `--without-contact-import` or
  for a buyer missing from the contacts export the first run read and present in a later one. Revisit
  if the new row shows real numbers on MEC's data. Pinned by
  `WixContactImportTest::an_order_history_hold_on_an_address_wix_says_is_subscribed_is_counted_on_its_own_row_and_kept`.
- **A real opt-out on a hold replaces the hold's reason (review fix).** Staff lifting reads only the
  reason, and nothing used to rewrite a reason on a row in force: `suppress()` wrote nothing ("already
  in force") and the contact import skipped any address with a row. So an unsubscribe link, or a Wix
  UNSUBSCRIBED / SPAM_COMPLAINT / BOUNCED, landing on an `order_history_import` or `not_opted_in` row
  left it liftable by staff. Now `EmailSuppressionService::replacesHold()` (current reason
  staff-liftable, incoming reason not) makes `suppress()` write the new reason, date and provenance,
  and keep the hold in two new nullable columns, `held_reason` and `held_since`. `suppressed_at` moves
  to the opt-out's date, because a row reading "unsubscribed on the day the order import ran" would be
  untrue. The contact import makes the same rewrite, counts it on its own row ("Held in Manara for
  want of consent, but opted out, complained or bounced on Wix") instead of "Already suppressed", and
  does NOT link the row in `import_links`: the run did not insert it, so its undo leaves the stricter
  reason in place (errs towards not mailing; a re-run from the same file would write it again).
  Alternative: have `liftPrecaution` look for an opt-out elsewhere. Rejected: there is no elsewhere;
  the row is the record. The unsubscribe landing page now offers the real unsubscribe over a hold
  instead of "You are already unsubscribed". Pinned by
  `ContactEmailConsentTest::an_unsubscribe_on_a_held_address_replaces_the_hold_and_staff_can_no_longer_lift_it`,
  `a_wix_unsubscribe_complaint_or_bounce_imported_over_a_hold_cannot_be_lifted_by_staff`, and
  `WixContactImportTest::a_wix_opt_out_over_a_hold_replaces_its_reason_on_a_row_of_its_own_and_survives_the_runs_undo`.
- **Held order lifted before the contact import: a Wix opt-out suppresses it again.** The order import
  holds a buyer without reading Wix's email status, so staff lifting that hold (release_source
  `staff_recorded_consent`) did not decide anything over a Wix opt-out; they could not see one. When
  the contact import then finds Wix UNSUBSCRIBED, SPAM_COMPLAINT or BOUNCED for that address, it
  suppresses it again with Wix's reason (keeping the hold in `held_reason`), counts it on its own row
  ("Order hold staff lifted in Manara, but opted out ... (suppressed again)") and prints a warning,
  so the office knows whose recorded consent no longer applies. Rule: the address ends where it
  would had the contact import run first, when staff could not have lifted a Wix opt-out at all
  (rule 3 on `EmailSuppressionService`: only the person undoes an opt-out). Wix with no opt-out
  (NOT_SET, pending) leaves the recorded consent alone. A `not_opted_in` staff lifted is NOT reopened:
  the contact import had read Wix before writing it, so the lift was made knowing Wix had no opt-out.
  Alternative: refuse the staff lift of an order hold until the contact import has run. Rejected: an
  organisation that never runs the contact import (or a buyer missing from its export) could then
  never be mailed, and "has the contact import run for this address" has no single answer. Pinned by
  `ContactEmailConsentTest::an_order_hold_staff_lifted_before_the_contact_import_is_suppressed_again_when_wix_has_an_opt_out`.
- **The consent dialog says what each import knew.** For `order_history_import` it reads "This address
  came in with an imported order, and no consent to email is on record in Manara, so it is held";
  only `not_opted_in` says the old website had no consent (`emailConsentPrompt` in `emailOptOut.ts`,
  pinned in `email-opt-out.test.ts`). The `STAFF_LIFTABLE_REASONS` docblock says the same.

## 2026-09-27 — Two couplings to keep in step (recorded at the order-hold ship)

- **The meal doors' per-order cap modes are mirrored by the cart.** The Halal Kitchen door passes
  `LunchOrderLines::CAP_REFUSE` (`KitchenOrdersController`) and the Friday lunch door passes
  `CAP_CLAMP` (`JummahLunchOrdersController`). The universal cart's `MealLineSource`
  (feat/universal-cart, not yet on main) deliberately copies each door's mode rather than
  choosing one, and always tells the shopper when it clamps. Changing either door's cap mode
  means changing the basket with it, or the two will disagree about the same order. The
  lunch-door Stripe fix (01df855a) maps errors only and moves neither mode.
- **A contact-import undo can delete a row a later batch made stricter.** Batch A inserts a
  `not_opted_in` row (linked to A); batch B rewrites it to `bounce` because Wix says BOUNCED;
  undoing A then deletes the row, because `bounce` is in `PRECAUTION_REASONS`. The end state is
  the same as before the order-hold fix (where B never wrote the bounce and undo A deleted the
  `not_opted_in` row), so it is not a regression. Accepted as is.
## 2026-09-27 — Studio W2 S7: one guarded capability writer for live organisations

`CapabilityWriter::apply(Masjid, array<string,bool>, int $actor)` is the writer
for an organisation that already exists. The single switch
(`PATCH .../capabilities/{key}`) now calls it for one key, and the new bulk
`PATCH /api/admin/masjids/{id}/capabilities` (`capabilities[<key>]=1|0`) for
several. `applyAtCreation` stays Studio's creation-time writer.

- **Only the keys sent (plan R6).** Each stores an explicit override, even at
  its default, and writes one ledger row, no-ops included. Nothing goes through
  `CapabilityCatalogue::resolve()`, so an unsent key (Burlington's `web_pages`
  off) is never reset. Keys are written in catalogue order.
- **All or nothing.** Every key is checked and Giving's refusal (which can call
  Stripe) runs before the transaction; any refusal writes nothing for any key.
  Refusals are a `ValidationException`, which the JSON renderer draws in the
  single switch's `{status:'failed', data:{capability:[sentence]}}` envelope, so
  the sentences have one home: `CapabilityWriter::assertWritable` (unknown /
  column-backed) and `GivingSwitch::refusalToSwitchOff`.
- **Top-level keys only (R7).** `array_key_exists` on `config('capabilities')`,
  as Studio's request does. This closes the dotted-key hole: `giving.defaults`
  used to store a junk override through the single switch and is now a 422 with
  "There is no such capability." No known caller sent one.
- **Locked.** The `masjids` row is `lockForUpdate()`ed and its overrides re-read
  inside the transaction, so two writers holding the same stale model both land.
  The single switch had no lock.
- **The family cache is flushed after commit** (`DB::afterCommit`), and the
  pivot is never touched (S2b owns it).
- **Bulk response:** `data` is exactly the single switch's payload
  (`ADMIN_APPENDS`), plus `meta: {changed, unchanged}`, where `changed` means the
  effective value moved and `unchanged` means it already had the value sent (the
  override is stored and ledgered all the same).
- **The single switch answers byte for byte as before**, pinned by
  `SetCapabilityDelegatesTest` against a recording made on the pre-change
  controller (`tests/fixtures/set-capability-responses.json`): eleven flips
  covering a change, a no-op, a grant, Giving refused and allowed, an unknown and
  a column-backed key, a missing and a `"false"` value, and a MasjidAdmin. (The
  harness sent a twelfth step, "an organisation that does not exist", to the
  existing organisation by mistake; that entry was cut from the fixture and the
  404 is asserted on its own.) The order of its checks is kept: 403, then the key
  (before the organisation is looked up), then 404, then Giving.
- The live panel is unchanged and keeps sending one key per request. Studio's
  live-organisation Features card (S9) is the bulk caller.

## 2026-09-27 — Studio W2 S18: the D11 board, and Jumu'ah shown only when supplied

Built by track T4 across three repos: this one (2afd6dc2, live), Android (`efb82f3`, merged)
and iOS (`e9d74dc` on main: MasjidKit, MasjidTV and the iPhone Friday card; the TV board reaches
Burlington only with its next TV release). The
calls below are where the build departs from, or answers, `docs/manara-studio-w2.md` §S18.

- **The flag.** `jumaa_settings.is_default` is nullable with no backfill: true = the 13:30
  placeholder provisioning writes when no `jumaa_iqama` is given (same truthiness as the old
  `?: '13:30'`), false = a time was supplied (provisioning with a `jumaa_iqama`, or an admin
  save that carries an iqama, or athans or shifts saved on a placeholder, which also nulls its
  13:30), NULL = every row that predates the column, which is every live organisation, and
  admin saves leave it NULL. The column
  is `$hidden`, because the row is serialized raw into `/prayers/settings`, the admin screen and
  every Friday's `prayers.jumaa_data`; it reaches clients only as `jumaa_is_default: true`, sent
  only when true. Every reader (MasjidKit `suppliedJumaa`, iPhone `showsJumaa`, Android
  `suppliedJumaa`) treats only `true` as "do not draw", and still receives the row. The
  provision snapshots were re-recorded for the one new column; the response body did not change.
- **What an admin save supplies (review fix, `fix/studio-s18-review`).** The admin Jumu'ah screen
  has had no iqama field since 1c92bbb5; it posts athans and shifts. The first build cleared the
  flag on every save, which promoted the invented 13:30 iqama to a time someone gave, and the
  board would have drawn "Iqama 1:30 PM" and counted down to it. Now an iqama in the request is
  supplied; athans or shifts on the placeholder supply Jumu'ah and DROP the placeholder iqama
  (the column is nullable); a save with no time leaves the placeholder flagged; a row that
  predates the flag (every live organisation) keeps its iqama untouched.
- **The Studio tick wins over a typed Jumu'ah time (review fix).** "Client has not given iqama
  times" greys out the Jumu'ah field and drops its preview row, so `toProvisionPayload` no
  longer sends a `jumaa_iqama` typed before the tick, and the organisation provisions with the
  flagged placeholder, as the tick already wins over typed offsets. The draft keeps the value.
- **Rolling back needs the migration too.** Pre-S18 `JumaaSetting` has no `$hidden`, so old code
  with the column present adds `"is_default": null` to every organisation's Jumu'ah payload. A
  rollback runs `migrate:rollback` for `2026_09_27_130000_add_is_default_to_jumaa_settings_table`
  and flushes the prayer-settings cache.
- **Iqama visibility (the plan's Unknown): answered.** `/prayers/settings` already carries
  `iqama.show_iqama_times` (the W1 S8 "client gave no iqama times" signal). The board draws no
  Iqama column and no iqama countdown when it is false; absent means shown. All five live
  organisations send true.
- **The countdown** runs to the earliest moment still ahead: the adhan, then the iqama once the
  adhan has passed (an iqama at or before its own adhan is ignored), then the next adhan. On a
  Friday whose Jumu'ah is drawn, each Jumu'ah time ("Khutbah in") and the Jumu'ah iqama take
  Dhuhr's place; a placeholder Friday counts to Dhuhr. Sunrise is never a target.
- **Events are text slides.** `/events` has no image field, so "an image or a text slide" is
  text only. The 14-day, six-event window is kept on the board, but the feed itself answers
  yesterday to six days ahead (`EventsController`); widening it would change the phone apps'
  payload, so it was not done.
- **Tests that moved.** No organisation, live or staging, had an event to record, so
  `tests/fixtures/mobile-events.json` is pinned to the endpoint's own serialization by
  `MobileEventsPayloadContractTest`, and MasjidKit decodes a byte-identical copy.
  `SignageStoreTests.events_keep_the_last_good_value_on_failure` became MasjidKit
  `EventsRefreshTests`, driving `KeepLastGood` through `MasjidAPIClient` and a stubbed session,
  because MasjidTV has no test target.
- **Digits and language.** Board labels follow the Apple TV's language (a String Catalog, English
  and Arabic, marked needs-review for the owner). Every number the board prints goes through one
  locale, `BoardFormat.numberLocale`: en_US_POSIX in English, as before, and Arabic words with
  Latin digits in Arabic, never the device region's digits. **Owner, 2026-09-28: Latin digits
  on the Arabic board**, which is what that function does; it is the one place to change if that
  ever changes. **Owner, 2026-09-28: the board's language follows the organisation's website
  language** (`masjids.website_locale`, added by W2 S12), not the Apple TV's. That is a follow-up
  slice after this one: the board reads the locale from a payload it already fetches (agreed with
  the S12 owner; tv-config or `/api/v1/settings` rather than a new endpoint), English when the
  field is absent, so every live board is unchanged until an organisation sets it; Arabic keeps
  Latin digits; and the locale is cached with keep-last-good, so a failed fetch never flips the
  language. Until then the S18 build follows the Apple TV's language. The Arabic labels stay
  needs-review until the owner signs them off.
- **Light theme.** tv-config `theme: "light"` drew white text on a light background; a palette
  gives it dark ink. Every dark value is the literal the views used before.

Phones and a provisioning-supplied Jumu'ah time. **Owner, 2026-09-28: the phone apps keep showing
ONLY khutbah times (`athans`/`shifts`), with no fallback to the Jumu'ah `iqama`.** No app change.
Today a Jumu'ah time given at provisioning is stored only as `iqama` (no `athans`), so the phones
draw no time for it while the TV board does. The fix belongs in Studio's Step 0 prayer slice (T2),
which will store Studio's Jumu'ah times as khutbah times. The flag must follow: a provisioning
that gives any khutbah time or an iqama is supplied (`is_default` false), and the 13:30 iqama is
written, flagged, only when neither is given. An iqama nobody gave is never written beside
supplied khutbah times.

## 2026-09-27 — Studio W2 S8: regenerating an existing organisation's brand images

`BrandAssets::regenerate(Masjid, ?bg, ?actor)` rebuilds the favicon (48×48,
transparent), touch icon (180×180, opaque) and share image (1200×630) from the
organisation's current `logos` row, with the same image code Studio uses at
provision. `POST /api/admin/masjids/{id}/brand-assets/regenerate`
(`{background_color?}`, SuperAdmin, in-controller 403) returns the four URLs.

- **One image code.** `LogoDerivatives::generate(StudioDraft)` and the new
  `fromFile(path)` are both thin callers of one private `derive()`; the plan's
  wording was "generate becomes a caller of fromFile", and this keeps its point
  (one implementation, provision output unchanged: `StudioProvisionLogoTest`
  unedited) without writing the draft's bytes to disk twice.
- **Source and colour.** The logo must be a PNG or JPEG by its bytes
  (`getimagesize`), else 422 `logo: "Upload a PNG or JPEG logo first."`; a logo
  GD then fails to decode gets the same answer, with the reason logged. The
  colour defaults to `theme_settings.background_color`; with none and none sent,
  422 `background_color`.
- **New first, old after commit.** The three new rows are added (with
  `preservingOriginal()`) in one transaction; the previous rows of the three
  collections are deleted only after it commits. Checked in vendor: medialibrary
  11.23.3 removes a row's files in `MediaObserver::deleted`, at the delete, not
  at commit, so deleting first inside the transaction would lose the old files
  on a rollback. A failure before the commit deletes the directories of the
  rows it made (StudioProvisioning's pattern); the old derivatives stay. A
  failed delete of an old row after commit is a warning, not an error: the new
  row is the latest and is what every reader takes.
- **After commit** it schedules the renderer purge and writes
  `Log::warning('Brand assets regenerated')` with the actor (production's level).
  No mobile cache is flushed: no mobile payload reads the derivatives (they read
  `logos`, which this never writes).
- **Logo uploads keep them in step, only where they exist.** After either admin
  logo upload (`MasjidDetailsController::updateDetails`, `MasjidsController::update`)
  has committed, `BrandAssets::afterLogoUpload` regenerates for an organisation
  that already has any of the three rows (a Studio one) and does nothing for one
  that has none, which is every live organisation today. It never throws and
  never changes the upload's answer; a failure is a warning and the old
  derivatives stay.
- **Live effect needs the owner's go per organisation.** Regenerating a live
  organisation adds `favicon_url`, `touch_icon_url` and `share_image_url` to its
  `/api/v1/settings` and changes its tab icon and share card (W1 R11). Studio's
  confirm dialog (S9) says so.

- 2026-09-28 (review fix S8-1): the SuperAdmin update route (`POST /api/admin/masjids/{id}`, `MasjidsController::update`) now has its own two tests, because its `BrandAssets::afterLogoUpload` call was pinned by nothing: one where a Studio org's derivatives are replaced from the new logo, one where an org without any stays without any. Test-only; no code changed. Not run locally (no PHP).

## 2026-09-27 — Studio W2 S9: Studio opens an organisation that already exists

A SuperAdmin opens any organisation in Studio at
`/dashboard/super/studio/organisations/:id` (Studio's list gains an Organisations tab
over the existing masjids index). No draft (plan R14).

- **Snapshot** (`GET /api/admin/studio/organisations/{id}`, `OrganisationSnapshot`):
  Studio's sections in Studio's order plus `apps` (S17 fills it). Each is
  `{data, edit_in}`; `edit_in` is `studio` for features and brand, otherwise the SPA
  route of the screen that already writes it (`/masjid/details#basic-info`,
  `#prayer-calculation`, `/masjid/about`, `/masjid/pages`, the super organisation
  screen). A `/masjid/…` link first switches the dashboard's current organisation, as
  the masjids list does, and asks first when there are unsaved changes. Every value is
  picked by name: `platforms` carries modes and `has_*` flags only, never a credential
  or identifier. `features` is `CapabilityCatalogue::forOrgType` with each entry's
  effective value (`moduleIsOff` / `hasCapability`) and whether it was `decided`.
- **Preview** (`POST …/preview`, writes nothing): `StudioPreview` now takes a
  `PreviewInput`. `fromDraft` is W1's derivation moved unchanged (the draft preview
  tests pass unedited); `fromMasjid` clones the organisation in memory with the
  candidate switches (only keys `CapabilityWriter::assertWritable` accepts, so the
  preview can never show a change Studio could not save) and paints the web with the
  theme's STORED tokens and the candidate colours, which is what the renderer draws
  once the colours are saved. The web mockup draws the organisation's own pages in the
  starter plan's shape (`preset_source: live`).
- **Writers are the existing ones.** Features: S7's bulk PATCH, only changed
  `writer === capability` keys (never crm or assistant, shown read-only with "Change on
  the organisation's details screen."). Colours: the theme screen's own endpoint with
  the four colours only (`tokens` is never sent, so stored tokens survive; the confirm
  lists failing contrast pairs and asks to save anyway: for a live org the palette is
  advisory). Brand images: S8's regenerate, whose confirm says in words, for an
  organisation with none, that it adds a favicon, home-screen icon and share image to
  its site and settings and needs the owner's go.
- **Reuse, not a refactor.** The Features card reuses Step 1's `FeatureRow` and
  `featureGroups` rather than making `StudioFeatureStep` dual-mode (W1 pins it); the
  frames (Web/iOS/Android/TV) and `PlatformContrastList` are reused as they are. The
  palette pair names moved into `core/studio/paletteLabels.ts`, shared by two screens.

Review fixes (2026-09-28):

- **Dialog titles are text.** SweetAlert2 parses `title` as HTML, so an organisation name
  in one ran script in a SuperAdmin's session. The live cards use `titleText` for anything
  interpolated and `dialogHtml` (which escapes) for every `html` body;
  `StudioSpaSourceTest` fails on a `${` in a live card's `title:` or an `html:` that is not
  `dialogHtml(`.
- **Stored #RGB and #RRGGBBAA colours** are accepted everywhere the theme save accepts
  them. The preview request takes the three forms; the mockups get a display copy
  (`WcagColor::normalize`: 3 digits expanded, alpha pair dropped); the Brand card's field
  and `coloursComplete` use `isThemeHex`, and Save colours sends an untouched colour
  exactly as stored. Draft Foundation stays six digits.
- **The Web tab follows the candidate Website switch** (the clone, not the saved row).
- **A live organisation's Android frame draws its stored pivot rows**, read through the
  `features()` relation as GET /features serves them, scoped by masjid_id, not the switches
  (`PreviewInput::$androidFeatureIds`, null for a draft). Saving switches never moves it, so
  the preview column says installed Android apps follow the stored menu until the cutover.
- **A failed preview leaves the contrast report stale.** The Save colours dialog then says the
  check failed for these colours (no pair list) and the Brand card marks the report out of date.
- **2026-09-28: an empty stored menu draws the shipped Android bar.** When a live
  organisation has no available pivot row (none stored, or all off), installed Android
  builds fall back to Home, Announcements, Contact and Donate (MenuViewModel,
  BottomBar.visibleTabs), so the frame draws those four, not a bare Home. On the live card,
  typing emits a colour only at 6 or 8 digits; a #RGB is taken on blur, since every
  six-digit code passes through a valid three-digit prefix.

## 2026-09-27 — Studio W2 S1–S4 (domains lifecycle): the calls made while building
- **S4 re-probes every confirmed host once a day, token or not, and `DomainsReconcileCommandTest`
  was edited on purpose.** W1 pinned that production's imported, confirmed rows cause no request
  without a token (`without_a_token_on_productions_rows_it_selects_nothing_and_sends_nothing`) and
  that a confirmed row is never selected. S4 changes both: the pin is now
  `without_a_token_confirmed_rows_are_probed_once_a_day_on_their_own_host_only` (a GET of each
  host's own `/api/tenant`, twice in two days, never Cloudflare), the selection test lists the
  three confirmed rows, and the token test's confirmed row carries a future `next_check_at`.
  Cadence is `next_check_at` plus a per-row `Cache::add` marker (the attacher's existing
  rate-limit pattern), because a manual row with a token is also selected every six hours for
  W1's reads. A lost cache can only bring one probe forward.
- **A miss on a confirmed row does not write `last_error`.** W1's "a miss after a match writes
  nothing" holds for the row the screens read; the run of misses lives in the new
  `serving_miss_count` / `serving_missed_since` (now in the admin payload). Demotion, which clears
  only `serving_confirmed_at`, does write `last_error`, so the screen says why the tick went.
  With daily probes, "three misses over at least 72 hours" is the fourth miss.
- **A confirmed row keeps its first `serving_confirmed_at`.** A re-probe match stamps
  `serving_last_seen_at`; only a demoted row is stamped again, by `DomainProbe::confirm()`.
- **S3 adds a fifth domain route, so `MasjidDomainsAdminRoutesTest` counts five** (the plan said
  it passes unedited; its refusal walk now covers detach too). Detach deletes the row even when
  something Studio did not create is left in Cloudflare: the objects are named in the answer and
  in a `Log::warning`, because keeping a `detaching` row for an object Studio will never remove
  would retry forever.
- **A `detaching` row of a trashed organisation is still finished by reconcile.** S2 keeps the
  attacher off a trashed organisation's rows; taking hosts off Cloudflare is exactly what a
  departed organisation needs, so the detach retry ignores the trash.

## 2026-09-27 — Studio W2 S5 (apex↔www redirects): the calls made while building
- **A pair is written only when `canonical` is sent.** `POST .../domains` and provisioning's
  `web_domain` accept `canonical` (`www` or `apex`) on a host that is its zone's apex or `www`, and
  then record both hosts. Without it the request is W1's one host, so every W1 test and the live
  SPA are unchanged and S5 ships inert. `www` is the owner's default for a screen that offers the
  choice; no screen sends it yet.
- **The token's redirect scope is checked by a read before anything is written.** A redirect row
  reads the zone's redirect entry point first; a refused token waits on `token_scope` before a
  placeholder A record exists for a rule that cannot follow. A redirect row waits
  (`waiting_on = canonical`) until its serving sibling has its zone.
- **`domains:collapse-alias` switches the row to `redirect` only after the 301 is seen.** Rule,
  then the probe (up to six tries, five seconds apart), then the role, then the Pages domain. If
  the 301 never shows, the rule it added is removed again and nothing else changes, so a host is
  never left unserved by the lookup while still reaching the renderer. The host's proxied CNAME
  stays; detach accepts either that CNAME or the placeholder A for a redirect row.
- **A verified redirect row is not re-checked.** S4's daily probe is for serving hosts; a redirect
  host has no admission to lose. It is excluded from S4's selection and from W1's reads.

## 2026-09-27 — Studio W2 S6 (the tool for imported rows): the calls made while building
- **Two model invariants, both lifted only inside `reclassifyImported()`.** A `reserved` row's
  status (W1's rule) and, new, an imported row's `source` and any row's `adopted_from_import_at`
  cannot change on a save anywhere else. W1's writers never touch either column, so nothing live
  changes; three of this track's own S3–S5 tests that set those columns on a saved row now write
  past the model, as the tool's result would look.
- **`list` probes but writes nothing.** "Whether a probe matches now" is a GET of each host's own
  `/api/tenant` through `DomainProbe::probe()`, which stamps nothing; `release` probes the same
  way before refusing a host that serves its own organisation.
- **The ledger keeps a released row's id without a foreign key**, so the record of a release
  outlives the row it released.

## 2026-09-29 — group_staff.assigned_by_user_id: recorded by the model, not the caller

- **What was wrong:** every teacher-to-class row written through TeachersController (store and update) stored
  `assigned_by_user_id` NULL. `Group::staff()` is `->using(GroupStaff::class)`, and in Laravel 12 attach() passes its extra
  attributes through `(new GroupStaff)->fill()` (InteractsWithPivotTable::castAttributes). `fill()` honours `$fillable`,
  and the column is deliberately not fillable, so the value passed at the call site was silently dropped. Production on
  2026-09-29: rows 7-10 (org 14) and 16 (org 17) are NULL; rows 3, 4, 11, 12 (org 14, user 10, 2026-09-08) carry it, so they
  were written by some path other than attach(), origin unknown (the custom pivot dates from 2026-08-29, before all of
  them). The old docblocks also said attach() never
  instantiates the model; with a `using` class it does, so model casts and creating hooks run.
- **Decision:** GroupStaff's `creating` hook sets `assigned_by_user_id = Auth::id()`. It stays out of `$fillable`, and
  callers no longer pass it, so no payload and no caller can choose the actor. With nobody signed in (console, seeder) it
  stays NULL rather than a guess. `masjid_id` is still passed explicitly on every attach.
- **Rejected:** adding the column to `$fillable` (it would become mass-assignable from any future fill of request data);
  a second query after attach() to write it (two writes, and every future caller must remember it).
- **Not done without the owner's yes:** backfilling the NULL rows on production (who assigned them is not recorded anywhere).
- Pinned by `TeacherProvisioningTest`: creating_a_teacher_records_the_signed_in_admin_as_who_assigned_each_class,
  a_class_added_on_edit_records_who_added_it_and_a_kept_class_keeps_its_original_assigner,
  a_row_written_with_nobody_signed_in_records_no_assigner_rather_than_a_guess.

## 2026-09-29 — Multi-org users, Phase 1: one teacher login, several schools
Decision: a Teacher can belong to several schools with ONE login and ONE password. The school
office's "add a teacher" door (`TeachersController::store`) is now create-or-attach: an email that
belongs to a live Teacher elsewhere attaches this school (a `masjid_user` row with `is_default`
derived under a lock on the user row, plus the classes and their per-class subjects) and touches
nothing on `users`; the teacher is sent a "you were added to {school}" notice
(`StaffAddedToOrganisation`, no token, no password link) instead of the set-password invite. The
teacher shell gets a school picker (rendered only with two or more memberships), its header is
read from the new tenant-bound `GET /api/teacher/masjids/{id}/school` (the school the server
bound, not `/teacher/user`'s default membership), the teacher tenant group is wrapped in
`EchoResolvedTenant`, and `TeacherApiService` takes part in the request epoch, compares the
`X-Tenant-Id` echo with the selection, and answers a 403 "outside memberships" by refetching
`/teacher/user`, rehydrating and reloading. No resolver behaviour changed: only its stale
comments (`ResolveMasjidTenant`, `TenantResolver::grantsFor`, `AuthController`). Design and
critic: `~/Developer/multi-org-users/DESIGN.md` (outside this repo); the owner's answers and the
critic fixes adopted are in that folder's `DECISIONS.md`.
Alternatives: consent-first attach (a pending `staff_membership_invites` row the teacher accepts) —
declined by the owner, "added straight away"; a second pivot or teacher-specific tenancy —
rejected, the resolver already binds a teacher by the route's `{masjid_id}`; making mixed roles
(teacher here, admin there) part of this slice — that is Phase 2, a separate project.
Rationale and the calls made while building:
- **The claim that a two-school teacher works with the gate shut is now a test, not a reading**
  (`TeacherMultiSchoolTest`, gate open and shut). So closing `tenancy.multi_membership` does not
  lock a teacher out the way it does a two-org admin; it only stops NEW attaches. The attach branch
  is gated on the flag only when the teacher already belongs somewhere (attaching a live login that
  belongs nowhere makes no cross-organisation grant). Removal never asks the flag: the rollback is
  "delete the extra memberships first", so that door must work with the gate shut.
- **What another school may see of a shared teacher (owner: added straight away).** For a Teacher
  with a live membership elsewhere (`User::belongsOutside`), every school that has them sees the
  STORED name, the email and the stored PHONE, and its OWN "last opened this school"; it never sees
  the newest token (a sign-in at ANY school) or another school's `last_seen_at`. The name is the
  stored one: `TeachersController::index` reads `users.name`, which the first school entered, so a
  school that adds an existing login sees that name in its list and NOT the one its own office typed
  (`TeacherAttachTest::a_shared_teacher_shows_every_school_the_phone_and_only_that_schools_last_opened`
  pins `Stored Name` and the phone in the list). The owner accepted seeing the other school's name.
  *(Changed 2026-09-29, round 2, by the owner's answer (a) below: this bullet first hid the stored
  phone and the last sign-in from both schools "symmetrically", because `users` carries no
  provenance. The phone is now shown, and the sign-in is replaced by the per-school "last opened".
  It also once said the second school's screens show only the name and email it typed; that was
  never true, and is corrected here and in the workspace folder's owner-answers note.)*
- **What the add itself discloses, stated exactly.** A successful add answers with the same message
  and the same data shape whether the address was new or existing, and the data is what the inviter
  typed (`TeacherAttachTest::the_create_and_attach_replies_are_indistinguishable_by_message_and_by_data`),
  so that reply alone cannot say. Three things can: (1) the list read above shows the stored name
  and phone; (2) with `tenancy.multi_membership` shut, which production is today, an address that is
  a live Teacher with a membership elsewhere is refused with "Adding an existing login to a second
  school is switched off." (`TeacherAttachTest::with_the_gate_shut_the_attach_is_refused_but_a_new_teacher_still_works`),
  which no other address is told, so the office learns the address is a live teacher at another
  school; (3) a login of another type, or a teacher a SuperAdmin trashed on purpose, gets
  `CANNOT_ADD` ("already has a Manara login that can't be added as a teacher"), and a teacher
  already at this school gets its own line
  (`TeacherAttachTest::adding_the_same_email_twice_at_one_school_is_refused_and_writes_nothing_more`).
  So the refusals distinguish the KIND of login. They are not "equal to today's `unique` rule",
  which says only that the address is taken. **The owner has accepted this (answer (b) below).**
- **A shared teacher's name and phone are read-only from any school** (update refuses a different
  name and ANY phone; the form shows the phone and never sends it back). Since answer (a) below the
  phone is visible to every school, so this is a rule about who may EDIT the shared record, no longer
  about who may know it. Classes stay editable. Resending the set-password link is refused for a shared teacher
  (`TeachersController::invite`) and for every Teacher via Team & Access (`TeamController::invite`,
  critic M1): completing that link deletes every token, ending the person's sessions at every school.
- **A trashed Teacher is restored only when they hold no `masjid_user` row** (critic H1): a row means
  a SuperAdmin trashed them on purpose (`UsersController::moveToTrash` leaves the rows). On restore
  the password is rotated, tokens deleted, name/phone overwritten and the set-password invite sent.
- **Email is trimmed and lowercased** for lookup (`LOWER(email)`, so a legacy mixed-case row is found on
  SQLite too) and on create (M2).
- **The switch is epoch abort + store reset, then a FULL RELOAD**, not the admin's in-place remount
  (design §5): `TeacherClass.vue` is ~4,000 lines of local state plus a `groupId` in its route, and
  none of it may survive into another school. The picker logic is pure (`core/helpers/teacherSchools.ts`)
  so `npm run test:spa` covers it; the component and layout wiring are pinned by source assertions
  because the suite has no Vue mount harness. Not exercised in a browser here.
- **The notice mail is sent synchronously, like `AccountAccessMail`** (the design said "queued";
  neither implements `ShouldQueue` and the suite asserts with `Mail::fake`), and a transport failure
  after commit is reported, not thrown: a completed attach must not turn into a 500 the office retries.
- ~~The teacher header now shows the teacher's name on wider screens.~~ **Reverted in the review fixes below**:
  it was a visible change for every single-school teacher that nobody asked for.
- **Not done here, on purpose:** lunch staff (Phase 1b: same branch table, `EchoResolvedTenant` on the
  lunch group, its picker); `OrganisationProvisioner`'s existing-admin attach (Studio's lane);
  `LunchStaffController`/`AdministratorsController` still hardcode `is_default = true`; whether Sanctum
  rejects a soft-deleted user's tokens is Unknown, needs investigation, so `destroy` deletes them itself.
- **Guard test:** `DualMembershipIsolationTest` whitelists `TeachersController` by name and pins that it
  checks the gate and the type (behaviour in `TeacherAttachTest`, plus a source tripwire). Its write
  sweep now recognises `firstOrCreate`, `updateOrCreate`, `->memberships()->create`,
  `ensureOwnerMembership` and the rest (critic M3), and that recogniser is itself pinned.
- Copy fixed: invite links last 7 days (`config/auth.php` `invites`), not "an hour" / "60 minutes".
- **A correction found by measuring:** `EchoResolvedTenant`'s comments (and `routes/admin.php`) said a
  refused request "unwinds past it and is rendered unstamped". It is not: Laravel's routing Pipeline
  renders the exception where it is thrown, so the 403 passes back through the echo and is stamped
  with the literal `unbound` (which the SPA already reads as "no echo"). Measured on the teacher
  group; the comments are corrected and the test asserts what matters, that a refusal never names a
  school. Nothing about the header's behaviour changed.

## 2026-09-29 — Multi-org users, Phase 1: review fixes (opus + sonnet lens findings, all confirmed)
Each fix has a test that fails without it (mutation-proved, see the build report).
- **`AdministratorsController::destroy` removes MasjidAdmins only.** It only checked for a `masjid_user` row, so a crafted
  request could remove a Teacher or LunchStaff there: their tokens are global, so it signed them out of every other school,
  left this school's `group_staff` rows behind, and never re-picked a default. A Teacher or LunchStaff is now a 422 that
  names their own screen (same rule as `TeamController::destroy`); an administrator's removal re-picks the default (lowest
  live school id, as `MasjidAdminsController::revokeMembership` does). There was no test for this door at all.
- **SuperAdmin delete and archive name every organisation** (DESIGN section 8, Phase 3 item 2 -- the OPEN GAP recorded
  above is now closed): `core/helpers/userRemoval.ts` builds the confirmation from `user.organisations`
  (`OrganisationAccess::forUsers`), saying "removes them from all N organisations: A, B" and pointing at the per-school
  screens. It is UI wording only: the endpoints still act on every school at once, by design; the SuperAdmin now knows.
- **The teacher shell's "can't open this school" and "wrong school" notices no longer outlive a sign-out.** They are
  module state and sign-out is an SPA navigation, so the notice's own remedy left the next sign-in stuck behind it.
  `removeAuth()` calls `resetTeacherSchoolGuard()` (notices and reload stamp); the shell clears the notices (not the
  stamp, which is what stops a reload loop) on mount. The guard's logic moved to `core/tenancy/teacherSchoolGuardCore.ts`
  with its browser dependencies injected, so it is now executed by `npm run test:spa` instead of regex-matched.
- **Sign-in lands a teacher in the school this browser last used** when the server still grants it
  (`signInSchoolId`), else the default. Before, a 401 sent a two-school teacher back to the default school.
- **Team & Access says "Shared login", not "Not signed in yet"**, for a teacher whose last sign-in is withheld: the
  payload now carries `shared`, and `last_sign_in_at` stays null as before (privacy unchanged).
  *(Superseded 2026-09-29, round 2: the column is now the per-school "Last opened this school" for everyone, so
  there is nothing withheld to explain; `shared` stays in the payload as the marker for the read-only name and
  phone, and the "Shared login" label is gone.)*
- **The teacher header prints no name** again (the unrequested change is reverted; if the owner wants it, it is one line).
- **The typed-name mitigation is partial, and said so in the UI.** `store()` returns what the inviter typed, but the next
  list read shows the stored name (the owner accepted seeing the other school's name). The add form now says, for every
  add and so without hinting at any address, that a person who already has a login shows under the name on it.
- **The attach lookup no longer locks a scan.** `LOWER(email)` cannot use `users_email_unique` on MySQL, so the old
  `... FOR UPDATE` took locks on every row it scanned, and a locking read that matches nothing takes gap locks that
  deadlock two concurrent adds of different new people. `User::scopeWhereEmailIs` uses plain equality on MySQL/MariaDB
  (utf8mb4_unicode_ci is already case-insensitive) and `LOWER()` only on SQLite; the lookup takes no lock, then the ONE
  row found is locked by key (the L1 lock is unchanged for an existing person). A deadlock victim (SQLSTATE 40001, or
  Laravel's `DeadlockException` from a nested transaction) is retried once, like the unique-index loser.
  **Unknown, needs investigation:** none of this could be observed on MySQL here (the suite is SQLite, which ignores
  locks); the SQL shape is pinned per driver, the retry is exercised by simulating the exception, and the rest is
  reasoned from InnoDB's documented behaviour. Verify on the staging MySQL before relying on it.
- **Test gaps closed** (mutants that survived): `resolveTeacher`'s Teacher-type filter, the three-school default re-pick
  (archived school, lowest id, non-default removal), both halves of the "already here" check, default derivation for a
  person with memberships but no default, the post-commit mail failure, the unique-index retry, the `email` rule,
  `belongsOutside`'s archived-school rule, the name trim, and that a two-office administrator is NOT treated as shared.
  `DualMembershipIsolationTest`'s write recogniser now also knows `MasjidUser::query()->create`, `withoutGlobalScopes()`
  hops, `firstOrNew`, `new MasjidUser`, and `memberships()->firstOrNew`; the sweep still reads controllers only (a write
  from `app/Services` or `app/Support` is outside it and belongs to its own lane).


## 2026-09-29 — Multi-org users, round 2: the owner's answers to the review's two questions
Recorded from the coordinating session's message of 2026-09-29. Answer (a) is the owner's own words as relayed; the
wording of question (b) is in the review and is not reproduced here, only the answer the coordinator reported.
- **(a) What a second school sees of a shared teacher.** Owner: "they should see when they last opened the specific
  school instead not necessarily the last time they logged in. Seeing their phone number I do not see as a problem."
  Done: the phone is shown to every school that has the teacher (Teachers list and edit read, Team & Access); the
  global last sign-in is replaced by a per-school "last opened this school" (next section). The refusal of name and
  phone EDITS by one school on a shared teacher is kept.
- **(b) That an office can infer an email teaches elsewhere.** Owner: "acceptable". The refusals on the add door
  distinguish the kind of login (see "What the add itself discloses, stated exactly" above), so an inviter can learn that
  an address is a live teacher at another school, or a login of another kind. That is accepted and no longer an open
  question; the uniform reply to a SUCCESSFUL add is kept anyway, since it costs nothing.

## 2026-09-29 — Multi-org users, round 2: "last opened this school" per organisation
Decision (owner, on the review's question about what a second school sees of a shared teacher): "they should see when
they last opened the specific school instead, not necessarily the last time they logged in." So the global last
sign-in (the newest personal access token, a sign-in at ANY school) is gone from Team & Access, and the Teachers list
gains the same column. Both now show `masjid_user.last_seen_at` for THIS school's membership, labelled "Last opened
this school"; `last_sign_in_at` is removed from the Team payload (the SPA was its only reader).
- **The column.** `masjid_user.last_seen_at`, nullable timestamp, no default, NO index, additive
  (`2026_10_01_120000_add_last_seen_at_to_masjid_user_table`; `down()` drops it and the stamps with it). Nothing is
  backfilled from tokens: a global sign-in is exactly the fact this replaces. **NULL means "no request has opened this
  school since the column shipped", not "never signed in"**, and the SPA says "Not opened yet". Hidden on `MasjidUser`
  (`$hidden`) so a serialised membership cannot carry one school's value into another school's payload; every screen
  reads it through `MembershipSeen::forOrganisation($masjidId)`, which filters by masjid once.
- **Who stamps it, and what it covers.** `ResolveMasjidTenant`, inline, after the tenant binds, and only on a response
  below 400. It covers the three staff realms that bind through a `masjid_user` grant: **MasjidAdmin** (`/api/admin/
  masjids/{id}/…`), **Teacher** (`/api/teacher/masjids/{id}/…`) and **LunchStaff** (`/api/lunch/masjids/{id}/…`).
  It does NOT cover: a **SuperAdmin** acting in a school (no membership, and the branch never sets one); a **family or
  student token** (a Contact is not a `User`; family routes use `family.tenant`, and a non-User principal is refused by
  this middleware); any request that binds no school (`/teacher/user`, `/lunch/user`, sign-in, and
  `TenantResolver::UNSCOPED_ADMIN_ROUTES`); a refused or failed request (401, 403, 404, 422, 5xx); and the ownership
  fallback (an unsaved `MasjidUser`, which must never be persisted by a read path: `touch()` returns for a row that
  does not exist).
- **How.** ONE conditional statement on the query builder, `UPDATE masjid_user SET last_seen_at = :now WHERE masjid_id
  = ? AND user_id = ? AND (last_seen_at IS NULL OR last_seen_at < :now - 5 min)`, not a read then a write and not a model
  save: no model event, observer, `updated_at` touch or audit write can fire on a membership row, and the database
  throttles a burst instead of a race between two reads. A request inside the window still issues the one statement,
  which matches nothing, so a query-counting test sees the same count either way. It runs after the controller has
  returned (no request-level transaction exists in this app, and the controllers' own have committed), and any
  throwable is caught and logged at WARNING (`Log::info` is invisible on production, where `LOG_LEVEL` is warning), so it
  can never fail or slow the caller.
- **Rejected.** Terminable middleware (the production transport never runs `terminate()`; that is how the /features
  counter was lost). A model `save()`/`touch()` (fires events, touches `updated_at`). Read-then-write throttling
  (a race, and two statements). An index (nothing searches or sorts by it). Keeping the global sign-in beside it (the
  point is that a school must not see another school's fact). A queue job (a failure mode for a timestamp).
- **`config/staging_scrub.php`: no entry, on purpose.** That config keeps login-time timestamps by omission
  (`contacts.last_login_at`, `users.*_verified_at`) and `last_seen_at` matches none of `StagingScrubCoverageTest`'s
  personal-data tokens, so it stays green; the timestamp identifies nobody once the row's email and name are scrubbed.
- **Known limits.** Unknown, needs investigation: the conditional UPDATE's behaviour under concurrent stamps on
  MySQL (the suite is SQLite, which has no row locks); on InnoDB it takes a row lock on one membership row for one
  statement. A person who last opened a school before this shipped shows "Not opened yet" until their next request.
  Only the URL's school is stamped, so a teacher who works in school A all day shows school B's old value: that is the
  point.

## 2026-09-28 — School side quest, W1-A quick wins (branch feat/school-w1-quick-wins)

- **Review fixes (2026-09-28, after the 5-lens review of this branch).** Each has a test that fails without it
  (mutation-proved on the droplet). Letters migration (`2026_10_01_100000`): the read that decides what to
  write now sits INSIDE the transaction with `lockForUpdate`, and the two case copies use `insertOrIgnore`,
  because `bin/deploy` checks out the new code (which accepts `a.upper`) before `migrate --force` with no
  maintenance mode: a teacher's tap in that gap used to make the plain insert collide and abort the deploy
  half-way, leaving new code live against an unconverted table. The row lock itself is a no-op on SQLite and
  is **Unknown, needs investigation** on MySQL until the staging run; the `insertOrIgnore` half is pinned.
  `down()` also refuses when the two cases differ on WHO marked them (it used to keep the capital's marker and
  delete the other; the docblock promised it refuses rather than lose data), and the note, mastered-date, bare-row and
  attribution guards are now each pinned. Plan files (`2026_10_01_110000`): `down()` refuses while any link exists.
  A bare `migrate:rollback` undoes the WHOLE batch in reverse, so it would have dropped every attachment and then
  failed on the letters migration above: **back W1 out only with `migrate:rollback --step=N`** (or ship B2's
  migration in its own deploy). Also pinned: reorder and new-link positions, `resource_ids: null` is a 422,
  plan-and-files atomicity, the Arabic stale-tab guard scope, the export's legacy label, the family summary's
  corrupt-polarity bucket. SPA: tapping the other case of the open letter switches the card (it used to close it;
  the open card is tracked by tile key), the family notes name the set in the portal's language, the plan Files
  hint no longer claims only staff can open a file the class already shares with families.

- **T-003.1 Positive always on top.** The skills list and both `by_skill` summaries order by
  `BehaviorSkill::scopeInPickerOrder` (positive, negative, other; then label) instead of
  `ORDER BY polarity`, which is alphabetical and put negatives first while the docblocks said the
  opposite. The teacher picker opens on the first positive skill and a skill a teacher adds is
  inserted in picker order (`core/helpers/behaviorSkills.ts`). Award logs stay newest-first (P1).
  Alternative: sort only in the Vue picker. Rejected: the summaries are read by parents and the
  office, and one server definition cannot drift between the three lists.
- **T-004.2 English letters by case.** Drill ids become `x.upper` / `x.lower` (52 drills), never
  `A` / `a`: prod `drill_id` is `utf8mb4_unicode_ci`, which is case-insensitive. `LetterCurriculum`
  gains `sets()` and `set()` (Arabic: `[]`, `null`); the payload gains `sets`, `set_totals` and a
  `set` on each drill; the teacher, office and family grids draw two runs (Capitals, Lower case)
  with a count each and /52 overall (L7). `classOverview` now filters its numerator by the stage's
  syllabus, which also fixes the latent Arabic over-count from letter-group drills. The data
  migration `2026_10_01_100000_split_english_letters_by_case` copies each existing mark to BOTH
  cases, keeping the original mastered date (owner question B2's default). **HELD FOR OWNER B2:
  this migration rewrites production rows (35 marks, 4 children) and `bin/deploy` runs migrations
  automatically, so W1 must not go to prod with it until the owner answers; if B2 is still open,
  ship W1 without this commit.** It also wants a run up, rolled back and up again on staging
  MySQL (the suite is SQLite and cannot see the collation). A stale tab posting a bare letter gets
  a "reload the page" 422 (`code: stale_page`). The school records export keeps `Drill id` and
  appends a readable `Letter` column. Alternative to copying into both cases: start every child
  fresh. Rejected as the default: it erases recorded progress; it stays the owner's call (B2).
- **T-004.1 Files under Activities.** A `lesson_plan_resources` join to the class's Files
  (`group_resources`); a plan owns no bytes and `lesson_plans` is not altered. Uploads reuse
  `POST /resources` (staff-only by default); attaching rides the plan save as `resource_ids`
  (`sometimes|array|max:10`, config `groups.lessons.max_attachments`). `sometimes` is the one
  exception to "every template field is nullable": absent keeps the plan's files (an old tab or
  the by-day PUT must not silently detach), `[]` clears them. Every id must belong to the plan's
  class AND school, or the whole request is a 422 before anything is written. `attachments` is in the
  teacher and office plan payloads and in no family payload; `lesson_plan_count` is staff-only.
  "Copy to week" copies files with the Activities rule (a day keeps its own activities and files,
  else takes the source's). No new teacher write verb (L1, L2, L3). Alternative: a `files` column
  or a per-plan upload endpoint. Rejected: a second copy of the type, size, private-disk and
  per-class-ceiling rules that Files already enforces, and a new teacher write verb. Known limit
  (L5): the private file disk has no backup, so a plan's files are one disk failure from gone;
  a separate backup item is open.
- **HELD FOR OWNER B1: negative behaviours always subtract.** (The owner answered B1 "negatives always
  subtract" on 2026-09-28, so this commit is approved; it stays the LAST commit and its message is reworded
  at integration.) Today a teacher-made negative skill ("Talking out of turn", polarity negative, default 1)
  is stored as +1 and every `SUM(points)` ADDED it while the picker showed "-1" (`TeacherClass.vue:4112`,
  `BehaviorAwardsController`). Every read aggregate (staff summary incl. `by_skill` and `by_polarity`, family
  summary, class totals) now uses `BehaviorAward::signedPointsSql()`: `CASE WHEN skill_polarity = 'negative'
  THEN -ABS(points) ELSE points END`. No stored row changes, and prod has 0 live negative awards, so no
  total moves today. It settles the two conventions the docblocks disagreed on (`DemoSchool.php` and
  `BehaviorAwardsController::totals`).
  **Review fix (2026-09-28):** the first version read every non-negative row as `ABS(points)`. That silently
  reversed a deliberate deduction: `StoreBehaviorAwardRequest` accepts an override from -max to +max and the
  controller snapshots it as given, so 'Kindness' given with -3 netted -3 and would have netted +3. Rows of
  every polarity except `negative` now read exactly as stored, so no positive-skill row moves whatever its
  sign; the count of such rows in prod is therefore not needed (it stays "Unknown, needs investigation" and
  does not matter). The award LOG rows now show the same signed figure (teacher, office and family screens),
  so a negative behaviour reads "-1" beside a total that went down. It must be settled before W4 (the weekly
  report and the buck ledger read these totals).

- 2026-09-28 (S8 hardening, from Point's review; must be on main before NAFIS, the first org with derivatives, uploads a logo):
  1. **Pixel and memory cap.** `LogoDerivatives::fromFile`, the one path both upload hooks and the regenerate route share, now reads the size with `getimagesize` and refuses BEFORE any decode. Two limits. The edge: `LogoDerivatives::MAX_EDGE = 8000`, the same constant `StoreStudioDraftLogoRequest`'s `dimensions` rule now reads, so the two cannot drift. And memory, because the edge cap alone does not protect production: GD holds a truecolor image at ~4 bytes a pixel, so 8000×8000 is ~256 MB to decode against `memory_limit = 128M` in `/etc/php/8.3/fpm/php.ini`. The budget compares an estimate with `memory_limit − memory_get_usage(true)` (`-1` is unlimited). **The 5 bytes a pixel first used here, and the 4,300 px worked example, were wrong (too low, so unsafe); see the 2026-09-28 repair below for the measured figure and the corrected budget.** `LogoDerivatives::$headroomBytes` is the test seam. A refusal is a `LogoTooLarge` (a `ValidationException`): the route answers the legacy 422 `{status:'failed', data:{logo:['The logo is too large to make the icons from (W×H). Upload a smaller logo, at most N pixels on each side.']}}` and writes nothing; an upload hook logs ONE warning with the org id, width, height and which limit, skips, and the upload answers exactly as before. The size is never turned into "no logo".
  2. **403 before 422.** The SuperAdmin check moved from the controller into `RegenerateBrandAssetsRequest::authorize()`, so a non-super never sees validation output (the pattern `SetFormsCardAccountRequest` documents). `failedAuthorization()` throws the same `HttpException(403)` the controller's `abort()` did, so the body is byte-identical in every environment: `{status:'error', message}` with the app's renderer sanitising the message outside debug ("Request failed.").
  3. **One run per organisation.** `BrandAssets::regenerate` takes `Cache::lock('brand-assets:regenerate:{masjid_id}', 60)` with `block(3)` before it reads the previous rows. The deletion of the old rows was already after the transaction, but not after an OUTER one (`DB::afterCommit` was not used), so it now is: the old rows, the renderer purge, the log line and the lock release all run in one `DB::afterCommit` callback, which runs at once when no transaction is open (a request) and at the outer commit when one is; a `DB::afterRollBack` callback gives the lock back and deletes the files of the rolled-back new rows. A throw before then releases the lock in `regenerate`. Wait timed out: the route answers 409 `{status:'error', message:'The brand images are already being made. Try again in a moment.'}` (built in the controller, not an `HttpException`, whose message the renderer would replace); a hook skips with a warning and the upload succeeds. `BrandAssets::$lockWaitSeconds` is the test seam. The renderer purge failing to schedule is now a warning instead of a throw out of somebody's commit.
  4. **Lifecycle tests** pin: old rows and files survive inside a caller's transaction and go only at its commit; an outer rollback keeps the old set and leaves no new files and no lock; a failing `$media->delete()` on an old row was already contained (warning, new set stays, 200), now pinned; and `MasjidsController::update`'s hook failure leaves that response unchanged.
  Not run locally (no PHP): `php -l` on every touched file, and real PHP 8.3 (`getimagesize`, `finfo`) on the crafted 20000×20000 header-only PNG (45 bytes; read as 20000×20000, `image/png`), which the logo rules' mime sniffing accepts. PHPUnit was not run.

- 2026-09-28 (S8 hardening, repair round after Point's verification; supersedes the memory figures above):
  1. **The memory budget was too low, measured.** Run under real PHP 8.3 with bundled GD, memory_limit 128M, using the actual spatie/image 3.9.5 chain (`loadFile`, `fit`, `resizeCanvas`, `background`, `save`, three times), the peak was **8.19 bytes a pixel for an RGBA PNG, 7.19 for an RGB PNG and 4.19 for a JPEG**, because GD's PNG reader holds a raw row buffer as well as the image. The budget allowed 5, so an 86 KB 4400×4400 RGBA PNG passed (100.3 MB against 106 MB left) and then died in `imagecreatefromstring`. Two costs were also not counted: the whole file, which spatie holds as a string while it decodes (up to 25 MB on the upload rules), and `autoRotate`'s `imagerotate`, a second full-size copy for a JPEG with EXIF Orientation 3 to 8 (not measured; about 8.4 by the same arithmetic). **New budget: `width × height × 10 + filesize + 8 MiB`** against the headroom. One factor for both types, the worst measured case plus a margin, so it does not depend on the exif extension; `the_memory_budget_is_ten_bytes_a_pixel_plus_the_file_plus_eight_megabytes` pins it, and `a_4400_pixel_square_that_the_old_five_byte_budget_took_is_refused_at_128m` pins the case that got through. Checked locally (estimate, not the droplet): with 30 MB in use the largest square the new budget takes is about **3,070 px**, and the real derive chain on that size peaked at 104 MB (RGBA PNG), 95 MB (RGB PNG) and 68 MB (JPEG) of the 128 MB, including the 30 MB. So the honest figure is **about 3,000 px square with ~30 MB in use, not 4,300**, and the memory check, not the 8000 edge, is what binds on production.
     - Unknown, needs investigation: the droplet. The peaks above are from the local `phptest-gd` image (PHP 8.3, bundled GD), not php-fpm on the droplet, and I did not ssh. Whether production's php8.3-gd uses bundled or system libgd is also unknown: with system libgd, GD's allocations do not count against `memory_limit`, and the risk becomes RSS and the OOM killer rather than a PHP fatal; the pixel budget is still the right guard. (Resolved 2026-09-28: system libgd 2.3.3, read from the droplet; see the third repair round.)
  2. **Studio provisioning was unguarded.** `LogoDerivatives::generate` (called from `StudioProvisioning`) decoded with no check, and the Studio upload admits 8000×8000 up to 8 MB. The check moved from `fromFile` into `derive()`: after the logo is written to the temporary folder and before anything decodes it, `getimagesize` and `filesize` feed `assertFits`, so `generate` and `fromFile` share it. A refusal costs one copy of the file and no decode, and the folder is deleted. `StudioProvisioning::provision` turns `LogoTooLarge` into a 422 `{status:'failed', data:{logo:[sentence]}}` (the brand gate's shape and key) before the transaction: no organisation, no rows, the draft keeps its logo. Tests in `StudioProvisionLogoTest`.
  3. **Lock release edge cases.** `regenerate`'s catch now releases the lock only until `run()` has registered the commit callback (a `$handedOver` flag), so a late throw cannot release it while the old rows still wait on an outer commit. Documented, not fixed: Laravel 12.64's transactions manager runs rollback callbacks only along the current transaction's parent chain, so a savepoint that committed and whose parent then rolls back to level 0 loses its rollback callback: the lock lives out its 60 s TTL and the new files are strays. Neither production caller runs inside a transaction (`/details` calls it after `DB::commit`, `update` opens none), so the TTL is the fallback.
  4. **Which cache store holds the lock?** (Resolved 2026-09-28: `CACHE_STORE=database`, read from the droplet; see the third repair round.) Unknown, needs investigation, when written. `config/cache.php` defaults to `database` and `.env.example` says `database`; phpunit uses `array`, so the tests prove in-process locking only. `array` would lock nothing across php-fpm workers; `database`, `redis` and `file` are fine. With the `database` store and no `DB_CACHE_LOCK_CONNECTION`, a regenerate run inside an outer transaction inserts the lock row inside it, and a competing insert waits on InnoDB's row lock (up to `innodb_lock_wait_timeout`, 50 s), not on `block(3)`. Not a problem for today's callers (none open a transaction). Before merge, on the droplet: `grep '^CACHE_STORE' .env` (read-only). If it is `array`, set it to `database` or `redis`; if `database` and a caller ever wraps regenerate in a transaction, set `DB_CACHE_LOCK_CONNECTION` to a second connection with the same credentials. No config changed here.
  5. **Not run.** PHPUnit has not run on this commit, on SQLite or MySQL (no PHP locally). What did run, in real PHP 8.3: `php -l` on every touched file, the derive-peak measurements above, and `assertFits` exercised through reflection against crafted header-only PNGs (20000×20000 and 8001×10 refused on the edge, 4400×4400 at 106 MB refused with a 3200 px hint, 3000×3000 at 106 MB accepted, 8000×8000 with the real 128M limit refused). To do on the CI droplet before merging: the full `BrandAssetRegenerationTest`, `StudioProvisionLogoTest`, and the suites that touch `/details` and `MasjidsController::update`, on SQLite and MySQL.

- 2026-09-28 (S8 hardening, second repair round; supersedes the factor of 10 above):
  1. **The factor of 10 was still not the worst case, measured.** Through the real `LogoDerivatives::fromFile` at `memory_limit` 128M (PHP 8.3, bundled GD, exif extension added in a throwaway container): a 3090×3090 JPEG with EXIF Orientation 6 peaked at **10.67 bytes a pixel** (101.9 MB above base); Orientations 3, 5 and 8 and a progressive JPEG gave 10.64 to 10.67. The check took all of them (needed 104.0 MB, headroom 104.9 MB) and they survived only on the 8 MiB fixed allowance: `memory_get_peak_usage(true)` reached exactly 134217728, the limit, so one more 2 MB Zend chunk would have been the fatal after the upload committed. The earlier "about 8.4, by arithmetic, not measured" for a rotated JPEG was wrong. The per-type figures through the whole chain are also higher than the 8.19 written above: RGBA PNG about 9.36, gray+alpha 9.35, 16-bit RGBA 9.36; palette PNG 2.38; plain JPEG 5.41; noisy RGBA PNG 13.2 counting its own file, which the budget charges separately.
     **New budget: `width × height × 12 + filesize + 8 MiB`.** 12 is the worst measured case (10.7) plus a margin of about 13 MB at 3090 px, one number for every type, still independent of the exif extension. What it means at 128M: about **3,200 px square with nothing in use, about 2,800 with ~30 MB in use** (was 3,000 in the note above). The 3090 px rotated JPEG that survived by a chunk is now refused (needs 117.3 MB against 104.9 MB). `the_memory_budget_is_twelve_bytes_a_pixel_plus_the_file_plus_eight_megabytes` pins the factor, `a_3090_pixel_square_that_the_ten_byte_budget_took_is_refused_at_128m` pins the measured case, and the hint in the 4400 px test is now 2900 (was 3200). Comments in `LogoDerivatives`, `LogoTooLarge` and `docs/manara-studio-w2.md` carry the measured numbers.
  2. **Studio upload vs provisioning limits disagree. (Closed in the third repair round below: the upload now runs the same check.)** `StoreStudioDraftLogoRequest` still accepts up to 8000×8000 (its `dimensions` rule reads `LogoDerivatives::MAX_EDGE`), while provisioning refuses a logo over roughly 2,800 to 3,200 px with a 422 keyed `logo` at the final step. Left as it is on purpose: running the memory estimate at upload time would check headroom on a request that does not decode, and the answer would differ from the one at provisioning; a clear 422 at the end beats the old fatal. The request's stale comment (it said the check was in `fromFile`) is fixed and the gap is written into docs/manara-studio-w2.md. If wizard users hit it, the follow-up is to run `assertFits` on the uploaded file in the draft-logo controller.
  3. **Nested savepoint rollback leaves the lock to its TTL.** Accepted as documented (BrandAssets docblock, item 3 of the repair above): no production caller runs `regenerate` in a transaction. If a nested caller appears, register the rollback callback on every pending level or run the replace outside the caller's transaction.
  4. **Not run.** PHPUnit has not run on this commit (no PHP locally). The arithmetic in the tests was checked by hand: the hint for 10 MiB left is 418 px (said 400), for 106 MiB 2,926 (2900), for 104.9 MiB 2,909 (2900).
  Unknown, needs investigation, unchanged: whether production's php8.3-gd is bundled GD or system libgd (with system libgd its allocations bypass `memory_limit`), and production's `CACHE_STORE`. (Both read from the droplet on 2026-09-28; see the third repair round below.)

- 2026-09-28 (S8 hardening, third repair round; supersedes the 8 MiB allowance above):
  1. **The fixed allowance was too low, measured.** Through the real `LogoDerivatives::fromFile` under bundled GD (`phptest-gd`), the warm derive peak for a logo of any size up to about 1200x630 is about 15.5 MB (measured again here: 14.8 MiB for a 48x48 and for a 1200x630 RGBA PNG), about five 1200x630 truecolor canvases of about 3 MB each plus the 180x180 and 48x48. The 8 MiB counted roughly one canvas and undercounted it, so a small logo could be accepted with too little left. **`FIXED_ALLOWANCE_BYTES` is now 20 MiB**, the measured floor plus margin, and the budget is **`width × height × 12 + file size + 20 MiB`**, everywhere (`LogoDerivatives`, `docs/manara-studio-w2.md`, the tests). The arithmetic lives once, in `LogoDerivatives::estimateBytes`. What it means at 128M: about 3,000 px square with nothing in use, about 2,600 with ~30 MB in use. The hints in the tests moved with it (40 MiB left gives 1300 for 3000x3000, 106 MiB gives 2700, 104.9 MiB gives 2700).
  2. **Production facts, read from the droplet on 2026-09-28.** The GD is Ubuntu's SYSTEM libgd: `gd_info` 'GD Version' is `2.3.3`, from libgd3 2.3.3-9ubuntu5 and php8.3-gd 8.3.6-0ubuntu0.24.04.11, not PHP's bundled GD. GD's pixel buffers and canvases are therefore malloc'd OUTSIDE `memory_limit` and invisible to `memory_get_usage`. On production the check is a conservative ceiling on real RAM, not a guard against the `memory_limit` fatal: the droplet has 1967 MB total, about 1277 MB available, 12 PHP-FPM children and `memory_limit` 128M, and a logo above roughly 2,900 px square is refused, so one decode stays near 100 MB. Under BUNDLED GD (`phptest-gd`) it is the precise guard against the fatal. The doc comments on `LogoDerivatives::assertFits` and the S8 section of `docs/manara-studio-w2.md` say so. This answers the two "unknown" items above. **`CACHE_STORE` is `database`**, so `Cache::lock` is a database lock (the `cache_locks` table, `database/migrations/0001_01_01_000001_create_cache_table.php`) that spans PHP-FPM workers: the regeneration lock does serialise across workers. The caveat above about a caller that opens a transaction still stands (the lock row would be inserted inside it); no production caller does.
  3. **A measuring test, not only a formula pin.** `BrandAssetRegenerationTest::the_estimate_is_at_least_what_the_real_derive_chain_peaks_at` runs the real `fromFile` on a 48x48 PNG, a 1200x630 RGBA PNG, a 2000x2000 RGBA PNG and a 2000x2000 JPEG with EXIF Orientation 6 (each made in the test with GD; the JPEG's APP1 segment is built by hand), resets with `memory_reset_peak_usage()` and asserts the peak delta is at most `estimateBytes`. It is skipped, with the reason, unless `gd_info()['GD Version']` contains 'bundled' (under system libgd the assertion would pass whatever the estimate said), and its rotated-JPEG case is skipped unless exif is loaded. Measured, `phptest-gd`, PHP 8.3, delta against estimate: 48x48 14.8 MiB against 20.0; 1200x630 14.8 against 28.7; 2000x2000 RGBA PNG 30.9 against 65.8; 2000x2000 rotated JPEG 31.4 against 65.8 (16.2 without exif, so autoRotate's copy is real); also 3000x3000 RGBA PNG 69.6 against 123.0. The exif case was run in a throwaway container with the extension built in; the standard `phptest-gd` has none, so there it skips.
  4. **Tests no longer depend on the process's memory.** `StudioDraftFixtures::setUpStudio` (which `ProvisionsStudioDrafts::setUpProvisioning` and the draft tests call) sets `LogoDerivatives::$headroomBytes` to 512 MiB and resets it to null with `beforeApplicationDestroyed`. `StudioProvisionLogoTest::the_real_memory_path_reads_the_ini_limit_minus_what_the_process_holds` keeps the real `memory_limit` minus usage path, with a limit the test sets (10 MiB above what it holds, then 512 MiB) and restores.
  5. **The draft-logo upload runs the same check** (Point's requirement). `LogoDerivatives::assertFits` is public and `StoreStudioDraftLogoRequest::after()` calls it on the uploaded file once the type and size rules have passed. A `LogoTooLarge` becomes an error on `logo` with its own sentence, so the answer is the legacy 422 `{status:'failed', data:{logo:[sentence]}}` and nothing is stored (the request fails before the controller opens its transaction). Provisioning keeps its own run, since the headroom there can differ. **The upload's `dimensions` rule lost its maximum**: with it, a 20000x20000 logo got Laravel's generic sentence before this check ran, not provisioning's. The maximum edge is `LogoDerivatives::MAX_EDGE`, checked by `assertFits`, and `StudioSpaSourceTest` now reads that constant for the SPA's `LOGO_LARGEST_EDGE` pin. Tests in `StudioDraftLogoTest`: a crafted 20000x20000 header (valid signature, IHDR with its CRC, IEND) gets the sentence and is not stored; a 3000x3000 header under a 40 MiB seam gets the memory sentence, keeps the draft's earlier logo, and is taken once the room is given.
  6. **Not done, on purpose.** No log line for a refused upload: the user sees the 422, and the hooks log only because they skip silently. Not run: `StudioProvisionLogoTest` and the suites on SQLite versus MySQL on the CI droplet.

## 2026-09-29: Class story engagement — reactions, read receipts, the reaction digest (W2)

**Asked (owner):** reactions on class stories, read receipts on class stories, and notifications for reactions
(the school-list items T-002.1, T-002.3, T-002.2). Built on branch `feat/school-w2-stories` off `19c0f4d1`.

**Reversed rule.** 2026-09-21 said reactions notify nobody. "Add notifications for reactions" is the owner's own
instruction, so the rule is reversed; what survives is its reason (a push per 👍 buries the replies). A tap still
dispatches nothing; the AUTHOR gets ONE content-free email per class per hour at most (`groups:notify-reactions`).
`GroupMessageReactionsTest` still pins "a tap sends nothing" (at the tap; the author hears in the digest).

**Owner-driven changes to the plan:**
- **No staff push.** The staff app is parked (S1), so reaction notifications are the email digest only. When the
  staff app resumes, push plugs into `GroupPushChannel`.
- **Read receipts are built but OFF by default** (`groups.story_reads.enabled`, env `GROUP_STORY_READS_ENABLED`).
  The privacy notice's ar / ur / ps / fa-AF (and es) copy is machine-drafted and needs a human review BEFORE any
  read is recorded in production. The switch gates recording, the parent-facing notice and the staff "Seen by"
  line together, from one config value (`meta.story_reads.enabled`), so they go live together.

**Decisions taken here (each defensible, none asked):**
1. **Shared `App\Support\Reactions`** for the four keys and the naming rule, used by message and story reactions.
2. **Reactions gate = the FEED read gate**, not the media gate: a reaction is to the words.
3. **Audience for "Seen by n of m"** = `GroupAudience::storyGuardianContacts()` (consented, current, live login), and
   the story email fan-out now reads the same method, so the two cannot drift. The count is per parent (guardian
   contact), not per family. `unreachable_count` reports consented, current parents with no portal login.
4. **Receipts omitted, not zeroed, while off**: a staff screen never shows "0 of 7" for a receipt nobody keeps.
5. **The digest re-checks both ends at send time** (reactor in the command, recipient in the job) and claims rows
   with an `UPDATE ... WHERE notified_at IS NULL` before sending: at most once, crash loses rather than repeats.
6. **Settle window 10 minutes** (`groups.reactions.settle_minutes`): an ESTIMATE, there is no data (0 reactions).
7. **`SendGroupNotificationJob` fix**: the sign-in URL follows the recipient's realm (`/auth/sign-in` for staff),
   not always the family portal. Pre-existing bug, found in the recon and fixed here.

**Counted lists:** teacher write verbs +2 (story reaction PUT/DELETE); family write verbs +3 (story reaction
PUT/DELETE and `POST .../posts/seen`). `TeacherMultiSchoolTest`'s sweep and `MemberAccountDeletionCoverageTest` were
updated on purpose. `TenantScopingCoverageTest` needed no edit: it discovers models by reflection, and the new
cross-tenant tests in `GroupPostReactionsTest` and `GroupPostReadsTest` satisfy its discovery. No permission was added.

**Review round (2026-09-29, after the five-lens review of `ada6660c`):**
8. **Reads are reported only once the Story section is drawn.** The load chain sets the posts mid-way, while the
   page is a spinner, so reporting on `posts` alone recorded fifteen reads for a parent who then hit an error or left.
   `watchStoriesSeen` (`familyClassRun.ts`) now also waits for `loading` to end without an `error`, and for the next
   render; SPA tests drive it with real refs.
9. **The notice says "parents" and "when"**, because staff see each guardian by name with a first-seen time, not "families".
   The English source and the five machine drafts were re-derived from it; all five still need the human review.
10. **`meta.story_reads` is one shape**: `{enabled}` in the family payload too (it was a bare bool there, an object in the
   staff payloads). The portal reads `.enabled`; an old cached portal reads a missing bool as off, which fails safe.
11. **"Not tracked before <date>" instead of "Seen by 0 of N"** for a story older than recording with no read on it.
   The day is `groups.story_reads.since` if the owner sets it, else the school's earliest recorded read (no setting
   needed); until either exists nothing is marked. A read that was recorded on an old story still shows.
12. **An archived staff member is nobody** to the digest (author and reactor), as a trashed guardian already was.
13. **`seen_by` order is pinned** on both keys (`first_seen_at`, then `id`), each with a mutation proof on SQLite.
   Unknown, needs investigation: that MySQL breaks the tie the same way, since the tie-break test ran on SQLite only.

**Held for the owner:** the human review of the five machine-drafted `story_seen_notice` strings
(`familyI18n.ts` ar, `locales/{es,ur,ps,fa-AF}.ts`); then set `GROUP_STORY_READS_ENABLED=true` (and, if wanted,
`GROUP_STORY_READS_SINCE` to that day). Also the settle window
(an estimate). BISS reach is 0 today (no consented guardians, staff or family logins there).

**Migrations (all additive, deploy-order safe):** `2026_10_02_100000_create_group_post_reactions_table`,
`2026_10_02_110000_create_group_post_reads_table`, `2026_10_02_120000_add_notified_at_to_group_reaction_tables`. New
code reads none of the new tables from a request that runs before the migration, except the story list, which reads
`group_post_reactions`: deploy after hours or run the migration first (the deploy script checks out PHP before it
migrates). Run each up, `migrate:rollback --step=3`, up again on staging MySQL before production.

## 2026-09-29 — Sign-in matches the typed address EXACTLY (fix/login-address-exact-match, off main 19c0f4d1)
Decision: every lookup that turns a TYPED email address into a contact, a code row or a login keeps its SQL (the index still narrows the search) and then filters the candidates in PHP to the address that was typed, byte for byte after `mb_strtolower(trim())`, BEFORE any rule counts or trusts them. The comparison is `ContactIdentity::sameAddress()` / `keepExactMatches()` (extended, not a second class). The address is also normalised at the door, as defence in depth: `ContactIdentity::submittedAddress()`.

**The production read this rests on** (owner's session, read-only, 2026-09-29): `contacts.login_email` and `contacts.email` are `utf8mb4_unicode_ci`, so `'victim@gmail.com' = 'victim@gmaíl.com'` is TRUE there (accents, and expansions such as ß = ss). Older comments in this repo said production is `utf8mb4_bin` (case- and byte-exact); for these columns that was wrong, and the comments in `FamilyLoginService`, `FamilyAccessService`, `MemberAccountDeletion` and `ContactIdentity` now say so. `app_signup_codes.email` and `users.email` were NOT read by this branch; both are treated as `utf8mb4_unicode_ci` (the migrations name no collation and `config/database.php` defaults `DB_COLLATION` to it; `User::scopeWhereEmailIs` already says `users.email` is). See ASSUMPTIONS 25-26.

**The defect, as it ran.** `MemberSignupService::deliver()` mails the code to the TYPED address, and `resolveContact()` looked the contact up with `LOWER(login_email) = ?`. Whoever owned a look-alike domain got a code, and redeeming it linked them to the victim's contact; with a password in the request that SET the victim's password and ended their sessions. `contacts.password` is the one password of both realms (`FamilyPasswordService::set()` is what the app's redeem calls), so the attacker's password then opened the parent portal at the victim's own address wherever the office had enabled a family login: children's data. A second door ran the other way: `matchLiveCode()` compared `app_signup_codes.email` with `where('email', ...)`, so a code issued to the look-alike redeemed at the VICTIM's exact address. And the public `/account-deletion` page mailed a code to the typed address and then deleted the account matching it.

**Sites fixed, and how each is filtered**
1. `MemberSignupService::resolveContact` (the `login_email` arm and the `email` arm), `::matchLiveCode` (the reverse door; the wrong-guess charge now reaches only exact rows), `::mayUsePassword` (same comparison, was already exact).
2. `FamilyLoginService::resolveContact` (shared by the code door and, through `FamilyPasswordService`, the password door; the code door mails the stored address so it was never exposed, the password door was).
3. `FamilyAccessService::currentHolderOf`: a confirmed `reassign_address` released the address from a look-alike holder. The unique index still refuses the second address, and `enable()` already turns that into its ordinary "just taken" 422.
4. `MemberAccountDeletion::deleteByAddress`.
5. `TeachersController::createOrAttach` (`User::scopeWhereEmailIs`): attached the REAL teacher's login for a look-alike an admin typed. A candidate that matched only through the collation is refused with the flow's existing `CANNOT_ADD` answer; the alternative, creating a user at that address, collides on `users.email` and would be a 500.

"Two rows is no row" now counts exact matches only, and the `limit(2)` before the filter was dropped (a limit taken first can cut the exact row off behind look-alikes, or leave one exact row where two hold the address). A look-alike neither makes a real address ambiguous nor stands in for it.

**The create path.** After the fix a look-alike resolves to no contact, so a member redeem tries to CREATE one at an address the `(masjid_id, login_email)` unique index calls equal to somebody's (production's index is collation-equal too). `MemberSignupService::consume` catches `UniqueConstraintViolationException` around the insert and answers as any refused redeem: `null` (the controller's one 410, byte for byte), nothing created, no password set, the code SPENT (the transaction commits the `consumed_at` that opened it), and one `Log::warning` with the masjid id and no address. The same catch covers a soft-deleted contact holding the address, which the index pins and `resolveContact()` never returns: that collision would have been a 500 as well. What this does not hide: someone who proved a mailbox learns that the address cannot be used (a 410 where any other new address is created). That is inherent in refusing, and says nothing about whose it is.

**Normalised at the door** (the coordinator's addition). On the member and family code-request, redeem and password doors, the public account-deletion page and the office's enable-family-login form, a non-ASCII DOMAIN is converted with `idn_to_ascii(..., IDNA_DEFAULT | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)`, so `gmaíl.com` becomes `xn--…` and can never equal a stored ASCII domain; a non-ASCII LOCAL part is refused with the door's ordinary "not an email address" sentence (an `ascii` rule; message = Laravel's own `email` text). Choices: (a) an address that is already ASCII is not touched, and no IDNA rules are applied to an ASCII domain, so nothing that signs in today is newly refused; (b) it is done in the `FormRequest` (`NormalisesSubmittedAddress`) AND in the services (`ContactIdentity::submittedAddress` returns null, which every service already treats as a silent refusal); (c) `idn_to_ascii` comes from ext-intl or from `symfony/polyfill-intl-idn` (composer.lock `packages`, v1.38.1; composer.json has no `ext-intl`); with neither, `submittedAddress` returns null and sign-in refuses, it does not fatal. A consequence to know: a contact whose stored address IS non-ASCII can no longer be reached by typing it (the typed form is converted). Production stores none today (ASSUMPTIONS 28 has the read-only count to run before ship).

**User.php:229 (staff `whereEmailIs`), the decision.** Fixed at its one caller (TeachersController, above), because that caller trusts the row it finds (attaches it, restores it) on the strength of a typed address. Checked and left alone: `AccountAccessService::sendResetLink` (`User::where('email', $typed)`) MAILS `$user->email`, the STORED address, never the typed one, so a look-alike sends the real owner a reset link and nobody else anything; the reset/invite redemption (`Password::broker()->reset`) needs the emailed token, keyed to the stored address; `AuthController::login` matches by typed address but then needs the password; `TwoFactorReset` (console) and `TwoFactorController` name a user by id or confirm the address; `UsersController::store` (admin-only, restores an archived user at a typed address, mails nothing) is admin authorship, not sign-in. No staff path was found that mails the typed address after a collation-equal match.

**Tests** (they cannot run here: no PHP). SQLite compares bytes, so `tests/Support/FoldsAccentsLikeUnicodeCi` gives it production's collation: `LOWER()` overridden to fold accents (the brief's method), plus, where a query compares without `LOWER`, the column or the unique index re-collated. The other person's address is stored ACCENTED, the plain one submitted, and each test asserts the raw query returns the row before asserting the door refuses it. Restored in `tearDown` (the in-memory PDO outlives a test). One test file per surface: `MemberSignInLookAlikeAddressTest`, `FamilySignInLookAlikeAddressTest`, `FamilyAddressHolderLookAlikeTest`, `AccountDeletionLookAlikeAddressTest`, `TeacherAttachLookAlikeAddressTest`, `SignInAddressAtTheDoorTest`, and unit cases in `tests/Unit/ContactIdentityAddressTest`. `the_password_door_refuses_a_look_alike_address` (member) is a pin, not a regression test: that door already compared the address exactly.

Alternatives considered: `EmailAddress::same()` as a new class (the brief allows it; ContactIdentity is the existing identity helper and the brief says to extend it); making `matchLiveCode` use `LOWER(email)` so the LOWER override would reach it (defeats the `app_signup_codes_lookup_index` on an unauthenticated table, so PHP re-checks instead); a hard `utf8mb4_bin` migration on the address columns (a production schema change, and it would not cover the next column).

Not run: `php -l` on any file, PHPUnit. There is no PHP on this machine; the CI droplet must run the six new test files and the suites for the touched doors (`MemberPasswordSignInTest`, `AccountDeletionPageTest`, `MemberAccountDeletionTest`, `FamilyLoginCodeTest`, `FamilyPasswordTest`, `FamilyLoginEnablementTest`, `FamilyLoginLifecycleTest`, `TeacherAttachTest`, `ContactIdentityTest`) on SQLite and MySQL. Nothing ships without the owner's yes.

## 2026-09-29 — School wave W3: grades and curriculum (T-001.1 to T-001.5; branch feat/school-w3-grades)

Owner answers this wave builds on (2026-09-28, side-quest `DECISIONS.md`): **B3 yes** (seed the Subjects
list on prod, guarded and reversible), **weights per type with a per-assignment override, relative,
renormalised over the types with scored work**. Everything below is a choice this wave made inside those
answers; each carries its alternative.

- **W3-1 (REVISED at review). A weight is what a TYPE of work is worth in the average; a piece with its own weight
  is a slot of its own.** The owner's words were "weights per type ... relative and renormalised over the types
  that have scored work", and the first build read them per piece (`sum(w_i * pct_i) / sum(w_i)` over every
  piece, `w_i` the piece's override else its type's weight). That made a type's weight a per-piece multiplier: with
  Test 40 and Homework 10 a child with one Test at 90% and five Homework at 100% read 95.6%, ten Homework put
  Homework at 71% of the grade against the 20% the teacher typed, and the parent's by-type rows ("Test counts 40")
  could not reproduce the headline. The shipped rule (`GradeRecord::weighted`) is now: each type with counted work is
  ONE slot worth its class weight, however many pieces are in it; the pieces in a slot are POOLED (points earned
  over points possible, exactly as the by-type row prints them, so the headline is the sum of `weight x percent`
  over the by-type rows divided by the sum of their weights); slots are renormalised over the types that have
  counted work, so a type nobody has been marked on drags nothing down and weights need not add to 100. Test 40 and
  Homework 10 with one Test at 90% and any number of Homework at 100% is 92.0. **The per-assignment override** ("each
  assignment inherits its type's weight and may override it") **makes that piece a slot of its own worth exactly the
  number typed**, beside its type's pool, and the piece leaves the pool (the by-type row is the type's slot, so it
  excludes overridden pieces). That is my reading of "override" (alternative: a relative weight WITHIN its type's
  pool, which changes nothing when the type has one piece, so the override would silently do nothing in the
  commonest case). An override of 0 keeps a piece out of the figure and still counts it in `points_pieces`. A piece
  with neither an override nor a type is left out and counted in `untyped_excluded`. Points work gets a percentage;
  levels a weighted mean LEVEL over the same slots (never a percentage); simple marks are never averaged. No
  weights means every average is unchanged. All five types are set together or cleared together. A per-work
  weight is refused (422) unless the class is weighted, and clearing removes every per-work weight in the class in
  the same transaction, so a dormant override cannot revive. **Owner to confirm** when he sees the Weights panel
  (it explains "Tests make up four fifths ... whether a child has done one Homework or ten"). If he meant a per-piece
  weight after all it is a change inside `GradeRecord::weighted`, the Weights-panel copy and
  `GradebookWeightingTest`, and no schema.
- **W3-2. `LessonPlan::subjectKeyFor` keeps its own key.** The plan named it as a second caller of
  `SubjectKey`. It is not: `lesson_plans.subject_key` carries a unique index with live rows, so changing how it
  is derived would leave every existing plan under its old key, the by-day save would miss the plan it means
  and add a duplicate, and re-keying needs a data migration with a collision pre-flight on rows teachers wrote.
  `SubjectKey` is used for `class_assignments.subject_key`, `school_subjects.name_key`, the per-subject blocks
  and the fence, which is everything new. Alternative: migrate lesson-plan keys. Rejected: risk on live data for
  no user-visible gain.
- **W3-3. The subject fence, rule by rule** (`App\Support\SubjectFence`). (a) **Allow-list, not deny-list:** a
  limited teacher touches work only when its subject maps to a staff subject they teach, so a Qur'an-only
  teacher gets Qur'an and the combined "Qur'an & Islamic Studies" column and not Arabic Language, Islamic
  Studies alone or Mathematics (the owner's words: "only access specific to the subject they're teaching").
  (b) **Untagged work is invisible to a limited teacher** (404) and a limited teacher must name a subject they
  teach when setting work, so "leave it blank" is no way round the fence. Consequence to know: work set before
  subjects existed has no subject and is hidden from subject-limited teachers until an unrestricted teacher (or
  a later edit) tags it. Unknown, needs investigation: how many BISS assignments exist (RECON says 9 on prod in
  all and BISS had no teacher logins; users 38-49 are new), so this is expected to hide nothing today.
  (c) **Lesson plans:** a plan that names a subject is fenced the same way; **the day's general plan (no
  subject) stays open to every teacher of the class**, because BISS writes nothing else and fencing it would
  strand every plan. (d) **Only a Teacher is limited.** The office reads the same controllers through the admin
  realm and must see everything, so an admin who also holds a `group_staff` row is never fenced; the family
  endpoint has no fence. (e) **`PUT grade-weights` is not fenced**: weights are a policy of the class, and a
  class with a teacher per subject (BISS) would otherwise have nobody who could set them. **Setting is open to every teacher of the class; CLEARING is refused a limited teacher (403, nothing written) while
  any work outside their subjects (untagged included) carries a weight of its own**: clearing reaches per-work fields
  of work they cannot list, and narrowing it to their own work would leave the others' overrides dormant once the
  class's weights go, which is the very thing clearing removes them to prevent. The confirm text also says clearing
  removes weights another teacher gave their own work. (f) The fence reaches
  the child's summary (`levels`, `simple`, `by_subject` and the list), not just the assignment routes, so a
  limited teacher's picture of a child cannot contain a mark in another subject.
- **W3-4. A standard is only ever a row of the school's own pacing guide.** The teacher picks it from the
  existing standards search (no second matcher); the server checks the (code, focus, week) is a
  `curriculum_weeks` row of THIS school before storing it (another school's guide is refused), and an unchanged
  snapshot on edit passes even after the guide is re-imported. An uncoded weekly focus (the Islamic Studies
  column) is a standard by its words. `standard_code` 32, `curriculum_focus` 500, `curriculum_week_no` are the
  guide's own column sizes. Hidden and not written where `short_lesson_plan` is on (G8, BISS). Arabic has no
  standards and the search answers nothing for it: none is invented (A-3).
- **W3-5. The Subjects list.** `grade_labels` NULL means every grade; a subject limited to grades is compared
  through `GradeLevel` (memberships say `1st`, the guide `Grade 1`, the Drive doc `1st Grade`), and a child with
  no grade label hides no subject. A class offers the school's list, else the guide's subjects, else nothing to
  check against (free text, as before). A school with NO guide gets its list in the lesson-plan picker without
  choosing a grade. Names are unique per school by folded key. Work stores the subject as a snapshot string, so
  no edit of the list moves a mark.
- **W3-6. T-001.4 / T-001.5.** Qur'an, Islamic Studies and Arabic Language are separate subjects at every grade
  for Al-Razi and BISS through the seed (B3). The guide's combined weekly "Qur'an & Islamic Studies" column
  is NOT split, hidden or rewritten (that is authoring Islamic content); the lesson-plan picker keeps it,
  labelled "school pacing-guide column", because the standards search only works under it. **T-001.4 is
  therefore partial until the school's revised weekly guide (B12)**; the Arabic per-grade outcomes import
  waits for B7 and is not in this wave.
- **W3-7. `StandardPicker.vue` is new; the lesson plan's picker was NOT moved onto it.** The plan asked for an
  extraction from `TeacherClass.vue`. The plan's picker shares state with its draft-loss guards (`autoFill`,
  `stdLeft`, `cancelPrefill`, the 9ea27686 races) and `lesson-plans.test.ts` cannot exercise a component, so
  extracting it without a browser to prove those races survive would risk a live feature. The searching logic
  is a controller with no Vue and no HTTP (`core/helpers/standardSearch.ts`, 12 tests); the gradebook uses it,
  and moving the lesson plan onto it is a follow-up that needs a browser.
  **Open follow-up (W3-F1):** two client implementations of debounce, stale-answer and composition handling now exist
  (`TeacherClass.vue`'s inline lesson-plan search and `standardSearch.ts`); a fix to one must be made in the other until
  the plan moves onto `createStandardSearch`. Closes with a browser-verified run of the lesson plan's picker (type, pause,
  Enter, IME composition, switch class mid-search) plus `lesson-plans.test.ts` green. Not done in this wave: no browser.
- **W3-8. The seed migration** (`2026_10_03_100300`) writes only where the org id AND its name agree (14 says
  "razi", 18 says "sunday school"), only INSERTS (an office decision is never overwritten), in one transaction,
  with one WARNING line; Al-Razi's list is the three plus its own guide's subjects minus the combined column.
  `down()` removes only rows this migration wrote (`school_subjects.seeded_by` carries its name; office rows leave it NULL)
  that are still untouched (`updated_at = created_at`), and the table's own `down()` then refuses while the office keeps any
  list. The mark is needed because an office "Qur'an" typed at the defaults (position 0, every grade) is column for column
  the seed's own row, and the seed skips a subject that already exists, so it never owned that row; a column comparison alone
  would have deleted it on a rollback. **It ships only after the owner's yes (B3), after hours, after a
  backup, and after a run up/down/up on staging MySQL.** No prod data was touched by this wave.
- **W3-9. New family copy needs a human reader.** Eighteen keys (`marks_weighted_*`, `marks_untyped_*`,
  `marks_section_subjects|types`, `marks_no_subject`, `marks_type_counts`, `mark_type_*`,
  `marks_standard_source`) are in all six portal languages. English is the source; the Arabic is mine and the
  Urdu, Pashto, Dari and Spanish are machine-drafted like their tables. All five non-English sets want a fluent
  reader before this ships (the same class of ask as W2's read-receipt notice).
- **W3-10. Tripwires edited on purpose.** `TeacherRealmTest` write list +1 (`PUT grade-weights`); the
  `TeacherMultiSchoolTest` sweep gained its body; `FamilyGradesTest` key pins gain `weighting`, `by_subject` and
  the assignment's `subject`, `type`, `weight`, `standard_code`, `curriculum_focus`; the records export appends
  five columns to the assignments file (positions of the older seven unchanged).
- **W3-11. A limited teacher's figures say they are limited.** `GET members/{id}/grades` returns `data.fenced` (true when
  the summary counts only the teacher's subjects) and the Students view labels every headline line "(your subjects)" and adds a
  note that a parent sees every subject. The family and the office read every subject, so a limited teacher's "Weighted
  average" and a parent's can differ, and nothing on the teacher's screen said why. Alternative: show only the by-subject
  block to a limited teacher. Rejected: the headline is what gets quoted at a meeting.
- **Deploy notes.** `bin/deploy` checks out new PHP before it migrates, so new code meets the old schema for a
  few seconds (a gradebook save in that window would fail on an unknown column): deploy after school hours.
  New unique indexes are hand-named under 64 characters; `GradebookSchemaTest` asserts it. The migrations
  have not been run on MySQL by this wave.

- **2026-09-29 (school side quest W4, T-003.2): the weekly points reset is a teacher-opt-in VIEW; nothing is deleted.**
  Owner: "Points need to have a reset option at the end of week that Teachers can opt into." Decision: `groups.points_period`
  (`running` | `weekly`, null reads as `running`) says how a class's points are SHOWN; a teacher of the class flips it
  (`PUT .../points-period`, the teacher realm's +1 write verb, pinned in `TeacherRealmTest`) or the office through the group
  form. It is on the CLASS, not the teacher (a family sees one number for their child), and the screen and the response say it
  applies to every teacher. A weekly class leads with the week and keeps the running history beside it; nothing in the table
  moves (`PointsWeekTest` snapshots every award row, revoked ones included, before and after a toggle), so no backup and no data
  migration are needed and switching it off gives the running total straight back.
  Week rule: Sunday 00:00 to Sunday 00:00 on the SCHOOL's clock (`App\Support\PointsWeek`, start day passed explicitly), ends built as
  local midnights then converted, so the daylight-saving weeks are 167 and 169 hours (pinned for 2026-11-01 and 2027-03-14).
  Awards are placed by instant (`BehaviorAward::scopeAwardedWithin`, half-open), NEVER `whereDate`, which reads the UTC date and moves a
  Saturday-evening Eastern award into the next week (the test shows the row the old range loses). `?week=` (any day of the week, or
  `current`) narrows the same audience-constrained query on the staff and family listings and summaries; a non-date is a 422, never a
  silent fall back to this week under last week's label. `totals` carries `week_points`/`week_awards` beside the running figures, and
  negatives subtract in both (`signedPointsSql`). Alternative: a stored "week start" per class or a snapshot table of weekly totals.
  Rejected: a second copy of every total that can disagree with the award rows, and a reset that has to be undone. No leaderboard
  anywhere: the weekly list is roster order with no rank (test). The family portal gets a "This week" line under Behaviour and the
  printable report page (T-003.3). Non-English copy for the new portal words is machine-drafted like the rest of those files (es, ur,
  ps, fa-AF), flagged in each file's own banner. Unknown, needs investigation: Al-Razi's dismissal time and whether a Monday-start week
  is wanted (the start day is one argument).

- **2026-09-29 (school side quest W4, T-003.3): the Friday points report is a notice and a link, OFF by default, claimed once per class and week.**
  Owner (2026-09-28): the weekly report goes to parents (their own child's week) and to the teacher (the class summary), Friday
  afternoon in the school's time zone, BISS (Sundays only) Sunday evening. Owner B5 (2026-09-29): "your child's weekly report is ready"
  with a link to the printable portal report, nothing about the child in the email. Built as `points:weekly-report` (hourly,
  `withoutOverlapping`, one line per run on the `monitors` channel because production's LOG_LEVEL=warning drops an info line on the
  default one), behind a new `points_weekly_report` grant that is OFF for every organisation, Al-Razi included: turning it on for
  Al-Razi on production is the owner's call at ship (B4).
  - **When.** Each school's moment is `PointsReportSchedule`: Friday 15:00 on the school's own clock unless `masjid_points_settings`
    says otherwise (a SuperAdmin-only `PUT /api/admin/masjids/{id}/points-report-schedule`; GET shows what is set and what is default).
    BISS is Sunday 18:00, given by a guarded, idempotent, inert data migration (org 18, a school whose name says "Sunday School", the
    same guard as 2026_09_21_120000; it only says WHEN, the grant stays off). Deliberately NOT derived from the school calendar: a year
    models one weekly meeting day, so an Al-Razi that later entered a calendar would have had its report silently move. A run sends only
    inside CATCH_UP_HOURS (12) after the moment, so a missed hour still goes and a report is never days late because the grant was
    switched on afterwards. Al-Razi's dismissal time is Unknown, needs investigation, which is why the time is settable.
  - **Which week.** The points week containing the SCHEDULED instant, up to that instant (not the moment the run started, so a catch-up at
    16:10 decides as 15:00 would have). A child is in the report only with a live award in that span, so an award after the send shows in
    the portal only and never makes a second email (test). BISS's Sunday 18:00 falls in the week that STARTED that Sunday, and BISS meets
    on Sundays, so the report covers that day's points; the recon note that it would be "usually nothing" assumed a Monday-to-Friday
    school. Pinned by DST tests for both schools on both change weekends (2026-11-01 and 2027-03-14, and the Fridays 2026-11-06 and
    2027-03-19). A week the school calendar marks closed (a closure on any day of it) is skipped; no calendar reads as never closed.
  - **Who.** Families: a current ward, a confirmed, current guardian edge holding feed consent, a live family login
    (`GroupNotificationRecipientResolver::weeklyReportGuardians`, which checks the ward's and the guardian's `left_on` itself rather than
    trusting the model hook that ends a guardian edge with the child; tests write the rows around the hook). Consent is required although
    groups.md says consent gates broadcasts and not a parent's own child's record: this is an email to an address the school holds, so the
    cautious direction was taken, and the portal report itself is readable without consent (unchanged). One notice per address, so a parent
    with two children in the class gets one whose link shows both. Teachers: the class's `group_staff` logins only, when a current child
    has a week to summarise; a legacy Contact leader reached through a family login is excluded because the link is the teacher's sign-in.
    No staff push: the staff app is parked, so there is no seam to call (the resolver already names the right people when it returns).
  - **At most once.** `behavior_weeks` holds the claim: insert-or-ignore then `UPDATE ... WHERE report_sent_at IS NULL`; only the process
    that changed the row sends (unique `(group_id, week_start)`). A crash between the claim and the mail loses that class's notice for
    that week rather than repeating it (the portal report is there either way). A run that finds nobody to tell claims nothing, so a
    guardian whose login comes back that afternoon is picked up by the next hourly run inside the window.
  - **What is in the email.** School, class, a link. No child's name, figure, skill, note or count (tests use distinctive values and search
    the rendered HTML and subject); a generic subject identical for every family. `WeeklyPointsReportMail` is its own mailable and the
    sweep its own path: SendGroupNotificationJob is untouched (W2 fixes its URL for User recipients). The family link is
    `/family/{school}/sign-in?next=/family/{school}/classes/{class}/report`; sign-in follows `next` only for that one path shape for THIS
    school (`familyNextPath`, allowlist, tested against open-redirect shapes), and a signed-out parent opening the report directly is sent
    to sign in and back. The teacher's link is `/teacher/classes/{class}?tab=points`.
  - **The portal page.** `FamilyWeeklyReport.vue` (route `classes/:groupId/report`, family guard): each of the parent's own children for the
    week (positives first, then the awards with dates and notes), week navigation by the server's own neighbours (never the browser's
    clock), a Print button with a print sheet, and a sentence when a read fails (never a zero). It calls the existing ward-edge-gated
    `/awards` and `/awards/summary` with `?week=`: no new family endpoint, so the family write list is unchanged (the teacher realm has only
    the +1 verb of T-003.2). No leaderboard or ranking anywhere.
  - **Capability files.** `points_weekly_report` is in group `school`, which already exists, so `config/capability_groups.php` needs no
    edit. `Capability.ts`, `OrganisationModulesTest`, `CapabilityCatalogueEndpointTest` SCHOOL_KEYS, the three provision-snapshot fixtures and
    `set-capability-responses.json` (which records the whole capabilities object byte for byte) gain the key. The Studio session is paused,
    so there is no collision; the integrator should expect the same five files to conflict with any other wave that adds a grant.
  - **Not done, on purpose.** The child's week inside the email (a template-only follow-up if the owner reverses B5). Reach is limited: only
    guardians with a live family login are reachable, about 10 at Al-Razi and 0 at BISS on 2026-09-28 (to tell the owner at ship).

- **2026-09-29 (school side quest W4, review fixes to T-003.3): the report's links name the week; a total send failure gives the claim back.**
  From the seven-lens review of 7c30697a. Each fix has a test that fails without it.
  - **Both links carry the reported week.** `/family/{school}/sign-in?next=/family/{school}/classes/{class}/report?week=YYYY-MM-DD` and
    `/teacher/classes/{class}?tab=points&week=YYYY-MM-DD`, where the date is the points week the sweep reported (its first day, the same value
    as `behavior_weeks.week_start`), not the week holding "now". Before, both opened the week in progress, so Al-Razi's Friday 15:00 email read
    on Sunday or Monday landed on a new, empty week (`weekly_report_none`). `familyNextPath` now admits exactly one query shape after the
    report path, `?week=` plus a REAL calendar date (open-redirect cases still tested); the family route hands the week through sign-in
    (`familyReturnTarget`, which drops every other query); `FamilyWeeklyReport.vue` and the teacher's Points tab read it through
    `weekFromQuery` and fall back to the current week when it is absent or invalid. Found while wiring the teacher side: landing on the Points
    tab from `?tab=points` is not a tab change, so the `watch(activeTab)` that loads the totals never fired and the tab opened with no totals at
    all; a mount hook now loads them (on the linked week). Not changed: the teacher's sign-in does not carry `next` for the teacher realm
    (unchanged from before; an already signed-in teacher lands on the week, a signed-out one signs in and opens the class).
  - **A class whose every email failed is not left "sent".** After the deliveries, if no mail went out at all (the transport was down),
    `BehaviorWeek::release()` clears `report_sent_at` and `recipients_count` so the next hourly run, inside the 12-hour catch-up window, tries
    again; the class is counted as `classes_undelivered` (and is on the monitors line and in the command output), not as `classes_sent`. Only on
    TOTAL failure: after a partial send the claim stays, because a retry would tell the families who already have it a second time. The window
    still bounds a long outage (a test brings the transport back after 12 hours and nothing goes). The earlier "a crash between the claim and
    the mail loses that notice" stands: a process that dies cannot release.
  - **A closure on any day of the week skips the whole report.** This was already the behaviour (`closureWithin(start, last)`); now it is
    decided and tested: first day, a middle day, the send day, the last day skip the week, the day before and the day after do not.
    Whether a Monday holiday SHOULD skip a whole Friday report is the owner's to say; Unknown, needs investigation, and it is one call to change.
  - **Tests added for behaviour that was already right, so a regression fails:** the guardian query is scoped to the class (a guardian of the
    same child in another class only, and an unconsented edge here beside a consented one there, are not told); the catch-up window is exactly
    12 hours in both branches (11:59:59 sends, 12:00:00 does not); a retired (`is_active = false`) class is not reported; a weekly class still
    serves a parent the whole record with no `?week=` (summary and list); `24:00`, `24:30` and `23:60` are refused as a report time; the BISS seed
    migration refuses a non-school and a soft-deleted org 18 even with the right name.
  - **Left as they were.** The resolver's own `->current()` on the ward query is redundant with the command's (defence in depth; the
    review marked the mutant equivalent). The `familyLoginIsActive` and `PointsWeek::containing` time-zone mutants that survived the command
    test file alone are covered elsewhere or unconfirmed; not re-litigated here.

- **2026-09-29 (school side quest W5, T-002.4): "Send later" for class stories and NEW conversations.** Built on `b9bb63f4`
  (integrate/w3-w4), branch `feat/school-w5-scheduling`. S11 to S15 of the delivery plan, as answered by default; rules in
  `.claude/rules/groups.md` ("Scheduled class stories and new conversations").
  - **Stories: the clock publishes, the sweep announces.** (Amended 2026-09-30, see the review-fix round below: a due story is visible only
    once announced.) `group_posts.published_at` (visibility), `announced_at` (the email's claim),
    `publish_failed_at` + `publish_failure` (S15). Every family read goes through `GroupPost::scopePublished()`; the sites are the feed,
    one story, W2's seen POST and reaction PUT and DELETE, a photo download, a playback ticket and the playback stream, and
    `ScheduledClassStoryTest` asks each of them for a future story after proving the door is open for an ordinary one. Feeds order by
    `published_at`; `retained_until` counts from it. The digest leaves a reaction on an unpublished story unclaimed, and W2's
    "Not tracked before" line now dates a story by `published_at`.
  - **Deviation from the plan: two extra nullable columns** (`publish_failed_at`, `publish_failure`) on `group_posts`. S15 says a story whose
    author left the class is "not sent, shown as failed with the reason" and the plan's two columns cannot say "failed": with only
    `published_at`, a refused story would appear when its time passed. `scopePublished()` excludes a failed row whatever the clock says.
    Also nullable `published_at` (plan section 3.1 rule 4) with NULL read as "out" so an old-code insert in the deploy seconds stays visible.
  - **Look-ahead.** (Superseded 2026-09-30: it no longer keeps a refused story hidden, it only shows the refusal early.) The sweep asks the
    author gate `groups.scheduling.lookahead_seconds` (120) BEFORE the time. An estimate: two sweeps' worth.
  - **Conversations live in `group_message_schedules` until their time**, written through `GroupThreadWriter` (extracted from
    `GroupThreadsController::store`, one send path) with the `sent` stamp inside its transaction. Gates re-run at send time
    (`ScheduledSendGate`): author still on `group_staff` (or an administrator who still belongs and holds `manage contacts`), child still a current
    participant. Claims: `scheduled -> sending` one guarded UPDATE, stale claims (10 min, an estimate) handed back, edit/send now/cancel each one
    guarded UPDATE. `send_now` is a PUT that sets the time to now.
  - **Only new conversations, text only.** `send_at` and `send_now` on a reply or on `POST /threads` are a 422, not a quiet send-now (a client
    that believes it scheduled something must not be answered 201 with an immediate send); a photo on a schedule is refused, not dropped.
  - **Decision to confirm (S14 reading): the office (`manage contacts`) may READ scheduled items without being on the class roster**, because S14
    says it edits and cancels them and that needs the words. For a story that is not yet a disclosure to anybody; for a conversation ABOUT A CHILD
    it is the office reading a message meant for one family before it is sent. The feed and every thread stay behind roster standing; only the
    Scheduled lists use `GroupAudience::mayReadUnpublished` (a teacher of the class, or `manage contacts`). An administrator who is on the roster only
    as a consented parent gets neither. OWNER TO CONFIRM; narrowing it is one line in `mayReadUnpublished`.
  - **Time.** The school's wall clock (`masjids.timezone`, unset UTC reads America/New_York), 30 days ahead at most (S12), an offset honoured, a
    skipped DST hour moved on and reported back. The compose boxes label the zone the server names. The `send_at` name is used for both kinds.
  - **Scheduler.** `groups:publish-due` every minute, `withoutOverlapping(5)`, one WARNING line per run, each item handled with its own tenant bound
    and the previous binding restored. New `GroupAudience::mayReadUnpublished` is the twentieth pinned signature
    (`GroupAudienceForeignPrincipalTest`). `groups:purge-feed` gained the schedule rows (finished ones only). `config/staging_scrub.php` scrubs
    `group_message_schedules.subject` and `.body`.
  - **Teacher realm:** +3 write verbs (`POST`, `PUT`, `DELETE` `scheduled-messages`), pinned in `TeacherRealmTest` and swept for cross-school bleed in
    `TeacherMultiSchoolTest` (the world gained a schedule row). Story scheduling adds no verb.
  - **Mutation-proved.** 55 single-fault mutants, one per guard, each run against the scheduling tests on the droplet: every family door
    losing `published()` (feed, one story, seen, react, download, ticket, stream), the scope's failed-row and NULL rules,
    `mayReadUnpublished` (always true, view-only administrators), `postsFor`, the scheduled-list and `show` gates, a staff reaction on an
    unpublished story, the co-teacher and cancel authority, rescheduling a published story, the creation-time announcement, `send_now` not
    announcing, the retention day, the school-clock parse and both bounds, the digest deferral, the look-ahead, both gates at send time
    (author and child, plus the archived and lost-membership cases), the claims (double announce, double send, stale reclaim), the tenant binding,
    the in-transaction `sent` stamp, error recording, the log leak, both `send_at` refusals on replies and live threads, photos on a schedule,
    the purge guard and the schedule registration. All 55 were killed. Three guards had NO killing test and each got one (two spotted by reading the mutant list before it ran, the third by a real survivor, M2):
    `a_conversation_whose_claim_was_lost_leaves_no_thread_behind` (the sent stamp is atomic with the thread),
    `a_story_inside_the_lookahead_that_passes_its_gate_waits_for_its_time_to_be_announced` and
    `the_controller_refuses_the_list_itself_when_a_route_lets_the_wrong_person_in` (the controller's own gate, which the route middleware
    otherwise duplicates). Harness and results: `/root/w5mut/{mutants.py,run.py,run_direct.py,results.jsonl}` on the droplet, copied to the
    side-quest folder `w5-logs/`.
  - **Not done, on purpose.** Photos on a scheduled conversation (S13), scheduling a reply (S11), a staff push (the staff app is parked), and any
    change to the Friday report (unaffected). Unknown, needs investigation: how many families a scheduled story reaches at BISS is 0 until BISS is
    onboarded; and a sweep this frequent has no production timing yet.

- **2026-09-30 (school side quest W5, review-fix round): confirmed findings fixed.** Branch `feat/school-w5-scheduling`. Each fix has a test that
  fails without it, proved by reverting the fix: 13 fix-reverting mutants and 24 guard-removing mutants (37 in all), every one killed on the
  droplet. One (N14, the gate never binding the class's school) survived the first version of its test, which only asserted the tenant was
  restored; the test now binds ANOTHER school first and asserts the gate still answers correctly, and the mutant dies. Harness and results:
  `/root/manara-ci-w5-mut.py` and `/root/manara-ci-w5-mut-results.jsonl` on the droplet, copied to the side-quest folder `w5-logs/`. Three SPA mutants
  (the 30-day boundary, a blank failure reason, the office story tab's date) and one text mutant (the way-out hint) were killed by `npm run test:spa`.
  - **S15 no longer depends on the sweep's timing (decision).** The review showed a story became visible on the clock alone, and only the sweep,
    looking 120 s ahead, could refuse it; a killed run holds the `withoutOverlapping(5)` mutex for 5 minutes, so a removed author's story could
    reach families and then be pulled back. Chosen: option (a). `GroupPost::scopePublished()` (and `isPublished()`) now also require
    `announced_at IS NOT NULL`, and for a scheduled story only `GroupStoryPublisher::announce()`, called by the sweep after the gate passed,
    sets it. An outage therefore delays a story by however long it lasts and cannot leak one. The claim also requires `published_at <= now`,
    so a story moved after the sweep listed it is not announced early. `scopeScheduled`/`scopeUnpublished` follow (a due story not yet
    announced is still on the Scheduled list). The earlier claim "worst case up to a minute, a failed story never appears" is replaced by
    "never appears, however late the sweep is; a late sweep delays a story". Rejected: (b) failing pending stories where `group_staff` rows are
    removed (many removal paths, and the sweep would still be the only place the gate is asked). Cost: a story is one sweep later than its
    minute when the sweep is late; on a healthy box it is at most the sweep's own minute (the sweep announces a due story in the run that
    finds it).
  - **The race between "reschedule" and the sweep.** The `PUT` now decides the move on the row under `lockForUpdate` inside its transaction and
    answers 422 if the story is out by then; the sweep's claim waits on that lock and then finds the new time. Neither an early email nor a
    silent pull-back of an announced story remains. Proven with a hook that announces in the instant before the transaction begins.
  - **A new time or "Send now" asks the gates at once (decision).** For a failed item whose author left (or whose child left), the sweep
    would refuse the new time again about two minutes before it, with no way forward in the UI. Now `PUT` with `send_at`/`send_now`, story and
    conversation, asks the same gate first and answers 422 "... cancel it and write it again". This also closes a bypass: "Send now" on a
    story skipped the sweep, and so the gate. Rejected: reassigning the author to the editor (it would put the office's name on what a teacher
    wrote to families).
  - **The Scheduled lists show everything.** One page as long as the list (bounded by what people scheduled; the paginator shape is kept).
    Rejected: teaching three clients to follow `last_page`.
  - **`retained_until` may not close before the story goes out** (422, create and edit). The purge deletes on that date alone.
  - **The office story tab dates a story by `published_at`**, like the family and teacher screens.
  - **Tests added for guards that had none** (every one killed by its mutant on the droplet): the update paths' 30-day and past-time bounds for
    stories and conversations; the purge window on finished schedule rows (past, future, null, and a failed row inside it); the sweep's
    `announced_at` and `publish_failed_at` filters and both batch caps; dry runs (a due story, stale claims, `--masjid`); the read-receipt
    cutoff (`published_at`, not `created_at`); rolled-over calendar dates; the family payload's `published_at`; the SuperAdmin author; the
    message reschedule's retention day; the claim's own status and time guards and `markFailed`'s status guard; the gate's tenant binding and
    restore; the 5-minute claim staying fresh; `can_change` false for a sending item; the exact 30-day boundary and a blank failure reason in
    the SPA helper.
  - **Equivalent survivors, recorded so a later mutation run does not re-report them** (each verified equivalent by reading the routing):
    `postsFor` on the staff `update` and `destroy` (the routes already require `manage contacts` or `teacher.leads`, so `mayReadUnpublished`
    is true for anyone who gets there); the admin PUT, DELETE and GET permission middleware (the controller re-gates through
    `mayReadUnpublished` and `mayChange`); `authorizeSeeing` on update and destroy; `required_if` on the send-at rule (the lookup yields
    the same 422); the race-only claim guards on the `sent` stamp.
  - **Not fixed on purpose.** Nothing in the confirmed list was declined.
- **2026-09-29 (school side quest W6-A, T-003.4): the class store is an append-only ledger of Manara Bucks minted from positive points, behind a capability that is OFF for every organisation.**
  Owner B6 (2026-09-29): "points convert to Manara Bucks (1 point = 1 buck, weekly); students spend them in a class store the teacher runs; the app
  keeps each balance; paper Bucks are the physical version" (R1 to R6 defaults). The physical Manara Bucks are PAUSED (owner, 2026-09-29), so the
  paper cash-out is built and OFF. Nothing here touches the `bucks/` design folder (W6-B).
  - **Points stay the record; bucks are a ledger.** `prize_ledger_entries` is append-only in the application (the model throws on update and delete,
    no route exists, a test scans the route list). A balance is the SUM of a child's rows. Alternative: a stored `balance` column on the roster row.
    Rejected: a second number that can disagree with its own history, and a correction that has to edit it. Alternative: DB triggers for
    append-only. Rejected: erasure and the retention purge must still be able to remove a child's whole ledger (the rows go as a set).
  - **Once is a database fact.** A nullable UNIQUE `dedupe_key` (`earned:{membership}:{week}` and four siblings), because MySQL has no partial indexes
    and SQLite's FK rebuild drops them. Every index is hand-named under 64 characters (a test asserts it). `week_start` is a plain `Y-m-d` string, not a
    `date` cast: the cast stores a timestamp on SQLite and an exact match would silently miss there (found by the minting tests).
  - **What earns a buck.** `floor(P / points_per_buck)` per child and week, `P` = live awards that week whose skill is not negative AND whose points
    are above zero. Alternative in the plan: `ABS(points)`. Rejected: a positive skill docked with a negative override (a real use, see
    `BehaviorAward::signedPointsSql`) would MINT bucks. A negative award neither mints nor subtracts (R2). Whole bucks per week, so a dearer rate
    drops a week's remainder (said in the docs; at the default rate nothing is lost). A child who left the class is not minted for (ASSUMPTIONS W6-A2).
  - **Minting and late changes.** `bucks:mint` hourly: closed weeks from `bucks_from` mint once; only the LAST TWO closed weeks are re-read, and a
    late change is an `adjusted` delta clamped at a zero balance. **`week_basis`** (what the week's points came to, after each row) makes a clamped
    clawback forgiven once; without it the shortfall would be taken back out of a later week's earnings the next time the window found the gap.
    Alternative: carry the shortfall as debt. Rejected: a child's balance is never negative, and R-defaults say "clamped at 0".
  - **Nothing retroactive by surprise.** `masjid_points_settings.bucks_from` is set to the start of the week in progress the first time a school with
    the store on is seen. Alternative: mint every closed week in the points history the day the grant is switched on. Rejected: it pays a term of
    history nobody expected. A SuperAdmin can move the date earlier on purpose.
  - **Redemption.** Locks the student's roster row and the prize row (`lockForUpdate`) inside one transaction; SQLite has no row locks, so the
    invariant is also checked after the write and rolled back, and a test proves it with a simulated concurrent spend. A `request_id` per click makes a
    double-tap a replay. The prize must be this school's and school-wide or this class's own (checked in `ClassStore` too, because a console
    caller is unbound). Stock: optional, blank = unlimited, decremented with the redemption, given back by a reversal. Reversal: a new row, once per
    entry (`reversal:{entry}`), only of a redemption or a cash-out, refused after an expiry.
  - **Expiry and retention.** `bucks:expire` writes one `expired` row per child and cutoff at `groups.ends_on` and at each calendar year's
    `last_day`, taking only what was minted before the cutoff. The retention purge removes a child's ledger as a SET, only when every row is due,
    re-decided inside a transaction holding the roster row. Both are logged on the `monitors` channel, one line per run.
  - **Privacy.** Every balance is read through `GroupAudience::readablePrizeLedgerQuery` (the awards' audience: the class's teachers, the student, that
    student's own guardians). Roster order, no rank, no class total, no prize wall. The family payload is the narrow one. **The office reads class
    totals only** (`mayReceiveClassStoreTotals`): no child, no roster id, no per-student figure; an office-run school-wide store that reads every child's
    balance stays not built (RECON section 6). Alternative: let the reconciliation list students with a balance below zero. Rejected: the count is enough,
    and a list is a child's balance for an administrator who stands nowhere in that class.
  - **The capability.** `class_store`, a grant in group `school`, OFF for masjid, school and community. Studio owner's three conditions: the mobile
    `/features` and `tv-config` are byte for byte identical on or off (test); it is writable through `CapabilityWriter::apply` and appears in the catalogue
    (test); the snapshot fixtures differ only by the key. The class payloads carry `class_store: true` only when on.
  - **Verbs.** Teacher +5 (`POST prizes`, `PUT prizes/{id}`, `POST members/{id}/prizes/redeem`, `POST members/{id}/prizes/cash-out`,
    `POST prize-entries/{id}/reverse`), family +0. The office gets the school-wide prize routes and the reconciliation in the admin realm; a
    SuperAdmin gets `PUT class-store-settings`.
  - **Not done, on purpose.** A SuperAdmin settings screen (API only, like the Friday report's schedule); a push or email to a family when they earn
    or spend (the digest and the staff app are separate items); the physical Manara Bucks print run (paused); an office-run school-wide store; a
    raffle (B6 option c).
  - **Unknown, needs investigation.** How MySQL behaves under two simultaneous taps (ASSUMPTIONS W6-A8); whether Al-Razi wants a departed child to
    keep the week's bucks (W6-A2); the year and class end dates Al-Razi has entered (W6-A3).


- **2026-09-29 (school side quest W6-A review fixes): what the review of the class store found, and how each was fixed.**
  Every fix has a test that fails without it (mutation proofs in the closing report).
  - **Expiry ran before the class's last week was minted, and its once-only key let those bucks escape.** The week that holds a cutoff is
    minted only after it closes, so bucks for a pre-cutoff week can arrive after the first write-off, and `expired:{m}:{cutoff}` was already
    taken. Chosen: the expiry is re-runnable per cutoff (`expired:{m}:{cutoff}:{n}`, `n` counted under the student's lock; the amount is
    still `balance - minted from weeks on or after the cutoff`, so a run with nothing left writes nothing), and the minter stops minting a
    class's weeks that open after its `ends_on`. Alternative: hold the cutoff back until the weeks around it have closed and left the
    adjustment window (about three weeks). Rejected: bucks stay spendable for weeks after a class ended. Cost of the chosen way: for up to
    30 minutes (between the hourly mint at :10 and the expire at :40) a late-minted pre-cutoff buck shows on the balance before it is
    written off.
  - **A change of `points_per_buck` re-rated the last two weeks.** Each `earned` and `adjusted` row now keeps `week_rate` (and `week_points`, the
    audit record of what the basis was worked out from; the adjustment itself needs only the rate); a week is re-priced at its own rate, so a
    new rate applies to weeks minted after it. Alternative: an
    "effective from" date on the setting. Rejected: a second setting to keep in step, and a late award in an old week still has to be priced
    at some rate. Migration `2026_10_04_100400` (nullable columns; an older row without them reads at the current rate).
  - **Switching the store off and on paid the weeks it was off.** `bucks_swept_at` marks a sweep that found the store on; a sweep that finds it
    off for a marked school clears `bucks_from` and the mark, so the next sweep with it on starts at the week in progress. Alternatives: hook the
    capability writer (Studio's file, to be coordinated), or read `masjid_capability_changes` (a start day set by hand after a pause cannot be
    told from an old one). A SuperAdmin's start day, set after the pause or before the store was ever on, is honoured. Migration
    `2026_10_04_100500`.
  - **`bucks_from` was documented as a day and worked as a week.** Kept as documented: the first week's window starts at that day's midnight on
    the school's clock (BucksMinter, and the reconciliation's expected figure). Alternative: round it to the week start and echo that.
    Rejected: it would credit the days the SuperAdmin meant to exclude.
  - **Two overlapping mint runs could write one late change twice.** The delta is decided again under the student's lock from the newest row
    as it is then (`settle`), not from the row read before the lock.
  - **A lost response was a second deduction in the SPA.** One request id per write (student and prize, student and amount), kept across a
    retry after no response, 408 or 5xx and dropped on success or any other 4xx. A reload failing after a successful write no longer says
    the write failed (that would invite the second tap).
  - **Tests only:** the row locks (source pin, SQLite cannot see them), the store gate on cash-out and the hand-out with paper ON, the
    office's contacts permissions, replays of the last bucks and last stock, the layers of the replay and stock guards, a 1-buck overdraft,
    the start-side week boundary, the frozen week, and a request id scoped to its kind.
  - **Not fixed, on purpose.** The reconciliation's `expected` uses today's rate, so after a rate change it differs from `minted` for weeks
    minted earlier: the view already calls a difference a question for the office, and its docblock now names the rate change as a reason.
  - **Unknown, needs investigation.** The lock behaviour on MySQL with two connections (ASSUMPTIONS W6-A8) and the migrations on MySQL 8.4
    (W6-A7) remain unrun by this fix, as before.
## 2026-09-29 (follow-ups) — The exact-address fix, second pass (fix/login-followups, off fix/login-address-exact-match @ 20a8f984)
Decision: the point's opus review of b9f11d4c found six more places where a `utf8mb4_unicode_ci` email column lets a look-alike address (`victim@gmaíl.com` for `victim@gmail.com`) act as the real one. Each is fixed the same way as the first pass: the query keeps its SQL and only SHORTLISTS, then `ContactIdentity::keepExactMatches()` / `sameAddress()` decides, before any tie or ambiguity rule counts the rows, and with no `limit()` ahead of the filter. Basis: the brief states production's email columns were verified `utf8mb4_unicode_ci` read-only (2026-09-29); this branch did not read them itself (ASSUMPTIONS 25, 26, 30). Ships after the branch it is off; rebase onto main once that lands. Nothing here ships without the owner's yes.

1. **Sign-in throttle keys** (`AppServiceProvider::familyLoginKey`, shared by `family-login`, `family-verify`, `member-login`, `member-verify` and the account-deletion page). The bucket keyed on `strtolower(trim(raw))`, and a limiter runs BEFORE the FormRequest normalises the address, so every UTS46-equivalent spelling (soft hyphen, ZWSP, fullwidth, math letters, U+3002) was a bucket of its own: the reviewer got a 200 on a variant after the plain address had hit 429. It now keys on `ContactIdentity::submittedAddress()` via `bucketAddress()`, and on `mb_strtolower(trim(raw))` when the door would refuse the address (a non-ASCII local part), which nothing can act on. Test: `SignInThrottleKeyTest`, every door by every spelling: exhaust the plain address, then the variant is 429.
2. **Staff throttle** (`login` limiter, on `/admin/login`, `/admin/forgot-password`, `/admin/reset-password`). The per-address bucket (5 a minute per address + IP) now keys on the same normalised address, and the limiter ADDS two IP-only limits shared by the three routes: **60 a minute and 600 an hour** (`config('auth.admin_throttle')`, env `ADMIN_LOGIN_PER_IP_PER_MINUTE` / `_PER_HOUR`). Why those numbers: the address limit already allows 5 a minute for any one address, so the IP limit exists to stop one host multiplying that by spellings or by addresses. A shared office or school is one NAT address, and a Monday morning is thirty staff signing in inside a minute, some of them twice (60 requests): the per-minute figure is that plus nothing, so it is the smallest number that office never trips. The hourly figure is ten times the minute figure, so a busy hour of sign-ins, resets and typos never reaches it while a script at the per-minute rate still stops after ten minutes. Both are estimates, not measurements of any real office (ASSUMPTIONS 32), and both are env-tunable without a deploy. The staff key does NOT merge an ACCENTED spelling with the plain address: `submittedAddress()` turns an accented domain into punycode, which is a different string, and a non-ASCII local part falls back to the raw string. `users.email` calls those the same user, so each accented spelling still has its own five-a-minute bucket; the IP-only limits are what bound them, all together, per IP. Tests: `StaffLoginThrottleTest`.
3. **Roster import** (`RosterImportService` preview at :322 and `apply()` at :407). The guardian match used `LOWER(email)` with no exact re-check, so a look-alike contact made through the anonymous offering-registration door became the staff-CONFIRMED guardian of the real parent's children on the next import. Both halves shortlist and then keep exact matches, so the preview and the write still ask one question. The importer's rule is unchanged, "the first contact holding the address" (`first()`, no explicit order), and now ranges over the exact holders only. Test: `RosterImportLookAlikeAddressTest`.
4. **Staff-to-contact bridge** (`GroupAudience::identitiesFor`). (a) The candidates are filtered to the exact address before "two rows is no row" counts them, and the `limit(2)` ahead of the filter is gone. The exact comparison uses the user's own address, not the multibyte-lower-cased copy the shortlist uses. (b) `POST /admin/profile` no longer changes `users.email`: `UpdateProfileRequest` refuses any address other than the one the account holds (case aside; 422 on `email`, nothing written) and the controller no longer writes `email` at all. The profile screen posts the whole form, so the field stays required and the SPA shows it read-only. **The owner can decide later on a verified change flow** (mail a link to the NEW address, change it only when it is opened, and tell the old one); until then an address change is an office act through the users screen. Tests: `GroupAudienceLookAlikeAddressTest`, `ProfileEmailChangeTest`.
5. **Donor contact** (`DonorContactService::findOrCreateForMasjid`). A public look-alike contact captured the real donor's later gifts and the receipts were mailed to it. Exact-matched; the oldest exact holder wins, and the order is now explicit (`orderBy('id')`), where before `first()` carried no order. Test: `DonorContactLookAlikeAddressTest`.
6. **Minors.**
   - `EmailSuppressionService::suppress()`, `release()` and `liftPrecaution()` act only on the row of the EXACT address. A resubscribe link minted for a look-alike spelling used to release the real person's opt-out. When the only row belongs to a look-alike, `release()` and `liftPrecaution()` do nothing (and `release()` leaves the mirror alone), and `suppress()` neither rewrites that row nor its hold or release, and cannot store its own beside it (the unique index is collation-equal too): it logs a warning naming the organisation only and returns null. Its callers already handle null. `liftPrecaution()` was added to the two the brief named because it is the same defect and a release. Test: `EmailSuppressionLookAlikeAddressTest`.
   - `UsersController::store`: the archived-user restore is exact. A candidate that matched only through the collation is refused with a 422 and a sentence, not restored and not a unique-index 500. Test: `UsersStoreLookAlikeAddressTest`.
   - `ContactIdentity::sameAddress()` (and `submittedAddress()`, and `of()`, the roster-merge identity, which had the same fold) no longer fold a non-ASCII letter into an ASCII one. `mb_strtolower()` maps U+212A KELVIN SIGN to `k`. The brief said to lower-case ASCII only; taken literally that would also stop `GMAÍL` equalling `gmaíl`, which the existing unit case `accented capitals fold to the same accented letters` requires, so `foldCase()` lower-cases the ASCII capitals by byte and lets any other letter change only into another NON-ASCII letter (`É` to `é`; the Kelvin sign stays). Nothing non-ASCII becomes ASCII, and that case still holds. Tests: new Kelvin cases in `ContactIdentityAddressTest` and `ContactIdentityTest`.
   - `MemberSignupService::consume`, link path: adopting the typed address as `login_email` has the same unique-violation catch as the create path and is answered as the refused redeem (the one 410, nothing adopted, code spent, a warning with no address). Tests in `MemberSignInLookAlikeAddressTest`, for a look-alike holder and for a soft-deleted holder.
   - `contacts.email` with a Unicode IDN domain is stored as punycode at the public doors. Member sign-up already did (its address is `submittedAddress()`'s output in both columns; pinned by a new test). The offering-registration door now does: `normaliseEmail()` runs `submittedAddress()` and falls back to lower-case for an address it refuses, so every comparison at that door is in the same form. Other writers found are listed in ASSUMPTIONS 33. Tests: `OfferingRegistrationAddressTest`.
   - The office's enable-family-login refusal for an accented local part says "Remove the accents before the @" (a rule ahead of `email`, which refuses such an address first with its own generic sentence). The public doors keep the generic sentence on purpose. The existing test that asserted the old sentence for this case was changed to the new one.
7. ASSUMPTIONS 29 is corrected to what this branch fixes and what is still office-side; 25 and 26 carry the brief's statement; 30 to 33 are new.

Alternatives considered: folding accents in the staff throttle key with a transliteration table (a bigger, unrunnable fold for a key the IP limits already bound); a schema change to a binary collation on the address columns (production DDL; the owner's call, and the same review would need repeating for the next column); refusing instead of ignoring a look-alike opt-out in `suppress()` (it would drop a legitimate one).

Not run: `php -l`, PHPUnit, or anything else (no PHP on this machine). The CI droplet must run the new files (`SignInThrottleKeyTest`, `StaffLoginThrottleTest`, `RosterImportLookAlikeAddressTest`, `GroupAudienceLookAlikeAddressTest`, `ProfileEmailChangeTest`, `DonorContactLookAlikeAddressTest`, `EmailSuppressionLookAlikeAddressTest`, `UsersStoreLookAlikeAddressTest`, `OfferingRegistrationAddressTest`), the changed ones (`ContactIdentityAddressTest`, `MemberSignInLookAlikeAddressTest`, `SignInAddressAtTheDoorTest`) and the suites for the touched doors (`ImportSchoolRosterTest`, `RosterImportTest`, `Broadcasts/EmailUnsubscribeTest`, `Broadcasts/ContactEmailConsentTest`, `UsersAccessListTest`, `AccountAccessTest`, `TwoFactorTest`, `HouseholdIdentityTest`, the family and app sign-in suites) on SQLite and MySQL. Two of the new tests rebuild a table's column with a SQLite collation (`contacts.email`, `email_suppressions.email_normalized`); if a table cannot be rebuilt on CI they fail at `collateColumnLikeUnicodeCi()` before they assert anything.

## 2026-09-29 (follow-ups, round 3) — the suppression key is byte-exact, the staff door matches exactly (fix/offering-address-exact, off d6035bab)
Decision: the point's review of the follow-ups found that the fix for a look-alike opt-out (item 6 of the entry above) traded one hole for another, and four smaller items. All four are folded into this branch, in small commits, and ride with the follow-ups in one owner yes. Nothing here ships without it.

1. **`email_suppressions.email_normalized` is made `utf8mb4_bin` on MySQL** (migration `2026_10_01_130000_make_email_suppression_key_byte_exact.php`). The problem it fixes: the column and its unique index `(masjid_id, email_normalized)` were `utf8mb4_unicode_ci`, so once a look-alike spelling (`victim@gmaíl.com`) held a row, the real person's own unsubscribe (`victim@gmail.com`) hit the unique index. The previous round caught that, logged a warning and returned null, and `UnsubscribeController::store()` ignores `suppress()`'s return and renders "done": the page said the person was unsubscribed and they went on being mailed. A person who asked to stop must stop, and a check that can be defeated by another person's row cannot promise that.
   - **The SQL, exactly.** Guarded by `in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)`, so SQLite is a no-op:
     - `up()`: `ALTER TABLE email_suppressions MODIFY email_normalized VARCHAR(191) COLLATE utf8mb4_bin NOT NULL`
     - `down()`: `ALTER TABLE email_suppressions MODIFY email_normalized VARCHAR(191) COLLATE utf8mb4_unicode_ci NOT NULL`
   - **Why a MODIFY and no index work.** Type, length and nullability are the create migration's (`string('email_normalized', 191)`, not null, no default). MODIFY rebuilds the indexes over the column under the new collation and keeps their names, so nothing is re-created: `email_suppressions_tenant_address_unique` (39 characters; `email_normalized` is its second column) and `email_suppressions_address_index` (32) are both under MySQL's 64-character limit. The brief said to re-create the unique index with an explicit short name only if MySQL needed it; it does not.
   - **Production facts** (read by the point, relayed by the coordinator 2026-09-29; this branch did not read them): 0 rows; `varchar(191) NOT NULL utf8mb4_unicode_ci`; the two indexes above; MySQL 8.4.8. On an empty table the rebuild is instant. `up()` is correct whatever the collation was; only `down()` assumes unicode_ci (ASSUMPTIONS 30).
   - **SQLite** compares TEXT bytes, so the suite's table is already what production becomes. The look-alike tests now rebuild it with `collate BINARY` (`collateColumnLikeUtf8mb4Bin()`) so the shape they depend on is stated, and every one of them asserts the premise first: the plain address finds NO row and the accented one finds its own. On MySQL the helper does nothing and that premise reads the migrated column, so it fails there if the migration has not run.
   - **The one rule the column adds** (written on the migration, on `normalize()` and in `.claude/rules/migrations.md`): a `utf8mb4_bin` column compared in SQL with a `utf8mb4_unicode_ci` one fails on MySQL with "Illegal mix of collations", and SQLite cannot show it. The audit for that, at this commit: every SQL touch of `email_normalized` compares it with a PHP-built literal and there is no JOIN, subquery, `whereColumn` or `EXISTS` against another table's email column. The sites: `EmailSuppressionService` `suppress()`, `release()`, `liftPrecaution()`, `activeReason()`, `suppressedAt()` (each `where('email_normalized', $literal)`) and `suppressedAmong()` (`whereIn('email_normalized', <array>)`, then filtered in PHP); `EmailSuppression::scopeForAddress()`; `WixContactImport` `plan()` (`->get([...])`, no comparison, keyed and compared in PHP) and `applyEmailSuppression()` (a literal); `WixOrderHistoryImporter` `createHeldContact()` (`forAddress(literal)`) and the undo check at `:~961` (`contacts.email` against a bound parameter that was read from a row, not a column). `mirrorOntoContacts()` compares `contacts.email LIKE <literal>` and touches no suppression column. The audience filter takes the suppressed keys out of SQL and compares in PHP (`BroadcastAudienceResolver::emailAudience()`).
   - **The round-2 behaviour is reverted.** The `UniqueConstraintViolationException` catch that logged and returned null is gone from `suppress()`. Where the column can no longer produce that conflict (MySQL after the migration, and the byte-exact table the tests build) it cannot happen; where it still could (the column not yet migrated) the write now throws, so the request fails loudly instead of a page saying "done". A concurrent double click can still make the second insert throw: that is the behaviour before round 2 and it still leaves the one row in force. The one null `suppress()` returns is for a value that is not an address at all, which a link that parsed cannot carry, so `UnsubscribeController` was left as it is.
   - **Every read looks the row up by the exact normalised address** (`EmailSuppressionService::normalize()`, the writer's own function): `suppress()`, `release()`, `liftPrecaution()`, `activeReason()`, `suppressedAt()`, and the send-time `suppressedAmong()`, which now also drops any key that is not one of the addresses asked about. The `keepExactMatches()` re-check stays as belt and braces on all of them.
   - **Tests.** `EmailSuppressionLookAlikeAddressTest` (the existing tests moved to the byte-exact table, the round-2 "not written" assertion replaced by "written beside the look-alike's untouched row", plus: the real person unsubscribing through the public link beside a look-alike's row is written, the send-time audience then excludes them and still excludes the look-alike, and the look-alike's row is untouched; every read names only the exact address; and on a unicode_ci table the same opt-out throws rather than being dropped). `MigrationsBootTest` pins the two statements, the guard, the restated column and the two index names. `StagingScrubCoverageTest` and `TenantScopingCoverageTest` are unaffected: no column or model is added, and the one test name cited in `app/` (`StaffLoginLookAlikeAddressTest`, on `AuthController`) exists.
   - Alternatives considered: keeping unicode_ci and finding the row by the exact address in PHP only (the unique index still refuses the second row: this is the defect); a second key column holding a hash or the punycoded, case-folded address (a wider change, and the writer would need a second normaliser); a query-time `COLLATE utf8mb4_bin` on the read (MySQL-only SQL, and it defeats the index).
2. **The staff door matches the typed address exactly** (`AuthController::login()` through `staffUserAt()`). The limiter keys the per-address bucket on `ContactIdentity::submittedAddress()` while the lookup was `User::where('email', typed)` and `LoginRequest` validates `exists:users,email`, so on the unicode_ci `users.email` a look-alike was the same user to both queries and a different address to the limiter. The lookup now uses `submittedAddress()` (case and spaces folded, a Unicode domain converted to punycode, a non-ASCII local part refused), `User::whereEmailIs()` to shortlist, and `keepExactMatches()` on the found rows. A look-alike is answered exactly as a wrong password, whether or not its password is right (`{"message":"invalid credentials"}`, 200). `LoginRequest`'s `exists:users,email` is left alone, as its docblock says; it still lets a look-alike reach the controller, which is where it now stops. A consequence to know: an account stored at a non-ASCII address can no longer be reached by typing it (the typed form is converted or refused); production stores none (ASSUMPTIONS 28 has the count to run for `users`). Test: `StaffLoginLookAlikeAddressTest`, with the stand-in on `users.email` (column and unique index re-collated, `LOWER()` folding).
3. **`UpdateProfileRequest`: `email[]=x` is a 422.** The refusal closure cast its value to a string and a rule after a failed one still runs without `bail`, so a list threw an ErrorException (a 500). The email rules are now `bail`, `required`, `string`, `email`, then the refusal. Test: `ProfileEmailChangeTest::an_email_that_is_not_a_string_is_a_422_and_not_a_500` (a list, a one-word list, a nested list).
4. **The profile refusal names who can change it.** It sent staff to "your organisation's administrator", but `users.email` is changed only through the `super`-only users routes (`routes/admin.php`, `UsersController::update`), so it now says "Only a platform SuperAdmin can change it." This corrects the entry above, which called an address change an "office act": the office cannot do it. The test that pins the message now pins the sentence and not only the constant. `.claude/rules/auth-permissions.md` says the same.

Minors left open, in ASSUMPTIONS: ForgotPassword's 429 shown as success (34, deliberate), the IP ceilings counting successful sign-ins (35), and the two already recorded, punycode consistency across writers (33) and `RegistrationsController::createContact` (29).

Not run: `php -l`, PHPUnit, `artisan`, or any SQL (no PHP on this machine). The CI droplet must run `EmailSuppressionLookAlikeAddressTest`, `StaffLoginLookAlikeAddressTest`, `ProfileEmailChangeTest`, `MigrationsBootTest`, `Broadcasts/EmailUnsubscribeTest`, `Broadcasts/ContactEmailConsentTest`, `WixOrderHistoryImportTest`, `StagingScrubCoverageTest`, `TenantScopingCoverageTest`, and the staff sign-in suites (`TwoFactorTest`, `SecondAdministratorLoginTest`, `StaffAuthGuardPinTest`, `AccountAccessTest`, `StaffLoginThrottleTest`), and the MySQL migrations job must run the new migration up, down and up again.

- **2026-09-29 (W3/W4 folds, F1): a family sees a week only where its own switch is on.** The point's review found the portal's
  "This week" block and the weekly report page showing for every school, although the `points_weekly_report` grant is documented as
  OFF meaning nothing visible. Two surfaces, two gates, because they answer to two different choices: the report page and every link
  to it need the SCHOOL's grant; the class's "This week" line needs the CLASS's opt-in (`points_period = weekly`, the teacher's
  choice). The family class payload gains `weekly_report` (the grant) and the awards endpoints enforce the same rule on `?week=`
  (a school with the grant: any week; otherwise `current` for a weekly class; anything else 404 before the value is read as a date),
  so a hand-typed request gets no more than the screen shows. Alternative: hide it in the SPA only. Rejected: the data is the
  parent's own child's, but "off means off" is a claim about the API too, and the point asked for the server side checked.
  A non-weekly class no longer asks for a week at all. `PointsWeekTest` pins both sides; `points-week.test.ts` pins the views.

- **2026-09-29 (W3/W4 folds, F3): `school_subjects.seeded_by` is its own migration; 100200 is what it first said.** Commit 12ced552
  put the column into the create-table migration `2026_10_03_100200` after 4ebd8d8d (also on the W5 and W6 branches) had written it
  without, so a box that had already run the earlier 100200 would never get the column and the seed's insert would abort its migrate.
  100200 is restored byte for byte (SHA-1 pinned in `SchoolSubjectsSeededByMigrationTest`) and `2026_10_03_100250_add_seeded_by_to_school_subjects_table`
  adds the column behind a `hasColumn` guard, after the table and before the seed (`2026_10_03_100300`, the only other file that names it;
  the test fails on any later file that reads it from before 100250). Proven both ways: a fresh `migrate:fresh` runs 100200, 100250,
  100300 in that order; and a box built as the old 100200 left it (table without the column, 100250 and 100300 not yet run) gains the
  column and then the seed runs, marking its rows. Rule restated: an applied migration is never edited, a new column is a new migration.
  The staging check the review asked for (does `migrations` hold `2026_10_03_100200`?) is no longer needed for this: either answer is safe.

- **2026-09-29 (W3/W4 folds, F4): another subject's work is not there for a limited teacher, and their by-day save makes their own plan.**
  Two defects with one cause. (1) `PUT /lesson-plans` (the by-day address) falls back to "the day's only plan" for an older screen; on
  a day whose only plan was another subject's, that fallback landed on it, `write()` fenced it and a Qur'an-only teacher got a 403 naming
  Arabic Language instead of a plan of their own. (2) Work and plans that existed in another subject answered a 403 that said so, which
  tells a teacher walking ids that the work is there (untagged work already answered 404). Decision: to a limited teacher another
  subject's work or plan does not exist. By id it is the one plain 404 untagged work gets (gradebook: `abort(404)`, byte for byte the
  same body; lesson plans: the same `ModelNotFoundException` a missing id raises, so the body cannot tell them apart). By day, "the
  day's plans" are only those the teacher may touch: their save creates their own plan when none of the plans they may touch is the
  one meant (an Arabic plan is never renamed under their label), their delete removes only their plan (a hidden plan no longer makes it
  a 409) and a day with nothing of theirs is a 404, EMPTY OR NOT, so 404 versus 200 cannot reveal a hidden plan; an unrestricted
  teacher's empty-day delete is still a harmless 200. The 403 stays for a subject the teacher TYPES and does not teach, in the words
  they typed, before anything about the day is read, so it is the same sentence whether or not that subject has a plan. Alternative:
  keep the 403s and only stop naming the subject. Rejected: a status that differs from "no such thing" still confirms the thing.

- **2026-09-29 (W3/W4 folds, F5, the point's decision, superseding W3-3(e)): the class's weights are for a teacher of ALL subjects, and the office.**
  W3-3(e) left `PUT grade-weights` unfenced so that a class with a teacher per subject (BISS) would not be left with nobody able to set
  them, and refused a limited teacher only the CLEAR while other subjects' work carried a weight of its own. The point's review found
  the cost: the weights are one policy for the whole class and they change the weighted average a parent reads for EVERY subject, so a
  one-subject teacher (a Qur'an-only teacher) could re-weight what families see for Arabic and Mathematics, work they cannot even list.
  New rule: a teacher limited to some subjects (`group_staff.subjects` a non-empty list, however long) is refused, setting or clearing,
  with a 403 that writes nothing; a teacher with no list (or an empty one, "everything") and the office are not. `SubjectFence::mayWeighClass`
  is the one answer; the clear-only special case is deleted with it, and the teacher's Weights panel is read-only for a limited teacher
  (the server refuses either way). Alternative: refuse only the set, or only where the class already has weights. Rejected: the
  point asked for the plain rule, and a half-fence would leave the same re-weighting one keystroke away.
  Consequence to know, for the owner: a class whose EVERY teacher is limited (BISS, if each teacher is given a subject) now has nobody who can
  set its weights, because the office has NO route to this verb (`GradebookWeightingTest::the_office_has_no_route_to_set_weights`, and
  DECISIONS W3-3(e) said the office reads and does not set). The gate admits the office if a route is ever mounted, but none was added
  here. Unknown, needs investigation: whether any live class has only limited teachers (BISS is on simple marking, which is never averaged, so
  weights matter to it only for points work; whether Al-Razi's teachers carry a subject list was not read from production). Open question for
  the point: an admin-realm `PUT` for the weights (`permission:manage contacts`), or leave it.

- **2026-09-29 (W3/W4 folds, F6): simple-scale work says "not averaged", and a weighted class with only such marks says why it has no weighted figure.**
  The server never averages or weights Excellent / Good / Needs work (`GradeRecord::weighted` skips the scale, and leaves it out of
  `untyped_excluded`), but the teacher's and the office's screens badged a typed simple piece "counts 40" and gave a BISS class that set
  weights and typed its work no figure and no reason. `weightNote()` / `effectiveWeight()` now read the piece's `scale`: a simple piece in
  a weighted class is "not averaged" (and no weight), the blank weight box on a simple form says so, the "no type" warning no longer counts
  work a type could not help, and `averageLines()` adds one explanatory line where a weighted class has simple marks and nothing else to
  average. Teacher and office screens only; the family screen still shows the three words and no figure. No family copy.

- **2026-09-29 (W3/W4 folds, F7): one helper, one rounding, for a child's plain points percentage.** The server sends the plain figure only as
  earned and possible, and the browser worked the percentage out in two places: the office Grades tab's "Points work" block rounded to a whole
  number (`Math.round`, "85%") while the new figures card beside it used `percentText` (one decimal, "84.7%"), so one child read two
  different figures side by side. `pointsPercentText(earned, possible)` in `core/helpers/gradebook.ts` is now the only place, with
  `percentText`'s rounding (one decimal, whole numbers lose it), and both places call it. Chosen: one decimal, because the weighted, per-type
  and per-subject percentages the server sends are already to one decimal and this way the plain and weighted figures are comparable.
  Alternative: whole numbers everywhere. Rejected: it would hide a real difference between two children at 84.7 and 85.3.

- **2026-09-29 (W3/W4 folds, F8, orchestrator's call): a family sees no weighted figure while older work is left out of it.** Once a class sets
  weights, work with no type (everything set before) drops out of every weighted figure and is counted in `untyped_excluded`, so a child with nine
  older pieces and one new typed quiz read "100% across 1 piece" above a plain total of 60 of 90, and the family's screen carried its untyped note
  only inside the weighted block. Decision: while `weighting.untyped_excluded > 0` the FAMILY screen shows the plain total (and the per-type rows,
  which are plain figures over typed work) and NO weighted figure: not the headline, not the weighted level, not a subject's weighted percentage
  (`familySeesWeighted` in `core/helpers/gradebook.ts`). It returns by itself when the older work is typed, and needs no new family copy, so the
  block that explained what was left out is removed from the family view; its strings (`marks_untyped_*`, in all six word tables and pinned by
  `family-marks-i18n.test.ts`) are kept for the day the point prefers the note to the silence. STAFF views are unchanged: they keep the weighted figure
  and the untyped note. The decision is in the SPA, not the payload: the family and teacher endpoints stay byte-identical (`FamilyGradesTest`'s parity
  test), and the family payload keeps carrying `untyped_excluded` for the screen to decide on. Alternative: show the untyped note to families beside
  the weighted figure. Rejected by the orchestrator: a family has no way to act on it, and the note still leaves the two figures disagreeing.

- **2026-09-29 (W3/W4 folds, the five cheap optional items): done, one skipped in part.**
  (1) `2026_10_03_100000` `down()` now refuses over a row holding only `curriculum_week_no` (it drops that column too). down() only.
  (2) `GradeRecord::weighted` counts `points_pieces` and `level_pieces` only for slots of weight above 0: "across N pieces" no longer counts a piece
  whose type or own weight is 0 and shaped nothing (a subject of only weight-zero work read "across 2 pieces" beside no figure). This SUPERSEDES the
  W3 line "an override of 0 keeps a piece out of the figure and still counts it in `points_pieces`" (DECISIONS W3-1 revised); the figure itself is unchanged.
  (3) The per-piece override reads "counts 30 on its own" (`weightNote`), not "(this work)": an override is a slot beside its type's, not a share of it.
  (4) `BehaviorWeek::claim` inserts plainly and catches ONLY `UniqueConstraintViolationException` (a duplicate is "already sent"); any other failure
  is thrown, and the command's per-class handler logs it as a failure. Proven on SQLite with a table whose insert breaks NOT NULL, which
  `INSERT OR IGNORE` skips silently (the SQLite mirror of MySQL's INSERT IGNORE). `.claude/rules/groups.md` said insert-or-ignore and is corrected.
  (5) BISS schedule migration `2026_10_02_130000`, DOWN() ONLY: it now leaves a row that has been saved since (`updated_at` moved) and logs one warning naming what
  it removed. NOT done, because it needs a mark on the row (a new column and a change to up(), the mistake F3 fixes): a row a SuperAdmin created
  with exactly Sunday 18:00 and never touched still reads as the seed's and is removed by a rollback. Recorded in the migration's docblock; the report is
  off by default and a rollback of this migration alone is unlikely, so the exposure is small.

- **W3/W4 folds (point, 2026-09-29): office route for grade weights.** The point's reason: a class whose teachers are ALL limited to some subjects
  (the common case at Al-Razi) could otherwise never set weights, because F5 refuses a limited teacher, setting or clearing, and the office had no
  route to the verb. Decision: the office gets `PUT /api/admin/masjids/{masjid_id}/groups/{group_id}/grade-weights` (`AdminDashboard\GroupGradeWeightsController`),
  behind `permission:manage contacts`, the gate the roster, letter tracker and class writes beside it carry. No new permission or capability. It takes
  the same `SaveGradeWeightsRequest` and runs the same write as the teacher's route: the body of `Teacher\GradebookController::saveWeights` moved into
  `Services\Schools\ClassGradeWeightsService::save` (set all five types or clear; a clear also removes every per-work override, in one transaction) and both
  controllers call it, so only WHO may call differs. The teacher route keeps its `SubjectFence::mayWeighClass` gate untouched; the office route has no
  subject fence, because the office is not subject-limited and is gated by the permission. The group is read through the tenant scope, so another
  organisation's group is a 404 and writes nothing (`AdminGradeWeightsTest`). Supersedes the F5 "Consequence to know" above (the office has no route) and
  W3-3(e)'s "the office reads and does not set"; the rest of F5 stands. The office Grades tab gets a Weights panel (a "Set weights" / "Weights" button
  above the work list) built like the teacher's: the same five inputs and confirm-before-clear, never read-only, "Saved" or the server's words on failure. It
  does not know whether the signed-in office user holds `manage contacts` (the admin SPA carries no permission list), so a user without it sees the panel and is
  refused by the server with that message; the same is true of every other office write. Alternative: mount the teacher's `saveWeights` in the admin realm
  as the gradebook reads are mounted. Rejected: it would route the office through a fence that is about subject-limited teachers, and the point asked for the
  write to be shared, not the route. Resolves ASSUMPTIONS W3F-2. Also here: the teacher SPA treats a 404 from the plan removal as "nothing to delete" (no message,
  and the screen ends as after a removal that worked). The screen removes a plan by id; `DELETE /lesson-plans?date=` has no caller in this app and stays for
  older screens.

- **W3/W4 folds (2026-09-29, G2): the by-day lesson-plan verbs never take over the shared general plan.** F4 made "the day's plan" mean the plans the
  signed-in teacher may touch, and the general (untagged) plan counts as touchable, so on a day holding [general, Arabic] a Qur'an-only teacher's by-day
  `PUT {subject: Qur'an}` found the general plan as "the day's only plan", retyped it to Qur'an and overwrote its body, and a by-day `DELETE` then
  deleted it (it used to be a 409). Rule now: a by-day PUT with subject S updates only the day's plan filed under S, or creates one; a teacher LIMITED
  to some subjects never renames "the day's only plan" by day (`onlyPlanOn` is null for them) and a limited teacher's by-day DELETE reaches only plans
  filed under their own subjects: the general plan is neither counted (no 409 for it) nor deleted, and a day with nothing of theirs is the same plain
  404 whether it is empty or holds the general plan or another subject's. The general plan stays open to a limited teacher BY ID, where they open it on
  purpose. An unrestricted teacher's by-day behaviour is unchanged, including the old screen's rename of a day's only plan and the 409 for a day of
  several. Two existing tests pinned the regression and were changed, one half each, in `TeacherSubjectAccessTest`:
  `a_limited_teachers_by_day_save_ignores_every_plan_they_may_not_touch_however_many_there_are` (its second half asserted the general plan was renamed
  to Qur'an) and `the_by_day_delete_removes_only_a_plan_the_teacher_may_touch_and_never_counts_a_hidden_one` (its second half asserted a 409 for
  [Qur'an, general], which counted the general plan as the teacher's; the 409 now needs two plans under their own subjects, Qur'an and the combined
  "Qur'an & Islamic Studies"). Alternative: keep the rename for a limited teacher when the only plan they can see is a subject's, not the general one.
  Rejected: the point's rule is that a by-day save never retypes another subject's plan, and the old screen is not what the day view uses.
  Accepted after the point's second-round review: a by-day PUT with NO subject edits the class's general plan for a limited teacher too (it upserts on the
  empty subject key). That is the access they already have by id (a plan with no subject is shared), and the empty subject is an explicit choice, not a
  resolution of "the day's plan"; the `LessonPlanController` docblock says so.

- **W3/W4 folds (2026-09-29): F8 stays client-side, and any future native family grades screen must withhold the weighted figure while `untyped_excluded` > 0.**
  F8 hides a family's weighted figure in the SPA (`familySeesWeighted`), not in the payload: the family and teacher endpoints stay byte-identical
  (`FamilyGradesTest`'s parity test) and the family payload keeps carrying `weighting` and `untyped_excluded`. The delta review found no native app in
  `~/Developer` that reads `weighting`, `by_type` or `untyped_excluded` or calls a grades endpoint, so the hide covers every current surface. Rule for the
  day one does: a native family grades screen must not draw `summary.weighting.percent`, `level_mean` or a subject's `weighted_percent` while
  `weighting.untyped_excluded > 0` (a family would read "100% across 1 piece" above a plain 60 of 90), or the server must stop sending the weighted figure
  in that state. Alternative: move the rule into `Family\GradesController` now. Rejected for now: it breaks the parity test on purpose and there is
  no client that needs it.

- **W3/W4 folds (2026-09-29, second round G1, G3, G4, G5): four small ones.** G1: the family class screen's link to the weekly report page follows the
  school's grant (`weekly_report`, `points_weekly_report`) alone and sits outside the "This week" block, which keeps the class's opt-in gate: the Friday
  email goes to every class in a granted school, so a family whose class has not opted in still gets the page the email is about. G3: another subject's work
  answers the `ModelNotFoundException` an id that names no work answers, so the body matches a missing id with debug on as well (it was a bare `abort(404)`).
  G4: the BISS schedule seed `2026_10_02_130000` up() stamps `created_at` and `updated_at` from one `now()`, so down()'s "saved since" guard cannot skip the
  row it wrote; this edits an applied migration's up(), allowed once because no persistent database has run it (staging was checked 2026-09-29 20:15 ET
  and has none of W3/W4's migrations; production has none) and the change is the timing of one data row, not the schema. G5: one predicate (`isUntyped`)
  decides which work "has no type", so simple-scale work is never badged or counted as waiting for one, in the teacher's list or the office's; the office
  list shows "not averaged" for a bare simple piece and the untyped note under the list; and the reason for a missing weighted figure is "N pieces have no
  type, left out" while any is waiting for a type, and "never averaged" only when none is.


## 2026-09-27 — The canary attributes gallery rows through the `model` morph pair, not a new tenant key
Decision: `config/canary.php` gains `tenant_morphs => ['model']`. For
row-ownership attribution only, a relation keyed on `model_id` (Masjid::gallery)
attributes a row to organisation `model_id` only when `model_type` is Masjid's
morph class. TenancyCanary::ownerKeyFor() is the one rule, used by the lookup
and by the `tables_available` inventory, and the lookup adds the type clause
itself (TenancyCanary::ownerMap): a plain hasMany may carry no type clause, and
Relation::noConstraints drops a morph relation's own.
Alternatives: (a) add `model_id` to `canary.tenant_keys`. Rejected: tenant keys
are also read out of response bodies, and the mobile services, announcements,
features, about and donation-link endpoints serialize raw media rows
(MobileMedia::envelope), as splash does with its image row, so a Service icon's
`model_id` (the service's id) would read as a cross-tenant read on a correct
answer. (b) Only put `where('model_type', …)` on
Masjid::gallery() the way logo() has it. That is right for the app, and it
ships beside this as its own change (next entry), but on its own it changes
nothing for the canary, which still could not tell that `model_id` names an
organisation. (c) Declare `items` a global
bucket, or silence exit 3. Rejected: that stops watching the rows, the
opposite of the fix.
Rationale: since the MEC import at 2026-09-22 00:26 UTC (26 photos, Masjid 13,
`galleries`, media ids 1000718-1000743), every hourly run exited 3 `partial`
with `row_ownership_unplaced` on `api/v1/gallery`. The floor was right: rows
were served and nothing could place them. The fix makes them placeable, so the
floor (`unplaced === []`) stays exactly as strict. A gallery swapped between
organisations now exits 1 (test pinned), and a Service's photo whose id equals
a masjid's id is never attributed to that masjid (pinned on ownerMap() with a
relation that carries no type clause, so it holds whatever Masjid::gallery()
carries). With `tenant_morphs` emptied, the production symptom comes back
(control test). Mutation runs on the droplet:
- Against the pre-fix command, the four end-to-end gallery tests fail. MEC's
  shape reproduces production's exact "NOT TRACED: items" line, and the swap
  exits 3, not 1.
- Before Masjid::gallery() carried the type half, removing only the canary's
  type clause failed exactly the Service-photo test, with a false
  foreign_rows accusation.
- On the integrated tree (21e77c81), removing ownerMap()'s type clause fails
  exactly the_ownership_lookup_adds_the_type_half_itself. The end-to-end
  Service-photo test still passes there, because the relation now carries the
  clause itself; that is why the ownerMap() pin exists.

## 2026-09-27 — Masjid's gallery, header and footer logos read media by the whole key
Decision: Masjid::gallery(), header_logo() and footer_logo() gain
`->where('model_type', self::class)`, as logo() and brandDerivative() already
had. Spatie's `media.model_id` is half a key.
Alternatives: leave them, since production holds no colliding row. Rejected:
nothing stops another model from writing a `galleries`, `header_logos` or
`footer_logos` collection, and the gallery relation also DELETES
(MasjidGalleryController::delete and the index's orphan cleanup), so a collision
would let one organisation's admin screen delete another organisation's file.
Rationale: measured read-only on production 2026-09-27, every media collection
belongs to exactly one model type. `galleries` is 26 Masjid rows (masjid 13),
`header_logos` and `footer_logos` are empty, so no served payload changes.
tests/Feature/MasjidMediaModelTypeTest.php builds the collision (a Service,
owned by the other organisation, whose id equals the masjid's id). It covers
/api/v1/gallery (index and show), /api/mobile/masjids/{id}/gallery, the admin
gallery index, its orphan cleanup and delete, /api/v1/settings header and
footer logo urls, and the app's header_image_url. Against the old relations
all six tests fail.

## 2026-09-29 — Manara mail sends from manara.hopetechapps.com (branch feat/manara-sending-domain)
Decision: Manara mail moves from `notifications@tapcraft.tech` to
`notifications@manara.hopetechapps.com`, a Resend domain on the Manara platform host. The display
name stays the organisation's. The switch is production's `MAIL_FROM_ADDRESS` plus a new Resend key
scoped to the new domain, which is also the rotation the 2026-08-26 key exposure is owed. No mail
class changes: every Mailable already reads `config('mail.from.address')`, now pinned by
tests/Feature/MailSendingDomainTest.php. `send` and `rsend` join `cloudflare.reserved_labels`,
because Resend's return paths (`send.manara` and `rsend.manara`, CNAMEs to `forge.rmta.net` that
Resend's auto-configure created) sit in Studio's managed namespace, where an organisation's record
would displace them.
Alternatives: `notifications@hopetechapps.com`. Rejected: the root is Hope Tech's Zoho mailbox
domain with no DMARC, so school volume and any complaint spike would land on the company's own
mail, and adding DMARC there would govern Zoho too. A deeper name such as
`mail.manara.hopetechapps.com`. Rejected: longer, and it isolates nothing more, because
`manara.hopetechapps.com` sends no other mail. Keep tapcraft.tech. Rejected: another product's name
on every parent email; it fails the Schools page check P5.
Rationale and runbook (records, order, owner gates, rollback): docs/mail-sending-domain.md.
`php artisan mail:test-send` proves a domain before and after the switch: one message to one named
address, framed as the school mails are, with `--from` for a domain not yet in `.env` and
`--prompt-key` for staging, whose `.env` keeps RESEND_KEY blank. A mailer that delivers nowhere
(log, array) exits non-zero, so a send into the log file cannot read as a success.
DMARC (owner, 2026-09-29: Cloudflare DMARC Management): that feature works on apex domains only, so
it publishes `_dmarc.hopetechapps.com` at `p=none` and `manara.hopetechapps.com` inherits it by the
organisational-domain fallback. No `_dmarc.manara` record, which would take its reports out of the
dashboard. `p=none` monitors and does not change delivery, including the company's Zoho mail.
Known limit: `manara.hopetechapps.com` has no MX, so a reply to mail without an organisation
Reply-To bounces.

## 2026-09-30 — W5 S14 decided (point): the office sees and cancels, but cannot read, a scheduled item before it is sent

- **Supersedes** the "Decision to confirm (S14 reading)" above, which let the office read scheduled items.
- **Rule.** Before an item (a class story or a new conversation) is sent:
  - The class's teachers and the author read it in full.
  - The author edits, moves and sends it now.
  - The office (`manage contacts`) sees its metadata only: class, author, scheduled time, kind, audience (`one_child`
    without the child's name), status, failure reason. Payloads carry `content_hidden: true` and omit title, subject,
    body and attachments. The office may CANCEL it, but edit, move and send-now are 403.
  - An office administrator who also teaches the class is a teacher here.
- **Why** (the point): a conversation about one child is then no more visible before it is sent than after it, when
  the office reads it only through roster standing. A draft is not a disclosure the office needs in order to stop it:
  cancelling needs the time and the author, not the words.
- **Where.**
  - GroupAudience::mayReadUnpublished (teachers of the class) and mayCancelScheduled (teachers + office).
  - The admin GroupPostsController (metadataOnly, readablePostsFor for attachments/playback) and
    GroupMessageSchedulesController (metadataOnly).
  - GroupMediaPlaybackController.
  - SPA: ScheduledItems.vue (a note instead of the words; Cancel only) and scheduledSend.ts (canCancel, contentHidden).
- **Widening it** (the office reads drafts) is a deliberate owner decision, not a default.

## 2026-09-30 — W5 fold of the point's review (items 1–10, a–h)

- **Done with tests:**
  - (1) The child is resolved once; open() refuses participant scope with no child.
  - (2) retained_until must be after the send day; the purge skips a waiting story.
  - (3) A deadlock or lock-wait is handed back for the next run; any other error is `failed` and shown in the author's
    Scheduled list.
  - (6) The run line goes to the monitors channel at info; stuck > 10 min is an ERROR.
  - (7) An identical same-second re-save is not "no longer editable".
  - (10) Withdrawn consent gets nothing.
  - (a) Both scheduling migrations' down() refuse while items wait.
  - (b)(d) Docblocks corrected.
  - (e) The roster merge treats a scheduled/sending/failed conversation about a child as a record about them.
  - (f) The legacy row is left alone by the sweep; the duplicate backfill test is removed.
  - (g) DST fall-back test.
- **(4) A queue that cannot take the email** after a story or conversation is out: logged at ERROR ("sent, email not
  queued"), not counted as a failure, and the item stays sent. There is NO re-dispatch path: that email is lost and
  the log names the item. Accepted as the smaller change; revisit if it is ever seen.
- **(5) The minute-boundary race:** store() decides the email from the saved row (announced_at set by the creating
  hook), not from the request. No test reproduces the sub-second race (ScheduledTime refuses send_at == now); the
  ordinary-post email tests cover the path.
- **(8) Gates re-checked inside open()'s transaction: NOT done.** After (1) the only window left is the author's
  standing changing in the milliseconds between the gate and the write; the point rated it low.
- **(c) Backfill timezone:** on production 2026-09-30 the point read MySQL session/global time_zone = SYSTEM,
  system = UTC, now() = utc_timestamp(), so `published_at = created_at` cannot move a story into the future. The mysql
  connection is NOT pinned to +00:00 (a global config change); re-check this if the database's time zone ever changes.
- **(9)** Rollback runbook: deploy/README.md, "Rolling back scheduled stories and conversations".

- **2026-09-30 (school side quest W6 fold): main merged, and the point's review of the class store folded (Gate A, all fourteen of Gate B).**
  Main 5ba2fae0 merged (three mechanical conflicts, both sides kept). Each fold has a test that fails without it (one mutant run with the
  Gate A and B source reverted and the tests kept: 16 of 19 new PHP tests failed; the other 3 are the two HTTP tests B13 asked for (behaviour that already held) and a guard of B3's
  boundary (a date that still exists is not given back); the SPA's
  mounted tests fail against the pre-fold screens and against single-line mutants of the message and busy guards).
  - **A1: `dedupe_key` is `utf8mb4_bin` on MySQL, set in the table's own CREATE.** Chosen over main's ALTER pattern because no W6
    migration has run on any persistent database, so there is nothing to alter; SQLite keeps the plain column. Proven on MySQL 8.0.46 in a
    throwaway database on staging: with the migration, `redeemed:1:abcDEF12` and `redeemed:1:ABCdef12` are two rows; with the column
    ALTERed back to the table default the second is `ERROR 1062 Duplicate entry`. The suite pins the compiled MySQL statement.
  - **A2: `markPrizesConverted` inserts and catches only the unique violation** (as main's `claim()`); **A3: 100200, 100300 and 100400
    refuse to roll back while ledger rows exist** (100400 included: it holds each week's rate, which every adjustment reads).
  - **Already fixed by the workflow's own fix stage (89452859, 9308902f), not redone:** B1 (the delta decided under the student's lock),
    B2 (expiry re-runnable per cutoff), B6 (a week keeps its rate), and the SPA half of B5 (one request id per write). **B1 was proven on
    MySQL 8.0 here:** two `bucks:mint` runs made to meet at the student's row lock (a third session held it, both runs had read the week
    before it) wrote ONE `adjusted +3` with the current minter and TWO (balance 11 where 8 was owed) with the pre-fix minter of d2820e85.
  - **B3: a cutoff waits `expiry_grace_days` (7) and a vanished cutoff is given back.** Alternative: make the office confirm an end date.
    Rejected: the dates are already typed on screens that serve other features, and a confirm step does not help a date typed wrong with
    confidence. The give-back is a `reversal` row pointing at the `expired` one (append-only both ways); a reversal of a prize is refused
    only across an expiry that still stands.
  - **B5 server: `request_id` REQUIRED on redeem and cash-out.** **B11: a replay with another prize or amount is a 409.** **B8: a prize edit
    locks the row and compares `expected_stock`** (compare-and-set). Alternative: a delta from the loaded count. Rejected: it needs the same
    loaded count and silently merges two people's intentions; a 409 with the current count lets the editor decide. The screens send the
    stock only when it changed. **B10: an empty `is_active` is no change.**
  - **B7: `mayReceiveClassStoreTotals` checks the bound tenant, and the reconciliation hides classes under 5 current students**, left out of
    the totals too (a total that included them gives them back by subtraction). Alternative: merge small classes into an "other" row.
    Rejected: with one small class, "other" is that class.
  - **B9: the purge removes only a zero-sum set of a child no longer enrolled** (left, or the class ended). Cost: a withdrawn child's
    positive balance is kept past 365 days until W6-C1 is decided (ASSUMPTIONS W6-A12).
  - **B4, B12, B13, B14 (the screens):** the refusal message survives the reload; "Show earlier" and keyboard-operable students on the
    teacher's screen; each screen MOUNTED in `npm run test:spa` by a small harness (`tests/support/mountSfc.ts`: the project's own
    `@vue/compiler-sfc` and Vue's `createRenderer`, no DOM and no new dependency). Alternative: add jsdom and @vue/test-utils. Rejected
    for this fold: two new dev dependencies and a lockfile change on a branch that must not touch main's toolchain; the harness can be
    swapped for them later without changing a test's intent. HTTP tests added for the 409 `balance_changed` and the 422 `expired`.
  - **Gate C.** C2 built as recommended and reversible (records export dataset `bucks_ledger`, no note). C1 only in part: a left child's
    refusal now says why; the carry-over on a move and the 30-day office hold are an ENABLE BLOCKER (W6-C1). C3 is an ENABLE BLOCKER (no
    settings screen). The Arabic and other machine-drafted parent copy is an ENABLE BLOCKER (W6-A13). The store and paper cash-out stay OFF.
  - **Also checked on MySQL 8.0 while the throwaway database existed:** the expiry and its give-back (`expired -8` then `reversal +8`
    after the end date moved, nothing on the third run), and the purge's grouped query (a left child's zero set removed, a left child's
    8 Bucks and a current child's 5 kept). Database and user dropped and the copy deleted afterwards.

## 2026-09-29 — Guide split: the school's separated Qur'an, Arabic and Islamic Studies weeks replace the combined column for Pre-K to Grade 2, weeks 1-8 (feat/school-guide-split, off b5c2f808)
Decision: the school's separated guide ("First Semester / Quarter 1 suggested pacing for all subjects from Pre-k
to Grade 2", dated 2026-09-07, pinned by the sha256 values in the data file's `source`) is in hand. It supersedes
W3-6's "T-001.4 is partial until the school's revised weekly guide" for these cells only. `curriculum:import` (extended) loads `database/curriculum/al-razi-qai-split-2026-27-q1.json`
after the base guide: 96 new rows (Pre-K, Kindergarten, Grade 1, Grade 2, times Qur'an, Arabic Language and
Islamic Studies, times weeks 1-8) replace the 32 combined cells for the same grades and weeks, in one
transaction. Masjid 14 goes from 1512 to 1576 rows. The combined column stays for weeks 9-36 in Pre-K to
Grade 2 and for all 36 weeks of Grades 3-5 (220 rows): the school has given nothing else for them.
The production apply is NOT part of this change and waits for the owner's yes on the exact dry-run counts.
- **Mapping, name for name, nothing joined.** Focus Skill to `focus`, Objective to a new `objective`, Learning
  Outcome to a new `learning_outcome`, Standard Code to `standard_code`; `assessment_note` is NULL (the plan has
  no such column). A single-slot mapping loses the school's words ("Memorize Surah Al-Ikhlāṣ" is an Objective,
  "Letters أ–ب" a Focus Skill) and a joined string is one the school never wrote, which a byte-for-byte test could
  not state. Migration `2026_10_05_100000` adds both columns nullable; its `down()` refuses while any row holds
  either (the inverse import empties them first).
- **Subject names are the catalogue's exact bytes** (`Qur'an` with U+0027, `Arabic Language`, `Islamic
  Studies`), not the combined column's U+2019, because `LessonPlan::subjectKeyFor` does not fold apostrophes and
  a second spelling would let one class-day hold two Qur'an plans. The school's own heading words stay in the
  file's `source.tables` block. Grade labels are the live guide's spellings, because the lookups compare exactly.
- **Data path is the importer, not a migration.** A migration runs everywhere at deploy (code and data would
  ship as one step), cannot print a dry run for the owner to approve, and no migration writes guide data. The
  importer gains `--dry-run` (exact counts plus one `PLAN {json}` line, writes nothing), `--expect=` (refuses on
  any different count, so the apply does only what was approved), `--verify` (read-only, byte for byte, exit 1 on
  any mismatch), a masjid guard (`for_masjid`: id, name_contains, org_type), an inverse file and a full snapshot
  written 0600 before any change (read back and counted), one transaction that re-counts before it commits, and
  a WARNING log line with the file's sha256 and provenance (prod logs warning and up). `--fresh` now deletes
  INSIDE that transaction (it used to delete outside it, so a failed import lost the guide), and is refused
  together with `replaces`. Idempotent: a second apply is delete 0, insert 0, update 0, unchanged 96.
  The ordering hazard is real and pinned: re-running the base file re-creates the 32 combined cells (an import only
  upserts); re-run the split file, which is idempotent.
- **Teachers' records are never written.** Plans and assignments copy text and hold no key to the guide, so
  deleting a guide row changes none of them, and an assignment whose unchanged snapshot names a deleted cell still
  saves on edit. The dry run counts `plans_touching`, `assignments_touching` and `plans_combined_subject`, and
  `--expect` pins them. The owner approves counts from a dry run taken against production just before the apply,
  never from an earlier count: teachers write plans every day.
- **What people see.** The prefill and standards payloads carry `objective` and `learning_outcome` ONLY on rows
  that have them, so every July row answers byte for byte as before (the pin in
  `TeacherCurriculumStandardsTest` is unchanged). The standards de-duplication key includes the objective, since
  K and Grade 1 `x.QUR.MEM.1 Memorization` (weeks 4 and 7) and Grade 2 `2.AAL.ALPH.1 Alphabet` (weeks 2 and 3)
  repeat a code and focus with a different Objective; the matcher reads focus, objective and outcome as the
  row's own words. SPA: the week select and both standards lists show the objective and key by it; a pick writes
  the Objective (else the Focus Skill, as a week prefill does); the Learning Outcome fills the outcomes list only
  where the teacher wrote none or the guide wrote the only one there (`outcomeFill`, the rule `autoFill`
  applies to every field); the Islamic integration box takes one line per Islamic sibling when there are several
  and the bare focus when there is one (`islamicIntegration`), so Qur'an no longer falls into "other subjects".
  The family portal, the catalogue, the COMBINED list and `SubjectFence` are unchanged.
- **After review (2026-09-30).** (1) A sibling line in the week payload now carries `objective` when the row has
  one (the base guide's rows have none, so they stay exactly `{subject, focus}`), and the Islamic integration box
  writes `focus — objective` for such a sibling, so "Memorize Surah Al-Ikhlāṣ" is not lost behind "Memorization".
  (2) `outcomeFill` empties the outcomes list when the guide wrote the only entry there and the next pick or week
  has no Learning Outcome, as `autoFill` does for every other field; a teacher's own entry is still never touched.
  (3) The week select gains "Another week…", which swaps in the number input, and a plan holding a week past the
  list opens on the number input: the separated weeks stop at 8, and before the split these subjects had the
  free 1-52 input.
- **Not done (options, not built).** O-1: the plan's ELA, Math, Science, Social Studies and STEM are not
  imported (different code system from the live guide; Grade 2 Math absent, Grade 2 ELA overview only, Grade 2
  Science in two differing copies). F-1: a hint on a split subject's week list that weeks 9+ are still under the
  combined column. F-3: which per-grade Arabic outcomes document is current is NOT settled by the school's
  files; no outcomes list is imported.
- **Correction.** An earlier planning note said the school's document carries no Qur'an, Arabic or Islamic
  Studies codes. It does: the 7 Sep plan has `PK.QUR.*`, `PK.AAL.*`, `PK.IS.*` and the K, 1 and 2 equivalents,
  school-authored, imported verbatim and never invented. `.claude/rules/groups.md` says so now.
- **Faithfulness.** `database/curriculum/sources/al-razi-detailed-pacing-plan-2026-09-07.txt` is committed whole
  (sha256 `40cc44ac38a4b8b2be01fd07ef7fdf081682f0ff17ca78968992024a82ea8a0f`); the generator
  `database/curriculum/tools/build-al-razi-qai-split.mjs` copies whole lines by number and exits non-zero on any
  surprise; `CurriculumSplitSourceFaithfulnessTest` re-reads the .txt by line number and `assertSame`s all 480
  stored strings. The build also compared all 96 rows' five cells against the .docx tables directly (OOXML
  `w:tbl`, not the .txt or the extractor's JSON): 0 differences.
- **Open with the school (owner questions, defaults imported):** K and Grade 1 Qur'an week 3 says Recitation in
  the quarter table and Tajwīd in the day plan (the quarter table is imported); Pre-K has two Qur'an sections
  (the full one at L1733 is imported, not the overview at L1723); whether plan week n is guide week n (assumed).
Deviations from the design: (1) the inverse file's `for_masjid` falls back to `{id}` when the source file
names none, so an inverse is never unguarded; (2) an inverse row whose own `source_label` was NULL restores as
NULL (an explicit null in a row is kept, not replaced by the file's label); (3) the two safety files never
overwrite an earlier pair made in the same second (`-1`, `-2` suffix); (4) `--verify` is also refused with
`--fresh`; (5) under `--fresh` the plan reports delete = every existing row and insert = every file row, and
the inverse restores all existing rows and removes only cells the file created.

### 2026-09-30 — Guide split, second review folded (feat/school-guide-split)
- **An apply refuses by default when it would leave something behind.** `plans_touching` or `assignments_touching`
  above 0 (a teacher record copies a cell being deleted), or `delete_absent` above 0 (a cell the file replaces is
  not there: the file's spelling is not the database's, so it would insert 96 rows beside 32 it meant to replace,
  or the file was already applied), makes `curriculum:import` exit 1 and write nothing, with or without `--expect`,
  unless `--allow-references` is given. `--expect` alone is not the yes: it pins numbers, the flag accepts them.
  A dry run still prints all three counts and says an apply needs the flag. A second apply of the same file
  therefore needs `--allow-references` too (delete_absent 32).
- **Runbook, production.** Every step below runs as `sudo -u www-data env HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan
  curriculum:import ...` (the web user, with a writable home). (1) Dry run against production just before, read the
  counts. (2) The owner approves those exact counts. (3) `sudo -u www-data env HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan curriculum:import 14 <file>
  --expect=delete=N,insert=N,after=N,plans_touching=N,assignments_touching=N,delete_absent=N --allow-references`
  with the six counts the owner approved (the dry run prints this line when the flag is needed). The flag waives the
  refusal for any counts, so the pins on `plans_touching`, `assignments_touching` and `delete_absent` are what stop
  an apply when a teacher saves a plan between the dry run and the apply (run as the web user, as `bin/deploy` runs migrate, so the log and the safety directory are
  not left root-owned). (4) `--verify --expect=after=<the dry run's after>`: `--verify` compares only the file's
  cells and the replaced keys, so a row nobody planned passes it; the total it prints next to the expected `after`
  is the check on everything else, and a different total fails it. The "Curriculum import applied" log line records
  whether `--allow-references` was given and the `--expect` pins, so a waiver is visible afterwards.
- **Rollback restores content, not ids or timestamps.** The inverse file re-creates a deleted cell through an
  upsert, so it comes back with a new id and new `created_at` / `updated_at`. Nothing references a cell's id (plans
  and work copy text), and every read orders by content, so nothing depends on them. It is "the same cells", not
  "the same rows byte for byte". The inverse file is an ordinary import, so once teachers have planned on the split
  cells (Qur'an, Arabic Language, Islamic Studies, Pre-K to Grade 2, weeks 1-8) applying it refuses with
  `plans_touching` above 0 until `--allow-references` is added: dry-run the inverse, have its counts approved, and
  pin them as in the runbook.
- **Public repository.** The data file, generator, tests and these notes carry no private document id, no
  internal note field, and no reference to internal working notes or local paths. The source is pinned by `docx_sha256`
  and `txt_sha256`, a title and `document_date`. The committed source `.txt` stays for now (whether the school's
  text may be public is the owner's call); `CurriculumSplitSourceFaithfulnessTest` reads it when present and, when it
  is absent, checks the data file's sha256 pins and skips the line-by-line comparison with a message, so dropping the
  `.txt` later is a one-file deletion (the generator needs it to rebuild, which is not a test).
- **Weeks past the split.** A plan for Qur'an, Islamic Studies or Arabic Language asking for a week the split has no
  row for (weeks 9 on) in a grade the split covers (Pre-K to Grade 2: the grade has split rows) gets the combined
  "Qur’an & Islamic Studies" line for that week as the prefill. Grades 3-5 have only the combined column, so they get
  no fallback for any separated subject (Arabic has no column there at all, and "the school has not separated this
  week yet" would be false); the owner has not decided Arabic or Qur'an prefills for them. Where it applies, the
  prefill is marked `from_combined_guide` with `guide_subject`, and the plan form says so. No split row is invented,
  and the combined line is not its own sibling. The fence applies: an Arabic-only teacher, who may not read the
  combined column, gets no fallback.
- **The subject fence covers the guide reads.** With `?group_id=` the week list, the cell, its siblings and the
  standards search use the same `SubjectFence` limits `subjectsFor` uses, by subject key. Qur'an-only and
  Islamic-Studies-only teachers still see the combined column; an Arabic-only teacher does not. Only the subjects a staff
  subject covers (Qur'an, Arabic, Islamic Studies and the combined column) are fenced among a cell's siblings:
  Mathematics, Science and the rest have no staff subject, so a limited teacher still gets those integration lines. The SPA now sends the
  class on the prefill, the plan's standards search and the assignment picker. Without `group_id` nothing is fenced,
  as before.
- **Minors.** `in_scope` and the scope rank compare by subject key, not exact string. An empty Objective or Learning
  Outcome is stored as NULL by the importer and read as absent everywhere. A single Islamic sibling that is not the
  combined column carries its subject label; the combined column stays bare.

## 2026-09-28 — Donation row build extracted from the door: `DonationService::createPendingDonation`
Decision: the `Donation::create` that `createDonationCheckout` ran before opening Stripe is now
`DonationService::createPendingDonation`, and the door calls it and reads every value back off the
returned row. It writes the row and nothing else: no Stripe call, no email, and NO gate (form or
fund open, `canAcceptDonations`, giving switch, amount bounds stay in `DonationsController`). The
universal cart calls it only AFTER the shopper has paid, so a gate here would turn taken money into
an unrecorded payment. `application_fee_amount` and `idempotency_key` are optional inputs whose
defaults are the door's; `is_zakat` and `zakat_source` are not inputs, only the giver's `zakat`
answer is, and `ZakatDesignation::resolve` remains the one place it is decided.
Alternatives: give the cart `Donation::create` of its own (a second zakat/gross-up implementation
that drifts), or route the cart through `createDonationCheckout` (opens a Session per line).
Rationale: behaviour-preserving move; pinned by `tests/Feature/Cart/PendingDonationTest.php` and the
untouched `DonationFlowTest`. Settlement (`markSucceeded` with the cart's own PI and this line's own
fee/net, then the receipt) is a separate task; `markSucceeded` has no status guard, so the cart must
check `pending` itself.

## 2026-09-28 — Cart settlement (slice 4b): records exist only once paid
Decision: the universal cart's one webhook creates each line's real record, already paid, in ONE
transaction (`CartSettlementService`), routed by a cart question asked LAST in
`StripeWebhookController::dispatch()` (`cart_order_uuid` on the org's own account,
`cart_charge_ref` on a holder's). It calls the three extracted writers unchanged and asks no gate,
so a payment after a form closed, a menu closed or a fund was deactivated is still recorded and
logged. What settlement needs is FROZEN at checkout on `order_items.payload` / `price_snapshot`
(the unshipped orders migration was edited, no new one): a form's `FormPayment::quote()`, a
meal's frozen line, a donation's `{intended_minor}`; nothing is re-quoted at webhook time.
Emails, the lunch confirmation and the donation receipt run after the commit and only for a line
whose settle call returned true. Donation `fee`/`net` stay null: the basket's one fee cannot be
split honestly per line.
Alternatives: create pending records at checkout and settle them (rejected 2026-09-28: unpaid food
on the kitchen board, and a second payment page per ticket); re-quote at webhook time (a tier
boundary or the card switch can null the quote after the money is taken); email inside the
transaction (a rollback would leave an email for nothing).
Rationale: money already taken must always be recorded, and nothing may be visible before it is.
A genuine failure to record a paid basket rolls back everything and is rethrown so Stripe retries
(refusals, which no retry could fix, return 200 with a warning). Pinned by
`tests/Feature/Cart/CartSettlementTest.php` and `CartWebhookRoutingTest.php`; every older webhook
test passes untouched.

## 2026-09-29 — Cart settlement review fixes (slice 4b): close the basket, backfill the payer, pin the holder
Decision: the settlement review confirmed five defects, all fixed in `feat/universal-cart`.
(1) Settlement closes the basket in its transaction (`Cart::STATUS_CHECKED_OUT`, lines deleted; the
cart is locked before the order, checkout's own order, so the two cannot deadlock) and
`CartCheckoutService::checkout()` / `acknowledge()` refuse a closed cart; checkout also refuses a
basket whose fingerprint already has a PAID order on the same cart. (2) A session event that
finds the order already paid backfills what `payment_intent.succeeded` could not know (donation
contact and session id; a meal order's placeholder name, phone and e-mail) and runs the steps that
were skipped (donor link, receipt delivery, meal confirmation) through their once-only paths;
nothing is re-settled. (3) A basket paid on a holder's account pins each form row
(`charge_account_id`, `charge_masjid_id`) in the settlement transaction, so the holder's refund or
dispute flags it. (4) The legacy `amount_due` and `entry_count` are frozen into the form line's
`price_snapshot` at checkout and written over the writer's live figures. (5) Cart pages disable
Adaptive Pricing, and a payment intent in another currency than its order's is skipped at info
level; only the session event reports a refundable mismatch.
Alternatives: leave the cart open and rely on the fingerprint alone (rejected: the same lines are
one tab away from a second charge); have the intent defer to the session event (rejected: a lost
session event would leave paid money unrecorded); stamp `charge_ref` on the row (rejected: unique
per row, and a basket has one).
Rationale: money is taken once and recorded once, and the record says who paid and on whose
account. Pinned by `tests/Feature/Cart/CartSettlementReviewFixesTest.php`; the existing cart and
webhook tests are unmodified. ASSUMPTIONS #28-#30.

## 2026-09-29 — Cart settlement review fixes, round 2 (slice 4b): one receipt, refunds on the order, only paid lines leave the basket
Decision: the check of bc4771df found one major, one design gap and two minors; all fixed in
`feat/universal-cart`, in the still-unshipped `2026_09_28_090000` orders migration (no new one).
(A) A donor receipt could be mailed twice when `payment_intent.succeeded` and
`checkout.session.completed` arrive together: both queue `donorAndReceiptStep`, and the controller's
`deliverReceipt()` is check-then-send. The step now claims the line first
(`order_items.receipt_claimed_at`, `UPDATE ... WHERE receipt_claimed_at IS NULL`); only the process that
changed one row links the donor and hands the receipt on. `deliverReceipt()` is untouched. A step with
no address and no contact on the gift claims nothing (else the intent's step would use up the claim the
session event's step needs); a claim that delivers nothing or fails is released. (B) A refund or dispute
on a basket's charge is flagged on the ORDER (`orders.charge_flag`, `charge_refunded_minor`,
`charge_flagged_at`) by a new cart arm that runs before the form arm, and `handleChargeFlag` skips every
form row a cart settled. A basket's rows share one payment intent and the event names an amount, never a
line, so any per-row flag was a guess (and flagged the first row only). Fix 3's pin stays: it still
gives the refund instruction. (C) Settlement removes from the basket only the lines the order paid for
(type, id and the canonical payload hash, stored at checkout as `order_items.cart_payload_hash`), closes
the basket only when nothing is left, and after the commit expires the cart's other pending pages
through `CartCheckoutService::closeOtherPages()`, so page B can no longer charge lines page A paid.
Alternatives: dedupe the mail on `receipt_delivered_at` alone (rejected: it is written after the send, so
it cannot be atomic; the brief also forbids changing `deliverReceipt()`); attribute a partial refund to a
line by amount (rejected: a guess with money on it); match paid lines by comparing the order line's
`payload` with the cart's (rejected: a meal's order payload is reshaped, so only a hash stored at
checkout compares exactly).
Rationale: money is mailed for once, flagged where the fact is, and never dropped unpaid. Two calls the
brief left open: the cart arm accepts a linked basket's order (its masjid is the CHILD, not the account's
holder) on its pinned account alone, since that is the motivating BISS case; and the recorded refunded
amount is the largest figure seen (Stripe's is cumulative), so a late event cannot lower it. Pinned by
`tests/Feature/Cart/CartSettlementRound2Test.php`. One existing assertion changed:
`CartSettlementReviewFixesTest::a_linked_baskets_registration_is_pinned_to_the_holder_so_its_refund_flags_it`
now expects the order flagged and the row not (that is the design change). ASSUMPTIONS #28, #30-#32.

## 2026-09-29 — Cart settlement review fixes, round 3 (slice 4b): a failed send, an early refund, a trashed holder
Decision: the check of ba50f193 found three minors; all fixed in `feat/universal-cart`. (1) A receipt could
be left unsent: the step that wins the line's claim keeps it, `deliverReceipt()` is best-effort, and a
failed send leaves `receipt_delivered_at` null with the claim still held, so every later step gets 0 rows.
The step now hands the line's id back with the receipt, and the controller's cart path releases the claim
when the gift is still undelivered after the send. `deliverReceipt()` is unchanged, and so are its other
callers. (2) A refund or dispute that names an order found by its payment intent but not yet paid was
acked in silence, and Stripe does not redeliver: it is now flagged (flag and amount) with a WARNING naming
the order and saying it was flagged before settlement recorded it; a charge no order carries still writes
nothing but leaves an INFO line. (3) `flagOrder()` resolved the account holder with `withTrashed()->first()`,
which can name a trashed organisation that shares the account id with a live one (the unique index covers
live rows only): it now uses one `accountHolder()` lookup, live first and trashed only as a fallback, shared
with settlement's `resolve()`.
Deferred on purpose: `closeCart`'s match does not include quantity. No endpoint edits a line in place yet,
so it is latent; it belongs to the add-to-basket slice.
Alternatives: release the claim inside `deliverReceipt()` (rejected: the brief keeps it unchanged and other
paths call it); hand the controller a release callback (rejected: the line id is data, and a static release
on the service is the same one the step already uses); ignore an unpaid order's flag until it settles
(rejected: the event is never redelivered).
Rationale: money is mailed for once, but a receipt that failed to send is not lost, and a dispute is never
dropped for arriving early. Pinned by `tests/Feature/Cart/CartSettlementRound3Test.php`; no existing test
changed. ASSUMPTIONS #33, #34.

## 2026-09-29 — Universal cart public endpoints (slice 5): dark by default, the house idiom, priced before it is kept
Decision: the basket is exposed over HTTP (`CartsController`, `CartOrdersController`, `CartLineAdder`,
routes in `routes/api_v1.php`) and INERT until the owner switches it on. (0) `config/cart.php`
`enabled` (`CART_ENABLED`, false) and `masjid_ids` (`CART_MASJID_IDS`; empty = every organisation once
on; anything malformed becomes `[0]`, nobody, never "everyone"). One middleware, `cart.enabled`,
throws the router's own not-found exception with the router's own message, so an off cart is the same
bytes as an unknown route in debug and out of it, before any throttle or query; the routes are
registered either way, so the route cache is stable. (1) The `/api/v1` house idiom: `masjid-id` header
int-cast, `<= 0` 400, `PublicTenant::exists()`, every query hand-filtered and every create stamped, the
`{status, message, data}` envelope, a 422 `{status:'failed', data:{field:[...]}}`. The token is 32 random
bytes as hex, returned once in the JSON body and stored as `Cart::hashToken()` = HMAC-SHA256 on
`APP_KEY` (the FamilyInviteService construction; the migration comment that said plain SHA-256 is
fixed); a wrong token, another organisation's, an expired, offboarded or missing basket are one 404
(`This basket is not available.`, the same sentence for the organisation half). Expiry slides 7 days on
every SUCCESSFUL write. A paid (`checked_out`) basket still reads and answers 422 to every write.
Each line is validated as its own door validates it (form: `withoutUnusedPriceAnswers`, `FormSchema`
validator, `only()`; a form with file fields refused in one sentence; meal 1..99, a catalogue pickup read
in the ORGANISATION's timezone and stored as an absolute instant because the pricer and settlement
parse it without a zone, and required as the kitchen door requires it; donation 100..99999999, `zakat`
only when answered, `recurring` refused), then inserted under the basket's row lock and PRICED by the
unchanged `CartPricer` over the whole basket; a line that comes back `gone` rolls back and is refused
with the source's own reason. A form line's price and place count come from `FormLineSource`, a dish's
price from the dish. `client_line_key` (unique per basket) makes an add idempotent; the same key with a
different request is a 409, told apart by a second column, `client_line_hash` (the keyed
`FormResponse::payloadHash` of what was asked for, taken BEFORE validation), so a retry is recognised as
itself whatever has changed since (a form that closed, numbers encoded as strings). The add answer is
the priced basket plus `line_id`. (2) The doors' gates the cart services lacked now sit in the line
SOURCES, so checkout re-asks them: `giving` off (`DonationLineSource`, the door's sentence verbatim),
`jummah_lunch` off (`MealLineSource`), a form with file fields (`FormLineSource`); each `reprice()` gained
an optional trailing `?Masjid $org`, passed by `CartPricer`, loaded from the model when omitted so the
gate can never be skipped; `canAcceptDonations()` stays the payee rule. (3) `orders.buyer_name` and
`buyer_phone` (unshipped orders migration, edited in place); checkout's signature gained two optional
arguments; settlement records a meal order under buyer name/phone first (then contact, then Stripe,
then the placeholder), and `detailsWithBuyer` generalises from email to name and phone; a page handed
back takes the name and phone typed last but keeps the email it was opened with. Staging anonymises both
columns; `MemberAccountDeletion` clears them on unpaid orders. (4) Five named limiters beside the
form's: per token DIGEST for `cart-write`/`cart-read`/`cart-checkout`, and a token that names no live
basket meets a per-connection bucket of the same size instead (the brief's "falling back to IP|masjid",
widened from "no header" to "no live basket" so junk tokens cannot mint a fresh allowance); a 429 says
the wait in its body in the form's shape. (5) `cart:prune`, daily 03:41: OPEN baskets whose expiry is more
than a day past, unless a PENDING order's page could still be paid (`checkout_expires_at` less than an
hour behind); lines cascade. Settlement needed NO change to settle a pruned basket's order: it writes
from `order_items` and `orders.cart_id` is nullOnDelete (`CartPruneTest`).
Alternatives: a per-route feature check in each controller (rejected: one middleware is one place, and it
runs before the throttles); validating and pricing a single line without the whole basket (rejected: a
second copy of the pricer's payee and currency rules, in code the brief says to leave alone); comparing a
replay with the stored line instead of a stored hash (rejected: it would need the request re-validated,
and a retry after a form closed would fail); a `Cart-Token` cookie (rejected: CORS carries no
credentials); putting the giving and lunch checks in the endpoint (rejected: checkout would not re-ask
them, and a basket sits for days).
Rationale: production ships from `main`, so nothing here may be reachable until the owner says so, and
once it is, it must be the doors' floor at least: tenancy by hand, a uniform 404, no answers in any
response, the door's own validation, and money never opened for what a door would refuse. Every rule
has a test in `tests/Feature/Cart/Endpoints/`; no existing test changed. ASSUMPTIONS #25 (closed),
#35-#44 (open ones name what the owner must check before `CART_ENABLED`: MEC's return origin and its
capabilities).

## 2026-09-29 — Universal cart, slice 5 fix round 1: a basket never takes a reserved date, and closeCart matches on quantity
Decision: (1) `FormLineSource::reprice()` returns `gone` (`FormLineSource::RESERVES_A_DATE`, "book that date on
the form's own page") for a line whose answers reserve a date, `Form::reservedDateIn($payload) !== null`.
The form door claims that date with `FormReservations::claim()` under the form lock and refuses the second
payer (`FormDateTaken`); a basket line is settled with no `reserveOn` (`CartSettlementService::settleForm()`),
so nothing held the date and two shoppers could pay for one Ramadan evening. The add endpoint needed no
change: it already refuses a line that prices as `gone`, so the shopper gets the sentence as a 422, and a
line already in a basket (or on a form that gained a date list later) is dropped and named at checkout. A
line on the same form that reserves nothing, the choice-priced "Individual Iftar", stays payable; the door
drops a date named beside it, so the stored answers hold none. (2) `closeCart()` also matches on quantity
(`(int) $line->quantity === (int) $paid->quantity`), because `POST /cart/acknowledge` edits a line's
quantity in place and the payload hash of a dish does not see it: page A paid for two after the shopper
acknowledged three and opened page B must leave the line in the basket, and B is expired as before.
Alternatives: taking a hold on the date from the basket (rejected: a hold needs an expiry that nothing in the
payment path has, the same reason capacity is not held); refusing only when the date is already held
(rejected: a date free at add and taken by checkout is the same race, and the answer would differ by luck);
folding the quantity into `PricedBasket::payloadHash()` (rejected: it would change the `cart_payload_hash`
already stamped on open orders, and the quantity is not part of the payload).
Rationale: a date sold twice is not a count to put right afterwards, and a quantity edit must not delete
what was never paid for. ASSUMPTIONS #36 closed. Tests: `FormLineSourceTest` (both date cases),
`CartAddItemTest` (the refusal and the payable line), `CartSettlementRound2Test` (the quantity race).

## 2026-09-29 — Universal cart, slice 5 fix round 2: dark means first, and what the shopper typed is what is used
Decision: (1) `bootstrap/app.php` ranks `EnsureCartEnabled` ahead of `ThrottleRequests` in the middleware
priority list (`prependToPriorityList`). Laravel re-sorts a route's middleware by that list, and
`ThrottleRequests` outranks the `api` group's `SubstituteBindings`, so the unranked gate ran LAST: a dark cart
still ran the limiter closures (database reads), wrote rate-limit rows, carried `X-RateLimit-*` headers on its
404 and answered 429 to the 21st `POST /carts` of an hour, so "switched off" was distinguishable from "never
built". `CartEndpointsGateTest` now reads the SORTED stack (`Router::gatherRouteMiddleware`) and has two
behavioural tests with the cart off (no limiter closure runs; 22 starts are 22 identical bare 404s).
(2) `CartCheckoutService::reuseOpenPage()` hands an open page back only when the buyer email ALSO matches the
order's (`usableEmail`, lower-cased); otherwise the page is closed as for a changed basket and a new one opens
with the new email. The email is locked into the Stripe page and is where settlement sends the receipt, so a
corrected typo used to keep the typo. The existing "page handed back takes the phone typed last" test used a
corrected email to prove the old behaviour; its second call now uses the same address.
(3) Checkout's and a stale acknowledge's 409 body is the priced view exactly as `GET /cart` returns it, with
`notices` and `view_fingerprint` inside it: `CartCheckoutRefused::basketChanged()` takes the `PricedBasket`.
A notice is a sentence with no amount, and acknowledging adopts the new price and quantity, so the shopper must
be shown them first. (4) `checkout()` takes `requirePhoneForMeals`, which the endpoint always passes, and
refuses under the basket lock a basket that prices a payable dish when the phone is empty
(`CartCheckoutService::PHONE_REQUIRED`, a 422). The controller's rule is unchanged; its `hasMeal` read precedes
the lock, so a dish another tab added in between was charged with no phone to ring. It is opt-in (ASSUMPTIONS
#45) because the settlement and buyer-identity tests open dish orders through `checkout()` with no phone on
purpose (the documented legacy state, #25).
(5) the `cart-create` allowance (`config/cart.php` `throttle.create_per_hour`, `CART_CREATE_PER_HOUR`)
is 200 an hour per IP|masjid, up from 20. The limiter is keyed by connection and organisation, and MEC's
festival is one venue Wi-Fi network: every phone in the hall reaches the API from one public address, so 20
starts an hour locked the 21st shopper out of opening a basket at all, and one script on the same network
could do it in 20 requests. What a start costs is one cheap row (a basket with no lines and a token digest),
and an abandoned one is deleted by `cart:prune` a day after its week is up, so a higher number costs storage
for a few days and nothing else. The limit stays per connection: an anonymous door with no limit lets one
caller fill the table.
(6) `cart:prune` also deletes an order whose status is `expired` once its `checkout_expires_at` is more than 7
days past (`cart.prune.expired_order_days`, `CART_PRUNE_EXPIRED_ORDER_DAYS`, floor 1), with its lines: the
frozen copy of the shopper's details (`order_items.payload`, `orders.buyer_name`, `buyer_phone`,
`buyer_email`) outlived the basket the sweep deleted for holding the same data. An expired order is a payment
page that was never completed. Never `pending` (a delayed payment can still settle it) and never `paid`. The
lines go through `order_items.order_id`'s `cascadeOnDelete` (checked in the orders migration), in the same
statement; the delete names the status again so an order that settled between the read and the delete is not
taken. ASSUMPTIONS #46. (7) `cart:prune` `Log::info`s its counts (baskets and orders, zeros included) as
`groups:purge-feed` does, because `schedule:run` discards stdout (routes/console.php). (8) Tests only: a
required file question is refused at add with the one sentence and not a field bag; the same
`client_line_key` with other form answers, another meal quantity or another pickup is a 409; at 25 lines a
replay of the 25th key returns that line; a confirmed `masjid_domains` host of this organisation is the return
base with an empty env allowlist, and another organisation's is refused.
Alternatives: (1) reading the gate from a controller or a `Route::middleware` order (rejected: the priority
list, not the listed order, decides; a test of the listed order is what missed this); (2) updating the email on
the reused order and page (rejected: Stripe's page cannot be edited, and a receipt for a page opened with the
typo would still be mailed to the typo); (3) binding acknowledge to a `GET /cart` view (rejected: the 409
already has the priced basket in hand, and one more round trip is one more place for it to differ); (4) a
strict default (rejected for now, see above); (5) keying `cart-create` by something finer than the address
(rejected: an anonymous caller has nothing finer that it cannot mint fresh, which is why the per-basket
limiters key by a token that already exists), lifting the limit for one organisation (rejected: an allowlist of
addresses is operations work and one more thing to forget on the day), or leaving 20 and telling MEC to raise
`CART_CREATE_PER_HOUR` (rejected: the default is what ships, and the failure lands on the shoppers at the
event); (6) deleting `pending` orders too (rejected: an order whose page lapsed but whose webhook never came
is not provably unpaid).
Rationale: dark has to mean first, a corrected address has to be the address used, the shopper has to see what
they are asked to accept, and a number that decides who the kitchen rings has to be checked where the basket
cannot change under it. The 20 in the brief was sized for one person; the deployment is a room.
`CartConfigTest` pins the new defaults; `CartThrottleTest` sets its own small numbers and is unchanged.

## 2026-09-29 — Member portal, slice 6: a member's orders, gifts and receipts (API only)
Decision: (1) REALM. The routes are in the MEMBER realm, `/api/mobile/masjids/{masjid_id}/me/...`, inside the
existing `crm` member group beside recurring giving, with its full stack (`auth:family`, `member.active`,
`member.token`, `family.tenant`, `crm`, on top of the file's `throttle:mobile`) and limiters of their own:
`throttle:30,1,member-portal` for `GET me/orders`, `me/orders/{source}/{id}` and `me/gifts`, and
`throttle:20,1,member-receipt` for `GET me/receipts/{id}/pdf` (dompdf renders on every call). The prefix is
load-bearing: an inline throttle is keyed on the caller alone, so without one these reads would spend the
monthly-giving screen's allowance and the other way round. The family portal was not used because
`FamilyAccessService` admits only a confirmed guardian of a live ward, so the 64 Wix members and every
festival ticket buyer cannot enter it; the member realm is the one a plain contact reaches with no office
step (an e-mail code adopts `login_email` and sets `verified_at`). Nothing client-side was built: which client
shows it (the MEC apps or a web page) is the owner's open question.
(2) THE LINKING RULE. A member sees a purchase when it was confirmed to THEIR VERIFIED ADDRESS (`login_email`
while `verified_at` is set, through `Contact::memberAccessIsActive()`), or, where the source carries a contact,
when `contact_id` is theirs, always inside their own organisation: cart `orders` PAID with `contact_id` = them OR
`buyer_email` is exactly the address (case and the spaces around it aside: fix round 1, below, item 1);
`historical_orders` by `contact_id` (the importer's key; the table holds
no e-mail); `form_responses` with a PAID money leg (`payment_method` set, `payment_status = paid`) whose
`respondent_email` is exactly the address; `meal_orders` PAID whose `customer_email` is exactly the address or
whose `contact_id` is theirs; donations
by `contact_id`, status `succeeded`. A form response or meal order that an `order_items` row records
(`record_type` + `record_id`, the type checked as well as the number) is left out, because the cart order already
lists it. Without a verified address EVERY list is empty, the contact-keyed ones included (an unverified member
never gets past `member.active`, so this is belt and braces). The confirmation e-mail already told the holder of
the address, so the portal reveals nothing new, and someone who typed another person's address cannot see the
order unless they own that inbox. `App\Services\Member\MemberPurchases` is the only place the rule lives: the
list (a UNION of `{source, key, moment}` rows, ordered by moment, source, key so LIMIT/OFFSET pages are stable)
and the detail are built from the same four per-source queries, and every query names `masjid_id` itself
(`FormResponse` never had the tenant trait, the union runs on the base builder, and an unbound scope is no
filter).
(3) HANDLES. A cart order is addressed by its uuid; a Wix order, a form response and a meal order by their row
number, ownership-checked on every lookup. The form response's and the meal order's uuids are NOT handed out,
against the brief's "the uuid where there is one": they are bearer capabilities (a form response's opens its
public payment page and `POST /form-responses/{uuid}/checkout`, a meal order's is the link that edits it,
`PATCH /lunch-orders/{uuid}`, routes/api_v1.php), and a list that carried them would turn a stolen member token
into the power to edit a lunch order. A donation's uuid was minted as the opaque external handle and unlocks
nothing public; a receipt has no handle of its own and is one to one with its gift, so `receipt.id` and the
`me/receipts/{id}/pdf` path use the GIFT's uuid.
(4) ONE 404. A miss, another member's order, another organisation's, a form response or lunch the basket already
lists, a handle of the wrong shape and an unknown source are one byte-identical body
(`{status: error, message, data: {}}`), and none of them reaches a table before the shape is checked. The routes
carry no `where` constraint because a router 404 has a different body. The receipt PDF is resolved through the
caller's own succeeded gifts and refuses an imported Wix gift whatever a stray receipt row says.
(5) WHAT IS SHOWN. Every row is built by hand (`MemberPurchaseProjector`); no model is serialised. List row:
`source` (`manara` cart, `wix`, `form`, `meal`), `id`, `number`, `date`, `status`, `total_minor`, `currency`,
`summary {labels (up to 3), count}`. Detail: the same identity, `lines [{label, quantity, unit_minor,
line_minor}]`, `totals {subtotal_minor, discount_minor, total_minor}` and `receipt_note`. Gift: `id`, `date`,
`fund_name`, `amount_minor` (the charged amount, which is the receipt's gross), `currency`, `is_zakat`,
`receipt {id, serial} | null`, `receipt_note`. Statuses are one vocabulary: `paid`, `refunded`,
`partially_refunded`, `disputed`, `canceled`, `declined`, and `unknown` for a Wix status the importer never
writes; a canceled or declined Wix order keeps that word and a note that no money was taken, and a basket's
refund or dispute flag is its status (the refunded AMOUNT is not shown). `fee_minor` is never shown (Manara's
cut on `orders`, the fee Wix added on `historical_orders`), nor Stripe ids, accounts, fingerprints, payloads,
answers, line options, the import batch, buyer names and phones, or what a Wix line was recorded as. A meal's
donation and covered card fee are lines, so the lines add up. Dates are the calendar day in the ORGANISATION's
timezone (`masjids.timezone`, UTC when it is not a real one), never a UTC instant rendered as a day. The
receipt note for a cart, form or meal purchase is the brief's sentence; a Wix order and a Wix gift get their own
(the organisation's old checkout, no receipt issued here).
(6) The list is paginated as the admin lists are (`data` is the paginator, `data.data` the rows), 15 a page,
at most 50, and `per_page[]` is not a number.
Alternatives: (1) relaxing the guardian rule so the 64 members can use the family portal (rejected: the rule is
what keeps a school's children's records to their guardians, and the member realm needs no office step);
(2) matching orders to a contact at settlement on the typed `buyer_email` (rejected: unverified, so a mailbox
owner could see an order a stranger typed their address into, and nothing there would ever change if the
address moved); reading `contacts.email` instead of `login_email` (rejected: it is not proved); a signed
link like `AccountAccessService` (rejected: typed to staff and one more emailed-secret channel for money);
(3) the uuids the brief asked for (rejected as above); a `uuid` column on `donation_receipts` (rejected: a
migration for a handle that already exists on the donation, and the receipt is one to one with it); an
HMAC-derived handle per row (rejected: an ownership-checked row number discloses nothing a 404 does not, and
inverting a derived handle means scanning the caller's rows); (4) a 403 for someone else's order (rejected:
it confirms the handle is real, which is a disclosure about a named person's spending).
Rationale: the address is what proves the person, the rule is asked in one place so the list and the detail
cannot disagree, and the projection is an allowlist because `Order` hides four fields, `Donation` hides none and
`fee_minor` means two opposite things. Nothing was RUN on the machine this was written on (no PHP there); the
suites below then ran on the CI box on SQLite, 83/83 at 5690ed6f (the pre-rebase tip of this branch; the rebase changed no
portal file), and have never executed on MySQL (ASSUMPTIONS #47, corrected in fix round 1).
Tests: `tests/Feature/Member/MemberPurchasesTest.php` (the rule, clause by clause, asked of the service:
isolation, no verified address, case and space, the cart-owned exclusion by record type, order and paging,
`find()` returning null for every way of not having a row), `MemberOrdersTest.php` (the stack read from the
router, the door, isolation over HTTP, one 404, exact keys plus canary values, statuses, dates, pagination, the
limiter buckets), `MemberGiftsAndReceiptsTest.php` (the gifts, the receipt object and its note, the PDF's
headers, its one 404, the house paginator).

## 2026-09-29 — Member portal, slice 6, fix round 1 (review wf_97cdfff0-76f)
Decision: (1) THE ADDRESS IS DECIDED IN PHP, THE SQL ONLY SHORTLISTS (major). `orders.buyer_email`,
`form_responses.respondent_email` and `meal_orders.customer_email` set no collation of their own, so on production
(verified read-only) they are `utf8mb4_unicode_ci`, and `LOWER(TRIM(col)) = ?` is TRUE for `victim@gmail.com` against
`victim@gmaíl.com`. Somebody who owns the look-alike domain can redeem a member code at it and hold a verified
`login_email` that the database calls equal to the victim's typed address, and then read the victim's baskets, festival
tickets and lunches (lines and totals). SQLite compares bytes, so 83 green tests could not show it. Each address arm of
`MemberPurchases` (cart `buyer_email`, form `respondent_email`, meal `customer_email`) now runs the `LOWER(TRIM())`
query as a SHORTLIST (no LIMIT), keeps the rows whose stored address is exactly the proved one with
`ContactIdentity::keepExactMatches()`, and hands the caller a builder that asks for those keys (`id IN (...)`, inlined
as integers) beside the caller's own `contact_id` arm. The exact filter therefore runs before anything is counted,
paginated or projected: the union's `COUNT` and its LIMIT/OFFSET page, `load()` and `find()` are all built on the same
corrected builders, so the page count is right and no PHP filter is applied after a page is cut. The `contact_id` arms
are untouched (an order keyed to the caller stays theirs whatever address was typed). Cost: one extra narrow query per
address arm each time a builder is made (ASSUMPTIONS #60). ASSUMPTIONS #49 said the columns were `utf8mb4_bin`; it is
rewritten. The same collation lets `MemberSignupService` (`LOWER(login_email) = ?`) resolve a contact for a look-alike;
that lookup and its fix are on main already (`ContactIdentity`, `MemberSignInLookAlikeAddressTest`) and are not touched
here.
Alternatives: `COLLATE utf8mb4_bin` on the comparison (the reviewer's fix; rejected: MySQL-only, so SQLite needs a
second branch the suite cannot show is equivalent, and the house already has one exact-address comparison,
`ContactIdentity::sameAddress()`); filtering the union's rows in PHP after `paginate()` (rejected: the page would be
short, `total` and `last_page` would count look-alikes, and a look-alike could push a real row off the page);
paginating by hand in PHP (rejected: it reads every source's whole history to cut one page).
Tests: `tests/Feature/Member/MemberPurchasesLookAlikeAddressTest.php`, one test per source (cart, form, meal) plus one
across all three, built on `Tests\Support\FoldsAccentsLikeUnicodeCi`: a look-alike's purchase is in no builder, no
`find()`, no page, and its detail is the one 404; the page count and last page are the exact rows'; the premise
(the SQL cannot tell the rows apart) is asserted first.
(2) A CONTACT MERGE MOVES BASKETS AND LUNCHES (m1). `ContactsController::merge` moved donations, Wix orders and imported
seats to the survivor but not `orders.contact_id` or `meal_orders.contact_id`; both are `nullOnDelete`, so the
`forceDelete()` at the end nulled them, and because the portal lists a cart order and a lunch by `contact_id` beside the
typed address, the survivor lost every purchase whose typed address was not their own verified one while their gifts and
Wix orders still showed. Both now move to the survivor inside the merge's transaction, scoped to the bound organisation
like the moves beside them. Live registrations' payers are still not moved (unchanged, DECISIONS 2026-09-25).
Tests: `tests/Feature/Member/MemberPurchasesSurviveAMergeTest.php`: a basket and a lunch keyed to the absorbed contact
under a work address are the survivor's after the merge (and were not before), a neighbour's basket does not move.
(3) GIFTS STAY LISTED BY `contact_id` ALONE, AND THAT IS AN OWNER QUESTION (m2). The review found that `donations.contact_id`
is set at settlement from the address the giver typed, matched against the office's `contacts.email`, so the portal can
show (and serve a receipt PDF for) a gift confirmed to a mailbox the member never proved. The fix the brief allows needs
the payer's address on the donation, and `donations` has none: no `email` or payer column exists in its create migration
or in any migration that alters it. So the rule was NOT changed and nothing was added to the schema (a migration on a
money table, with a Stripe backfill, is not a fix-round item); the gap is ASSUMPTIONS #61, an owner question with the
migration that would close it spelled out. The docblocks that say a gift is theirs by `contact_id` now point at it.
Tests: none, because no behaviour changed; `MemberGiftsAndReceiptsTest` already pins "by their donation, never by an
address".
(4) ONLY AN `issued` RECEIPT IS SHOWN OR SERVED (m3). `donation_receipts.status` allows `issued` and `void`, and neither
the receipt object on a gift row nor the PDF door looked at it; `DonationReceiptPdfService` renders a voided row exactly
like a live one, with nothing on the page that says it was voided, so a receipt the office withdrew would have kept
reaching the donor (latent today: no code path writes `void`). One rule now,
`MemberPurchaseProjector::receiptOf()`, asked by the gift row and by `receiptPdf`, so the PDF is reachable for exactly the
gifts whose row advertises a receipt: not an imported Wix gift, and only status `issued` (`DonationReceipt::STATUS_ISSUED`
and `STATUS_VOID` are new constants). A gift whose receipt was voided carries `receipt: null` and its own note
(`RECEIPT_NOTE_GIFT_VOID`, "The tax receipt issued for this gift was voided, so it is not available here."), because "No
tax receipt has been issued" would be untrue of it. The PDF is the same one 404 as every other way of not being entitled.
Tests: `MemberGiftsAndReceiptsTest::a_voided_receipt_is_neither_advertised_on_the_gift_nor_served_as_a_pdf` (issued, then
voided: the row loses `receipt` and gains the note, the PDF goes 200 to 404) and a voided gift added to
`every_way_of_not_being_entitled_to_a_receipt_is_one_and_the_same_404`.
(5) PAGE LINKS KEEP THE QUERY STRING, AND THE ROUTES ARE NAMED INTO THE ERROR ENVELOPE (m9, m10). The paginators were built
with no `withQueryString()`, so `GET me/orders?per_page=50` answered `next_page_url` `...?page=2` and a client that
followed it got page 2 at the default 15: rows 16-30 again, 51 onward missed. Both lists now call `withQueryString()`
(the admin lists' precedent, `FormResponsesController`), so `first_page_url`, `last_page_url`, `next_page_url`,
`prev_page_url` and every `links[].url` carry `per_page`. The shape stays the admin lists' (`data` is the paginator);
the Mobile realm's `{items, pagination}` shape that `HadithsController` uses is not adopted (DECISIONS (6) of slice 6
chose the admin shape and nothing new argues against it: an owner question if the apps want the other).
The four routes were unnamed, so `MobileErrorEnvelope::ROUTES` (`mobile.member.me.*`) did not apply: a 401, 403 or 429
from the stack or the limiter had no `data` object, the iPhone app's `Response<T>` cannot decode that, and a member who
refreshed the orders list past 30 a minute would have seen a generic error. They are now `mobile.member.me.orders.index`,
`.orders.show`, `.gifts.index` and `.receipts.pdf` (one `->name('mobile.member.me.')` on the `/me` group), and the
envelope's docblock, the `respond()` comment in bootstrap/app.php and `.claude/rules/auth-permissions.md` say the prefix
covers the portal's reads.
Tests: `MemberOrdersTest::the_page_links_keep_the_page_size_that_was_asked_for` and the same in
`MemberGiftsAndReceiptsTest` (every link carries `per_page=2`, and following `next_page_url` gives page 2 of 2, not of 15);
`MemberOrdersTest::every_refusal_at_the_door_carries_an_empty_data_object` (the exact 401 body, the family-token 403 and the
foreign-organisation 403) and a `"data":{}` assertion on the 429 in the limiter test;
`MemberGiftsAndReceiptsTest::the_routes_are_named_under_the_prefix_the_error_envelope_matches_and_their_refusals_carry_data`
(the four names exist; the 401 of the gifts door and of the PDF door).
(6) TEST GAPS CLOSED, NO CODE CHANGED (m4-m8, m14). Six rules were stated in DECISIONS and ASSUMPTIONS and had no test that
fails without them; each is now pinned, and none exposed a bug (every one passed against the code as it stood, by
reading it: nothing was run). `MemberPurchasesTest`: `a_paid_status_without_a_payment_method_is_not_a_money_leg` (m5: the
existing row had BOTH columns null, so the `payment_status` clause excluded it on its own),
`an_order_line_of_another_organisation_hides_none_of_this_ones_rows` (m6: a line of organisation B recording this
organisation's row numbers, beside a same-organisation control that does hide), and
`a_soft_deleted_member_gets_empty_lists_though_the_row_still_holds_a_verified_address` (m14, the liveness check
`verifiedAddress()` leans on). `MemberOrdersTest`: `a_lunch_total_is_what_was_paid_not_what_the_order_is_now` (m4:
`settled_total_minor` 2160 against a current total of 3000), `a_form_row_written_before_the_cents_columns_falls_back_to_its_decimal_amount_and_to_due_plus_fee`
(m7: 30.29 is 3028.9999999999995 as a float, so a cast in place of `round()` shows 3028; and a NULL total shows due plus
fee) and `every_source_dates_an_evening_purchase_at_the_organisations_calendar_day` (m8: 03:30 UTC on the 6th is the 5th
in New York, for a form, a lunch and a Wix order). One observation, not changed: `FormResponse::owedMinor()` already
turns the legacy decimal into cents without a float, and the projector re-does it with `round((float) ...)`; the two agree
for every two-place decimal, so it is a house-fit point, not a defect.
(7) DOCS CORRECTED (m11, m12, m13). m11: `LOWER(TRIM(col)) = ?` defeats an index on the column (`form_responses.respondent_email`
is indexed; `orders.buyer_email` and `meal_orders.customer_email` are not), and the exact check in (1) adds one narrow query
per address arm: recorded as a perf note, not changed, in ASSUMPTIONS #60 (written with (1)). m12: the docblocks said the
admin receipt download "refuses" an imported Wix gift; it does not. `DonationsController::receiptPdf` 404s only when no
receipt row exists, and only `issueReceipt` and `ReceiptService::issueFor` refuse a historical gift, so the portal's two
refusals (a Wix gift, a voided receipt) are its own and stricter than the admin download's; the controller and projector
docblocks now say so. m13: ASSUMPTIONS #47 and the "Nothing was RUN" sentence of the first entry said the suites had never
executed. They ran on the CI box on SQLite, 83/83 at 5690ed6f (the pre-rebase tip; the rebase changed no portal file). What
is still true, and is now what #47 says: CI's MySQL job runs migrations only, so the union, the shortlist keys and the
`LOWER(TRIM())` comparisons have never executed on MySQL, and this fix round was written without PHP and has run on neither
driver.

## 2026-09-30 — Pre-merge fixes (group A)

Off the ship-critic (`design/ship-critic-2026-09-30.json`), on `fix/cart-premerge-a` (base 3345ac28). Group B works the money path in a sibling worktree on disjoint files. Written without PHP: nothing here has run. Each fix has a NEW test that, read against the old code, fails without it; the last line of each says which.

(A1) THE MEMBER PORTAL GOES DARK BEHIND A SWITCH, AND STAYS DARK UNTIL THE OWNER PICKS ITS CLIENT AND ANSWERS ASSUMPTIONS #61.
`config/member_portal.php`: `enabled` (`MEMBER_PORTAL_ENABLED`, default false; a typo reads as off) and `masjid_ids`
(`MEMBER_PORTAL_MASJID_IDS`; empty = every organisation once on; a malformed list becomes `[0]`, nobody, by the same parser
as `config/cart.php`). `EnsureMemberPortalEnabled` (alias `member.portal`, on the `/me` group of `routes/api.php`) reads the
ROUTE's `{masjid_id}` (these routes carry the organisation in the URL, unlike the cart's header) and throws the router's own
404 with the router's own message, so a dark route is the same bytes as an unknown one. (Round 2, R2-2: as first written it was not,
because the exception rendered on a MATCHED `mobile.member.me.*` route and `MobileErrorEnvelope` added `data:{}` to it. It is now a
`DarkRouteException`, a `NotFoundHttpException` the envelope skips.) It is ranked ahead of authentication with
`prependToPriorityList(before: AuthenticatesRequests::class, ...)`. The cart gate is only ranked ahead of `ThrottleRequests`, and
authentication ranks ahead of the throttles, so a portal gate placed the same way would run behind `auth:family` and answer an
unauthenticated probe with a 401 where a missing route answers 404. Alternatives: (a) unregister the routes when off, rejected because the route cache would
then differ between states, as the cart's decision says; (b) a `crm`-style capability, rejected because the owner has not
chosen a client and a per-organisation capability row is a production data change. The suites that drive the routes turn the
portal on in their setUp (`BuildsMemberPortal::turnMemberPortalOn()`). Tests: `MemberPortalGateTest` (off is the router's 404
with no token, a junk token and a real one; the token is never looked at (`last_used_at` stays null); the `mobile` limiter closure
never runs; no rate-limit header; 35 calls never reach a 429; the SORTED stack from `Router::gatherRouteMiddleware` puts the gate
before `Authenticate` and every `ThrottleRequests` on all four routes; the allowlist, the fail-closed `[0]`, the master switch over
the allowlist, the route's id over a header), `MemberPortalConfigTest` (the env parser). Fails without the fix: the off tests, from
round 2 on. As first written they did NOT: `BuildsMemberPortal::portalUrl()` already prefixed `me/`, and the test's paths did too, so every
call went to `/me/me/...`, a route that does not exist, and the off tests passed with no gate at all. Only the calls to
`enabledFor()` and the sorted-stack test depended on the gate. Round 2 (R2-1) removes the doubled prefix and makes each off test first assert
that its URL is a real route (switched on, it answers the portal's 401); only then do the off assertions mean anything.
(A2) A FUND CANNOT BE DELETED WHILE A BASKET LINE STILL NEEDS IT. `FundsController::destroy` answers 409 with one sentence while an
`order_items` row of type `donation` for that fund sits on a `pending` order, or on a `paid` order with `record_id` null (the
LINE's `record_id`: `orders` has none). Settlement of a gift line throws when the fund is gone, and for a paid basket that is a
payment taken with no gift recorded and a webhook Stripe retries for ever. Funds stay hard-deleted; deactivating is the answer the
sentence gives. The check skips itself when the cart tables do not exist yet (the deploy window before `migrate`). Alternative:
soft-deleting funds, rejected as a schema change to a table that the mobile app, receipts and reports all read. Tests:
`FundDeleteBasketGuardTest` (pending refused, paid-unrecorded refused, paid-recorded / expired / another fund's line / a meal line
with the same number / no basket at all still delete). Fails without the fix: the two refusals.
(A3) THE STAGING SCRUB NULLS `orders.basket_fingerprint`, an unsalted sha256 over the same answers as `order_items.cart_payload_hash`
(which was already nulled): a short answer set can be guessed back from it. It is only compared for equality at checkout, so NULL
costs staging nothing. Test: `StagingScrubTest::a_baskets_fingerprint_never_reaches_staging`; `StagingScrubCoverageTest` needs no change
(the name matches no PII token) and still holds (the column exists). Fails without the fix.
(A4) `StripeWebhookController::deliverReceipt` LOGS THE EXCEPTION CLASS, NOT ITS MESSAGE, for a failed send and for a failed PDF render.
A transport's message quotes the recipient ("550 no such user donor@example.org"). The donation id stays in the context, which is what
staff need. It is the live donation path too, and the change is an improvement there. Test:
`DonationReceiptPdfTest::a_failed_receipt_send_is_logged_by_class_and_never_quotes_the_recipient` (a transport whose refusal quotes the
address, a `MessageLogged` listener as the spy). Fails without the fix.
(A5) TEST ONLY: `FormSubmissionTest` now asserts the public door stores `device_id`, the request IP and the (1000-character) user agent on the
FormResponse. The writer's own test hands it a made-up origin; dropping the door's `$origin` array left every door suite green. Passes
against the code as it stands (it pins a behaviour that exists); it fails if the door stops passing the origin.
(A6) `bin/deploy`'s "every application class is loadable" regex allows any run of `final|abstract|readonly`. Before it, `final readonly class`
(12 files: the cart's line outcomes and sources, the settlement result, the Studio value objects, `FormCharge`) was skipped, so a broken
autoload entry for one passed the gate. `grep -rlE '^(final |abstract )?(class|interface|trait|enum) ' app | wc -l` = 939 (before);
`grep -rlE '^((final|abstract|readonly) +)*(class|interface|trait|enum) ' app | wc -l` = 951 (after). Test: `DeployClassGateRegexTest`
reads the regex OUT of `bin/deploy` (no copy to drift) and checks every declaration form plus that no file under `app/` is skipped. Fails without
the fix (the readonly forms and the whole-tree walk).
(A7) `WixContactImport`'s UNDO CHECK APPLIES THE SAME CONDITIONS DELETION USES. `heldBy()` walked `OFFICE_RECORDS + LOGIN_RECORDS` without
`OFFICE_RECORD_CONDITIONS`, so once the cart is on an unpaid or expired order would hold a contact against an undo, and so would an
abandoned basket. Now an order counts only when `paid`, and `carts` is skipped (it cascades away with the contact, and a signed-in shopper's
login columns hold the undo on their own). Tests: `WixContactImportTest::undo_is_not_held_by_an_unpaid_checkout_or_an_abandoned_basket` (fails
without the fix), `undo_is_still_refused_once_an_imported_contact_has_a_paid_order` (passes with or without it: it pins the other direction).
(A8) DOCS. ASSUMPTIONS #61 now covers the imported Wix orders as well as the gifts (both listed by `contact_id` alone); ASSUMPTIONS "PM-A6" records
the Connect endpoint's subscription list and recommends the owner/ops call to add `checkout.session.expired` and `payment_intent.payment_failed`,
noting the code has no `payment_intent.payment_failed` arm today; `.claude/rules/stripe-payments.md` "Universal cart" carries the portal
switch, the fund-delete guard, the scrub, the log line and the subscription fact.
## 2026-09-30 — Pre-merge fixes (group B): money path, settlement, prune, deletion

From the ship-critic's confirmed findings and minors (`design/ship-critic-2026-09-30.json`), base `feat/universal-cart` @ 3345ac28.
Group A (portal switch, office side, tooling, docs) worked in a sibling worktree on disjoint files. NOTHING WAS RUN: there is no
PHP here, so every test below was written by reading the code it exercises and none has been executed on either driver.

(B1) `orders.charge_flag` is `string(32)`. 'partially_refunded' is 18 characters and the column was 16: MySQL in strict mode refuses
it (error 1406), SQLite does not, so every partial refund of a basket would have failed to record and been swallowed by
`handleChargeFlag`'s catch-all. The unshipped migration is edited in place (neither cart migration has run on staging or
production, per the brief; if that is wrong for staging an ALTER migration is needed instead, ASSUMPTIONS B-5). THE AUDIT of every
string column in `2026_09_27_090000_create_carts_table` and `2026_09_28_090000_create_orders_table` against everything the code
writes found one more that was tight: `orders.charge_account_id` was `string(64)` and is copied from `masjids.stripe_account_id`,
which is `string(255)`; it is now 255 too. Everything else fits, and the widths that decide it are: statuses 16 (longest 'checked_out',
11; 'pending'/'expired', 7), `recorded_as` 16 ('registration', 12), `buyable_type` 64 ('meal_item', 9), `record_type` 64
('form_response', 13), currency 3 ('usd'), `order_number` 32 (8), `idempotency_key` 64 ('cart_order_' + uuid = 47), `charge_ref` 40
('cref_' + 32 = 37, the tightest), `buyer_email` 255 (the door caps it at 190), `buyer_name` 120 and `buyer_phone` 32 (both
`mb_substr`'d to the column), `label` 255 (a form, fund or dish name, each a `string(255)`; the basket's own copy is `mb_substr`'d),
`client_line_key` 64 (the door's regex), digests `char(64)` (hex SHA-256/HMAC). `CartColumnWidthsTest` reads the declared widths out
of both migrations and compares them with the longest value the code writes (a map next to the constants), fails when a new string
column has no recorded maximum, and reads every string in the rows a real paid and partly refunded basket leaves.

(B2) A refund or dispute before settlement is no longer lost. `CartPaymentService::recordPaymentIntent()` writes
`orders.stripe_payment_intent_id` (once, `whereNull`, outside any transaction) as soon as an event has
resolved the order and matched its page, BEFORE settlement is tried, so it survives a refusal (amount mismatch) or a throw (a fund
that vanished). (Round 2, R2-4: only a checkout SESSION event whose id is the order's recorded page does this now; a payment-intent
event records its intent at settlement, as before.) `flagOrder` then finds the pending order and flags it with its "flagged before settlement recorded it" warning.
HAVING AN INTENT IS NOT BEING PAID, proved by grep over `app/`: the only readers of `orders.stripe_payment_intent_id` are
`flagOrder` (finds the order), `CartSettlementService` (writes it; `noteRepeat` reads it on an already PAID order) and now
`PruneCarts` (treats pending-with-intent as a payment to reconcile); everything that decides "paid" reads `status`
(`Order::isPaid()`, `MemberPurchases` `orders.status = paid`, `CartOrdersController`, `CartCheckoutService` PENDING/PAID reads).
`CartPreSettlementFlagTest::having_an_intent_does_not_make_an_order_paid` pins the model, the payment-state read, the basket and the
records. One consequence for settlement: an intent recorded early might not be the paying one (a holder's own users can write the
metadata that identifies an order on their account), so step 3 now records the PAYING intent (`$paymentIntentId ?? recorded`); it
used to keep whatever was there. CHANGED EXISTING TEST: `CartSettlementTest::an_amount_mismatch_settles_nothing_and_leaves_the_order_pending`
asserted the intent stayed NULL after a refused settlement, which is the defect; it now asserts the intent is recorded and the order
is still pending and not paid.

(B3) A form line's record key is `cart:item:<order_item id>` (was `cart_item_<id>`), for tickets (`client_submission_key`, 64 wide)
and gifts (`donations.idempotency_key`, 255). The public door accepts `^[A-Za-z0-9_-]{8,64}$`, so the old key could be submitted by
anyone against the same form and settlement's `earlier()` would find THEIR row and mark it paid with the basket's payment; a colon is
outside that alphabet. `CartSettlementService::lineKey()` is the one place the key is built. Tests: the door's own rule refuses
`cart:item:42` and accepts `cart_item_42`; a decoy row written under `cart_item_<id>` is neither captured nor marked paid. Existing
tests that spelled the old key were updated (`CartSettlementTest`, `FormResponseWriterTest`). `.claude/rules/stripe-payments.md` (group A's
file) now says `cart:item:<id>`, which group A wrote against the brief and which round 2 (R2-7) checked against `CartSettlementService::lineKey()`.

(B4) `cart:prune` also deletes `pending` orders, with their lines, because an order never becomes `expired` on production (the
Connect endpoint does not subscribe to `checkout.session.expired`): with NO intent on record once `checkout_expires_at` is more than
`expired_order_days` (7) past; WITH an intent once it is more than `cart.prune.pending_with_payment_days` (new, default 30, floor 7,
env `CART_PRUNE_PENDING_WITH_PAYMENT_DAYS`) past, and those orders are logged at WARNING with each order's number, payment intent and
amount (the rows are gone afterwards; no name, address or answer is logged). (Round 2, R2-3 and R2-6: an `expired` order follows the
same rule, and the WARNING is one per chunk of 100 with every deleted order listed, not one capped at 100.) A debit that succeeded would have settled through
`payment_intent.succeeded`, so a pending order with an intent this long after is a payment to reconcile in Stripe. `paid` is never
touched; each delete names the status (and the missing intent) again so an order that settled or was named between read and delete
is skipped. Boundaries tested (7 and 30 days exactly are kept), plus dry run, the config floor and the log. CHANGED EXISTING TEST:
`CartPruneTest::an_expired_order_more_than_a_week_past_its_page_goes_...` kept a pending order 30 days old "however old"; it now
keeps a pending order 3 days old and a pending-with-intent order exactly 30 days old.

(B5) `CartRefundArmIsolationTest`: the cart arm's own first query throws (a `DB::listen` on `select * from "orders"`) and the form arm
still flags the refund and the dispute, the webhook answers 200 and the cart arm logs the class only. It passes against the code as
it stands (the try/catch exists); it fails if the try/catch is removed.

(B6) `App\Support\CartTables::has($table)`: `Schema::hasTable` memoised per process (an existing table for good, a missing one for 30
seconds so a long-lived worker recovers). Guards the form refund arm's `whereNotExists(order_items)`, the cart's own refund arm (it
logged a false error line per refund), `MemberAccountDeletion`'s carts and orders steps and its `reasonsToKeep` read of `orders`.
`CartDeployWindowTest` drops the four tables. Not guarded, outside group B's files or the brief: the `ContactsController` merge, the
portal routes (dark behind group A's switch), and `WixContactImport`'s undo (a console command).

(B7) `settleLocked`'s first read is MOVED OUT of the transaction, not made a locking read: `settle()` reads
`(id, masjid_id, cart_id)` of the order before `DB::transaction` opens. Making it `FOR UPDATE` would lock the ORDER before the CART,
the reverse of checkout's order, and invite the deadlock the cart-first order exists to avoid. Safe because that read only decides
WHICH cart to lock, an order's `cart_id` never changes except to NULL when its basket is pruned (then there is no cart to lock and
`closeCart` does nothing, exactly as before), and everything that matters (the cart, the order, the lines) is read afterwards under
the locks; InnoDB's locking reads see the latest committed rows and do not fix the REPEATABLE READ snapshot, so the first plain read
now comes after both locks. `CartSettlementLockOrderTest` pins the statement order (basket id before BEGIN, cart lock first inside).
SQLite cannot show the anomaly; the concurrent case has not run on MySQL.

(B8) `pinToHolder` asks `CartPaymentService::accountHolder()` (now `public static`; live before trashed, and the trashed lookup
`orderBy('id')`) instead of an unordered `withTrashed()->pluck()->first()`; the link's own holder (`forms_card_via_masjid_id`)
still wins while it holds the pinned account. `CartPinToHolderTest`.

(B9) Delete account also clears the buyer's name, phone and address on UNPAID (pending or expired) orders in the member's
organisation whose `buyer_email` is EXACTLY their `login_email` or `email` (`ContactIdentity::keepExactMatches`; the query is only
`LOWER(buyer_email) = LOWER(?)`, a shortlist), because the public door always writes `contact_id` NULL. Paid orders are never
touched. `order_items.payload` (attendee names) and `basket_fingerprint` are NOT cleared: a pending order can still be paid by a
delayed method and settlement writes its records from the payload; `cart:prune` removes the whole order (B4). Tested including a
look-alike under the `FoldsAccentsLikeUnicodeCi` stand-in, in both directions.

## 2026-09-30 — Pre-merge fixes, round 2 (from the Opus checks of groups A and B)

From `design/premerge-fixes-check-2026-09-30.json`, worked on `feat/universal-cart` @ 1aaae2ab (groups A and B merged on 3345ac28). Framework
behaviour was read in `~/Developer/MasjidWebMS/vendor` (Laravel 12.64.0, the version `composer.lock` pins). NOTHING WAS RUN: there is no PHP here,
so every test below was written by reading the code it exercises and none has been executed. "Fails without the fix" below means read against the
code before the fix, not a run.

(R2-1) `MemberPortalGateTest` WAS VACUOUS, AND IS NOT NOW. `ROUTES` and every literal passed `me/orders` and the like to `portalUrl()`, which already
prefixes `me/`, so each HTTP call went to `/me/me/...`. The prefix is gone from the paths (no other suite had it). Each off test now first calls
`assertTheRouteIsReal()`: with the switch ON for everyone, an unauthenticated call to the same URL must answer the portal's 401, which a path no route
matches can never do (it answers the router's 404); the switch is put back as the test had it. The helper `bare()` drops sticky headers and the
guard's memoised user first, because `withHeader()` STAYS on the test for every later call, so a "no token" call after `asMember()` had been a member's
call. `on_an_unauthenticated_call_is_the_stacks_401_not_the_gates_404` now covers all four routes. Fails without the fix: nothing here is a code fix.
The old paths made `switched_on_the_routes_answer` and the unauthenticated 401 test fail, and the off tests pass vacuously; the premise is what fails
against a wrong URL.

(R2-2) A DARK PORTAL ROUTE IS THE UNKNOWN ROUTE'S BYTES. `EnsureMemberPortalEnabled` throws after the route has matched, and the `exceptions->respond()`
hook (`MobileErrorEnvelope::withDataKey`) then added `data:{}` to that 404, as it does to every JSON refusal on a route named `mobile.member.me.*`;
a path that matches no route never reaches it, so a probe could tell "switched off" from "never built". Chosen: a dedicated
`App\Exceptions\DarkRouteException extends NotFoundHttpException` (the router's own message, `forPath()`), which the gate throws and the envelope
skips; `withDataKey()` takes the exception as an optional third argument and `bootstrap/app.php` passes it. Alternative: a request attribute the hook
reads, rejected because the exception already carries the fact through the handler unchanged (`prepareException` returns an HttpException as it is) and a
class cannot be forgotten to be set. Every other refusal on these routes, including a controller's own 404, keeps its envelope (the iPhone app decodes
`data`). Tests: `MemberPortalGateTest::off_every_portal_route_is_the_same_404_as_a_route_that_does_not_exist` now compares the status, the body's bytes and
every header but `Date` of the unknown route with the dark answer, for no token, a junk token and a real member's, on all four routes;
`off_the_404_has_no_data_key_where_the_portals_own_refusals_keep_theirs` pins the key's absence on the dark 404 and its presence on the 401s;
`MobileErrorEnvelopeTest` pins it without HTTP (an ordinary `NotFoundHttpException` is decorated, a `DarkRouteException` is not, another route is untouched).
Fails without the fix: the first two (the body differs by `"data":{}`) and the unit test (no such class).

(R2-3) THE EXPIRED SWEEP OF `cart:prune` FOLLOWS THE PENDING RULE. B2 records an intent early, and `markExpired` / `closePage` expire an order when the shopper
checks out again, possibly while a delayed debit from the earlier page is still clearing; the expired sweep deleted such an order 7 days after its page
closed, ahead of the 30 days B4 gives a pending order with an intent, and with no reconcile list. Now an `expired` or a `pending` order WITH an intent goes
after `pending_with_payment_days` (30), and one WITHOUT after `expired_order_days` (7); the two statuses share `sweepWithoutIntent()` and
`sweepWithIntent()` (each delete names the status and the intent condition again). The console prints and logs a third count
(`expired_orders_with_payment`); existing keys and output strings are unchanged. Tests: `CartPruneTest::an_expired_order_with_a_payment_intent_waits_thirty_days_and_one_without_waits_a_week`
(no intent: 8 days goes, exactly 7 stays; intent: 31 goes, exactly 30 and 8 stay; the WARNING lists the 31-day one) and
`a_dry_run_counts_expired_orders_with_a_payment_intent_and_deletes_none`. Fails without the fix: the 8-day order with an intent was deleted.

(R2-4) THE EARLY PAYMENT-INTENT RECORD CAN NO LONGER BE SQUATTED. `recordPaymentIntent()` wrote the first intent any resolved event named, before the
amount and currency checks. On a holder's account the holder's own users can write `cart_charge_ref` metadata on a PaymentIntent, so a stray or forged
one could take the write-once slot, keep the real payment's intent off the order, and (refunded) flag the order. Now it records ahead of settlement ONLY
from a checkout SESSION event whose id equals the order's `stripe_checkout_session_id`: the page the app opened, so the intent Stripe made for it is the
order's. `handlePaymentIntentSucceeded()` no longer calls it, so a payment-intent event records its intent only when it settles the order, as before B2. An
order that has recorded no page yet records nothing early (there is no page to match). Alternative: keep trusting a payment-intent event and check the
amount first, rejected because a forged event can carry the right amount and the slot is write-once. Cost, accepted (ASSUMPTIONS R2-4): a payment that
reaches only a refused payment-intent event has no intent on the order, so an early refund or dispute of it is not found. Tests:
`CartPreSettlementFlagTest::a_forged_payment_intent_event_does_not_take_the_slot_and_the_genuine_session_event_still_records_its_intent` (own account),
`on_a_holders_account_a_forged_payment_intent_event_does_not_take_the_slot_either` (a `charge_ref` basket), `a_session_event_records_early_only_for_the_page_the_order_recorded`.
CHANGED EXISTING TEST: `a_payment_intent_event_that_settlement_refuses_records_its_intent_too` asserted exactly the behaviour removed; it is replaced by the
first of these. Fails without the fix: all three.

(R2-5) `CartPaymentService::handleChargeFlag()`'S TABLE CHECK IS INSIDE ITS TRY. The `CartTables::has('orders')` guard sat above the try/catch, so a database that
could not answer `Schema::hasTable` would have thrown ahead of the form refund arm that runs after it, which the method's "never throws" promise and B5 exist to
prevent. It is now the first statement inside the try. Tests: `CartRefundArmIsolationTest::a_refund_still_flags_the_form_row_when_the_cart_arms_table_check_throws`
and `a_dispute_...`, which break only the `orders` question (a `DB::listen` on the SQLite grammar's `sqlite_master ... name = 'orders'`, after `CartTables::forget()`), so
the form arm's own `order_items` guard still answers. Fails without the fix: the form row was not flagged and the webhook answered 500.

(R2-6) THE PRUNE'S RECONCILE LIST IS NOT CAPPED. The single WARNING sliced `listed` to 100, and it is the only record staff can reconcile from once the rows are
deleted. `sweepWithIntent()` now reads and deletes 100 orders at a time (`RECONCILE_CHUNK`, `chunkById`, safe with deletes) and logs one WARNING per chunk
listing every order that chunk deleted (number, organisation id, intent, amount, currency; no buyer). Alternative: one WARNING per order, rejected as noisier for the
same information. Test: `CartPruneTest::every_deleted_order_with_an_intent_is_on_a_warning_however_many_there_are_and_none_carries_a_buyer` (250 orders, both statuses, each
chunk's WARNING equals the expected list, three WARNINGs in all, no buyer name, email or phone in any). Fails without the fix: the list stopped at 100.

(R2-7) DOCS made true on the merged branch: ASSUMPTIONS PM-A1 (what the dark 404 is, and what it is not), PM-A3 (the vendor source was read: index 5), B-4 and B-5 (edited
for R2-3 and R2-4), a new "round 2" table (R2-0, and R2-2 to R2-6); (A1), (B2), (B3) and (B4) above carry an inline "Round 2" note; `.claude/rules/stripe-payments.md` (the key is
`cart:item:<id>`, which was already true of `CartSettlementService::lineKey()`; its retention paragraph is rewritten for B4, R2-3 and R2-6, and the refund paragraph for R2-4 and R2-5);
`config/staging_scrub.php` says `order_items.cart_payload_hash` is "(above)" the orders list, which it is.

## 2026-09-30 — Pre-merge fixes, round 3 (the point's live-path review)

From the point's review of the live paths: GO, with 0 blockers and 0 majors, once the deploy-window guards are folded in. Worked on `feat/universal-cart` @ aa05b84b.
bin/deploy makes the new code live BEFORE `migrate`, so for that window the cart tables do not exist. NOTHING WAS RUN: there is no PHP here, so every test below was
written by reading the code it exercises and none has been executed. "Fails without the fix" means read against the code before the fix, not a run.

(R3-0) THE RULE FOR A TABLE CHECK: ONLY A GENUINELY MISSING TABLE SKIPS; A FAILED CHECK FAILS CLOSED ON ANY PATH THAT DELETES OR MOVES DATA. (A design correction from the
point's review, applied in this round: item 4 of the brief, read as written, would have made `CartTables::has()` swallow a throwing check for every caller.) A check that throws means "I could not tell", which is a different thing
from "the table is not there". Swallowing it as "absent" is right only where absent is harmless, and wrong where it skips a look at `orders`: a member holding paid orders
would be erased, a merge would leave the source's paid orders to the foreign key's SET NULL, and an import's undo would delete a contact orders still name. So
`App\Support\CartTables` has two questions. `has()` FAILS SAFE (catches, logs ONE warning per process by class, answers absent, never remembers a failure): the live form
refund arm (R3-4) and `cart:prune` (R3-3) only. `existsOrFail()` FAILS CLOSED (false only for a table that genuinely does not exist; a failed check propagates, so the
caller's transaction rolls back and the operator sees an error): `MemberAccountDeletion` (both steps and `reasonsToKeep`), `ContactsController::merge` (R3-1),
`WixContactImport::heldBy` (R3-2), and `CartPaymentService::handleChargeFlag` (its catch logs the failure at error, as before, instead of reading it as "no orders table" and
losing a flag on a real order in silence). Alternative for the strict question: trust a remembered absence like `has()` does; rejected, because the remembered absence can be
up to 30 seconds stale and these paths delete data, so `existsOrFail()` asks again (one statement on a path that runs rarely). Tests: `CartDeployWindowTest`
(`the_fail_safe_question_answers_absent_and_warns_once_and_a_failure_is_never_remembered`, `the_strict_question_lets_a_failed_check_propagate_and_says_false_only_for_a_table_that_is_missing`,
`the_strict_question_does_not_trust_a_remembered_absence`, `what_an_account_deletion_keeps_a_member_for_is_not_decided_by_an_orders_check_that_threw`,
`an_account_deletion_whose_baskets_check_threw_is_rolled_back_whole`, and, from c85b8bbb, `an_account_deletion_whose_unpaid_checkout_check_threw_is_rolled_back_whole`); the existing `CartRefundArmIsolationTest` is unchanged and still needs the cart arm to use the strict
question (its check must reach the arm's catch and log at error). Fails without the fix: the last two fail if deletion used `has()` (the member is erased, or the
deletion carries on past the failed step).

(R3-1) THE CONTACT MERGE. `ContactsController::merge` moved `orders.contact_id` with no guard, so every admin merge answered 500 until migrate finished. The orders move now
sits behind `CartTables::existsOrFail('orders')`; the `meal_orders` move stays unconditional (an old table). The brief said "and the carts move if one exists": the merge has NO
carts move (an open basket is the shopper's half-finished choice; `carts.contact_id` cascades off the source's force-delete), so there was nothing to guard there. Tests:
`CartDeployWindowMergeTest::a_merge_in_the_window_succeeds_and_moves_everything_that_is_not_a_cart_order` (tables dropped; gift, lunch and imported Wix order follow to the
survivor) and `a_merge_whose_orders_check_cannot_be_answered_fails_whole_and_orphans_no_paid_order` (500, source kept, paid order neither moved nor nulled, earlier moves rolled
back). Fails without the fix: the first (500 on the missing table); the second (the old code never asked, so the merge answered 200), and it also fails if the fail-safe `has()` were used.

(R3-2) THE WIX IMPORT UNDO. `WixContactImport::heldBy` ran `DB::table('orders')` unguarded, so an undo in the window failed on a missing table. It now skips a cart table that
`existsOrFail()` says is genuinely missing (`carts` was already skipped as `GONE_WITH_THE_CONTACT`). Tests (`WixContactImportTest`): `undo_in_the_deploy_window_removes_what_the_run_created_exactly_as_before_the_cart`,
`undo_in_the_deploy_window_is_still_refused_for_a_contact_the_office_holds` (refusal text unchanged), `an_undo_whose_orders_check_cannot_be_answered_stops_and_removes_nothing`.
Fails without the fix: all three.

(R3-3) THE PRUNE COMMAND. `cart:prune` is scheduled daily at 03:41 and had no guard. With any of the four cart tables absent it prints and logs one info line ("cart tables
not migrated yet; nothing to prune", with the missing names as context), exits 0, and issues no statement that names a cart table; `--dry-run` takes the same path. It uses the
fail-safe `has()`: a check that cannot be answered also skips the night (the line then says "not migrated", which is a small untruth, and the one `has()` warning says why).
Skipping is harmless for a sweep that runs again tomorrow. Tests (`CartDeployWindowTest`): `the_prune_in_the_window_logs_one_info_line_exits_zero_and_touches_no_cart_table`
(a `DB::listen` collects any statement naming a cart table) and `the_prune_skips_the_night_and_deletes_nothing_when_the_table_check_itself_throws` (an expired basket stays).
Fails without the fix: both.

(R3-4) THE LIVE FORM REFUND ARM. `FormResponsePaymentService::handleChargeFlag` called `CartTables::has('order_items')` outside any try, on the live form refund path, so a
database that could not answer the question turned a refund into a 500. `has()` itself now fails safe (R3-0), so the arm skips the cart exclusion and behaves exactly as before the
cart. Test: `CartDeployWindowTest::a_live_form_refund_is_still_flagged_and_answered_200_when_the_order_items_check_itself_throws` (a `DB::listen` on the SQLite grammar's
`sqlite_master ... name = 'order_items'` throws; the pinned row is flagged, the webhook answers 200, one warning by class, no error line). Fails without the fix: the exception left
the arm, the row was not flagged and the webhook answered 500.

(R3-5) A FAILED RECEIPT EMAIL LOGS A SCRUBBED REASON. `StripeWebhookController::deliverReceipt` logged the exception class only, which told staff nothing about why a receipt did not
go. It still logs `error` (the class) and now also `reason`: the exception message with the recipient's address replaced by `[recipient]`, then any remaining text of the shape
`[^\s@<>"']+@[^\s@<>"']+` by `[email]`, cut to 300 characters (the cut last, so it cannot leave half an address). Only the SEND failure: the PDF render failure stays class-only, because its
message can quote the letter's names and no pattern finds a name. If the pattern itself fails, `reason` is empty rather than unscrubbed. Tests (`DonationReceiptPdfTest`): the send test now
asserts `reason` is `550 5.1.1 no such user [recipient]` and that no line holds the address (the transport's own text may now appear); a new test with two more addresses (one in capitals, in angle
brackets) and a 400-character tail asserts the exact scrubbed, 300-character reason. The render-failure test is unchanged. Fails without the fix: both (no `reason`). CHANGED EXISTING TEST:
`a_failed_receipt_send_is_logged_by_class_and_never_quotes_the_recipient` no longer asserts the transport's text is absent.

(R3-6) RECORDED, NOT CHANGED: A DARK ROUTE ANSWERS 405 FOR A WRONG METHOD, AND AN OPTIONS PREFLIGHT IS ANSWERED. Both reveal that the path exists, which the 404 of a switched-off portal
route (R2-2) otherwise hides. Requests with a declared method are byte-identical to an unknown route's. Accepted, because the repository is public, so the route list is not a secret. No
code change, no test.

(R3-7) DOCS made true: the `CartTables` class comment (two questions, the rule, the memo), `.claude/rules/stripe-payments.md` (the cart arm asks the strict question), the `CartRefundArmIsolationTest`
class comment, and ASSUMPTIONS "Pre-merge fixes (round 3)". B6 above says the merge and the undo were "not guarded"; R3-1 and R3-2 supersede that.

## 2026-09-30 — Tuition autopay and the family ledger: what "no payment scheduler" still means
(amends "2026-08-10 — Payments doctrine: tenant is always merchant of record", corollaries (a),
(b) and (c), including "Manara does not build a payment scheduler"; the owner's decisions of
2026-09-30 on tuition autopay and a family ledger, and his answer of the same day: no card surcharge)

Decision. Corollary (a) stands in its core: Stripe subscriptions on the organisation's own
connected account are the only thing that charges, retries or dunns a card or bank payment.
Manara builds no payment scheduler, no retry loop, no dunning engine, and nothing that initiates,
retries or re-attempts a charge.

What changes, and why it is still not a scheduler:
1. Autopay for tuition is one Stripe Subscription Schedule per enrolment on the organisation's
   own account. Manara AUTHORS that schedule: its start date, its number of instalments, its price
   and any one-time fee lines. The 2026-08-10 doctrine allowed less (a schedule attached to a
   Checkout-made subscription only to stop it after N), so this is an amendment, not a
   clarification. The start date and the instalment count are fixed when the family consents on
   the hosted setup page, shown to them there, and stored; nothing recomputes them later. After
   creation Manara changes a schedule only to swap its payment method at the office's request, or
   to cancel it (never prorating, never invoicing on cancel). It never changes when or how much a
   live schedule charges. Stripe is the billing clock, the retrier and the dunning engine.
2. A family-account LEDGER (append-only) records what a family owes and what has been recorded
   against it: (i) money an office received outside Manara's Stripe path (cash, check, Zelle, bank
   transfer, a third-party scholarship payer, a card payment taken in the school's own Stripe
   dashboard, an invoice the school marked paid in its dashboard), each entry naming how it came,
   who recorded it and when; (ii) what a family owes, as charge rows that are bookkeeping (posting
   one touches no card, sends nothing that asks for payment, and is idempotent); (iii) Stripe
   payments the signature-verified webhook path already recorded, mirrored as derived rows that
   can be rebuilt from registration_payments.
3. Why the ledger is not a scheduler: a ledger row never causes money to move. A charge row says
   "the school expects X on date D". Whether and when X is collected is Stripe's act (autopay) or
   the office's (desk). Charge rows are written when an autopay schedule is created (one place,
   from the stored snapshot), by an import of the school's own sheet, or by an explicit staff
   action. Nothing fires on them. A late fee charged by Manara on a date would be a scheduler and
   needs its own decision.
4. Payment state (corollary (b)): anything that LOOKS like Stripe state (paid_via stripe, the
   stripe_* columns, source stripe_webhook) is written only by the webhook projection. An invoice
   the school marks paid in its own dashboard never becomes Stripe state: staff record how the
   money actually came. Staff entries carry a how-it-came label and the recording staff member,
   extending the precedent of form take-cash and lunch Mark paid to a family account. Staff
   entries are never written to registrations.payment_status or registration_payments. A
   statement says "recorded by the school", never "paid by card through Manara".
5. Aid (corollary (c)): a credit entry records a reduction the school decided on. It is not money
   movement and does not change what Stripe charges. Posting one to an account with live autopay
   needs an acknowledgement, and the statement says so.
6. No card surcharge. Tuition is published with card costs built in. A school may discount other
   ways of paying, and that discount is priced before payment in both places it applies:
   (i) autopay: a separately priced bank-debit plan the family chooses on the setup page, which
   corollary (c) already allows as a pre-checkout price; (ii) money received outside Stripe: a
   credit the office posts with each payment, computed from the amount received (never typed) and
   capped at the discount on the whole charge. The offline credit is not post-hoc money movement: it moves no money and changes no
   Stripe charge; it records the price the school set for that way of paying, at the moment the
   office receives the money. Manara never adds a fee to a card payment.
7. Refunds and disputes remain the organisation's own act in its own Stripe dashboard. For a
   payment Manara projected, the webhook records what Stripe reports (refund amounts, dispute
   opened and closed) on the payment row and as idempotent ledger rows; staff cannot hand-record a
   refund of a projected payment. Staff record refunds of money that came outside Stripe.
8. Unchanged: the organisation is merchant of record, Manara never holds funds or sees card data,
   and only Stripe-hosted surfaces take payment details.

Alternatives rejected: Stripe-only (cannot represent cash, check or Zelle, and gives no family
balance); per-response take-cash on forms (one payment per response, no balance);
registration_payments (one writer, the webhook, by design, per registration); the class-store Bucks
ledger (prize points, not money); a card surcharge (owner answer N1).

Consequences: new tables and opt-in grants; the ledger is the ONLY record of offline receipts, so
off-site backups with a passed restore drill are a precondition for real data; a read-only
reconcile command exists because a webhook-only mirror can miss a payment.

Design and slicing: the tuition plan (private workspace), reviewed by the point session in two passes
(2026-09-30). Rule files: .claude/rules/family-ledger.md (new), stripe-payments.md ("Stripe owns the
billing clock" and "Registration payments record fee/net"), registration-billing-data.md ("Tuition
autopay"). No code implements this yet; slice 1A builds the ledger.

## 2026-09-30 — Switching the universal cart on for MEC (owner: "Yes, switch it on for MEC")

The owner, in the cart session: switch the cart on for MEC only (`CART_ENABLED=true`, `CART_MASJID_IDS=13`). The member portal stays
dark (`MEMBER_PORTAL_ENABLED` unset; PM-A1/#61 still stand). The enable blockers, each closed or decided here:
- PM-A6b, the form refund arm: it now asks the strict `CartTables::existsOrFail('order_items')`. With the cart on, a failed table
  check read as "absent" would let the FORM arm flag a row a basket settled, and the cart arm owns that flag. So a failed check
  throws, the webhook answers 500 with the event left unprocessed, and Stripe's retry flags the row once. A genuinely missing table
  (the deploy window) still skips the exclusion. The fail-safe `has()` is now used only by `cart:prune`. Test:
  `CartDeployWindowTest::a_live_form_refund_whose_order_items_check_throws_is_refused_for_a_retry_and_flagged_once_on_it`,
  which replaces the fail-safe test.
- PM-A6, the events: `payment_intent.payment_failed` is now routed for a basket only (`CartPaymentService::handlePaymentFailed`,
  info with the decline code, no state change). Cart pages are card only (`payment_method_types => ['card']`), so a decline leaves
  the page open for another card, and `checkout.session.async_payment_*` cannot occur for a basket. Every other payment intent's
  failure is acked and ignored as before. `checkout.session.expired` was already handled (pending to expired). The Connect
  endpoint's subscriptions are the owner's step, after this ships. Until then `cart:prune` ages out stale pending orders. Test:
  `CartPaymentFailedTest`.
- #43, MEC's return origin: verified read-only on production. `FormPaymentReturn::base()` admits
  `https://mec.manara.hopetechapps.com` (MEC's active managed subdomain) for masjid 13 and refuses it for another org.
  `mec-web.pages.dev` and the reserved domains get no base, which is correct. Pinned by the existing
  `CartCheckoutEndpointTest::a_confirmed_domain_of_this_organisation_is_the_return_base_and_another_organisations_is_refused`.
- #44, MEC's grants: already met on production (`giving` and `jummah_lunch` on, the giving module on, `canAcceptDonations()` true,
  a Stripe account). No change.
- The canary: `api/v1/cart*` is NOT added to `canary.throttle_allowlist`. A basket is anonymous and token-keyed, and its tenancy is
  pinned by `CartBasketAccessTest` and `CartTenantIsolationTest`, so the hourly canary could not probe it without a token it does
  not have. Revisit if a signed-in basket arrives.
- The live test purchase is the owner's: a $1 donation through the basket, refunded in Stripe.

## 2026-09-30 — Shop slice B1: products, size variants, stock without a hold table, and a fourth cart line (branch feat/shop-b1, off 6de704bc)

The plan is `mec-wix-migration/design/shop-plan-2026-09-30.md` (the point's review is binding) and the brief is
`design/brief-shop-b1.md`. This slice is the money core: the catalogue tables, a product as a fourth basket line type, stock, and
the settlement arm. It ships DARK behind the new `shop` grant (OFF for every organisation type), so nothing changes for anyone
until a SuperAdmin grants it. No admin API, no public read API, no pickup list and no confirmation e-mail: those are B2. **Nothing in
this slice has been run: there is no PHP on the machine it was written on** (ASSUMPTIONS, shop slice B1, S1).

- **The grant.** `shop` is a `grant` in group `registration_money`, label "Online shop", defaults all false, and `listed_when_off`
  TRUE as the brief says. Consequence for the point: TeamController lists every grant that is not `listed_when_off => false` as a
  chip even while it is off, so every organisation's Team & Access screen gains an "Online shop" chip (off). TeamController's own
  docblock says `listed_when_off => false` exists so that "adding a grant never adds an 'off' chip to every organisation's screen",
  and class_store and the other recent grants use it. One word flips it, and nothing else reads the key. Mirrored where 9c0a4add mirrored class_store: `Capability.ts` (union and label), `OrganisationModulesTest`,
  the three provision snapshots and `set-capability-responses.json`.
- **The tables.** `products`, `product_variants` (soft-deleting) and `product_sales`; integer minor units; `BelongsToMasjid` on all
  three. Unique among LIVE rows: a product's slug per organisation, a size's label per product, as a partial index on SQLite and as
  a VIRTUAL generated column plus a unique index on MySQL (`live_slug`, `live_label`), hidden on the models and listed under
  `never_write` in the staging scrub. VIRTUAL, not STORED: `migrations.md` says STORED, but the two shipped migrations that use this
  technique (`masjids.active_owner_user_id`, `masjid_user.default_key`) record that an ALTER adding a STORED column rebuilt the table
  and failed on foreign keys in production, and that a unique index on a VIRTUAL column works on 8.0+. I followed the shipped,
  production-run migrations and said so in the migration's docblock; the rules file's line is the one that disagrees.
  `product_sales`: `order_id` and `order_item_id` RESTRICT (a paid order is never pruned; a masjid hard-delete with a paid shop order
  is therefore refused, which is deliberate), `order_item_id` UNIQUE (one sale per line), and `product_id` and `variant_id` are plain
  indexed ids with NO key, because either may be trashed, even removed, after it sold: the snapshot columns are the truth. It holds
  no buyer data (the pickup list reads the buyer from the order), so it has no contact column and `MemberAccountDeletionCoverageTest`
  needed nothing. An index on `order_items (buyable_type, buyable_id)` serves the stock hold's read.
- **Stock without a hold table.** `available = stock - sold_count - held`; NULL stock is unlimited. `held` is the quantity on the lines
  of PENDING orders whose `checkout_expires_at` plus `cart.shop_hold_grace_minutes` (default 15, floor 0) is still ahead, so a unit is
  held 31 + 15 = 46 minutes at most. Nothing releases: paid moves a unit into `sold_count` at settlement, an expired order stops
  matching, prune deletes the old ones. All of it is `App\Services\Cart\ProductStock`.
  - **Where it is asked.** The pricer (advisory: a notice before the card screen), the checkout (the decision), settlement (the count).
  - **The checkout locks FIRST.** `ProductStock::lockBasket()` runs straight after the cart's own lock, before the pricer's first
    plain read, with locking reads only (the basket's product lines, then those sizes `FOR UPDATE` in ascending id). Reason: under
    REPEATABLE READ the first plain SELECT fixes the snapshot, so a checkout that priced first and locked after would wait behind a
    competing checkout and then sum `held` from a snapshot older than the winner's commit; both would see the last unit free. That
    is the brief's "lock every variant ... re-compute available" made correct: the lock cannot sit between the order insert and the
    re-count, because the re-count's plain read would already be pinned to an old snapshot. The brief's own check still runs where it
    said (`createPendingOrder`, after the lines, before `openPage()`), re-locking the same rows (a no-op) and excluding the order it
    just made; a shortfall throws `CartCheckoutRefused` ("{label}: sold out." / "{label}: only N left."), the transaction rolls the
    order back, and no page is opened.
  - **The `held` sum is a plain read on purpose.** A locking read over `orders` would queue behind a settlement holding the order row
    while that settlement waits for the size this checkout holds: the lock order is cart, order, size, and a checkout that took the
    order rows after the size would invert it.
  - **Own basket excluded in the pricer, only the new order in the decision.** The pricer leaves out the basket's OWN pending orders
    (the page about to be reused or replaced must not count against its own basket: the brief's "must not count the reused order
    twice"; the reuse path makes no new order, so it runs no second check). The decision excludes only the order it just made, so it
    is conservative: a stale pending order of the same basket that opened no page (which `reuseOpenPage` cannot see) still counts and
    refuses with a sentence. That cannot arise today (a checkout closes the basket's earlier page before it opens another);
    `ShopStockTest::the_decision_is_taken_inside_the_checkout...` builds it by hand to prove the decision is independent of the pricer.
  - **Two lines of one size clamp together, sequentially.** The pricer allocates in line-id order: each line gets what the earlier
    lines left. Subtracting every OTHER line's request would clamp both to nothing.
  - **A stale-snapshot oversell is still possible, only through a late webhook.** A payment that reaches the webhook after the grace
    can find its unit re-sold. That is the point's decision: record, flag, alert, never refuse, never refund (below).
- **Pricing** (`ProductLineSource`, called from `CartPricer`). The size is loaded with `masjid_id` (another organisation's is a miss,
  a trashed one too), then: the `shop` grant (else `gone`, "The shop is not available."); the product live, active, and the size
  enabled; the product's currency equal to the basket's (a product in another currency is `gone`, not sold at its number in ours);
  quantity 1..20 (above is clamped and told); unit = the size's `price_minor` else the product's `base_price_minor`, at least 1; a
  changed price is `repriced` ("The price changed while this was in your basket."); stock `gone` at none ("Sold out.") or clamped and
  told ("Only N left, so this was reduced to N."). Paid into the organisation's own account, as food and gifts are.
- **Adding.** `type: product` takes `variant_id` and `quantity` 1..20; the grant is asked FIRST, so a dark shop gives one sentence
  for a real id and a made-up one; another organisation's size and a missing one are the same 422 field error. The line carries
  payload `{product_id}`, label "{product} ({size})", `recorded_as` `sale`, `buyable_type` `product_variant`. The replay hash is
  `variant_id` and `quantity`. A request for more than is left is KEPT and clamped with a notice, as a dish is clamped to its cap.
- **Checkout freezes the sale.** `order_items.payload` is null and `price_snapshot` is `{product_id, variant_id, product_name,
  variant_label, unit_minor, quantity, total_minor}`: everything settlement writes the sale from, nothing personal.
- **Settlement** (`settleProduct`, dispatched on `product_variant`). Before the line loop it locks every size of the order's shop
  lines in ascending id, so two orders sharing two sizes cannot deadlock (and a checkout locks in the same order); the order stays
  cart, order, size. Then per line: the size under its lock (trashed rows included), `sold_count += qty`, a `product_sales` row from
  the snapshot, the line linked (`record_type` `product_sale`). Idempotent by `record_id`, with a belt: a sale that already names the
  line (unique) is relinked and counts nothing twice. A trashed, disabled or product-off size is still settled (a warning says so). A size
  REMOVED outright is recorded from its snapshot alone, with a warning and no stock to move: unlike a form or a fund (whose
  records have foreign keys), nothing here needs the row, and a retry loop on taken money would help nobody.
  **Oversold**: if `sold_count` then exceeds `stock` the sale is still recorded, flagged `oversold`, and an ERROR on the `monitors`
  channel (so `OPS_ALERT_EMAIL` fires) names the order number, the order id, the organisation, the size and its counts, with no
  buyer detail. It is raised AFTER the commit, as the e-mails are, so a rolled-back sale never alerts. It never refuses and never
  refunds. The pickup-list and order banners the review asked for are B2's; the flag they will read is `product_sales.oversold`.
- **Refunds and disputes stay order-level.** No product arm: `CartPaymentService` flags the order, a refund does not restock and
  does not touch a sale (`ShopSettlementTest::a_refund_or_dispute_flags_the_order...`).
- **The member portal does not list product sales in this slice.** `MemberPurchases` has no source for them, so there was nothing to
  exclude beside the form and meal exclusions; a member sees a shop purchase only as a line of the cart order that holds it (the
  `manara` source). A later source for `product_sales` must use `notOwnedByACart(..., OrderItem::RECORD_PRODUCT_SALE)` or one purchase
  is listed twice; the class docblock says so. The constant exists for that.
- **`CartTables::NAMES` gains `product_sales`, before `order_items`.** Its consumers: `cart:prune` skips a night (an info line) until
  the shop migration has run, which is right and changes nothing once it has; the deploy-window tests drop the names in order and a
  FK child now precedes its parents; `MemberAccountDeletion::OFFICE_RECORDS`, the Wix undo and the contact merge look at `orders`
  and `carts` only and have no `product_sales` column to ask about.
- **Staging scrub.** `products.name`, `products.description`, `product_variants.label`, `product_sales.product_name` and
  `product_sales.variant_label` are `reviewed_keep` with a reason (catalogue text, not personal data); `live_slug` and `live_label` are
  `never_write`.
- **Tests.** `tests/Feature/Shop/`: `ShopCapabilityTest`, `ShopSchemaTest`, `ProductTenantIsolationTest` (one method per model, as
  `TenantScopingCoverageTest` reads them), `ProductLineTest`, `CartAddProductTest`, `ShopStockTest`, `ShopSettlementTest`;
  `CartColumnWidthsTest` and `CartConfigTest` extended; `tests/Mysql/ShopLiveUniquenessTest` and `ShopStockMysqlTest` (group `mysql`)
  run the generated columns and the whole stock path on the engine, on ONE connection: they prove the statements and the
  arithmetic, not a two-connection race.

## 2026-10-01 — Shop B1 ship critic: the checkout's size lock no longer gap-locks other baskets

- **The finding (confirmed, major).** The ship critic was three lenses, each finding put to an adversarial refuter.
  - `ProductStock::lockBasket()` was a locking range read of `cart_items` (by cart, organisation and type), run in EVERY
    checkout.
  - Under InnoDB REPEATABLE READ, a locking read over a non-unique index takes next-key and gap locks even when it matches
    nothing. So an ordinary basket's checkout, with the shop off everywhere, made other baskets' line adds wait for its whole
    transaction. Other baskets means any organisation's dish, form or gift, and the transaction includes the Stripe calls.
  - Behind a slow Stripe, those adds could fail at `innodb_lock_wait_timeout` with a 500. Before B1, a checkout locked only its
    `carts` row by primary key.
  - SQLite has no row locks, and the race proof used shop baskets only, so neither showed it.
- **The fix.**
  - The basket's sizes are read by `ProductStock::basketVariantIds()` BEFORE the checkout's transaction opens. A plain read
    there fixes no snapshot and takes no lock.
  - Inside the transaction, `ProductStock::lock()` locks them by PRIMARY KEY alone. A `masjid_id` condition could let the
    optimiser range-scan the organisation's index, so the organisation is checked on the returned rows instead.
  - A basket with no product line takes no extra lock at all, exactly as before the shop.
  - After pricing: if the priced lines name a size that was not locked (one added between the read and the cart lock), the
    attempt rolls back having written nothing (`BasketSizesMoved`) and runs once more.
  - A basket that moves under two attempts is refused with `CartCheckoutService::SIZES_MOVED`.
- **Also fixed (confirmed, minor).**
  - A replacement checkout that failed after closing the old page (Stripe failing to open the new one, or the stock refusal)
    rolled back the old order's `expired`, while its Stripe page stayed expired. The dead page then read as `pending` and held its
    units for up to 46 minutes.
  - Every order `markExpired()` touches during an attempt is now marked expired again after a rollback (`keepExpired()`, with the
    same pending → expired guard).
- **Proof.**
  - `ShopStockTest`: the lock-order test now allows only the cart lock before the size lock, requires the size read BEFORE the
    transaction, and requires a primary-key-only lock statement. New tests: a size added mid-checkout reruns once; one that keeps
    moving is refused after two attempts with nothing written; a failed replacement leaves the closed page expired and its
    units free.
  - Mutants K1-K5: all killed.
  - `tests/Mysql/ShopCheckoutLocksMysqlTest` reads `performance_schema.data_locks` inside the checkout's transaction. An ordinary
    basket adds no `cart_items` lock; a shop basket adds only `product_variants|PRIMARY|X,REC_NOT_GAP`.
- **Refuted, but carried to B2 (it gains editing).**
  - MySQL's live-slug and live-label unique indexes compare under `utf8mb4_unicode_ci`, which is case- and accent-insensitive,
    and SQLite's do not. B2's clash checks must compare the same way.
  - Renaming a size that baskets or sales hold would change what a paid line names. B2 decides whether a held size's label is
    frozen.

## 2026-10-01 — Shop slice B2: the admin API, the pickup list and the public read (branch feat/shop-b2, off feat/shop-b1 e24baced)

The brief is `design/brief-shop-b2.md`; the plan and the point's review are `design/shop-plan-2026-09-30.md`. This slice puts three doors on
B1's tables: the office's catalogue (products, sizes, pictures), the pickup list with its collect, undo and CSV, and the renderer's read.
Everything stays behind the `shop` grant. **Nothing in this slice has been run: there is no PHP on the machine it was written on**
(ASSUMPTIONS S-12). No migration, no model column and no new permission: the one model edit is the constant `Product::MAX_IMAGES`.

- **Who may reach the admin routes.** `capability:shop` on the `{masjid_id}/shop` prefix (a dark shop answers 403 with "Online shop is not
  switched on for this organisation.", exactly as class_store's routes do; a SuperAdmin passes, as for every grant), and per route
  `permission:view donations` (every GET, the CSV included) or `permission:manage donations` (every write, collect and undo included). The
  brief said to use what the office already has for orders and money. Jummah-lunch orders and the form roster carry NO `permission:` (only
  `admin`), so there was nothing to copy there; the one pair that gates what somebody is CHARGED is the fee plans' (`view donations` /
  `manage donations`, routes/admin.php), and a product's price is exactly that. No permission was minted (`Permission::count()` stays 8;
  `ShopAdminGateTest` asserts it). The routes sit OUTSIDE `crm`, as the Jummah-lunch board does (its comment: a masjid selling lunch must
  not first switch on the member directory): the pickup list reads the buyer from the order, not from a contact, so nothing here needs the
  directory. `ShopAdminGateTest` walks the router and fails for a shop route with another permission, one inside `crm`, or one it has no
  call for, so a route added later has to bring its gate and its tenancy case.
- **The currency (closes S-9).** `products.currency` is ALWAYS `config('services.stripe.currency')`, written on every create and every
  update. A request that names another currency is a 422 ("Products are sold in USD only."), not a quiet override, so a client that thinks it
  is selling in pounds is told; the platform's own currency in either case is accepted. A product left in another currency by the B1
  column default is put right by its next save.
- **The slug** is generated from the name once, at creation, and never changes with a rename (the renderer links to it), and is never read
  from the body. It is unique among LIVE products of the organisation: a clash gets `-2`, `-3`, and a soft-deleted product frees its slug, so
  a product re-created under the same name gets the plain one (`ProductSlug`, which reads only live rows, exactly what B1's partial index
  / `live_slug` column constrains). Two saves that pick the same slug at once are sorted out by the unique index; `ProductWriter::create`
  retries with a fresh read (five tries). A name that slugs to nothing gets `product`.
- **A product's sizes are a LIST inside its update** (and, optionally, its create). `variants` absent: the sizes are left alone. A list is the
  product's whole set: a row with an `id` edits that size, a row without one adds one, a live size left out is SOFT-deleted (its `sold_count` and
  its sales stay). Within a row an absent key leaves an existing size's value alone and a null clears it (no price of its own; unlimited
  stock). An `id` that is not one of THIS product's live sizes (another organisation's, another product's, a trashed one, one that is not
  there) is a 404 like any other id and the whole save rolls back. The writer applies the list in a fixed order, because the unique
  index on a label is among live rows: omitted sizes first (freeing their labels), sizes whose label changes then step aside under a
  throwaway label, then the new labels and the new rows. So a size removed and a new one of the same name in one save works, and two
  sizes may SWAP names (S and M) in one save. `sold_count`, `product_id` and `masjid_id` are never read from a row. The list's order is the
  order the caller sent (a new size with no `sort` takes its position in it); `validated()` rebuilds the list rule by rule, so the request
  restores the order from the raw input's keys.
- **Clash checks compare the way production's index does** (from B1's ship critic). The live-slug and live-label unique indexes compare under
  `utf8mb4_unicode_ci` on MySQL (case AND accent blind: `Polo` = `polo`, `M` = `m`, `Médium` = `Medium`, `Straße` = `Strasse`), and SQLite's
  partial indexes are byte-exact, so the suite cannot see a clash the index would refuse. Every clash check B2 makes itself therefore folds
  both sides through `LiveText::fold()` (Latin letters transliterated with `Str::ascii`, then lower-cased; any other script is only
  lower-cased, so two different Arabic labels are never merged): the label validation ("Two sizes cannot share a name", a 422 on
  `variants.N.label`) and the slug suffixing (`ProductSlug`, whose read is also case-blind, `LOWER(slug)`, so a capitalised slug an import left
  behind still counts as taken). It errs toward calling two texts the same: a sentence or a `-2` is a cheap mistake, a unique-violation 500 is
  not (ASSUMPTIONS S-26).
- **A size's name is FROZEN once any `order_items` row names it** (from the same critic): an open payment page, a paid sale, an order that
  expired, whatever became of it. A rename would change what that line is called while the sale's snapshot keeps the old name. It is a 422 on
  `variants.N.label` ("The size "M" is already in a basket or an order, so its name cannot change. Switch it off and add a new size instead."),
  the whole save is refused, and the size's price, stock, `enabled` and `sort` stay editable; it can still be removed (soft-deleted), and a
  new size can be added beside it. The check is made in `ProductWriter` under the sizes' row locks, so a line a checkout wrote a moment ago is
  seen (ASSUMPTIONS S-27).
- **A stock set below what is sold and held is allowed** (the brief asks for this call to be recorded). Stock is the TOTAL for sale, sold units
  included; typing a number under `sold_count + held` is the office saying "stop selling", and refusing it would make a mistyped total
  impossible to correct. The answer carries `stock`, `sold_count`, `held` and `available` (never below zero: `ProductStock::available`),
  so the SPA can say "Total 20 · sold 12 · in baskets 1 · left 7", and the stored `sold_count` is never touched (it moves only at
  settlement, under the size's row lock). `held` is ONE grouped query for every size on the page.
- **Money in the request is `integer:strict`**: a JSON number, never a string, a float or a boolean. A price is 1 to
  `FormPayment::MAX_CHARGE_MINOR` (the ceiling `CartCheckoutService` refuses a basket total at), a stock 0 to the unsigned-int ceiling.
  Widths are the columns': `ShopProductValidationTest` reads the migration and compares (SQLite ignores a varchar's length).
- **Pictures.** Spatie media, collection `product_images`, on the library's default disk as the gallery uses (`MEDIA_DISK`, `public`). The
  upload rule is the one every image upload in the admin has (`ValidatesVideoSection::sectionUploadRules`): `mimes` reads the bytes, `extensions`
  pins the client's file NAME (the library keeps it on the public disk and the web server picks the Content-Type from it), 25 MB each (10 MB since the fix round, below)
  (the library's own ceiling); SVG is not on the list. At most 8 per product: the count is taken under the product's row lock, so two
  uploads at once cannot both pass, and an upload that would take it past eight is refused AS A WHOLE (not even the first file is
  kept). Reorder takes the full list of the product's picture ids and writes Spatie's `order_column`: an id that is not this product's is a
  404, a list that leaves one out a 422. The `media` table has no organisation column, so tenancy is the product's: a picture is only ever
  read through `Product::images()` (which carries `model_type` as well as `model_id`, b5c2f808's rule), and the count behind the cap uses
  `reorder()` because an ORDER BY on a bare COUNT(*) is an error under ONLY_FULL_GROUP_BY.
- **The pickup list** (`PickupList`, one query behind the list, its CSV and the row a collect answers with). The buyer (name, e-mail, phone),
  `paid_at`, the currency and the refund flag are read from the ORDER, joined on the order id AND the organisation; the product name, size,
  quantity and prices are the sale's snapshot, so a renamed or deleted product does not change what was sold; nothing of the buyer is copied
  into `product_sales`. States: `to_hand_out` (the default: not collected and the order not refunded or disputed), `collected`, `all`. A
  mistyped state is a 422, never "everything". **`refunded` is `charge_flag` refunded or disputed**; `partially_refunded` is NOT in it (a
  refund names an amount, never a line, so the office cannot tell it was this item), so such a sale stays to hand out and carries its
  `charge_flag` for a person to judge (ASSUMPTIONS S-18). Search matches the order number or the buyer name, case-blind, `%` and `_`
  literal (`LOWER(col) LIKE ? ESCAPE '!'`, because a backslash escape means different things in a MySQL and a SQLite string). Newest paid
  first, `per_page` 1 to 100 (default 25).
- **The header counts** (`meta.summary`) are ONE grouped query over the whole organisation, per product and size: `to_hand_out` and `collected` in
  UNITS, and (since the fix round, below) `oversold_open` in LINES; it was oversold units not refunded, which kept counting a sale the office had
  dealt with. The filters do not move it. It is
  grouped by the names the sales carry, so a product renamed after it sold shows as two lines for the same size rather than hiding a name.
- **Collect and undo.** `POST .../collect` is the form roster's check-in for a sale: stamped by the FIRST press only (a repeat answers "Already
  handed out." with the first collector kept), on the locked row. It is refused with a sentence on a refunded or disputed order, and that
  check comes FIRST: collect never claims "handed out" on money that went back, even for a sale collected before the refund. `DELETE` clears the
  mark (allowed on a refunded sale too; nothing to undo is a 200). Both write the actor to the log as the office's other actions do (ids and the
  order's number, never a buyer detail); the undo also logs who had collected it and when, since clearing the stamp would erase that.
- **The CSV** (`GET .../shop/sales.csv`, same filters, same columns) goes through the SHARED writer and escaper of the school-records exports
  (`SchoolRecordsCsv`: BOM, RFC 4180 rows, `text()` on every cell a person typed, the phone included because `+` triggers a formula), not a
  new one. `Refunded` is yes/no, as the list says it (refunded or disputed), and `Charge flag` carries the order's own word beside it, so a partly
  refunded sale, which is still to hand out, is not read as refunded. Rows come in sale-id order because the shared chunked walk needs an
  unordered query (the cursor is `product_sales.id`, qualified, because the join has an `id` of its own), times are the organisation's own
  clock with the zone written out, `Cache-Control: no-store, private`.
- **The public read** (`GET /api/v1/shop/products`, `/{slug}`, `masjid-id` header). Dark by `shop.enabled` (`EnsureShopEnabled`): the router's
  own 404 (`DarkRouteException`), ranked ahead of every throttle in `bootstrap/app.php` like the basket's gate, unless the grant is on AND
  the basket is on for the organisation (`EnsureCartEnabled::enabledFor`). The second half is my addition to the brief (S-20): a listing
  nobody can put in a basket is a dead storefront, and one gate gives one 404. Listed: active, not deleted, with at least one ENABLED live size;
  a disabled or deleted size is not shown at all. Per product: name, slug, category, description, `price_minor` (the lowest enabled size's
  effective price) and `price_varies`, `currency`, picture URLs in order, and sizes as `{id, label, price_minor, sold_out}`. **The point's
  rule: availability is ONE plain boolean.** No stock, sold, held or available number, no oversold flag, no "only N left" wording, at any depth;
  a picture is a bare URL string, so it cannot carry a field. `ShopPublicApiTest` pins the exact keys of the product and of each size,
  the type of every value, and the absence of any key that looks like a figure. A size is `sold_out` when `available` is 0; an unlimited
  size never is. Four queries however many sizes (products, sizes, pictures, ONE grouped held sum), pinned by a query-count test. No
  caching: the reads send nothing, so they carry the same default `Cache-Control` the basket's reads do, and a test compares the two.
  The generous named limiter `shop-read` (600 a minute per connection and organisation) is behind the gate; the renderer reaches us from
  Cloudflare's shared addresses, as the by-host lookup's does. The `data` key is always present (`[]` for an empty shop), which the
  `api` macro would drop.
- **Not changed.** Staging scrub, `MemberAccountDeletionCoverageTest`, `TenantScopingCoverageTest` and `StagingScrubCoverageTest` demand nothing
  new (no table, no column, no model). `CartColumnWidthsTest`'s map already holds the widths B2 validates to; only its comment was updated.
  The tenancy canary plans public GET collections from the route table (`ProbeCatalog`); `GET /api/v1/shop/products` is DECLINED there, behind
  `throttle:shop-read`, which is not in `canary.throttle_allowlist`, exactly as the basket's `GET /cart` is, so the hourly canary neither spends the
  limiter nor reads the dark 404 as an unreachable endpoint; `/{slug}` takes a parameter and is never planned. The admin SPA (B3) and the
  renderer (C) are not in this slice.
- **Tests.** `tests/Feature/Shop/`: `ShopProductsAdminTest`, `ShopProductValidationTest`, `ShopProductImagesTest`, `ShopPickupListTest`,
  `ShopPublicApiTest`, `ShopAdminGateTest` (capability, permission and tenant on every route, walked from the router), and the traits
  `BuildsShopAdmin`. Written without being run (S-12).

## 2026-10-01 — A hard-deleted size can still gap-lock product_variants (accepted, documented)

- **Raised by the point's review of the B1 lock fix:** `ProductStock::lock()` is a primary-key `IN` locking read. For an id with no row
  (a size HARD-deleted while a basket still names it), InnoDB locks the gap where that id would be. Above the current maximum id
  that gap is the supremum, so new sizes for EVERY organisation wait until that checkout commits, Stripe calls included.
- **Why it is accepted:**
  - Sizes and products are only ever SOFT-deleted. B2's admin API trashes rows and never `forceDelete()`s them, and no command or
    job removes them.
  - So only a hand-run SQL delete reaches this. The wait is one checkout's length, and it blocks only the adding of sizes.
- **The rule:** never hard-delete a `product_variants` or `products` row on production. A cleanup that needs to must first remove
  every `cart_items` line naming it.

## 2026-10-01 — B2 critic fix round: a sale's resolution, locks, a script-independent fold and stale editors (branch feat/shop-b2, rebased onto the shipped B1 d2fbee5d)

The brief is `design/brief-shop-b2-fixes.md`: six minor findings of the B2 critic, plus the point's two additions (the picture limit, the public cap).
B1 is in production, so its migration is untouched; ONE new migration, `2026_10_06_100200_add_resolution_and_lock_version_to_shop_tables`
(Blueprint only, every name by hand, a real `down()`), adds the columns below. `php -l` was the only thing run: nothing else was, and the suite has still
not run (ASSUMPTIONS S-12). Each item is its own commit.

1. **A sale's resolution.** `product_sales.resolution` (string 16, nullable: `refunded` | `substituted`), `resolved_at`, `resolved_by_user_id` (nullable
   FK to users, hand-named `product_sales_resolved_by_foreign`, nullOnDelete as `collected_by_user_id` is). `POST .../shop/sales/{sale}/resolve
   {resolution}` and `DELETE .../resolve`, both `manage donations`, both answering `{status, message, data: row}` as collect does and both logging the
   actor the way collect does (ids and the vocabulary word only; a clear logs what it was and who had set it). Resolving is idempotent: the SAME
   word again changes nothing and keeps the FIRST resolver and moment; a DIFFERENT word replaces the resolution, and the sale then carries who set THAT
   one (S-33). `refunded` takes the sale off "to hand out" (list, summary and row `to_hand_out`), like a fully refunded order, and `collect` refuses it
   with a sentence; `substituted` STAYS to hand out (the substitute is what is handed over) and only ends the need for anyone's call. `oversold` itself is
   never cleared: it is a fact; `resolution` is what was done. A row's `refunded` stays the ORDER's flag. Rows carry `resolution`, `resolved_at` and
   `resolved_by {id, name}`; the CSV gets a `Resolution` column after `Charge flag`. No buyer data and no free text, so nothing for the staging scrub.
2. **collect locks the order.** After the sale's own lock, collect reads the order's `charge_flag` with `lockForUpdate()`, so a refund or dispute being
   recorded at that moment is waited for, not raced. Lock order: sale, then order. Settlement locks the order and then INSERTS new sales (it never locks an
   existing sale row), and the refund arm (`CartPaymentService::flagOrder`) locks the order and nothing else, so there is no cycle; the docblock says so.
3. **`oversold_open` replaces `oversold` in the summary.** It COUNTS SALES (lines), not units: oversold, the order not refunded or disputed, `resolution`
   NULL and not collected. Units overstated the work (one line of 5 is one decision), and a resolved or handed-over line kept counting.
4. **No gap-locking read of sizes.** `create()` takes no locking read of sizes at all (a product made in the transaction has none). `update()` locks the
   product row FOR UPDATE (it serialises every writer of the product and its sizes), checks `lock_version`, and then, when the request carries a list,
   locks the sizes THE ROWS NAME by primary key alone, ascending, through `ProductStock::lock()` (the lock a checkout takes before it writes an order
   line; no `product_id` or `masjid_id` in the statement, because under REPEATABLE READ a locking range read gap-locks: B1's ship blocker, 740e5db1);
   ownership is checked on the rows afterwards (a size that is not this product's live size is the 404). ONLY THEN are the live sizes read (a plain
   `get()`), and the `order_items` that name a renamed size (also plain), so both see every line a competing checkout committed, and a renamed size is
   always one of the request rows: the frozen-label guarantee holds. No consistent read comes before the first lock: the route binds the product in
   the controller, BEFORE the transaction, in autocommit, and the request's validation reads nothing, so the transaction's snapshot is fixed after the
   locks. `delete()` finds the sizes with a plain read under the product lock and soft-deletes them by primary key after the same locks, not by a
   `product_id` range. All three run in `DB::transaction(..., 3)` (a deadlock victim retries; no Stripe call, mail or file is in any of them). The writer
   never writes `sold_count`: a test saves after a settlement moved it and reads the statements.
5. **A script-independent fold and a backstop.** `LiveText::fold()` is NFKD (the `intl` Normalizer), then every mark (`\p{M}`) and every format
   character (`\p{Cf}`: soft hyphen, ZWSP, ZWJ, ZWNJ, directional marks) and the Hangul fillers stripped, white space collapsed and the ends trimmed (MySQL 8.4's
   unicode_ci is PAD SPACE), lower case, then Latin runs transliterated (`ß` to `ss`). So `Е`/`Ё`, `أطفال`/`اطفال` (hamza), `آ`/`ا` (madda), `XL`/`X­L`,
   `M`/`M `/` M`, `Medium`/`Médium` are each one label, and two different Arabic or Cyrillic words stay two. Without `intl` the Normalizer step is
   skipped (the fallback still folds case, space, format characters, decomposed marks and every Latin accent; precomposed `Ё`, `أ`, `آ` would be told apart:
   S-29). Whatever the fold misses meets the index, and `ProductWriter` CATCHES the violation: one naming `live_label` (MySQL) or `product_variants.label`
   (SQLite's wording) becomes a 422 on `variants` ("Two sizes of one product cannot share a name."); one naming `live_slug` / `products.slug` is retried
   with the next suffix, five tries in all, and after the fifth a 422 on `name` ("Another product took that name just now. Try saving again."), never a 500.
6. **Stale editors are refused.** `products.lock_version` (unsigned int, default 0). EVERY successful `update()` adds one under the product lock, a
   sizes-only save included; so does every picture upload, delete and reorder (they change what the editor shows), by one atomic UPDATE. Every product
   answer carries `lock_version` (show, index, store, update and the three picture answers). `PUT products/{id}` REQUIRES `lock_version` (`integer:strict`,
   a missing one is a 422 on `lock_version`); under the lock a different value is HTTP 409 with the body exactly
   `{"status":"failed","message":"This product was changed by someone else. Reload it and make your change again."}` and nothing is written. The
   Studio draft's check (`StudioDraftsController`, `lock_version`) was the precedent; its 409 body is `{status:'conflict', message, data}`, but the body
   here is the one the point pinned for the SPA, without `data` (S-32). The picture endpoints need no `lock_version` of their own.

**The point's two additions.** A picture is at most 10 MB (`UploadProductImagesRequest::MAX_MB`; "Each picture can be at most 10 MB."), because
production's `post_max_size` is 110M and eight at the old 25 MB (200 MB) could never arrive and failed as a bare 413 (S-16 closed); `meta.max_image_mb` is 10,
beside `max_images`. `PublicCatalogue::listing()` returns at most 200 products (`LISTING_LIMIT`), in the order the office set; the shape is unchanged and a
product past the 200th is still reachable by its own slug (S-19 now bounded).

**Deferred to v1.1, not built:** a collect-only or shop-specific permission; the oversold alert wording when an admin lowers a stock below sold plus held; a
deleted product's pictures staying on the public disk; the upload writing files inside the database transaction; reorder and delete of pictures not taking the
product's row lock first (they bump the version with one atomic UPDATE instead, which is enough for the version but takes no lock for the media rows themselves);
a pixel-dimension cap on pictures.

**For B3 (the new fields, pinned):** every product answer carries `lock_version` (int); PUT sends it back (422 if missing, 409 if stale, then reload and
repeat); sales rows add `resolution` (null | "refunded" | "substituted"), `resolved_at` (ISO 8601 | null), `resolved_by` ({id, name} | null), and
`to_hand_out` is false for "refunded"; summary rows are `{product_id, variant_id, product_name, variant_label, to_hand_out, collected, oversold_open}`
(`oversold_open` replaces `oversold` and counts lines); `POST|DELETE .../shop/sales/{sale_id}/resolve`; the CSV's last column is `Resolution`; products
`meta` is `{currency, max_images, max_image_mb}`. For C: nothing changes except the 200-product ceiling.

## 2026-10-01 — Three videos in a class story or a message, bounded by their combined size (W7-1, teacher feedback; branch feat/school-w7-video-count)
Decision: `groups.media.video.max_per_post` goes from 1 to 3 (code default, no production `.env` edit), for a class
story and for a conversation message alike. A new `groups.media.video.max_total_kb` (120MB) bounds what the videos of
ONE post or message add up to; one clip may still be the full 100MB.
- **Why a total and not just the count.** The count multiplies straight into the request body: three 100MB clips and
  eight photos is ~364MB, and `public/.user.ini`, php.ini and nginx all refuse a body over 192MB as a bare 413, before
  any validation message exists. With the total the worst legal body is ~184MB, so the change needed no server
  configuration change and ships like any other. `UploadCeilingTest` now computes the worst case from the smaller of
  count × size and the total, keeps 4MB of room for fields and multipart boundaries, and fails the build if a later
  change breaks either.
- **Storage.** The private disk holds the clips for 90 days and no backup covers it. Three full-size clips per post
  would triple the worst case per post; the total keeps it at 120MB (was 100MB).
- **Raising it later** (three full-size clips in one post) means raising `post_max_size` (`public/.user.ini` and
  php.ini) and nginx `client_max_body_size` to about 400MB first, then `max_total_kb`: a production server change
  that waits for the owner's yes.
- **Refusals name the limit.** "A post may carry at most 3 videos." (plural-correct; "1 video" when the limit is one)
  and "The videos in one post may add up to 120MB. Send the others in another post." The pickers apply the same rule
  while files are being chosen (`core/helpers/mediaPick.ts`, one definition for the shared picker and the office's
  story box), so the refusal comes before the upload, not after it.
- **An edit is held to the same limits.** `GroupPostsController::update` appended whatever arrived, counting only the
  request, so an edit could take a story past the limits a new one is held to. It now counts what the story already
  carries: photos by count, videos by count and by combined size.
- **The teacher screen reads the server's limits.** Its three pickers passed nothing and ran on the component's
  built-in default (one video), so a server limit never reached the people who asked for this. They now bind
  `pickerLimits(meta, …)` from the posts and threads lists.
- **Where the 192MB is true.** The two hosts staff use in production are served by nginx directly. Staging and the
  marketing host sit behind a proxy that refuses a body over about 100MB, so a request over that cannot be exercised
  on staging; the staging check for this change uses small clips (count and total with a lowered total), and the
  arithmetic is held by `UploadCeilingTest`.
- **A file the browser gives no type.** `isVideoFile` falls back to the extension (mp4, m4v, mov, webm), and the three
  places that split one list into the `images` and `videos` bags use it, so such a clip meets the video limits instead
  of being sent as a photo and refused after the upload.
- **Over the ceiling anyway** (a 413 from nginx or PHP, which no validation sentence can reach): every upload screen
  now says "That is too large to send together. Send fewer photos or videos at once, and the rest in another post."
  The office screens showed axios's "status code 413" before.
- **Known, not changed here.** (1) The family portal asks for one playback ticket per video tile as the page opens,
  and those count against the family rate limit; three videos per post makes a busy class page more likely to reach
  it (the tiles that are refused show the file name, not a player). Ticket on view or on press is the fix, next wave.
  (2) Two simultaneous API edits can each pass the count on the same story; no screen sends media on an edit, so it
  takes a hand-written client to do it.
Alternatives: raise the server ceilings to ~400MB now (a production server change, and three times the disk exposure);
lower the per-video size so three fit (takes away the 100MB single clip teachers have today); upload each video in its
own request (the right long-term shape, a larger change to the upload path).

## 2026-10-01 — `shop` section type (owner: "a small section in the web page editor to add any shop component to a page")
Decision: `SectionType::SHOP` (`shop`, "Shop"), content `heading`, `category`, `max_items` (1 to 24, default 8),
`show_view_all` (default on), exactly as mec-wix-migration `design/brief-shop-section.md`. It stores no product, price or
size: the renderer fetches the products from the public shop API, keeps those whose `category` equals the section's (null or
empty means every category), shows the first `max_items`, and links to `/shop` when `show_view_all`. `usesExternalData()` is
true. Both `getImageFieldsForSectionType` copies name it with no fields. `SectionContentBinder` and `PageSectionResource` are
untouched (the binder's `default` arm returns the stored content).
Calls the brief left open:
- **A capability gate for a section type did not exist; it is copied from the nearest one.** `requiresModule()` only knows
  modules, and `moduleIsOff()` fails open on any key that is not a module, so it cannot say "no `shop` grant, no section". No
  type was gated by a grant, and the palette is documented as global. The mechanism is the shop's own:
  `ProductLineSource::reprice()` asks `$org->hasCapability('shop')` (fails closed) from the ORGANISATION, and the `capability:`
  gate's refusal is worded "{label} is not switched on for this organisation". So `SectionType::requiresGrant()` is a new
  exhaustive `match` beside `requiresModule()`, the one palette filter goes where section-types.md says to put one
  (`PageSectionsController@sectionTypes`, validation stays ungated for reading), and the creation gate is `ValidatesShopSection`
  on the four requests.
- **A grant is the opposite of a module.** A module is on until switched off, so its type stays offered and only says so
  (`moduleOffNote`). A grant is off until given, so its type is not offered without it.
- **The organisation decides, never the viewer, SuperAdmin included.** The public shop API answers the dark 404 for an
  organisation without the grant whoever built the section, so a SuperAdmin creating one there would build a section that draws
  nothing. (The `capability:` middleware lets a SuperAdmin through; this rule is about the data, not the screen.)
- **What is gated is the offering and the creation, nothing else.** A section already of the type stays readable, editable and
  deletable after the grant is switched off, and the public page payload still lists it; the renderer shows nothing because the
  shop API 404s. Editing an existing shop section is allowed because it is neither a creation nor a change to the type; changing
  one away and back is refused (the way back is a change to it).
- **Unknown content keys are refused, not dropped.** The brief offered either "as the other types do"; the other types do
  neither (they store whatever is sent). Refusing is loud, needs no mutation hook and cannot lose an apply script's key quietly.
  Absent keys are accepted (the renderer reads an absent key as its default), so a row made through the API may hold fewer than
  the four.
- **Strict types on content.** `max_items` must be an integer (not "8", not 8.5) and `show_view_all` a boolean (not "yes", not 1):
  the renderer reads the stored value as it is. Heading and category count characters, not bytes.
- **Not listed in `withoutRenderer()`.** That list's one sentence ends "do not publish the sign-up form on a page instead", which
  is about an unrendered registration block and would be false on a shop section; changing it to be per type is a new mechanism.
  And the type is offered only to an organisation with the grant today (MEC), whose renderer is being built. Like `video`, the
  renderer must ship before a MEC page carries a shop section: until it does, the section draws nothing.
- **The stored-type lookup was split out of `ValidatesEmbedContent::resolvedSectionType()`** (`storedSectionType()`) so the grant
  rule can tell "already a shop" from "being changed to one". The lookup, including its tenant scoping, is unchanged.
- **The editor reads the categories itself.** `GET /api/admin/masjids/{id}/shop/products?per_page=100` is not in this branch
  (it ships just before this one); until then the call 404s and the editor shows the plain text input with "Leave empty for all
  categories". The list is read from `data` or `data.data`, so a paginated or a plain answer both work. A category over 60
  characters is not offered, since the server would refuse to save it.
- **No i18n strings.** The brief asks for them in every locale file; the page-builder editors carry their strings inline in
  English (all thirty-one), and the only locale files in the SPA (`views/family/locales`) belong to the family portal. Adding
  admin i18n would be a parallel system, so the editor follows the editors.
- **Palette pins updated on purpose** (as with `video`): the exact type count (28 to 29), `LATER_TYPES` in the school and community
  suites, and the exact list of external-data types, which now ends with `shop`. Two suites that read the whole palette for an
  organisation without the grant now expect one type fewer, or are given the grant.
Not run: `php artisan test` (the brief allows `php -l` and `npm run test:spa` only). `ShopSectionTypeTest` and the five edited
suites have not been executed; `npm run test:spa` is green (660).
Unknown, needs investigation: what the iOS and Android apps do with an unknown `shop` section. The API passes `platforms`
through without filtering (`PageSectionResource`), as it does for `video`; a MEC placement of `["web"]` is the safe one until it
is known.

## 2026-10-01 — Shop admin screens and the shop section: what the browser run and the point's review changed

- **The pager (found by driving the screens in a browser).** The shared `Pagination` emits its starting page as it mounts. The shop
  screens started it at 0, so the pickup list re-asked for `page=0`, got a 422, and showed that error over a list that had
  just loaded. Both lists now start at 1 and load only on a real move (`shouldLoadPage`).
- **A typed price (the point's review).** `parseMajorToMinor` stripped every comma, so "19,99" was read as 1999.00. A comma is
  now accepted only as thousands grouping (`^\d{1,3}(,\d{3})+(\.\d*)?$`); any other comma is refused with "Use a point for cents,
  like 19.99". The helper is shared with the fee plans, which take the same rule.
- **A picture answer's version (the point's review).**
  - Every picture call moves `lock_version` on by exactly one, and its answer is the whole product.
  - The editor used to adopt the answer's version outright. A colleague's save made in between was then silently overwritten by
    the next Save.
  - The editor now adopts it only when it is the version it held plus one (`pictureAnswerVersion`). On any other value it keeps
    the version the form loaded, so the next Save is refused (409), and it shows "Changed elsewhere … Reload" at once. The
    pictures always refresh from the answer.
- **A shop section is a WEBSITE section for now (the point's call).**
  - The native apps have not been checked against a section type they do not know.
  - `PageSectionsController` therefore refuses, with a 422 on `platforms`, a placement that would show a shop section in the
    mobile app. This covers store, update and attach. No platforms at all means both, so that is refused too.
  - `SectionFormModal` unticks and locks Mobile for the type.
  - **V1.1:** check the iOS and Android apps against an unknown `shop` section, then lift this.
- **Deferred to v1.1 from the same review:**
  - the pickup actions sit in a seventh, sideways-scrolling column on a phone;
  - handing out the last row of a later page reloads onto an empty page;
  - a 403 still shows the tabs and "New product";
  - a stock-below-sold hint;
  - an unsaved-changes guard.

## 2026-10-01 — Every basket payment asks Stripe to send its receipt to the buyer

- **The gap (the point's review of the renderer's shop pages).** A shop purchase mails the parent nothing from Manara:
  `settleProduct` sends no mail, and B2 added no Mailable. The basket's page set `customer_email` but no `receipt_email`. It is
  a direct charge on the organisation's account, so Stripe's receipt went out only if that account happened to have
  "successful payments" emails on. The site tells the parent a receipt was emailed.
- **The fix.** `CartCheckoutService::openPage` sets `payment_intent_data.receipt_email` to the buyer's address, for EVERY basket.
  Stripe then sends its itemised receipt in live mode whatever the account's setting; it is the one receipt that lists a mixed
  basket line by line. A gift in the basket also gets its own `DonationReceiptMail`: the duplication is accepted (the point's
  call). No address means no parameter.
- **A refused address.** A refusal that names either `customer_email` or `payment_intent_data[receipt_email]` drops BOTH and
  opens the page on a new idempotency key, as before.
- **Proof.** `CartCheckoutServiceTest`: present with a usable address; absent with none, an empty one or an unusable one; both
  dropped on a refusal naming either parameter. Three mutants killed.
- **Recorded for v1.1, not built:**
  - A paid basket's token stays live until its expiry, and add, remove and checkout answer 422 "This basket has already been
    paid for." The renderer matches that sentence to start a new basket, so `CartLineAdder::CLOSED` and `PAID_MESSAGE` must
    NOT be reworded. A machine-readable code (`data.code = 'basket_closed'`) beside the unchanged sentence is the v1.1 change.
  - The order status read allows 30 reads an hour per order (`cart.throttle.order_status_per_hour`); a poller must back off.

## 2026-10-01 — An unread count on the teacher Messages tab (W7-3, teacher feedback; branch feat/school-w7-unread-badge)
Decision: staff see how many messages from other people they have not seen: a pill on the Messages tab, an "N new" chip
beside the class name, "N new" on each conversation row, a count on each My Classes card, and the same on the office's
group screen. No migration and no new route; the number is delivered on payloads that already exist.
- **Reuse `group_thread_reads`, never seed it.** Staff bookmarks are shown to families as read receipts, so a baseline row
  written to make a count start at zero would tell every parent "seen by the teacher" about messages nobody opened. With no
  bookmark, only messages at or after `config('groups.messaging.unread_since')` count. That is the literal
  `'2026-09-28 00:00:00'` (UTC, the Monday of the week this ships: that week's unopened messages show, older history never
  does), deliberately not an env var: a production `.env` edit is a risk this does not need. Null or absent means no floor.
- **One grouped query** (`App\Support\GroupThreadUnread`, through `GroupMessage` so the tenant scope applies). My Classes
  computes it once for every class the teacher leads; a per-class query would grow with the teacher's classes. The thread
  list carries `unread_count` per row and `meta.unread_total` for the whole class (exact when the list is paginated, and
  independent of the `scope` filter). The admin group show carries `unread_messages`, counted only over the threads the
  caller may read, so an office user with no standing in the class gets 0 and the thread list still answers them 403.
- **The staff `unread` boolean keeps its key** (two native apps and the admin SPA read it) and now means `unread_count > 0`,
  so the pill and the number cannot disagree. Its old source was wrong in three ways: no bookmark meant every thread with a
  message was unread, it counted the reader's own and co-teachers' messages, and it was only known once the tab was open.
- **Long conversations (the count stalled).** `show` moves the bookmark to the newest message on the page it serves, and the
  teacher screen asked only for page 1 of 50, so a conversation of 51 or more messages could never reach zero, and a
  teacher could not read past message 50 at all. `per_page` is now honoured up to 200 (default still 50 for the native
  apps), and both SPAs read every page. The count of the thread returned is the one the LAST page left behind.
- **A reply from a stale screen no longer swallows a message.** Replying advanced the bookmark to the reply, jumping past a
  parent message that arrived while the screen was open, which was then never unread (and the receipt said the teacher had
  seen it). A reply now advances the bookmark only when no other author's message sits between it and the reply.
- **Not counted:** your own messages; a soft-deleted conversation; a conversation scheduled and not yet sent (it is not a
  `group_messages` row until the sweep writes it, then it counts for the other teachers and not for its author). A closed
  conversation still counts.
- **Phone.** Messages is the seventh tab and the strip scrolls it off screen, so a badge on the tab alone would be invisible
  exactly where teachers read messages; the chip beside the class name is a button that opens the tab. The tab was not
  moved into "More".
- **Keeping it true without a reload.** Opening a conversation subtracts its count locally; the next list's
  `meta.unread_total` replaces the guess; the class number refreshes when the window regains focus (at most every 15
  seconds, only the number, never a class reload that would drop a half-written reply).
- **Not done:** a teacher added to a class later sees that week's history as unread (the floor is global, not per
  assignment); the My Classes screen does not refresh on focus.
Alternatives: seed a bookmark per thread at deploy (rejected: false receipts); a per-class query (rejected: cost grows with
classes); an env-var floor (rejected: production `.env` edits); moving Messages into "More" (rejected: hides it further).

## 2026-10-01 — Edit a class story after it is sent, with an "Edited" marker and no re-notification (W7-2a, teacher feedback; branch feat/school-w7-edit-stories)
Decision: a class story that is OUT can be edited in place by the people who could already change it, and the edit is
visible: staff and families see "Edited", with the time as a tooltip. Nobody is notified.
- **Who: the class's teachers, and the office only where it may read the class feed.** Not only the author (pinned
  by `ScheduledClassStoryTest` and `GroupFeedTest`); a scheduled story stays author-only. ONE group loses something:
  an office administrator (`manage contacts`) who is NOT on the class roster could edit a sent story before and
  cannot now (see "The write hole" below); she can still delete one. For everyone else it is unchanged. The marker
  makes an edit visible where it used to be silent. Narrowing edits to the author alone is a deliberate server change
  in `update()` that reverses two tests, and nobody asked for it. (Corrected 2026-10-01: this bullet first read
  "Who: unchanged", which the write-hole bullet contradicted.)
- **`group_posts.edited_at`** (nullable datetime, migration `2026_10_08_100000`, no backfill: every existing story
  reads as never edited, which is true). Stamped by `update()` only when a story that is already out has its title or
  body actually changed, or a file added. Not stamped for a scheduled story's edits, a move or "Send now", a save that
  changes nothing, or a retention-only change. The decision is made on the row under its lock, so a story the sweep
  announced a moment ago counts as out. `updated_at` cannot serve: the sweep's claim and a retention change bump it.
- **The write hole, closed.** `update()` never asked the feed read gate and returned the story with media allowed
  hard-coded. An office administrator off the class roster gets 403 on `GET /posts`, yet could `PUT` a sent story and
  read its words and attachment list back; the same call let her change words she was not allowed to read. A story
  that is out is now edited under the feed read gate (403 otherwise, before anything is written) and the response is
  media-gated like the feed. A story that is not out keeps its author-only rule with no roster standing (it is the
  author's own text). `GroupFeedTest::a_post_can_be_edited` now seats its administrator on the roster, as any reader
  of the feed is. `destroy()` has the same shape (it answers no content, only an id) and is left as it was.
- **`can_edit`** (staff payload) is true exactly when this caller's PUT would be allowed for a story that is out: the
  realm's write gate (teacher realm: leads the class; admin realm: `manage contacts`), the tenant check, then the feed
  read gate. The SPA draws Edit from it and from nothing else. False for a story that is not out; absent from the
  office's metadata-only view of a scheduled story. The family payload gets `edited_at` only (no `can_edit`, no
  editor).
- **No re-notification.** An edit dispatches nothing (no email, no push, no job) and does not touch read receipts or
  reactions: a parent who read the first version stays "seen". "Seen" means opened at some time, not opened this
  version.
- **Translations.** The server cache is keyed by a hash of the text, so an edited story is a miss and is translated
  again with nothing to invalidate. The portal's in-page map is keyed by id, so the key now includes `edited_at`
  (`postTranslationKey`) and a re-fetched edited story translates again without a reload.
- **Attachments are not edited in this slice.** The form changes the title and the text. A file added through the API
  still counts as an edit and is held to the media limits; removing one has no route.
- **The deploy window.** For the seconds between the new code and the migration the row has no `edited_at`: reads are
  null-safe, and an edit that would have to stamp it is refused with a 503 and a plain sentence before anything is
  written, so the text stays in the teacher's form. Ship it after school hours with the migration (up, down, up on
  staging MySQL first).
- **The "Edited" strings** (`story_edited`, six tables) are machine-drafted in the four newest languages and Arabic,
  and wait for the human review the rest of the portal's strings are waiting for.
Alternatives: author-only editing of a sent story (a deliberate narrowing, see above); an `edited_by_user_id` shown to
staff (answers "who changed my words", more than was asked); re-notifying families on an edit (rejected: a typo fix
should not email a class); deriving "edited" from `updated_at` (wrong, see above).

## 2026-10-01 — Editing a message after it is sent (W7-2b, teacher feedback; branch feat/school-w7-edit-messages)
Decision: the AUTHOR of a conversation message, a staff account, may change its words after sending, in the office
and on the teacher screen. Nobody else may, and families do not edit in this slice. Every real edit is audited.
- **Who.** `PUT .../groups/{group_id}/threads/{thread_id}/messages/{message_id}` in BOTH staff realms (admin under
  `manage contacts`, teacher under `teacher.leads`), one controller method (`GroupThreadsController::updateMessage`).
  The thread must still be readable by the caller (`authorizeThread`, so a teacher taken off the class or an office
  admin who may not read a participant thread is refused) and `author_user_id` must equal the signed-in user. That one
  comparison refuses a parent's message, a colleague's, and one whose author account was deleted (the column is
  nulled). The office and a SuperAdmin get no exemption: an edit puts words in someone's mouth, so only the speaker may.
- **What.** The body only, at the ceiling sending has (`groups.messaging.max_message_length`). Photos, videos, subject,
  scope and authorship are refused by name (422), not ignored, so no client believes it changed them. An empty body
  is allowed only for a message that carries an attachment. An unchanged body (compared trimmed) is a 200 that writes
  and stamps nothing.
- **When.** No time window. A closed conversation refuses (422, the sentence reply and reactions use). A scheduled
  conversation that has not gone out is not a `group_messages` row, so the route is a 404 for it; its own rules stand.
- **Trace.** `GroupMessage` has always promised there is no per-message eraser that quietly rewrites what a parent was
  told. So a real edit writes the OLD text to a new append-only table `group_message_edits` (`GroupMessageEdit`,
  tenant-scoped, `previous_body`, `editor_user_id`, `created_at`) and stamps `group_messages.edited_at`, in one
  transaction under a row lock on the message. The new text is not stored twice: the earlier texts plus the current
  body are every version. The office reads them at `GET .../messages/{message_id}/edits` (admin realm only, `manage
  contacts` plus the same thread read gate). No teacher or family route, and no message payload carries an earlier text.
- **Quiet.** An edit sends no email or push (the nudge for the original went out already, and a nudge is content-free),
  does not touch the thread's `updated_at` (`GroupMessage::$touches` would float an old conversation to the top, so
  the save runs inside `withoutTouching`), moves nobody's read marker, and leaves reactions and "seen by" as they
  were: they now refer to the earlier wording, and the "Edited" label tells the reader so.
- **Payloads.** Staff and family message payloads gain `edited_at` (null when never edited); the staff payload gains
  `can_edit` (author, conversation open). Family sees the fact only, never who edited or what it said before. The
  family SPA's translation cache key now includes `edited_at`, so a parent who has Translate on is not shown the
  translation of the old wording.
- **Erasure.** Edit rows go with their message: the DB cascade (thread purge, deleted group or organisation) and an
  explicit query delete in `GroupMessage`'s `deleting` hook for a message deleted through the model. Deleting a staff
  account or erasing a parent only nulls the author on the message, so those leave the history with its attribution
  softened. `previous_body` is in `config/staging_scrub.php`.
- **Deploy.** Both schema changes are additive and nullable (migrations `2026_10_08_110000`, `110001`), with no
  backfill. The new PHP runs for a few seconds before `migrate`: reads of `edited_at` are null on a missing column, and
  an edit in that window answers 503 with a plain sentence (the author's text stays in the box) instead of a 500.
Alternatives: let the office edit anyone's message (rejected: rewrites a colleague's words); a time window (the label
and the audit answer the same worry without taking away fixing an old typo); clear reactions on an edit (a one-line
follow-up if wanted); let families edit their own replies (needs a contact-side audit column, an erasure entry and a
decision on whether the teacher is told).

## 2026-10-01 — W7 integration folds: the video review's second pass, and an edit must name the words (branch integrate/w7-2-edits)
- **The office story box always has limits.** An administrator who is not on the class roster may post a story and is
  refused the feed read, so the feed's `meta` never reached that screen and no limit applied: four large clips would
  upload and end in a 413. The box now uses the shipped defaults (`DEFAULT_POST_LIMITS`, mirroring config/groups.php)
  until the server's own limits arrive, and re-plans what is already chosen when they do. The server remains the truth.
- **A generic file type says nothing.** `application/octet-stream` on a `.mov` is treated as "unknown", so the
  extension decides, and the clip goes in the videos bag.
- **The 413 sentence names what is being sent** ("…the rest in another message" on conversation screens), as the
  server's own refusals do. The test calls the function instead of matching its text, and pins both office uploads.
- **The office hint explains the videos even when photos have no limit** (an images limit of 0).
- **Editing a message: `body` must be present.** A PUT that did not name the words was read as "empty them", which
  blanked a photo message's caption. It is now a 422 and nothing is written.
- **Known, accepted** (from the slice reviews): an office login that is not on a class roster can no longer edit that
  class's sent stories (it could read back words it may not open; it can still delete); the "Earlier versions" control
  shows for an office viewer whose role cannot read them (the refusal is shown in place); a story announced by the
  sweep in the instant between an edit's check and its lock is stamped Edited without the feed gate having run (the
  editor is its own author).

## 2026-10-01 — Unread badge, review fold: the list is what a regained window refreshes, and an open takes its number from the server
- **Focus refreshes the LIST once it exists.** The refresh wrote only the class number, so with the Messages tab open
  the pill said "1 new" while no conversation was marked new. It now reloads the conversation list quietly (no
  spinner, the scheduled list left alone, a failure keeps the rows): that load touches no draft, no chosen photo and
  not the open conversation, so the reason for never reloading the CLASS does not apply to it. Before the list has
  been loaded, only the number is asked for, as before.
- **Opening a conversation takes the number from a fresh list.** The screens subtracted the row's count as it was when
  the list was loaded. If more arrived meanwhile, the server cleared all of it and the tab stayed too high. Both staff
  screens now re-read the list after an open and use `meta.unread_total`; the old subtraction is the fallback when
  that re-read fails.
- **A reply never writes a bookmark over messages the reader has not opened, whatever their age.**
  `hasUnseenFromOthers` applied the unread floor, so a reply to a conversation never opened, whose earlier messages
  were older than the floor, advanced the bookmark past them. A staff bookmark is the read receipt families see; the
  floor belongs to the COUNT only.
- **The conversation list's `per_page` is clamped** (1 to 100, default 15): `per_page=0` was a division by zero.
- **Next slice, not here:** a later page failing after an earlier one was bookmarked; "Load more" for a class with more
  than fifteen conversations on the teacher screen; the parent reply path advancing the contact's bookmark
  unconditionally; a MySQL-group test of the count query.


## 2026-10-01 — Editing after sending, the point's review fold: a line ending is not a change of words, and a save closes only its own form
- **One line ending, "\n".** A browser sends the same textarea two ways: a multipart form (how a story or a message
  with a photo is created) carries line breaks as "\r\n", and the edit of that text arrives with "\n". Stored as sent,
  an untouched save of any multi-line story stamped it "Edited", and an untouched multi-line message got a history
  row holding the text it still has. `App\Support\LineEndings` normalises at the request boundary (story create and
  edit, message, new conversation, scheduled message create and edit, message edit), and BOTH sides of every "did the
  words change" comparison, on the server and in the two SPA helpers, because rows written before this still hold
  "\r\n". Those rows are not rewritten: no data migration, and the first real edit of one stores "\n".
- **One story save at a time, and a save closes only its own form.** With a save in flight on story A, Edit could be
  opened on story B; A's answer then closed B's form and dropped its draft. Edit is now disabled while a save is in
  flight (and the composable refuses), and a finished save closes the form only when it is still the one open
  (`editingAfterSave`).
- **The "Earlier versions" button is named by its own text.** Its fixed aria-label said "Show earlier versions" while
  the list was open; `aria-expanded` already carries the state.
- **Next slice, from the same review, not here:** two people editing one sent story is last-write-wins (send
  `updated_at`, answer 409); a scheduled story announced between an edit's pre-check and its lock during the deploy
  window answers 500 where 503 was meant; keyboard focus is dropped on Edit, Save and Cancel; Esc discards a message
  draft unasked; the edit TIME is only a title tooltip (no touch or screen-reader path); `can_edit` on a message does
  not ask the admin realm's write permission; the closed-conversation check sits outside the lock.

## 2026-10-01 — An address several contacts hold is nobody's to sign in with (member code door)

- **The bug.** It was found by the social sign-in design's read of this code, and confirmed by reading and by a test.
  - `MemberSignupService::resolveContact()` answered null both for an address nobody holds and for one SEVERAL contacts hold.
  - Two contacts of one organisation with the same office `email` and no `login_email` (two members of a family on one
    address is the ordinary case) therefore looked like a new member to the code door. With a name supplied, `consume()`
    created a THIRD contact (`email` = `login_email` = the address, `signup_source` `app`) and signed the person in to it,
    with none of their gifts or orders.
  - From then on that contact's `login_email` won every sign-in, so merging the two originals no longer brought the history
    back; the office had to merge three.
  - Without a name the door answered the 422 "tell us your name", which says nobody here has the address.
  - The password door already refused the same ambiguity, and the controller's docblock already promised the one 410 for
    "an address matching two contacts".
- **The fix.**
  - `holdersOf()` (public) returns an `AddressHolders`: nobody, one contact, or several. It keeps the same precedence
    (`login_email` first, then the office `email` only on contacts with no login address) and the same exact-match filter
    before counting. `resolveContact()` is kept on top of it for the callers that only link.
  - `consume()` refuses several before its create branch: the one 410, nothing created, nothing adopted, no password, the code
    spent.
  - A warning names the organisation and the contacts' ids, never the address, so the office can merge them.
  - A contact that already signs in with the address is not made ambiguous by office duplicates.
- **What it means for the office.** A member whose address sits on two contacts cannot sign in until those are merged. That is
  the right failure: the alternative was a quiet empty account. Any import that pre-creates contacts (the MEC Wix move) must
  count and resolve duplicate addresses BEFORE members are invited.
- **Proof.** `MemberSignInSharedAddressTest`, 7 tests: no third contact with a name; the same 410 without one; the code spent
  and the log naming ids only; signing in on the surviving contact after a merge; the `login_email` holder unaffected by office
  duplicates; nobody still makes a new member; the three answers and tenancy of `holdersOf()`. Four of them fail on the old code.

## 2026-10-02 — Lunch staff can save an order edit: the board's store no longer refuses them (owner: "it is not just a masjid admin power")
- **What was wrong.** A lunch volunteer saw "Edit items", changed the plates, pressed Save and was told "Only a masjid
  administrator can change what is on an order." No request was sent: `jummahLunchStore.updateOrderItems` threw for a
  `LunchStaff` before calling the server. The guard dates from the editor's first version (3d19e285), when the route
  was admin-only. The 2026-09-24 fix (018d82e6) added the route to `routes/lunch.php` and showed the button, and left
  this guard in place, so volunteers have had a button that could not save since then. Production's nginx access log
  (read on the box 2026-10-02 14:38 UTC, today's and yesterday's files) agrees: in the lunch realm, 98 reads of the
  orders list, 5 orders taken and 1 payment link, and no PATCH at all.
- **The fix.** The guard is removed. Who may edit an order is the server's decision (the `lunch` realm's own gate, the
  tenant binding, `staffMayEdit`), and the store sends the edit to the caller's own realm through `base()`.
- **Why the suite did not see it.** `LunchStaffRealmTest` proves the server serves a volunteer's edit; nothing ran the
  store. `tests/lunch-staff-realm-parity.test.ts` now reads `routes/lunch.php` and the store together: a call the lunch
  realm serves may not be refused in the store for a LunchStaff, and what the realm does not serve is a short decided
  list (menu delete, the staff logins). Red on the old store.
- **Comments said the opposite of the code** and are corrected; until this change they read "administrators only":
  admin.php's route comment, the store's docblocks, two in the board, and the audit model's actor note. One of them
  is the likely reason the guard survived the September fix.
- **The public order address on the board had no organisation number for a volunteer** ("/jummah-lunch/"): the board
  read it from masjidStore, which a lunch volunteer's shell never loads. It now comes from the store
  (`organisationId()`), beside `base()`. Found by the review of this fix; same root cause.
- **An independent review ran on the fix** (four readers, each serious finding re-checked by a skeptic): server path
  and screen path clean for a LunchStaff. What it showed and is NOT changed here: through this route a volunteer can
  edit a PAID order exactly as an administrator can (the total moves, the payment does not, and the difference is
  shown as owed or owed back for somebody to settle by hand), which is what the owner asked for today; a save that
  changes nothing still closes a customer's pending top-up page; a 422 from the request rules shows a generic message;
  the lunch-prefix server test covers an unpaid pickup order only. Next slice.
- **The earlier "live-verified" was the server, not the screen.** A fix to what a volunteer can do is verified by a
  volunteer login pressing the button.

## 2026-10-03 — A "TV Display" page: an organisation chooses six things about its lobby TV board (owner: "yes and do that")
Decision: the board's settings, which were constants in `TvConfigController`, become the organisation's own choice
where the TV app that is actually installed honours the choice. Six settings, one table, one admin page.

- **The six** (each honoured by the TV build released 2026-08-07, ios `a4a1c04`, and by iOS main): announcement
  slides on or off (`is_enabled`), the title at the top (`header_title`, 60), seconds per slide
  (`carousel_interval_seconds`, 3 to 120), the prayer times panel (`show_prayer_panel`), the donation code
  (`show_qr`), and the words under the code (`donate_caption`, 40). The board itself has no length limit and no
  ceiling on the interval; its floor of 3 is its own. The two lengths were checked near their limits on the
  released build in the tvOS simulator: a 58-character title wraps to two lines and fits, a 39-character caption
  fits on one line. Not checked on a physical television.
- **The page offers six slide speeds, not a free number: 5, 10, 20, 40, 80, 120 seconds.** The board redraws itself
  every 40 seconds (burn-in drift) and restarts the slide clock when it does, so only a speed that divides 40 or is a
  multiple of it keeps an even rhythm. Timed on the released build in the simulator: at 60 the slides changed after
  80, 40, 80 seconds (the average is right, the rhythm is not); at 120 they changed 120 seconds apart. The other
  four follow from the same rule and were not each timed; 10 is what every board has run until now. The server still accepts any whole number from 3 to
  120; a stored speed that is not one of the six stays selectable on the page and is marked "uneven on the screen".
- **Storage.** `masjid_tv_settings`, one optional row per organisation, every setting nullable with NO database
  default. Null means "not chosen" and resolves to what the board got before the table existed, so an organisation
  that never opens the page is served byte-identical tv-config (`TvConfigSnapshotTest` passes unedited). The three
  switches are stored only as `false` or null: a posted `true` is stored as null.
- **The two derived switches can hide, never force.** The prayer panel is `isMasjid() && not turned off`; the donation
  code is `a donation link exists && not turned off`. Forced on, a school's board would sit on "Loading prayer times",
  and a stored `true` would stop the code from following a donation link added later. Hiding the code is this switch
  and nothing else: the board falls back to the organisation's own link when no URL is sent.
- **One resolver, two callers.** `App\Support\TvBoard::resolve()` feeds the public payload and the page's "on the
  screen now", so the page cannot promise what the board does not get (pinned: `effective` equals the public body).
  It never trusts the row: a blank caption is the default caption, an interval out of range is the default interval,
  a blank title is null (an empty string would HIDE the header), over-long text is cut. The tvOS decoder is strict and
  fails silently, freezing a board on its old settings while the server logs a 200.
- **The admin API** is `GET` and `POST /api/admin/masjids/{id}/tv-display`, inside `admin` + `tenant` and behind the
  `tv_display` grant (below): a SuperAdmin always, and the organisation's own MasjidAdmin once the organisation holds
  the grant. No permission is minted (`Permission::count()` stays 8). An absent key is left alone; null puts a setting
  back to "not chosen". Nonsense for a switch is a 422, never read as off.
- **A save reaches the board within one poll** (about three minutes): the save flushes `MobileCache::TV_CONFIG`. The
  donation link save now flushes it too; it did not, so the board's code lagged a link edit by up to five minutes.
- **The deploy window.** New code is in place seconds before `migrate` runs, and boards poll. A missing table answers
  the defaults (nobody can have chosen anything yet) and that answer is NOT kept in the cache. Any other failure to
  read the settings is a 500: the board keeps its last good settings on a 500, where defaults would un-pause it.
- **NOT settings, and why.** The theme: `light` is white text on a white ground on the released build (the real light
  palette is on iOS main only, `48c8b99`). Which announcements show: `tv_flagged` does nothing on the board, and
  `manual` needs a picker the admin API cannot feed, ownership validation (`Announcement` is hand-scoped) and a rule
  for picks that expire. A donation URL override: the Donation Link page owns the URL, and on iOS main (`48c8b99`)
  the board draws that link's own title and picture beside the code (the released build draws the code and the
  caption only). The board's language: it follows the website language (S12, parked).
- **Only organisations with a TV see the page (owner, 2026-10-03, asked "every organisation, or only those with a
  TV?": "Only organisations with a TV").** A new grant, `tv_display` (group Communication, "TV display"),
  OFF for every organisation of every type until a SuperAdmin turns it on in the organisation's switches. It gates the
  two admin routes (`capability:tv_display`, 403 otherwise) and the page (`requiresCapability` on the route and the
  menu item, so it is hidden for an organisation without it). On for Burlington at the ship, by the owner's word: it
  is the one organisation with a TV app (the `MasjidTV` target is organisation 1). `enabled_platforms` was not usable
  as the signal: older organisations were backfilled to ios/android/web with no tvos, including the one with a TV.
- **The grant gates the page, never the board.** The public tv-config read does not ask for it (a switch never changes
  a board; the class-store condition of 2026-09-29; held by `TvDisplaySettingsTest`,
  `the_grant_gates_the_page_and_never_the_board`, since `ModuleSideDoorsTest` flips modules only and cannot see a
  grant): with the grant on or off an organisation
  that chose nothing is served the same recorded bytes, and an organisation that loses the grant keeps what it chose
  and only loses the page. A SuperAdmin is never gated by a grant, as everywhere else. The capabilities object every
  organisation payload carries gains the key (`tv_display: false`), so the four byte-pinned fixtures were re-recorded;
  the recordings changed by that key and nothing else (checked by stripping it and comparing).
- **The switch reads "TV display", and a refusal is said calmly (review of the grant, 2026-10-03).** The gate builds
  its sentence from the label ("{label} is not switched on for this organisation."), so a plural label read wrongly;
  every other grant label is singular. With the grant, a 403 became an ordinary answer for the page (a bookmark, or a
  tab left open after the switch is turned off: the router guard only knows once the organisation has loaded), so the
  page shows the server's sentence as a notice with no Retry, the way the shop pages do, and keeps red-with-Retry for
  a real fault. The description names all six settings and says the prayer times are a masjid's. Left as they are,
  each matching the other grants: Studio offers the switch to every new organisation unticked (ticking it when tvOS
  is chosen is one line, `studio_preselect_with`, and is the owner's call); a board paused before its grant is taken
  away stays paused and only a SuperAdmin can un-pause it, so look at the board before removing a grant; on a phone
  the sidebar drawer does not close after tapping a grant-gated entry (Shop and Class Store too).
- **The parked branch `feat/studio-tv-config-locale` will have ONE conflict** in `TvConfigController` when it is
  rebased: the nine lines from `return [` to `show_qr`. Take this side's lines with that side's `$payload = [`. The
  cause is the comment under `return [`, which said "No pause switch exists yet" and had to become true. Its trailing
  `website_locale` block and `return $payload;` apply untouched. This feature uses that name nowhere.
- **Found on the way, not changed here:** a broadcast sent to the "Lobby screen" channel alone never reaches the tvOS
  board, which reads `/announcements` and has never called `/signage`; the composer's hint says it does. Studio's
  preview of an existing organisation still draws the constants, so it can differ from that organisation's board.
- **One line means one line.** `TvBoard::NOT_ONE_LINE` is the single definition: every control character, the
  Unicode line and paragraph separators, and the bidirectional embedding, override and isolate controls. The request
  refuses them, the resolver removes them from a row it does not trust, and the page says so before the request. The
  joiners and direction marks Arabic, Persian and Urdu text uses (U+200C, U+200D, U+200E, U+200F) are allowed. Text
  that is not valid UTF-8 has its own rule and is a 422: a `/u` pattern fails on such text and `not_regex` reads a
  failed match as no match.
- **A save sends only what changed, and is locked while it is in flight.** The server leaves an absent key alone, so a
  tab left open since yesterday cannot put five settings back over a colleague's newer choices. Two first saves at
  the same moment both land (the loser of the insert is applied to the winner's row). A cache flush that fails after
  the row is stored is logged and the save is still answered as a success.
- **An answer belongs to the organisation it was asked for.** The store drops a load answered after a switch of
  organisation and refuses a save unless the settings on screen were loaded for the organisation it would be sent to.
- **The board has an empty right-hand panel when prayer times and the donation code are both off** and the slides are
  on (both the released build and main: `SignageView.rightRail` is always drawn). Seen in the simulator. The page says
  so, before the save and in "On the screen now". This is also what any organisation that is not a masjid and has no
  donation link gets today. Fixing the board is TV-app work.
- **Verified against the TV app itself, not only against PHP's idea of it.** (1) The app's own `TVConfig` type, copied
  unmodified from the released build (`a4a1c04`) and from iOS main (`b97c6b1`), decoded all nine response bodies
  captured from staging's QA sandbox (never saved, saved unchanged, every setting chosen, an Arabic title, markup in the
  caption, the limits, paused, back to not chosen); three deliberately wrong bodies (a boolean as 1, the interval as a
  string, a null caption) each failed to decode. (2) The released app, version 2.8 build 2608070933, was built for the
  tvOS simulator and shown seven of those bodies through its disk cache, launched with an organisation id that does not
  exist so that it drew only what it was given and wrote nothing anywhere: the title, the caption, the pause, the
  hidden prayer panel and the hidden code each appeared as chosen. What was NOT done: a run on a physical Apple TV, and
  a live change arriving over the network (the app's server address is fixed to production).

## 2026-10-03 — The composer stops offering "Lobby screen": no TV app reads that channel (owner: "Hide it, stop there")
Decision: the broadcast composer no longer offers the signage channel, and says on the announcements feed that the
lobby TV shows it too. Nothing else about the channel changes.

- **What was wrong.** The composer offered "Lobby screen: Puts it on the TV board while it is running." The tvOS app
  has never asked for `/signage`: its endpoint list has five entries (announcements, prayer settings, masjid,
  tv-config, events) on iOS main (`b97c6b1`) and in the released build (2.8, `a4a1c04`), and its slides are the
  announcements feed. `SignageChannel` answered `sent` with "Live on the signage board …; the board pulls it on its next
  fetch." An admin who ticked only that box saw "Sent" and nothing appeared on any screen.
- **Nobody had used it.** A read-only count on production at 2026-10-03 19:20 UTC (`1ccafe0b`): one broadcast in
  total, no signage delivery of any status, none scheduled. So there is nothing to migrate and nobody to tell.
- **Options put to the owner, and the choice.** (C) hide the channel and say what is true; (A1) make signage also
  create a feed post, which then shows in the phone apps and on the website as well; (A2) a TV-only flag on
  announcements with the server telling the TV from a phone by its User-Agent; (B) a TV app release that reads a board
  feed. The owner chose C alone. A2 was not recommended: `/announcements` is one address and one cache for the iPhone
  app, the Android app and the TV, the server reads no app key, and the User-Agent of the released build is inferred,
  not verified.
- **What changed.** `composerChannels()` is the composer's list, without signage. Its announcements hint adds "Your
  lobby TV shows it too." for an organisation holding the `tv_display` grant, the sign the TV Display page already goes
  by, so an organisation with no screen is not told about one. The link hint, the empty list's sentence and the
  Broadcasts switch description no longer name the screen. `SignageChannel`'s note now reads "Stored for the lobby
  screen until …, but the TV app does not read this channel, so it is not on the screen. Post it to the announcements
  feed to show it there."
- **What did not change, on purpose.** The API still accepts `signage`, and its delivery is still `sent`: a browser
  holding the older page can tick the box until it reloads, and the note is what tells that admin the truth (it is the
  chip's tooltip, so it is easy to miss; with no use in seven weeks the exposure is small). Turning the delivery into
  `skipped` would empty `/signage` (`scopeLiveOnSignage` needs `sent`) and rewrite the tests that pin it, which is the
  server half option B would reuse. `/signage`, the scope, the list's "Screen" label for an old delivery and
  `BroadcastChannel::SIGNAGE` are untouched.
- **If lobby-only notices are wanted later (option B).** It is TV-app work plus an API change, not a tick box:
  `/signage` answers broadcasts OR the announcements, so one notice would replace every announcement on the board;
  broadcasts have no delete and the end date is optional, so a notice could not be taken down; a broadcast sent to both
  channels would show twice. And the tick box may come back only for an organisation whose TV runs the new build.
- **Verified.** The mounted composer draws four tick boxes and no lobby screen, names the TV only with the grant,
  still hides a switched-off module's channel, and names the ticked channels in its question
  (`broadcast-composer-screen.test.ts`); the new note test fails on the old sentence. Not done here: the page in a
  browser against a server (the staging walk at ship time), and a physical Apple TV.

## 2026-10-04 — Form Responses search reads the answers from their own column, never from the JSON as text (review fold on efb2416c; branch fix/form-responses-search-answers)
- **What was wrong.** efb2416c made the search look inside the answers by matching the whole JSON document as text
  (`LOWER(CAST(data AS CHAR)) LIKE`). That text also holds every question's KEY and whatever is stored beside the
  answers, so an ordinary first name found every row: "Reem" is in `waiverAgreement`, "Sara" in `parent1SpeaksArabic`,
  "Mai" in `registrantEmail`, and "Ada" in the SHA-256 the website import keeps per document. A parent who used to be
  found exactly was buried in the whole list, at the door too, and the export, roster, cash totals and Insights
  widened with it. On SQLite the same text is `\uXXXX`-escaped, so the suite could not find an Arabic or accented
  name at all, nor a value with a slash. A term that was not valid UTF-8 dropped the filter and returned every row.
- **The fix.** `form_responses.answers_text` (MEDIUMTEXT, nullable, no index): the WORDS of the answer values,
  lower-cased in PHP, one space before each, built by one pure function, `App\Support\FormAnswersText::build()`.
  The search reads that column with `LIKE '% word%' ESCAPE '!'` on words that the same function made
  (`FormAnswersText::words()`), so the predicate is the same on MySQL and SQLite.
- **Lower case is the SIMPLE mapping** (`MB_CASE_LOWER_SIMPLE`, one character for one character, as
  `LessonPlan::subjectKeyFor()` uses), not `mb_strtolower` as first written. The full mapping turns a Turkish
  capital İ into "i" plus a combining dot, so a child entered as "İbrahim" was not found by "ibrahim", "Ibrahim" or
  "İBRAHİM", only by the exact spelling; and it lower-cases a Greek capital sigma differently at the end of a word
  than inside one, so a word alone and the same letters inside the text could differ.
- **What is in the text.** Only questions the form declares (plain sections, and each row of a repeating one): a
  text, number, date, email or phone answer; a choose-one answer and the label of its option; each value of a
  choose-any answer. Never a `file` question (a file name), a `checkbox` (a tick), a key the form does not declare,
  a value that is one run of 32 or more hex digits, or a value that is one Stripe object id.
- **The digest rule is there because "declared only" was not enough.** The school-website form DECLARES its
  `…Ref` fields as text questions (database/forms/alrazi-website-registration.json; `AlRaziSubmissionMapperTest`
  pins that the mapper emits declared keys only), so on the very form this was written for the digests would
  still have been searched. A digest is left out wherever it is stored.
- **A payment id is left out for the same reason.** The same form declares `websiteStripePaymentId` as a text
  question and the import fills it with the payment's id: `pi_` and 24 random letters and digits, which now and
  then contain "ali" or "mai", so a short name found an unrelated paid enrolment at random (a reviewer's estimate,
  not measured on real ids: about 0.07% per paid row per three-letter word). The rule is narrow on purpose: a
  whole value that is a payment object's prefix (`pi`, `cs`, `ch`, `py`, `in`, `seti`), optionally `live_` or
  `test_`, then ONE run of 14 or more letters and digits with a digit in it, so a chosen option such as
  `in_person_attendance` stays an answer. Decided: the office does not find a family by pasting a payment id
  into this box. If it ever should, drop `FormAnswersText::PROVIDER_ID` and rebuild.
- **Who writes it.** The model, in a `saving` hook, whenever `data` changes or the column is still NULL, so the
  public submit, the basket, a registration and the website import cannot forget it. The migration fills existing
  rows (chunks by primary key, only rows still NULL, re-runnable after an interruption); the hadiths
  normalised-column migration is the precedent. `staging:scrub` rewrites `data` without the model, so it NULLS the
  column (`config/staging_scrub.php`), or staging would keep every real name, and then runs the same fill
  (`StagingScrub::rebuildAnswersText()`), because nothing else would write it back: the policy's comment said
  the model or the command would, and no step of `deploy/staging/DATA-REFRESH.md` ran either. What the fill
  writes there is the scrub's placeholder once per answer. A row copied from production cannot be found on staging by a child's
  name whatever is done, because the name is gone from `data`; walk the search on a row submitted on staging.
  `forms:rebuild-answers-text [--form=] [--all]` is the same fill by hand.
- **The search.** Every word, in any order, in the respondent's name, email or phone, or in the answers. A term
  with no letter in it ("7", "#123", "555 0100") is a registration number or a phone and is not looked for in the
  answers; beside a word with a letter it is ("Layla 5", "Rahmani 2019"). The registration-number alternative is
  unchanged. A term that is not valid UTF-8, and a term that leaves no word, match nothing (the first was scrubbed
  to `?` before the word-start change; scrubbed now, the `?` would be dropped as punctuation and "Maryam" plus a
  cut character would find Maryam under a filter nobody typed). A typed `%` or `_` is now literal in the three
  identity columns as well; before, either one alone returned every row.
- **The deploy window.** `bin/deploy` makes the code live before it migrates. Until the column exists the model
  does not name it and the search reads the identity columns and the number only, as on main
  (`FormAnswersText::columnExists()`, memoised as `CartTables` is). Without this a family submitting in those
  seconds would have lost the registration to "unknown column".
- **Known limits, decided.** The text follows the form's questions as they were when the row was last saved: after
  a form's schema changes, run `forms:rebuild-answers-text --form=<id> --all`. The labels of a choose-any answer,
  and of options that come from the school calendar, are not in the text (their values are, and the values are
  what the response screen shows). On MySQL the column's collation also ignores accents, so "emile" finds "Émile"
  there and not on SQLite: more is found, never less.
- **In the answers a word is matched at the START of a word, never inside one. DECIDED by the lead 2026-10-04**
  (this replaces the note that left it open for the owner; the first version of this change matched any part of an
  answer). Matched anywhere, a short name returned the whole form at a registration desk: on the school-website
  enrolment form "mai" is in a preferred-communication answer of `email`, "ali" in a home language of "Somali",
  "ian" in `guardian`, "tim" in `full_time`. Now "kar" finds "Kareem", and "rahman" finds "Abdul-Rahman" and
  "al-Rahman" but NOT "Abdulrahman": a name written as one word is found from its first letters only, and that
  cost is the decision. The same in Arabic, where the article is written joined: "الرحماني" is found by "الرح",
  not by "رحماني".
  - **How, portably.** No word-boundary operator and no marker character: the two engines do not share one, and
    how MySQL's collation weighs an unusual character is not something this suite can see. The text itself is
    built as words. `FormAnswersText::normalise()` lower-cases, turns every run of characters that is not a
    letter, a combining mark or a number character (`[^\p{L}\p{M}\p{N}]+`) into ONE space, and `build()` puts
    one space in front, so every word, the first too, follows a space: `" samira al nasser samira example test
    2019 04 02"`. A letter keeps its marks (an accent typed as its own character, Arabic vowel signs).
  - **What is typed goes through the same function** (`FormAnswersText::words()`), so "al-rahman", "o'neil"
    (straight or curled) and a whole email address fall into the pieces the stored answer fell into, and EVERY
    piece must begin a word: `answers_text LIKE '% piece%'` per piece. The pieces are not required to be next
    to each other or in order, which is the rule the words of the term already had. A typed word with nothing
    in it for the answers ("&", "-") is left to the identity columns. At most 8 pieces of one typed word are
    looked for, the cap the words already had.
  - **The respondent's name, email and phone are still matched in ANY part**, as the lead decided and as they
    always were ("our@exam", "0199"). So this change does not narrow them: "mai" still finds every row whose
    `respondent_email` is a gmail address, and "ali" a respondent called "Dalia" or "Khalid". If the desk
    reports that, the same word rule on those three columns is the next step; it is not done here.
  - **A number beside a name begins a word too**: "Layla 2019" and "Layla 201" find a date of birth of
    `2019-04-02` (the words 2019, 04, 02); "Layla 19" and "Layla 4" do not.
  - **The column has never been migrated anywhere**, so the builder changed in place and the migration's fill is
    the same idempotent one (rows still NULL, chunks by primary key). A database that had run the first version
    would need `forms:rebuild-answers-text --all`; none has.
  - Pinned by `FormResponseSearchTest::a_word_is_matched_at_the_start_of_a_word_in_the_answers_and_never_inside_one`
    (the email / Somali / guardian cases, a hyphenated and an apostrophe name, Arabic, an email address typed
    whole, a number beside a name), `...::the_first_word_of_the_answers_is_found_and_the_name_email_and_phone_are_matched_in_any_part`,
    and `FormAnswersTextTest::a_word_is_a_run_of_letters_marks_and_numbers_and_everything_else_is_one_space`.
    With the pattern turned back into `%word%`, six tests of those two files fail, these three among them (run
    once, then restored).
- **The screen says only what is true**: the placeholder, the help sentence for screen readers and the "At the
  door" notice name "a word in the answers"; the help sentence states the number rule, that in the answers a word
  is matched from the start of a word, and that what is not searched is the name of an uploaded file and a single yes/no tick
  box (the ticked options of a choose-any question ARE searched, so "ticked boxes" was wrong).
- **Tests.** `FormResponseSearchTest` (SQLite; it also runs the migration's own `up()` and `down()` over rows
  stored before it, since the backfill in `up()` is the only thing that fills production's existing rows),
  `FormAnswersTextTest` (the pure function), `StagingScrubTest` (the text after a scrub),
  `tests/Mysql/FormResponseSearchMysqlTest.php` (the column type, Arabic, accented and Turkish names, the word
  start, the escape in the respondent's name, the backfill reading MySQL's JSON). The MySQL file was NOT run
  where it was written (no MySQL server). After the word-start change every body but the column-type one was run
  once on SQLite through a temporary copy, to check the fixtures: 7 passed. That proves the fixtures, not MySQL.
  See ASSUMPTIONS.md F-1 to F-7.

## 2026-10-04 — A student can be moved to another class, and a roster row never changes class (owner: "should have a way to move students up to a different class"; branch feat/roster-s1-move)

- **One path: left here, started there.** The old place gets a leaving day and keeps everything recorded on it;
  a new place opens in the new class, or the place the student held there before opens again. The design's
  "simple change of class" for a row holding nothing was DROPPED after the review by the session that owns the
  school features: every office roster action and every record writer loads a row through its class and then
  writes by primary key with no lock, so a row that changed class would be written to by requests that loaded it
  under the old one. The price is one extra "moved" row in the old class, which holds nothing and which Remove takes.
- **Owner's defaults, shipped as written and said on screen**: consent does not follow a move (it is asked
  again); marks, register, report cards, ḥifẓ and letter progress stay with the class they were earned in; the old
  class's teachers keep reading what they recorded. A whole class is moved one student at a time; a "move
  several" control waits for the owner's answer on consent (25 students with two guardians each is 50 consents).
- **The guardian rule is new.** A move is refused while the class being entered holds a confirmed guardian entry
  for the student whose adult is not a confirmed, current guardian in the class being left. Narrowing or
  un-confirming the entry instead was rejected: a closed confirmed entry still reads the child's records, and an
  unconfirmed one is one tap from a grant. The refusal names every such guardian and can be cleared from the
  screen it appears on.
- **A confirmation now has two doors**: `confirmedByStaff` creates one, `carriedFrom` copies one for the same
  (adult, child) pair into another class. One caller each side of the copy, both counted in a test. Consent is
  never copied.
- **One list of what a roster row holds** (`AcademicRecordsHeld::KEYS`, eleven keys). The two roster deleters
  refuse on the eight kinds a delete would destroy. That adds Arabic daily notes, which a removal used to delete
  without a word, and soft-deleted awards and ḥifẓ entries, which the counts used to miss. It does NOT add
  conversations, scheduled messages or addressed files: the written rules say those survive or lapse with the row.
- **"Put back" on a moved row is guarded on the screen, not on the server.** The undo verb stays ungated because
  its own rule is that an undo is never refused. The dialog reads the roster again and offers nothing while it
  would bring back an adult who is no longer a confirmed guardian where the student is now.
- **The move day is owed to one register**, decided from the old class's last mark on or after the chosen day; a
  return keeps its first joining day unless the class took a register meanwhile.
- **The class-store balance stays on the old row** and the office is shown no figure, only that Bucks stay
  (W6-C1 is still open). **"Add to roster" now takes the student's contact lock** before its duplicate check.
- **Not built, and whose it is**: registration's adder taking the same lock (the owning session hands over one
  commit); a contact merge after a move (it re-opens closed guardian entries as pending claims); keeping a left
  student with marks on the teacher's report-card list; the family portal's notices on the old class.
- **Tests.** `RosterMoveTest`, `RosterMoveRosterTest`, `roster-move.test.ts`, `roster-move-mounted.test.ts` (run).
  `tests/Mysql/RosterMoveMysqlTest.php` and `tests/MysqlLocks/RosterMoveLocksTest.php` were NOT run where they
  were written (no MySQL server); they run in CI's MySQL job. See ASSUMPTIONS.md M-1 to M-8 and
  `.claude/rules/groups.md`, "Moving a student to another class".

## 2026-10-04 — Ages on class rosters: an optional, encrypted date of birth on the contact, and a whole-number age on two payloads (owner: "We need to show ages on the Classroom Student Rosters please"; Q1 yes)

Decision: a student's date of birth is stored, optional, as ciphertext in
`contacts.date_of_birth` (TEXT), hidden, not fillable, with one writer
(`Contact::recordDateOfBirth`) and one reader (`Contact::dateOfBirthOrNull`). The
age is never stored: `App\Support\StudentAge` works out whole years on read, on
the school's clock, and `age: int|null` is on exactly two payloads, the office
roster list and the teacher's class payload, for students in classes only (role
`member`, group kind `class`). The date itself appears only on the office's
birth-date routes and in the contacts file of the school records export, both
behind `manage contacts`. A teacher who taps a student on the Roster tab gets a
sheet with the avatar, name, grade and age and nothing about a parent (owner, Q3:
parents' names and phone numbers are for the office only).

Alternatives: a stored age (wrong within a year); an `age` accessor on Contact
(it would publish an age wherever a contact is serialised); a plain date column
(reaches logs on a query error and every whole-model answer); "N at enrolment"
from form answers (no stored link from a roster child to an answer, so it would
be name matching on minors' data); the date on the Member Directory edit form
(filled from a list row that cannot carry a hidden column, so an untouched field
would wipe it); age inside the teacher's `student()` (thirty decrypts on every
gradebook, register and report-card read, and six pinned payloads changed).

Rationale and what the review of the design changed:
- The CLEAR is keyed by contact and never refused. Reading and setting go by
  roster row, which is how the server knows the child is a student in a class;
  but the date is on the contact and outlives that row (Remove, an import undo,
  an archived class, a changed kind, a merge), and a parent who asks for it to be
  deleted must not meet an office with no button for it.
- One reader that survives a value it cannot decrypt (null plus one ERROR line
  with the id and no value), used by the age, the GET, the merge and the streamed
  export. The export had sent its 200 before the first row, so a throw there
  would have left a file that stops at one child and looks complete.
- Who set, changed or removed a date is logged at `warning` (production keeps
  nothing below it), with ids and the verb only.
- "Not in the future" is judged on the school's today, the day the age uses.
- `bin/deploy` serves new code before it migrates, so every read of the column
  is behind `StudentAge::columnExists()`; between checkout and migrate the
  rosters show no ages instead of failing.
- The teacher's sheet is the screen's own modal (a bottom sheet at phone width),
  not a new offcanvas pattern, and it holds the avatar picker that used to be a
  button on the row. It is drawn from a four-key model so nothing a later
  payload carries can appear in it; the dormant `guardianNames` hook is deleted.

Measured (2026-10-04, the dev Mac, PHP 8.3, SQLite in memory, in-process
requests, median of 40 after 2 warm-ups, run twice): a class of thirty students.
Office roster list 7.4 ms with no dates, 8.0 to 8.2 ms with thirty; teacher class
payload 4.8 ms with none, 5.2 to 5.3 ms with thirty. So thirty decrypts cost
about 0.5 to 0.7 ms. What this did NOT isolate: both figures already include the
three fixed reads the age adds whether or not a date is held (does the column
exist; the school's calendar, two statements; the students' dates, one
statement), and SQLite in memory has no network. On production's managed MySQL
each is a round trip; their cost there is Unknown, needs measuring on staging.
Rules: `.claude/rules/groups.md`, "A student's date of birth, and the age on a
roster". Tests: `StudentBirthDateTest`, `StudentBirthDateLeakTest`,
`tests/Mysql/ContactDateOfBirthMysqlTest` (the column type; CI only),
`resources/vue-app/tests/student-age.test.ts`.

## 2026-10-04 — The three roster features on one screen: what the integration decided (branch feat/roster-features)

Moving a student, Student details and ages were built on three branches and meet on
the office roster (`GroupRosterTab.vue`) and its panel (`StudentDetailsPanel.vue`).
Joining them took a few decisions no single slice could make:

- **One question, one answer: "is this a class".** The Move button (row and panel),
  the Age column, the "dates missing" line, "Age {n}" and the date-of-birth form all
  read the server's `meta.teaches_students`. A group that is not a class shows none
  of them, and an answer with no meta (the minutes between checkout and migrate, or
  an older server) shows none either, rather than guessing.
- **The panel stays request-free.** Everything the other two features add is put
  into its five named slots from the roster tab. The date form asks the server
  itself and draws nothing for a login that may not see the date.
- **A saved date patches the row, it does not re-read the roster.** The PUT answers
  the new age; re-reading would cost the office its place for one number.
- **Remove offers the clear.** When the server says a removed student's date of
  birth is still on their record, the message waits and offers "Remove the date of
  birth" beside OK. Considered and rejected: clearing it automatically (the student
  may still be in another class, where the age would vanish), and saying nothing
  (after the last row is gone no screen reaches the date).
- **The panel hears keys after focus falls out of it.** Mounting the date form in
  the panel brought back the fault the Grade field had: the form turns its Save
  button off while saving, the browser drops focus to the page, Escape stops
  closing the dialog. Seen in a browser, fixed in the panel (a document listener
  while it is open) rather than in each slotted part.
- **"Joined" is read as the day it is.** `joined_at` is a date column; the roster
  drew it as an instant, so readers west of UTC saw the day before. That was already
  so on main; a move puts "Moved from {Class} 4 Oct" beside it, so it is fixed here
  on the row and in the panel.
- **The roster row's exact key set** (`OfficeStudentDetailsPayloadTest`) now lists
  the four `moved_*` columns, `moved_to`, `moved_from`, `moved_to_state` and `age`,
  each classified as something the office reads on that list. The date itself is on
  no list: `StudentBirthDateLeakTest` now also walks the move preview, the move and
  both rosters after it.
- **Registration's adder** takes the contact lock as of the merged commit 9859b8cf;
  the residual is narrowed in ASSUMPTIONS.md M-8 and in the rules.

Left as built, and said to the lead: the badge "Moved to {Class} {date}" follows the
design's label exactly, and reads awkwardly for a class named with a number ("Moved
to Grade 4 4 Oct 2026"); the student "Left" badge prints its day as "28 Sep 2026"
(the move's own format) while a guardian's prints "Sep 28, 2026". Neither was
changed without the designer.

Verified: the SPA suite, the PHP suites of the three slices and their neighbours
(SQLite), a build, and a walk in a browser on a throwaway local instance with
invented people (desktop and 375px). NOT run here: `tests/Mysql`, `tests/MysqlLocks`
(CI only), and nothing on staging or production.

## 2026-10-04 — The roster features, review fold: Remove offers the date clear only when no class is left, and the dialogs take the keyboard (branch feat/roster-features)

Decisions the findings forced, each with the alternative that was not taken:

- **Remove offers to clear a date of birth only when no class lists the student any
  more.** "Lists" is any `member` row of theirs in a class that still exists, current
  or marked as left: that roster shows the age and holds the date form, so the date is
  one tap away there. In that case the server's sentence names the class and sends no
  `data.birth_date`, and the screen shows the sentence with a plain OK. This is the
  ordinary case after a move, whose own answer invites the office to remove the empty
  old entry; the offer there wiped the age of a current student. Not taken: keeping the
  key and adding a flag (a screen that ignored the flag would offer the clear again),
  and counting only current rows (a row marked as left still shows the age).
- **A failed clear is offered again** ("Try again" / "Leave it"), because it is offered
  only when no roster is left to remove the date from. NOT built: a date-of-birth
  control in the Member Directory for a person who is in no class. The clear route
  works for any contact id, but once the offer is dismissed no screen sends it. Said in
  the rules as a known gap; it needs a decision on whether the directory may say that a
  date is held.
- **A student whose contact was deleted in the Member Directory can still be moved**,
  as the preview already said. The move's contact lock is a mutex and is now taken on
  the row whether or not it is deleted. Not taken: refusing the move in `decide()` with
  its own sentence. The smaller change, and the roster row is real either way.
- **The date form tells the roster through a function prop (`afterChange`), by roster
  row id**, not through an emit: Vue drops an emit from an unmounted component, so a
  date saved just before the panel was closed never reached its row.
- **Put back and Move take the keyboard**: focus goes into the dialog on mount, Escape
  and Tab are heard on the document, the result's OK is focused after a move, and the
  button that opened the dialog gets focus back. Written in each dialog, as
  `TeacherStudentSheet.vue` does, not as a shared helper.
- **"What is a class" in the browser**: the office roster follows the server's
  `meta.teaches_students`, but the Move dialog's class list and the teacher's student
  sheet compare kind `class` themselves. Not changed; the docblock on
  `Group::teachesStudents()` and the rules now name the three places instead of
  promising a one-line change.

Found by the first MySQL run of this branch (CI run 37233553614), beyond the reviews:
every test in `tests/Mysql/RosterMoveMysqlTest.php` that moved a student, and one in
`tests/MysqlLocks`, read a protected property of the test from outside it ("Cannot
access protected property"). Both files now hand the values in. Checked by running a
copy of the first file on SQLite, where its fifteen move tests pass; the two files
themselves still run only on the MySQL job.

## 2026-10-04 — The office's class list names each class's teachers, with their subjects beside the name (owner: "see the teacher/s names on the class room list"; branch feat/classes-list-teachers)
- **The request.** "It would be nice if I can see the teacher/s names on the class room list. You can multi line for
  classes will multiple teachers and put the subject next to it."
- **What the list gains.** A "Teachers" column on the Classes screen, after the name: one line per teacher, in order
  of name, and beside each name the subjects that teacher teaches in THAT class in the product's own words
  ("Qur'an", "Arabic", "Islamic Studies"), or "All subjects" for a teacher of the whole class. A class nobody
  teaches shows a muted dash. On a phone the subjects drop under the name. The cell is words: no link, no control.
- **Where the names come from.** `GET admin/.../groups` gains `teachers` on each row,
  `[{id, name, subjects: [{value, label}] | null}]`: the live staff logins on `group_staff` with the teacher role,
  read in one query for the page. Set on the page's rows in the controller, not on the model, so no other payload
  carries it. The gate is the one the Teachers screen already reads under (`view contacts`), so nobody sees a
  teacher's name here who could not see it there.
- **Decided here, the owner did not say:**
  - **Order.** By name without regard to case, sorted in PHP so SQLite and MySQL agree whatever the column's
    collation; two teachers of one name keep one order (by id). Subjects are in the product's order, not the order
    they were ticked.
  - **Who counts.** The teacher role only. An archived login is left out though its assignment rows remain. A
    teacher who has been invited and has not signed in yet IS listed: they are assigned, and the Teachers screen
    lists them too.
  - **"All subjects"** for NULL and for an empty list (`GroupStaff::teaches()` reads both that way). A stored value
    this build does not know is shown as written rather than dropped, so a limited teacher can never read as
    teaching everything.
  - **Names only.** No email, phone or invited/active badge in the cell; those stay on the Teachers screen.
  - **The heading is plain "Teachers".** The terminology pack has no word for teachers (the Teachers screen and
    the sidebar say the same); the screen's own title still reads the organisation's word for classes.
  - **Labels travel with each subject** (`{value, label}`), so the screen holds no copy of them. The Teachers
    endpoint sends bare values beside a `meta.subjects` list because its form needs every option; this list
    needs only the ones taught, and adding to `meta()` would have changed show/store/update as well.
- **Not built.** The search box still looks in a class's name, slug and description, not in its teachers' names.
  The names are not links to the Teachers screen. The class's own page (`show`) does not list them.
- **Tests.** `GroupIndexTeachersTest` (12; each guard was removed once and a test went red: the live-login join,
  the role, the tenant scope, the order) and `resources/vue-app/tests/class-teachers.test.ts` (10; the Classes
  screen mounted from its .vue file). Not run on MySQL (no server where this was written); the query uses nothing
  SQLite-specific. The column was looked at in a browser only as a static page built from the compiled
  stylesheets (375 px and 1280 px wide), not in the running app behind a sign-in.

## 2026-10-05: a roster shows the age a family gave when no date of birth is on file

- **Asked.** The owner, the evening the Age column shipped: every age was a dash, and "they need to be populated
  ASAP for every student". The column was built on a date of birth and no school had one on file: the
  registration form asks for the child's age, not the date.
- **Decided.** A second source, `contacts.age_given` (`{age}@{day}`, encrypted, hidden, one writer and one
  reader), used only when no date of birth can be read, brought up to today by the whole years since it was
  given, and marked on the office's roster as the family's ("given"). A date of birth still wins and is still
  the only exact age. Rule: `.claude/rules/groups.md`, "The age a family gave".
- **Rejected.** Writing an estimated date of birth from the age. It would put a date nobody gave into a field
  the office reads as the child's date of birth, and into the records export under that heading.
- **Decided here, the owner did not say:**
  - The office is shown which ages are the family's, in a word beside the number and one line above the roster.
    A teacher is shown the number only.
  - The age grows by whole years from the day it was given rather than staying as typed, so it is not a year
    out the following autumn. It can still be one short between the child's birthday and that anniversary.
  - One encrypted column for both facts, following `date_of_birth`, not two plain ones: the two are never
    written apart, and a plain value reaches a log on any query error that prints its bindings.
  - The ages are copied in by an office-run script through the model's writer, matching a student only when
    exactly one current student has that first and last name. No request can set one.
- **Not built.** A new registration does not carry its age onto the student by itself. No screen edits or
  clears the age given. The records export does not carry it.
- **From the pre-ship read (the session that owns the school rules), same day.**
  - The clear of a date of birth answers the same constant for every contact. The first version answered with
    the family's age, which told the office that a contact who is not a student held one. The date form now
    re-reads the roster row after a clear and tells the roster what it shows.
  - Rolling back: by code only, and a revert must keep `age_given` in `Contact::$hidden`. Older code would send
    its ciphertext in every whole-contact answer.
  - Still to do, next change: clear the age given together with the date once no class lists the contact, and
    have Remove say when either is held. Until then a deletion request for a child who has left is a one-off
    through the model's writer.
  - A teacher now sees an age for these students too, with no mark that it may be one short.
- **Tests.** `StudentAgeGivenTest` (20), the two SPA files, one MySQL case (CI only).

## 2026-10-05: a moved student's consent goes with them as it was recorded, and a whole class can be moved in one go (owner: "Carry each parent's consent as it is"; "yes it should carry it too"; "defaults makes sense"; branch `feat/r2-integration`)

- **Asked.** After the single-student move shipped the owner asked for a whole class to be moved at once, and
  for consent to go with the students: 25 students with two guardians each would otherwise be 50 consents to
  record again by hand. Asked which he meant, he chose "carry each parent's consent as it is" over "consent
  not required", and said a single move should carry it too. This REPLACES the decision of 2026-10-04 that
  consent does not follow a move.
- **Decided: one door, and as it is or not at all.** `GroupMembership::carriedFrom()` gains a named argument,
  false by default, and copies the two consent columns unchanged, for the same adult and child, from a
  confirmed, current entry with consent, onto an entry the move CREATES. Never onto an entry the class already
  holds; never for an adult with none on record; never a narrowed copy. When the adult already stands in the
  class entered for another child with less, nothing is carried and the guardian is named. No gate that decides
  who receives a class story was touched.
- **A marker column, naming the class.** `group_memberships.consent_carried_from_group_id` tells a carried
  consent from one the office recorded, on the row, for as long as the row exists. A record by the office
  clears it; a withdrawal keeps it, which is how "withdrawn here after it was carried" stays readable. It names
  the class and not the entry, because Remove and a contact merge re-issue rows. No column for who carried it
  or when: the copy's own dates and the move's stamp on the student's row say it, and the log line for about
  two weeks (ASSUMPTIONS.md M-12).
- **Nothing withdrawn comes back by a move: refused, not just named.** A move destroys nothing, so a source and
  its copy both keep their consent, and the family can since have withdrawn or narrowed on either. A move back
  that would re-open one of them with more than the other side now holds is REFUSED with the remedy (withdraw
  it on that roster first, then move, then record afresh if the family agrees); a "Put back" that would do the
  same is NOT OFFERED on the screen, and its verb stays ungated (corrected by the repair pass below: this
  sentence used to say "REFUSED" of both). Naming it in a
  line was the alternative and was rejected: the only way to not re-arm a consent without a move writing to an
  existing entry's consent is to refuse. Where the other side no longer exists there is nothing to compare, and
  the guardian is named. What the refusal cannot see is written down (the rules file, and M-11).
- **A withdrawal says where else consent stands.** Its answer lists the same adult's other entries that still
  open this class, then their entries for the same child in other classes. A carry makes two rows of one
  family decision, and the moment of a withdrawal is when the office is acting for the family.
- **What the tap echoes.** The preview takes no lock, so the POST now also echoes a fingerprint of the consent
  result; a difference under the locks is "changed while you were looking" and nothing is moved.
- **A whole class is the single move, once per student, in ONE request that names the rows.** The server
  pre-flights the whole list and refuses the request when anything differs from what was shown; then each
  student moves in their own transaction and the answer names every one: moved, not moved and why, or not
  reached. A refused student does not stop the others; a fault does, and the answer still says who was moved.
  One transaction for the class was rejected: one held row would fail everybody, and a register save about
  any student would wait for all of them. The browser sending the single request N times was rejected: only a
  server run can judge two siblings against the class as it stood BEFORE the run, so the result does not
  depend on the order of a list.
- **Its limits are judgements, not measurements.** One run at a time per class being left (a lock on the
  `database` cache store, named so it does not depend on configuration); 60 students per request; no student
  started after 40 seconds; one attempt per student inside a run. Nobody has timed a move on MySQL (M-13).
- **Grades are asked each time.** Keep, up one, or one grade for everyone, with none pre-selected: the request
  was "move a class UP", and a pre-chosen "keep" would land a class in next year's room with last year's
  labels. "Keep" on a return gives the grade recorded on the place re-opened, which is what puts a class back.
- **Putting a class back is the same action from the other class**, with one filter ("only the students who
  came from …"). No table of runs: each student's row already says where it came from.
- **The class moved is not ended or switched off by the run**, before or after; the result says how, after it
  has said how to put the class back.
- **The family portal says nothing new** about a carried consent (the owner's default: no new text in six
  languages); a parent who wants it withdrawn asks the office. One false sentence is removed: the portal no
  longer says "You have not given consent" about a class the child has left (`in_class_now`).
- **Between new code and its migration a move is refused**, with one sentence and one WARNING line, rather than
  carrying nothing for a while: a move made then would do something its own sentences do not say.
- **Decided here, the owner did not say.** The old place a student is moved back FROM is no longer offered for
  Remove once a consent was carried beside it. The roster's consent button shows the word "Consent". The
  consent answers carry their notes at the top level of the answer. For a class the child has left, the
  portal's Story tab shows nothing rather than "Nothing posted yet." (M-20).
- **From the integration walk, in a browser on a local instance.** Three things no test without a layout could
  see, each fixed and pinned. The single-student dialog's Move button was out of reach on a laptop-height
  window once its preview carried the consent lines: the form between the dialog's box and its body stopped the
  body scrolling (the class dialog had the same fault on a phone, fixed by its builder). "Open {class}" on a
  refusal outlined the guardian's row and left it below the fold: every navigation in the admin app ends by
  scrolling to the top, the one that takes `focus` off the address included, so the roster now waits for that
  navigation before it scrolls. And after a move that empties a class the keyboard was on nothing, because the
  button the dialog returns it to had just gone: it goes to "Add to roster".
- **Not built, and whose it is.** The class-store balance does NOT follow a moved student yet: the ledger's
  two transfer kinds, their writer and their labels are in this branch and nothing calls them; the commit
  that lets a move carry a balance waits on the class-store rules and is made separately, with its own
  record. Recording a whole class as having LEFT the school is not part of this. A parent withdrawing their
  own consent is not built. Two lock cases have no test (M-15).
- **Tests.** `RosterMoveTest`, `RosterMoveRosterTest`, `RosterClassMoveTest`, `FamilyPortalTest`,
  `OfficeStudentDetailsPayloadTest`, and the SPA files `roster-move*.test.ts`, `roster-class-move*.test.ts`,
  `student-details.test.ts`, `family-left-class-notice.test.ts` (run). `tests/Mysql/RosterMoveMysqlTest.php`
  and `tests/MysqlLocks/RosterClassMoveLocksTest.php` were NOT run where they were written (no MySQL server);
  they run in CI's MySQL job (M-16). See ASSUMPTIONS.md M-9 to M-21 and `.claude/rules/groups.md`, "Moving a
  student to another class".

## 2026-10-05, later: what four reviews of that branch changed (the repair pass on `feat/r2-integration`)

Four adversarial reviews read the integrated branch (consent, the server, the screens, the diff as a whole).
None found a blocker; five findings were major. What was decided in answering them, and why:

- **A carried consent the family REDUCES in the new class now stops a return, as a withdrawal does.** The
  owner's approved default says "withdrawn or reduced on the other side of an earlier move"; what was built
  refused a reduction only where the consent was first recorded, because a record on the copy cleared the
  marker. Now a record of LESS than the class it was carried from holds KEEPS the marker, and the move's
  rule reads "marked, and less than this entry" (`copy_narrowed`). It is a comparison of two rows, not a
  memory of an act, so an untouched copy whose source was recorded for more afterwards is refused too;
  every sentence and the roster's label therefore say what the two classes hold, never "reduced".
  Rewording the owner's default to match the code was the alternative, and was not taken: he approved the
  wider rule.
- **"Put back" on a row that simply left is held to the same consent rule as on a row a move left.** The
  entries beside it re-open either way, and one can be a carried copy whose source was withdrawn while the
  child was in neither class. Still a screen guard; the verb stays ungated by its own rule.
- **A body with no consent echo may not carry a consent.** The bundle shipped before this release sends none,
  and its screen said consent "does not move". Design 4.4 said an absent expectation is not checked; design
  7.2 refuses a move in the deploy window "because a move made then would otherwise do something the sentence
  does not say", and the same holds for that tap. Told to reload in its own sentence: "look again" would loop.
  Set by the single verb's controller, so no other caller of the service is held to it.
- **In a whole-class move, a brother or sister who may go back caps a carry before their entry re-opens.**
  One sibling returning and one arriving used to be decided by the order of the list, and in one order the
  consent was carried and the returning child's blank entry opened beside it, unsaid. Reordering the run
  (returners first) was the smaller change and was not taken: it leaves the same hole when the returner is
  busy and is moved in the next round. A single move keeps the design's rule (a closed entry gives no standing).
- **A parent with consent for one child and none for another is told the story reaches them**, not that the
  family "receives nothing", when both children are in one whole-class move.
- **A roster row put back by hand that later simply leaves is no longer "moved to" anywhere.** It read as
  moved away for good: on the roster, to a move, and to the class store's undo, each with a false sentence.
- **The class dialog.** Only the Move button sends (Enter on a student's tick used to move the class); a
  refused run is said first in the body and beside the button, and holds Move off until it is read; a
  refusal about the class as a whole leaves the ticks alone and has "Try again"; a click beside the box
  never closes a result; "give everyone this grade" asks nothing until a grade is typed.
- **Not built, and whose it is.** The whole-class preview still prints, child by child, the shipped "has
  Manara Bucks … They stay there for now": that is the ledger wiring's to replace, and the class store must
  stay off until then (M-23). Remove is still not refused on a place holding a withdrawn carried copy; only
  the move's invitation to remove it is gone. The two missing lock cases and the first MySQL run are still
  open (M-15, M-16): no MySQL server exists where this was written.
- **New wording, for the owner.** The refusal for a reduced copy; the reload sentence; the cap by a sibling who
  may return; the three "through a brother or sister" sentences; "A guardian's withdrawal of a carried consent
  is recorded there, so it stays."; the busy student's sentence in a class result; the roster's "Carried from
  {class}, which holds photograph consent". Each is built on the server (the last is a state label), each is
  pinned, and none was in the approved design.
- **Tests.** The same files as above, with a test that fails without each change. See ASSUMPTIONS.md M-7, M-9,
  M-11, M-17 (rewritten) and M-22 to M-26.


## 2026-10-05 — A registration that was never paid can be deleted once it is cancelled (narrows the rule that a registration with a money leg is never deleted; branch `fix/delete-unpaid-registration`)

- **Asked.** An organisation's office reported that it could not delete registrations: people start a
  registration, reach the card payment page and never pay, and what they leave behind stays in the list for good.
- **The rule until today, written down here for the first time.** `FormResponsesController::destroy()` refused
  every registration with a money leg (`FormResponse::hasMoneyLeg()`: any `payment_method` at all) with "A
  registration with a payment is never deleted. Cancel it instead, and add a note." The rule existed only as code
  comments citing "festival brief, blocker 2", a brief that is not in this repository, and no entry above states
  it. Its reason holds: a holder's cash, a Stripe charge and the cash totals all point at the row. But
  `payment_method` is written the moment a registrant CHOOSES how to pay, so a card registration whose page was
  abandoned (`online`, `unpaid`) and a family that chose the office and never came (`office`, `unpaid`) were
  "registrations with a payment" although no money had moved.
- **Decided: the rule is narrowed, not removed.** A registration is deleted only when it never held money AND
  can no longer take any. `destroy()` decides on the locked row, in this order:
  1. **A payment on record: never deleted**, in the words it always was. "On record" is everything outside an
     ALLOWLIST, `FormResponse::neverRecordedAPayment()`: method `online` or `office`, `payment_status` exactly
     `unpaid`, and `paid_at`, `stripe_payment_intent_id` and `charge_flag` all null. So a registration paid by
     card, in cash or elsewhere, in any status, cancelled, refunded or disputed, is never deleted, and neither is
     any state nothing writes (cash that reads unpaid, an unpaid row carrying a payment intent): those go to a
     person. `hasMoneyLeg()` itself is unchanged; it has six other readers.
  2. **Imported from another system** (`external_ref`): refused as before, whatever its payment state. The next
     import would write it back.
  3. **No money leg**: deleted, as before.
  4. **Never paid and not cancelled: refused**, "This registration has not been paid, but it still can be. Cancel
     it first; a cancelled registration that was never paid can then be deleted." Stripe is not asked. Cancel
     comes first because only a cancelled registration is refused a new card page and a payment by hand: one left
     `new` can be paid at any later date (`FormResponseCheckoutService::preflight()` checks no window and no age).
     The cancel is also the reversible step, it is stamped with who and when, and it is what closes the card page.
  5. **Never paid and cancelled, no card page on record** (every office registration; a card registration whose
     page never opened): deleted, with no Stripe call.
  6. **Never paid and cancelled, a card page on record**: Stripe is asked about that page under the row lock,
     through the existing `closeOpenSession()` (which closes a page that is still open), and the registration is
     deleted only when the answer is exactly `expired`. `complete`, an account Stripe no longer lets the platform
     act on, no account on record to ask, a refused close, any Stripe error and any status that is not a page's
     each KEEP the registration, cancelled as it was, and the answer says it was not deleted and why. The earlier
     cancel is not trusted for this: it leaves the session id on the row and records nothing about its close.
- **Why "unpaid" on the row is not enough.** The webhook answers Stripe 200 and records nothing when an event
  carries no connected account, names an account no organisation holds, or fails a pinned row's account,
  currency, session or amount test. The row then reads unpaid while its page is `complete` at Stripe. Only
  Stripe's status for the page on the row tells the two apart, which is also why no delete by age alone was
  built. After a delete a late payment finds no row, is logged, and Stripe is not asked to retry.
- **Paying the office is included.** No Stripe leg can exist for such a registration, and once cancelled it is in
  no figure of the cash totals (the cancel is what takes it out of "owed to the office", so the delete moves
  none). What remains is money handed to the office and never recorded, which only the office knows: the
  screen's question says to delete it only if the office received no payment for it.
- **What a delete does, and the one record of it.** There is no soft delete: the answers and the uploaded files
  go (the files in the model's deleting hook, so the delete is the last write, after everything that can
  refuse), the reserved date goes with the row, and the place on a form with a capacity is given back. The
  cancel's stamp goes too. So every delete this endpoint performs writes one WARNING line first, by ids only:
  organisation, form, registration, its uuid, payment method, Checkout session, the organisation the page was
  charged through, the amount due and the total, and the admin's user id. The uuid and the session are what the
  webhook's "NOTHING was recorded" warnings carry, so an orphan payment can be matched to the deleted row.
  Never a name, an address or an answer.
- **Refusals are returned from the transaction, never thrown.** `closeOpenSession()` switches an unreachable
  holder's card payments off inside the caller's transaction; an exception leaving `destroy()` would roll that
  back. A row that went away while the request waited for its lock answers 404, where it answered 500.
- **Lock order.** On a form that reserves dates the FORM row is locked before the registration's row, as
  `update()` and the public submit lock them. The submit holds the form and then locks the registration holding
  the date it asks for; a delete that held the registration and then wrote the form's counter could wait on it
  for ever. A form that reserves nothing is locked as it was.
- **The screen.** One pure rule, `formResponseStatus.ts::deleteStep()` (delete / cancel-first / never), read
  from `status`, `payment_method` and `payment_status`. Not `payment_state`: that is null on every row of a form
  that no longer has payment settings, and a paid registration there would be offered a delete. Delete is dimmed
  with the server's sentence for "never" and "cancel-first", and still clickable so the sentence can be read.
  A live Delete asks a question that says what goes with the registration, holds the row busy from the question
  to the answer, shows a refusal in the server's own words, and re-reads the list and, where the form reserves
  dates, the board. The cancel question now says, for a never-paid registration, that the cancel is what lets it
  be deleted, and that deleting it afterwards frees its place; the detail's "its payment on record" hint shows
  only for a paid registration.
- **Rejected.**
  - **Delete in one step, without the cancel.** Safe for the payment (pages open only under the row lock, which
    the delete holds), but it removes a registration that can still be paid in one press, with no reversible
    step and no stamp. Cancel-first also reuses what `update()` already says about the card page.
  - **"Anything that is not paid" (`payment_status != paid`).** A denylist: it would delete states the
    application cannot write instead of sending them to a person.
  - **A server-sent "deletable" flag.** The list row already carries the three columns, the server decides on
    the locked row whatever the screen thinks, and the screen's convention is a mirrored rule plus the server's
    sentence.
  - **Guards against a cart line or an offering registration pointing at the row.** Both pointers only ever land
    on a row with no money leg or a paid one, so the two queries could never refuse.
- **Not in this change, each its own decision.** A soft delete. Hiding cancelled registrations by default.
  Expiring abandoned registrations automatically. Any delete without Stripe's `expired` (by age, or the
  "I checked the holder's dashboard" override that take-cash has). A Delete in the detail panel. Raising the
  webhook's row-not-found line to error level.
- **Known limits.**
  - Three kinds of cancelled card registration can never be answered `expired`, so they stay, cancelled, as
    they do today: an organisation with no Stripe account on record any more; a page pinned to an account Stripe
    no longer lets the platform read; and (inferred, not run) an unpinned page whose organisation has since
    moved to another Stripe account, which is asked about on the new account and answers "try again" each time.
  - A family that chose the office was emailed its registration number at submit; after a delete that email
    points at nothing. A card registrant was never emailed, and their open tab reads "This registration was not
    found."
  - The native apps are not in this repository. A client that still sends the old request gets the new sentences.
  - The screen was not driven in a browser by this change, and nothing was run against Stripe or any server.
- **Not verified in Stripe.** Two facts this leans on are only asserted by the code's own comments and stubs:
  ASSUMPTIONS.md D-1 and D-2. They should be tried in Stripe test mode before this ships.
- **Earlier entries this narrows.** 2026-09-13, "Unpaid office rows count toward capacity and never lapse…
  unless someone has a plan to cancel stale rows": a stale one can now be cancelled and then deleted, which
  frees its place. 2026-09-27 (responses list status select, review fixes), "A registration with a payment is
  never deleted, so for it the capacity line says cancelling does not free a place": true of a registration a
  payment was recorded on; a never-paid one is told that deleting it after the cancel frees the place.
- **Rule file.** `.claude/rules/stripe-payments.md`, "A registration that was never paid can be deleted".
- **Tests.** `FormResponseNeverPaidDeleteTest` (one per kind of row, and what a delete does),
  `FormResponsesMoneyAdminTest` (the pinned delete test, split), `form-response-status.test.ts` (the rule, the
  two questions, the wiring, the two sentences against the controller's source),
  `tests/Mysql/FormResponseDeleteLockMysqlTest.php` (the two locking reads and their order; CI only, NOT run
  where it was written).
- **After the three reviews (2026-10-05, same branch).** Three reviewers (server, money, screen) found no
  defect in the committed behaviour. This round closes the test gaps they named, hardens the allowlist and makes
  four sentences true. Where a line here differs from a bullet above, this one stands.
  - **The allowlist names every column only a payment writes.** `neverRecordedAPayment()` now also requires
    `paid_via`, `marked_paid_by_user_id`, `staff_code_id`, `collected_at` and `charge_flagged_at` to be null and
    `charge_refunded_minor` to be empty. The application writes each of them together with `paid` or with the
    charge flag, so nothing it writes is newly refused; an unpaid registration carrying one of them alone is a
    state nothing writes, and goes to a person like the others. A new column that only a payment writes joins
    the list.
  - **The rule is pinned to the locked row.** Reading it from the copy the request first found left every test
    green and deleted a registration paid in between. Two tests now change the row after the first read
    (restored: "cancel it first", Stripe not asked, its page left open; paid meanwhile: the paid sentence, the
    registration kept), and each was seen to fail against that one-line regression.
  - **Stripe is asked about any page on the row, whatever its payment method**, and a test now holds the code
    to it: an unpaid office registration carrying a page that was paid is kept.
  - **A page Stripe says was paid.** The refusal no longer says "It will show as paid once Stripe confirms it":
    when the webhook refused that payment's event and answered 200, no second event comes and the registration
    reads unpaid for good. It now ends "If it still shows as unpaid later, check this payment in Stripe.",
    followed by the refund instruction as before. The delete also writes one WARNING line for it, by ids only
    (organisation, form, registration, its uuid, Checkout session, the organisation the page was charged
    through): the moment the platform learns of money at Stripe that the row does not record.
  - **The delete's own line** is still written before the delete, so the record exists even if the process
    dies between the two, and for that reason it now reads "is being deleted": a delete that then fails rolls
    back, and the line had said "was deleted" of a registration that was still there. It now also carries
    `charge_ref` and the cancel's stamp (`status_changed_by_user_id`, `status_changed_at`). The bullet above
    overstated what a late payment can be matched by: the webhook's warning carries the uuid only for a charge
    on the organisation's own account. For one taken through another organisation it carries the Stripe object
    and no uuid, so the Checkout session matches that page's own event, and `charge_ref`, the one key such a
    charge carries in Stripe's metadata, is what matches the rest.
  - **"The delete is the last write" was wrong.** The files leave the disk in the model's deleting hook; the
    row's own DELETE, the form's counter and the commit follow. A failure in one of them leaves the
    registration in the list without its files. The comment and the rule file now say so; changing it is the
    fourth open item below.
  - **Tenant isolation of the DELETE is tested.** Another organisation's registration under this organisation's
    form (404), under this organisation's id with the other's form (404) and under the other's own URL (403),
    and this organisation's registration under another of its forms (404): rows, files and counters untouched,
    Stripe never asked.
  - **The screen.** The cancel question no longer ends with "once it is cancelled it can also be deleted" for a
    card page already known to be beyond checking (`page_unreachable`), nor says a delete after the cancel
    frees its place: it says the registration stays cancelled and cannot be deleted unless Stripe can be asked
    about its card payment page, which is what the delete would answer. The delete question tells every card
    registration that its card page is checked first, from the row's own flags and never `payment_state`: where
    the row says a page is on record it says so outright, and where the row does not say (on a form that has
    lost its payment settings the server sends `card_page_opened` false on every row, and still asks Stripe
    about a page such a row carries) it says "If a card payment page was opened for it, that page is checked
    first". The same sentence now also shows for a card registration whose page never opened, where it is true
    and asks nothing. `deleteStep()` still reads `status`, `payment_method` and `payment_status` alone, and its
    comment now says that is a choice: the row carries `paid_at`, the payment intent and the charge flag, the
    server refuses such a row on the locked row, and the screen shows that sentence. While its request runs,
    the pressed row's Delete shows the status select's spinner in place of the bin and is `aria-busy`; a second
    press is still turned away by the row lock and sends nothing.
- **Known limit: on a form that reserves dates, the form row is held across the delete's Stripe calls.** The
  delete locks the form row first (see "Lock order") and keeps it to the commit, across `closeOpenSession()`:
  one read of the page, a close when the page is still open, and a second read when that close is refused. The
  public submit takes the same form lock for every submission, and a triage of that form takes it too, so they
  wait for as long as Stripe takes. No timeout is set on the Stripe client, so the SDK's own apply (80 seconds
  a request, 30 to connect, read in the vendored client; the wait itself was read from the code, not measured
  under load). Cancelling such a registration from the list already waits the same way; an office clearing a
  list of abandoned registrations repeats it once per row. A form that reserves nothing holds only the
  registration's own row across the calls. Not changed here: a short timeout for these admin-side page reads
  is its own decision.
- **Open after the reviews, not built in this round.**
  1. `update()`, take-cash and the public checkout answer 500, not 404, for a registration deleted while they
     waited for its lock (`lockRow()` still ends in `firstOrFail()` inside the transaction). Only the DELETE
     answers 404. Before this change a registration with a payment method could not disappear under them.
  2. After a delete the whole list is re-read behind the page's spinner, so at phone width the table's scroll
     position and the focus are lost; and deleting the only row of a last page re-reads that page, which is now
     empty, instead of the one before it. Read from the template, not seen in a browser.
  3. A registration with NO payment method is deleted even when it carries a payment intent or `paid_at`
     (unchanged from before this change: the old rule tested only `hasMoneyLeg()`). No path that writes such a
     row was found.
  4. An attachment's bytes are removed before the commit (see "The delete is the last write" above). Removing
     them only after it is a change to the model's hooks, shared by every delete of a registration.
- **"No Checkout session id on the row" means nothing at Stripe could have been paid (the lead reviewer's
  question, 2026-10-05).** The delete of a cancelled, never-paid card registration asks Stripe only when a
  session id is on the row. What makes the other case safe is ONE fact: a page's address reaches the payer only
  in the answer of the transaction that records its id (`FormResponseCheckoutService::onLockedRow()` returns
  after its commit). So: (1) an attempt in which Stripe failed, or whose answer or commit was lost, is ROLLED
  BACK whole. `create()` does save the idempotency key before it calls Stripe, but inside that same
  transaction, so the key goes too and the row is left with neither a key nor an id; whatever session Stripe
  made in that attempt has an address nobody was ever given, and nobody can pay it. Pinned by
  `FormPaymentCheckoutTest::a_stripe_failure_after_the_row_is_written_leaves_it_unpaid_and_payable_again`
  (the key is saved when Stripe is asked, and null afterwards). (2) The id is cleared in one place,
  `reuseOrReplace()`, and only after Stripe answered for the old page: `complete` throws and keeps it, an open
  page with an address is handed back and keeps it, an open page with none is closed first (`complete` there
  throws), so the id goes only when the page is expired (for a pinned account Stripe no longer lets the platform
  read, only once the pinned expiry has passed); and a replace that then fails is rolled back to the old,
  expired id and key, so a later delete asks Stripe about that old page. (3) A row holding an idempotency key
  and NO session id is therefore a state nothing should write. If one is ever found, cancelled and never paid,
  it is NOT deleted: 422, "A card payment page was requested for this registration, but its reference was not
  saved, so we cannot tell whether it was paid. It was not deleted, and it stays cancelled." It goes to a
  person. Production held no registration in that state on 2026-10-05 (counted). A payment that did land on a
  page nobody can name meets the webhook: found by the registration's uuid or charge reference it is recorded
  as paid as usual; refused, it writes the "NOTHING was recorded" warning that carries the same uuid.
- **After the re-reviews (2026-10-05, branch `fix/never-paid-delete-followups`).** Three small things the two
  re-reviewers of the hardening asked for, none of which changes what is deleted. (1) The rule file's paragraph and
  `destroy()`'s docblock now name the fifth outcome: with no page on record a row is deleted only when it also holds
  no idempotency key (`DELETE_PAGE_UNKNOWN` otherwise). (2) The CANCEL made the promise the delete had stopped
  making: when Stripe says the page of a registration being cancelled was paid, `closePageOfCancelled()` answered
  "…so it will show as paid once Stripe confirms it". It now says "This registration had just been paid by card. If
  it still shows as unpaid later, check this payment in Stripe." (the refund instruction still follows) and leaves
  one warning line by ids, as the delete does for the same answer. (3) A test pins that the row Delete's accessible
  name says it is deleting even when the row has just been given a reason it cannot be.
  Still open, known and not built: for a card registration that is NOT yet cancelled and whose page is pinned to an
  account Stripe no longer lets the platform read, the dimmed Delete (its tooltip, accessible name and popup) still
  says "a cancelled registration that was never paid can then be deleted", which the server will refuse after the
  cancel (the cancel question for the same row already says so truly). No such registration exists today. The
  delete question hedges ("If a card payment page was opened for it…") because the row's `card_page_opened` is
  only sent on a form that still has payment settings. The progress sign's `role="status"` sits inside the button,
  so what a screen reader is sure to get is the button's changed name and `aria-busy`.

## 2026-10-05 — The private disk's signed storage routes are switched off (`'serve' => false` on `local`; branch `fix/no-signed-local-disk-routes`)

- **What was switched off.** `config/filesystems.php`, disk `local` (`storage/app/private`, where every private
  upload lives), `'serve' => true` is now `false`. It was the only disk with the flag. With it on, the
  framework (`FilesystemServiceProvider::serveFiles()`) registered two routes after the application's own, with
  no login and no middleware: `GET|HEAD storage/{path}` (`storage.local`) and `PUT storage/{path}`
  (`storage.local.upload`). Each honoured only an address signed with APP_KEY, or with any key still listed in
  APP_PREVIOUS_KEYS (the framework's check tries each; confirmed by a run at 9e026457), so rotating APP_KEY alone
  would not have closed it. The PUT wrote the request body to the named path on the private disk. The disk also made such addresses (`temporaryUrl()`,
  `temporaryUploadUrl()`); with the flag off it throws "This driver does not support creating temporary URLs"
  (and "...upload URLs").
- **Why.** The signature was the only thing between a request and a write onto the private disk, and the
  application has no use for either route. Private files leave only through the application's own routes, which
  re-resolve masjid, group, resource and audience on every request: the authenticated downloads
  (`.claude/rules/private-uploads.md`) and, for video, the playback ticket `GroupMedia` signs for one viewer. An
  address the framework signs would skip all of that and would outlive consent being withdrawn. The GET was never
  reachable here, because the admin screen's GET catch-all in `routes/web.php` is registered first (run at
  9e026457: a signed GET for a file that exists on the private disk was answered with the admin shell, not the
  file). The PUT was: the catch-all is GET only.
- **Two ways it could come back unnoticed.** Removing or renaming the whole `local` entry in
  `config/filesystems.php` brings back the framework's own default `local` disk, which has serve on (deleting only
  the `'serve'` line is safe); the test reads the merged configuration and goes red for it. And the disk's refusal
  to make an address holds on the real disk only: `Storage::fake('local')` makes addresses of its own, so a
  feature tested against a faked disk would pass its tests and throw on a server.
- **The door was real.** At 9e026457, in the test client, the disk minted an upload address for `proof.txt`; a
  PUT of a body to it with no login answered 204 and the body was on the private disk (read back, under the real
  configured root and under a scratch root). The same PUT unsigned, or with a signature that was not one, answered
  403 and wrote nothing. An address signed by hand (HMAC-SHA256 of the relative URL `/storage/proof.txt?expires=..&upload=1`,
  keyed with APP_KEY) was byte-for-byte the one the disk made, and wrote the same.
- **Confirmed unused.** Searched `app/`, `routes/`, `resources/` (views and the Vue app), `config/`,
  `database/`, `tests/`, `bootstrap/`, `bin/`, `scripts/`, `deploy/`, `docs/`, `.claude/` and every Markdown record
  for `temporaryUrl`, `temporaryUploadUrl`, `getTemporaryUrl` (and the `getFirst`/`getLast`/`getAvailable`
  forms), `buildTemporaryUrlsUsing`, `serveUsing`, `storage.local`, `Route::has`, route lookups by name, any read
  of the `serve` key, and any client that PUTs to `/storage`: nothing. The only mentions were the comment on
  `GroupResource` and the flag itself. `GroupMedia` signs the application's own named playback routes, not these.
  Packages: `spatie/laravel-medialibrary` 11.23.3 makes a temporary address only when something calls
  `getTemporaryUrl()`, `getAvailableTemporaryUrl()` or the first/last forms, and nothing here does (its
  `getUrl()` is an unsigned address on the media disk, `MEDIA_DISK`, default `public`); the Pro package named in
  `config/media-library.php` is not installed; `barryvdh/laravel-dompdf` 3.1.2 only does
  `Storage::disk($disk)->put()` to save a PDF. Laravel and Flysystem define the temporary-address methods; across
  the rest of `vendor/` Spatie's is the only code that calls one. `mpdf/mpdf` was NOT read: this worktree's `vendor/` lacks it (and four of its
  dependencies) although `composer.lock` names 8.3.1. It requires no Laravel package, so it cannot ask a Laravel
  disk for anything, and the one place the application uses it, `ReportCardPdfService::render()`, returns the
  bytes in memory. Also not read: the production `.env` and server configuration (no server was contacted).
- **When it takes effect.** On a deploy, not at merge. `bin/deploy` runs `route:clear` then `route:cache`. In a
  private export, route cache built from 9e026457 held both routes (`route:list --path=storage` showed them, a
  signed PUT answered 204 and wrote); built from this change it holds none (no route matches `storage`, and a
  PUT, signed or not, answers 405 and writes nothing). Uncached gave the same. Until the next deploy the
  production route cache still holds the two routes.
- **Before anyone switches it back on.** All of these would have to be true, and written here when it happens:
  1. A feature the owner has asked for needs a signed address to a file, and it cannot be served by an
     authenticated route that re-resolves the ownership chain the way the attachment downloads and the playback
     routes do.
  2. It is a disk of its own that holds nothing private. Never `local`. The flag registers the PUT as well as the
     GET, so anyone who can sign can write to that disk.
  3. The addresses are short-lived, and what happens when consent is withdrawn or access is lost while one is
     still valid has been reasoned through and written down.
  4. `tests/Feature/NoSignedLocalDiskRoutesTest.php` is changed in the same commit, saying why.
- **Not done here.** No `/storage/...` route of this change's own. `feat/page-documents`, which went out in the
  same release, adds a GET that answers 404 on `/storage/{missing}` ahead of the catch-all; its comment, its
  test's comment and its rule were brought in line with this change when the two were combined.
- **Tests.** `NoSignedLocalDiskRoutesTest` (7). Red at 9e026457: no disk has serve on, no route named for a disk,
  no write verb matched on a storage path, a local disk refuses a temporary address, a correctly signed PUT
  writes nothing. Green at 9e026457 as controls: an unsigned PUT and a badly signed PUT write nothing (the
  signature was the only barrier).

## 2026-10-05 — Two public image uploads pin the file's name as well as its bytes (owner asked; branch `fix/pin-upload-file-names`)

- **Asked.** Pin the file name on two image uploads to the public disk that checked a file's bytes and not its
  name: a page's title background (`StorePageRequest`, `UpdatePageRequest`; the media library keeps it under the
  client's own file name) and the Friday-lunch flyer (`MealMenusController::uploadFlyer`, served to the admin
  realm and to the lunch realm; stored as `<uuid>.<the client's extension>`).
- **Reproduced before anything was changed** (`PublicUploadFileNameTest`: real JPEG bytes in a real
  `UploadedFile`, so the type is what finfo reads from the file). Named `x.html`, a new page answered 201 and the
  file was on the public disk as `<media id>/x.html` with a media row of that name; a replacement answered 200
  the same way; the flyer answered 201 and was stored as `lunch-flyers/<uuid>.html`, through either realm. The
  same for `x.jpg.html`, `x.HTML` (the flyer lower-cased it to `.html`) and `x.svg`. A name with no extension
  was kept as it came on a page and became `<uuid>.jpg` on the flyer. `x.php` was already refused by both:
  `image` and `mimes` turn a PHP extension away themselves (`shouldBlockPhpUpload`).
  Not reproduced here: the web server answering such a file as a page. The suite has no web server; that half
  is read from how `public/storage` is served (from disk, the Content-Type from the extension).
- **Decided.**
  - `extensions:` beside `mimes:`, naming the same kinds, on all three rules: `jpeg,jpg,png,gif,webp` for the
    title background, `jpeg,jpg,png,webp` for the flyer, which has never taken a GIF. This is the rule of
    2026-09-25 (above), applied to two uploads it had not reached. They were not the only two: thirteen more
    had the same gap and were closed the same day (the next entry).
  - The flyer is stored under `$file->extension()`, which is guessed from the sniffed type and which `mimes` has
    already held to its own list, never under the client's extension. PNG bytes sent as `photo.jpg` are kept as
    `.png`, and a `.jpeg` is kept as `.jpg`. Nothing reads that name except the address the upload answers.
  - A file refused only for its name reads one sentence: "The flyer's file name must end in .jpg, .jpeg, .png or
    .webp. Rename the file and upload it again." (the title background's also names `.gif`). The other rules keep
    the framework's sentences, as before.
  - Capitals. `validateExtensions()` compares `strtolower()` of the client's extension with the list exactly as
    the rule wrote it (`Illuminate\Validation\Concerns\ValidatesAttributes`). `IMG_0001.JPG` therefore still
    passes, and a test says so on every door; a list written in capitals would match no name at all, so the
    lists stay in lower case.
- **What a person meets that they did not before.** A real image whose name ends in something else (`.jfif`,
  `.jpe`, or no extension at all) is refused until it is renamed. It used to be accepted on its bytes alone.
- **Not changed.** No migration. No file already on the disk is renamed, moved or looked for. The size limits,
  the kinds of image accepted and every other field of the three rules are as they were.
- **Rejected.** Naming the title background server-side (`usingFileName`). It removes the class without refusing
  anything, but it changes stored names and addresses, and 2026-09-25 chose a 422 for the same kind of upload.
- **The screen (the lunch board; its own commit).** A refused flyer was shown as axios's "Request failed with
  status code 422": `serverReason()` in `JummahLunchView` reads a string `data` or a `message`, and a refused file
  arrives keyed by its field. The field's sentences are now read with `serverFieldErrors` and put under the flyer
  input (`flyerError`), cleared by the next pick and by reopening the dialog. Not in a toast: the dialog's overlay
  (`.jl-modal`, z-index 1080) is stacked above the toast's container, and at phone width the dialog covered the
  toast completely. Both were seen in a browser against a local server and a scratch database: a JPEG named
  `x.html` picked in the New menu dialog put the sentence under the input, a reopened dialog did not carry it,
  and `IMG_0001.JPG` then uploaded and cleared it. Any other failure of the upload goes to the toast as before.
  Not seen: a desktop-width window, and the lunch realm's own sign-in (it is the same view).
- **The title background has no screen.** The admin SPA's page form sends no file to the pages endpoints (the
  page-title editor uploads through the section requests, which already pin the name), so that sentence is read
  by a caller of the API and by nobody on a screen.
- **Left as found.** Every other toast raised while a `.jl-modal` dialog is open (a failed menu save, "Flyer
  uploaded") is still behind the overlay. Moving them is its own change to that screen.

## 2026-10-05 — Every upload kept on the public disk under the client's file name pins that name, and two kinds of test watch it (rounds 2 and 3 of `fix/pin-upload-file-names`)

- **Found.** After the entry above, every upload under `app/` was audited (28, read at 9e026457). Thirteen more
  image uploads, 26 rule lines in 18 requests, checked a file's bytes and not its name while the media library
  kept the client's file name on the public disk: announcements, splash announcements, the gallery (one picture
  or a bag of them), the organisation logo on Details, the header and footer logos on General settings, the
  logos on organisation create and edit, a user's picture on the Users screen, an admin's own profile picture
  (no capability gate, so every admin-realm login reaches it), a service's picture and icon, the About picture
  and its two icons, the donation link's picture, a push notification's picture, and the publish composer's
  main picture, which is copied under the same name into the announcement and the notification it makes.
- **Reproduced before any rule changed** (`PublicUploadFileNameDoorsTest`: real bytes through the real routes,
  29 doors, one or more per rule line). Image bytes named `x.html` and `x.svg` were answered 200, 201 or 202 at
  every door and kept as `<media id>/x.html` and `<media id>/x.svg`; the composer kept three of each
  (`broadcasts`, `announcements`, `notifications`). 58 refusal cases, each red first.
  Not reproduced here, as before: the web server answering such a file as a page (the suite has no web server).
- **Production, read only, by the lead on 2026-10-05.** No `.html` file is on the public disk, and its 37 `.svg`
  files carry no script.
- **Decided.**
  - `extensions:` on every one, in lower case, naming what a real file of that field can be called:
    `jpeg,jpg,png,gif,webp` beside `mimes:jpeg,png,jpg,gif,webp`; `png,webp` on an icon, and `png,gif,webp` on
    the icon of a service edit (shorter than the icon's own `mimes:`, for the reason in the next bullet); the
    two rules that are a bare `image` (the donation link, a push notification) take
    `jpeg,jpg,png,gif,bmp,webp`, which is what `image` admits. No size limit and no accepted kind of file
    changed.
  - An icon's name list leaves out `ico` and `icns` (round 3). The icon rules' `mimes:` lists name `ico`, and
    `icns` on a service edit, and round 2 copied them into `extensions:` and into the sentence. But `image`
    stands beside `mimes:` on each of those rules and refuses a real icon file first: a real `.ico` sent to each
    of the six icon doors is told one thing, that the field "must be an image", with `app/` as it is on main
    and as it is here. So the sentence asked for a name no real icon file could pass under, and PNG bytes named
    `icon.ico` were kept on the public disk under that name. `ico` and `icns` are out of the name list and the
    sentence of the service icon (create and edit) and of the About page's mission and vision icons. Every
    `mimes:` list is exactly as it was, so no kind of bytes is newly refused or newly accepted. It narrows one
    thing a person could do: PNG bytes named `.ico` or `.icns` were accepted at every icon door on main (12 of
    12 cases, kept under those names) and are refused now until the file is renamed.
  - `bail` first on each of them and on the three rules of the entry above. A file that is not an image is told
    that once ("The avatar field must be an image.") and is no longer also told what type it must be and to
    rename it: a PDF sent as `notice.jpg` is still refused, so "rename the file" was advice that could not work.
    A file refused for its name alone reads its field's own sentence, for example "The avatar's file name must
    end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again."
  - The composer's feed channel borrows the announcement's rules, and its picture rule is the composer's own
    plus `required`. A picture the composer's rule has refused is no longer reported a second time with
    "Announcements feed:" in front of it. With no picture at all the feed's `required` still speaks.
  - `SaveMasjidAboutRequest` writes each field's rule whole. It used to join fragments held in variables, which
    the coverage test below cannot read together.
  - **`UploadFileNameCoverageTest`** reads every PHP file under `app/` (tokens, so a comment is not a rule) and
    fails when an upload rule written in one of the ordinary ways has no `extensions:` beside it. The ordinary
    ways are a rule string or a rule list with `image`, `file`, `mimes:`, `mimetypes:` or `dimensions:` in it,
    its names read as Laravel reads them (without regard to capitals, `_` or `-`), and the framework's fluent
    rules (`Rule::file()`, `Rule::imageFile()`, `Rule::dimensions()`, `File::types()`, `File::image()`,
    `File::default()`, `new File`, `new ImageFile`), under an import alias too. "Beside" is narrow: in the same
    string literal with its list written out, or a whole element of the same rule list; a pin in one branch of a
    ternary, in a concatenation, or with a list taken from a variable is reported as no pin. It is a control for
    those ways of writing a rule and not a proof that no upload is open: what it cannot see is listed under
    "Deliberately left", and a new upload still needs its own row in `PublicUploadFileNameDoorsTest`.
    The way out is `NAME_NEVER_PUBLIC` in that test: an entry says what the upload is and why the
    client's file name cannot reach a public address, and carries facts the test checks (the disk a config key
    names is not the public one; a line the storing code must still contain). Twelve entries today: the
    assistant's chat picture (kept under PHP's temporary name), the newsletter's block pictures (re-encoded,
    `<uuid>.<ext>`), the Studio draft logo and the flyer photo (private disk, random name), public form
    attachments and a form's own file question, class feed photos and videos, class handouts, a contact's
    credential document (all private disk, random name, signed-in download), and the roster spreadsheet at
    its two steps (never stored). Seen red with the pin removed from an admin's profile picture, from the
    donation link's picture and from the About mission icon, one at a time, and green again with each put back.
    In round 3 the scanner was put to each of the 32 lines under `app/` that carry a pin, with that one line's
    pin taken off: it reported a rule every time (32 of 32).
- **What a person meets that they did not before.** On these uploads too, a real image whose name ends in
  something else (`.jfif`, `.jpe`, a trailing dot, or no extension at all) is refused until it is renamed.
  The second review measured three more against main (34 + 21 + 29 cases). Round 3 measured them again, with
  the same counts, and a fourth that its own icon change adds, by sending the same real bytes through the same
  routes with `app/` as it is on main and as it is here:
  - an icon whose bytes are PNG or WebP but whose name ends `.jpg` or `.jpeg` (and `.gif`, except on the edited
    service icon, whose list has `gif`) is refused until it is renamed (36 cases kept on main, 34 of them
    refused here);
  - PNG bytes named `.bmp` are refused on the photo fields, except the donation link picture and the push
    notification picture, whose lists have `bmp` (23 kept on main, 21 of them refused here);
  - a name with a space after its extension (`photo.jpg `) is refused at every one of these uploads (29 kept
    on main, as `photo.jpg-`; all 29 refused here);
  - PNG bytes named `.ico` or `.icns` are refused on the icon fields (12 kept on main, all 12 refused here; the
    icon bullet above).

  A name such as `x.html.jpg` is accepted and kept as written at every photo door: 23 of the 29 doors of
  round 3, and three times through the composer when the feed and push are both ticked. The six icon doors,
  whose lists have no `jpg`, refuse that name and take `x.html.png` in the same way (`x.html.png` and
  `x.html.webp` are kept at every door). Round 4 sent the three names again with the four composer doors it
  added, by a probe in a private copy and not by a committed test: `x.html.jpg` kept at 27 of 33, the other
  two at all 33. That such a file is an image and not a page rests on the web server typing a file by its
  LAST extension, which is one more reason for the server-side backstop recommended under "Deliberately
  left". Nothing here was run against a web server.
- **The screens.** Each of the thirteen is sent by one form in the admin SPA, and each form puts what the server
  answered into its failure dialog (`getMessageFromObj` flattens a refusal's field messages; run on the exact
  refusal envelope, it returns the sentence). Nobody is left without a reason, so no screen was changed. Read
  from the views and not driven in a browser. Left as found: most of those dialogs are titled with axios's
  "Request failed with status code 422" above the server's sentence, and three forms (announcement, service,
  splash) leave the form after the failure dialog, so what was typed is lost.
- **Deliberately left.**
  - Files already stored are not scanned, renamed or moved.
  - A name the media library itself refuses after validation (`x.php.jpg`, a name over 255 bytes) still answers
    500 after the record is written, and a page UPDATE then loses its old background. It was so before this
    branch, and it is its own change.
  - The web server still serves whatever is on the public disk by its extension. A server-side backstop (nothing
    page-like under `/storage` is served as a page) is recommended to the owner as a separate, deliberate server
    change. It is the only thing that would cover a file that reached the disk some other way.
  - The coverage test reads rules, and only rules written in the ways it knows. Each of these passes it with a
    door open. Its own table (`whatTheScanCannotSee`, 25 rows after round 4) holds one of each that can be
    written as a snippet, which is all but the rule outside `app/`, and shows the scanner saying nothing
    against it; 23 of the rows also put real PNG bytes named `x.html` to Laravel's validator under that shape
    and see them accepted:
    - a rule with no file word in it at all (only `max:`, say);
    - the one word `image` or `file` standing alone as a rule where the scan does not expect rules: in a helper
      with no `rules` in its name, a constant, a property, or a validator made another way
      (`app('validator')->make(...)`); and, wherever it is written, in an arm of a `match` or among another
      call's arguments, alone or as one branch of a ternary there (`Rule::when($new, 'image')`). The scan takes
      the bare word for a rule only in a list beside a presence word or a rule with a colon, or in a method
      with `rules` in its name, an array assigned to a variable with `rules` in its name or a validate call.
      Even there it reads the word only as a list element or as the whole of a value: what a field is given,
      what is assigned or handed back (`return`, an arrow function), or one branch of a ternary or the
      right-hand side of `??` in one of those, with or without brackets round it. A method with `rules` in
      its name is not read through and through (round 4);
    - a rule that is not one piece of text: joined from two literals or from a constant, read from `config()`,
      made by `sprintf()`, or changed in a later statement;
    - the array form of a rule (`['mimes', 'jpg', 'png']`);
    - a custom rule object or a closure;
    - a pinned rule the request switches off (`exclude_if:` in front of it);
    - a rule outside `app/`;
    - an upload read with no rule at all, and a file that does not arrive as an upload (written from text the
      client sent, or fetched from an address);
    - a pinned upload whose stored name comes from another input;
    - whether a pinned list is a sensible one (`extensions:jpg,html` counts as pinned).
  - The door tests prove, for each door, that image bytes under a page-like name (`x.html`, `x.HTML`,
    `x.jpg.html`, `x.htm`, `x.xhtml`, `x.svg`, no extension at all) are refused with nothing stored, and that
    every kind of file an office may upload there is accepted under a lower-case and an upper-case name. The
    kinds are written in the test, door by door, and are not read from the rule. They do not prove that a list
    is not too wide beyond those page-like names (`jfif` added to a list leaves them green).
  - The scan from the other side that the second review proposed (every call under `app/` that puts an upload
    on a disk, held to a list that names the rule pinning it) was not built. It is what would cover a rule with
    no file word in it and an upload with no rule at all.
  - A real `.ico` or `.icns` file is still refused as "not an image", as it was before this branch: `image`
    admits neither, whatever `mimes:` beside it names. Taking an icon file would be its own change.
- **After the second review (round 3, the same day).** Both reviewers found no upload open at the round-2 head.
  What changed is what the tests can show and what this entry claims.
  - The scanner. It had one wrong answer (a list with `extensions:` in one branch of a ternary counted as
    pinned) and blind spots. Of the eight rule shapes the review walked through a real route with `x.html`
    stored and the test green, it now reports five (capitals; the pin in a ternary; `Rule::dimensions()`; the
    fluent rule under an import alias; `File::default()`) and still says nothing against three (no file word;
    the one word from a helper not named for rules; a list that names a page), which are on the list above.
    It is held by tables of source snippets and not by prose: at the end of round 3, 44 it must report, 16 it
    must accept as pinned, 10 that are not rules, and the 23 it is known to miss. Under `app/` it reads what
    it read before: 58 answers, 33 of them pinned.
  - The door tests. Each of the 29 doors sent one ordinary name; each now sends every kind it takes under a
    lower-case and an upper-case name (260 cases), and five page-like names where it sent two (145 cases). The
    four doors of the page and the flyer send every kind they take in the same way (44 cases; two of the four
    had one such name each). Shown by mutation in a private copy, each put back afterwards: `webp` taken off a
    new announcement's list turned 4 cases red (the new announcement, and the composer, whose feed borrows that
    rule); the gallery's `images.*` list cut to `jpeg,jpg` turned 6 red; `png` taken off the edited service
    icon's list turned 2 red. The first two left every test green before this round, as the review showed.
  - The icons, as in the icon bullet under "Decided".
- **After the independent check (round 4, the same day).** The check found no upload open at the round-3 head,
  and four places where the tests or this entry said more than they showed. Only tests and the record changed
  in this round; nothing under `app/` did.
  - The composer's own picture rule had no row in which it was the only guard. The composer door ticks the
    announcements feed, and the feed's borrowed announcement rule refuses a bad name whatever the composer's
    own list says: the check showed that a trailing comma in that list (an empty name, so a file with no
    extension passes) left all three upload test files green. The composer is now five doors: the feed with
    push, as before, and push, the signage board, email and a text message each alone (the test organisation
    is given the CRM for the last two, which read the contact directory). A test holds where each keeps the
    picture and that it is kept nowhere else: `broadcasts` always, `notifications` as well when push is
    ticked, `announcements` as well when the feed is. Shown by mutation of that one list in a private copy,
    each put back afterwards: the trailing comma turned 4 cases red (a file named `x` kept by each of the
    four new doors); `html` added turned 18 red; `webp` taken off turned 10 red.
  - The scanner read the one word `image` or `file` in a rules method only as a list element, as the whole of
    a field's rule or of one branch of a ternary that was the field's rule, or after `=` with nothing else.
    Seven shapes the check found there were not reported: a ternary in brackets, the right-hand side of `??`,
    a ternary assigned to a variable, `return 'image';`, a returned ternary, and the word handed back by an
    arrow function, standing as a field's rule or handed to `Rule::forEach()`. The scanner now reads the word
    as the whole of a value (the list under "Deliberately left" says what that is and what is still unseen)
    and reports all seven. Six of them were written into a real request in a private copy: each time the
    coverage test turned red and named the line. Under `app/` its answers are the same, answer for answer:
    58, 33 of them pinned. Its tables now hold 60 snippets it must report, 16 it must accept as pinned, 16
    that are not rules and 25 it is known to miss.
  - `x.htm` and `x.xhtml`, which a web server also types as pages, are sent to every door (231 refusal cases
    at the 33 doors, beside 300 accepted names; 8 more refusals at the page and the flyer). `htm` added to a
    new announcement's list turned 1 case red; the check had shown it leaving the door tests green.
  - The count for `x.html.jpg` under "What a person meets" said all 29 doors. It is corrected there.

## 2026-10-05 — An office can attach a PDF to a web page: uploaded on its own, linked from a field a section already has (branch `feat/page-documents`)

- **Asked.** An office tried to put three documents on one of its public pages (a curriculum, a calendar, a
  schedule) by uploading them into a programme's image box. That box takes images, and no section took a
  document: section uploads are images, and an MP4 for the Video section.
- **Decided.** Upload first, no new section type. `POST {masjid}/pages/documents` (in the group that guards
  saving a page) stores ONE PDF on the organisation (media collection `page_documents`, public disk) and answers
  `{url, name, size}`. "Upload a PDF" under the link field of Link Buttons, Programs & Curriculum and Call to
  Action sends the file at once and writes the address into that field, as if it had been pasted. The website
  and the apps are not changed. Rule: `.claude/rules/section-types.md`, "A PDF is not a section upload".
  - **Let in:** a PDF by its bytes (`mimetypes:application/pdf`), by its first five bytes (`%PDF-`), by its name
    (`extensions:pdf`), at most 25 MB (the media library's own ceiling). The type a browser declares is not read.
  - **Written:** under a name the server makes (a slug of the client's name, then `.pdf`). The client's name
    never reaches the disk; it is kept on the media row for people to read.
  - **Answered:** an absolute address from the public disk's configured `url`, never from the request.
  - **Told to the office, where it acts:** the file is public as soon as it is uploaded; it stays online while a
    saved section links to it; how to take it offline; what to do about a file uploaded by mistake; and each
    refusal with what to do about it.
  - **A label is never a raw address:** a blank Link Buttons label and icon, and a blank programme Link Text, are
    filled from the file's name. Words the office chose are left alone.
- **A document is deleted when the last saved section that links it lets go.** On a section's save (either
  route) and on its deletion from the library, `PageDocuments::forgetUnlinked()` deletes the organisation's own
  page documents whose address that write removed, unless another section of the organisation still carries it.
  Matched by path (not host), resolved by media id AND stored name through `Masjid::pageDocuments()`, never
  failing the save, one warning line by ids alone per file. **Kept on purpose:** a document whose section was
  only taken off a page or whose page was deleted, and one that was uploaded and never saved. Nothing is
  scheduled: a sweeper would delete public files by inference, which is how media was lost here before.
- **Rejected.**
  - A "Documents" section type first. It needs a renderer component in the website's repository before a
    visitor sees anything, the palette is not gated on the renderer, and it adds no reach: the three link
    fields already carry an address to every visitor.
  - Riding the section's save (the Video precedent). The file would have no address until Save, so the live
    preview could not open it; queued files are keyed by list position and Link Buttons moves rows; and a late
    refusal is a 500 on a half-saved section.
  - A scheduled deleter for uploads that were never saved, and a forced download (`Content-Disposition`), which
    would stop the document opening in the browser.
- **Decided here, the owner did not say** (the design's four questions, taken at their defaults by the lead):
  documents are not in the apps (the apps show no pages); a document a save stops linking is deleted then;
  never-saved uploads are kept; the address is on the platform's own host, as every section image's is.
- **Found while building, where the code differed from the design.**
  1. **`%PDF-` at byte 0 is the guard, not a belt.** PHP 8.3's type sniffer reports a web page followed by a
     PDF as `application/pdf`, so `mimetypes` alone lets that file in. The first-five-bytes check refuses it
     (`PageDocumentUploadTest`, "a web page with a PDF after it").
  2. **A path under `/storage` with no file behind it answered the admin screen with a 200**, from the SPA's
     catch-all route, so a removed document would still "open". `routes/web.php` now answers such a path 404,
     ahead of the catch-all. The route's parameter is deliberately not `path`: when this was written the
     framework registered `storage/{path}` (signed links to the private disk) after the catch-all, where a GET
     had never reached it, and a route of the same address is replaced by it in the earlier place. That
     framework route is switched off in the same release (the entry above, `'serve' => false`); the name stays
     different in case it is ever switched back on. This changes the answer for EVERY missing file under
     `/storage`, not only documents. Checked with routes cached and uncached.
  3. **A Teacher is answered 401, not 403:** the `admin` middleware has always answered 401 for a signed-in
     account that is not an administrator. Pinned as it is.
  4. **A public disk with no absolute `url` refuses the upload** (500, nothing kept) rather than answering a
     root-relative address the website would look for on itself. `Storage::fake('public')` drops the `url`, so
     the tests hand the fake one.
  5. **The page tool sends a `.pdf` the computer gave no type** (the server reads the bytes); it refuses a
     file with any other type. One more server sentence than the design listed: a wrong NAME is told apart
     from wrong bytes.
- **Open, not built.**
  1. Save is not held while a PDF uploads (the modal is unchanged). A section saved before the upload ends is
     saved without the address, and the file stays online, linked from nowhere.
  2. An address pasted somewhere that is not a section (a menu link, an announcement) does not keep its file
     online.
  3. Two saves in the same instant, one removing a document's last link and one adding the address to another
     section, are not serialised.
  4. No rate limit on the upload, as on no other admin upload; no list of documents; no report of unlinked ones.
  5. Not run by this change, and release gates in the design: the response headers of a stored PDF on staging
     and production, what the edge cache does after a delete, the request-size ceiling of each web server, and
     the walk through the real screens. See ASSUMPTIONS.md, "A PDF attached to a web page".
- **Fix round after three reviews, the same day** (the upload, the deletion on save, the editors). All three
  said "ship after fixes"; none found a way to store or serve anything but a PDF. Of "Open, not built" above,
  item 1 (Save is not held) and the rate limit of item 4 are now built; the rest stands.
  - **The deletion on save, four corrections.**
    1. **A save from an out-of-date editor deletes nothing.** Two tabs hold one section linking X; one replaces
       X with Y and saves (X is deleted, as designed); the other, still showing X, saves, and Y was deleted
       too, leaving the page linking a file that was gone. When the content after a save brings in an address
       of page-document shape that the section did not have before, and none of the organisation's documents
       is behind it, nothing is deleted on that save and one warning line names what was kept. Chosen over
       refusing the stale save: no client sends a version today. So the old copy's link is still stored and
       still dead; what is saved is the current file. Not covered: a stale save that only DROPS a document
       the other tab added (nothing new comes in, so it reads as an ordinary removal). The guard can only
       keep: an address of that shape with no document of this organisation behind it (another site's, another
       organisation's) put in place of an own document also stops that deletion, and no later save reaches it.
    2. **"Still linked" sees through spellings.** It is asked of the address as written, of the document's
       own path, and of both percent-decoded once (a viewer's link that carries the address encoded keeps the
       file). The address pattern refuses an id with a leading zero, so `/storage/03/x.pdf` is nobody's
       document and can never start a deletion of document 3. What STARTS a deletion was not widened: a link
       that only ever carried the address encoded keeps a file and, when it goes, leaves it online. An address
       encoded twice is not found.
    3. **A file is called deleted only when the disk says it is gone.** The media library deletes the row
       first and a disk that refuses a removal raises nothing, so the disk is asked afterwards; a file it
       still holds is logged as NOT removed and still online. Its row is gone by then, so no later save
       retries it: the line is the record.
    4. **The cleanup runs whenever the content was written.** Both `update` actions call it in a `finally`
       that starts after the section's row is updated, compared with what is stored. A save that failed in
       its image step (answered 500) has already lost the address, and no later save could find the file. So
       a save the office was told failed can now delete a file: consistent with what is stored, not with the
       answer.
  - **The upload.** The store runs in a transaction, so a copy that throws (the file's directory cannot be
    made) leaves no `page_documents` row; the cost is that anything throwing AFTER the file is written would
    leave a file with no row. A named limiter, `page-documents`: 30 requests an hour for each signed-in user,
    with a sentence in the 429 that the screen shows. Laravel runs it straight after authentication, ahead of
    the tenant and capability gates and of the upload's own rule, so a refused request is counted too.
  - **The editors.**
    1. **Save waits for an upload.** `SectionFormModal` provides a count the control raises and lowers
       (cleared if the control unmounts). While it is above zero Create/Update Section is off, one line says
       why, Enter in a field submits nothing, and Cancel and the close button ask first.
    2. **Only true sentences.** What may be said about taking a file offline depends on whether the SAVED
       section links it, and the modal provides that (read once, when it opens). A saved document is told
       "clear the address and save"; one uploaded since is told that clearing or replacing it now leaves it
       online, and how to take it offline. When an upload replaces an unsaved file the control shows that
       file's address, which is then in no field; that notice lives with the control, so it goes when rows
       move.
    3. **Said where the office acts.** Beside Save, for each saved document the content no longer links, in
       any field of any editor: "This file is taken offline when you save, unless another saved section
       still links it."
    4. **Sentences and names.** The page tool's two refusals for a wrong file are now the server's own, word
       for word, so both say what to do; a file a little over the limit reads "a little over 25 MB"; a label
       made from a file's name reads dashes and underscores as spaces and keeps a dash between two digits;
       the "Uploaded." note says which blank fields were filled; each row's button and Open link carry the
       row's label in their accessible name; the button is `aria-disabled` while it uploads, never
       `disabled`, so the keyboard's focus stays on it.
- **Limits that stay, confirmed by the reviews.**
  1. Only a SECTION of the SAME organisation keeps a file online. A link from an announcement, a menu, a
     section's settings or another organisation's page does not: when the owning organisation's last section
     lets go, the file is deleted and that link is dead.
  2. An upload that was never saved is kept, and no screen lists such files. The hourly limit bounds how fast
     they can be made, not how many there are.
  3. There is no ceiling for an organisation beyond the hourly limit for each user.
  4. `GET /storage/{missing}` answers 404 for EVERY missing file under `/storage`, on every hostname this
     deployment answers to, not only for page documents. Why: the web server hands any path with no file
     behind it to the application, and the admin SPA's catch-all answered each of them with a 200 and the
     sign-in screen, so a removed file still "opened" for a visitor, a link checker and a search engine.
- **This branch ships with or after `fix/pin-upload-file-names`.** The upload review found, outside this
  branch's diff, that older public image uploads check a file's bytes and not its name, so image bytes named
  `.html` can be stored under that name on the origin where the admin screens keep their sign-in token. Page
  documents neither open nor widen that (they only ever write `.pdf` names), but `StorePageDocumentRequest`
  says every upload rule beside it pins the name, which is true only once that branch has landed. Both
  branches edit this file and `.claude/rules/section-types.md`: keep both additions when the second merges.
- **Fix round 2, the same day** (after the re-review of the first fix round: the server lens said "ship", the
  editors lens "ship after fixes" with one major). Additions only; what is above stands unless a line here
  says it changed.
  - **An edit abandoned with Cancel is gone (the major).** Link Buttons bound its fields onto the very link
    objects the page list holds (it copied the list, not the links), and Cancel does not reload that list. So
    an upload that was cancelled read as SAVED the next time the section was opened ("clear the address and
    save", then "taken offline when you save", both false), and a saved address that was cleared and cancelled
    went out, cleared, with the next save, and the server deleted a document the office believed it had kept.
    Fixed in two places, each pinned on its own:
    1. `SectionFormModal`'s form is a deep copy of the section's content, made when the modal opens (through
       JSON: the object is reactive, and `structuredClone` throws on a proxy). A new section's default content
       is copied the same way; it was the store's own object, handed to every new section of that type. No
       editor can reach the object the list holds, and `sectionSavedDocuments` is read from that untouched
       original. This replaces "read once, when it opens" above: it is now the list's own object that stays the
       saved content, however often the section is opened and cancelled.
    2. Ten editors shared rows with the content they were handed and now copy every row, when they open and in
       their watch: Link Buttons, Admissions & Tuition (fees, payment plans, steps), Carousel, Grid Cards,
       Impact Stats, Mission & Vision, Providers, Services & Eligibility, Staff Directory, Stats. (Mission &
       Vision and Stats shared the list itself.) Programs already copied each programme. The other twenty-one
       editors bind only top-level fields of an object they build and hold no rows (32 editors: ten fixed,
       Programs already safe, twenty-one with no rows). This was a defect in every
       one of the ten, not only where a PDF can be uploaded: any abandoned row edit stayed in the list until
       the page was reloaded.
  - **A new section's type and mode wait for an upload.** Changing the Section Type, or switching to Attach
    Existing, takes the editor away exactly as Cancel does and asked nothing. Both are held (disabled) while
    the count of uploads is above zero, as Save is.
  - **What Cancel says, and when it asks.** The question about an upload in flight ended "wait, then close",
    which leaves the same file online; it says "then save". When the form holds a page document the saved
    section does not (uploaded, or put in by hand), Cancel and the close button ask once: the file's name,
    that it stays online if the section is not saved, and its address. It is read from the content when the
    office closes (`sectionDocumentsNotSaved`); no new state. It says "linked from nowhere UNLESS another
    saved section links it", because the page tool cannot know that of an address put in by hand (PD-10). The
    note under an unsaved file names closing as well as clearing and replacing.
  - **A label is said to come from the file's name only when it did.** A name with nothing to read gets the
    editor's own word, and the note says what the field was filled in as.
  - **THE OUT-OF-DATE RULE CHANGED (item 1 of the first fix round).** It was: a save is out of date when it
    brings in ANY address of the page-document shape with none of the organisation's documents behind it. That
    is far more than an old copy can hold, and keeping is not free: the kept document has left the section's
    content, so no later save has it in its "before", and it stays public for good while the page tool said
    "taken offline when you save". It fired for an ordinary replace by another organisation's document, by
    the organisation's own PDF in another collection, and by another site's address. It is now: the address is
    written as OURS (on the public disk's host, on the host the request came in on, or with no host) AND no
    media row at all has that id and file name (the document it named has been deleted). Anything else is an
    ordinary replace and what it drops is deleted. The test that pinned the old behaviour for another site's
    address is rewritten to expect the deletion. Still kept, by this rule: one of our own addresses whose
    document is gone, however it got there (typed, an id too large to be a row, or carried percent-encoded
    inside a viewer's link). Still not covered, as before: a stale save that only DROPS a document.
  - **"Still linked" reads the spellings a browser resolves.** Besides the address as written and
    percent-decoded once, each string is read with backslashes and JSON-escaped slashes as slashes, a doubled
    slash as one, and `.` and `..` segments resolved. For the KEEPING side only: what starts a deletion is
    still the address as written, and the out-of-date check reads the address as it was given. The page
    tool's "taken offline when you save" follows the same reading (not asked for by the brief; without it the
    footer would promise a deletion the server no longer makes). Not seen, still: an address encoded twice, a
    path or `.PDF` in upper case, HTML-entity slashes (PD-17). The doubled slash is a working link only where
    the web server merges slashes, which is its default and was not checked on a server.
  - **The upload limit counts REQUESTS, for each person in each organisation.** Thirty an hour, keyed by the
    signed-in user and the organisation in the address, read as a number as the tenant gate reads it (the
    route does not pin the spelling, so `7`, `07` and `7.0` are one count). It runs ahead of the tenant and
    capability gates and of the upload's own rule, so it counts every signed-in request to that address:
    stored, refused by the rule (422), or refused because the organisation is not the person's (403). The
    sentence is "You have tried to upload a lot of documents in the last hour", true of all three. What this
    gives up: a sign-in that may act for every organisation has thirty for EACH, so the ceiling on one stolen
    token of that kind is thirty times the number of organisations, not thirty.
  - **A disk that cannot be asked** has its own log line (the file could not be checked and may still be
    online), in place of "the disk kept the file".
- **Limits that stay after round 2** (each confirmed, none new behaviour).
  1. A stalled upload holds Save, the Section Type and the mode, with no way out but Cancel, then Close
     Anyway, which discards every edit in the modal. No request here has a timeout.
  2. The notice about a replaced, never-saved file lives in the control: it goes when rows move, and it stays
     under an empty control if the field is cleared by hand.
  3. A size is shown in the unit of the limit (25 MB is 25 x 1024 x 1024 bytes), so a computer that counts in
     thousands shows a slightly larger number for the same file.
  4. Anything that throws after the file is copied and before the row is committed leaves a file with no row
     (PD-20).
  5. In a NEW section, changing the Section Type after an upload has ENDED replaces the content, the address
     with it, and asks nothing; Cancel then asks nothing either, since the form no longer holds it. (Driven
     through the real modal.) And Attach Section, pressed after an upload in the Create New form, closes
     without the question (read from the code, not run). Both leave the file online, linked from nowhere.
  6. Clearing an unsaved file's address by hand and then closing asks nothing: the note under the field said
     beforehand that clearing leaves it online.
- **Fix round 3, the same day** (after the check of round 2: the server lens said "ship after fixes", the
  page-tool lens "ship"; nothing major). Additions only; what is above stands unless a line here says it
  changed. Nothing was run against a server and no browser was driven.
  - **The cleanup takes one pass over a text, in both of its readers, and bounds the one cost that is left.**
    A section's text has no size limit, and the cleanup reads every string of the saved section on every
    save, and every other section's when a save drops a document. Two readers cost the SQUARE of what an
    administrator can write, and a third thing grew faster than the text:
    1. `resolved()` (round 2's spelling reader) removed one `/name/..` per pass over the whole text. 40,000
       steps (195 KB) took 11.2 s on the server and 9.1 s in the page tool; they take 0.011 s and 0.008 s.
       The text is cut at its slashes once and its segments walked once.
    2. The out-of-date check read ALL the text in front of every address a save brought in. Not in the
       check's findings: it was found by timing the other readers after the first was fixed. 5,000 addresses
       of another site in one text (250 KB) took 15 s alone and 19 s through the route; the check takes
       under 0.01 s, and the route's whole test 0.07 s. It now reads back from each address to the nearest
       character a host cannot hold, to a limit of 2,048 characters, and gives the answer the old pattern
       gave for every text within that (compared on 200,000 generated texts with no difference; the old
       pattern's `$` read past one line feed before the path, and so does this). PAST THE LIMIT IT THROWS:
       the caller logs "were not checked" and deletes nothing. Chosen over answering "not ours" unread,
       which would let a deletion through, and over a larger limit, which would let the cost grow with it.
       What it gives up is PD-28: an address that follows more than 2,048 characters with no space, quote,
       tag, `?`, `#`, `=` or `&` among them is not judged, and the document that save drops stays online.
    3. Every address a save LETS GO OF is looked for in all the section holds now, in every spelling, and
       then asked of the database. Also found by timing, not in the check's findings: 10,000 addresses let
       go of for a text as long (615 KB) took 2.0 s, and 20,000 (1.2 MB) 6.4 s. An address that is still
       there as it was written is now found by one pass over the new content, and only the rest are looked
       for one by one; A SAVE THAT LETS GO OF MORE THAN 1,000 AT ONCE THROWS, is logged "were not checked",
       and deletes nothing. At 1,000 the save takes 0.1 to 0.2 s for a text of up to 615 KB (1.2 s for
       5 MB); past it, 0.04 s. No section links a thousand documents (thirty an hour for each person). The
       limit is on what one save lets go of, not on what a section holds.
    Not changed, and linear in the text: one database question for each address a save brings in that is
    written as ours and has a row, repeated if the address is. One real address written 40,000 times in a
    save that drops another document (2 MB) is 40,000 questions and took 2.1 s on the test database. Asking
    once for each distinct address would end that; it is left as found and written down here.
  - **Round 2's three unpinned readings are pinned** (the percent-decoded reading of the new content in the
    out-of-date check; the percent-decoded reading resolved as well as the text as written; more than one
    `..` step). Tests only; each fails with its reading taken out.
  - **"Still linked" keeps a file through four more spellings**, for the keeping side only: a `..` that
    steps back over a segment holding a space, an apostrophe, a quote or an angle bracket, and a tab, a
    carriage return or a line feed inside the address, which a browser drops. DECIDED HERE: the `..` does
    not step back over a segment holding `?` or `#`. The brief said "any segment"; a browser's path has
    ended at either, so such an address is no link to the file, and the check's own suggestion excluded
    them. Thirty-six spellings were run through the real routes in three arrangements, beside a browser's
    own parser (Node's): every one a browser resolves to the file was kept, none started a deletion, and
    the page tool read each as the server did. What is STILL not seen is listed whole in PD-17, the
    docblock and the rule file: a character written as an HTML character reference, an address encoded
    twice, another letter case, a link that does not hold the path at all.
  - **"Is the document gone?" is asked of the public disk only.** The out-of-date check asked whether ANY
    media row had the number and file name an address carried, and its answer shows in what happens to the
    administrator's own document. So one upload and two saves told an administrator whether another
    organisation's PRIVATE file had a given number and name. It asks only of media on the disk page
    documents live on. A private row of that number and name now reads as "gone": the save is out of
    date, and the document is kept, exactly as for a number and name no row has.
  - **A host is compared as a browser takes it.** Our own host written with the port its scheme uses anyway
    (`:443`, `:80`) or with a closing dot did not match itself, so an old copy holding such an address
    deleted the current document. Both are taken off before comparing, on the written side and on ours.
    Any other port is another site. (Compared again with the old pattern plus that rule on 300,000
    generated texts: two differed, both with a digit or a dash straight in front of `http:`, which is now
    read as no scheme, so either port is taken off.) PD-16 now says what "no host" means, and that after
    a staging data refresh or a change of `APP_URL` stored addresses are on a host that is neither the
    disk's nor the request's, so an old copy holding one is an ordinary replace there.
  - **A delete that was cancelled is "NOT deleted".** A listener that answers false leaves the row and the
    file, and the line read "its record is gone". Anything but a plain yes now gets the line of a delete
    that threw, by ids alone, and the disk is not asked.
  - **The page tool's reading no longer slows typing.** With a picture chosen and not yet saved the form
    holds a `data:` URL, and the footer read every megabyte of it on each edit: 12 ms for each MB at the
    head of round 2 (98 ms for 8 MB), 0.00 ms now. A string that starts `data:` is not read, and a text is
    copied only when its percent-decoded or resolved reading differs. Measured in Node, not in a browser.
  - **A PDF uploaded in the editor stays on the screen when the form lets go of it.** ONE mechanism, in
    the modal: it keeps the list of what was uploaded while it is open (the control adds each file as its
    upload ends, whether or not the control is still there), and the footer names, beside Save, each one
    the form no longer holds: its name, that it is online and in no saved section, its address, and how
    to take it offline. It stays through row moves, Remove, a type change and the Attach form, and goes
    when the address is put back. The question on Cancel and the close button names these files too, once
    each, in words true of them (saving would not link them either). Attach Section asks the same question
    before it closes the modal; its button reads "Attach Anyway", and "Keep Editing" attaches nothing.
    THIS CHANGES limits 2, 5 and 6 of round 2 above, which no longer hold: the notice about a replaced,
    never-saved file is the footer's and does not go when rows move; a type change after an upload and
    Attach Section are no longer silent; and a file whose address was cleared by hand is named in the
    footer and asked about on closing. "The form" is what a save from here would send: while Attach
    Existing is chosen that is nothing, so a file in the set-aside Create New form is named until the
    office switches back.
  - **Smaller corrections.** The modal tests' mount helper hands the modal a reactive section whenever it
    is given a plain one (so PD-24 is true as written); the row editors' tests read back what was typed
    into every field after the rebuilds (PD-25); the upload test that the address comes from configuration
    and never from the request's host posts to a full address on the other host, where it sent a `Host`
    header that a test does not turn into the request's host; "twenty-two editors" above is twenty-one;
    PD-10 names the close question.
- **Limits that stay after round 3** (this list replaces "Limits that stay after round 2"; 1, 3 and 4 there
  stand as they are).
  1. A stalled upload holds Save, the Section Type and the mode, with no way out but Cancel, then Close
     Anyway, which discards every edit in the modal. No request here has a timeout.
  2. A size is shown in the unit of the limit (25 MB is 25 x 1024 x 1024 bytes), so a computer that counts in
     thousands shows a slightly larger number for the same file.
  3. Anything that throws after the file is copied and before the row is committed leaves a file with no row
     (PD-20).
  4. The modal's list of uploads lives as long as the modal. Once it is closed (Close Anyway, Attach Anyway,
     or a save made while the footer names a file) nothing lists a file that no saved section links; no
     screen does (limit 2 of the reviews' list above).
  5. A PDF's address put in BY HAND, never saved, and then replaced by an upload or taken out is not named
     afterwards: it was not uploaded here, the page tool cannot know whose file it is (PD-10), and the note
     under the field said beforehand that letting go of it leaves it online. Round 2's control named it
     after a replace; the footer does not.
  6. A save whose content holds an address after more than 2,048 characters of unbroken text, or that lets
     go of more than a thousand addresses at once, has its cleanup given up, out loud (PD-28).
  7. The footer's new line, the Attach question and the longer close question were mounted in the test
     harness and built; they were not seen in a browser (PD-23, PD-15).
