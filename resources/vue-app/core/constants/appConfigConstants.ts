export const LOCAL_STORAGE_KEYS = {
    token: 'MASJID_APP_AUTH_TOKEN',
    dashboard_masjid_id: 'MASJID_APP_DASHBOARD_MASJID_ID'
}

export const API_CONFIG = {
    // `?? ''`, like every other reader of this variable. It is undefined in
    // every deployed build (see env.d.ts), and axios treats undefined and ''
    // alike, so this was harmless — but only by axios's grace, and
    // `ApiService.init` is typed to take a string.
    base_url: import.meta.env.VITE_APP_URL ?? ''
}