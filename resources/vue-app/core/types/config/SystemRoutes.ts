// Type for validating the system routes
export type SystemRoute =
    '/' |
    AuthRoute |
    MasjidDashboardRoute |
    SuperDashboardRoute

// Type for validating the auth layout routes
export type AuthRoute =
    '/auth' |
    '/auth/sign-in' |
    '/auth/dashboards'


// Type for validating the dashboard layout routes
export type MasjidDashboardRoute =
    '/masjid' |
    '/masjid/dashboard' |
    '/masjid/details' |
    '/masjid/announcements' |
    `/masjid/announcements/${number}` |
    '/masjid/events' |
    `/masjid/events/${number}` |
    '/masjid/services' |
    `/masjid/services/${number}` |
    '/masjid/donation' |
    '/masjid/about' |
    '/masjid/gallery' |
    '/masjid/flyers' |
    '/masjid/donations/dashboard' |
    '/hadith' |
    `/hadith/${number}` |
    '/masjid/mobile-features' |
    '/masjid/iqama' |
    '/masjid/jumaa' |
    '/masjid/notifications' |
    '/masjid/contacts' |
    // The tenant's groups: classrooms for a School, halaqat for a Masjid,
    // volunteer teams for a Community org. The path stays neutral — what the
    // screen is CALLED comes from the terminology pack, never the URL.
    '/masjid/groups' |
    `/masjid/groups/${number}` |
    // The admin screen that provisions teacher logins and assigns the classes
    // they lead. Neutral path like /groups; the label is authored, not from the
    // terminology pack.
    '/masjid/teachers' |
    // The intake triage queue (Community vertical) and one request's own page.
    '/masjid/appointment-requests' |
    `/masjid/appointment-requests/${number}` |
    // Offerings — the sellable things (a semester, a camp, a membership year),
    // one offering with its fee plans and roster, and one registration. The path
    // stays neutral: what an offering is CALLED comes from the terminology pack
    // ("Programs", "Services"), never the URL.
    '/masjid/offerings' |
    `/masjid/offerings/${number}` |
    `/masjid/offerings/${number}/registrations/${number}` |
    '/masjid/funds' |
    '/masjid/jummah-lunch' |
    '/masjid/donations' |
    '/masjid/recurring-donations' |
    '/masjid/annual-statements' |
    '/masjid/properties' |
    '/masjid/form-responses' |
    '/masjid/assistant' |
    '/azkar' |
    `/azkar/${number}` |
    '/tasabih' |
    `/tasabih/${number}` |
    '/masjid/admin/profile'

// Type for validating the dashboard layout routes
export type SuperDashboardRoute =
    '/dashboard/super/masjids' |
    `/dashboard/super/masjids/${number}` |
    '/dashboard/super/onboarding' |
    '/dashboard/super/users' |
    `/dashboard/super/users/${number}` |
    '/dashboard/super/profile'
