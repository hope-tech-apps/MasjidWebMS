# Recon — identity & tenancy, 2026-08-11

## iOS app readiness for a parent app

## (a) Current identity: device-only, and it regenerates

`Masjid/Views/Splash/SplashViewModel.swift:32` builds `DeviceIdRequest(masjidId: AppConfig.masjidId, deviceId: UUID().uuidString)` and POSTs it to `/mobile/user` (`Masjid/Models/Networking/HTTP/APIRouter.swift:14,49-50`). Registration is skipped entirely if `Settings.shared.deviceId` already exists (`SplashViewModel.swift:36-43`), so the UUID is the identity — persisted only in UserDefaults, not the Keychain, so a reinstall creates a **new** user row.

The response (`Masjid/Models/ObjectModels/DeviceId.swift`) is `id, device_id, masjid_id, user_agent` — no person. It then calls `OneSignal.login(device.deviceId)` (`SplashViewModel.swift:68`) and, after a 3s delay, fire-and-forget POSTs `/mobile/user/heartbeat` with `{device_id, onesignal_subscription_id}` (`SplashViewModel.swift:77-85`, `APIRouter.swift:15,51-52`). Tenant scoping is a build constant, not a claim: every path interpolates `AppConfig.masjidId` (`APIRouter.swift:56-90`), and `AppDelegate.swift:34` tags the OneSignal subscription `masjid_id`.

## (b) Login UI / credentials / tokens: none

There is no sign-in screen, no auth view model, no OAuth/Sign-in-with-Apple. Auth is **wired but dead**:
- `APIRouter.swift:94-96` — `isRequiredToken` returns a hardcoded `false`, so the `Bearer` header at line 233 is unreachable.
- `Settings.accessToken` exists (`Masjid/Models/Settings.swift:33-37`) but nothing ever writes it; it's stored in **UserDefaults**, not Keychain.
- `Masjid/Models/Keychain.swift` is a complete, unused generic-password wrapper — zero call sites.
- `RequestInterceptor.swift:21-38` adapts nothing; the 401 handling and refresh-token flow are entirely commented out (lines 52-140).
- `AppEnvironment.Status` has no `.login` case (`Models/Core/AppEnvironment.swift:15-21`), though the dead code at `RequestInterceptor.swift:59` references one.

## (c) What parent auth needs structurally

- **Token storage:** move `accessToken` off `Settings` into `Keychain.system` (`setSecret(_:forKey:)` already works); keep a `Settings.currentParent` profile for display.
- **Attach it:** flip `isRequiredToken` to a per-case switch in `APIRouter.swift:94`, or better, set the header in `RequestInterceptor.adapt`. Un-comment the 401 → reset → `.login` path (`RequestInterceptor.swift:52-63`) and add `case login` to `AppEnvironment.Status` + a branch in `Masjid/Views/ContentView.swift:27-49`.
- **Gating:** ContentView already routes on `appStatus`, so a login wall is a one-branch change; the classroom tab gates in `MainTabView.swift:43-85` alongside the existing feature-id checks.
- **Build on:** `APIService`/`activeService` (`Models/Networking/ServiceProtocol.swift:19-23`) with the `Response<T>` envelope; `TextFieldFormView.swift` already has a `.password` field type; push can bind parent→device by extending `HeartbeatRequest` (`Models/ObjectModels/JSONModels.swift:25`) with a user id — no OneSignal rework, and `OneSignal.login()` can take the parent id instead of the UUID.

## (d) School tenant: Home is hard-blocked

`BuildMasjid` is a single constant — `enum BuildMasjid { static let masjidId = 1 }` (`Masjid/Config/BuildMasjid.swift:9`, NAFIS = 5). No vertical/tenant-type concept exists anywhere in the codebase (grep for school/vertical/parent returns only `padding(.vertical:)`).

**Yes, Home hardcodes prayer UI.** `HomeView.swift:180-186` (Suhur/Iftar), `:214-262` (Now/Next + Hijri), `:275-305` (`prayerItem` rows for Fajr…Isha, literal strings), `:307` `jumaaSection()`, plus `PrayerTimesManager`/`PrayerScheduler` on `onFirstAppear`/`onAppear` (`:50-77`). A school build would launch into a prayer table.

**Minimum vertical-awareness:** add `static let vertical: Vertical` to `BuildMasjid` (`.masjid` / `.school`), then split `HomeView.content` (`:154-176`) so `containerView()` dispatches to a `SchoolHomeView`, and make the prayer scheduling in `onFirstAppear`/`onAppear` conditional. Tab identity in `MainTabView` `enum Taps` (`:140-145`) needs a `classroom` case. The header backdrop/brand (`BrandHeaderBackground`) and the feature-id gating already re-skin per tenant, so nothing else structural is required.

## (e) Sizing

| Work | Size | Why |
|---|---|---|
| Parent auth (login screen, Keychain token, header injection, 401 reset, `.login` status) | **M** | Every seam exists but is commented out; ~5 files touched, no new dependency. |
| Classroom tab (feed, points, hifz, messages) | **L** | 4 new endpoints in `APIRouter`, new models, new list screens, pagination — `PaginationView`/`NotificationInboxView` are reusable patterns but this is net-new UI. |
| Vertical-aware Home + school target | **M** | Home split plus a new target/`BuildMasjid` variant; mechanically similar to the NAFIS target. |
| Push binding to parent identity | **S** | One field on `HeartbeatRequest`, one `OneSignal.login` argument. |

Note: `S.ProductionServer.url` and both `apiKey` values are empty strings (`Masjid/Models/S.swift:57-70`) and `S.server` points at `DevelopmentServer` — auth work will surface that.

## How deeply single-tenancy is assumed

**Note:** this is read-only recon; nothing in the repo was modified.

# Single-tenant assumption audit — MasjidWebMS

## (a) Where "the user's tenant" is a single scalar

There is **no `users.masjid_id` column** — the link is the inverse: `masjids.user_id`, exposed as `User::masjid()` = `hasOne(Masjid::class)` (`app/Models/User.php:85-88`). `hasOne` silently returns *one arbitrary row*, so a second tenant wouldn't error — it would be dropped.

- `app/Http/Middleware/ResolveMasjidTenant.php:60` — `$ownMasjidId = $user->masjid_id ?? $user->masjid?->id` (`masjid_id` never exists; always the hasOne). Line 67-69 is the hard 403; line 71 binds the singleton.
- `app/Support/TenantContext.php:22` — `private ?int $masjidId` — one scalar per request; `set()/get()/hasTenant()` all scalar.
- `app/Models/Concerns/BelongsToMasjid.php:45-64` — global scope + creating hook both read that one scalar. ~50 models use it.
- `app/Http/Controllers/AdminDashboard/AuthController.php:54-65` and `84-95` — login/`/user` serialize `user.masjid` (singular) and **force-logout** a MasjidAdmin with no masjid.
- `app/Models/Masjid.php:181` `admin()` = `belongsTo(User)` — one admin per masjid, reciprocally.
- `app/Traits/SearchableTrait.php:35` — `scopeFilterByMasjid` trusts a client `masjid-id` header (separate concern, but the same single-scalar shape).
- `app/Support/ImpactMetrics.php:787-795` — throws if a *different* tenant is already bound.

## (b) The Vue SPA

`resources/vue-app/stores/authStore.ts:19` — a single `dashboardMasjidId` ref, mirrored to one localStorage key (`:45-48`). For a MasjidAdmin it is set once from `user.masjid.id` (`authStore.ts:84-85`, `views/auth/SignIn.vue:81-82`); SuperAdmins get a switcher (`views/super/DashboardsView.vue:75-76`). Every store/view derives the URL from it — `authStore.dashboardMasjidId ?? masjidStore.masjid?.id` repeated in ~12 files (`stores/masjid/donationsStore.ts:41`, `flyersStore.ts:303`, `views/dashboard/DonationsView.vue:355`, …). `core/types/data/Admin.ts:19,35` types it `masjid: Masjid | null`. **It cannot represent two tenants** — but the SuperAdmin switcher is a working template for the fix.

## (c) Roles are global, not per-tenant

`config/permission.php:135` → `'teams' => false`. `User::TYPE_ROLE_MAP` (`User.php:34-38`) maps the *global* `users.type` enum; `syncRoleFromType()` (`:110-133`) calls `syncRoles([...])` — user-wide, no tenant column. `UserObserver::saved` re-applies it on every write. So "masjid-admin at the school, member at the masjid" is unrepresentable today.

## (d) What breaks

- `app/Http/Requests/Admin/Masjids/StoreMasjidRequest.php:26` and `Onboarding/ProvisionMasjidRequest.php:82` — **`unique:masjids,user_id`**. Hard block. (`UpdateMasjidRequest.php:23-31` omits it — an existing inconsistency.) No DB-level unique index exists, so the constraint is validation-only and data may already violate it.
- `AuthController.php:58-64` — a many-to-many user with no `masjid` hasOne gets logged out.
- `RolesAndPermissionsSeeder.php:24-33` + `tests/Feature/RolePermissionBridgeTest.php:102,108` pin `Permission::count() === 8`; any per-tenant permission additions break both.
- ~20 tests hard-set `$masjid->user_id = $admin->id` as *the* ownership fact (e.g. `tests/Feature/TenantIsolationTest.php:82-88`, `ContactCrudTest.php:99`, `HifzTrackingTest.php:121`).
- `TenantContext` is an AppServiceProvider **singleton** — one binding per request; nothing supports "acting as two".
- `unique:masjids,user_id` ignores `SoftDeletes` (`Masjid.php:14`), so an archived tenant already poisons re-assignment.

## (e) Size: **M**, riskiest = `ResolveMasjidTenant` + `BelongsToMasjid`

The pivot table and a `User::masjids()` relation are cheap; the SPA already switches tenants for SuperAdmins. The danger is `ResolveMasjidTenant.php:62-72`: it is the *only* thing stopping a MasjidAdmin from reading another tenant, and its check would change from `id === ownId` to a membership lookup. Get that wrong and the failure is silent cross-tenant data exposure through ~50 `BelongsToMasjid` models, with app-layer scoping as the sole backstop (no MySQL RLS — stated in `BelongsToMasjid.php:35-36`). Keep the 403 as the default and make membership the only widening.

## Complete auth/authz surface

# Auth/Authz Surface — MasjidWebMS

## (a) Guards, Sanctum, aliases
- `config/auth.php:38-47` — only `web` (session) and `api` (sanctum); both on the single `users` provider → `App\Models\User` (`:66-70`). **No second provider/guard exists.**
- `config/sanctum.php:36` guard `['web']`; `:53` expiration **480 min (8h)**. Tokens are minted with no abilities (`AuthController.php:52` `createToken('login-token')` ⇒ `*`); logout nukes **all** tokens (`:113`).
- Aliases `bootstrap/app.php:38-59`: `super`, `admin`, `tenant`, `crm`, `assistant`, `role`, `permission`, `role_or_permission`. `SecurityHeaders` appended globally (`:36`). Admin routes are mounted under `api` prefix at `:29-31`.

## (b) Stacks
- Admin group: `routes/admin.php:76` → `auth:sanctum` + `admin` + `tenant`. Login sits outside at `:71` (`throttle:login`, 5/min email+IP).
- CRM group: `routes/admin.php:419` adds `crm`, then **per-route** `permission:*`.
- Layer split: **identity** `auth:sanctum`; **actor class** `UserAdminMiddleware.php:22` / `SuperAdminMiddleware.php:19` (string `users.type`); **tenancy** `ResolveMasjidTenant.php:62-77` (MasjidAdmin pinned to own masjid, 403 on foreign `{masjid_id}`; SuperAdmin bound to route masjid); **feature gate** `EnsureCrmEnabled.php:42` / `EnsureAssistantEnabled`; **permission** Spatie (8 seeded perms, `RolesAndPermissionsSeeder.php:24-32`, guard `web`); **disclosure** `GroupAudience`, in-controller only.

## (c) Public trees
- `routes/api.php:31` `throttle:mobile` (60/min/IP, `AppServiceProvider.php:158`); `:34-40` `throttle:device` (10/hr); `:71-74` `throttle:contact` (10/hr). Tenancy = `{masjid_id}` **route param**, unbound — controllers filter by hand (`api.php:76-80`).
- `routes/api_v1.php` (required at `api.php:147`): **reads carry no middleware at all** (`:16-30,76-87`); tenancy comes from the attacker-controlled **`masjid-id` header** read in `HomeController.php:33`, `SettingController.php:39`, `PhotoGalleryController.php:23,53`, `ContactUsController.php:37`, plus `Page.php:82`, `Section.php:81`, `SearchableTrait.php:34`. Writes throttled by name: `form-submit` 8/hr, `appointment-request` 8/hr, `registration-intake` 8/hr, `registration-quote` 60/hr (`AppServiceProvider.php:103-156`) — note those keys **include the spoofable header**, so rotating it resets the bucket.

## (d) GroupAudience
`identitiesFor` (`app/Support/GroupAudience.php:73-98`) lowercases the admin's login email and matches **exactly one** live `Contact` in the bound tenant (0 or ≥2 ⇒ no identity). `standingIn` (`:486-541`) returns `in_group/leader/feed/participant_contact_ids/ward_contact_ids`, consuming `consentCovers` for guardians. It grants **read standing only** (`:111,:162,:203,:281,:332,:393,:410`); every write stays Spatie-gated. With real parent tokens the email bridge must be *replaced* by a contact-id claim, not extended — today an admin sharing an email with a contact silently inherits that contact's standing.

## (e) Needs an explicit staff-vs-parent decision
`GroupPostsController`, `GroupThreadsController`, `BehaviorAwardsController`, `HifzEntriesController`, `GroupConsentController`, `GroupMembershipsController`, `GroupsController`, `ContactsController`, `ContactCredentialsController`, `AppointmentRequestsController`, `OfferingsController`, `FeePlansController`, `RegistrationsController`, `DonationsController`, `DonationStatsController`, `DonationExportController`, `RecurringDonationsController`, `AnnualStatementsController`, `PropertiesController`, `ImpactMetricsController:51`, `FormResponsesController`, `AssistantController` + `Services\Assistant\ToolRegistry`/`AssistantTool.php:64`, `MasjidsController::setCrmAccess`, `AuthController`, `TwoFactorController`; middleware `UserAdminMiddleware`, `SuperAdminMiddleware`, `ResolveMasjidTenant`; services `GroupAudience`, `TenantContext`.

## (f) 2FA
`app/Services/TwoFactorService.php:19-72` is a stateless TOTP/QR wrapper (±1 step drift, `:66`) with **no user coupling** — staff-only by *placement* (`routes/admin.php:87-91`, inside `auth:sanctum`+`admin`) and by storage (`User.php:65,80,95`, encrypted `two_factor_secret`). Enforcement exists solely in `AuthController.php:30-49`: opt-in, no lockout, no `require_admin_2fa`. Reusable for parents, but nothing would enforce it.

**Standing risk:** `LoginRequest.php:12` `exists:users,email` returns 422 for unknown emails vs 200 "invalid credentials" — a user-enumeration oracle a parent realm would duplicate.
