# Link an existing Stripe account

This optional Connect OAuth path links a Standard account the organisation already owns. It ships OFF: `STRIPE_CONNECT_OAUTH_ENABLED=false` and `STRIPE_CONNECT_CLIENT_ID` empty. Both values must be configured before the door or routes work. Existing create-new Account Link onboarding remains available as before. No surcharge or charge transport changes are included.

## Choice of integration

The owner's build brief supplied these findings from Stripe's current [Standard OAuth documentation](https://docs.stripe.com/connect/oauth-standard-accounts), read on 2026-10-15:

> OAuth isn't recommended for new Connect platforms. We recommend using Connect Onboarding for Standard accounts instead.

> Starting in June 2021, Platforms using OAuth with read_write scope won't be able to connect to Standard accounts that are controlled by another platform.

OAuth remains the selected path for an existing independently owned Standard account. Create-new onboarding is a separate door. An account controlled by another platform is outside this path's supported case. These findings were supplied in the brief; this build did not independently access Stripe online.

## Staff flow

The existing Online payments screen and its authorisation apply: SuperAdmins and the organisation's MasjidAdmin staff with `manage donations`, while CRM is enabled. A school admin cannot act on another organisation. Family, teacher and lunch realms gain no route.

When configured, an unlinked organisation sees **Create a new account** and **Link an existing Stripe account**. The latter opens Stripe's OAuth consent screen with `scope=read_write`. Return to Online payments and refresh after consent. An organisation taking form cards through another organisation must have that existing form-account arrangement removed by a SuperAdmin first.

An organisation with an existing Stripe link must explicitly unlink first. **Unlink Stripe account** asks for confirmation, deauthorizes the platform, clears the organisation's account and capability flags and records the acting staff user. Card payments also stop for organisations using that account for forms. Previously recorded financial entries and their own pinned charge references remain intact. Stripe may refuse later API actions on disconnected accounts; an unlink does not refund or erase money.

The latest 20 history entries show link, unlink or Stripe deauthorization, the account's last four characters, staff name (resolved from the staff record), and time in the viewer's local timezone. Removed staff names are not copied into history. A hard-deleted staff user becomes NULL through `ON DELETE SET NULL`.

## State and safety

A random 256-bit nonce is held in an authenticated encrypted payload with the organisation and staff IDs. The server stores only its SHA-256 hash, those IDs and a ten-minute expiry. The redirect is always the configured application host, never the request Host header. Callback organisation selection comes exclusively from this state, never a query organisation ID.

As with existing onboarding return/refresh pages, the callback is public because Stripe's browser redirect carries no SPA bearer token. A matching, unexpired server record and valid authenticated ciphertext are required. An atomic DELETE claims that record before any token exchange, so failures, denial and replay require a fresh start. Where the browser has a staff session, its user must match. The originating staff user's current role, permission, live organisation membership and CRM access are checked again. Expired rows are pruned on the next start.

Stripe token exchange uses the platform secret through HTTP Basic authentication. Returned OAuth tokens and authorisation codes are never persisted or logged. Full account IDs remain in the existing `masjids.stripe_account_id` field; history stores only four characters. The history and state tables are tenant scoped and dropped during staging scrubbing; no new `masjids` columns are added.

The callback reads Stripe's account capabilities before opening its database transaction. Claims serialize on the oldest surviving tenant root's primary key, then lock the target root and check all organisations, including archived rows, before storing. Locking reads precede the ordinary duplicate check so InnoDB uses a fresh read view after the preceding claim. A deleted mutex root refuses the attempt safely. No Stripe call runs under the callback's row locks. The existing live-account unique index is a further backstop. A refused or failed local link releases the grant it just obtained ONLY when no organisation (including an archived one) holds that account. Stripe deauthorization is platform/account wide, so refusing an account another organisation already holds never deauthorizes it: the holder keeps its working link. If Stripe itself deauthorizes an account, its verified webhook clears the holder's link and records that event. Failed OAuth transport logs only the operation and returns a plain retry sentence; if cleanup cannot reach Stripe, the owner must reconcile platform access in Stripe. A later successful `account.updated` alone never recreates a link cleared by deauthorization.

## Owner setup (not performed by this build)

1. In the platform's Connect settings, enable OAuth and copy the correct `client_id` (`ca_…`). Test and live client IDs are different; start in TEST mode with TEST platform keys and a TEST Standard account.
2. Register the exact redirect URI: the configured `APP_URL`, without its trailing slash, plus `/connect/oauth/callback`. Scheme, host and path must match exactly. This callback has no organisation path segment.
3. Set `STRIPE_CONNECT_CLIENT_ID` and `STRIPE_CONNECT_OAUTH_ENABLED=true` in that environment's `.env`, then run `php artisan config:cache`. Do not enable production as part of the test proof.
4. On the **Connect webhook endpoint** (events on connected accounts), add `checkout.session.completed`, `payment_intent.succeeded`, `charge.refunded`, and add `account.application.deauthorized` and `account.updated` if missing. Retain the event subscriptions already needed by other payment paths.
5. Set `STRIPE_CONNECT_WEBHOOK_SECRET` if unset, using that Connect endpoint's signing secret, then refresh the config cache. A platform endpoint secret and a Connect endpoint secret are distinct. Point the TEST endpoint at the TEST/staging application with its matching secret.

## Owner's TEST-mode proof (not performed)

On staging, link an independently owned TEST Standard account through OAuth, confirm flags and the office history, then initiate a tuition card payment as a **direct charge on that connected account**. Deliver the signed Connect webhook and replay it: exactly one payment ledger line must remain. Refund and verify a refund ledger line. Deauthorize from Stripe and verify the link/flags clear with one history entry. Also verify the explicit office unlink and that card checkout becomes unavailable after disconnect. This local build uses fake Stripe responses; it does not establish Stripe dashboard eligibility, provider behaviour, webhook subscriptions or real payment settlement.


## Files changed

| Files | Purpose |
| --- | --- |
| `config/services.php`, `.env.example` | Default-OFF OAuth switch and empty platform client ID. |
| `routes/admin.php`, `routes/web.php`, `app/Http/Controllers/StripeConnectOAuthController.php`, `resources/views/connect/oauth-status.blade.php` | Existing staff gates for start/unlink and an information-thin public browser callback with plain refusals. |
| `app/Services/Stripe/StripeConnectOAuthService.php`, `app/Services/Stripe/StripeConnectService.php` | OAuth transport, single-use state, current authority checks, account exclusivity, capability refresh and explicit unlink; read capabilities before taking callback locks. |
| `database/migrations/2026_10_15_100000_create_stripe_connect_oauth_tables.php`, `app/Models/StripeConnectOAuthState.php`, `app/Models/StripeConnectHistory.php` | Two tenant tables with primary keys, expiry and nullable actor attribution. |
| `app/Http/Controllers/AdminDashboard/StripeConnectController.php`, `app/Http/Controllers/StripeWebhookController.php` | Staff history projection and verified deauthorization clearing/audit, including delayed-event protection. |
| `config/staging_scrub.php` | Drop linking credentials and financial attribution history from staging copies. |
| `resources/vue-app/components/StripeConnectPanel.vue`, `resources/vue-app/stores/masjid/connectStore.ts`, `resources/vue-app/core/types/data/masjid-related/StripeConnect.ts` | Conditional existing-account door, confirmed unlink and account-suffix history. |
| `tests/Feature/StripeConnectOAuthTest.php`, `tests/Feature/StripeConnectOAuthSchemaTest.php`, `tests/Mysql/StripeConnectOAuthSchemaTest.php`, `tests/Feature/FormLinkedWebhookTest.php`, `resources/vue-app/tests/stripe-connect-oauth.test.ts` | Faked OAuth behavior, isolation, deletion/null actors, schema/rollback/key checks, webhook trace and real mounted panel interactions. Existing create-new onboarding tests remain untouched. |
| `DECISIONS.md`, `ASSUMPTIONS.md`, this document | Integration decision, supplied documentation quotes, scope/limitations and owner setup/proof. Local `STATE.md` and `PLAN.md` checkpoint the work. |

## Local verification (2026-10-15)

- Tests were added first: initial OAuth run failed 10 tests / 14 assertions before implementation (`/tmp/connect-oauth-red.log`).
- One ENTIRE default PHP run, before the final lock refinement: **11 failed, 7 skipped, 11359 passed (187149 assertions)**, **883.30s**, printed by Pest in `/tmp/connect-oauth-full.log`. The only failures were four `ResendTransportTimeoutTest` and three `TenancyCanaryTest` socket refusals, two `BackupRunTest` archive assertions, and the two missing-build cases in `ExampleTest` and `HostHeaderUrlIntegrityTest`. No other failures. Its JUnit omits the three canary setup errors; use the printed totals and failure list in the text log.
- Final review narrowed callback locks and moved the Stripe read before the transaction. A new transaction-level test failed first (`/tmp/connect-oauth-lock-red.log`), then the final broad targeted run passed **179 tests / 2871 assertions** (`/tmp/connect-oauth-final-contracts.log`), including OAuth, original Connect tests, webhook, tenant coverage, staging scrub, migration boot, member deletion, Studio snapshots and source-contract readers.
- Final targeted roles-ON run: **55 passed / 268 assertions** (`/tmp/connect-oauth-final-roles-on.log`). App101 pinned separately: **1 passed / 26 assertions** (`/tmp/connect-oauth-final-app101.log`). Its fixture was never edited; no `CAPTURE_*` variable was set.
- `npm run -s test:spa`: **2205 tests, 2204 pass, 0 fail, 1 skipped**, 219857.881333ms (`/tmp/connect-oauth-spa.log`). Final mounted OAuth tests after the type-only history-label annotation: **3 passed** (`/tmp/connect-oauth-final-spa-target.log`).

- One `npm run build` passed in **11.49s** (`/tmp/connect-oauth-build.log`). Checked all **233 manifest entries**, their referenced files/imports, and compiled OAuth/link/unlink/history markers across **208 JavaScript chunks** (`/tmp/connect-oauth-build-inspection.json`); then removed `public/build` as requested. Vite reported a large-chunk warning.
- Final PHP syntax checks passed for **16 changed/new PHP files**. Added tracked lines and new files passed a strict private-ID/client/email scan, `git diff --check` passed, and whitespace/non-whitespace diff stats remain close. Removed 39 newly generated suite artifacts; retained the pre-existing subject-summary artifact byte-for-byte. No formatter or CHANGELOG edits.

The full PHP suite was not repeated after the final lock refinement. MySQL-specific rollback/key/FK coverage is added for CI and remains unexecuted here. MySQL concurrency, real browser cookies/redirects, real Stripe TEST OAuth/direct tuition payment/refund/delivery and deployed behavior remain **NOT verified**. No commit, network access, deployment, switch-on or production action occurred.
