import axios, { AxiosInstance, AxiosResponse } from "axios";

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

    private static instance(): AxiosInstance {
        if (!StudentApiService.client) {
            StudentApiService.client = axios.create({
                baseURL: import.meta.env.VITE_APP_URL ?? '',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            StudentApiService.client.interceptors.request.use((config) => {
                const token = localStorage.getItem(STUDENT_STORAGE_KEYS.token);
                if (token) config.headers.Authorization = `Bearer ${token}`;
                return config;
            });
        }
        return StudentApiService.client;
    }

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
        return StudentApiService.instance().put(url, data);
    }
}

export default StudentApiService;
