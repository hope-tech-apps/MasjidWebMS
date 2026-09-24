# Manara Studio — design

The SuperAdmin flow that takes a client from a name to a live, themed, feature-
correct product set: website, iOS, Android, tvOS. Owner-only, internal.

Interviewed and decided 2026-09-23. Sixteen decisions are recorded below with
the reason each was taken; anything not decided is in **Open** at the bottom.

---

## 1. What Studio is

**Studio is the existing onboarding wizard, renamed and grown** — not a second
machine beside it. `/dashboard/super/onboarding` already does Step 0 and most of
provisioning today, transactionally, with BYO store credentials and a working
app-generation job plane. Rebuilding that would be rewriting the half that works.

The four steps the owner described, mapped onto what exists:

| Step | Name | Today | Work |
|---|---|---|---|
| 0 | Foundational — name, kind, brief, brand, platforms | **exists** (Identity / Prayer / Brand / Apps) | logo UPLOAD, slug + domain, description |
| 1 | Feature list, the nitty-gritty | 11 legacy mobile keys | the real 24 modules + 9 grants, org-kind filtered |
| 2 | Layout and design, previewed per platform | **nothing** | layout model, device mockups, starter pages |
| 3 | Generate, export, open | apps only, no repo, no web | web tenant + DNS, repos, export, tvOS |

## 2. Ground truth — what already exists

Read 2026-09-23 against `main` @ `d746534`. Paths are load-bearing; check them
before assuming anything below is still true.

**Provisioning.** `POST /api/admin/onboarding/provision`
(`app/Http/Controllers/AdminDashboard/OnboardingController.php:110`) creates, in
ONE transaction: the `masjids` row, `theme_settings` (four hex colours),
`masjid_abouts`, prayer calculation + iqama + Jumu'ah settings, donation link,
social links, a `masjid_mobile_app_features` pivot row for every catalogue
feature, the vertical's starter forms, `masjid_app_publishing`, and optionally a
MasjidAdmin user + membership. Invite emails are collected inside the
transaction and sent only after commit. `GET /onboarding/options` serves the
whole catalogue in one fetch.

**Platforms and store credentials.** `ProvisionMasjidRequest:152` already
validates `platforms` against `ios|android|tvos|web` and refuses tvOS without
iOS — they ship under one Apple account. `masjid_app_publishing` holds
`enabled_platforms`, per-platform `account_mode` (`managed`|`byo`), and
encrypted ASC `.p8` / Play service-account JSON, never echoed back.

**App generation.** `POST /api/admin/masjids/{masjid_id}/provision-apps` writes a
`provisioning_jobs` row per platform with its own callback token and fires a
GitHub `repository_dispatch` (`scaffold-masjid`). The runner reports to the
unauthenticated `POST /api/provisioning/callback`, authorised by that per-job
bearer. Statuses: queued → dispatched → scaffolding → building → uploaded/built/failed.

**The iOS scaffolder.** `scripts/scaffold_masjid_app.rb` in the iOS repo
(`~/Developer/NewMasjidSystem-r0`) duplicates the golden Burlington target:
bundle id, display name, team, app-icon set, a generated
`Masjid/Config/BuildMasjid+<Slug>.swift` carrying `masjidId`, a shared scheme,
and optionally a `<Name> TV` scheme.

**tvOS exists.** `MasjidTV` (App / Data / Resources / Signage) is a real tvOS
signage app in the same Xcode project, already consuming the shared `MasjidKit`
SwiftPM package. The iOS app is NOT yet on MasjidKit — the repo calls that a
deliberate fast-follow, and it is now on Studio's critical path (§4, W3).

**The website.** One Nuxt deploy serves every tenant by Host. Content is API
data; four hex colours are the only theme an admin can edit. The per-client
standalone sites that exist (`mec-web`, `intellicor-web`, `mas-youth-web`,
`al-razi-school-web`) were hand-built, not generated.

## 3. The three landmines

1. **OneSignal's app id is hardcoded in both native apps.** Every white-label
   app currently shares Burlington's push channel. Any client app shipped before
   this is fixed can push to, and be pushed by, the wrong congregation. Studio
   creates a OneSignal app per client and the apps read the id from config.
2. **There is no `domain` column.** Nothing in the backend maps a host to an
   organisation; the map lives in the Nuxt repo's code (`docs/tenant-host-map.md`).
   Studio cannot provision a website until that map is data.
3. **"The feature list" exists in at least seven copies** —
   `config/capabilities.php`, `Masjid::MODULE_KEYS` + `MODULE_DEFAULTS`,
   `Capability.ts`, the sidebar's placement, `config/capability_groups.php`,
   `config/verticals.php`'s 11 legacy keys, and the `mobile_app_features` table.
   Studio READS the catalogue through one server endpoint. It must not become
   the eighth copy.

## 4. Decisions

Each is the owner's, taken 2026-09-23, with the reason.

**D1 — Studio is the existing wizard, renamed and grown.** The transactional
provision call, the BYO credential handling and the job plane are kept.

**D2 — Web output is BOTH, and Manara-managed is the default.** The normal
deliverable is a live tenant on the shared renderer: the client keeps getting
renderer improvements, and the site is data. A standalone single-tenant repo is
an OPTIONAL export, explicitly not managed by Manara once handed over. Rationale:
a per-client repo freezes that client's site at generation time; nobody should
get that by accident.

**D3 — Generated code is an internal build artifact.** Repos live in the Hope
Tech org. Clients get apps and sites, not source, unless a handover is agreed.

**D4 — Generation is templates + config; an LLM writes only words.** The repo is
a known-good template with the client's config, theme and flags written in —
deterministic, reviewable, and it compiles because the template compiles. The
LLM writes copy and the layout suggestion, nothing structural. This is also what
keeps Step 3's token spend near zero, which is the point of Steps 0–2.

**D5 — Client repos hold config; MasjidKit is a pinned dependency.**
`manara-<client>-ios` carries bundle id, name, assets, theme and overrides; the
app comes from MasjidKit at a pinned version, so a platform fix is a version
bump rather than N hand-edits across client repos. Requires finishing the
iOS-onto-MasjidKit refactor, which is therefore part of W3, not optional.

**D6 — Preview is a themed mockup, not a build.** Device frames rendered from
the chosen theme, features and layout. It shows the DECISION. Building four real
products to decide whether the header should be dark is the spend Step 2 exists
to avoid.

**D7 — Draft at Step 0, live at Step 3.** Studio keeps a draft while you walk the
steps; approving Step 3 creates the org, capabilities, theme, pages and invite in
one transaction. An abandoned draft leaves no half-configured live tenant in the
counts.

**D8 — Approving the layout writes real pages and sections, with starter
content.** The site is demo-able the moment it exists. Starter content is built
ONLY from what the client actually gave (name, address, times, description) plus
clearly-marked neutral placeholders. Nothing about a congregation's history,
scholars, programmes or numbers is invented — see
`sahaba-talks-authenticity-rule` and the MEC migration's standing rule.

**D9 — Studio creates a OneSignal app per client.** Fixes landmine 1. The app id
is stored against the org and read from config by the apps.

**D10 — Repo only at Step 3, with optional toggles.** The default deliverable is
a repo that builds. Creating the ASC record, creating the Play listing and
uploading a first build are individual opt-in switches, default OFF, because a
wizard that can publish to the developer account is a different blast radius.

**D11 — tvOS is built for real**, templatised from `MasjidTV`. Screens: prayer
times with an iqama countdown; announcements and the events calendar; a donation
/ fundraising appeal. The school-flavoured TV board (bell schedule, lunch menu)
is NOT in scope.

**D12 — Studio is internal.** A SuperAdmin screen. No client-facing intake.

**D13 — The palette is extracted from the uploaded logo, and you can override
it.** Contrast is checked before a theme is proposed. Imagery is never generated:
photographs and artwork stay client-supplied, with neutral placeholders where
nothing was sent.

**D14 — Org kind filters the feature list.** A non-school never sees school
features. The filter is the existing `org_type` defaults, not a new rule.

**D15 — Platform set stays `ios | android | tvos | web`,** tvOS still requiring
iOS.

**D16 — Build order: (1) Steps 0–2 and a real website, (2) the tvOS template,
(3) the repo/export machinery.**

## 5. Build order

### W1 — Steps 0–2 and a live website
The shortest path to walking a client end to end.

- **Logo, properly.** Upload at Step 0 (today the file is sampled in the browser
  and discarded), store it on the org, derive the palette, check contrast,
  let it be overridden. Generate favicon and share image from the same asset.
- **Identity fields.** `slug`, `description`/vibe, and a `domains` table with a
  runtime host→org lookup, so the renderer stops being code-edited (landmine 2).
- **Step 1, the real catalogue.** One `GET` serving the 24 modules + 9 grants,
  grouped by `config/capability_groups.php`, filtered by org kind, each with what
  it actually turns on in plain words. One bulk writer to apply the chosen set —
  today every flip is its own PATCH with a ledger row, so N flips is N requests.
- **Step 2.** A small set of layout presets per vertical; device mockups for the
  four platforms rendered from theme + features + layout; approval writes real
  pages and sections with starter content (D8).
- **Step 3, web only.** Provision the tenant, attach the hostname, seed the pages,
  send the invite, open the live URL.

### W2 — tvOS
Templatise `MasjidTV` into a per-org signage app (D11), add `tvos` to
`ProvisioningJob` and to the scaffolder's platform switch, and give it the same
config-not-constant treatment as the phones.

### W3 — Repos and export
Finish the iOS-onto-MasjidKit refactor (D5), then per-client repo creation from
a template, the standalone web export (D2), the source download, and the three
store toggles (D10).

## 6. Open

- **DNS.** Who owns the client's domain, and should Studio call the Cloudflare
  API to attach the hostname, or stop at "add this CNAME"? W1 cannot finish
  without an answer.
- **How many layout presets** per vertical is enough to feel like a choice
  without becoming a page builder?
- **Managed Apple/Play accounts.** Do clients ship under Hope Tech's accounts by
  default, with BYO as the exception, or the reverse? The data model supports
  both already; the DEFAULT is a business decision with review and liability
  attached.
