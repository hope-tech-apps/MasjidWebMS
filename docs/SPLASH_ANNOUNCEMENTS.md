# Splash Announcements — System Overview

A per-masjid splash modal (image + text + optional CTA) that an admin
authors once and that propagates to two surfaces:

1. **Web (Nuxt site)** — read directly from our own API.
2. **Mobile apps (iOS + Android)** — delivered as a OneSignal **In-App
   Message** (IAM). The mobile SDK handles display, scheduling, and
   per-session dismissal. **No new mobile code is required** — the
   existing OneSignal SDK integration already handles IAMs out of the box.

## Architecture

```
┌─────────────────────┐        ┌──────────────────────────────────────┐
│ Admin Vue SPA       │        │ Laravel backend                      │
│  /masjid/splash-*   │ ─────► │  AdminDashboard\Splash...Controller  │
└─────────────────────┘  POST  │   ├─ saves SplashAnnouncement row    │
                               │   ├─ uploads image to media library  │
                               │   ├─ flushes MobileCache::SPLASH     │
                               │   └─ OnesignalInAppMessageService    │
                               │      └─ POST/PUT to OneSignal IAM    │
                               └──────────────────────────────────────┘
                                          │                  │
                              GET /mobile/masjids/{id}/splash│
                                          ▼                  ▼
                               ┌──────────────────┐  ┌──────────────────┐
                               │ Nuxt site        │  │ OneSignal cloud  │
                               │ SplashModal.vue  │  │  (IAM scheduled) │
                               └──────────────────┘  └──────────────────┘
                                                              │
                                                  triggers on app open
                                                              ▼
                                                    ┌──────────────────┐
                                                    │ Mobile app       │
                                                    │ (iOS/Android)    │
                                                    │  OneSignal SDK   │
                                                    │  renders IAM     │
                                                    └──────────────────┘
```

## Database

`splash_announcements` table — see migration
`2026_05_24_000000_create_splash_announcements_table.php`.

Important columns:
- `masjid_id` — FK, every splash is scoped to one masjid
- `starts_at`, `ends_at` — UTC timestamps; the active window
- `priority` — 0-100, higher wins when two rows overlap
- `is_active` — soft toggle; turning this off disables the IAM mirror too
- `onesignal_iam_id` — IAM id returned by OneSignal on first sync, stored
  so subsequent updates PUT rather than POST

Image lives in Spatie MediaLibrary under collection name
`splash_announcements`.

## Endpoints

### Admin (auth: sanctum + admin middleware)

```
GET    /api/admin/masjids/{id}/splash-announcements
POST   /api/admin/masjids/{id}/splash-announcements
GET    /api/admin/masjids/{id}/splash-announcements/{splash_id}
POST   /api/admin/masjids/{id}/splash-announcements/{splash_id}    (update)
DELETE /api/admin/masjids/{id}/splash-announcements/{splash_id}
DELETE /api/admin/masjids/{id}/splash-announcements/{splash_id}/trash
```

### Public (rate-limited via `throttle:mobile`, 60 req/min/IP)

```
GET /api/mobile/masjids/{id}/splash
```

Returns:
- `200 {"status":"success","data":{...}}` when a splash is currently live
- `204 No Content` when nothing is active right now

The Nuxt site treats 204 as "render nothing"; mobile apps do **not** call
this endpoint — they receive content via OneSignal IAM.

Cached server-side via `MobileCache::SPLASH` (TTL 5 min). Admin mutations
flush the per-masjid key.

## Mobile team — what you need to do

**Short answer: nothing, if your OneSignal SDK is already initialized.**

The backend creates an IAM in OneSignal with these properties:

| Field           | Value                                                       |
|-----------------|-------------------------------------------------------------|
| `name`          | `Splash #<id> — masjid <masjid_id>`                         |
| `start_time`    | `starts_at` of the row, ISO 8601                            |
| `end_time`      | `ends_at` of the row, ISO 8601                              |
| `filters`       | tag `masjid_id` equals the splash's masjid id (string)      |
| `triggers`      | session_time > 0 (i.e. on every app foreground)             |
| `contents.en`   | headline, body, image_url, optional `actions[]` for the CTA |

**The only requirement on the mobile side is that each device be tagged
with its masjid_id.** The existing OneSignal integration already does
this (`OnesignalService::notifyAllOfMasjid` uses `include_aliases` with
`external_id` — make sure devices ALSO set the `masjid_id` tag via the
SDK's `setTag`/`addAlias` call when the user picks their masjid).

If you want fancier UX (e.g. listen for the CTA tap to deep-link to a
specific in-app screen instead of opening the URL in a browser), wire up
the OneSignal SDK's `setInAppMessageClickHandler` — the action `id` will
be `splash_cta_<id>`.

## Configuration (production)

In addition to the existing OneSignal vars (`ONESIGNAL_APP_ID`,
`ONESIGNAL_REST_API_KEY`, `ONESIGNAL_REST_API_URL`), no new env vars are
required for this feature — the IAM service derives its endpoint from the
existing notifications URL.

If OneSignal is unreachable when an admin saves a splash, the local row
still saves and the Nuxt site still works — only the mobile IAM mirror is
skipped (and logged via `Log::error`). Editing+saving the splash later
will retry the sync.
