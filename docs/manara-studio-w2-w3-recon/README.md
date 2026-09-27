# Studio W2/W3 planning inputs (2026-09-24)

The evidence behind `docs/manara-studio-w2.md` and `docs/manara-studio-w3.md`,
kept so a builder can check a citation without re-running the recon. W1's plan
lost one of its recon reports (the layouts §E/§F the W2 checklist was supposed
to follow), which is why these are committed.

| File | What it covers |
|---|---|
| `tvos.md` | MasjidTV, the D11 screens, TV targets and signing, tv-config |
| `apps-plane-and-onesignal.md` | `provisioning_jobs`, dispatch and callback, `masjid_app_publishing`, OneSignal sending and provisioning |
| `existing-org-editing.md` | Capability writers, the switch panel, drafts, brand assets, placeholders, locale |
| `domains-lifecycle.md` | `masjid_domains`, Cloudflare service and attacher, deletion, re-confirmation, the Pages ceiling, owner alerts |
| `renderer-and-export.md` | Renderer tenancy and locale, single-tenant builds, the hand-built sites, export inputs, GitHub org |
| `vendor-facts.md` | App Store Connect, Google Play, OneSignal, Cloudflare and GitHub facts, with sources |
| `review-w2.md`, `review-w3.md` | The adversarial reviews of each draft; every finding was applied |

Platform recon for the two mobile repos is in `.claude/ios-recon.md` and
`.claude/android-recon.md`, per the engineering-excellence kit's convention.
