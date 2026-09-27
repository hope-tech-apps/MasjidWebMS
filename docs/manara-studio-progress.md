# Manara Studio W2 + W3 — program board

Maintained by the point session (it owns `main` and the production ship order). Track sessions
report to point, and point updates this file — track branches never edit it, so it never conflicts.
(It lives here rather than in STATE.md because this repository gitignores STATE.md.)
Last updated 2026-09-27.

## Programs in flight

### Manara Studio W2 + W3 (owner goal: finish both)

Contract `docs/manara-studio.md` (D1–D17); plans `docs/manara-studio-w2.md` (S1–S19) and
`docs/manara-studio-w3.md` (S1–S22), owner answers recorded 2026-09-24 in both. W1 is live
(S1–S11 on prod `65e8b517`); W1 S12 (retire the old wizard) waits on the first real Studio client.

Tracks — one session each, each on its own branch per slice, handed back to point to merge and ship:

| Track | Slices | Repo(s) | Starts | Blocked on |
|---|---|---|---|---|
| T1 Domains lifecycle | W2 S1 → S2 → S3 → S4, S5, S6 | MasjidWebMS | now | S5: a Cloudflare token scope for redirect rules; every S6 run: owner's go |
| T2 Studio on live orgs | W2 S7, S8 → S9 → S10, S11, S12 → S13 | MasjidWebMS, renderer | now | S12: owner reviews the Arabic labels; S13 ships to the live renderer |
| T3 Apps plane + push | W2 S15 → S16 → S14 → S17 | iOS, Android, MasjidWebMS | now | S14: owner's OneSignal organisation key + `ONESIGNAL_ORG_ID`; S17 needs T2's S9 |
| T4 TV | W2 S18 → S19 | iOS (MasjidKit, MasjidTV), Android, MasjidWebMS | now | S19 needs T3's S16 + S17 |
| T5 Renderer export | W3 S16, then W3 S2 | renderer, iOS | now | — |
| (later) iOS/Android refactor | W3 S1, S3–S10 | iOS, Android | after T3 lands W2 S15 | — |
| (later) Repos, export, stores | W3 S11–S15, S17–S22 | new repos, MasjidWebMS | after W3 S8 + S10 | owner: ops credentials, the dedicated runner Mac, making this repo private |

Rules every track follows: branch from the latest `origin/main`; never push to `main` or ship —
hand the branch to point; one test suite at a time on the droplet through `/root/manara-test-run.sh`
with a `/root/manara-ci-<track>` tree; this repository is PUBLIC, so no secret, key or id in a commit;
no Co-Authored-By or AI attribution lines; mobile work means iOS AND Android.

### Status board

| Slice | Track | State |
|---|---|---|
| W2/W3 plans merged to main | point | done 2026-09-27 |
| Studio walkthrough with NAFIS (demo, not a real client) | point | in progress |
| Lesson-plan standards autofill (Al-Razi teachers' report) | point | in progress |
