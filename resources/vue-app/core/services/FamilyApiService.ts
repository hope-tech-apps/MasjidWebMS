import axios, { AxiosInstance, AxiosResponse } from "axios";

/**
 * The parent portal's own HTTP client — deliberately NOT ApiService.
 *
 * ApiService.setHeader() writes the bearer token onto axios's GLOBAL default
 * headers. One shared instance therefore cannot hold a staff token and a family
 * token at once: whichever signed in last would sign the other's requests. A
 * parent's credential reaching /api/admin, or a staff credential reaching
 * /api/family, is precisely the realm confusion the backend guards were built
 * to refuse (routes/family.php: "a staff token resolves to null here"), and the
 * client should not be the thing that manufactures the attempt.
 *
 * So the portal gets its own instance, its own storage key, and no access to
 * the admin token at all.
 */
export const FAMILY_STORAGE_KEYS = {
    token: 'MANARA_FAMILY_TOKEN',
    contact: 'MANARA_FAMILY_CONTACT',
    masjid: 'MANARA_FAMILY_MASJID_ID',
};

class FamilyApiService {
    private static client: AxiosInstance;

    public static init(baseUrl: string): void {
        FamilyApiService.client = axios.create({
            baseURL: baseUrl,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            // PINNED FALSE, and this is not belt-and-braces. The admin
            // ApiService sets `axios.defaults.withCredentials = true` globally
            // and axios.create() inherits the defaults — the same poisoning that
            // made JSON bodies arrive as empty forms, in a second costume.
            //
            // This realm authenticates with a BEARER TOKEN and never a cookie,
            // so credentials are never needed. Sending them breaks the moment a
            // page is served from an organisation's own domain: a credentialed
            // cross-origin request requires Access-Control-Allow-Credentials:
            // true, config/cors.php sets supports_credentials to false, and the
            // browser refuses the preflight. The page renders and every call
            // fails.
            withCredentials: false,
        });

        // Read the token per-request rather than pinning it at init: the portal
        // signs in and out inside one page life.
        FamilyApiService.client.interceptors.request.use((config) => {
            const token = localStorage.getItem(FAMILY_STORAGE_KEYS.token);
            if (token) {
                config.headers.Authorization = `Bearer ${token}`;
            }
            return config;
        });
    }

    private static instance(): AxiosInstance {
        if (!FamilyApiService.client) {
            FamilyApiService.init(import.meta.env.VITE_APP_URL ?? '');
        }
        return FamilyApiService.client;
    }

    /**
     * Writes DECLARE JSON, for the reason recorded on TeacherApiService.JSON_WRITE:
     * axios.create() inherits axios.defaults, the admin ApiService sets a global
     * form-urlencoded Content-Type, and a JSON body under that label reaches
     * Laravel as an unparseable form — every field empty, a 422, and on this
     * realm that would be a parent's message or sign-in code silently failing.
     */
    private static readonly JSON_WRITE = { headers: { "Content-Type": "application/json" } };

    public static get(url: string): Promise<AxiosResponse> {
        return FamilyApiService.instance().get(url);
    }

    public static post(url: string, data: any = {}): Promise<AxiosResponse> {
        return FamilyApiService.instance().post(url, data, FamilyApiService.JSON_WRITE);
    }

    /**
     * PUT and DELETE carry the same JSON_WRITE pin as post(), for the same
     * reason — the global form-urlencoded Content-Type would otherwise reach
     * Laravel as an unparseable form and empty every field. DELETE takes no
     * body: the two routes that use it (`/password`, and any future one) say
     * everything in the URL.
     */
    public static put(url: string, data: any = {}): Promise<AxiosResponse> {
        return FamilyApiService.instance().put(url, data, FamilyApiService.JSON_WRITE);
    }

    public static delete(url: string): Promise<AxiosResponse> {
        return FamilyApiService.instance().delete(url, FamilyApiService.JSON_WRITE);
    }

    /**
     * Attachment bytes. The API serves the FILE, not a signed URL (deliberately
     * — a signed URL would outlive the consent that authorised it), so the
     * bearer token has to travel on the request. An <img src> cannot carry a
     * header, so the bytes are fetched here and handed back as an object URL
     * the caller must revoke.
     */
    public static async blobUrl(url: string): Promise<string> {
        const res = await FamilyApiService.instance().get(url, { responseType: 'blob' });
        return URL.createObjectURL(res.data);
    }
}

/**
 * The rows out of a family payload, whatever shape it came in.
 *
 * MEASURED against production: `/groups` answers a plain array, while
 * `/posts`, `/threads`, `/awards`, `/hifz` and a thread's `messages` all answer
 * a Laravel PAGINATOR — the list is at `data.data`. Reading `data` alone gives
 * the paginator object, which renders as nothing and reports a length of
 * undefined. One unwrapper, so a caller cannot get this right in four places
 * and wrong in the fifth.
 */
export function rowsOf(node: any): any[] {
    if (Array.isArray(node)) return node;
    if (Array.isArray(node?.data)) return node.data;
    return [];
}

export default FamilyApiService;
