<template>
    <div class="lunch-wrap" :dir="dir" :lang="lang">
        <div class="lunch-card">
            <header class="lunch-head">
                <button type="button" class="lunch-lang" @click="toggle">{{ switchLabel }}</button>
                <div class="lunch-badge">🍽️ {{ t('badge') }}</div>
                <h1 v-if="menu">{{ menuTitle }}</h1>
                <p v-if="menu?.service_date" class="lunch-date">{{ formatDate(menu.service_date) }}</p>
                <p v-if="menuPickup" class="lunch-pickup">📍 {{ menuPickup }}</p>
            </header>

            <img v-if="menu?.flyer_image_url" :src="menu.flyer_image_url" class="lunch-flyer" alt="" />

            <div v-if="loading && !menu" class="lunch-muted lunch-pad">{{ t('loading_menu') }}</div>

            <div v-else-if="!menu" class="lunch-empty">
                <p>{{ t('none_open') }}</p>
                <p class="lunch-muted">{{ t('check_back') }}</p>
            </div>

            <template v-else>
                <section class="lunch-items">
                    <div v-for="item in menu.items" :key="item.id" class="lunch-item">
                        <div class="lunch-item-info">
                            <div class="lunch-item-name">{{ itemName(item) }}</div>
                            <div v-if="itemDesc(item)" class="lunch-item-desc">{{ itemDesc(item) }}</div>
                            <div class="lunch-item-price">{{ money(item.price_minor) }}</div>
                        </div>
                        <div class="lunch-stepper">
                            <button type="button" @click="dec(item.id)" :disabled="(cart[item.id] || 0) === 0">−</button>
                            <span>{{ cart[item.id] || 0 }}</span>
                            <button type="button" @click="inc(item)">+</button>
                        </div>
                    </div>
                </section>

                <section v-if="totalMinor > 0" class="lunch-total-row">
                    <span>{{ t('total') }}</span>
                    <strong>{{ money(totalMinor) }}</strong>
                </section>

                <form class="lunch-form" @submit.prevent="submit">
                    <div class="lunch-field">
                        <label>{{ t('your_name') }}</label>
                        <input v-model="form.customer_name" type="text" maxlength="120" required :placeholder="t('full_name_ph')" />
                    </div>
                    <div class="lunch-field">
                        <label>{{ t('phone') }}</label>
                        <input v-model="form.customer_phone" type="tel" maxlength="32" required :placeholder="t('phone_ph')" />
                    </div>
                    <div class="lunch-field">
                        <label>{{ t('email') }} <span class="lunch-opt">{{ t('optional') }}</span></label>
                        <input v-model="form.customer_email" type="email" maxlength="190" :placeholder="t('email_ph')" />
                    </div>
                    <div class="lunch-field">
                        <label>{{ t('notes') }} <span class="lunch-opt">{{ t('optional') }}</span></label>
                        <textarea v-model="form.customer_notes" maxlength="500" rows="2" :placeholder="t('notes_ph')"></textarea>
                    </div>

                    <input v-model="honeypot" type="text" tabindex="-1" autocomplete="off" class="lunch-hp" aria-hidden="true" />

                    <div v-if="methods.length > 1" class="lunch-pay">
                        <label class="lunch-pay-opt" :class="{ active: form.payment_method === 'online' }" v-if="menu.allow_online_payment">
                            <input type="radio" value="online" v-model="form.payment_method" />
                            <span>💳 {{ t('pay_online') }}</span>
                        </label>
                        <label class="lunch-pay-opt" :class="{ active: form.payment_method === 'pickup' }" v-if="menu.allow_pay_at_pickup">
                            <input type="radio" value="pickup" v-model="form.payment_method" />
                            <span>🤝 {{ t('pay_pickup') }}</span>
                        </label>
                    </div>

                    <p v-if="error" class="lunch-error" role="alert">{{ error }}</p>

                    <button class="lunch-submit" type="submit" :disabled="submitting || totalMinor === 0">
                        <template v-if="submitting">{{ t('placing') }}</template>
                        <template v-else-if="form.payment_method === 'online'">{{ t('pay_and_order', money(totalMinor)) }}</template>
                        <template v-else>{{ t('place_order') }} · {{ money(totalMinor) }}</template>
                    </button>
                    <p class="lunch-fine">{{ t('pickup_after') }}</p>
                </form>
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { usePublicLunchStore } from "@/stores/publicLunchStore";
import { useLunchLang } from "./lunchI18n";

const route = useRoute();
const router = useRouter();
const store = usePublicLunchStore();
const { lang, isAr, dir, locale, toggle, t, switchLabel } = useLunchLang();

const masjidId = String(route.params.masjidId);
const menu = computed(() => store.menu);
const loading = computed(() => store.loading);

// Header text is admin data, so it carries its own optional Arabic (title_ar /
// pickup_instructions_ar) shown when the visitor picks Arabic, English otherwise.
const menuTitle = computed(() => {
    const m = menu.value;
    if (!m) return "";
    return isAr.value && m.title_ar ? m.title_ar : m.title;
});
const menuPickup = computed(() => {
    const m = menu.value;
    if (!m) return "";
    return isAr.value && m.pickup_instructions_ar ? m.pickup_instructions_ar : (m.pickup_instructions || "");
});

const cart = reactive<Record<number, number>>({});
const honeypot = ref("");
const submitting = ref(false);
const error = ref<string | null>(null);

const form = reactive({
    customer_name: "",
    customer_phone: "",
    customer_email: "",
    customer_notes: "",
    payment_method: "pickup",
});

const methods = computed<string[]>(() => {
    const m: string[] = [];
    if (menu.value?.allow_online_payment) m.push("online");
    if (menu.value?.allow_pay_at_pickup) m.push("pickup");
    return m;
});

const totalMinor = computed(() => {
    if (!menu.value) return 0;
    return (menu.value.items || []).reduce(
        (sum: number, it: any) => sum + (cart[it.id] || 0) * Number(it.price_minor),
        0
    );
});

function money(minor: number): string {
    return "$" + (Number(minor) / 100).toFixed(2);
}

// Show the Arabic name/description when the visitor picked Arabic AND the admin
// entered one; otherwise fall back to the English the menu was created with.
function itemName(item: any): string {
    return isAr.value && item.name_ar ? item.name_ar : item.name;
}
function itemDesc(item: any): string {
    return isAr.value && item.description_ar ? item.description_ar : (item.description || "");
}

function formatDate(d: string): string {
    try {
        return new Date(d + "T00:00:00").toLocaleDateString(locale.value, {
            weekday: "long", month: "long", day: "numeric",
        });
    } catch {
        return d;
    }
}

function inc(item: any) {
    const cur = cart[item.id] || 0;
    if (item.max_quantity && cur >= item.max_quantity) return;
    cart[item.id] = cur + 1;
}

function dec(id: number) {
    const cur = cart[id] || 0;
    if (cur > 0) cart[id] = cur - 1;
}

async function submit() {
    error.value = null;
    const items = Object.keys(cart)
        .map((id) => ({ item_id: Number(id), quantity: cart[Number(id)] }))
        .filter((l) => l.quantity > 0);

    if (items.length === 0) {
        error.value = t("add_one");
        return;
    }

    submitting.value = true;
    const result = await store.placeOrder(masjidId, {
        menu_uuid: menu.value.uuid,
        items,
        customer_name: form.customer_name,
        customer_phone: form.customer_phone,
        customer_email: form.customer_email || null,
        customer_notes: form.customer_notes || null,
        payment_method: form.payment_method,
        website: honeypot.value,
    });
    submitting.value = false;

    if (!result.ok) {
        error.value = result.message || t("generic_err");
        return;
    }

    if (form.payment_method === "online" && result.checkoutUrl) {
        window.location.href = result.checkoutUrl;
        return;
    }

    const uuid = result.data?.uuid;
    if (uuid) {
        router.push(`/jummah-lunch/${masjidId}/order/${uuid}`);
    }
}

onMounted(() => store.fetchMenu(masjidId));
</script>

<style scoped>
.lunch-wrap {
    min-height: 100vh;
    background: linear-gradient(160deg, #0c3d2b 0%, #0a2c20 100%);
    padding: 24px 16px 60px;
    display: flex;
    justify-content: center;
    font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Arabic", sans-serif;
}
.lunch-card {
    width: 100%;
    max-width: 460px;
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
    overflow: hidden;
}
.lunch-head {
    position: relative;
    padding: 26px 22px 18px;
    background: linear-gradient(135deg, #0c3d2b, #14523a);
    color: #fff;
    text-align: center;
}
.lunch-lang {
    position: absolute;
    top: 14px;
    inset-inline-end: 14px;
    background: rgba(255, 255, 255, 0.16);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 999px;
    padding: 4px 12px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
.lunch-lang:hover { background: rgba(255, 255, 255, 0.26); }
.lunch-badge {
    display: inline-block;
    background: rgba(201, 162, 75, 0.2);
    color: #f0d9a0;
    font-size: 13px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 999px;
    margin-bottom: 10px;
}
.lunch-head h1 { font-size: 22px; margin: 0; font-weight: 700; }
.lunch-date { margin: 6px 0 0; opacity: 0.9; font-size: 14px; }
.lunch-pickup { margin: 10px 0 0; font-size: 13px; opacity: 0.85; }
.lunch-flyer { width: 100%; display: block; }
.lunch-items { padding: 8px 0; }
.lunch-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 22px; border-bottom: 1px solid #f0f0f0; gap: 12px;
}
.lunch-item-name { font-weight: 600; color: #1a1a1a; }
.lunch-item-desc { font-size: 13px; color: #777; margin-top: 2px; }
.lunch-item-price { font-size: 14px; color: #0c3d2b; font-weight: 600; margin-top: 4px; }
.lunch-stepper { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.lunch-stepper button {
    width: 32px; height: 32px; border-radius: 50%; border: 1px solid #0c3d2b;
    background: #fff; color: #0c3d2b; font-size: 18px; line-height: 1; cursor: pointer;
}
.lunch-stepper button:disabled { opacity: 0.3; cursor: default; }
.lunch-stepper span { min-width: 18px; text-align: center; font-weight: 600; }
.lunch-total-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 22px; background: #faf7ef; font-size: 17px;
}
.lunch-total-row strong { color: #0c3d2b; font-size: 20px; }
.lunch-form { padding: 18px 22px 26px; }
.lunch-field { margin-bottom: 14px; }
.lunch-field label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 5px; }
.lunch-opt { font-weight: 400; color: #999; }
.lunch-field input, .lunch-field textarea {
    width: 100%; padding: 11px 13px; border: 1px solid #ddd; border-radius: 10px;
    font-size: 15px; box-sizing: border-box;
}
.lunch-hp { position: absolute; inset-inline-start: -9999px; width: 1px; height: 1px; opacity: 0; }
.lunch-pay { display: flex; gap: 10px; margin: 8px 0 16px; }
.lunch-pay-opt {
    flex: 1; display: flex; align-items: center; gap: 8px; justify-content: center;
    padding: 12px; border: 1.5px solid #ddd; border-radius: 12px; cursor: pointer; font-size: 14px;
}
.lunch-pay-opt.active { border-color: #0c3d2b; background: #f2f8f5; font-weight: 600; }
.lunch-pay-opt input { accent-color: #0c3d2b; }
.lunch-submit {
    width: 100%; padding: 15px; border: none; border-radius: 12px;
    background: #c9a24b; color: #1a1a1a; font-size: 16px; font-weight: 700; cursor: pointer;
}
.lunch-submit:disabled { opacity: 0.5; cursor: default; }
.lunch-fine { text-align: center; font-size: 12px; color: #999; margin: 10px 0 0; }
.lunch-error { color: #c0392b; font-size: 14px; margin: 4px 0 12px; }
.lunch-empty { padding: 40px 22px; text-align: center; }
.lunch-pad { padding: 22px; }
.lunch-muted { color: #888; }
</style>
