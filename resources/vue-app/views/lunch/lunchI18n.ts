import { computed, ref } from "vue";

/**
 * Tiny bilingual (English / Arabic) layer for the PUBLIC Jummah-lunch pages.
 *
 * The language is a module-level singleton, so the order page and the status
 * page share one choice and toggling on either sticks — persisted to
 * localStorage so it also survives the Stripe round-trip and a reload. Only the
 * UI CHROME is translated; the menu item's name/description come from the
 * database exactly as the masjid typed them.
 */
export type LunchLang = "en" | "ar";

const LANG_KEY = "MANARA_LUNCH_LANG";

function initial(): LunchLang {
    try {
        return localStorage.getItem(LANG_KEY) === "ar" ? "ar" : "en";
    } catch {
        return "en";
    }
}

const lang = ref<LunchLang>(initial());

const STRINGS: Record<LunchLang, Record<string, string>> = {
    en: {
        badge: "Jummah Lunch",
        loading_menu: "Loading the menu…",
        none_open: "No lunch is open for ordering right now.",
        check_back: "Please check back closer to Jummah.",
        total: "Total",
        your_name: "Your name",
        full_name_ph: "Full name",
        phone: "Phone",
        phone_ph: "Contact number",
        email: "Email",
        optional: "(optional)",
        email_ph: "For your receipt",
        notes: "Notes",
        notes_ph: "Allergies, special requests…",
        pay_online: "Pay online now",
        pay_pickup: "Pay at pickup",
        placing: "Placing your order…",
        pay_and_order: "Pay {x} & order",
        place_order: "Place order",
        pickup_after: "Pickup is after Jummah prayer.",
        add_one: "Please add at least one item.",
        generic_err: "We couldn't place your order.",
        // status page
        loading_order: "Loading your order…",
        not_found_title: "Order not found",
        not_found_body: "We couldn't find that order.",
        all_set: "You're all set!",
        pay_cancelled: "Payment cancelled",
        order_received: "Order received",
        order_num: "Order #",
        cancel_note: "Your payment was cancelled, so this order is not paid. You can pay at pickup, or place a new order.",
        payment: "Payment",
        method: "Method",
        paid: "Paid",
        not_paid: "Not paid",
        refunded: "Refunded",
        online: "Online",
        at_pickup: "At pickup",
        pickup_show: "Pick up after Jummah prayer. Show order",
    },
    ar: {
        badge: "غداء الجمعة",
        loading_menu: "جارٍ تحميل القائمة…",
        none_open: "لا يوجد غداء متاح للطلب حالياً.",
        check_back: "يرجى المراجعة قرب موعد الجمعة.",
        total: "الإجمالي",
        your_name: "الاسم",
        full_name_ph: "الاسم الكامل",
        phone: "رقم الهاتف",
        phone_ph: "رقم للتواصل",
        email: "البريد الإلكتروني",
        optional: "(اختياري)",
        email_ph: "لإرسال الإيصال",
        notes: "ملاحظات",
        notes_ph: "حساسية، طلبات خاصة…",
        pay_online: "ادفع الآن عبر الإنترنت",
        pay_pickup: "ادفع عند الاستلام",
        placing: "جارٍ إرسال طلبك…",
        pay_and_order: "ادفع {x} واطلب",
        place_order: "إتمام الطلب",
        pickup_after: "الاستلام بعد صلاة الجمعة.",
        add_one: "يرجى إضافة عنصر واحد على الأقل.",
        generic_err: "تعذّر إرسال طلبك.",
        // status page
        loading_order: "جارٍ تحميل طلبك…",
        not_found_title: "الطلب غير موجود",
        not_found_body: "تعذّر العثور على هذا الطلب.",
        all_set: "تم إتمام طلبك!",
        pay_cancelled: "أُلغيت عملية الدفع",
        order_received: "تم استلام الطلب",
        order_num: "طلب رقم ",
        cancel_note: "أُلغيت عملية الدفع، لذا هذا الطلب غير مدفوع. يمكنك الدفع عند الاستلام أو إجراء طلب جديد.",
        payment: "الدفع",
        method: "طريقة الدفع",
        paid: "مدفوع",
        not_paid: "غير مدفوع",
        refunded: "مُسترجَع",
        online: "عبر الإنترنت",
        at_pickup: "عند الاستلام",
        pickup_show: "الاستلام بعد صلاة الجمعة. أظهِر رقم الطلب",
    },
};

export function useLunchLang() {
    const isAr = computed(() => lang.value === "ar");
    const dir = computed(() => (isAr.value ? "rtl" : "ltr"));
    const locale = computed(() => (isAr.value ? "ar" : "en"));

    function toggle(): void {
        lang.value = isAr.value ? "en" : "ar";
        try {
            localStorage.setItem(LANG_KEY, lang.value);
        } catch {
            /* private mode / storage disabled — the in-memory ref still works */
        }
    }

    /** Translate a key for the current language, with a `{x}` interpolation slot. */
    function t(key: string, x?: string): string {
        const s = STRINGS[lang.value][key] ?? STRINGS.en[key] ?? key;
        return x !== undefined ? s.replace("{x}", x) : s;
    }

    /** The label shown ON the toggle button (the language it switches TO). */
    const switchLabel = computed(() => (isAr.value ? "English" : "العربية"));

    return { lang, isAr, dir, locale, toggle, t, switchLabel };
}
