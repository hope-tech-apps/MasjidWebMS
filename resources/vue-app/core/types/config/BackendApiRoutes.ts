// This type used to ensure the validation of back-end API routes
export type BackendApiRoute =
    '/api/admin/login' |
    '/api/admin/user' |
    '/api/admin/masjids' |
    '/api/admin/logout' |
    `/api/admin/masjids/${string}/` |
    `/api/admin/masjids/${string}/search?search_for=${string}` |
    `/api/admin/masjids/${string}/details` |
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
