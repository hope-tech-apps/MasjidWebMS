# Recon: existing-org-editing

> Read-only recon for the Studio W2/W3 plans, 2026-09-24. MasjidWebMS `c3fc0324`, iOS `origin/main` `8e5191f`, Android `origin/master` `cf61d54`, renderer `origin/main` `6a7ead2`.
> Line numbers are as of those commits; re-read before relying on one. This repo is PUBLIC: identifier-shaped values (OneSignal ids, Apple team ids, key ids) are redacted as `[redacted]`.

## Facts

[F1] `CapabilityWriter` has exactly one method, `applyAtCreation(Masjid $new, array $desired, ?int $actor)` (app/Support/CapabilityWriter.php:44). It compares each value from `CapabilityCatalogue::resolve` with `defaultAtCreation` and skips a match with no write and no ledger row (:51-56). It writes a departure to the column for crm/assistant, otherwise to `capability_overrides` (:63-69). It saves through `forceFill` (:75), ledgers each departure (:82) and flushes `MobileCache::flushFamily` after commit (:89). The docblock says it has no Giving guard (:27-31), never touches the pivot (:33-34), and that "the guarded bulk apply() for existing organisations is W2 (R21)" (:36).

[F2] `CapabilityCatalogue::resolve` fills every served key the caller did not send with `defaultAtCreation` (app/Support/CapabilityCatalogue.php:174-189, esp. :183-185).

[F3] `CapabilityLedger::record` writes one append-only `masjid_capability_changes` row plus one `Log::warning`. It must be called inside the saving transaction and is called for no-op flips too (app/Support/CapabilityLedger.php:13-15, :25-52).

[F4] The four single switches, each SuperAdmin-checked with an in-controller `abort(403)`:
- `setCrmAccess` (app/Http/Controllers/AdminDashboard/MasjidsController.php:155): transaction plus ledger :165-171, `flushFamily` :176.
- `setDirectoryListing` (:200): ledger key `directory_listing` :221, flushes `MASJIDS_LIST` and family :226, :234.
- `setAssistantAccess` (:246): ledger :260. It does no MobileCache flush (:255-266).
- `setCapability` (:282): refuses column-backed or unknown keys with 422 (:288-299), runs the Giving refusals (:314-346), always stores the override explicitly (:275-277, :353), ledgers with `override_before` (:359), flushes family (:366) and returns `ADMIN_APPENDS` (:370). It takes no row lock.

Routes are at routes/admin.php:687, :690, :695, :703 and GET `capabilities` at :708. `{capability}` is deliberately unconstrained (:698-701).

[F5] `SetCapabilityRequest` validates only `enabled` (app/Http/Requests/Admin/Masjids/SetCapabilityRequest.php:16-21). `setCapability` looks up `config("capabilities.{$capability}")` (MasjidsController.php:288), and `hasCapability` does the same dotted lookup (app/Models/Masjid.php:431-433). Studio's request uses a top-level `array_key_exists` instead (app/Http/Requests/Admin/Onboarding/ProvisionMasjidRequest.php:405), per DECISIONS.md:2849-2850 ("Capability keys are top-level config keys").

[F6] `GivingSwitch::liveSubscriptionCount` (app/Support/GivingSwitch.php:63), `billedAfterCancelCount` (:87, which can call Stripe via `stripeStatusOf` :101) and `openCheckoutCount` (:119).

[F7] What the W1 plan says W2 must build:
- R21: "W1 ships `applyAtCreation` only. The rest is W2", and the rest is "a guarded `apply()`, a `PATCH` endpoint and `setCapability` delegating to it". Delegation "is the only change that can move the live switch panel" (docs/manara-studio-w1.md:111).
- S8 repeats this (docs/manara-studio-w1.md:1312-1313).
- §6 lists the same W2 candidates: the bulk `PATCH /api/admin/masjids/{id}/capabilities`, `setCapability` delegating to a guarded `CapabilityWriter::apply()`, regenerating brand assets for an existing org, and Studio opening a live org (docs/manara-studio-w1.md:1920-1925).
- The spec's motive: "One bulk writer to apply the chosen set — today every flip is its own PATCH" (docs/manara-studio.md:228-230).
- S8 requires two ledger policies: the single switch ledgers no-ops and `applyAtCreation` ledgers only departures (docs/manara-studio-w1.md:1409-1411; DECISIONS.md:2709-2715).

[F8] The S2b cutover:
- W1 §6: "Live orgs' pivot/switch disagreements are not fixed. The S2b cutover owns them (catalogue live impact 2)" (docs/manara-studio-w1.md:1952-1953).
- `app-features:cutover-plan` is read-only (app/Console/Commands/AppFeaturesCutoverPlan.php:99-104). It says S2b replaces the `masjid_mobile_app_features` pivot with module switches, with resolutions in `config/app_feature_cutover.php` "before the migration is written" (:25-35). It exits 2 on blocking findings (:108, :537).
- `config/app_feature_cutover.php` does not exist at c3fc0324 (checked with `ls`). The command reads it as null (AppFeaturesCutoverPlan.php:183, :479-481).
- Until the cutover migration, the pivot and the switches may disagree and neither is written from the other (.claude/rules/verticals.md, "stop being separate at S2b").

[F9] ASSUMPTIONS.md:37 (row 14, 🔴): a Studio school or community org given content for a module that is off by default (for example a donation link) gets one BLOCKING (a) finding on `donation_link`. The first such org "blocks the S2b cutover until the owner adds a resolution". It is open whether to resolve such orgs in config or make the command non-blocking when the module is already off. It is pinned by `StudioProvisionCapabilitiesTest::a_studio_org_has_no_blocking_cutover_finding` (tests/Feature/Studio/StudioProvisionCapabilitiesTest.php:158).

[F10] `AppFeaturePivot::seedFromSwitches` throws if the org already has pivot rows (app/Support/AppFeaturePivot.php:58-61).

[F11] The live switch panel is `resources/vue-app/components/super/OrganisationSwitchesPanel.vue`, mounted in `views/dashboard/super/masjid/MasjidDetailsView.vue:138`.
- It loads with `GET /api/admin/masjids/{id}/capabilities` (OrganisationSwitchesPanel.vue:348).
- One flip is `PATCH .../capabilities/{key}` with a form-encoded `URLSearchParams` `enabled` of `'1'`/`'0'` (:552-558), followed by a re-fetch (:577) and an `updated` emit.
- A 422 is shown as "Not changed" with the server's sentence (:582-591). It refuses writers other than `capability` (:537).
- CRM, Assistant and directory listing are separate PATCHes in MasjidDetailsView.vue:801, :872 and :754.

[F12] `studio_drafts`:
- Columns: status, current_step, lock_version, name, org_type, `answers` json, logo_* (disk, path, original_name, mime, size, sha256), `provisioned_masjid_id` (nullable, **unique**, FK masjids, nullOnDelete), provisioned_at, created_by, updated_by (database/migrations/2026_09_24_000000_create_studio_drafts_table.php:33-68).
- Statuses are only `draft|provisioned` (app/Models/StudioDraft.php:30-33).
- `ANSWER_SECTIONS` = identity, prayer, brand, content, features, layout, platforms, domain (:38).
- The model is not tenant-scoped (:15-17).
- Routes are under `studio` with `super`: drafts CRUD, logo, preview, provision (routes/admin.php:1662-1684).

[F13] Where each answer section lands in a live org:
- identity → `masjids` columns (app/Support/Studio/OrganisationProvisioner.php:79-116), `donation_links` (:171-178), `masjid_social_media_links` (:181-193), admin user (:326).
- prayer → `prayer_calculation_settings` (:138-142), `iqama_time_settings` (:153-162), `jumaa_settings` (:165-168).
- brand → `theme_settings` colours (:121-126), plus `tokens.color.on*` via `ApplyDraftBrand` (app/Support/Studio/ApplyDraftBrand.php:22-32), plus media.
- content → `masjid_abouts` (OrganisationProvisioner.php:129-135).
- features → `capability_overrides` or the columns, plus the pivot (:221-226).
- layout → pages, sections and `page_section` via `StarterSite::applyTo`, plus `theme_settings.tokens.layout` (:258-266).
- platforms → `masjid_app_publishing` (:280-307).
- domain → `masjid_domains` (:388-407).

The live admin writers for the same data carry `renderer.purge`: details, general-settings, donation-link, about, theme save, pages and sections (routes/admin.php:212, :219, :307, :314, :426, :498-517).

[F14] Brand assets:
- `LogoDerivatives::generate(StudioDraft $draft, string $bg)` reads the draft's private logo bytes (app/Support/Studio/LogoDerivatives.php:41-44). It writes a 48×48 transparent favicon, a 180×180 opaque touch icon and a 1200×630 share image to `storage/app/private/studio-tmp/` (:47-73).
- `ApplyDraftLogo::apply(Masjid, LogoFiles)` adds media to `logos`, `favicons`, `touch_icons` and `share_images` with `preservingOriginal()` (app/Support/Studio/ApplyDraftLogo.php:25-43).
- `StudioBrandGate::assert` takes a `StudioDraft` (app/Support/Studio/StudioBrandGate.php:52). `PaletteContrast::report(array $brand, …)` is pure (app/Support/Studio/PaletteContrast.php:64).
- `Masjid` registers no media collections (no `registerMediaCollections`/`singleFile` in app/Models/Masjid.php). `logo()` returns the latest `logos` row (:626-632). The derivatives resolve through `brandDerivative` (:665-682).

[F15] Readers of the derivatives:
- `/api/v1/settings` adds `favicon_url`, `touch_icon_url` and `share_image_url` only when the row exists (app/Http/Controllers/Api/V1/SettingController.php:123-138).
- The by-host lookup emits `favicon_url` and `share_image_url` and never falls back to `logos` (app/Http/Controllers/Api/V1/OrganizationByHostController.php:32-36, :65-66). Its route is routes/api_v1.php:29.
- No mobile payload reads them. Mobile reads `logo`: the directory `with('logo')` (app/Http/Controllers/Mobile/MasjidsController.php:35) and AppMenu `logo_url` (app/Support/AppMenu.php:569).

[F16] The existing admin logo uploads write only `logos` and regenerate no derivatives: MasjidDetailsController.php:57-58 (appends) and MasjidsController.php:598-600 (clears, then adds). The latter flushes `flushMasjidAll`, `MASJIDS_LIST` and family (:608-612).

[F17] Renderer purge runs only through the `renderer.purge` middleware (app/Http/Middleware/PurgeRendererCacheAfterWrite.php:31-49), which queues `RendererPurgeScheduler::afterSave`. It is a no-op when purge is not configured (app/Support/Renderer/RendererPurgeScheduler.php). The Studio routes and the capability PATCH routes carry no `renderer.purge` (routes/admin.php:687-708, :1662-1684).

[F18] Placeholders:
- The marker is `sections.settings.studio` = `{version:1, preset, slot, placeholders:[{field, kind, hint, essential, source?}]}` (app/Support/Studio/StarterPlaceholders.php:25-28, :56-81).
- `open` is computed at plan time and never stored (app/Support/Studio/StarterSite.php:497-512; DECISIONS.md:2732-2735).
- The public path strips it (StarterPlaceholders.php:39-47).
- The admin section payload returns `settings` raw (app/Http/Controllers/AdminDashboard/PageSectionsController.php:340). `SectionFormModal` round-trips `settings` (resources/vue-app/components/modals/SectionFormModal.vue:581, :686-687), and update writes only validated keys (PageSectionsController.php:138).
- No admin Vue screen outside Studio reads `settings.studio` (git grep, no hits).
- Paths: resources/vue-app/views/dashboard/pages/PagesView.vue, resources/vue-app/views/dashboard/pages/PageSectionsView.vue, resources/vue-app/components/modals/SectionFormModal.vue.

[F19] `StarterSite::applyTo`:
- It skips any slug the org holds, trashed or not, and never updates or restores (app/Support/Studio/StarterSite.php:170-172, :192).
- It runs in its own transaction or savepoint (:178-180).
- Facts come from `StarterFacts::fromMasjid` (OrganisationProvisioner.php:261).
- No `studio:apply-layout` command exists. The only `studio:` command is `studio:purge-drafts` (app/Console/Commands/PurgeStudioDrafts.php:36). The closest precedent is the idempotent `form:apply-templates` (app/Console/Commands/ApplyFormTemplatesCommand.php:16-26).

[F20] The W1 plan on the checklist: "MasjidAdmin placeholder checklist (layouts §E/§F: a new endpoint, plus badges in `PagesView`, `PageSectionsView` and `SectionFormModal`) and the `studio:apply-layout` command are W2. Both touch live admin screens" (docs/manara-studio-w1.md:1927-1931). The layouts recon report that defines §E/§F is not tracked in the repo (git ls-files, docs/ listing).

[F21] Locale:
- R15: labels are `en` only in W1, because "the payload carries no locale (renderer-lookup contract A)" (docs/manara-studio-w1.md:105).
- `config/studio_layouts.php` has only `labels.en` (:49-52, :68-69). `StarterFacts` defaults to `en` and coerces an unknown locale to it (app/Support/Studio/StarterFacts.php:27, :171-176). `StarterSite` throws when a locale has no labels (app/Support/Studio/StarterSite.php:117-119).
- The by-host payload has no `locale` key (OrganizationByHostController.php:59-67).
- `masjids` has no locale column. `mailing_locale` is an address line (database/migrations/2026_07_22_110000_add_tax_fields_to_masjids.php:11, :22).
- `HostResolver` is the probe's DNS helper, not the lookup (app/Services/Domains/HostResolver.php:5-10).
- The renderer map already supports `locale: 'en'|'ar'` with RTL (renderer:shared/tenant.ts:41, :55, :108-113). Its `match` has no `lookup` yet (renderer:shared/tenant.ts:187).
- Production `NUXT_TENANT_HOSTS` has no locale fields (docs/manara-studio-w1.md:2003).
- The W1 plan defers "Arabic starter labels, and a website-locale field in the lookup" to W2 (docs/manara-studio-w1.md:1932-1934).

## Answers

**1. Capability writers and what W2 must build.** Today's writers are F1, F3, F4, F5 and F6. The plan's W2 items are F7: a guarded `CapabilityWriter::apply()`, a bulk `PATCH /api/admin/masjids/{id}/capabilities`, and `setCapability` delegating to `apply()`. From F1 to F6, `apply()` for a live org must:
- keep `setCapability`'s policy: store the override even when it equals the default, and ledger no-op flips (F4, F3, F7);
- apply only the keys actually sent, reading "before" from current state, never through `resolve()` (F2);
- carry the Giving refusals and their 422 envelope (F4, F6);
- refuse dotted keys at the top level (F5);
- leave the pivot alone (F1, F10, F8).

For the S2b cutover and "catalogue live impact 2": the command exists and writes nothing, the resolutions file does not exist, and the migration is unwritten (F8). Blocking cases are in F9.

**2. The switch panel.** See F11. One flip is one PATCH with form-encoded `enabled` `'1'`/`'0'`, then a full GET re-fetch.

**3. Studio opening a live org.** The draft's shape is F12 and the mapping is F13. What is missing: a draft has no "edits org X" mode.
- `provisioned_masjid_id` is unique, so at most one draft can point at an org.
- The only statuses are `draft|provisioned` (F12).
- `StudioProvisioning::provision` always creates an org (app/Support/Studio/StudioProvisioning.php:65).

Each section would need either a live-org writer or a reuse of the existing admin writers, which already carry `renderer.purge` and cache flushes (F13, F16, F17). There is no live reader today for identity `vibe`, `brand.extracted` or `ink_overrides` (app/Models/StudioDraft.php:57-63).

**4. Brand assets.**
- What is generated and where it is stored: F14. It cannot run against an existing org as written, because `LogoDerivatives` and `StudioBrandGate` take a `StudioDraft` (F14). `ApplyDraftLogo` and `PaletteContrast` are reusable.
- Live readers: F15.
- Cache: a new `logos` row changes mobile `logo_url`, which needs `flushMasjidAll`, `MASJIDS_LIST` and family flushes as at F16. New derivatives change `/api/v1/settings` and the renderer's pages, which need a purge (F17).
- A Studio org whose admin later uploads a logo keeps its old favicon and share image (F16).

**5. Placeholders.** How a placeholder is marked is F18. The checklist and `studio:apply-layout` are F20 and F19. The view paths are in F18. What the checklist endpoint and badges contain (layouts §E/§F) is Unknown (U1).

**6. Arabic.** F21. The public lookup is `OrganizationByHostController@show` at `GET /api/v1/organizations/by-host`, and `locale` would be added at OrganizationByHostController.php:59-67. There is nowhere to store a website locale today: no `masjids` column, and `masjid_domains` would need one (F21). Contract A: W1's lookup payload carries no locale, so a lookup-resolved tenant renders en/ltr (F21).

**7. Studio entries in DECISIONS and ASSUMPTIONS that bind W2.**
- DECISIONS.md:2076-2086 (S1): `resolve()` accepts only real PHP booleans; string coercion stays in the request. A new studio route must be added to `StudioAccessTest::calls()`.
- DECISIONS.md:2102-2131 (S6): `ProvisionContext.actorId` is uncast. `ProvisionResponseSnapshotTest` pins the legacy provision byte for byte.
- DECISIONS.md:2249-2275 (S4): unresolved `linked_page` placeholders exist, and plans never pre-fill bound prose.
- DECISIONS.md:2709-2767 (S8 A):
  - the two ledger policies;
  - "Jumu'ah… W2/W3 must not show it unless it was supplied" (:2728-2729);
  - the marker shape (:2732-2735);
  - Studio always sends `capabilities` (:2742-2744);
  - the brand gate refuses a duplicate name while the wizard does not (:2750-2752);
  - `LivePublicPayloadsUnchangedTest` recordings from fe390d7d (:2767).
- DECISIONS.md:2769-2814 (S8 B): BYO credentials exist only in `StepGenerate` and the provision body. A draft that never opened Features cannot provision.
- DECISIONS.md:2816-2872 (review fixes):
  - account modes are sent only for selected platforms, and the provisioner stores `managed` for the rest (:2845-2848);
  - top-level capability keys (:2849-2850);
  - Step 3's outcome wording.
- DECISIONS.md:2651-2675 (S5): the deviation that iOS `parts` is a module→bool object.
- ASSUMPTIONS.md:36 (row 13, 🟡): the iOS drawer band name. ASSUMPTIONS.md:37 (row 14, 🔴): cutover blocking (F9).

**8. Tests.**
- Capabilities: CapabilityChangeLedgerTest (tests/Feature/CapabilityChangeLedgerTest.php:74 `a_catalogue_flip_records_before_after_the_prior_decision_and_who`, :109, :139 `a_refused_or_invalid_flip_writes_nothing`, :179 `every_flip_is_logged_at_the_deployed_level`), CapabilitiesEndpointTest, CapabilityGateTest, CapabilityTsMirrorTest, ModulesFailOpenTest, GivingSwitchTest, GivingSwitchPreconditionTest, AppFeatureCutoverPlanTest, Studio/AppFeaturePivotTest, Studio/CapabilityCatalogueEndpointTest, and Studio/StudioProvisionCapabilitiesTest (:38, :57, :78, :158, :217 `a_dotted_key_that_names_a_nested_config_array_is_refused_not_dropped`). No test names `CapabilityWriter` directly (git grep).
- Brand: StudioProvisionLogoTest:34, LiveSettingsPayloadUnchangedTest:45 `an_org_with_logos_and_no_derivatives_keeps_exactly_its_previous_key_set`, OrganizationByHostTest:226 `favicon_comes_from_favicons_never_logos`, StudioBrandGateTest, StudioProvisionThemeTest.
- Placeholders: StarterSiteWriterTest (:137 `placeholders_are_recorded_in_settings_studio`, :171 `a_rerun_never_overwrites`), StarterSitePublicPayloadTest:49 and :81, StudioStarterSiteServedProvenanceTest, StudioLayoutPresetsTest (labels pinned to tests/fixtures/studio-layout-labels.json, config/studio_layouts.php:51-52), LivePublicPayloadsUnchangedTest, PublicPayloadKeysUnchangedTest.

## Risks to live clients

[R1] If `setCapability` delegates to `applyAtCreation`'s sparse policy, a SuperAdmin's decision that equals today's default is not stored. A later catalogue default change would then move orgs 1, 13, 14 and 18 unasked, and no-op ledger rows would stop (F1, F4, F7).

[R2] If a bulk PATCH is built on `resolve()`, every key not sent resets to its creation default. On a live org that overwrites existing overrides, for example Burlington's `web_pages` off (F2; .claude/rules/auth-permissions.md "Burlington's site is run by the owner with `web_pages` off").

[R3] A bulk path without the Giving guard could switch Giving off while Burlington's monthly gifts still bill (F4, F6). Running the guard per key inside a bulk transaction may also make Stripe calls (F6).

[R4] Touching the pivot from a live-org apply changes installed apps' drawers for 1, 13, 14 and 18 before S2b. `seedFromSwitches` would throw on them in any case (F8, F10).

[R5] Regenerating brand assets for a live org changes each of these for that org:
- `logo_url` in the app directory and menu;
- the `/api/v1/settings` key set and the tab icon or share card, which R11 and rule F were written to prevent;
- the premise of `LiveSettingsPayloadUnchangedTest`.

It needs a per-org opt-in, cache flushes and a renderer purge (F15, F16, F17).

[R6] Running `studio:apply-layout` against a live org cannot overwrite existing pages, but it adds new active pages and sections under slugs the org does not hold. It would also rewrite `tokens.layout` if the provisioner's step were reused, which changes the header and footer on Burlington's or MEC's live site (F19; OrganisationProvisioner.php:263-266).

[R7] Latent in the live switch today, found by code reading and not executed: `PATCH .../capabilities/giving.defaults` passes the refusal at MasjidsController.php:288-299 and stores a junk override key (F5). A delegating `apply()` should close it.

[R8] The live orgs carry no `settings.studio` marker: the S8 preflight count was required to be 0 (docs/manara-studio-w1.md:1242). The checklist badges must therefore render nothing when the marker is absent. A save that drops `settings` would erase the marker on Studio orgs (F18).

## Unknowns

[U1] What layouts recon §E/§F specify for the checklist endpoint and badges. The report is not in the repo. Resolve by locating the recon file (planning session scratch) or asking the owner.

[U2] Whether every live org (1, 13, 14, 17, 18) has a `theme_settings` row. `ApplyDraftBrand` and the layout write use `firstOrFail`. Resolve with a read-only count on production or staging.

[U3] Whether the admin section list SPA drops or keeps `settings` on reorder and toggle paths beyond `SectionFormModal`. Resolve by reading PageSectionsView.vue's write calls.

[U4] Where a website `locale` should be stored (a new `masjids` column, `masjid_domains`, or `theme_settings`). No existing column exists (F21). This is an owner or plan decision.

[U5] Whether `setAssistantAccess`'s missing MobileCache flush matters. That depends on whether `/menu` reads `assistant_enabled`. Resolve by grepping app/Support/AppMenu.php for `assistant`.
