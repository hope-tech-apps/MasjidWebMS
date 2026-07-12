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
        return ApiService.VueApp.axios.post(resource, data);
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