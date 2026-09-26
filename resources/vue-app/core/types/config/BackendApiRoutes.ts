// This type used to ensure the validation of back-end API routes
export type BackendApiRoute =
    '/api/admin/login' |
    '/api/admin/user' |
    '/api/admin/masjids' |
    '/api/admin/logout' |
    // Two-step sign-in for the acting admin's OWN account (T-043d). No masjid
    // segment on purpose: these are per-ACCOUNT, not per-organisation, and the
    // controller only ever touches Auth::user().
    '/api/admin/2fa/enroll' |
    '/api/admin/2fa/confirm' |
    '/api/admin/2fa' |
    '/api/admin/2fa/recovery-codes' |
    // The one 2FA path that is NOT about the caller's own account: a SuperAdmin
    // clearing a stranded second factor for somebody who has lost both their
    // phone and their printed codes. Super-gated, needs the operator's own live
    // code, and writes a permanent record — see TwoFactorController::resetForUser.
    `/api/admin/2fa/reset/${string}` |
    `/api/admin/masjids/${string}/` |
    `/api/admin/masjids/${string}/search?search_for=${string}` |
    `/api/admin/masjids/${string}/details` |
    `/api/admin/masjids/${string}/broadcasts` |
    `/api/admin/masjids/${string}/broadcasts?page=${number}` |
    `/api/admin/masjids/${string}/broadcasts/${string}` |
    `/api/admin/masjids/${string}/announcements` |
    `/api/admin/masjids/${string}/announcements?page=${number}` |
    `/api/admin/masjids/${string}/announcements/${string}/` |
    `/api/admin/masjids/${string}/announcements/${string}/trash` |
    `/api/admin/masjids/${string}/splash-announcements` |
    `/api/admin/masjids/${string}/splash-announcements?page=${number}` |
    `/api/admin/masjids/${string}/splash-announcements/${string}` |
    `/api/admin/masjids/${string}/splash-announcements/${string}/trash` |
    `/api/admin/masjids/${string}/events` |
    `/api/admin/masjids/${string}/events?page=${number}` |
    `/api/admin/masjids/${string}/events/${string}/` |
    // Duplicate one event onto new dates. It needs a member of its OWN because
    // the `events/${string}/` shape above ends in a slash, so
    // `.../events/9/duplicate` does not match it — which is why the call site
    // was written with an `as BackendApiRoute` cast, and why the cast then hid
    // that no such route existed server-side. With the shape declared here the
    // call site passes the template literal directly — a cast on this path is
    // now a bug, not a workaround.
    `/api/admin/masjids/${string}/events/${string}/duplicate` |
    `/api/admin/masjids/${string}/services` |
    `/api/admin/masjids/${string}/services?page=${number}` |
    `/api/admin/masjids/${string}/services/${string}/` |
    `/api/admin/masjids/${string}/services/${string}/trash` |
    `/api/admin/masjids/${string}/contacts` |
    `/api/admin/masjids/${string}/contacts?page=${number}` |
    `/api/admin/masjids/${string}/contacts/${string}` |
    // Contact tags (2026-09-25): list/create, one tag (rename, delete), and the
    // bulk tag / untag of its members — both POSTs carrying `contact_ids[]`.
    `/api/admin/masjids/${string}/contact-tags` |
    `/api/admin/masjids/${string}/contact-tags/${string}` |
    `/api/admin/masjids/${string}/contact-tags/${string}/contacts` |
    `/api/admin/masjids/${string}/contact-tags/${string}/contacts/remove` |
    // Family sign-in for one contact — the parent-portal ON-SWITCH (T-015d).
    // One shape, three verbs: GET reads the state and the audit trail, POST
    // enables/re-addresses, DELETE revokes. See ContactFamilyLoginController.
    `/api/admin/masjids/${string}/contacts/${string}/family-login` |
    // …and the fourth verb (2026-09-24): mail this parent a 7-day link that
    // lands them inside the portal. Its own path rather than a flag on the POST
    // above, because enabling and inviting are different acts on different days
    // — the invite is the one that is re-sent when a family says it never
    // arrived, and it must not re-run an address change to do that.
    `/api/admin/masjids/${string}/contacts/${string}/family-login/invite` |
    // Volunteer credentials on one contact (T-023) — the licences, background
    // checks and certifications a Community org tracks on a provider. The
    // `/contacts/${string}` shape above would already swallow these, but they
    // are spelled out for the same reason groups and teachers are: the union is
    // the only place the SPA's endpoint surface is written down.
    //
    // `?${string}` carries ?expiring_within_days=N — the renewal chase read,
    // and a READ, not a reminder. The trailing `/${string}` shape carries GET
    // (one credential), PUT (edit) and DELETE.
    //
    // The document download is deliberately ABSENT from this union: its URL is
    // supplied by the server on the payload (`document.download_url`), is
    // fetched as a blob straight off the axios instance so the bearer token
    // travels with it, and must never be a path assembled in the SPA
    // (.claude/rules/private-uploads.md).
    `/api/admin/masjids/${string}/contacts/${string}/credentials` |
    `/api/admin/masjids/${string}/contacts/${string}/credentials?${string}` |
    `/api/admin/masjids/${string}/contacts/${string}/credentials/${string}` |
    // Groups — the org -> group -> member level, and everything hung off it
    // (roster, class story, threads, behaviour awards, hifz). One `${string}`
    // pattern per endpoint SHAPE rather than per id: the ids are interpolated at
    // runtime, and the query strings are appended after the fact, so the call
    // sites assert to this type the way donationsStore already does.
    `/api/admin/masjids/${string}/groups` |
    `/api/admin/masjids/${string}/groups?${string}` |
    `/api/admin/masjids/${string}/groups/${string}` |
    // Teachers — the admin provisioning surface: the index/create of teacher
    // logins and the classes each one leads. A plain array on GET, a 201 on
    // POST. One pattern per endpoint SHAPE, as with groups above. The
    // `/teachers/${string}` shape carries GET (pre-fill), PUT (edit) and DELETE
    // (remove from this school); `/invite` re-sends the set-password invite.
    `/api/admin/masjids/${string}/teachers` |
    `/api/admin/masjids/${string}/teachers/${string}` |
    `/api/admin/masjids/${string}/teachers/${string}/invite` |
    // Appointment requests — the intake queue, one request, and the two writes
    // hung off it (status, notes). One pattern per endpoint SHAPE, as above:
    // `${string}` swallows the nested `/{id}/status` and `/{id}/notes` segments.
    `/api/admin/masjids/${string}/appointment-requests` |
    `/api/admin/masjids/${string}/appointment-requests?${string}` |
    `/api/admin/masjids/${string}/appointment-requests/${string}` |
    // Contact-us inbox — the listing, one message, and the two writes hung off
    // it (reply, answered). One pattern per endpoint SHAPE, as above: the
    // trailing `${string}` swallows the nested `/{id}/reply` and
    // `/{id}/answered` segments, and `?${string}` carries ?page= and ?search=.
    // These shapes matched NO existing pattern before T-042d — the store has
    // been asserting past the union since the inbox was built, and the build is
    // `vite build` with no type-check, so nothing said so.
    `/api/admin/masjids/${string}/contact-requests` |
    `/api/admin/masjids/${string}/contact-requests?${string}` |
    `/api/admin/masjids/${string}/contact-requests/${string}` |
    // Offerings + the two things nested under one: its IMMUTABLE fee plans and
    // its registrations. One pattern per endpoint SHAPE, as above — the trailing
    // `${string}` swallows the nested `/{id}/adjustments`, `/{id}/promote` and
    // `/{id}/cancel` segments, and the `?${string}` variants carry the filters.
    `/api/admin/masjids/${string}/offerings` |
    `/api/admin/masjids/${string}/offerings?${string}` |
    `/api/admin/masjids/${string}/offerings/${string}` |
    `/api/admin/masjids/${string}/offerings/${string}/fee-plans` |
    `/api/admin/masjids/${string}/offerings/${string}/fee-plans/${string}` |
    `/api/admin/masjids/${string}/offerings/${string}/registrations` |
    `/api/admin/masjids/${string}/offerings/${string}/registrations?${string}` |
    `/api/admin/masjids/${string}/offerings/${string}/registrations/${string}` |
    `/api/admin/masjids/${string}/behavior-skills` |
    `/api/admin/masjids/${string}/behavior-skills?${string}` |
    // The impact report (T-024) — READ-ONLY, and the only call the screen
    // makes. The `?${string}` is not optional decoration: impactReportStore
    // always appends the serialized `from`/`to` bounds, and an all-time report
    // is an EMPTY query string rather than an absent one, which this shape
    // still matches. There is deliberately no second member for a bare
    // `/impact/report`: no caller uses it, and a shape nobody calls is an
    // inventory entry that cannot be trusted.
    `/api/admin/masjids/${string}/impact/report?${string}` |
    `/api/admin/masjids/${string}/funds` |
    `/api/admin/masjids/${string}/funds/${string}` |
    // Accepted payment methods: GET the set, PUT the whole set (PaymentMethodsController).
    `/api/admin/masjids/${string}/payment-methods` |
    `/api/admin/masjids/${string}/jummah-lunch/menus` |
    `/api/admin/masjids/${string}/jummah-lunch/flyer` |
    `/api/admin/masjids/${string}/jummah-lunch/menus/${string}` |
    `/api/admin/masjids/${string}/jummah-lunch/menus/${string}/items` |
    `/api/admin/masjids/${string}/jummah-lunch/menus/${string}/items/${string}` |
    `/api/admin/masjids/${string}/jummah-lunch/menus/${string}/orders` |
    `/api/admin/masjids/${string}/jummah-lunch/menus/${string}/orders/${string}/mark-paid` |
    `/api/admin/masjids/${string}/jummah-lunch/menus/${string}/orders/${string}/status` |
    `/api/admin/masjids/${string}/donations` |
    `/api/admin/masjids/${string}/donations?page=${number}` |
    `/api/admin/masjids/${string}/donations/${string}` |
    `/api/admin/masjids/${string}/donation-link` |
    `/api/admin/masjids/${string}/connect/onboarding` |
    `/api/admin/masjids/${string}/connect/status` |
    `/api/admin/masjids/${string}/forms` |
    `/api/admin/masjids/${string}/forms?page=${number}` |
    `/api/admin/masjids/${string}/forms/options` |
    `/api/admin/masjids/${string}/forms/field-types` |
    `/api/admin/masjids/${string}/forms/${string}` |
    `/api/admin/masjids/${string}/forms/${string}/responses?${string}` |
    `/api/admin/masjids/${string}/forms/${string}/responses/roster?${string}` |
    `/api/admin/masjids/${string}/forms/${string}/responses/${string}` |
    `/api/admin/masjids/${string}/forms/${string}/responses/cash-totals?${string}` |
    `/api/admin/masjids/${string}/forms/${string}/insights?${string}` |
    `/api/admin/masjids/${string}/forms/${string}/responses/${string}/collect` |
    `/api/admin/masjids/${string}/forms/${string}/responses/${string}/take-cash` |
    `/api/admin/masjids/${string}/forms/${string}/responses/${string}/mark-paid-external` |
    `/api/admin/masjids/${string}/forms/${string}/staff-codes` |
    `/api/admin/masjids/${string}/forms/${string}/staff-codes/clear-lockout` |
    `/api/admin/masjids/${string}/forms/${string}/staff-codes/${string}` |
    `/api/admin/masjids/${string}/forms/${string}/staff-codes/${string}/reset-device` |
    `/api/admin/masjids/${string}/about` |
    `/api/admin/masjids/${string}/gallery` |
    `/api/admin/masjids/${string}/gallery/${string}` |
    `/api/admin/hadiths` |
    `/api/admin/hadiths/${string}/` |
    `/api/admin/hadiths?page=${number}` |
    `/api/admin/hadiths/library` |
    `/api/admin/hadiths/library?search=${string}` |
    `/api/admin/hadiths/library/add` |
    `/api/admin/masjids/${string}/features` |
    `/api/admin/masjids/${string}/features/${string}/` |
    // This organisation's text-message sender identity and the outcome of its
    // A2P 10DLC carrier registration (T-009). GET reads it, PUT records it; both
    // are `super`-only, so the only caller is the super shell's masjid details
    // screen. The CONSENT half needs no entry of its own —
    // `/api/admin/masjids/${string}/contacts/${string}` above already swallows
    // the nested `/{contact_id}/sms-consent` segment.
    `/api/admin/masjids/${string}/sms-sender` |
    `/api/admin/masjids/${string}/crm-access` |
    `/api/admin/masjids/${string}/assistant-access` |
    `/api/admin/masjids/${string}/assistant/chat` |
    `/api/admin/masjids/${string}/iqama` |
    `/api/admin/masjids/${string}/jumaa` |
    `/api/admin/masjids/${string}/theme` |
    // Live preview sessions (docs/live-preview.md): one per editing surface, each
    // behind the same gate as that surface's save.
    `/api/admin/masjids/${string}/theme/preview-session` |
    `/api/admin/masjids/${string}/theme/preview` |
    `/api/admin/masjids/${string}/pages/preview-session` |
    `/api/admin/masjids/${string}/splash-announcements/preview-session` |
    // The organisation's nisab price (T-043c). ONE shape, two verbs: GET returns
    // the stored row plus the nisab reference resolved by the same server code
    // the public calculator answers donors from; POST saves the price, its date
    // and its cited source. The calculator itself is NOT here — it is the public
    // /api/v1 endpoint, called with a tokenless client and the `masjid-id`
    // header so the office exercises the donor's real path.
    `/api/admin/masjids/${string}/zakat-settings` |
    `/api/admin/masjids/${string}/notifications` |
    `/api/admin/azkar` |
    `/api/admin/azkar/${string}/` |
    `/api/admin/azkar?page=${number}` |
    `/api/admin/azkar/categories` |
    `/api/admin/azkar/library` |
    `/api/admin/azkar/library?search=${string}` |
    `/api/admin/azkar/library/add` |
    `/api/admin/tasabih` |
    `/api/admin/tasabih/${string}/` |
    `/api/admin/tasabih?page=${number}` |
    `/api/admin/tasabih/library` |
    `/api/admin/tasabih/library?search=${string}` |
    `/api/admin/tasabih/library/add` |
    '/api/admin/admins/masjid/available' |
    '/api/admin/countries' |
    `/api/admin/countries/${number}/cities` |
    `/api/admin/masjids/${number}` |
    `/api/admin/masjids/${string}/trash` |
    `/api/admin/users` |
    `/api/admin/users/${string}/` |
    `/api/admin/users/${string}/trash` |
    `/api/admin/profile` |
    `/api/admin/app-config` |
    `/api/admin/app-config/${string}` |
    `/api/admin/onboarding/options` |
    `/api/admin/onboarding/provision` |
    `/api/admin/onboarding/intake/geocode` |
    `/api/admin/search?search_for=${string}`
    // T-041i (entering a registration by hand). Appended with a LEADING pipe so
    // this adds lines and edits none — three agents can append to this union in
    // one wave without touching each other's text.
    //
    // The contact PICKER on the manual-registration modal, and on the offline
    // donation form, which has been casting `as any` past this union since it
    // was built: the only shape here is `contacts?page=`, and the picker sends
    // `?search=…&per_page=…`. The build is `vite build` with no type-check, so
    // nothing said so. POST …/offerings/{id}/registrations needs no entry — it
    // is the same path shape as the roster GET, which is already above.
    | `/api/admin/masjids/${string}/contacts?${string}`
    // What an organisation has, grouped, with its recent changes — the
    // SuperAdmin's switch panel (MasjidsController::capabilities). The PATCH
    // that flips one entry is `capabilities/${key}`.
    | `/api/admin/masjids/${string}/capabilities`
    | `/api/admin/masjids/${string}/capabilities/${string}`
    // The attendance log (the school office's read of the register) and one
    // child's own record. READ-ONLY: every write the register has lives in the
    // teacher realm, so there is no POST/PUT shape here and adding one would be
    // a new decision rather than a new line.
    //
    // Both carry `?${string}` and neither has a bare form, for the same reason
    // the impact report above does not: attendanceLogStore always appends the
    // serialized filters, and the default window is an EMPTY query string
    // rather than an absent one — which this shape still matches. A shape no
    // caller uses is an inventory entry that cannot be trusted.
    //
    // The `members/${string}` segment is spelled out rather than swallowed by a
    // trailing `${string}`: the two endpoints answer different payloads, and
    // one shape covering both would let a typo in either reach the office as a
    // 404 on a screen whose empty state reads "no register was taken".
    | `/api/admin/masjids/${string}/attendance?${string}`
    | `/api/admin/masjids/${string}/attendance/members/${string}?${string}`
    // An organisation's web addresses (Manara Studio W1, S7), SuperAdmin-only:
    // the list and POST share the bare shape; "Check now" and the DELETE name
    // the row. The domain check is Studio's, under its own prefix.
    | `/api/admin/masjids/${string}/domains`
    | `/api/admin/masjids/${string}/domains/${string}/refresh`
    | `/api/admin/masjids/${string}/domains/${string}`
    | '/api/admin/studio/domains/check'
    // Manara Studio (docs/manara-studio-w1.md S1-S5), all under the SuperAdmin
    // `studio` prefix (R18). Drafts are listed with `?status=` so the list can
    // show provisioned drafts beside open ones; the logo is fetched as a blob
    // with the bearer, never from a public URL (.claude/rules/private-uploads.md).
    | `/api/admin/studio/catalogue?org_type=${string}`
    | '/api/admin/studio/drafts'
    | `/api/admin/studio/drafts?status=${string}`
    | `/api/admin/studio/drafts/${number}`
    | `/api/admin/studio/drafts/${number}/logo`
    | `/api/admin/studio/drafts/${number}/preview`
    // Step 3 (S8): the draft becomes an organisation, once.
    | `/api/admin/studio/drafts/${number}/provision`
    | `/api/admin/studio/layout-presets?org_type=${string}`
