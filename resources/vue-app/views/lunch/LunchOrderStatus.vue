<template>
    <div class="lunch-wrap" :dir="dir" :lang="lang">
        <div class="lunch-card">
            <div class="lunch-topbar">
                <button type="button" class="lunch-lang" @click="toggle">{{ switchLabel }}</button>
            </div>

            <div v-if="loading && !order" class="lunch-pad lunch-muted">{{ t('loading_order') }}</div>

            <div v-else-if="!order" class="lunch-pad">
                <h1>{{ t('not_found_title') }}</h1>
                <p class="lunch-muted">{{ error || t('not_found_body') }}</p>
            </div>

            <template v-else>
                <header class="lunch-head" :class="{ ok: paidNow }">
                    <div class="lunch-check">{{ paidNow ? "✓" : cancelled ? "!" : "🍽️" }}</div>
                    <h1>{{ headline }}</h1>
                    <p class="lunch-num">{{ t('order_num') }}{{ order.order_number }}</p>
                </header>

                <div class="lunch-pad">
                    <div v-if="cancelled" class="lunch-note warn">{{ t('cancel_note') }}</div>

                    <ul class="lunch-lines">
                        <li v-for="(it, i) in order.items" :key="i">
                            <span>{{ it.quantity }} × {{ it.item_name }}</span>
                            <span>{{ money(it.line_total_minor) }}</span>
                        </li>
                    </ul>

                    <div class="lunch-total-row">
                        <span>{{ t('total') }}</span>
                        <strong>{{ money(order.total_minor) }}</strong>
                    </div>

                    <div class="lunch-status-grid">
                        <div>
                            <span class="lbl">{{ t('payment') }}</span>
                            <span class="pill" :class="order.payment_status">{{ payLabel }}</span>
                        </div>
                        <div>
                            <span class="lbl">{{ t('method') }}</span>
                            <span class="pill">{{ order.payment_method === "online" ? t('online') : t('at_pickup') }}</span>
                        </div>
                    </div>

                    <p class="lunch-pickup-note">📍 {{ t('pickup_show') }} <strong>#{{ order.order_number }}</strong>.</p>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted } from "vue";
import { useRoute } from "vue-router";
import { usePublicLunchStore } from "@/stores/publicLunchStore";
import { useLunchLang } from "./lunchI18n";

const route = useRoute();
const store = usePublicLunchStore();
const { lang, dir, toggle, t, switchLabel } = useLunchLang();

const masjidId = String(route.params.masjidId);
const uuid = String(route.params.uuid);

const order = computed(() => store.order);
const loading = computed(() => store.loading);
const error = computed(() => store.error);

const cancelled = computed(() => route.query.cancelled === "1");
const paidNow = computed(() => order.value?.payment_status === "paid");

const headline = computed(() => {
    if (paidNow.value) return t("all_set");
    if (cancelled.value) return t("pay_cancelled");
    return t("order_received");
});

const payLabel = computed(() => {
    switch (order.value?.payment_status) {
        case "paid": return t("paid");
        case "refunded": return t("refunded");
        default: return t("not_paid");
    }
});

function money(minor: number): string {
    return "$" + (Number(minor) / 100).toFixed(2);
}

onMounted(() => store.fetchOrder(masjidId, uuid));
</script>

<style scoped>
.lunch-wrap {
    min-height: 100vh;
    background: linear-gradient(160deg, #0c3d2b 0%, #0a2c20 100%);
    padding: 24px 16px 60px;
    display: flex; justify-content: center;
    font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Arabic", sans-serif;
}
.lunch-card { width: 100%; max-width: 460px; background: #fff; border-radius: 18px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
.lunch-topbar { display: flex; justify-content: flex-end; padding: 10px 12px 0; }
.lunch-lang {
    background: #f2f8f5; color: #0c3d2b; border: 1px solid #cfe3d8;
    border-radius: 999px; padding: 4px 12px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.lunch-head { padding: 20px 22px 22px; background: linear-gradient(135deg, #0c3d2b, #14523a); color: #fff; text-align: center; }
.lunch-head.ok { background: linear-gradient(135deg, #1a7a4f, #0c3d2b); }
.lunch-check { font-size: 40px; line-height: 1; margin-bottom: 10px; }
.lunch-head h1 { font-size: 22px; margin: 0; }
.lunch-num { margin: 8px 0 0; opacity: .9; }
.lunch-pad { padding: 22px; }
.lunch-lines { list-style: none; margin: 0 0 4px; padding: 0; }
.lunch-lines li { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid #f2f2f2; font-size: 15px; gap: 12px; }
.lunch-total-row { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; font-size: 17px; }
.lunch-total-row strong { color: #0c3d2b; font-size: 20px; }
.lunch-status-grid { display: flex; gap: 12px; margin: 8px 0 18px; }
.lunch-status-grid > div { flex: 1; background: #faf7ef; border-radius: 12px; padding: 12px; text-align: center; }
.lbl { display: block; font-size: 12px; color: #999; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .04em; }
.pill { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; background: #eee; color: #444; }
.pill.paid { background: #d7f0e0; color: #1a7a4f; }
.pill.unpaid { background: #fbe6d4; color: #a05a1a; }
.pill.refunded { background: #eee; color: #666; }
.lunch-pickup-note { background: #f2f8f5; border-radius: 12px; padding: 14px; font-size: 14px; color: #0c3d2b; text-align: center; margin: 0; }
.lunch-note.warn { background: #fbe6d4; color: #a05a1a; border-radius: 12px; padding: 12px; font-size: 14px; margin-bottom: 16px; }
.lunch-muted { color: #888; }
</style>
