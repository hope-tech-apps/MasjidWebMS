<template>
    <div class="jl container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="mb-1">Jummah Lunch</h3>
                <p class="text-muted mb-0">Set this week's menu and watch orders come in.</p>
            </div>
            <button class="btn btn-success" @click="openCreateMenu">+ New menu</button>
        </div>

        <!-- Menus list -->
        <div v-if="!currentMenu">
            <div v-if="loading" class="text-muted">Loading…</div>
            <div v-else-if="menus.length === 0" class="text-center text-muted py-5 border rounded">
                No menus yet. Create one to start taking Jummah lunch orders.
            </div>
            <div v-else class="row g-3">
                <div v-for="m in menus" :key="m.id" class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <h5 class="card-title mb-1">{{ m.title }}</h5>
                                <span class="badge" :class="statusClass(m.status)">{{ m.status }}</span>
                            </div>
                            <div class="text-muted small mb-2">{{ formatDate(m.service_date) }}</div>
                            <div class="small mb-3">
                                {{ m.items_count ?? 0 }} item(s) · {{ m.orders_count ?? 0 }} order(s)
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-primary" @click="manageMenu(m.id)">Manage</button>
                                <button class="btn btn-sm btn-outline-secondary" @click="openEditMenu(m)">Edit</button>
                                <button class="btn btn-sm btn-outline-danger" @click="removeMenu(m)">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Menu detail -->
        <div v-else class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <button class="btn btn-sm btn-link px-0 text-decoration-none" @click="closeMenu">← All menus</button>
                    <h5 class="mb-0">{{ currentMenu.title }} <span class="text-muted small">· {{ formatDate(currentMenu.service_date) }}</span></h5>
                </div>
                <div class="btn-group">
                    <button v-for="s in ['draft','open','closed']" :key="s" class="btn btn-sm"
                        :class="currentMenu.status === s ? statusBtn(s) : 'btn-outline-secondary'"
                        @click="setStatus(s)">{{ s }}</button>
                </div>
            </div>

            <div class="card-body">
                <p v-if="currentMenu.status === 'open'" class="alert alert-success py-2 small">
                    ✅ This menu is <strong>OPEN</strong> — the public order page is live at
                    <code>/jummah-lunch/{{ masjidId }}</code>.
                </p>

                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item"><a class="nav-link" :class="{ active: tab === 'items' }" href="#" @click.prevent="tab = 'items'">Menu items</a></li>
                    <li class="nav-item"><a class="nav-link" :class="{ active: tab === 'orders' }" href="#" @click.prevent="switchToOrders">Orders <span v-if="summary" class="badge bg-secondary">{{ summary.orders }}</span></a></li>
                </ul>

                <!-- Items tab -->
                <div v-if="tab === 'items'">
                    <div class="d-flex justify-content-end mb-2">
                        <button class="btn btn-sm btn-success" @click="openAddItem">+ Add item</button>
                    </div>
                    <div v-if="!currentMenu.items || currentMenu.items.length === 0" class="text-muted text-center py-4">
                        No items yet. Add the first plate.
                    </div>
                    <table v-else class="table align-middle">
                        <thead><tr><th>Item</th><th>Price</th><th>Available</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="it in currentMenu.items" :key="it.id">
                                <td>
                                    <div class="fw-semibold">{{ it.name }}</div>
                                    <div class="text-muted small" v-if="it.description">{{ it.description }}</div>
                                </td>
                                <td>{{ money(it.price_minor) }}</td>
                                <td><span class="badge" :class="it.is_available ? 'bg-success' : 'bg-secondary'">{{ it.is_available ? 'Yes' : 'No' }}</span></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-secondary me-1" @click="openEditItem(it)">Edit</button>
                                    <button class="btn btn-sm btn-outline-danger" @click="removeItem(it)">×</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Orders tab -->
                <div v-else>
                    <div v-if="summary" class="row g-2 mb-3">
                        <div class="col"><div class="stat"><div class="stat-n">{{ summary.orders }}</div><div class="stat-l">Orders</div></div></div>
                        <div class="col"><div class="stat"><div class="stat-n">{{ summary.paid_orders }}</div><div class="stat-l">Paid</div></div></div>
                        <div class="col"><div class="stat"><div class="stat-n">{{ money(summary.revenue_paid_minor) }}</div><div class="stat-l">Collected</div></div></div>
                        <div class="col"><div class="stat"><div class="stat-n">{{ money(summary.expected_total_minor) }}</div><div class="stat-l">Expected</div></div></div>
                    </div>
                    <div v-if="orders.length === 0" class="text-muted text-center py-4">No orders yet.</div>
                    <div v-else class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>#</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="o in orders" :key="o.id">
                                    <td class="fw-bold">{{ o.order_number }}</td>
                                    <td>
                                        <div>{{ o.customer_name }}</div>
                                        <div class="text-muted small">{{ o.customer_phone }}</div>
                                    </td>
                                    <td class="small">{{ itemsLabel(o) }}</td>
                                    <td>{{ money(o.total_minor) }}</td>
                                    <td>
                                        <span class="badge" :class="o.payment_status === 'paid' ? 'bg-success' : 'bg-warning text-dark'">{{ o.payment_status }}</span>
                                        <div class="text-muted small">{{ o.payment_method === 'online' ? 'online' : 'at pickup' }}</div>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm" :value="o.status" @change="setOrderStatus(o, ($event.target as HTMLSelectElement).value)">
                                            <option v-for="s in ['pending','confirmed','ready','picked_up','cancelled']" :key="s" :value="s">{{ s }}</option>
                                        </select>
                                    </td>
                                    <td class="text-end">
                                        <button v-if="o.payment_method === 'pickup' && o.payment_status === 'unpaid'"
                                            class="btn btn-sm btn-success" @click="markPaid(o)">Mark paid</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Menu modal -->
        <div v-if="menuModal.show" class="jl-modal">
            <div class="jl-dialog card">
                <div class="card-header"><h5 class="mb-0">{{ menuModal.isEdit ? 'Edit menu' : 'New menu' }}</h5></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label">Title</label><input v-model="menuModal.form.title" class="form-control" maxlength="120" /></div>
                    <div class="mb-2"><label class="form-label">Title — Arabic <span class="text-muted small">(optional)</span></label><input v-model="menuModal.form.title_ar" class="form-control" dir="rtl" maxlength="120" placeholder="العنوان بالعربية" /></div>
                    <div class="mb-2"><label class="form-label">Service date (Friday)</label><input v-model="menuModal.form.service_date" type="date" class="form-control" /></div>
                    <div class="mb-2"><label class="form-label">Ordering closes at <span class="text-muted small">(optional)</span></label><input v-model="menuModal.form.ordering_closes_at" type="datetime-local" class="form-control" /></div>
                    <div class="mb-2"><label class="form-label">Pickup instructions</label><input v-model="menuModal.form.pickup_instructions" class="form-control" maxlength="255" /></div>
                    <div class="mb-2"><label class="form-label">Pickup instructions — Arabic <span class="text-muted small">(optional)</span></label><input v-model="menuModal.form.pickup_instructions_ar" class="form-control" dir="rtl" maxlength="255" placeholder="تعليمات الاستلام بالعربية" /></div>
                    <div class="mb-2">
                        <label class="form-label">Flyer image <span class="text-muted small">(optional)</span></label>
                        <div v-if="menuModal.form.flyer_image_url" class="mb-2 d-flex align-items-center gap-2">
                            <img :src="menuModal.form.flyer_image_url" alt="flyer" style="max-height:120px;border-radius:8px;border:1px solid #eee" />
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="menuModal.form.flyer_image_url = ''">Remove</button>
                        </div>
                        <input type="file" accept="image/*" class="form-control" @change="onFlyerFile" :disabled="uploadingFlyer" />
                        <div v-if="uploadingFlyer" class="text-muted small mt-1">Uploading…</div>
                    </div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" v-model="menuModal.form.allow_online_payment" id="jlaop" /><label class="form-check-label" for="jlaop">Allow pay online (Stripe)</label></div>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" v-model="menuModal.form.allow_pay_at_pickup" id="jlapp" /><label class="form-check-label" for="jlapp">Allow pay at pickup</label></div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" @click="menuModal.show = false">Cancel</button>
                    <button class="btn btn-success" :disabled="savingMenu" @click="saveMenu">{{ savingMenu ? 'Saving…' : 'Save' }}</button>
                </div>
            </div>
        </div>

        <!-- Item modal -->
        <div v-if="itemModal.show" class="jl-modal">
            <div class="jl-dialog card">
                <div class="card-header"><h5 class="mb-0">{{ itemModal.isEdit ? 'Edit item' : 'Add item' }}</h5></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label">Name</label><input v-model="itemModal.form.name" class="form-control" maxlength="120" /></div>
                    <div class="mb-2"><label class="form-label">Name — Arabic <span class="text-muted small">(optional)</span></label><input v-model="itemModal.form.name_ar" class="form-control" dir="rtl" maxlength="120" placeholder="الاسم بالعربية" /></div>
                    <div class="mb-2"><label class="form-label">Description <span class="text-muted small">(optional)</span></label><input v-model="itemModal.form.description" class="form-control" maxlength="500" /></div>
                    <div class="mb-2"><label class="form-label">Description — Arabic <span class="text-muted small">(optional)</span></label><input v-model="itemModal.form.description_ar" class="form-control" dir="rtl" maxlength="500" placeholder="الوصف بالعربية" /></div>
                    <div class="mb-2"><label class="form-label">Price ($)</label><input v-model="itemModal.form.price" type="number" min="0" step="0.01" class="form-control" placeholder="8.00" /></div>
                    <div class="mb-2"><label class="form-label">Max per order <span class="text-muted small">(optional)</span></label><input v-model="itemModal.form.max_quantity" type="number" min="1" class="form-control" /></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" v-model="itemModal.form.is_available" id="jlavail" /><label class="form-check-label" for="jlavail">Available to order</label></div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" @click="itemModal.show = false">Cancel</button>
                    <button class="btn btn-success" :disabled="savingItem" @click="saveItem">{{ savingItem ? 'Saving…' : 'Save' }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeMount, reactive, ref } from "vue";
import Swal from "sweetalert2";
import { useJummahLunchStore } from "@/stores/masjid/jummahLunchStore";
import { useMasjidStore } from "@/stores/masjidStore";

const store = useJummahLunchStore();
const masjidStore = useMasjidStore();

const loading = ref(false);
const savingMenu = ref(false);
const savingItem = ref(false);
const uploadingFlyer = ref(false);
const tab = ref<"items" | "orders">("items");

const masjidId = computed(() => masjidStore.masjid?.id);
const menus = computed(() => store.menus);
const currentMenu = computed(() => store.currentMenu);
const orders = computed(() => store.orders);
const summary = computed(() => store.orderSummary);

const menuModal = reactive({
    show: false, isEdit: false, id: null as number | null,
    form: emptyMenuForm(),
});
const itemModal = reactive({
    show: false, isEdit: false, id: null as number | null,
    form: emptyItemForm(),
});

function emptyMenuForm() {
    return {
        title: "Jummah Lunch", title_ar: "", service_date: "", ordering_closes_at: "",
        pickup_instructions: "Pick up after Jummah in the main hall.", pickup_instructions_ar: "", flyer_image_url: "",
        allow_online_payment: true, allow_pay_at_pickup: true,
    };
}
function emptyItemForm() {
    return { name: "", name_ar: "", description: "", description_ar: "", price: "", max_quantity: "", is_available: true };
}

function money(minor: number): string {
    return "$" + (Number(minor || 0) / 100).toFixed(2);
}
function formatDate(d: string): string {
    if (!d) return "";
    try {
        return new Date(String(d).slice(0, 10) + "T00:00:00").toLocaleDateString(undefined, { weekday: "short", month: "short", day: "numeric" });
    } catch { return d; }
}
function statusClass(s: string): string {
    return s === "open" ? "bg-success" : s === "closed" ? "bg-secondary" : "bg-warning text-dark";
}
function statusBtn(s: string): string {
    return s === "open" ? "btn-success" : s === "closed" ? "btn-secondary" : "btn-warning";
}
function itemsLabel(o: any): string {
    return (o.items || []).map((i: any) => `${i.quantity}× ${i.item_name}`).join(", ");
}

async function load() {
    loading.value = true;
    try { await store.fetchMenus(); } catch (e) { toastError(e); }
    loading.value = false;
}

function openCreateMenu() {
    menuModal.isEdit = false; menuModal.id = null; menuModal.form = emptyMenuForm(); menuModal.show = true;
}
function openEditMenu(m: any) {
    menuModal.isEdit = true; menuModal.id = m.id;
    menuModal.form = {
        title: m.title, title_ar: m.title_ar ?? "", service_date: String(m.service_date ?? "").slice(0, 10),
        ordering_closes_at: m.ordering_closes_at ? String(m.ordering_closes_at).slice(0, 16) : "",
        pickup_instructions: m.pickup_instructions ?? "", pickup_instructions_ar: m.pickup_instructions_ar ?? "", flyer_image_url: m.flyer_image_url ?? "",
        allow_online_payment: !!m.allow_online_payment, allow_pay_at_pickup: !!m.allow_pay_at_pickup,
    };
    menuModal.show = true;
}
async function saveMenu() {
    savingMenu.value = true;
    try {
        if (menuModal.isEdit && menuModal.id) await store.updateMenu(menuModal.id, menuModal.form);
        else await store.createMenu(menuModal.form);
        menuModal.show = false;
        await load();
        if (currentMenu.value && menuModal.id === currentMenu.value.id) await store.fetchMenu(menuModal.id);
        toast("Menu saved");
    } catch (e) { toastError(e); } finally { savingMenu.value = false; }
}
async function onFlyerFile(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    uploadingFlyer.value = true;
    try {
        menuModal.form.flyer_image_url = await store.uploadFlyer(file);
        toast("Flyer uploaded");
    } catch (err) {
        toastError(err);
    } finally {
        uploadingFlyer.value = false;
        input.value = "";
    }
}

async function removeMenu(m: any) {
    const ok = await confirmDelete(`Delete "${m.title}"?`);
    if (!ok) return;
    try { await store.deleteMenu(m.id); await load(); toast("Menu deleted"); } catch (e) { toastError(e); }
}

async function manageMenu(id: number) {
    tab.value = "items";
    try { await store.fetchMenu(id); } catch (e) { toastError(e); }
}
function closeMenu() { store.currentMenu = null as any; }
async function setStatus(s: string) {
    if (!currentMenu.value) return;
    try { await store.updateMenu(currentMenu.value.id, { status: s }); await store.fetchMenu(currentMenu.value.id); await load(); } catch (e) { toastError(e); }
}
async function switchToOrders() {
    tab.value = "orders";
    if (currentMenu.value) { try { await store.fetchOrders(currentMenu.value.id); } catch (e) { toastError(e); } }
}

function openAddItem() { itemModal.isEdit = false; itemModal.id = null; itemModal.form = emptyItemForm(); itemModal.show = true; }
function openEditItem(it: any) {
    itemModal.isEdit = true; itemModal.id = it.id;
    itemModal.form = { name: it.name, name_ar: it.name_ar ?? "", description: it.description ?? "", description_ar: it.description_ar ?? "", price: (Number(it.price_minor) / 100).toFixed(2), max_quantity: it.max_quantity ?? "", is_available: !!it.is_available };
    itemModal.show = true;
}
async function saveItem() {
    if (!currentMenu.value) return;
    savingItem.value = true;
    const payload = {
        name: itemModal.form.name,
        name_ar: itemModal.form.name_ar,
        description: itemModal.form.description,
        description_ar: itemModal.form.description_ar,
        price_minor: Math.round(Number(itemModal.form.price || 0) * 100),
        max_quantity: itemModal.form.max_quantity === "" ? null : Number(itemModal.form.max_quantity),
        is_available: itemModal.form.is_available,
    };
    try {
        if (itemModal.isEdit && itemModal.id) await store.updateItem(currentMenu.value.id, itemModal.id, payload);
        else await store.addItem(currentMenu.value.id, payload);
        itemModal.show = false;
        await store.fetchMenu(currentMenu.value.id);
        toast("Item saved");
    } catch (e) { toastError(e); } finally { savingItem.value = false; }
}
async function removeItem(it: any) {
    if (!currentMenu.value) return;
    const ok = await confirmDelete(`Remove "${it.name}"?`);
    if (!ok) return;
    try { await store.deleteItem(currentMenu.value.id, it.id); await store.fetchMenu(currentMenu.value.id); toast("Item removed"); } catch (e) { toastError(e); }
}

async function markPaid(o: any) {
    if (!currentMenu.value) return;
    try { await store.markOrderPaid(currentMenu.value.id, o.id); await store.fetchOrders(currentMenu.value.id); toast("Marked paid"); } catch (e) { toastError(e); }
}
async function setOrderStatus(o: any, status: string) {
    if (!currentMenu.value || status === o.status) return;
    try { await store.updateOrderStatus(currentMenu.value.id, o.id, status); await store.fetchOrders(currentMenu.value.id); } catch (e) { toastError(e); }
}

function toast(title: string) {
    Swal.fire({ toast: true, position: "top-end", icon: "success", title, showConfirmButton: false, timer: 1800 });
}
function toastError(e: any) {
    Swal.fire({ toast: true, position: "top-end", icon: "error", title: e?.message || "Something went wrong", showConfirmButton: false, timer: 3000 });
}
async function confirmDelete(text: string): Promise<boolean> {
    const r = await Swal.fire({ title: text, icon: "warning", showCancelButton: true, confirmButtonText: "Delete", confirmButtonColor: "#c0392b" });
    return r.isConfirmed;
}

onBeforeMount(load);
</script>

<style scoped>
.jl-modal { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: flex-start; justify-content: center; padding-top: 6vh; z-index: 1080; }
.jl-dialog { width: 100%; max-width: 460px; }
.stat { background: #f6f8fa; border-radius: 10px; padding: 12px; text-align: center; }
.stat-n { font-size: 20px; font-weight: 700; color: #0c3d2b; }
.stat-l { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: .03em; }
.nav-tabs .nav-link { cursor: pointer; }
</style>
