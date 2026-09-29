import axios from "axios";
import type { AxiosInstance, AxiosResponse } from "axios";
import { stripInheritedAuthorization } from "@/core/services/stripInheritedAuthorization";

/**
 * Child mode's own HTTP client — deliberately NOT FamilyApiService.
 *
 * The whole point of the hand-off token is that it CANNOT reach the parent's
 * portal: it carries `student:{membership}` and not `family`, and the server
 * refuses it on every parent surface. A shared client would send whichever
 * token was stored last, so the parent's phone would start signing parent
 * requests with the child's token (or worse, the reverse) and the boundary
 * would exist only on the server while the client fought it.
 *
 * Separate instance, separate storage key. Same reasoning that keeps the admin
 * and family clients apart.
 */
export const STUDENT_STORAGE_KEYS = {
    token: 'MANARA_STUDENT_TOKEN',
    context: 'MANARA_STUDENT_CONTEXT',
};

export interface StudentContext {
    masjidId: string;
    groupId: string;
    membershipId: string;
    name: string;
}

class StudentApiService {
    private static client: AxiosInstance;

    /**
     * axios.create() COPIES axios's global defaults, and the admin ApiService writes
     * three things onto them that must not reach a child's phone (the same three the
     * family client pins; see FamilyApiService.init):
     *
     *  - the STAFF bearer token (`Authorization`). Stripped, and the interceptor below
     *    removes any Authorization on a request with no child token, so a device where
     *    an admin or teacher once signed in cannot sign the child's requests as staff;
     *  - `withCredentials = true`. This realm is bearer-only, and a credentialed
     *    cross-origin call needs Access-Control-Allow-Credentials, which
     *    config/cors.php does not send;
     *  - a global form-urlencoded Content-Type, under which a JSON body arrives as an
     *    empty form (see JSON_WRITE).
     */
    public static init(baseUrl: string): void {
        StudentApiService.client = axios.create({
            baseURL: baseUrl,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            withCredentials: false,
        });

        stripInheritedAuthorization(StudentApiService.client);

        StudentApiService.client.interceptors.request.use((config) => {
            const token = localStorage.getItem(STUDENT_STORAGE_KEYS.token);

            if (token) {
                config.headers.Authorization = `Bearer ${token}`;
            } else {
                // No child token means NO credential, whatever else set one.
                config.headers.delete?.('Authorization');
                delete (config.headers as any).Authorization;
            }

            return config;
        });
    }

    private static instance(): AxiosInstance {
        if (!StudentApiService.client) {
            StudentApiService.init(import.meta.env.VITE_APP_URL ?? '');
        }
        return StudentApiService.client;
    }

    /**
     * A JSON body must be DECLARED as JSON: axios.create() inherits the admin
     * ApiService's global form-urlencoded Content-Type, and a plain object under that
     * label reaches Laravel as an unparseable form (every field empty, a 422).
     */
    private static readonly JSON_WRITE = { headers: { 'Content-Type': 'application/json' } };

    public static begin(token: string, context: StudentContext): void {
        localStorage.setItem(STUDENT_STORAGE_KEYS.token, token);
        localStorage.setItem(STUDENT_STORAGE_KEYS.context, JSON.stringify(context));
    }

    /** Handing the phone back. The token stays valid server-side until it
     *  expires, but this device forgets it immediately. */
    public static end(): void {
        localStorage.removeItem(STUDENT_STORAGE_KEYS.token);
        localStorage.removeItem(STUDENT_STORAGE_KEYS.context);
    }

    public static context(): StudentContext | null {
        try {
            return JSON.parse(localStorage.getItem(STUDENT_STORAGE_KEYS.context) || 'null');
        } catch {
            return null;
        }
    }

    public static isActive(): boolean {
        return !!localStorage.getItem(STUDENT_STORAGE_KEYS.token) && !!StudentApiService.context();
    }

    public static get(url: string): Promise<AxiosResponse> {
        return StudentApiService.instance().get(url);
    }

    public static put(url: string, data: any): Promise<AxiosResponse> {
        return StudentApiService.instance().put(url, data, StudentApiService.JSON_WRITE);
    }
}

export default StudentApiService;
