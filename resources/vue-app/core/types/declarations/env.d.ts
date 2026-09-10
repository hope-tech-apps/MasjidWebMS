interface ImportMetaEnv {
    VITE_APP_NAME: string;
    VITE_APP_URL: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}

/**
 * Globals the SERVER injects into the document before the bundle runs
 * (resources/views/vue-app-index.blade.php). They are deliberately not part of
 * import.meta.env: those are baked at build time and one bundle is served by
 * every deployment, so only the responding server can answer these honestly.
 *
 * Both are optional because a page can be rendered by something other than that
 * Blade — read them defensively, never assume presence.
 */
interface Window {
    /** `config('app.env')`, e.g. "production" / "staging". Read by EnvironmentRibbon. */
    __APP_ENV__?: string;

    /** Masjid id for a host mapped in config/portal.php. Read by OrgPortal. */
    __PORTAL_MASJID__?: number;
}
