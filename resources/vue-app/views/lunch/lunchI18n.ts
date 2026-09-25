import { computed, ref } from "vue";

/**
 * Tiny bilingual (English / Arabic) layer for the PUBLIC Jummah-lunch pages.
 *
 * The language is a module-level singleton, so the order page and the status
 * page share one choice and toggling on either sticks — persisted to
 * localStorage so it also survives the Stripe round-trip and a reload. Only the
 * UI CHROME is translated; the menu item's name/description come from the
 * database exactly as the masjid typed them.
 *
 * The same line divides the words below from the SERVER's own sentences. What
 * happened when an order WAS changed is answered by the API in English and shown
 * as it comes — the page must never tell a customer something the endpoint did
 * not. Why an order cannot be changed is different: it stands under the total on
 * every order page whose lunch has closed, so it is sent as a CODE as well
 * (`edit_notice_code`) and said here in the reader's own language, with the
 * server's sentence as the fallback for a code this bundle does not know.
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
        closes_at: "Orders close {x}",
        loading_menu: "Loading the menu…",
        none_open: "No lunch is open for ordering right now.",
        check_back: "Please check back closer to Jummah.",
        total: "Total",
        subtotal: "Food",
        give_title: "Add a little extra",
        give_sub: "Some people like to give more than the plate price. Anything you add goes to the masjid.",
        give_line: "Extra",
        give_none: "No extra",
        give_other_ph: "Other amount",
        give_cap: "The most you can add here is {x}.",
        cover_fees: "Add {x} to cover the card processing fee, so the masjid receives the full amount.",
        fee_line: "Processing fee",
        sms_title: "Text me when Jummah lunch ordering opens",
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
        // changing an order already placed
        edit_change: "Change my order",
        edit_cancel: "Cancel",
        edit_save: "Save changes",
        edit_saving: "Saving…",
        edit_new_total: "New total — confirmed when you save",
        edit_min_one: "Your order must keep at least one plate.",
        edit_saved: "Your order has been updated.",
        edit_failed: "We couldn't change your order.",
        one_fewer: "One fewer {x}",
        one_more: "One more {x}",
        pay_new_total: "Pay the new total",
        // why an order cannot be changed — keyed by the server's edit_notice_code.
        // Each one says CHANGE, because this sits under the total on a perfectly
        // valid order: "Orders for this menu are closed." on its own reads as
        // though the order itself is off.
        edit_why_closed: "This order can no longer be changed here — ordering for this lunch has closed. Your order still stands.",
        edit_why_paid: "Ordering has closed and this order is paid. Please contact the masjid to change it.",
        edit_why_refunded: "This order was refunded, so it cannot be changed here. Please contact the masjid.",
        edit_why_cancelled: "This order was cancelled, so it cannot be changed here. Please contact the masjid.",
        edit_why_item_gone: "Part of this order is no longer on the menu, so it cannot be changed here. Please contact the masjid.",
        // changing an order already PAID: more plates are paid for first
        topup_paid: "You've paid",
        topup_new_total: "New total",
        topup_to_pay: "To pay now",
        topup_pay_button: "Pay {x} and update my order",
        topup_redirecting: "Taking you to the payment page…",
        topup_confirming: "Thank you — your payment is being confirmed. Your order will change once the payment is confirmed.",
        topup_done: "Your payment is confirmed and your order has been updated.",
        topup_conflict: "We received your payment, but your order had changed in the meantime or ordering had closed, so it was not updated. The masjid will settle the difference with you.",
        topup_slow: "Your payment is still being confirmed. Please reload this page in a minute.",
        topup_cancelled: "Payment cancelled — your order was not changed.",
        // a paid order's refusals — keyed by the server's data.code
        topup_reduce: "To remove plates from a paid order, please contact the masjid.",
        topup_too_close: "It's too close to the ordering cutoff to add plates to a paid order online. Please contact the masjid.",
        topup_unavailable: "Adding to a paid order online is not available for this lunch. Please contact the masjid.",
        topup_confirming_wait: "Your last payment for this order is still being confirmed, so nothing was changed. Please wait a moment and reload the page.",
        order_moved: "This order was changed while you were editing it, so nothing was changed. Please reload the page and try again.",
        just_paid: "This order has just been paid, so nothing was changed. Please reload the page and try again.",
        topup_not_opened: "The payment page could not be opened, so nothing was changed. Please try again in a moment.",
        topup_too_small: "The difference is too small to pay online. Please contact the masjid to make this change.",
        paid_balance_open: "This order has a balance the masjid still has to settle with you, so it can't be changed online. Please contact the masjid.",
        paid_prices_moved: "The menu's prices have changed since this order was paid, so it can't be changed online. Please contact the masjid.",
        topup_unconfirmed: "We could not confirm this payment, so your order was not changed. Please contact the masjid.",
        topup_expired: "The payment page closed before a payment was made, so your order was not changed.",
        topup_waiting: "A change to this order is waiting for a payment of {x}. Nothing changes until that payment goes through.",
    },
    ar: {
        badge: "غداء الجمعة",
        closes_at: "يُغلق استقبال الطلبات {x}",
        loading_menu: "جارٍ تحميل القائمة…",
        none_open: "لا يوجد غداء متاح للطلب حالياً.",
        check_back: "يرجى المراجعة قرب موعد الجمعة.",
        total: "الإجمالي",
        subtotal: "الطعام",
        give_title: "أضف مبلغاً إضافياً",
        give_sub: "يحبّ بعض الناس دفع أكثر من ثمن الطبق. كل مبلغ تضيفه يذهب إلى المسجد.",
        give_line: "مبلغ إضافي",
        give_none: "بدون إضافة",
        give_other_ph: "مبلغ آخر",
        give_cap: "أقصى مبلغ يمكن إضافته هنا هو {x}.",
        cover_fees: "أضف {x} لتغطية رسوم معالجة البطاقة، ليستلم المسجد المبلغ كاملاً.",
        fee_line: "رسوم المعالجة",
        sms_title: "أرسل لي رسالة نصية عند فتح باب طلب غداء الجمعة",
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
        // changing an order already placed
        edit_change: "تعديل طلبي",
        edit_cancel: "إلغاء",
        edit_save: "حفظ التعديل",
        edit_saving: "جارٍ الحفظ…",
        edit_new_total: "الإجمالي الجديد — يُعتمد عند الحفظ",
        edit_min_one: "يجب أن يبقى في الطلب طبق واحد على الأقل.",
        edit_saved: "تم تحديث طلبك.",
        edit_failed: "تعذّر تعديل طلبك.",
        one_fewer: "إنقاص {x}",
        one_more: "زيادة {x}",
        pay_new_total: "ادفع الإجمالي الجديد",
        // سبب تعذّر تعديل الطلب — حسب edit_notice_code القادم من الخادم
        edit_why_closed: "لا يمكن تعديل هذا الطلب هنا — أُغلق استقبال الطلبات لهذا الغداء. طلبك ما زال قائمًا.",
        edit_why_paid: "أُغلق باب الطلبات وهذا الطلب مدفوع. يُرجى التواصل مع المسجد لتعديله.",
        edit_why_refunded: "تمت إعادة مبلغ هذا الطلب، فلا يمكن تعديله هنا. يُرجى التواصل مع المسجد.",
        edit_why_cancelled: "تم إلغاء هذا الطلب، فلا يمكن تعديله هنا. يُرجى التواصل مع المسجد.",
        edit_why_item_gone: "أحد أصناف هذا الطلب لم يعد على القائمة، فلا يمكن تعديله هنا. يُرجى التواصل مع المسجد.",
        // تعديل طلب مدفوع: تُدفع الأطباق الإضافية أولًا
        topup_paid: "المبلغ المدفوع",
        topup_new_total: "الإجمالي الجديد",
        topup_to_pay: "المطلوب دفعه الآن",
        topup_pay_button: "ادفع {x} وحدّث طلبي",
        topup_redirecting: "جارٍ نقلك إلى صفحة الدفع…",
        topup_confirming: "شكرًا لك — جارٍ تأكيد دفعتك. سيُحدَّث طلبك بعد تأكيد الدفع.",
        topup_done: "تم تأكيد دفعتك وتحديث طلبك.",
        topup_conflict: "استلمنا دفعتك، لكن طلبك تغيّر في هذه الأثناء أو أُغلق باب الطلبات، فلم يُحدَّث. سيُسوّي المسجد الفرق معك.",
        topup_slow: "ما زال تأكيد دفعتك جاريًا. يُرجى إعادة تحميل هذه الصفحة بعد دقيقة.",
        topup_cancelled: "أُلغيت عملية الدفع — لم يُغيَّر طلبك.",
        // رفض تعديل طلب مدفوع — حسب data.code القادم من الخادم
        topup_reduce: "لإزالة أطباق من طلب مدفوع، يُرجى التواصل مع المسجد.",
        topup_too_close: "اقترب موعد إغلاق الطلبات كثيرًا، فلا يمكن إضافة أطباق إلى طلب مدفوع عبر الإنترنت. يُرجى التواصل مع المسجد.",
        topup_unavailable: "لا تتوفر إضافة أطباق إلى طلب مدفوع عبر الإنترنت لهذا الغداء. يُرجى التواصل مع المسجد.",
        topup_confirming_wait: "ما زال تأكيد دفعتك الأخيرة لهذا الطلب جاريًا، فلم يُغيَّر شيء. يُرجى الانتظار قليلًا ثم إعادة تحميل الصفحة.",
        order_moved: "تغيّر هذا الطلب أثناء تعديلك له، فلم يُغيَّر شيء. يُرجى إعادة تحميل الصفحة والمحاولة مرة أخرى.",
        just_paid: "دُفع هذا الطلب للتو، فلم يُغيَّر شيء. يُرجى إعادة تحميل الصفحة والمحاولة مرة أخرى.",
        topup_not_opened: "تعذّر فتح صفحة الدفع، فلم يُغيَّر شيء. يُرجى المحاولة مرة أخرى بعد قليل.",
        topup_too_small: "الفرق أصغر من أن يُدفع عبر الإنترنت. يُرجى التواصل مع المسجد لإجراء هذا التعديل.",
        paid_balance_open: "على هذا الطلب رصيد لم يُسوِّه المسجد معك بعد، فلا يمكن تعديله عبر الإنترنت. يُرجى التواصل مع المسجد.",
        paid_prices_moved: "تغيّرت أسعار القائمة منذ دفع هذا الطلب، فلا يمكن تعديله عبر الإنترنت. يُرجى التواصل مع المسجد.",
        topup_unconfirmed: "تعذّر علينا تأكيد هذه الدفعة، فلم يُغيَّر طلبك. يُرجى التواصل مع المسجد.",
        topup_expired: "أُغلقت صفحة الدفع قبل إتمام الدفع، فلم يُغيَّر طلبك.",
        topup_waiting: "هناك تعديل على هذا الطلب بانتظار دفع {x}. لن يتغيّر شيء حتى تتم هذه الدفعة.",
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
