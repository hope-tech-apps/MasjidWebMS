# Shop B3 (admin SPA) working notes

Recon, 2026-10-01. Copy the neighbours; invent nothing.

## Patterns copied
- Money list with row actions and a CSV: `views/dashboard/FormResponsesView.vue` (collect / undo "Check in",
  `exportCsv` is a bearer-token `fetch` blob download, `Swal` confirm and toast), `stores/masjid/donationsStore.ts`
  (`buildLedgerQuery`, `exportCsv`, `masjidId()`).
- Newer in-page CRUD with money: `views/dashboard/ClassStoreView.vue`, `views/dashboard/offerings/OfferingFeePlansTab.vue`,
  helpers in `core/helpers/*.ts` with no Vue in them so `npm run test:spa` covers them.
- Money in and out: `composables/useMinorUnits.ts` (`parseMajorToMinor`, `formatMinor`). Never `Number(x) * 100`.
- Errors: `core/helpers/serverMessage.ts` (`serverMessage`, `serverFieldErrors`), `core/services/ApiErrors.ts`.
- Routes: one file per feature in `router/routes/`, spread into `dashboardLayoutRoutes.ts`; meta carries
  `requiresCapability: 'shop'` (guard in `router/router.ts`).
- Sidebar: `core/constants/dashboardAsideMenuItems.ts` + icon in `components/dashboard/DashboardAside.vue` MENU_ICONS +
  route type in `core/types/config/SystemRoutes.ts`. Visibility is `menuItemState()` in `core/access/orgAccess.ts`.

## Findings that shape the build
- The admin SPA has NO i18n. Every admin screen writes plain English in the template; the only locale files are the
  family portal's (`views/family/locales/*`) and `views/lunch/lunchI18n.ts`, which are other realms. The shop screens
  follow the admin screens: plain English, so there is no locale gap to leave.
- The SPA carries no per-user permission list. "Has `view donations`" is `allowed_types: ['SuperAdmin','MasjidAdmin']`,
  as for every neighbour: both hold every permission (RolesAndPermissionsSeeder) and the server's gate is the boundary.
- The sidebar is a flat list; it has no children. One "Shop" entry opens Products, and both screens carry a two-tab strip
  (Products / Pickup list) in the page.
- The coordinator's correction: pictures are 10 MB each, read from `meta.max_image_mb` (fallback 10).

## Built (all under resources/vue-app)
- `core/types/data/masjid-related/Shop.ts`, `core/helpers/shop.ts` (pure, tested), `stores/masjid/shopStore.ts`.
- `router/routes/shopManagementRoutes.ts` (parent `shop` + four children), the "Shop" entry in
  `core/constants/dashboardAsideMenuItems.ts`, its icon and route types.
- `views/dashboard/shop/`: `ShopProductsView`, `ShopProductEditorView`, `ShopPickupView`, `ShopTabs`, `ShopRoutes`.
- Tests: `tests/shop.test.ts` (helpers), `tests/shop-screens.test.ts` (the three screens mounted),
  `tests/shop-wiring.test.ts` (sources). `tests/support/mountSfc.ts` gained `focus()`, `querySelector([attr="v"])` and
  `document.getElementById`, which the editor's focus handling asks for.

## API assumptions not confirmed by the B2 code (the brief wins where they differ)
- `lock_version`: on every product answer, sent back in the PUT's JSON body as `lock_version`. B2's `ProductPayload` does not carry it yet.
- The list's on/off switch sends `{active, lock_version}` alone (every other product field is optional in B2's update request).
- `meta.max_image_mb` (coordinator's correction; 10 when absent). B2 still says 25 MB.
- `POST/DELETE sales/{id}/resolve`, and `resolution`, `resolved_at`, `resolved_by`, `summary[].oversold_open` on the pickup list: not in B2 yet.
- Picture answers are `{status, data: product}` with no `meta`, as in B2.
