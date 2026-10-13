# Push accounts and delivery outcomes

The account follows the phone. The organisation selects the audience; the installed app selects the push account. Selecting a child or another organisation changes the audience membership, never the account a phone registered with. Existing audience rules, prayer timing, service/tag membership, images and sounds remain unchanged.

## Registration contract for app builders

Send the optional `push_app_id` string on:

- `POST /api/mobile/user` (first registration and re-registration)
- `PUT /api/mobile/user` (organisation selection)
- `POST /api/mobile/user/heartbeat` (with the current subscription id)

Its value is the **OneSignal app ID passed to the OneSignal SDK when this installed app initializes**. It is not the Apple bundle ID, Android application ID, organisation ID, OneSignal subscription ID, or REST key. Read the same build configuration value used to initialize the SDK, rather than looking up the organisation on screen. For example, a build initialized with the invented provider app ID `aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa` sends that exact value, even while showing another organisation.

For iPhone, use the target's `ONESIGNAL_APP_ID` build setting read through Info.plist by `OneSignalConfiguration.appId()`, the value used by `OneSignal.initialize`. For Android, use the same app ID supplied to `OneSignal.initWithContext`; keep it in the build/flavour configuration and use it for both initialization and registration. Send it on every heartbeat so an app upgrade and its refreshed subscription move together. Never send credentials. This server build does not modify either native app.

The field accepts 1–128 ASCII letters, digits, underscores or hyphens. It is nullable and optional forever. Omission, null and an empty value preserve a previously recorded value; a device that has never reported it remains null. It is routing telemetry, never authentication or a tenant grant. An unknown value is accepted at registration but cannot make the platform send through an unregistered account. The registration response remains exactly the existing six-key device projection; heartbeat remains the existing acknowledgement. Unknown extra fields remain accepted.

**Null preserves today's routing:** use the organisation's existing complete `masjid_app_publishing` OneSignal configuration, or the runtime shared configuration if no dedicated account is configured. The registry does not change this legacy choice. An explicitly inactive registry entry blocks that account for null phones too. Unknown, inactive or credential-less **explicit** app IDs are counted as not deliverable: unknown app; they never fall back to another account.

## Registry and credentials

`push_apps` holds `provider_app_id` (unique), `rest_api_key`, `label`, `active`, an internal id and timestamps. `rest_api_key` uses Laravel's existing `encrypted` cast with `APP_KEY`, exactly like `masjid_app_publishing.onesignal_rest_api_key`, and is hidden from model serialization. The migration encrypts the runtime shared key and copies existing organisation ciphertext verbatim. It prints neither. An empty installation stays empty and the existing configuration still works for null registrations.

An operator can register an existing provider app with:

```sh
php artisan push:register aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa 'Example phone app'
```

The command prompts for the app REST API key without echoing it. Do not pass credentials in command arguments, logs or shell history. `--inactive` disables that registry entry. Updating the same app ID replaces its credential and label. Access to these commands is platform operator access; no new office permission or credential endpoint is added.

`OneSignalProvisioningService::provisionApp` also registers every new provider app. It accepts explicit `apnsP8`, `apnsKeyId` and `apnsTeamId` arguments together. Supplying only part of that triple is refused. Omitting the triple uses the existing global settings fallback; existing callers retain their response and attach behaviour. Existing per-call Android credential and APNs environment overrides remain supported. `attach_to_organisation => false` in the overrides prepares a registry entry without replacing the organisation's legacy account. No `.env` file is written. Calls with actual key material belong in an owner-approved secure operator workflow, never pasted into repository files or a shell command.

Staging previously nulled the encrypted organisation REST key, the organisation app ID, and handset subscription IDs; it dropped notification and queue rows. It still does that. It also nulls device `push_app_id`, drops `push_apps`, `push_sends` and `push_send_groups`, and classifies the new encrypted credential for nulling (covered by the row drop). Staging must register its own test accounts. The existing blank-shared-configuration send guard remains in place.

## Sends and read-back

Every active sender routes through the shared transport: the office Notifications sender, combined publish push channel, nightly silent sync, iqama-save sync, prayer-time backstop, prayer-test command and summer-content push command. Group notifications currently skip push and use email; feed announcements and splash in-app messages do not send a phone push.

`push_sends` holds organisation, optional notification reference, kind, status, targeted/delivered/errored totals, unknown-app count, numeric platform counts and scheduling time. `push_send_groups` stores the account label and public app ID, legacy flag, routing key, provider message ID, counts, state and number of checks for each group. Both are tenant scoped. They contain no credentials, recipients or message copy. Routing uses the caller's already-selected subscription IDs even if a queued phone selects another organisation before the worker runs; it never adds that organisation's other devices. The existing single notification message ID remains the first accepted group's ID for compatibility; the group ledger is authoritative for multiple accounts.

Null registrations retain the same URL, account, targeting and payload as before. During a partial migration, null phones retain the old request; app-aware phones are refused until all registry/ledger tables exist so they cannot be routed through another account. App-aware registrations are partitioned into account groups. Null and explicit registrations sharing a usable account share one request. Query-string app IDs, when present, are adjusted for explicit account groups. The old unused segment helper retains its original segment/tag request when all registrations are null; if app-aware devices exist it uses the registered device audience, like every live sender. It has no production caller.

Provider acceptance queues an ID-only read-back job after three minutes. An in-progress group gets one further check fifteen minutes after the first. Completed or unknown groups are not checked again by a replay. Counts are replaced and totals recomputed, never added to previous totals. Provider failures, incomplete/malformed answers, exhausted in-progress checks and worker failure leave an unknown outcome. Provider response bodies and exception text are never logged or stored. A synchronous queue cannot honour delays, so read-back uses the database queue in that configuration; run its worker too.

`targeted` is the original selected device count. `delivered` is the provider's `successful` count. `errored` includes its `errored` and `failed` counts, also by platform where supplied. The provider may count fewer subscriptions than the selected registrations; the difference is not silently called a delivery or an error. “Delivered” means successful delivery to a push server, not proof that a person read the notification. See [OneSignal View message](https://documentation.onesignal.com/reference/view-message) for `successful`, completion and platform statistics.

## Reading outcomes

Broadcasts replaces its push “sent (N)” badge with stored wording and a **Check delivery** refresh button. Push checks and unknown/error outcomes no longer use the green delivery badge. Cached older payloads without outcome fields also say delivery could not be checked. The existing Notifications screen lists its 30 most recent notifications and has a **Check delivery** button. Both keep their existing admin/tenant/module fences. Older sends without a ledger say that delivery could not be checked.

Examples:

- `Sent to 74 phones. Checking delivery…`
- `Sent to 74 phones. Delivered to 8. 40 could not be delivered (iPhone).`
- `Sent to 74 phones. Delivery could not be checked.`
- An unknown/inactive app group says `Targeted N phones.` and adds `N not deliverable: unknown app.`

A platform operator can read every group's label and counts without credentials:

```sh
php artisan push:outcomes --masjid=123 --since=2026-10-01
```

Both flags are optional; the default window is seven days. The command is read-only. It includes silent syncs and prayer pushes as well as office notifications. It reports platform figures when available and unknown values explicitly.

## Moving an organisation from shared to its own account

1. Ship the registry, routing and outcome migration and run queue workers. Leave the existing organisation configuration on shared. Confirm the shared app is registered and active.
2. Prepare the new provider app with its own Apple team's push key and Android credentials. Register it through provisioning with `attach_to_organisation => false`, or register an existing app with the hidden-prompt command. Do not disable shared.
3. Build **both** native apps with the new provider app ID. Make both report `push_app_id` and their matching subscription on heartbeat. New installs can receive through the new registry entry while the organisation still defaults to shared.
4. Before changing the organisation's legacy configuration, identify remaining null registrations and verify which installed provider app they belong to. With specific owner approval, pin those verified old shared installs to the shared provider app ID in `mobile_app_users.push_app_id`. Do not guess from the organisation selection: a cross-app or child selection is the reason this feature exists. A null device cannot reveal its historical account; if that cannot be established, keep the legacy configuration unchanged until it reports an app ID. Coordinate this pin and configuration switch so no newly created null registration can slip between them. For verified shared-only registrations, the operator transaction must lock the organisation/device registration workflow or use a controlled registration pause; do not run an unguarded broad backfill while installs are registering.
5. Keep the legacy default on shared while any distributed old build can still create a fresh null registration. New builds already use the dedicated registry entry, so attaching can wait. Once old installs are pinned, new builds report their app IDs, and the distribution/registration window is controlled, attach the new organisation configuration in a separately approved action. Shared old installs remain reachable through shared; new installs through the new app. Keep both registry entries active while either has devices.
6. Prove a real iPhone delivery and Android delivery, plus a child/cross-app selection, and inspect per-group outcomes. Faked provider tests do not establish real delivery. Retire an account only after its remaining registrations and build distribution have been checked and the owner approves.

Null compatibility intentionally does not infer an app from parenthood, organisation selection or an account's migration date. Older installs need the verified pin or a new heartbeat before a legacy account switch can be safe.
