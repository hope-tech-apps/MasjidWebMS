<template>
    <div>
        <PageDataContainer title="Payment Methods">
            <div class="container w-100 pm">
                <p class="text-muted">
                    Tick each way {{ orgName }} accepts money and say how to pay with it. Your website shows these to people
                    ordering or paying, in this order. When money comes in by hand, "Mark paid" records which one it was.
                </p>

                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading…</span></div>
                </div>

                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
                </div>

                <form v-else @submit.prevent="save">
                    <ul class="list-unstyled m-0">
                        <li v-for="(row, i) in rows" :key="row.method" class="pm-row card mb-2" :class="{ 'pm-row--on': row.accepted }">
                            <div class="card-body py-3">
                                <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                                    <div class="form-check">
                                        <input :id="`pm-${row.method}`" v-model="row.accepted" class="form-check-input" type="checkbox" />
                                        <label class="form-check-label fw-semibold" :for="`pm-${row.method}`">{{ row.name }}</label>
                                    </div>
                                    <div class="btn-group btn-group-sm" role="group" :aria-label="`Move ${row.name}`">
                                        <button type="button" class="btn btn-outline-secondary" :disabled="i === 0" :aria-label="`Move ${row.name} up`" @click="move(i, -1)">↑</button>
                                        <button type="button" class="btn btn-outline-secondary" :disabled="i === rows.length - 1" :aria-label="`Move ${row.name} down`" @click="move(i, 1)">↓</button>
                                    </div>
                                </div>

                                <p v-if="row.online && row.accepted && !cardReady" class="small text-warning-emphasis mt-2 mb-0" role="status">
                                    Saved, but not shown to anyone yet: card payments start once your Stripe account can take charges (Online Payments).
                                </p>
                                <p v-else-if="row.online" class="small text-muted mt-2 mb-0">
                                    Paid online through your own Stripe account. No instructions needed; people pay on a secure card page.
                                </p>

                                <div v-if="row.accepted" class="row g-2 mt-1">
                                    <div class="col-md-4">
                                        <label class="form-label small" :for="`pm-label-${row.method}`">
                                            Name shown <span v-if="row.method !== 'other'" class="text-muted">(optional)</span>
                                        </label>
                                        <input :id="`pm-label-${row.method}`" v-model="row.label" class="form-control form-control-sm" maxlength="64"
                                            :placeholder="row.method === 'other' ? 'e.g. PayPal' : row.name" />
                                    </div>
                                    <div v-if="!row.online" class="col-md-8">
                                        <label class="form-label small" :for="`pm-how-${row.method}`">How to pay</label>
                                        <textarea :id="`pm-how-${row.method}`" v-model="row.instructions" class="form-control form-control-sm" rows="2" maxlength="2000"
                                            placeholder="e.g. Send to office@example.org with your order number."></textarea>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <div v-if="problem" class="alert alert-warning py-2 mt-2" role="alert">{{ problem }}</div>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-success" :disabled="saving || !!problem">{{ saving ? 'Saving…' : 'Save' }}</button>
                    </div>
                </form>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import Swal from "sweetalert2";
import PageDataContainer from "@/components/PageDataContainer.vue";
import { apiErrorText } from "@/core/services/ApiErrors";
import { useMasjidStore } from "@/stores/masjidStore";
import { usePaymentMethodsStore } from "@/stores/masjid/paymentMethodsStore";
import { DraftRow, draftFrom, draftProblem, moved, saveBody } from "@/views/dashboard/paymentMethods";

const masjidStore = useMasjidStore();
const store = usePaymentMethodsStore();

const loading = ref(true);
const loadError = ref("");
const saving = ref(false);
const rows = ref<DraftRow[]>([]);

const orgName = computed(() => masjidStore.masjid?.name || "your organisation");
const cardReady = computed(() => !!store.payload?.card_ready);
const problem = computed(() => draftProblem(rows.value));

function move(index: number, delta: -1 | 1) {
    rows.value = moved(rows.value, index, delta);
}

async function load() {
    if (!masjidStore.masjid?.id) return;
    loading.value = true;
    loadError.value = "";
    try {
        await store.fetchMethods();
        rows.value = draftFrom(store.payload?.methods, store.payload?.catalogue);
    } catch (e) {
        loadError.value = apiErrorText(e, "Could not load the payment methods.");
    } finally {
        loading.value = false;
    }
}

async function save() {
    if (problem.value) return;
    saving.value = true;
    try {
        await store.saveMethods(saveBody(rows.value));
        // Redrawn from what the server stored, never from what was sent.
        rows.value = draftFrom(store.payload?.methods, store.payload?.catalogue);
        Swal.fire({ toast: true, position: "top-end", icon: "success", title: "Payment methods saved", showConfirmButton: false, timer: 2500 });
    } catch (e) {
        Swal.fire({ icon: "error", title: "Not saved", text: apiErrorText(e, "Could not save the payment methods.") });
    } finally {
        saving.value = false;
    }
}

// Loads once the organisation is known, and again if the admin switches to another.
watch(() => masjidStore.masjid?.id, () => { void load(); }, { immediate: true });
</script>

<style scoped>
.pm-row--on { border-color: var(--bs-success-border-subtle, #a3cfbb); }
</style>
