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
  so a double tap or a second tab cannot flip the answer back. No notification.
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
