import { defineStore } from "pinia";
import { ref } from "vue";
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "@/stores/authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";

/**
 * Admin Jummah-lunch store — CRUD over
 * /api/admin/masjids/{masjid_id}/jummah-lunch/... .
 *
 * The active masjid comes from masjidStore; the `tenant` middleware +
 * BelongsToMasjid enforce that this admin only ever touches their own masjid's
 * menus/orders (the controllers never hand-filter). POST goes out as FormData
 * and PUT as URLSearchParams (the proven ApiService content-type paths), with
 * booleans serialized to '1'/'0' for Laravel's `boolean` rule.
 */
export const useJummahLunchStore = defineStore("jummahLunchStore", () => {
    const menus = ref<any[]>([]);
    const currentMenu = ref<any | null>(null);
    const orders = ref<any[]>([]);
    const orderSummary = ref<any | null>(null);
    // The masjid's services, for the "notify subscribers of" picker. Read from
    // the existing services endpoint rather than widening the lunch API.
    const services = ref<any[]>([]);

    const masjidStore = useMasjidStore();
    const authStore = useAuthStore();

    /**
     * A LunchStaff login reaches the SAME controllers through its own realm, so
     * this store serves both and only the prefix differs.
     *
     * The lunch realm carries NO masjid id: the server binds the tenant from the
     * principal's membership, which is the point — there is no id in the URL for
     * a volunteer to change. So `base()` must not interpolate one for them, and
     * `ensureMasjid()` must not demand a masjidStore that their shell never loads.
     */
    const isLunchStaff = () => authStore.user?.type === "LunchStaff";

    function base(): string {
        return isLunchStaff()
            ? "/api/lunch"
            : `/api/admin/masjids/${masjidStore.masjid?.id}/jummah-lunch`;
    }

    function ensureMasjid(): void {
        if (isLunchStaff()) {
            return;
        }

        if (!masjidStore.masjid?.id) {
            throw new Error("Masjid not specified.");
        }
    }

    // ---------------------------------------------------------------- menus

    async function fetchMenus(): Promise<void> {
        if (!isLunchStaff() && !masjidStore.masjid?.id) return;
        menus.value = [];
        const res: AxiosResponse = await ApiService.get(`${base()}/menus`);
        if (res.data?.status === "success" && Array.isArray(res.data?.data)) {
            menus.value = res.data.data;
        }
    }

    async function fetchMenu(id: number | string): Promise<any | null> {
        if (!isLunchStaff() && !masjidStore.masjid?.id) return null;
        const res: AxiosResponse = await ApiService.get(`${base()}/menus/${id}`);
        if (res.data?.status === "success" && res.data?.data) {
            currentMenu.value = res.data.data;
            return currentMenu.value;
        }
        return null;
    }

    async function createMenu(payload: Record<string, any>): Promise<any> {
        ensureMasjid();
        const body = menuFormData(payload);
        const res: AxiosResponse = await ApiService.post(`${base()}/menus`, body);
        if (res.data?.status === "success") return res.data.data;
        throw new Error("Failed to create menu.");
    }

    async function updateMenu(id: number | string, payload: Record<string, any>): Promise<any> {
        ensureMasjid();
        const res: AxiosResponse = await ApiService.put(`${base()}/menus/${id}`, menuUrlParams(payload));
        if (res.data?.status === "success") return res.data.data;
        throw new Error("Failed to update menu.");
    }

    async function deleteMenu(id: number | string): Promise<boolean> {
        ensureMasjid();
        const res: AxiosResponse = await ApiService.delete(`${base()}/menus/${id}`);
        return res.data?.status === "success";
    }

    // ---------------------------------------------------------------- items

    async function addItem(menuId: number | string, payload: Record<string, any>): Promise<any> {
        ensureMasjid();
        const res: AxiosResponse = await ApiService.post(`${base()}/menus/${menuId}/items`, itemFormData(payload));
        if (res.data?.status === "success") return res.data.data;
        throw new Error("Failed to add item.");
    }

    async function updateItem(menuId: number | string, itemId: number | string, payload: Record<string, any>): Promise<any> {
        ensureMasjid();
        const res: AxiosResponse = await ApiService.put(`${base()}/menus/${menuId}/items/${itemId}`, itemUrlParams(payload));
        if (res.data?.status === "success") return res.data.data;
        throw new Error("Failed to update item.");
    }

    async function deleteItem(menuId: number | string, itemId: number | string): Promise<boolean> {
        ensureMasjid();
        const res: AxiosResponse = await ApiService.delete(`${base()}/menus/${menuId}/items/${itemId}`);
        return res.data?.status === "success";
    }

    // --------------------------------------------------------------- orders

    async function fetchOrders(menuId: number | string): Promise<void> {
        if (!masjidStore.masjid?.id) return;
        orders.value = [];
        orderSummary.value = null;
        const res: AxiosResponse = await ApiService.get(`${base()}/menus/${menuId}/orders`);
        if (res.data?.status === "success" && res.data?.data) {
            orders.value = res.data.data.orders ?? [];
            orderSummary.value = res.data.data.summary ?? null;
        }
    }

    async function markOrderPaid(menuId: number | string, orderId: number | string): Promise<any> {
        ensureMasjid();
        const res: AxiosResponse = await ApiService.post(`${base()}/menus/${menuId}/orders/${orderId}/mark-paid`, new FormData());
        if (res.data?.status === "success") return res.data.data;
        throw new Error(typeof res.data?.data === "string" ? res.data.data : "Failed to mark paid.");
    }

    async function updateOrderStatus(menuId: number | string, orderId: number | string, status: string): Promise<any> {
        ensureMasjid();
        const body = new URLSearchParams();
        body.append("status", status);
        const res: AxiosResponse = await ApiService.put(`${base()}/menus/${menuId}/orders/${orderId}/status`, body);
        if (res.data?.status === "success") return res.data.data;
        throw new Error("Failed to update order.");
    }

    async function uploadFlyer(file: File): Promise<string> {
        ensureMasjid();
        const body = new FormData();
        body.append("flyer", file);
        const res: AxiosResponse = await ApiService.post(`${base()}/flyer`, body);
        if (res.data?.status === "success" && res.data?.data?.url) return res.data.data.url;
        throw new Error("Upload failed.");
    }

    // ------------------------------------------------------------- helpers

    function menuFormData(p: Record<string, any>): FormData {
        const b = new FormData();
        b.append("title", p.title ?? "Jummah Lunch");
        if (p.title_ar != null) b.append("title_ar", p.title_ar);
        b.append("service_date", p.service_date ?? "");
        if (p.status) b.append("status", p.status);
        if (p.ordering_closes_at) b.append("ordering_closes_at", p.ordering_closes_at);
        if (p.pickup_instructions != null) b.append("pickup_instructions", p.pickup_instructions);
        if (p.pickup_instructions_ar != null) b.append("pickup_instructions_ar", p.pickup_instructions_ar);
        if (p.flyer_image_url != null) b.append("flyer_image_url", p.flyer_image_url);
        if (p.notes != null) b.append("notes", p.notes);
        b.append("allow_online_payment", p.allow_online_payment ? "1" : "0");
        b.append("allow_pay_at_pickup", p.allow_pay_at_pickup ? "1" : "0");
        return b;
    }

    function menuUrlParams(p: Record<string, any>): URLSearchParams {
        const b = new URLSearchParams();
        if (p.title != null) b.append("title", p.title);
        if (p.title_ar !== undefined) b.append("title_ar", p.title_ar ?? "");
        if (p.service_date) b.append("service_date", p.service_date);
        if (p.status) b.append("status", p.status);
        if (p.ordering_closes_at != null) b.append("ordering_closes_at", p.ordering_closes_at ?? "");
        if (p.pickup_instructions != null) b.append("pickup_instructions", p.pickup_instructions ?? "");
        if (p.pickup_instructions_ar !== undefined) b.append("pickup_instructions_ar", p.pickup_instructions_ar ?? "");
        if (p.flyer_image_url !== undefined) b.append("flyer_image_url", p.flyer_image_url ?? "");
        if (p.notes != null) b.append("notes", p.notes ?? "");
        // Booleans go as "1"/"0", NEVER "true"/"false": Laravel's `boolean` rule
        // rejects those two strings outright, and this body is form-encoded.
        if (p.allow_online_payment != null) b.append("allow_online_payment", p.allow_online_payment ? "1" : "0");
        if (p.allow_pay_at_pickup != null) b.append("allow_pay_at_pickup", p.allow_pay_at_pickup ? "1" : "0");
        // Every one of these was missing, so editing a menu silently discarded
        // the toggle the admin had just changed and the form redisplayed the
        // old value as if the save had worked.
        if (p.collect_customer_email != null) b.append("collect_customer_email", p.collect_customer_email ? "1" : "0");
        if (p.allow_donation != null) b.append("allow_donation", p.allow_donation ? "1" : "0");
        if (p.allow_fee_coverage != null) b.append("allow_fee_coverage", p.allow_fee_coverage ? "1" : "0");
        if (p.allow_sms_optin != null) b.append("allow_sms_optin", p.allow_sms_optin ? "1" : "0");
        // Nullable: "" clears it, which is how an admin turns the text off.
        if (p.notify_service_id !== undefined) b.append("notify_service_id", p.notify_service_id == null ? "" : String(p.notify_service_id));
        return b;
    }

    function itemFormData(p: Record<string, any>): FormData {
        const b = new FormData();
        b.append("name", p.name ?? "");
        if (p.name_ar != null) b.append("name_ar", p.name_ar);
        if (p.description != null) b.append("description", p.description);
        if (p.description_ar != null) b.append("description_ar", p.description_ar);
        b.append("price_minor", String(p.price_minor ?? 0));
        b.append("is_available", p.is_available ? "1" : "0");
        if (p.max_quantity != null && p.max_quantity !== "") b.append("max_quantity", String(p.max_quantity));
        if (p.sort_order != null) b.append("sort_order", String(p.sort_order));
        return b;
    }

    function itemUrlParams(p: Record<string, any>): URLSearchParams {
        const b = new URLSearchParams();
        if (p.name != null) b.append("name", p.name);
        if (p.name_ar !== undefined) b.append("name_ar", p.name_ar ?? "");
        if (p.description != null) b.append("description", p.description ?? "");
        if (p.description_ar !== undefined) b.append("description_ar", p.description_ar ?? "");
        if (p.price_minor != null) b.append("price_minor", String(p.price_minor));
        if (p.is_available != null) b.append("is_available", p.is_available ? "1" : "0");
        if (p.max_quantity !== undefined) b.append("max_quantity", p.max_quantity == null || p.max_quantity === "" ? "" : String(p.max_quantity));
        if (p.sort_order != null) b.append("sort_order", String(p.sort_order));
        return b;
    }

    // ------------------------------------------------------- lunch-only staff
    //
    // Admin-side only. These live under the ADMIN prefix even when the store is
    // serving a LunchStaff, and the server refuses them for that principal — so
    // the UI never renders them for one. See LunchStaffController.

    const staff = ref<any[]>([]);

    async function fetchStaff(): Promise<void> {
        if (isLunchStaff() || !masjidStore.masjid?.id) return;
        const res: AxiosResponse = await ApiService.get(`${base()}/staff`);
        staff.value = Array.isArray(res.data?.data) ? res.data.data : [];
    }

    async function createStaff(payload: Record<string, any>): Promise<void> {
        ensureMasjid();
        const b = new FormData();
        b.append("name", payload.name ?? "");
        b.append("email", payload.email ?? "");
        if (payload.phone) b.append("phone", payload.phone);
        await ApiService.post(`${base()}/staff`, b);
        await fetchStaff();
    }

    async function updateStaff(id: number, payload: Record<string, any>): Promise<void> {
        ensureMasjid();
        const b = new URLSearchParams();
        b.append("name", payload.name ?? "");
        b.append("phone", payload.phone ?? "");
        await ApiService.put(`${base()}/staff/${id}`, b);
        await fetchStaff();
    }

    async function inviteStaff(id: number): Promise<void> {
        ensureMasjid();
        await ApiService.post(`${base()}/staff/${id}/invite`, new FormData());
    }

    async function removeStaff(id: number): Promise<void> {
        ensureMasjid();
        await ApiService.delete(`${base()}/staff/${id}`);
        await fetchStaff();
    }

    async function fetchServices(): Promise<void> {
        // Admin-only: the picker chooses which SERVICE subscribers are notified
        // about, and the services endpoint lives behind `admin`. Lunch staff
        // simply do not see that field.
        if (isLunchStaff() || !masjidStore.masjid?.id) return;
        try {
            const res: AxiosResponse = await ApiService.get(
                `/api/admin/masjids/${masjidStore.masjid.id}/services?page=1`
            );
            const d = res.data?.data;
            services.value = Array.isArray(d) ? d : (d?.data ?? []);
        } catch {
            // A missing picker must not break the board; the field just stays empty.
            services.value = [];
        }
    }

    return {
        menus, currentMenu, orders, orderSummary, services, fetchServices, isLunchStaff,
        staff, fetchStaff, createStaff, updateStaff, inviteStaff, removeStaff,
        fetchMenus, fetchMenu, createMenu, updateMenu, deleteMenu,
        addItem, updateItem, deleteItem,
        fetchOrders, markOrderPaid, updateOrderStatus, uploadFlyer,
    };
});
