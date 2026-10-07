import { API_CONFIG, LOCAL_STORAGE_KEYS } from '@/core/constants/appConfigConstants';

/** Bearer-only reads. No public image URLs or inherited axios cookie/header defaults. */
export default class GuideApiService {
    private static async get(path: string, signal?: AbortSignal): Promise<Response> {
        const token = localStorage.getItem(LOCAL_STORAGE_KEYS.token);
        const response = await fetch(`${API_CONFIG.base_url}${path}`, {
            signal, credentials: 'omit', cache: 'no-cache',
            headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
        });
        if (!response.ok) throw new Error('Guide unavailable');
        return response;
    }

    static async json(path: string, signal?: AbortSignal): Promise<any> {
        return (await this.get(path, signal)).json();
    }

    static async ask(path: string, question: string, signal?: AbortSignal, failure = 'That did not work. Try again, or reach out to your Manara support contact.'): Promise<any> {
        let response: Response;
        try {
            const token = localStorage.getItem(LOCAL_STORAGE_KEYS.token);
            response = await fetch(`${API_CONFIG.base_url}${path}`, {
                method: 'POST', signal, credentials: 'omit', cache: 'no-store',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
                body: JSON.stringify({ question }),
            });
        } catch { throw new Error(failure); }
        let body: any;
        try { body = await response.json(); } catch { throw new Error(failure); }
        if (!response.ok) throw new Error(typeof body.message === 'string' ? body.message : failure);
        return body;
    }

    static async picture(path: string, signal?: AbortSignal): Promise<Blob> {
        return (await this.get(path, signal)).blob();
    }
}
