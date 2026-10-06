=== .claude/backend-recon.md ===
commit: cf7bc9b54af6bd04ed1038cf6139de3a720e38ca
platform: backend
scope: . — Laravel platform, background jobs, operational artifacts, Python cutout helper
generated: 2026-10-05 UTC
dirty: 1 uncommitted path — CLAUDE.md; clean at initial inspection
mode: full
kit: /Users/moneebsayed/.claude/engineering-excellence

> **This recon stops at cf7bc9b5.** Two ships reached `main` after it (984885cc and 95e9e70a,
> 2026-10-05) and nothing below describes them: the private disk no longer serves files
> (`config/filesystems.php`), `GET /storage/{missing}` answers 404 ahead of the SPA catch-all
> (`routes/web.php`), page documents (`PageDocumentsController`, `app/Support/PageDocuments.php`),
> file-name rules on every public picture upload, and nginx answering `/storage` from an
> allowlist (`deploy/nginx/manara-storage.conf`). That list is from the shipping session's note
> and the changed-file list (98 files), not from a re-read. Read those areas at `HEAD`.

# Recon: MasjidWebMS (Manara), backend

## Evidence and scope

This report maps the backend at the stamped commit. Inspection was read-only: source, configuration, lockfiles, tests, deployment artifacts and git metadata. No dependencies were installed, application builds or tests executed, or network commands run. Runtime behavior, production configuration and deployed infrastructure are **not verified**.

`CLAUDE.md` changed during inspection. Its citations below refer to **the committed version**, read through `git show HEAD:CLAUDE.md`, rather than the concurrent edit. Other cited files were inspected in this worktree. The report is returned for saving; this session’s filesystem permissions prevented writing `.claude/backend-recon.md`.

Searches and counts excluded vendored, generated and build trees. File censuses used pruned tracked paths; route counts additionally removed comments. Counts below describe source declarations or files, never executed routes, test cases or passing checks.

## Stacks

The primary backend is PHP/Laravel. Composer maps `App\` into `app/`, with factories and seeders under `database/`; the frontend manifest describes a Vue application built by Vite. These are one Laravel application and its SPA asset pipeline, rather than evidence of a separate Node backend. (`composer.json:37`, `package.json:5`, `package.json:40`)

A second execution stack exists: a Python image-processing subprocess using NumPy, ONNX Runtime and Pillow. It loads `/opt/cutout/models/u2netp.onnx`; PHP invokes the configured interpreter and script through Symfony Process. It is a helper, not an HTTP service. PHP and Python backend addenda were loaded. (`scripts/cutout/cutout.py:24`, `scripts/cutout/cutout.py:28`, `app/Services/Flyer/ImageCutout.php:76`)

The public renderer is an external integration configured through renderer origins and a shared secret. Its implementation is outside this worktree and **not verified**. An external Supabase export is also configured, but that does not establish a Supabase backend owned by this repository. (`config/services.php:310`, `config/services.php:338`)

## Binding instructions

The committed root instructions establish application-layer tenancy, three organisation verticals, and mandatory tenant-scoping tests. They require server-derived `masjid_id` for scoped CRM models. Their Laravel 11 statement is stale against both manifest and lockfile. (`CLAUDE.md:3`, `CLAUDE.md:13`, `CLAUDE.md:362`)

The applicable conventions have concrete enforcement counterparts:

- Scoped CRM models use `BelongsToMasjid`; bound queries filter automatically and creation overrides supplied tenant IDs. Explicit bypasses remain available. (`.claude/rules/tenant-scoping.md:13`, `app/Models/Concerns/BelongsToMasjid.php:43`)
- Staff Sanctum authentication pins the `users` provider, and Spatie permissions use the model’s explicit `web` guard. These settings must remain coordinated. (`.claude/rules/auth-permissions.md:12`, `config/auth.php:91`, `app/Models/User.php:88`)
- Payments use Connect Standard/direct charges and verified webhooks as settlement authority. These are recorded design constraints, not choices reopened by this recon. (`.claude/rules/stripe-payments.md:23`, `.claude/rules/stripe-payments.md:42`)
- Private uploads use a private disk and ownership-chain downloads; durable URLs use `SiteUrl`. (`.claude/rules/private-uploads.md:29`, `.claude/rules/generated-urls.md:16`)
- Listener discovery is the default. The tenant-reset listener is the deliberate explicit registration exception, with duplicate-registration tests. (`.claude/rules/events-listeners.md:27`, `app/Providers/AppServiceProvider.php:105`, `tests/Feature/ListenerRegistrationTest.php:49`)

`tests/CLAUDE.md` adds authentication, fake-disk and host-header test conventions. Its external coordination-file reference and machine-availability statements are documentation claims, **not verified** operational facts. `STATE.md` and `PLAN.md` were absent from this worktree; recorded decisions and assumptions were inspected selectively. (`tests/CLAUDE.md:3`, `tests/CLAUDE.md:13`, `tests/CLAUDE.md:35`, `tests/CLAUDE.md:71`)

## Shape and entry

Manara’s tenant root remains `Masjid`, with `masjid`, `school` and `community` types defined in code. The naming is shared across verticals; the rules explicitly describe verticals as configuration rather than forks. (`app/Models/Masjid.php:28`, `.claude/rules/verticals.md:16`)

The pruned tracked-source census found 197 controller files, 143 model files, 147 service files, eight job files and 280 migration files. These measure directory size, not independently deployed modules. Representative entry points show controller/service/model organisation and Laravel migration ownership. (`app/Http/Controllers/AdminDashboard/ContactsController.php:26`, `app/Services/Stripe/DonationService.php:38`, `database/migrations/2026_07_11_000000_create_contacts_table.php:16`)

HTTP starts at `public/index.php`, loads Composer and passes a captured request to Laravel. CLI commands start through `artisan`. `bootstrap/app.php` registers web, API, console and channel files, `/up`, and additional admin, family, teacher and lunch route files beneath `/api`. (`public/index.php:13`, `artisan:9`, `bootstrap/app.php:31`)

The web root renders the SPA or redirects a mapped portal hostname to `/portal`. A catch-all serves the SPA for paths excluding `api`; therefore a successful web-page response alone does not establish a dedicated server route. (`routes/web.php:130`, `routes/web.php:136`)

## Toolchain and floor

| Axis | Verified declaration or resolution | Authority |
|---|---|---|
| PHP | `^8.2`; no Composer platform override in the inspected configuration | Manifest floor; CI selects PHP 8.3. (`composer.json:12`, `composer.json:75`, `.github/workflows/tests.yml:28`) |
| Laravel | Manifest `^12.0`; lock installs **12.64.0** | Lockfile wins over root documentation. (`composer.json:16`, `composer.lock:1536`) |
| Sanctum | **4.3.3** | Lockfile. (`composer.lock:1817`) |
| Spatie permission / media library | **6.25.0 / 11.23.3** | Lockfile. (`composer.lock:5041`, `composer.lock:4870`) |
| Stripe SDK | **16.6.0** | Lockfile. (`composer.lock:5186`) |
| Stripe API | **2024-06-20** | Shared client construction pins the API separately from the SDK. (`app/Providers/AppServiceProvider.php:54`) |
| Anthropic / Resend / Pusher SDKs | **0.7.0 / 1.6.0 / 7.2.8** | Lockfile; installation does not prove active use. (`composer.lock:11`, `composer.lock:4529`, `composer.lock:4271`) |
| Pest / PHPUnit / Pint | **3.8.7 / 11.5.56 / 1.29.3** | Development lock entries. (`composer.lock:8949`, `composer.lock:10035`, `composer.lock:8639`) |
| Node | CI **22**; `.nvmrc` absent | CI is the inspected runtime authority. (`.github/workflows/tests.yml:33`) |
| Vite / Vue / TypeScript / Pinia | **6.4.2 / 3.5.13 / 5.7.3 / 2.3.1** | npm lockfile wins over manifest ranges. (`package-lock.json:4664`, `package-lock.json:4780`, `package-lock.json:4578`, `package-lock.json:3738`) |
| MySQL | CI image **8.4** | CI configuration is verified; deployed engine is not. (`.github/workflows/tests.yml:142`) |
| Python helper | Provisioner defaults pin ONNX Runtime **1.28.0**, Pillow **12.3.0**, NumPy **2.5.1** | Provisioning script; installed interpreter and packages are not verified. (`scripts/provision-cutout.sh:53`) |

Vite’s dependency engine accepts Node 18, 20 or 22+, but that is not the application’s tested runtime matrix. The SPA test command uses experimental TypeScript stripping, and CI selects Node 22. (`package-lock.json:4681`, `package.json:8`, `.github/workflows/tests.yml:35`)

Root Composer requirements do not explicitly list PHP extensions. CI installs SQLite/MySQL drivers and named supporting extensions; transitive requirements also exist in the lockfile. Actual server extension availability and PHP support-window status are **not verified**. (`composer.json:11`, `.github/workflows/tests.yml:30`, `.github/workflows/tests.yml:160`, `composer.lock:715`)

## Architecture

The observed architecture is a Laravel monolith with domain services and support classes, Eloquent persistence, Form Requests, facades and container injection. It mixes direct controller CRUD with transaction-oriented domain operations. The contacts flow creates validated models directly; roster moves delegate to a specialised planner/writer; Stripe routes events to payment services. (`app/Http/Controllers/AdminDashboard/ContactsController.php:94`, `app/Http/Controllers/AdminDashboard/GroupMoveController.php:90`, `app/Http/Controllers/StripeWebhookController.php:231`)

There are two material persistence conventions. Modern tenant-owned models use a global scope; older content models retain explicit relationships or public header filtering. `Contact` uses `BelongsToMasjid`, while `Page` uses `SearchableTrait`, media-library integration and soft deletion. This is an existing architectural boundary, not evidence that every model should receive the same trait. (`app/Models/Contact.php:68`, `app/Models/Page.php:13`, `.claude/rules/tenant-scoping.md:57`)

Controllers do not uniformly serialize through API Resources. Contacts return raw model-derived data, while public pages return `PageResource` collections. Consequently, model `$hidden`, casts and explicit projections are part of the wire contract. (`app/Http/Controllers/AdminDashboard/ContactsController.php:99`, `app/Http/Controllers/AdminDashboard/ContactsController.php:124`, `app/Http/Controllers/Api/V1/PagesController.php:43`)

## State, routing and API surface

Durable business state lives in Eloquent/database rows; request tenant state lives in `TenantContext`; ephemeral payloads and coordination state use Laravel cache. Sessions, queues and cache default to database-backed drivers, although environment overrides determine a deployment’s actual configuration. (`app/Support/TenantContext.php:50`, `config/session.php:21`, `config/queue.php:16`, `config/cache.php:18`)

The pruned static route census found **732 HTTP route declarations**. This excludes framework-generated health routes and does not expand GET into HEAD or prove runtime registration.

| Route file | Declarations | Main surface |
|---|---:|---|
| `admin.php` | 471 | Staff administration, content, CRM and school operations. (`routes/admin.php:102`, `routes/admin.php:128`) |
| `api.php` | 53 | Mobile reads, member identity and external callbacks. (`routes/api.php:42`, `routes/api.php:370`) |
| `api_v1.php` | 43 | Public website reads and transactional intake. (`routes/api_v1.php:26`, `routes/api_v1.php:65`) |
| `family.php` | 44 | Parent and student access. (`routes/family.php:192`, `routes/family.php:203`) |
| `teacher.php` | 93 | School/class teaching operations. (`routes/teacher.php:64`, `routes/teacher.php:143`) |
| `lunch.php` | 17 | Lunch staff operations. (`routes/lunch.php:53`, `routes/lunch.php:72`) |
| `web.php` | 11 | Public landings and SPA entry. (`routes/web.php:29`, `routes/web.php:130`) |

Routing uses string paths and controller references. Public website routes have a `/v1` prefix; admin, mobile, teacher and family paths use their own namespaces without that prefix. No executable route table or generated OpenAPI contract was verified. (`routes/api_v1.php:26`, `routes/admin.php:102`, `routes/api.php:42`)

Response conventions vary. The `api` macro returns status/message/data but filters falsy values and labels only HTTP 200 as success. Contacts use status/data, move refusals use status/message plus contextual fields, and selected member errors receive an empty data object for client decoding. (`app/Providers/AppServiceProvider.php:1226`, `app/Http/Controllers/AdminDashboard/ContactsController.php:83`, `app/Http/Controllers/AdminDashboard/GroupMoveController.php:113`, `bootstrap/app.php:267`)

Pagination also varies: contacts paginate with caller-supplied `per_page`, defaulting to 15, while public page lists return the complete active collection. Neither observation establishes a platform-wide pagination cap. (`app/Http/Controllers/AdminDashboard/ContactsController.php:81`, `app/Http/Controllers/Api/V1/PagesController.php:37`)

## Authentication, tenancy and boundaries

Admin routes place `auth:sanctum`, `admin` and `tenant` together. Teacher and lunch routes have separate role gates; school-scoped routes bind their tenant independently of the admin realm. Family authentication uses the contacts provider and a custom Sanctum-family driver. (`routes/admin.php:128`, `routes/lunch.php:72`, `bootstrap/app.php:123`, `config/auth.php:149`)

Staff login verifies password before its enrolled-user 2FA branch and issues a token carrying staff abilities. Staff tokens default to eight hours. The custom family guard receives a separate expiration, defaulting to 43,200 minutes; the scheduled token-pruning command understands the distinction. (`app/Http/Controllers/AdminDashboard/AuthController.php:58`, `app/Http/Controllers/AdminDashboard/AuthController.php:133`, `config/sanctum.php:56`, `app/Providers/AppServiceProvider.php:1114`, `app/Console/Commands/PruneExpiredTokens.php:297`)

Contact credentials are additionally separated by abilities. Member routes require `member.token`, whose middleware checks the Contact principal and member ability; family/student aliases have separate guards. Provider identity alone is therefore insufficient to describe realm separation. (`routes/api.php:228`, `app/Http/Middleware/EnsureMemberToken.php:43`, `bootstrap/app.php:165`)

`ResolveMasjidTenant` uses membership resolution for staff and refuses unsupported principals. Tenant context is scoped in the container, and a job-processing listener clears it between asynchronous jobs. Unbound model context deliberately adds **no tenant predicate**; this makes public handlers and system jobs responsible for explicit ownership. (`app/Http/Middleware/ResolveMasjidTenant.php:163`, `app/Http/Middleware/ResolveMasjidTenant.php:174`, `app/Providers/AppServiceProvider.php:48`, `app/Listeners/ResetTenantContextBetweenJobs.php:67`, `app/Models/Concerns/BelongsToMasjid.php:49`)

Multi-membership is configurable and defaults false. Teacher/lunch rules require tenant-bound URLs to name their organisation; actual live gate values and membership backfill are **not verified**. (`config/tenancy.php:41`, `.claude/rules/tenant-scoping.md:71`)

For public website models, `SearchableTrait` casts the `masjid-id` header, rejects a nonpositive value with 400, checks organisation existence, then adds the tenant predicate. Public pages use this scope. Mobile reads instead commonly name the organisation in their URL. (`app/Traits/SearchableTrait.php:84`, `app/Http/Controllers/Api/V1/PagesController.php:37`, `routes/api.php:70`)

Dependency injection is Laravel container-based: `TenantContext` is scoped, StripeClient is singleton, and the translator interface is bound to an Anthropic implementation. Cross-domain imports remain possible under the shared `App\` namespace; a compiler-enforced domain-module boundary is **not verified**. The public raw tenant setter explicitly documents convention-based restrictions. (`app/Providers/AppServiceProvider.php:48`, `app/Providers/AppServiceProvider.php:54`, `app/Providers/AppServiceProvider.php:77`, `composer.json:39`, `app/Support/TenantContext.php:98`)

## Data, persistence and concurrency

Database configuration defaults to SQLite; MySQL configuration defaults to strict mode and `utf8mb4_unicode_ci`. Production collation claims in documentation must not replace inspection of deployed columns. Redis connections are configured, but use of a live Redis server is **not verified**. (`config/database.php:19`, `config/database.php:45`, `config/database.php:145`)

Migrations are Laravel-owned. Deployment runs `migrate --force`; CI migrates MySQL and exercises rollback/reapply. The applied migration table, real schema and database connection capacity remain **not verified**. (`bin/deploy:213`, `.github/workflows/tests.yml:181`, `.github/workflows/tests.yml:195`)

Schema examples show tenant indexes, foreign keys and domain-specific uniqueness: contacts index tenant/id; donations have unique UUID and idempotency key; guardian membership edges have a composite unique key; domain hosts are globally unique. These examples are not a full constraint audit. (`database/migrations/2026_07_11_000000_create_contacts_table.php:33`, `database/migrations/2026_07_12_000003_create_donations_table.php:31`, `database/migrations/2026_07_12_000003_create_donations_table.php:60`, `database/migrations/2026_08_11_020001_create_group_memberships_table.php:59`, `database/migrations/2026_09_24_140000_create_masjid_domains_table.php:38`)

Concurrency includes row-lock transactions, cache locks and optimistic expectations. Roster moves lock the contact, source membership and ordered membership rows. Cart settlement opens a transaction and locks its cart/order. Whole-class moves take a named database-cache mutex while processing individual students. (`app/Support/RosterMove.php:256`, `app/Support/RosterMove.php:268`, `app/Services/Cart/CartSettlementService.php:162`, `app/Services/Cart/CartSettlementService.php:248`, `app/Support/RosterClassMove.php:207`)

Mobile payload caching centralises tenant/global keys and invalidation. Defined TTLs range from five minutes to a day; family invalidation includes organisation relationships. Actual cache contents and invalidation completeness were **not verified**. (`app/Support/MobileCache.php:90`, `app/Support/MobileCache.php:96`, `app/Support/MobileCache.php:182`)

Storage separates private local files from public media exposed through `public/storage`; S3 configuration also exists. Private video delivery supports byte ranges and re-resolves ownership and audience before streaming. (`config/filesystems.php:33`, `config/filesystems.php:41`, `config/filesystems.php:50`, `app/Support/PrivateMediaStream.php:116`, `app/Http/Controllers/GroupMediaPlaybackController.php:115`)

## Money and transactional intake

Donations persist pending rows before Checkout and pass the connected organisation account into the Stripe operation. Monetary donation columns use integer minor-unit storage/casts. Historical gifts are represented separately from money processed through Manara. (`app/Services/Stripe/DonationService.php:122`, `app/Services/Stripe/DonationService.php:243`, `database/migrations/2026_07_12_000003_create_donations_table.php:39`, `app/Models/Donation.php:101`, `app/Models/Donation.php:47`)

The Stripe webhook is outside staff authentication and throttling. Verification tries configured platform/Connect secrets. Successful dispatch sets `processed_at`; failures retain a retryable event and return 500. The unique event ID is durable deduplication state, but the controller’s processed check is not a demonstrated exclusive claim against simultaneous deliveries. (`routes/api.php:370`, `app/Http/Controllers/StripeWebhookController.php:185`, `app/Http/Controllers/StripeWebhookController.php:148`, `app/Http/Controllers/StripeWebhookController.php:221`)

Payment handlers distinguish `payment_status: paid` from session completion. Cart events have their own routing and settlement service, with tests asserting that they do not accidentally enter the donation fallback. Provider settlement, refund and dispute behavior was **not verified** against Stripe. (`app/Http/Controllers/StripeWebhookController.php:561`, `app/Services/Stripe/CartPaymentService.php:89`, `tests/Feature/Cart/CartWebhookRoutingTest.php:102`)

Public intake includes forms, appointments, offerings/registrations, lunch, kitchen orders and baskets; shop reads have a separate gate. Their route presence is verified, while every domain’s validation, reservation and deletion path was not individually audited. (`routes/api_v1.php:65`, `routes/api_v1.php:149`, `routes/api_v1.php:183`, `routes/api_v1.php:198`, `routes/api_v1.php:222`, `routes/api_v1.php:103`, `routes/api_v1.php:137`)

The family-ledger rule file explicitly describes future design until its implementation slice lands. It must not be cited as evidence of an implemented tuition ledger or scheduled billing engine. (`.claude/rules/family-ledger.md:3`)

## Schools, roster and disclosure

School routes cover class teaching, lesson plans, grades/report cards, resources, Qur’an progress, stories, messaging, behavior awards and class-store operations. Class/subject middleware provides finer teacher authority than organisation membership alone. (`routes/teacher.php:143`, `routes/teacher.php:156`, `routes/teacher.php:239`, `routes/teacher.php:250`, `routes/teacher.php:289`, `routes/teacher.php:303`)

`GroupAudience` centralises distinct disclosure questions: stories/media, participant threads and records concerning a particular student are separate decisions. Playback asks this authority again after resolving the complete ownership chain. Every disclosure branch was **not verified** exhaustively. (`app/Support/GroupAudience.php:451`, `app/Support/GroupAudience.php:483`, `app/Support/GroupAudience.php:644`, `app/Http/Controllers/GroupMediaPlaybackController.php:120`)

Single-student and whole-class moves are office operations requiring `manage contacts`. The single controller passes explicit expected-state fields, including consent, to `RosterMove`; the writer performs its locked transaction and carries consent according to its rules. (`routes/admin.php:1373`, `routes/admin.php:1388`, `app/Http/Controllers/AdminDashboard/GroupMoveController.php:107`, `app/Support/RosterMove.php:235`, `app/Support/RosterMove.php:475`)

Whole-class runs cap requests at 60 students and stop starting new students after 40 seconds. The code expressly says this is not an overall request-duration guarantee and that MySQL timings need investigation. Bucks transfer remains an unfinished connection: moves currently leave balances on the old row and document the enablement prerequisite. (`app/Support/RosterClassMove.php:63`, `app/Support/RosterClassMove.php:77`, `app/Support/RosterMove.php:1408`)

## Domains, renderer and provisioning seams

`masjid_domains` now exists. Public by-host lookup queries served domains, restricts them to serving roles and live organisations, and returns `Cache-Control: no-store`. Domain changes invalidate cached CORS origins; admission depends on serving confirmation. (`database/migrations/2026_09_24_140000_create_masjid_domains_table.php:35`, `app/Models/MasjidDomain.php:363`, `app/Http/Controllers/Api/V1/OrganizationByHostController.php:46`, `app/Http/Controllers/Api/V1/OrganizationByHostController.php:68`, `app/Models/MasjidDomain.php:251`, `app/Models/MasjidDomain.php:376`)

Preview tokens bind organisation, surface, path, admin origin and expiry, with a five-minute TTL and purpose-separated HMAC. The renderer shared secret is configured separately from `APP_KEY`; renderer-side verification is **not verified**. (`app/Support/Renderer/PreviewToken.php:23`, `app/Support/Renderer/PreviewToken.php:43`, `app/Support/Renderer/PreviewToken.php:51`, `config/services.php:317`)

Successful content writes carrying `renderer.purge` schedule two purge jobs, initially after three seconds and again after a trailing 75-second interval. Purge HTTP requests have bounded connect/overall timeouts. Actual worker execution and external cache removal are **not verified**. (`app/Http/Middleware/PurgeRendererCacheAfterWrite.php:34`, `app/Support/Renderer/RendererPurgeScheduler.php:27`, `app/Jobs/PurgeRendererCacheAgain.php:32`, `app/Support/Renderer/RendererCachePurge.php:79`)

App provisioning uses GitHub dispatch and per-job bearer callback authentication with constant-time comparison. Callback secrets are hidden from model serialization. External runner configuration, signing material and produced mobile artifacts are **not verified**. (`app/Services/GithubDispatchService.php:62`, `app/Http/Controllers/ProvisioningCallbackController.php:49`, `app/Models/ProvisioningJob.php:67`)

## Background work and observability

Queue defaults use database jobs, a 90-second reservation retry interval and database-backed failed jobs. The committed systemd unit runs the default queue, restarts failures and recycles hourly. Individual jobs override attempts/timeouts: notification delivery uses three attempts with backoff; broadcast and group fan-out deliberately use one attempt. (`config/queue.php:37`, `config/queue.php:106`, `deploy/masjid-queue.service:16`, `app/Jobs/SendMasjidNotificationJob.php:37`, `app/Jobs/SendMasjidNotificationJob.php:55`, `app/Jobs/SendBroadcastJob.php:45`, `app/Jobs/SendGroupNotificationJob.php:51`)

Schedules cover prayer notifications/resync, group publication and retention, translation/code pruning, registration expiry, website imports, cart pruning, domains, canaries, media verification, backups, restore drills, points reports and Bucks mint/expiry. Cron installation and last successful runs are **not verified**. (`routes/console.php:22`, `routes/console.php:108`, `routes/console.php:141`, `routes/console.php:227`, `routes/console.php:471`, `routes/console.php:746`, `routes/console.php:887`, `routes/console.php:909`)

Logging defaults to Laravel’s stack/single file. A dedicated monitors file keeps info-level proof of runs; the monitors stack also includes application logging and error-level email alerts, dependent on configured recipients. The implementation deliberately separates “ran” from “needs attention.” (`config/logging.php:21`, `config/logging.php:61`, `config/logging.php:93`, `config/logging.php:115`, `config/logging.php:139`)

Health includes `/up` and scheduled operational probes. A deployed metrics exporter, distributed tracing, request-ID propagation, error-reporting service and SLO dashboard are **not verified**. Error bodies use generic production messages through `Errors`; debug configuration can expose exception messages. (`bootstrap/app.php:37`, `routes/console.php:663`, `app/Support/Errors.php:69`)

## Testing, build, CI, lint and format

The pruned census found 583 Feature, 36 Unit, ten MySQL and two MySQL-lock test files. These are file counts, not case counts. PHPUnit configuration defines all four suites; Pest binds the application TestCase and gives MySQL suites engine/name guards, with lock tests avoiding `RefreshDatabase`. (`phpunit.xml:7`, `tests/Pest.php:16`, `tests/Pest.php:23`, `tests/Pest.php:44`)

Default tests use in-memory SQLite, array cache/mail/session and synchronous queues. That configuration cannot establish real MySQL locking, asynchronous serialization or separate-worker behavior. The bootstrap also enforces one ordinary suite process per checkout to prevent fake-disk collisions. (`phpunit.xml:41`, `phpunit.xml:54`, `phpunit.xml:57`, `tests/bootstrap.php:53`)

Read tests contain meaningful isolation and behavior assertions: contacts test forged tenant IDs and foreign-resource refusal; cart tests pin webhook routing; roster-class tests cover partial progress and mutex selection; preview tests cover scope, signing and unconfigured behavior. None was run here. (`tests/Feature/ContactCrudTest.php:194`, `tests/Feature/ContactCrudTest.php:231`, `tests/Feature/Cart/CartWebhookRoutingTest.php:102`, `tests/Feature/RosterClassMoveTest.php:938`, `tests/Feature/RosterClassMoveTest.php:1115`, `tests/Feature/LivePreviewSessionTest.php:119`)

CI installs locked dependencies, builds the SPA, runs Node tests and Pest, and then checks JUnit output for collection, assertions, failures and errors. Its MySQL job migrates 8.4, rolls back/reapplies and runs the MySQL group. A separate added-lines scan checks public-repository documentation/rules/fixtures. (`.github/workflows/tests.yml:45`, `.github/workflows/tests.yml:56`, `.github/workflows/tests.yml:69`, `.github/workflows/tests.yml:98`, `.github/workflows/tests.yml:116`, `.github/workflows/tests.yml:131`)

Pint is installed and ESLint dependencies are declared, but enforced formatting, PHPStan/Psalm and backend coverage thresholds are **not verified**. The inspected workflow is evidence of test/build gates, not of their current results or branch-protection requirements. (`composer.json:30`, `package.json:13`, `.github/workflows/tests.yml:17`)

## Flags, analytics, localization and accessibility

Capabilities distinguish opt-in grants from modules and combine configuration, dedicated columns and organisation overrides. Unknown capability keys return false; module handling has a separately coded fallback. Cart, member portal, multi-membership and story-read features have independent configuration switches, defaulting off. (`app/Models/Masjid.php:432`, `app/Models/Masjid.php:471`, `config/capabilities.php:232`, `config/cart.php:24`, `config/member_portal.php:28`, `config/tenancy.php:41`, `config/groups.php:89`)

Flag retirement has at least one measured seam: legacy mobile feature reads register counting middleware and have a scheduled report. Impact reporting is also routed. These do not establish a general analytics event catalogue; third-party analytics and comprehensive retirement policy are **not verified**. (`routes/api.php:148`, `routes/console.php:205`, `routes/admin.php:1877`)

Application locale defaults to English. Portal language metadata defines Arabic, Urdu, Pashto, Dari (`fa-AF`) and Spanish, including direction; configured languages are filtered against known metadata. Dynamic translation is container-injected and has provider-call/cache safeguards described in its implementation. Complete translation catalogues and translation accuracy are **not verified**. (`config/app.php:103`, `app/Support/PortalLanguage.php:49`, `app/Support/PortalLanguage.php:89`, `app/Providers/AppServiceProvider.php:77`, `app/Services/Translation/AnthropicTranslator.php:46`)

Backend accessibility enforcement and complete portal RTL rendering are **not verified**; those require the frontend and rendered output. The backend does expose language direction metadata. (`app/Support/PortalLanguage.php:105`)

## Secrets, configuration and release identity

Configuration uses Laravel environment-backed config files. Database credentials, provider keys and renderer secrets are server configuration; app-publishing credentials use encrypted casts. Contact passwords, birth dates and reported ages are hidden, with dates/ages encrypted at rest. Deployed secrets, rotation and key-recovery procedures are **not verified**. (`config/database.php:48`, `config/services.php:317`, `app/Models/MasjidAppPublishing.php:58`, `app/Models/Contact.php:239`, `app/Models/Contact.php:282`)

Security middleware supplies browser headers, including framing restrictions and CSP. Trusted-host enforcement defaults to observation rather than refusal; session cookies default Secure, HttpOnly and SameSite=Lax. Deployment overrides and actual TLS/CORS responses are **not verified**. (`app/Http/Middleware/SecurityHeaders.php:36`, `app/Http/Middleware/SecurityHeaders.php:155`, `config/trusted_hosts.php:73`, `config/session.php:176`, `config/session.php:189`, `config/session.php:206`)

Release identity is a deployed Laravel checkout plus SPA assets and environment configuration. Documentation describes a manual ship script that builds/rsyncs assets and invokes `bin/deploy`; that script installs production dependencies, migrates, rebuilds caches and restarts the queue. Applied server configuration is **not verified**. (`deploy/README.md:16`, `scripts/ship.sh:363`, `bin/deploy:201`, `bin/deploy:213`, `bin/deploy:216`, `bin/deploy:225`)

## Platform-shaped axes

**Execution model:** the committed staging example targets nginx and PHP 8.3 FPM; queue workers are long-lived processes, and cutout work launches a separate Python process. Installed process topology, FPM capacity and OPcache policy are **not verified**. (`deploy/staging/nginx-staging.conf.example:158`, `deploy/masjid-queue.service:16`, `app/Services/Flyer/ImageCutout.php:76`)

**Variant matrix:** production/staging are configured deployment targets, while organisation verticals share code. Runtime flags, credentials, origins and data make deployments differ; live values were not inspected. (`deploy/README.md:3`, `config/app.php:29`, `.claude/rules/verticals.md:16`)

**Data ownership:** the inspected Laravel service writes its business tables; the Python helper reads/writes image files and has no verified database-writing path. External database writers are **not verified**. (`database/migrations/2026_07_11_000000_create_contacts_table.php:20`, `scripts/cutout/cutout.py:44`)

**Deploy unit and rollback:** code/assets and migrations are separate effects within a manual release. CI tests schema rollback, but reverting code does not itself demonstrate restoration of data, files or provider-side effects. Blue-green deployment, automated production rollback and recovery evidence are **not verified**. (`deploy/README.md:9`, `bin/deploy:213`, `.github/workflows/tests.yml:195`)

## Closest parallel features

1. **Contacts:** use for ordinary tenant-scoped CRUD, Form Requests, pagination and negative ownership tests. The inspected chain includes request, controller, scoped model and Feature tests. Git history last touched the controller on 2026-10-04. (`app/Http/Requests/Admin/Contacts/StoreContactRequest.php:15`, `app/Http/Controllers/AdminDashboard/ContactsController.php:94`, `app/Models/Contact.php:68`, `tests/Feature/ContactCrudTest.php:153`)
2. **Roster moves:** use for preview/commit operations, optimistic expectations, bounded locking and partial batch outcomes. Git history last touched the writer on 2026-10-05. (`app/Http/Controllers/AdminDashboard/GroupMoveController.php:42`, `app/Models/GroupMembership.php:33`, `app/Support/RosterMove.php:256`, `tests/Feature/RosterClassMoveTest.php:1029`)
3. **Renderer preview:** use for signed external integration, configured-origin selection and disabled behavior. Git history last touched the controller on 2026-09-24. Renderer execution remains outside the verified chain. (`app/Http/Controllers/AdminDashboard/LivePreviewController.php:91`, `app/Support/Renderer/PreviewToken.php:43`, `tests/Feature/LivePreviewSessionTest.php:223`)

## Inconsistencies and risks

- **High — tenant isolation depends on correct binding or explicit predicates.** Unbound scoped-model queries are unrestricted by design, and raw tenant binding remains publicly callable by convention. This is a boundary requiring care, not a demonstrated cross-tenant exploit. (`app/Models/Concerns/BelongsToMasjid.php:49`, `app/Support/TenantContext.php:98`)
- **High — queue timing requires deployment verification.** Configured reservation retry is 90 seconds, while broadcast/group jobs allow 300/120 seconds. Overlapping execution with multiple workers is a risk inferred from those settings; effective production configuration and a reproduction are not verified. (`config/queue.php:42`, `app/Jobs/SendBroadcastJob.php:48`, `app/Jobs/SendGroupNotificationJob.php:53`)
- **High — class-store enablement has an unfinished prerequisite.** Roster moves currently leave Bucks behind despite the recorded decision that balances follow students. The implementation explicitly documents the seam and privacy concern. (`app/Support/RosterMove.php:1408`)
- **Medium — broad legacy catches change missing resources into 500 responses.** Page lookups occur inside exception handling that returns internal-server-error status. This is inferred from code, not reproduced. (`app/Http/Controllers/AdminDashboard/PagesController.php:87`, `app/Http/Controllers/AdminDashboard/PagesController.php:94`)
- **Medium — response contracts differ.** The API macro removes falsy fields and treats non-200 statuses as error; other handlers use independent envelopes. Consumers cannot assume one universal shape. (`app/Providers/AppServiceProvider.php:1226`, `bootstrap/app.php:267`)
- **Medium — inspection cannot establish operational safeguards are active.** Host refusal defaults off; alerts require configuration; renderer invalidation requires jobs and external integration. (`config/trusted_hosts.php:73`, `config/logging.php:97`, `app/Support/Renderer/RendererPurgeScheduler.php:31`)
- **Documentation drift — old recon claims are materially stale.** Current code has domains, shared renderer signing and queued purges; CI now specifies MySQL 8.4. The committed root still says Laravel 11. (`database/migrations/2026_09_24_140000_create_masjid_domains_table.php:35`, `config/services.php:317`, `.github/workflows/tests.yml:142`, `CLAUDE.md:3`)

## Open questions

- **Not verified:** deployed PHP/extensions, database engine/collations, applied migrations, connection limits, queue workers and effective retry interval. Checked manifests, database/queue config, CI and committed service artifacts.
- **Not verified:** current CI results, required branch checks and full end-to-end contracts across native apps and renderer. Checked workflow definitions and selected contract tests.
- **Not verified:** simultaneous Stripe delivery safety across every handler, provider sandbox behavior, refund/dispute ordering and deletion guarantees. Checked dispatch, deduplication and representative settlement paths.
- **Not verified:** MySQL roster timings, interacting registration/move transactions and full consent-return combinations. The batch implementation itself records its timing uncertainty. (`app/Support/RosterClassMove.php:63`)
- **Not verified:** active capability values, class-store enablement, member/cart allowlists and membership backfill. Configuration defaults cannot establish live tenant state.
- **Not verified:** scheduler installation, alert delivery, offsite backups, restore-drill results, OPcache refresh and production rollback procedures. Checked schedules and deployment artifacts.
- **Not verified:** Python interpreter/model integrity, installed dependency pins, resource usage and cutout output quality. Checked provisioner and helper source only.
- **Not verified:** complete translation coverage, accessibility, product analytics, distributed tracing, metrics and SLOs.

## Elided

Frontend rendering, browser accessibility, native iOS/Android implementations and the external renderer require separate platform reports. Production data, infrastructure probes, package installation, builds and executable verification were excluded by the brief. Full mode covers the recon axes; it does not imply every controller, migration or security-sensitive path received an exhaustive audit.