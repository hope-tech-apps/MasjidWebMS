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
    `/api/admin/masjids/${string}/services` |
    `/api/admin/masjids/${string}/services?page=${number}` |
    `/api/admin/masjids/${string}/services/${string}/` |
    `/api/admin/masjids/${string}/services/${string}/trash` |
    `/api/admin/masjids/${string}/contacts` |
    `/api/admin/masjids/${string}/contacts?page=${number}` |
    `/api/admin/masjids/${string}/contacts/${string}` |
    // Family sign-in for one contact — the parent-portal ON-SWITCH (T-015d).
    // One shape, three verbs: GET reads the state and the audit trail, POST
    // enables/re-addresses, DELETE revokes. See ContactFamilyLoginController.
    `/api/admin/masjids/${string}/contacts/${string}/family-login` |
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
    `/api/admin/masjids/${string}/funds` |
    `/api/admin/masjids/${string}/funds/${string}` |
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
    `/api/admin/masjids/${string}/crm-access` |
    `/api/admin/masjids/${string}/assistant-access` |
    `/api/admin/masjids/${string}/assistant/chat` |
    `/api/admin/masjids/${string}/iqama` |
    `/api/admin/masjids/${string}/jumaa` |
    `/api/admin/masjids/${string}/theme` |
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
