# Manara Studio W2 and W3: planning brief (delegated)

Written 2026-09-24 by the point session (Abdullah) for a delegated planning session. The
owner asked for W2 and W3 to be sliced like W1 and for the work to be delegated while the
point session ships W1's last slices. **This is planning only: no code changes, no
production, staging or store access, no deploys.**

## 0. The kit, first

Every Claude Code session on this Mac loads the engineering-excellence kit from `~/.claude`
(core via `~/.claude/CLAUDE.md`, a SessionStart hook, the `codebase-explorer` agent). Confirm
it loaded; if not, read `~/.claude/engineering-excellence/CLAUDE.md` first and say so. Probe
each repo you read, load only its matched addenda (iOS, Android, web, backend), and persist
recon to `.claude/<platform>-recon.md` in the repo you are planning for. Working files
(`STATE.md`, `DECISIONS.md`, `ASSUMPTIONS.md`) live at this repo's root; `STATE.md` is
gitignored here.

## 1. What to produce

Two plan documents in the same shape and rigour as `docs/manara-studio-w1.md`:

- `docs/manara-studio-w2.md`: W2.
- `docs/manara-studio-w3.md`: W3.

Each has: what it delivers and its exit criterion; a slice map (repo, risk to live tenants,
hard dependencies, a size estimate marked as an estimate); contract conflicts resolved with a
one-sentence reason; ship paths per repo; preflight reads; each slice's contract, files,
named tests, live impact and "verify in production"; what it does not do; observed-but-out-
of-scope; open questions with a recommended default. Every factual claim cites `file:line`
from code you read, not memory; unverifiable is labelled `Unknown, needs investigation`.
Use recon subagents for breadth, then an adversarial review of the plan before handing back
(the W1 plan was built that way: six recon reports, a synthesis, a reviewer who caught five
unsafe contracts).

## 2. Scope

**W2 (spec §5, `docs/manara-studio.md:237-240`):** tvOS as a per-org signage app (D11), `tvos`
in `ProvisioningJob` and the scaffolder, config-not-constant treatment like the phones. PLUS
the items `docs/manara-studio-w1.md` §6 defers to W2, each either sliced or explicitly left
out with a reason:
- editing EXISTING organisations from Studio (open a live org, the bulk capabilities PATCH,
  `setCapability` delegating to a guarded `CapabilityWriter::apply()`, regenerating brand assets);
- phone-app generation from Studio, and D9 (a OneSignal app per client) which must land
  before the first Studio-generated app;
- the MasjidAdmin placeholder checklist and `studio:apply-layout`;
- Arabic starter labels and a website-locale field in the host lookup;
- hostname lifecycle (detach/remove, Cloudflare cleanup on org force-delete), apex↔www
  canonical redirects, periodic re-confirmation of confirmed hosts, a reviewed tool for the
  frozen imported rows;
- **the Cloudflare Pages custom-domain ceiling** (100 per project on Free; ~47 two-host
  clients left): a monitoring slice that reports usage to the owner at thresholds, and a
  documented retirement procedure. Owner, 2026-09-24: "When we get there we will handle it
  insha'Allah but definitely keep me up to date and where we need to start retiring some we will."

**W3 (spec `docs/manara-studio.md:242-245`):** finish the iOS-onto-MasjidKit refactor (D5),
per-client repo creation from a template, the standalone web export (D2), the source
download, and the three store toggles (D10).

## 3. Owner decisions already made (do not relitigate)

- D1–D17 in `docs/manara-studio.md`.
- **App-store accounts default to Hope Tech's own (managed)**; the client's own (BYO) is the
  exception (owner, 2026-09-24). The code already defaults to managed
  (`app/Support/Studio/OrganisationProvisioner.php`, `apps[*].account_mode ?? 'managed'`;
  `PlatformsPanel.vue` sets `managed` on select). Plan store publishing on that default.
- Mobile work means BOTH platforms (iOS and Android) by default.

## 4. Where things are (leads, not sources: read them)

- MasjidWebMS (this repo): Studio backend and SPA are live as of `718c59b2` (W1 S1–S8).
  `docs/manara-studio-w1.md` §6 (deferred) and §7 (observed) are your starting list.
- iOS + tvOS: `~/Developer/NewMasjidSystem-r0` (and sibling `NewMasjidSystem-*` worktrees;
  find the one on its main line) — per-org targets with `BuildMasjid+*.swift`, `MasjidKit`,
  `MasjidTV`, `scaffold_masjid_app.rb`.
- Android: `~/Developer/burlington-masjid-Android` (and `Android-MAS-App`).
- Renderer (web): `~/Developer/burlington-masjid-site` (Nuxt 4 on Cloudflare Pages; the
  shared checkout is on another session's branch — read `origin/main` with `git show`).
- W1 S9–S11 (CORS from `masjid_domains`, the renderer runtime host lookup, switching it on)
  are being built by the point session now; assume they land.

## 5. Rules

- Read-only on every repo. Create worktrees for reading if you need a clean tree; never edit
  the shared checkouts (`~/Developer/MasjidWebMS` is the point session's).
- Write the two plan documents in a worktree of MasjidWebMS on a new branch
  `docs/studio-w2-w3-plan` off `origin/main`, commit (no Co-Authored-By or AI attribution),
  and push the branch. Hand back the branch, a summary, and the open questions that need
  the owner. Production and every code change stay with the point session and the owner's go.
