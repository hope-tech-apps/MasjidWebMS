/**
 * Loads the REAL live-organisation store (stores/super/studioOrganisationStore.ts)
 * under plain node, for the tests that exercise its logic.
 *
 * node cannot import the store as it is: it uses the `@/` alias, imports without
 * extensions, and pulls in ApiService (axios, the router) and the draft store (a
 * wizard's worth of modules) for one constant. So this bundles the store with
 * esbuild (already installed: vite depends on it), aliasing `@` to the SPA root,
 * and swaps exactly two modules:
 *
 *  - ApiService, for a transport the test answers by hand (`api.on(...)`);
 *  - studioDraftStore, for its PREVIEW_DEBOUNCE_MS, so a preview is due at once.
 *
 * Everything else in the store's import graph is the real code. Pinia and Vue are
 * bundled in with it, and `useStore()` hands out a store on a fresh Pinia each call.
 */
import { build } from 'esbuild';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const SPA_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

export type Call = { method: 'get' | 'post' | 'patch'; url: string; body?: URLSearchParams };
type Handler = (call: Call) => Promise<{ data: any }>;

/** The transport the store talks to: `on()` answers a request, `calls` records what was sent. */
export type FakeApi = {
    calls: Call[];
    on(method: Call['method'], url: string | RegExp, handler: Handler): void;
    reset(): void;
};

const STUB_API = `
const handlers = [];
const api = globalThis.__fakeApi = {
    calls: [],
    on(method, url, handler) { handlers.push({ method, url, handler }); },
    reset() { handlers.length = 0; api.calls.length = 0; },
};
async function send(method, url, body) {
    const call = { method, url, body };
    api.calls.push(call);
    const match = handlers.find((h) => h.method === method && (typeof h.url === 'string' ? h.url === url : h.url.test(url)));
    if (!match) throw new Error('no fake answer for ' + method + ' ' + url);
    return match.handler(call);
}
export default {
    get: (url) => send('get', url),
    post: (url, body) => send('post', url, body),
    patch: (url, body) => send('patch', url, body),
};
`;

const STUB_DRAFT_STORE = 'export const PREVIEW_DEBOUNCE_MS = 1;';

export async function loadOrganisationStore() {
    const result = await build({
        stdin: {
            contents: `export * from '@/stores/super/studioOrganisationStore'; export { createPinia, setActivePinia } from 'pinia';`,
            resolveDir: SPA_ROOT,
            loader: 'ts',
        },
        bundle: true,
        write: false,
        format: 'esm',
        platform: 'node',
        target: 'node22',
        logLevel: 'silent',
        alias: { '@': SPA_ROOT },
        // Some bundled packages (axios) call require() for node built-ins.
        banner: { js: "import { createRequire as __createRequire } from 'node:module'; const require = __createRequire(process.cwd() + '/');" },
        define: { 'process.env.NODE_ENV': '"test"', __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
        plugins: [{
            name: 'studio-store-stubs',
            setup(b) {
                const stub = (filter: RegExp, contents: string, name: string) => {
                    b.onResolve({ filter }, () => ({ path: name, namespace: 'stub' }));
                    b.onLoad({ filter: new RegExp(`^${name}$`), namespace: 'stub' }, () => ({ contents, loader: 'js' }));
                };
                stub(/^@\/core\/services\/ApiService$/, STUB_API, 'api');
                stub(/^@\/stores\/super\/studioDraftStore$/, STUB_DRAFT_STORE, 'draft-store');
            },
        }],
    });

    const file = join(mkdtempSync(join(tmpdir(), 'studio-store-')), 'store.mjs');
    writeFileSync(file, result.outputFiles[0].text);
    const module = await import(pathToFileURL(file).href);

    return {
        useStore: (): any => {
            module.setActivePinia(module.createPinia());
            return module.useStudioOrganisationStore();
        },
        SAVED_BUT_STALE: module.SAVED_BUT_STALE as string,
        api: (globalThis as any).__fakeApi as FakeApi,
    };
}
