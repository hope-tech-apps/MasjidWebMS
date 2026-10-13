export const LOCAL_STORAGE_KEYS = {
    token: 'MASJID_APP_AUTH_TOKEN',
    dashboard_masjid_id: 'MASJID_APP_DASHBOARD_MASJID_ID',
    // Which area ('teacher' or 'lunch') this browser was last in. Written ONLY for a
    // login that works in both (its user payload carries `areas`); a login with one
    // kind of role never has it. See core/helpers/staffAreas.ts.
    staff_area: 'MASJID_APP_STAFF_AREA'
}

export const API_CONFIG = {
    // `?? ''`, like every other reader of this variable. It is undefined in
    // every deployed build (see env.d.ts), and axios treats undefined and ''
    // alike, so this was harmless — but only by axios's grace, and
    // `ApiService.init` is typed to take a string.
    base_url: import.meta.env.VITE_APP_URL ?? ''
}