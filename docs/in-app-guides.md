# Private in-app guides

Help is inside the signed-in organisation dashboard, teacher shell and lunch shell. Families and guests have no guide access. There is no AI question box.

All staff are `User` principals on `auth:sanctum`, with separate admin/teacher/lunch role middleware. Families are `Contact` principals on the `family` guard. Admins get Admin; they also get School when the resolved organisation has `crm_enabled`, the same test as the Groups/Classrooms menu. This applies to SuperAdmin too. Teachers get Teacher; lunch staff get Lunch. The API checks entitlement before any release/file lookup.

## Release operations

The existing `local` disk is `storage/app/private`, with framework serving disabled. Releases live at `guides/<version>` there. `guides/current.json` holds current and previous together; `flock` serializes writers and a same-directory rename switches the pointer. Version names match `^d[0-9]+-[0-9a-f]{8}$` and are immutable.

From the application root, set `GUIDE_RELEASE_FOLDER` to the actual unpacked release folder containing `manifest.json`, then run:

```sh
sudo -u www-data php artisan guides:install "$GUIDE_RELEASE_FOLDER"
sudo -u www-data php artisan guides:status
```

The source must be readable by `www-data`. The folder's basename need not be the version: the validated manifest sets it. Install validates the source, copies into a temporary private directory, validates that copy, renames into place and finally switches the pointer. A failed validation or pointer operation does not select the attempted release. Reinstalling identical current content preserves the previous pointer; changed content under an existing version is refused.

Rollback to an installed version (the version below is a synthetic example):

```sh
sudo -u www-data php artisan guides:use d1-1234abcd
sudo -u www-data php artisan guides:status
```

Prune explicitly when ready to stop serving older reads:

```sh
sudo -u www-data php artisan guides:prune
```

Pruning preserves current and previously selected current, including after rollback. Install/use do not prune. A corrupt/unreadable pointer, or installed releases with no selected pointer, refuses pruning. Pictures in any still-installed version keep working; pruned versions return a miss.

`scripts/ship.sh` transfers `public/build`, not private guides. `bin/deploy` preserves storage and recursively re-owns it as the configured web user. This statement is based on repository scripts; no live server was probed. `public/storage` is configured to link to `storage/app/public`, not the private disk. No nginx or CSP changes are needed.

## Export contract and validation

Every release has `manifest.json` plus four books (`admin`, `school`, `teacher`, `lunch`). Each book has `page.html`, `page.css`, and manifest-listed pictures at `shots/<walk>/<name>.jpg|png|webp`. The manifest carries version, draft, follows, built_at, and book title/page SHA/style SHA/tasks/files. Page and style names are release-root-relative (`admin/page.html`, `admin/page.css`, and the equivalent for each book), while picture keys remain book-relative (`shots/...`). Optional page_bytes/style_bytes are validated when present; picture records require bytes and SHA256.

The installer refuses symlinks, traversal, absolute/escaped paths, extra files, missing files, invalid extensions, byte/hash mismatches, and pictures that do not decode as their claimed image type. Filenames use simple ASCII names. Errors name a rule and relative file, never its contents.

HTML must have one `<div class="mg" data-book="...">` root. It is passive markup, validated with an element/attribute allowlist: no script/style/iframe/object/embed/link/base/form/meta, inline event attributes, inline style, srcset, SVG/MathML, declarations/comments or document wrappers. Images have only manifest-listed `data-src` paths with width/height/alt; they have no src. Hrefs are valid HTTPS URLs with both `noopener` and `noreferrer`. Cross-guide links/hints carry no href. Tasks/chapters are sections with unique IDs within their kind, every manifest task exists inside a chapter and the task lists match. The manifest section is a chapter display title, not an id; contents group tasks by the actual page chapter headings. FAQs use details with data-faq and may have an opaque HTML id; named FAQ ids must be unique per book. data-words is allowed only on task sections and FAQ details. Cross-guide anchors may name one data-task, one data-faq (the destination question HTML id), or neither (the guide top), never both. Task/chapter IDs are opaque data without control characters, not filesystem paths; tasks must be a JSON array and their order need not be DOM order.

CSS has no imports, URLs, image/image-set resource functions, expressions, closing style tag or escape sequences. String resource functions cannot bypass the authenticated picture loader. Each selector begins under `.mg` and cannot select its siblings or use column combinators. Media/supports/container wrappers are accepted; global at-rules, keyframes, font-face and nested style rules are refused. Checks also run after stripping CSS comments. Dark rules use `.mg[data-theme="dark"]`.

## API and reader

For each realm `admin`, `teacher`, `lunch`, there are three GET/HEAD routes beneath `/api/<realm>/masjids/{masjid_id}/guides`:

- `/`: allowed books, title and current version; no release gives an empty list.
- `/{book}`: one snapshot of version/title/html/css/tasks.
- `/{book}/{version}/pictures/{path}`: only an exact key from that book/version manifest, authenticated on every read.

Book/list responses are private, no-store. Picture responses carry the real image type, nosniff, private/no-cache and a SHA ETag. A 304 still passes authentication and entitlement. The account limiter allows 900 guide requests/minute, enough for two pages with around 350 pictures plus navigation. No directory listing or public asset address is returned.

Reader addresses are `/masjid/help/:book/:task?`, `/teacher/help/:book/:task?`, `/lunch/help/:book/:task?`; question destinations use `?faq=<question-id>` so they cannot collide with task ids. Each navigation, including a link to the already-current address, loads a fresh whole snapshot. Lazy bearer picture requests capture the returned version, have a four-request cap, and use object URLs revoked on leaving. Missing/refused/corrupt pictures preserve the reserved box and show a quiet unavailable label. Cross-guide destinations are links only when offered by the API; chapter-grouped contents, task/question search (title, text and data-words), common questions, local theme switching and an accessible picture viewer share one screen. Figures get a visible Enlarge picture affordance; the viewer fits the screen, offers Zoom/Fit controls, and scrolls the natural-size image in either direction on a phone.

The guide follows the system colour preference until toggled, because the staff app has no theme switch. It changes only the guide root. Chapter destinations use collision-checked internal contents IDs; named questions use their own query parameter and actual task IDs remain unchanged. Links always name their data-guide book even when another book has the same task id; disallowed links become plain words and following text is preserved. Lunch reads its organisation from the same user.masjid as its existing board/header; other staff use the selected organisation.

Validation evidence and detailed decisions are under `artifacts/`. Tests compile/mount real Vue children and execute the delegated runtime against a DOM adapter; all four real pages were mounted locally and an opt-in integration installed/served the real release on a temporary disk. Actual phone layout, browser accessibility behavior and production installation remain unverified.

## Deploy, staging, backup and cache costs

`bin/deploy:210` recursively chowns storage on every deploy, including installed guide versions. It walks files and changes ownership metadata; it does not read/rewrite the 118 MB of image data or transfer it. Metadata cost scales with the number of retained files/releases; live duration was not measured. There is no guide delete step in ship/deploy. Install as the web user, and use explicit pruning to bound retained versions. No deploy code was changed.

`StagingScrub:573` deletes database rows directly and its rewrite paths are database queries; it does not walk or delete the private disk. The staging refresh runbook copies public media and expressly excludes storage/app/private. Install the guide release separately on staging. The backup job resolves one configured media disk (`BackupRun:143`, `MediaTarget:64`), public by default (`config/media-library.php:9`), and tars only that root (`BackupRun:352`). Guides are outside that backup under the current/default configuration; changing BACKUP_MEDIA_DISK to local would change coverage. Preserve the external immutable releases for reinstall after recovery. Live backup environment and infrastructure snapshots were not inspected.

A second visit recreates/revokes object URLs and performs one authenticated request per loaded picture (around 350 for the large book, lazily as viewed). If the browser retained the cached body, private/no-cache plus ETag permits a 304 and body reuse; otherwise it downloads the bytes again. The current controller buffers the file before checking ETag, so even 304 responses read the image bytes server-side. No browser bandwidth/latency measurements were made.

Versioned immutable addresses guarantee stable bytes, not continuing entitlement. Private max-age/immutable with normal fresh-cache reuse can bypass the server after a role/membership is revoked, even with a bearer header. Vary: Authorization would not fix revocation of the same token. The current fetch cache: no-cache would still force revalidation if those headers were added, so it would not eliminate the picture requests. Keep authenticated revalidation for the owner's strict account rule; no cache policy changed.
