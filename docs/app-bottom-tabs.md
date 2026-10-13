# Organisation app tabs

`app_bottom_tabs` is an opt-in organisation grant, default false for masjid, school and community organisations. It has no school or CRM dependency and no SuperAdmin bypass on the settings routes. OFF adds no mobile key or query, and hides the screen, navigation item, grant chips, computed admin capability and default switch/catalogue entries. Production activation and deployment require separate owner approval.

## Native payload contract

The launch feature endpoint used by installed apps is **GET `/api/mobile/masjids/{masjid_id}/features`**. Its `data` is still the literal existing feature array, including icons and pivot availability. `bottom_tabs` is added at the **envelope root**, beside `status` and `data`, because changing `data` from an array to an object would break installed decoders.

**GET `/api/v1/settings`**, with header `masjid-id: {masjid_id}`, also exposes catalogue feature identities in `data.activated_features`. It adds `data.bottom_tabs`. This is the website-compatible settings surface; it is not the launch-critical native feature request.

**GET `/api/mobile/masjids/{masjid_id}`** is the app's organisation detail payload. It contains no feature list today but adds the same `data.bottom_tabs`, so consumers reading organisation settings there receive the same preference. The tab objects on all three readers have identical content. This repo's routes/docs establish the server surfaces; which newly shipped native decoder reads the new key must be verified by the native app shippers.

All existing fields keep their original values and order. Only this one key is additive. A literal feature response fragment (synthetic catalogue IDs) is:

```json
{
  "status": "success",
  "data": [
    { "id": 42, "key": "services", "name": "Services", "is_available": 1, "pivot": { "masjid_id": 900, "feature_id": 42, "is_available": 1 }, "icon": { "original_url": "https://example.invalid/services.svg" } },
    { "id": 43, "key": "qur’an", "name": "Qur’an", "is_available": 1, "pivot": { "masjid_id": 900, "feature_id": 43, "is_available": 1 }, "icon": { "original_url": "https://example.invalid/quran.svg" } }
  ],
  "bottom_tabs": [
    { "id": 42, "key": "services", "name": "Services" },
    { "id": 43, "key": "qur’an", "name": "Qur’an" }
  ]
}
```

The example's `data` rows are abridged; existing real feature rows retain every existing field. The `bottom_tabs` value above is complete and exact. Its shape is **1–4 ordered `{id, key, name}` objects**, with integer catalogue ID, exact stored key and current catalogue display name. `name` is the title field already used by both feature lists. Do not normalise apostrophes, transliterate keys, derive IDs from key spelling or treat a selected ID as a tenant grant. A title rename is reflected on the next read. Unknown feature identities must be skipped by the apps.

The corresponding complete added value on the detail and v1 settings responses is:

```json
"bottom_tabs": [
  { "id": 42, "key": "services", "name": "Services" },
  { "id": 43, "key": "qur’an", "name": "Qur’an" }
]
```

Apps render **Home first**, then recognised entries in this order. Home is never sent, stored or selected. **Absent key or null means use today's built-in four fixed tabs.** The server emits no key when the grant is off, nothing is chosen, or all stored choices are unavailable. It never emits `[]` or uses an empty list to mean “no tabs”. If a non-empty response contains only identities an app does not recognise, the app skips them; the native shipper decides its remaining Home-only rendering, rather than interpreting that response as an instruction to erase Home.

An organisation reached by switching from a parent gets **its own grant and its own selections** by exactly this rule. A parent's preference is never inherited. The payload for an organisation describes that organisation only. Inheritance, if the owner wants it, is a follow-up.

**GET `/api/mobile/masjids/{masjid_id}/menu` remains unchanged**, including its sections, built-in `tabs`, profiles, hash, ETag and 304 handling. **GET `.../orgs` remains unchanged**. The feature-icons response is the `/features` response; its array, key strings, icon envelopes and SQL remain unchanged. Unchosen features stay in the app's side menu exactly as before. Supporting native releases must read `bottom_tabs` independently of the existing `/menu` tabs; this build does not change the legacy or switch-derived side-menu contracts.

The minimum supporting app version is set by whoever ships iPhone and Android, not by this server. Older apps ignore the new key and keep their built-in tabs. The admin explains that changes reach phones when people next open the app, only on versions that support them.

## Storage, writes and retirement

`app_bottom_tabs` is a side table. Keeping this out of `masjids` preserves all pinned public/raw organisation fields; keeping it out of the existing pivot preserves the feature array and allows deleted or detached choices to remain visible until saved again.

Columns: `id` (auto-increment primary key), `masjid_id` (organisation FK), nullable `feature_id` (catalogue FK), `position` (unsigned tiny integer, 1–4 from the writer), `saved_key` and `saved_title` (TEXT catalogue snapshots), `created_at`, `updated_at`. Short named constraints: `abt_org_fk`, `abt_feature_fk`, `abt_position_uq` and `abt_feature_uq`. The unique organisation-position and organisation-feature indexes defend order/duplicates; the writer enforces the four-item bound. Snapshot text has no artificial VARCHAR limit and carries only catalogue vocabulary; staging scrub explicitly reviews both fields as non-personal catalogue text.

`AppBottomTab` uses `BelongsToMasjid`. The route's verified tenant supplies the organisation ID; caller body/query/header organisation IDs are ignored. The editor and writer additionally refuse unbound or mismatched service calls with 403, before issuing preference SQL. The new model has a cross-tenant read/update/delete/stamping test in `AppBottomTabModelIsolationTest`.

Save accepts **`{"feature_ids": [42, 43]}`** (ordered catalogue IDs), or **`{"feature_ids": []}`** to restore built-in defaults. No snapshot text, Home, organisation ID or pivot row ID is accepted as a selection. One transaction starts with one primary-key `FOR UPDATE` lock on the organisation, then rechecks the grant, at most four distinct positive IDs, and that each feature exists and is switched on on THIS organisation's pivot. All rows are replaced atomically; refused writes leave the old list intact. Duplicate integer identities are refused even if different submitted string spellings would normalise to the same ID.

While opted in, the platform feature-availability writer uses the same organisation mutex. Its OFF branch retains the base statement list and response. Catalogue deletion uses existing cascade/null FK behavior; a concurrent feature deletion during preference save is translated to a field-level 422 instead of exposing an FK error.

Retention decisions:

- Disabling the tab grant retains all selections and adds no mobile key; enabling it later restores whatever remains valid.
- Switching off or detaching a chosen organisation feature retains its row/order/snapshots. Mobile readers omit it immediately; the editor shows “no longer available” until another save. Switching it back on before saving makes the retained selection available again.
- Deleting a catalogue feature cascades its existing pivot rows and sets `app_bottom_tabs.feature_id` to null. The retained key/title and position remain for the editor. Direct SQL deletion has the same FK behavior. No new delete-path guard or FK refusal is added.
- A next Save sends only currently available choices in their current relative order and removes the unavailable retained rows. A stale feature that became unavailable after loading is refused atomically, with the draft kept for correction.
- Organisation soft delete retains preferences; restore retains the original order. Hard/force delete cascades the table, including while the grant is off. No orphan preferences, contact linkage, member-specific records or additional account-erasure policy are introduced.

There is no Home row in the current eleven-feature catalogue. For a future catalogue insertion, exact key `home` or exact display name `Home` is reserved and refused; ordinary catalogue identity strings otherwise round-trip unchanged. This is a defensive decision for a future Home row, not a rewrite of existing feature keys.

## Admin access and screen

- SPA: `/masjid/app-tabs`, sidebar **App tabs**, in the organisation settings/app area.
- API: **GET** and **PUT** `/api/admin/masjids/{masjid_id}/app-bottom-tabs`.
- Both use `auth:sanctum` → `admin` → `tenant` → `capability:app_bottom_tabs`, outside `crm`. Roles: **MasjidAdmin** for its own organisation and **SuperAdmin** for the named organisation. This reuses the general-settings `UserAdminMiddleware::ADMIN_TYPES` fence; general settings has no granular Spatie permission to reuse. Teacher, LunchStaff and app-member realms cannot use these routes. Cross-organisation admins get 403; grant off gets 404 even for SuperAdmin. OFF navigation is hidden for every role and typed SPA navigation goes to `/auth/404` after the organisation payload loads.
- Read returns `data.features: [{id,key,name}]` of selectable switched-on catalogue rows and `data.chosen: [{id,feature_id,key,name,available}]` in retained order. `chosen.id` is the selection row ID, not a feature identity. A deleted choice has `feature_id: null`; `available: false` uses saved display text.
- The phone preview locks Home first; add/remove and Up/Down buttons change a local draft. Native buttons support keyboard/touch; drag is unnecessary. Four chosen slots include unavailable retained choices until removed or saved. The limit is explained in words. One Save persists the draft; successful responses replace it with the authoritative server state, failures keep it. Unsaved changes are visible; route exit and browser close/reload ask before discarding them. Loading or changing organisations invalidates stale responses.

Platform activation uses the existing audited `CapabilityWriter` via `PATCH /api/admin/masjids/{id}/capabilities/app_bottom_tabs` with `{"enabled":true}`. A platform operator can explicitly preview its hidden switch with `GET .../capabilities?include_app_bottom_tabs=1`; the default OFF panel/catalogue stays literal. This build adds no new organisation-admin feature toggle.

## Cache and query behavior

OFF `/features` retains the old cached collection verbatim. Only ON entries carry a private cache marker, never sent to clients. Existing capability writer family invalidation retires feature/detail caches after a grant flip commits. Choosing tabs flushes those two existing caches; side-menu/orgs caches are not changed by preference saves.

ON feature/detail reads use one fresh joined SELECT to check the current organisation grant, selection rows, catalogue and pivot even when the legacy response is cached. V1 reuses its already-loaded organisation and adds the same one SELECT. No preference or availability result is cached. This prevents a removed/disabled choice from surviving a warm cache. Cold and warm base feature/detail/v1 statement lists are pinned literally, with **zero new queries OFF and exactly one ON**, regardless of tab count; menu/orgs statement lists remain literal.

## Local verification

New base recordings were made before implementation on b531a07d: `tests/fixtures/app-bottom-tabs-off-b531a07d.json` (all five cold/warm bodies and SQL lists) and `app-bottom-tabs-menu-b531a07d.json` (all existing admin menu descriptors for both admin roles, Teacher and LunchStaff across all three verticals). No existing fixture is changed and no `CAPTURE_*` variable is used.

`AppBottomTabsTest`, `AppBottomTabModelIsolationTest` and mounted `resources/vue-app/tests/app-bottom-tabs.test.ts` cover the contract, order, unavailable rows, invariants, exact Unicode, child preferences, fences, retirement, schema, mutex order, empty/default state, additive-only fields and menu/SQL preservation. `TenantScopingCoverageTest`, `StagingScrubCoverageTest` and `Studio/ProvisionResponseSnapshotTest` exercise integration guards. The new SFC is included in the bare-template guard list and uses only app theme classes and logical/wrapping layout rules. Local PHP/SPA/build evidence and browser/MySQL limits are recorded in `STATE.md` and the build report; native decoding/version behavior belongs to the app shippers.
