/**
 * Build-time variables, declared as what they ARE in the deployed bundle.
 *
 * Both used to be declared as required strings. In every production build they
 * are `undefined`: the SPA is built with no `.env` at all, on purpose, so that
 * `VITE_APP_URL` stays empty and every API call stays same-origin on whichever
 * host serves the page. A required `string` told every reader — and the type
 * checker — that a value was always there, which is how `ApiService.init(app,
 * baseUrl: string)` came to be handed `undefined` without a word, and how the
 * tab title printed "| undefined" for weeks (now runtime: core/pageTitle.ts).
 *
 * Optional, so a reader has to say what happens when they are missing.
 * Nothing in the build type-checks yet (plain `vite build`); declaring them
 * honestly is the half that costs nothing, and the half a future `vue-tsc`
 * step will need.
 */
interface ImportMetaEnv {
    // VITE_APP_NAME is deliberately NOT declared. Nothing may read it: it is
    // undefined in every deployed build, and the tab title now comes from the
    // organisation at runtime (core/pageTitle.ts). Leaving it undeclared makes a
    // future read a type error instead of a tab that says "undefined".
    /** MUST be empty in deployed builds; see appConfigConstants.ts. */
    VITE_APP_URL?: string;
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
