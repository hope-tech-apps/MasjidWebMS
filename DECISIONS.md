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
