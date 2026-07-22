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
    '/hadith' |
    `/hadith/${number}` |
    '/masjid/mobile-features' |
    '/masjid/iqama' |
    '/masjid/jumaa' |
    '/masjid/notifications' |
    '/masjid/contacts' |
    '/masjid/funds' |
    '/masjid/donations' |
    '/masjid/recurring-donations' |
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
    '/dashboard/super/users' |
    `/dashboard/super/users/${number}` |
    '/dashboard/super/profile'
