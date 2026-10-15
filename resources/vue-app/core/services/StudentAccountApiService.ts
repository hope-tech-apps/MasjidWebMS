import axios, { AxiosHeaders, type AxiosInstance } from 'axios';
import { stripInheritedAuthorization } from '@/core/services/stripInheritedAuthorization';
import { studentSlotKey, studentTokenForUrl } from '@/core/helpers/studentAccountSession';

/** Bearer-only student client; no shared staff/family storage or ambient Authorization. */
export default class StudentAccountApiService {
    private static client: AxiosInstance;
    private static lastResponse = Date.now();

    public static init(baseURL: string): void {
        const origin = baseURL || window.location.origin;
        this.client = axios.create({ baseURL: origin, withCredentials: false, headers: { Accept: 'application/json' } });
        stripInheritedAuthorization(this.client);
        this.client.interceptors.response.use(response => {
            const token=studentTokenForUrl(sessionStorage,response.config.url ?? '',origin);
            if(token && response.config.headers.get('Authorization') === 'Bearer '+token) StudentAccountApiService.lastResponse = Date.now();
            return response;
        });
        this.client.interceptors.request.use(config => {
            const token = studentTokenForUrl(sessionStorage, config.url ?? '', origin);
            config.headers = AxiosHeaders.from(config.headers);
            config.headers.delete('Authorization');
            config.headers.delete('Content-Type');
            if (token) config.headers.set('Authorization', 'Bearer '+token);
            if (!(config.data instanceof FormData) && config.data) config.headers.set('Content-Type', 'application/json');
            return config;
        });
    }

    private static instance(): AxiosInstance {
        if (!this.client) this.init(import.meta.env.VITE_APP_URL ?? '');
        return this.client;
    }

    public static get(url: string) { return this.instance().get(url); }
    public static post(url: string, body: unknown = {}) { return this.instance().post(url, body); }
    public static put(url: string, body: unknown) { return this.instance().put(url, body); }
    public static lastResponseAt() { return this.lastResponse; }
    public static token(school: string) { return sessionStorage.getItem(studentSlotKey(school)); }
    public static adopt(school: string, token: string) { sessionStorage.setItem(studentSlotKey(school), token); this.lastResponse=Date.now(); }
    public static clear(school: string) { sessionStorage.removeItem(studentSlotKey(school)); }

    public static async download(url: string, name: string, stillCurrent?: () => boolean): Promise<void> {
        const response = await this.instance().get(url, { responseType: 'blob' });
        if(stillCurrent && !stillCurrent())return;
        const blob = URL.createObjectURL(response.data);
        const a = document.createElement('a'); a.href = blob; a.download = name; a.click();
        setTimeout(() => URL.revokeObjectURL(blob), 1000);
    }
}
