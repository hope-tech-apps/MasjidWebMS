import axios, { AxiosInstance, AxiosResponse } from "axios";
import { API_CONFIG, LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";

/**
 * The teacher shell's own HTTP client.
 *
 * A Teacher is a staff login and carries the SAME bearer token as an admin
 * (LOCAL_STORAGE_KEYS.token) — see the frozen `/api/teacher/*` contract. It gets
 * its own axios instance rather than reusing the global ApiService for two
 * reasons:
 *
 *  - ApiService pins a boundary-less "multipart/form-data" Content-Type on its
 *    axios defaults and only corrects it per body type. This instance keeps
 *    axios's clean defaults, so a plain object is sent as JSON — which is what
 *    every teacher endpoint expects ({drill_id,status}, {stage}, {body}, …).
 *  - It centralizes the base URL, the auth header, and 401 handling in one place
 *    so the teacher views never reach for the admin token directly.
 *
 * The token is read PER-REQUEST (not pinned at init) so a sign-out or a token
 * refresh inside one page life is always reflected.
 */
class TeacherApiService {
    private static client: AxiosInstance;

    public static init(baseUrl: string): void {
        TeacherApiService.client = axios.create({
            baseURL: baseUrl,
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        });

        TeacherApiService.client.interceptors.request.use((config) => {
            const token = localStorage.getItem(LOCAL_STORAGE_KEYS.token);
            if (token) {
                config.headers.Authorization = `Bearer ${token}`;
            }
            return config;
        });

        // Centralized 401 handling: a teacher whose token is no longer good is
        // sent back to the single staff sign-in. The dynamic import keeps the
        // router out of this module's static dependency graph.
        TeacherApiService.client.interceptors.response.use(
            (res) => res,
            async (error) => {
                if (error?.response?.status === 401) {
                    try {
                        const { default: router } = await import("@/router/router");
                        if (router.currentRoute.value.path !== "/auth/sign-in") {
                            router.push("/auth/sign-in");
                        }
                    } catch {
                        // If the router cannot be reached (very early boot), fall
                        // back to a hard redirect.
                        window.location.assign("/auth/sign-in");
                    }
                }
                return Promise.reject(error);
            }
        );
    }

    private static instance(): AxiosInstance {
        if (!TeacherApiService.client) {
            TeacherApiService.init(API_CONFIG.base_url ?? "");
        }
        return TeacherApiService.client;
    }

    /**
     * Every write DECLARES JSON, and must keep doing so.
     *
     * axios.create() inherits axios.defaults, and the admin ApiService sets a
     * GLOBAL default Content-Type of application/x-www-form-urlencoded
     * (setHeaderContentType, whose default argument is "url"). Both SPAs are one
     * bundle sharing one axios, so any admin write performed earlier in the page
     * life re-labels this instance's writes too — while axios still SERIALIZES a
     * plain object as JSON. Laravel then parses a JSON body as a form, every
     * field arrives empty, and the request 422s.
     *
     * Measured 2026-09-08: 28 consecutive letter-tracker taps 422'd in exactly
     * this way while the same payload from curl returned 200, and the caller's
     * empty catch turned it into a tile that simply never moved.
     */
    private static readonly JSON_WRITE = { headers: { "Content-Type": "application/json" } };

    public static get(url: string): Promise<AxiosResponse> {
        return TeacherApiService.instance().get(url);
    }

    public static post(url: string, data: any = {}): Promise<AxiosResponse> {
        return TeacherApiService.instance().post(url, data, TeacherApiService.JSON_WRITE);
    }

    public static put(url: string, data: any = {}): Promise<AxiosResponse> {
        return TeacherApiService.instance().put(url, data, TeacherApiService.JSON_WRITE);
    }

    public static delete(url: string): Promise<AxiosResponse> {
        return TeacherApiService.instance().delete(url);
    }
}

/**
 * The rows out of a teacher payload, whatever shape it came in — the frozen
 * contract mirrors the family/admin one, where `/groups` answers a plain array
 * while `/posts`, `/awards`, `/hifz` and `/threads` answer a Laravel paginator
 * (the list is at `data.data`). One unwrapper so a caller cannot get this right
 * in four places and wrong in the fifth.
 */
export function rowsOf(node: any): any[] {
    if (Array.isArray(node)) return node;
    if (Array.isArray(node?.data)) return node.data;
    return [];
}

export default TeacherApiService;
