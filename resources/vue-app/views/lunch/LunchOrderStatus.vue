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

                    <!-- What the server said about the change just saved, in its own
                         words, and the new card page when the amount moved. -->
                    <div v-if="savedMessage" class="lunch-note ok" role="status">{{ savedMessage }}</div>
                    <a v-if="newCheckoutUrl" class="lunch-pay-new" :href="newCheckoutUrl">{{ t('pay_new_total') }}</a>

                    <!-- Back from paying the difference on a paid order: the order
                         changes when the webhook records the payment, so this says
                         so, then says what happened once the order shows it. -->
                    <div v-if="topUpKey" class="lunch-note" :class="topUpTone" role="status">{{ t(topUpKey) }}</div>
                    <!-- A change still waiting on its payment, for a customer who comes
                         back from the email rather than from Stripe. -->
                    <div v-else-if="order.top_up && !editing" class="lunch-note warn" role="status">{{ topUpWaiting }}</div>

                    <ul class="lunch-lines">
                        <li v-for="(it, i) in order.items" :key="i">
                            <span>
                                <template v-if="!editing">{{ it.quantity }} × </template>{{ it.item_name }}
                                <small v-if="editing" class="lunch-muted">· {{ money(Number(it.unit_price_minor) * (draft[i] || 0)) }}</small>
                            </span>
                            <span v-if="!editing">{{ money(it.line_total_minor) }}</span>
                            <span v-else class="lunch-qty">
                                <button type="button" :aria-label="t('one_fewer', it.item_name)"
                                    :disabled="saving || (draft[i] || 0) <= 0" @click="bump(i, -1)">−</button>
                                <span class="lunch-qty-n" aria-live="polite">{{ draft[i] || 0 }}</span>
                                <button type="button" :aria-label="t('one_more', it.item_name)"
                                    :disabled="saving || (draft[i] || 0) >= 99" @click="bump(i, 1)">+</button>
                            </span>
                        </li>
                    </ul>

                    <!-- Shown only when there IS an extra, so an ordinary order
                         reads exactly as it did before. -->
                    <div v-if="Number(order.donation_minor) > 0" class="lunch-total-line">
                        <span>{{ t('give_line') }}</span>
                        <span>{{ money(order.donation_minor) }}</span>
                    </div>

                    <div v-if="Number(order.fee_covered_minor) > 0" class="lunch-total-line">
                        <span>{{ t('fee_line') }}</span>
                        <span>{{ money(order.fee_covered_minor) }}</span>
                    </div>

                    <!-- While editing, the figure shown is this page's arithmetic on
                         the prices it was given. The SERVER re-prices every line and
                         recomputes the card fee, so the label says the total is
                         settled on save and the saved order's own total comes back
                         from the server a moment later. On a PAID order it is set
                         against what was paid: the difference is paid first. -->
                    <div v-if="editing && isPaid" class="lunch-total-line">
                        <span>{{ t('topup_paid') }}</span>
                        <span>{{ money(paidMinor) }}</span>
                    </div>

                    <div class="lunch-total-row">
                        <span>{{ editing ? (isPaid ? t('topup_new_total') : t('edit_new_total')) : t('total') }}</span>
                        <strong>{{ money(editing ? previewTotal : order.total_minor) }}</strong>
                    </div>

                    <div v-if="editing && isPaid && toPayNow > 0" class="lunch-total-line lunch-topay">
                        <span>{{ t('topup_to_pay') }}</span>
                        <strong>{{ money(toPayNow) }}</strong>
                    </div>

                    <div class="lunch-edit">
                        <template v-if="!editing">
                            <button v-if="order.can_edit" type="button" class="lunch-btn" @click="startEdit">{{ t('edit_change') }}</button>
                            <!-- Not a button they can't press: the reason, said in
                                 the reader's own language when the server named
                                 the fact, and in the server's own words otherwise. -->
                            <p v-else-if="editWhy" class="lunch-muted lunch-why">{{ editWhy }}</p>
                        </template>
                        <template v-else>
                            <p v-if="draftPlates <= 0" class="lunch-muted lunch-why">{{ t('edit_min_one') }}</p>
                            <!-- No automatic refunds: fewer plates on a paid order is
                                 the masjid's to do, so it cannot be saved here. -->
                            <p v-else-if="paidBlock" class="lunch-note warn lunch-why">{{ t(paidBlock) }}</p>
                            <div class="lunch-edit-actions">
                                <button type="button" class="lunch-btn ghost" :disabled="saving" @click="cancelEdit">{{ t('edit_cancel') }}</button>
                                <button type="button" class="lunch-btn" :disabled="saving || draftPlates <= 0 || !draftDirty || !!paidBlock" @click="saveEdit">
                                    {{ saveLabel }}
                                </button>
                            </div>
                        </template>
                        <p v-if="editError" class="lunch-note warn lunch-why" role="alert">{{ editError }}</p>
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
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { usePublicLunchStore } from "@/stores/publicLunchStore";
import { useLunchLang } from "./lunchI18n";
import { EDIT_WHY_KEYS, REFUSAL_KEYS, paidDraftBlock, topUpOutcome, withoutTopUpMarker } from "./lunchTopUp";

const route = useRoute();
const router = useRouter();
const store = usePublicLunchStore();
const { lang, dir, toggle, t, switchLabel, locale } = useLunchLang();

const masjidId = String(route.params.masjidId);
const uuid = String(route.params.uuid);

const order = computed(() => store.order);
const loading = computed(() => store.loading);
const error = computed(() => store.error);

const cancelled = computed(() => route.query.cancelled === "1");
const paidNow = computed(() => order.value?.payment_status === "paid");
const isPaid = paidNow;
// What has actually been paid (the server's figure, never worked out here).
const paidMinor = computed(() => Number(order.value?.paid_minor || 0));

const headline = computed(() => {
    if (paidNow.value) return t("all_set");
    if (cancelled.value) return t("pay_cancelled");
    return t("order_received");
});

// Why the order cannot be changed. The server sends both a sentence and a name
// for the fact; the name is what can be translated, and an unknown one falls back
// to the sentence so a newer server is never silenced by an older bundle.
const editWhy = computed<string>(() => {
    const key = EDIT_WHY_KEYS[String(order.value?.edit_notice_code ?? "")];
    return key ? t(key) : String(order.value?.edit_notice ?? "");
});

// A change waiting on its payment: how much, and until when that page takes it.
const topUpWaiting = computed<string>(() => {
    const pending = order.value?.top_up;
    if (!pending) return "";
    const line = t("topup_waiting", money(Number(pending.amount_minor || 0)));
    const until = pending.expires_at ? new Date(pending.expires_at) : null;
    if (!until || Number.isNaN(until.getTime())) return line;
    return line + " (" + until.toLocaleTimeString(locale.value, { hour: "numeric", minute: "2-digit" }) + ")";
});

const payLabel = computed(() => {
    switch (order.value?.payment_status) {
        case "paid": return t("paid");
        case "refunded": return t("refunded");
        default: return t("not_paid");
    }
});

// --------------------------------------------- changing an order already placed
//
// Offered only while the SERVER says it may be (order.can_edit), and refused
// again by the server on the locked row whatever this page decided. The draft is
// a quantity per line, by position, and Save sends the FULL set of lines — which
// is what the endpoint takes, and what stops two edits crossing from adding up to
// a basket nobody chose.
//
// NO PRICE IS EVER SENT. The number shown while editing is this page's own sum
// of the unit prices it was given, labelled as settled on save; the totals it
// shows afterwards are the server's.
const editing = ref(false);
const saving = ref(false);
const editError = ref("");
const savedMessage = ref("");
const newCheckoutUrl = ref("");
const draft = ref<number[]>([]);

const lines = computed<any[]>(() => order.value?.items ?? []);
const draftPlates = computed(() => draft.value.reduce((n, q) => n + (Number(q) || 0), 0));
// Saving a basket identical to the one already stored spends one of the twelve
// public order-writes an hour this IP gets on this masjid (AppServiceProvider's
// `lunch-order` limiter keys on IP + masjid-id, so it is per connection, not per
// masjid — though everyone on the masjid's wifi shares one). The server answers
// "Your order is unchanged.", records nothing, and there was nothing to spend it
// on.
const draftDirty = computed(() => lines.value.some(
    (it: any, i: number) => (draft.value[i] || 0) !== (Number(it.quantity) || 0)));
const draftSubtotal = computed(() => lines.value.reduce(
    (sum: number, it: any, i: number) => sum + Number(it.unit_price_minor || 0) * (draft.value[i] || 0), 0));
// The extra the customer chose is never touched by an edit, and the card fee only
// moves on an order that was already covering it — so both are carried across as
// they stand, and the server has the last word on the fee.
const previewTotal = computed(() => draftSubtotal.value
    + Number(order.value?.donation_minor || 0)
    + Number(order.value?.fee_covered_minor || 0));

// A PAID order is changed on the money's terms: a lower total cannot be saved
// here, the same total saves as usual, and a higher one is paid first — the
// server sends the payment page and changes nothing until that payment lands.
// Why this draft cannot be saved here (fewer plates than were paid for, or more
// plates in the last half hour), said BEFORE the tap; the server refuses both too.
const paidBlock = computed<string | null>(() => paidDraftBlock({
    isPaid: isPaid.value,
    previewTotal: previewTotal.value,
    paidMinor: paidMinor.value,
    openUntil: order.value?.topup_open_until,
    nowMs: Date.now(),
}));
const toPayNow = computed(() => (isPaid.value ? Math.max(0, previewTotal.value - paidMinor.value) : 0));
const saveLabel = computed(() => {
    if (toPayNow.value > 0) {
        return saving.value ? t("topup_redirecting") : t("topup_pay_button", money(toPayNow.value));
    }
    return saving.value ? t("edit_saving") : t("edit_save");
});

function startEdit(): void {
    draft.value = lines.value.map((it: any) => Number(it.quantity) || 0);
    editError.value = "";
    savedMessage.value = "";
    newCheckoutUrl.value = "";
    editing.value = true;
}

function cancelEdit(): void {
    editing.value = false;
    editError.value = "";
}

function bump(i: number, delta: number): void {
    const next = [...draft.value];
    // 99 is the endpoint's own ceiling per line; the kitchen's cap is lower and
    // is the server's to enforce, in words this page shows as they come.
    next[i] = Math.max(0, Math.min(99, (next[i] || 0) + delta));
    draft.value = next;
}

async function saveEdit(): Promise<void> {
    if (!order.value || draftPlates.value <= 0 || !draftDirty.value || paidBlock.value || saving.value) return;
    saving.value = true;
    editError.value = "";

    const items = lines.value
        .map((it: any, i: number) => ({
            meal_menu_item_id: Number(it.meal_menu_item_id),
            quantity: draft.value[i] || 0,
        }))
        // A line whose menu item was deleted has no id to send. The server already
        // refuses to offer an edit on such an order (can_edit), so this never
        // drops a line in practice — it is here so it cannot start to.
        .filter((l) => Number.isFinite(l.meal_menu_item_id) && l.meal_menu_item_id > 0);

    const res = await store.updateOrder(masjidId, uuid, items);

    // Nothing has changed yet: the difference is paid on Stripe's page first, and
    // the order changes when the webhook records it. The button stays disabled
    // while the browser leaves.
    if (res.ok && res.paymentRequired && res.checkoutUrl) {
        window.location.assign(res.checkoutUrl);
        return;
    }

    saving.value = false;

    if (!res.ok) {
        // The server's reason — closed, over the kitchen's cap, fewer plates on a
        // paid order — in the reader's language when it named it, else as it wrote it.
        const key = REFUSAL_KEYS[String(res.code ?? "")];
        editError.value = key ? t(key) : (res.message || t("edit_failed"));
        return;
    }

    editing.value = false;
    savedMessage.value = res.message || t("edit_saved");
    // Their old card page was for the old amount and has been closed; this is the
    // new one. Without it an edit would take away the only way they had to pay.
    newCheckoutUrl.value = res.checkoutUrl || "";
}

function money(minor: number): string {
    // Grouped, because the optional extra is the first field on these pages that
    // can render a four-figure number and "$1000.00" reads like a typo. Always
    // en-US grouping: the prices are US dollars whichever language is showing.
    return "$" + (Number(minor) / 100).toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

// ------------------------------------------ back from paying the difference
//
// Stripe sends the customer back with ?topup=success the moment they pay, which
// is usually a few seconds BEFORE the webhook has recorded it. The order is read
// again until its last top-up is no longer pending, then the note says what the
// SERVER recorded (last_top_up_status): updated, paid-but-not-applied, or not
// confirmed. setTimeout, never requestAnimationFrame: a tab in the background
// must still get there. Bounded, because the status read shares a 60-an-hour
// allowance per connection.
const topUpKey = ref("");
const topUpTone = ref<"ok" | "warn">("ok");
const POLL_DELAYS_MS = [2000, 3000, 4000, 5000, 6000, 8000, 10000, 12000];
let pollTimer: ReturnType<typeof setTimeout> | null = null;

function settledTopUp(o: any): boolean {
    const outcome = topUpOutcome(o);
    if (!outcome) return false;
    topUpKey.value = outcome.key;
    topUpTone.value = outcome.tone;
    return true;
}

function pollTopUp(attempt: number): void {
    if (attempt >= POLL_DELAYS_MS.length) {
        topUpKey.value = "topup_slow";
        topUpTone.value = "warn";
        return;
    }
    pollTimer = setTimeout(async () => {
        const fresh = await store.refreshOrder(masjidId, uuid);
        if (!settledTopUp(fresh)) pollTopUp(attempt + 1);
    }, POLL_DELAYS_MS[attempt]);
}

// Back from Stripe with the browser's Back button, a phone restores this page
// from its back/forward cache exactly as it was left: "Taking you to the payment
// page…", disabled. Nothing was sent that is still in flight, so the editor is
// usable again.
function onPageShow(e: PageTransitionEvent): void {
    if (e.persisted) saving.value = false;
}

onMounted(async () => {
    window.addEventListener("pageshow", onPageShow);

    const marker = route.query.topup;
    const loaded = await store.fetchOrder(masjidId, uuid);

    if (marker === "cancelled") {
        topUpKey.value = "topup_cancelled";
        topUpTone.value = "warn";
    } else if (marker === "success" && loaded) {
        if (!settledTopUp(loaded)) {
            topUpKey.value = "topup_confirming";
            topUpTone.value = "ok";
            pollTopUp(0);
        }
    }

    // Read once. Left in the URL, a reload weeks later would replay the note.
    if (marker !== undefined) {
        router.replace({ query: withoutTopUpMarker({ ...route.query }) }).catch(() => undefined);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener("pageshow", onPageShow);
    if (pollTimer) clearTimeout(pollTimer);
});
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
.lunch-total-line {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    font-size: 14px;
    color: #5d7a6d;
}
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
.lunch-note.ok { background: #d7f0e0; color: #14533a; border-radius: 12px; padding: 12px; font-size: 14px; margin-bottom: 12px; }
.lunch-topay { color: #0c3d2b; font-size: 15px; }
/* Quantity stepper. Flex with gap only, no side margins, so the row reads the
   same way round in Arabic as the line it sits on. */
.lunch-qty { display: flex; align-items: center; gap: 10px; }
.lunch-qty button {
    width: 30px; height: 30px; border-radius: 50%; font-size: 17px; line-height: 1;
    background: #f2f8f5; color: #0c3d2b; border: 1px solid #cfe3d8; cursor: pointer;
}
.lunch-qty button:disabled { opacity: .45; cursor: default; }
.lunch-qty-n { min-width: 1.5rem; text-align: center; font-weight: 600; font-variant-numeric: tabular-nums; }
.lunch-edit { margin-bottom: 16px; }
.lunch-edit-actions { display: flex; gap: 10px; }
.lunch-btn {
    flex: 1; padding: 11px 16px; border-radius: 12px; border: 1px solid #0c3d2b;
    background: #0c3d2b; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer;
}
.lunch-btn.ghost { background: #fff; color: #0c3d2b; }
.lunch-btn:disabled { opacity: .5; cursor: default; }
.lunch-why { font-size: 13px; margin: 0 0 10px; }
.lunch-muted { color: #888; }
</style>
