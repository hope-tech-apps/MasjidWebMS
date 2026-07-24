import axios, { AxiosResponse } from "axios";
import { App } from "vue";
import VueAxios from "vue-axios";
import { BackendApiRoute } from "../types/config/BackendApiRoutes";

// Define custom type for header content-type mapping keys
type HeaderContentType = "url" | "formdata";
const TYPE_MAP = {
    formdata: "multipart/form-data",
    url: "application/x-www-form-urlencoded"
};

class ApiService {

    public static VueApp: App;

    // Initialize service VueApp with axios instance
    public static init(app: App, baseUrl: string, allowCredentials = true): void {
        ApiService.VueApp = app;
        ApiService.VueApp.use(VueAxios, axios);
        ApiService.VueApp.axios.defaults.baseURL = baseUrl;
        ApiService.VueApp.axios.defaults.withCredentials = allowCredentials;
        ApiService.VueApp.axios.defaults.headers.common['X-Requested-With'] = "XMLHttpRequest";
        ApiService.setHeader()

        // setHeaderContentType() pins a global default Content-Type of
        // "multipart/form-data" — a string with NO boundary. But requests send
        // either URLSearchParams (urlencoded forms like rent/donations) or
        // FormData (file uploads). Sending a urlencoded/multipart body under the
        // boundary-less "multipart/form-data" header makes PHP fail to parse it,
        // so every field arrives empty and FormRequest validation returns 422
        // ("Could not log the payment"). Set the Content-Type from the ACTUAL
        // body type so PHP can parse the body.
        ApiService.VueApp.axios.interceptors.request.use((config) => {
            const body: any = config.data;
            const headers: any = config.headers;
            if (!headers) return config;
            if (typeof FormData !== "undefined" && body instanceof FormData) {
                // Let the browser set "multipart/form-data; boundary=...".
                if (typeof headers.delete === "function") headers.delete("Content-Type");
                else { delete headers["Content-Type"]; delete headers["content-type"]; }
            } else if (typeof URLSearchParams !== "undefined" && body instanceof URLSearchParams) {
                if (typeof headers.set === "function") headers.set("Content-Type", "application/x-www-form-urlencoded", true);
                else headers["Content-Type"] = "application/x-www-form-urlencoded";
            } else if (
                body && typeof body === "object" &&
                !(typeof Blob !== "undefined" && body instanceof Blob) &&
                !(typeof ArrayBuffer !== "undefined" && body instanceof ArrayBuffer)
            ) {
                // Plain object -> JSON (Laravel parses it natively). Without this,
                // the boundary-less multipart default converts the object to a
                // FormData with no boundary and PHP can't parse it (settings/theme
                // forms would silently save nothing or 422).
                if (typeof headers.set === "function") headers.set("Content-Type", "application/json", true);
                else headers["Content-Type"] = "application/json";
            }
            return config;
        });
    }

    // Set axios headers
    public static setHeader(bearerToken = "", accept = "application/json"): void {
        ApiService.VueApp.axios.defaults.headers.common.Accept = accept;
        ApiService.VueApp.axios.defaults.headers.common.Authorization = `Bearer ${bearerToken}`;
        ApiService.setHeaderContentType();
    }

    // Set header content type
    public static setHeaderContentType(type: HeaderContentType = "formdata"): void {
        ApiService.VueApp.axios.defaults.headers.common["Content-Type"] = TYPE_MAP[type];
    }

    // Get
    public static get(resource: BackendApiRoute): Promise<AxiosResponse> {
        return ApiService.VueApp.axios.get(resource);
    }

    // Post
    public static post(resource: BackendApiRoute, data: any): Promise<AxiosResponse> {
        // Override the boundary-less multipart default per-request based on the
        // body type, so PHP can parse the body (see the interceptor in init()).
        const config: any = {};
        if (typeof FormData !== "undefined" && data instanceof FormData) {
            config.headers = { "Content-Type": undefined };            // browser sets the boundary
        } else if (typeof URLSearchParams !== "undefined" && data instanceof URLSearchParams) {
            config.headers = { "Content-Type": "application/x-www-form-urlencoded" };
        } else if (data && typeof data === "object" && !(typeof Blob !== "undefined" && data instanceof Blob)) {
            config.headers = { "Content-Type": "application/json" };   // plain object -> JSON
        }
        return ApiService.VueApp.axios.post(resource, data, config);
    }

    // Put
    public static put(resource: BackendApiRoute, data: any, contentType: HeaderContentType = "url"): Promise<AxiosResponse> {
        ApiService.setHeaderContentType(contentType);
        return ApiService.VueApp.axios.put(resource, data);
    }

    // Patch
    public static patch(resource: BackendApiRoute, data: any, contentType: HeaderContentType = "url"): Promise<AxiosResponse> {
        ApiService.setHeaderContentType(contentType);
        return ApiService.VueApp.axios.patch(resource, data);
    }

    // Delete
    public static delete(resource: BackendApiRoute): Promise<AxiosResponse> {
        return ApiService.VueApp.axios.delete(resource);
    }

    // Post or Put
    public static changeRecords(resource: BackendApiRoute, data: any, isPut: boolean): Promise<AxiosResponse> {
        if (isPut) {
            ApiService.setHeaderContentType("url");
            return ApiService.VueApp.axios.put(resource, data);
        } else {
            ApiService.setHeaderContentType("formdata");
            return ApiService.VueApp.axios.post(resource, data);
        }
    }

}

export default ApiService;