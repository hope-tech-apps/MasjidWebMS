import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";
import { serverFieldErrors, serverMessage } from "@/core/helpers/serverMessage";
import {
    csvFilename,
    ErrorReader,
    salesCsvRequest,
    salesListPath,
    shopBase
} from "@/core/helpers/shop";
import {
    ProductBody,
    SaleFilters,
    SaleResolution,
    SalesMeta,
    ShopMeta,
    ShopProduct,
    ShopSale
} from "@/core/types/data/masjid-related/Shop";

/**
 * The online shop over /api/admin/masjids/{masjid_id}/shop (shop slices B2 and B3): the catalogue, a
 * product's pictures, and the pickup list with its CSV.
 *
 * The active masjid comes from the same context every masjid-scoped store uses; the server's
 * `tenant` middleware and BelongsToMasjid keep each call inside this organisation, and the whole
 * `shop` group sits behind `capability:shop` (a 403 with a sentence when it is off) and the
 * donations permissions.
 *
 * WRITES ARE JSON. The shop's money and stock are JSON numbers (the server refuses a string, a float
 * or a boolean price), and a form-encoded PUT would turn every one into a string, so the writes use
 * the admin axios instance with an explicit JSON content type rather than ApiService.put, whose
 * default is form-encoded. The picture upload is the one multipart call.
 *
 * Every write returns the answer as it stands: a product answer is the WHOLE product with its new
 * `lock_version`, a picture answer is the whole product too, a collect or resolve answer is the
 * row. Nothing is retried here: a 409 (somebody else changed the product) is the screen's to show.
 */

/** The server's words and its dotted 422 keys, in the shape the pure helpers take. */
export const shopErrorReader: ErrorReader = { message: serverMessage, fields: serverFieldErrors };

const JSON_BODY = { headers: { 'Content-Type': 'application/json' } };

export const useShopStore = defineStore('shopStore', () => {

    // State
    const productsPaginated = ref<PaginatedData<ShopProduct>>();
    const productsMeta = ref<ShopMeta>();
    const salesPaginated = ref<PaginatedData<ShopSale>>();
    const salesMeta = ref<SalesMeta>();

    // Stores
    const masjidStore = useMasjidStore();
    const authStore = useAuthStore();

    /** dashboardMasjidId survives a hard refresh that has not yet hydrated masjidStore.masjid. */
    function masjidId(): number | string | null {
        return authStore.dashboardMasjidId ?? masjidStore.masjid?.id ?? null;
    }

    /** Throws rather than returns quietly: a silent return would book a successful load. */
    function requireMasjidId(): number | string {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        return id;
    }

    /** A 2xx that is not a success envelope is a failure (a proxy page, an expired session). */
    function expectSuccess(res: AxiosResponse, what: string): any {
        if (res.data?.status !== 'success' || !res.data?.data) {
            throw new Error(`${what} did not come back in a readable form. Try again.`);
        }

        return res.data;
    }

    // ------------------------------------------------------------------------------ products

    /** One page of the catalogue. `meta` carries the shop's currency and its picture limits. */
    async function fetchProducts(page: number = 1): Promise<void> {
        const id = requireMasjidId();

        if (productsPaginated.value) {
            productsPaginated.value.data = [];
        }

        const res: AxiosResponse = await ApiService.get(`${shopBase(id)}/products?page=${page}` as BackendApiRoute);
        const body = expectSuccess(res, 'The products');

        productsPaginated.value = body.data;
        productsMeta.value = body.meta ?? productsMeta.value;
    }

    /** One product, with its sizes, its pictures and its `lock_version`. */
    async function fetchProduct(productId: number | string): Promise<{ product: ShopProduct; meta: ShopMeta | null }> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.get(`${shopBase(id)}/products/${productId}` as BackendApiRoute);
        const body = expectSuccess(res, 'The product');

        if (body.meta) productsMeta.value = body.meta;

        return { product: body.data as ShopProduct, meta: (body.meta as ShopMeta | undefined) ?? null };
    }

    async function createProduct(payload: ProductBody): Promise<{ product: ShopProduct; meta: ShopMeta | null }> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.VueApp.axios.post(`${shopBase(id)}/products`, payload, JSON_BODY);
        const body = expectSuccess(res, 'The new product');

        if (body.meta) productsMeta.value = body.meta;

        return { product: body.data as ShopProduct, meta: (body.meta as ShopMeta | undefined) ?? null };
    }

    /**
     * Save a product. The payload is the WHOLE list of sizes and the `lock_version` the editor loaded;
     * a 409 means somebody else changed the product, and is never retried here.
     */
    async function updateProduct(productId: number | string, payload: ProductBody): Promise<{ product: ShopProduct; meta: ShopMeta | null }> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.VueApp.axios.put(`${shopBase(id)}/products/${productId}`, payload, JSON_BODY);
        const body = expectSuccess(res, 'The product');

        if (body.meta) productsMeta.value = body.meta;

        return { product: body.data as ShopProduct, meta: (body.meta as ShopMeta | undefined) ?? null };
    }

    /** A soft delete: paid orders keep their own record. */
    async function deleteProduct(productId: number | string): Promise<void> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.delete(`${shopBase(id)}/products/${productId}` as BackendApiRoute);

        if (res.data?.status !== 'success') {
            throw new Error('The product was not deleted. Try again.');
        }
    }

    // ------------------------------------------------------------------------------ pictures

    /** `images[]`, multipart. The answer is the whole product, new `lock_version` included. */
    async function uploadImages(productId: number | string, files: File[]): Promise<ShopProduct> {
        const id = requireMasjidId();

        const form = new FormData();
        files.forEach((file) => form.append('images[]', file));

        const res: AxiosResponse = await ApiService.post(`${shopBase(id)}/products/${productId}/images` as BackendApiRoute, form);

        return expectSuccess(res, 'The product').data as ShopProduct;
    }

    /** `order` is EVERY picture id of the product, first to last. */
    async function reorderImages(productId: number | string, order: number[]): Promise<ShopProduct> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.VueApp.axios.put(`${shopBase(id)}/products/${productId}/images/order`, { order }, JSON_BODY);

        return expectSuccess(res, 'The product').data as ShopProduct;
    }

    async function deleteImage(productId: number | string, mediaId: number | string): Promise<ShopProduct> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.delete(`${shopBase(id)}/products/${productId}/images/${mediaId}` as BackendApiRoute);

        return expectSuccess(res, 'The product').data as ShopProduct;
    }

    // ----------------------------------------------------------------------------- the pickup list

    /** One page of sales under the full filter set. `meta.summary` is the header and ignores the filters. */
    async function fetchSales(filters: SaleFilters, page: number = 1, perPage: number | null = null): Promise<void> {
        const id = requireMasjidId();

        if (salesPaginated.value) {
            salesPaginated.value.data = [];
        }

        const res: AxiosResponse = await ApiService.get(salesListPath(id, filters, page, perPage) as BackendApiRoute);
        const body = expectSuccess(res, 'The pickup list');

        salesPaginated.value = body.data;
        salesMeta.value = body.meta ?? undefined;
    }

    /** "Hand out": stamped by the first press. A 422 carries a sentence (refunded or disputed order). */
    async function collectSale(saleId: number): Promise<ShopSale> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.post(`${shopBase(id)}/sales/${saleId}/collect` as BackendApiRoute, {});

        return expectSuccess(res, 'The sale').data as ShopSale;
    }

    async function uncollectSale(saleId: number): Promise<ShopSale> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.delete(`${shopBase(id)}/sales/${saleId}/collect` as BackendApiRoute);

        return expectSuccess(res, 'The sale').data as ShopSale;
    }

    /** An oversold line's decision: refunded, or substituted. */
    async function resolveSale(saleId: number, resolution: SaleResolution): Promise<ShopSale> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.post(`${shopBase(id)}/sales/${saleId}/resolve` as BackendApiRoute, { resolution });

        return expectSuccess(res, 'The sale').data as ShopSale;
    }

    async function unresolveSale(saleId: number): Promise<ShopSale> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.delete(`${shopBase(id)}/sales/${saleId}/resolve` as BackendApiRoute);

        return expectSuccess(res, 'The sale').data as ShopSale;
    }

    /**
     * Download the filtered list as CSV.
     *
     * A bearer-token blob fetch, as the donations ledger and the form responses do it, because a plain
     * link carries no token. The request comes from the same query builder the list uses (without a
     * page), so the file can only hold what the filters on screen select.
     */
    async function exportSalesCsv(filters: SaleFilters): Promise<void> {
        const id = requireMasjidId();

        const request = salesCsvRequest(id, filters, localStorage.getItem(LOCAL_STORAGE_KEYS.token));
        const res = await fetch(request.url, request.init);

        // fetch only rejects on a network failure, so a 403/422 would otherwise be saved to disk as a
        // file full of JSON.
        if (!res.ok) throw new Error(`The pickup list export failed with ${res.status}`);

        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = csvFilename(res.headers.get('Content-Disposition'), new Date().toISOString().slice(0, 10));
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    return {
        productsPaginated,
        productsMeta,
        salesPaginated,
        salesMeta,
        masjidId,
        fetchProducts,
        fetchProduct,
        createProduct,
        updateProduct,
        deleteProduct,
        uploadImages,
        reorderImages,
        deleteImage,
        fetchSales,
        collectSale,
        uncollectSale,
        resolveSale,
        unresolveSale,
        exportSalesCsv
    }
})
