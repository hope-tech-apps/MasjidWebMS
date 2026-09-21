import { computed, ref } from "vue";
import { UR } from "@/views/family/locales/ur";
import { PS } from "@/views/family/locales/ps";
import { FA_AF } from "@/views/family/locales/fa-AF";
import { ES } from "@/views/family/locales/es";

/**
 * The language layer for the PARENT portal: English and Arabic, and since
 * 2026-09-21 Urdu, Pashto, Dari and Spanish.
 *
 * THE FOUR NEWER LANGUAGES ARE MACHINE-DRAFTED. Their tables live one per file
 * under ./locales/ precisely so that each can carry that warning at its head
 * and be handed to a fluent reader on its own; until one has been reviewed its
 * `reviewed` flag in FAMILY_LANGS below stays false. English and Arabic stay in
 * this file as they were.
 *
 * Modelled on views/lunch/lunchI18n.ts, and for the same reason: the language is
 * a module-level singleton, so the home screen, a class, the sign-in card and
 * child mode all share one choice and toggling on any of them sticks —
 * persisted to localStorage so it survives a reload and the emailed-link round
 * trip a parent usually arrives by.
 *
 * ONLY THE CHROME IS HERE. A teacher's post, a message body, a report-card
 * comment, a criterion, a child's name and every other staff-authored or
 * family-authored string comes from the server and is rendered exactly as it was
 * written. Translating those would put words in a teacher's mouth, and a parent
 * reading "the school said X" has to be reading what the school actually said.
 *
 * That rule has ONE door in it, added 2026-09-12 and deliberately not a hole:
 * useContentTranslation.ts will fetch an Arabic rendering of staff-written text
 * when a parent explicitly taps for it, keeps the original one tap away, and
 * says on the page that what is being read is an automatic translation. Nothing
 * in this file does that on its own, and switching the chrome to Arabic still
 * changes not one word the school wrote.
 *
 * The Arabic is Modern Standard Arabic written to be read by a parent, not a
 * gloss of the English: where a literal rendering would be stiff the sentence is
 * rewritten, and the English column is left exactly as it appears on screen
 * today so that turning Arabic on is the only thing this layer changes.
 */
export type FamilyLang = "en" | "ar" | "ur" | "ps" | "fa-AF" | "es";

/**
 * Everything the portal needs to know about one language, in the order the
 * picker lists them.
 *
 * `dir` is the reason this table exists rather than a list of codes: Urdu,
 * Pashto and Dari are right-to-left like Arabic, and every screen in this realm
 * lays itself out from `dir` (see the logical-property rules at the foot of
 * FamilyClass.vue and FamilyLayout.vue), so a wrong flag here is a mirrored
 * page. App\Support\PortalLanguage holds the server's copy, and
 * tests/Feature/FamilyLanguagesMirrorTest.php keeps the two in step — keep one
 * language per line so it can read them.
 *
 * `intl` is the locale handed to Intl for dates and weekday names. Every
 * right-to-left one pins Western digits (`nu-latn`) for the reason the Arabic
 * one always has — the counts on the same screen arrive from the server as 25,
 * and one screen in two numeral systems is harder to read than either. Pashto
 * and Dari also pin the GREGORIAN calendar (`ca-gregory`): CLDR's default for
 * both is the Solar Hijri calendar, which would print today as "30 Sunbula
 * 1405" beside a school calendar, a report date and a teacher's "see you on the
 * 25th" that are all Gregorian.
 *
 * Dari is `fa-AF`, not `prs` — the browser itself canonicalises `prs` to
 * `fa-AF` (`Intl.getCanonicalLocales("prs")`), so `fa-AF` is the one spelling
 * that survives a round trip. See App\Support\PortalLanguage.
 */
export type FamilyLangInfo = {
    code: FamilyLang;
    /** The language's name in itself — what a parent who reads only it can find. */
    label: string;
    dir: "ltr" | "rtl";
    intl: string;
    /** False until a fluent speaker has read the table. */
    reviewed: boolean;
};

export const FAMILY_LANGS: FamilyLangInfo[] = [
    { code: "en", label: "English", dir: "ltr", intl: "en", reviewed: true },
    { code: "ar", label: "العربية", dir: "rtl", intl: "ar-u-nu-latn", reviewed: true },
    { code: "ur", label: "اردو", dir: "rtl", intl: "ur-u-ca-gregory-nu-latn", reviewed: false },
    { code: "ps", label: "پښتو", dir: "rtl", intl: "ps-u-ca-gregory-nu-latn", reviewed: false },
    { code: "fa-AF", label: "دری", dir: "rtl", intl: "fa-AF-u-ca-gregory-nu-latn", reviewed: false },
    { code: "es", label: "Español", dir: "ltr", intl: "es", reviewed: false },
];

const INFO: Record<FamilyLang, FamilyLangInfo> = Object.fromEntries(
    FAMILY_LANGS.map((info) => [info.code, info]),
) as Record<FamilyLang, FamilyLangInfo>;

/**
 * A tag from storage or from the browser, as one of ours — or null.
 *
 * Browsers report `ur-PK`, `es-419`, `ps-AF`; Windows reports Dari as `prs-AF`;
 * an Iranian-Persian reader reports `fa` or `fa-IR`, and Dari is the closest
 * thing on offer for them. Matching on the primary subtag covers all of it.
 */
export function normaliseFamilyLang(tag: unknown): FamilyLang | null {
    if (typeof tag !== "string") return null;

    const primary = tag.trim().toLowerCase().split(/[-_]/)[0];

    if (primary === "prs" || primary === "fa") return "fa-AF";

    return (["en", "ar", "ur", "ps", "es"] as const).find((code) => code === primary) ?? null;
}

/**
 * One error or confirmation slot in a view.
 *
 * Messages are held as a KEY rather than as an already-translated sentence,
 * because a sentence translated at the moment it was set stays in the language
 * it was set in — and the moment a parent is most likely to reach for the
 * language toggle is the moment they cannot read what just went wrong. `text`
 * is the deliberate exception: it carries a sentence the API wrote (a
 * validator's explanation, a rate-limit refusal), which this portal shows as
 * the server worded it rather than guessing which of them came back.
 */
export type FamilyMessage = { key?: string; text?: string };

const LANG_KEY = "MANARA_FAMILY_LANG";

function initial(): FamilyLang {
    try {
        return normaliseFamilyLang(localStorage.getItem(LANG_KEY)) ?? "en";
    } catch {
        return "en";
    }
}

const lang = ref<FamilyLang>(initial());

/**
 * The language to offer a parent whose portal is still in ENGLISH.
 *
 * The translate button and its notices are labelled in two languages at once
 * (see tBoth) for the parent who has not found the picker. With one other
 * language that second label was always Arabic; with five, it is the first one
 * the parent's own browser says it reads, and Arabic when it names none of
 * ours — which is exactly what this portal did before, for every parent whose
 * phone is not set to Urdu, Pashto, Dari or Spanish. Read once: a browser's
 * language list does not change under an open page.
 */
function suggestedOther(): Exclude<FamilyLang, "en"> {
    try {
        const tags = (navigator.languages?.length ? navigator.languages : [navigator.language]) ?? [];

        for (const tag of tags) {
            const code = normaliseFamilyLang(tag);

            if (code && code !== "en") return code;
        }
    } catch {
        /* no navigator (SSR, a locked-down webview) — fall through */
    }

    return "ar";
}

const suggested = suggestedOther();

const STRINGS: Record<FamilyLang, Record<string, string>> = {
    ur: UR,
    ps: PS,
    "fa-AF": FA_AF,
    es: ES,
    en: {
        // ------------------------------------------------------------ shared
        switch_lang_title: "Switch language",
        student: "Student",
        cancel: "Cancel",
        send: "Send",
        sending: "Sending…",
        nothing_recorded: "Nothing recorded yet.",

        // ------------------------------------------------------- FamilyHome
        home_greeting: "Assalamu alaikum",
        home_greeting_named: "Assalamu alaikum, {x}",
        home_sub: "Your children's classes at this school.",
        home_empty_title: "Nothing here yet",
        home_empty_body: "The school has not added you to a class yet. If you think that is wrong, contact the office — they can see your record.",
        home_no_consent: "You have not given consent for class updates, so the class story is hidden. The school office can record your consent.",
        home_load_error: "We could not load your classes just now. Please try again.",
        signin_panel_title: "Signing in",
        signin_panel_has_pw: "You have a password set. You can still ask for an emailed code any time.",
        signin_panel_no_pw: "You sign in with a six-digit code we email you. If you'd rather use a password, you can set one here — codes will keep working either way.",
        pw_new: "New password",
        pw_again: "Type it again",
        pw_hint: "At least 12 characters. A short phrase you'll remember works well.",
        pw_saving: "Saving…",
        pw_change: "Change password",
        pw_set: "Set password",
        pw_change_mine: "Change my password",
        pw_set_a: "Set a password",
        pw_remove: "Remove it",
        pw_mismatch: "The two passwords did not match.",
        pw_saved: "Your password is set. You can sign in with it from now on.",
        pw_save_failed: "That password could not be saved.",
        pw_removed: "Your password has been removed. Sign in with an emailed code from now on.",
        pw_remove_failed: "That could not be removed.",

        // ----------------------------------------------------- FamilySignIn
        signin_title: "Parent sign in",
        signin_sub: "We email you a six-digit code. If you've set a password, you can use that instead.",
        signin_email_label: "Your email address",
        signin_email_hint: "Use the address the school has on file for you.",
        signin_password_label: "Your password",
        signin_submit: "Sign in",
        signin_code_instead: "Email me a code instead",
        signin_email_code: "Email me a code",
        signin_have_password: "I have a password",
        // Split in two around the address on purpose. The address is bold and
        // has to stay a left-to-right run of its own inside an Arabic sentence,
        // so it cannot ride through a single {x} slot; both halves are written
        // to read as one sentence with the address between them in either
        // language.
        signin_sent_before: "If",
        signin_sent_after: "is on file, a six-digit code is on its way. It expires shortly, and can only be used once.",
        signin_code_label: "Six-digit code",
        signin_other_address: "Use a different address",
        signin_code_failed: "That code did not work. It may have expired or already been used — ask for a new one.",
        signin_password_failed: "That email and password did not match. You can ask for a code instead.",

        // ------------------------------------------------------ StudentMode
        student_back: "Back to the family portal",
        student_greeting: "Assalamu alaikum, {x}!",
        student_sub: "Pick how you want to look.",
        student_who: "Who are you?",
        student_skin: "Your skin",
        student_hijab: "Your hijab",
        student_kufi: "Your kufi",
        student_saved: "Saved!",
        student_this_is_me: "This is me",
        student_hand_back: "Give the phone back",
        student_session_over: "This student session has ended. Ask a grown-up to start it again.",
        student_error: "Something went wrong. Ask a grown-up for help.",
        student_save_failed: "That could not be saved. Ask a grown-up for help.",

        // ------------------------------------------------- FamilyAttachment
        attachment: "Attachment",

        // ------------------------------------------------------ FamilyLayout
        // The bar above every screen in this realm. It is the school's name
        // once the directory answers, so only the fallback and the one control
        // in it are ours to word — and "Sign out" is the control a parent who
        // cannot read the page most needs to find.
        layout_portal: "Family Portal",
        layout_sign_out: "Sign out",
        layout_calendar: "School calendar",

        // --------------------------------------------------- FamilyCalendar
        // Only the chrome. A year's name and the reason there is no school on a
        // day are the school's own words and are shown as written.
        cal_title: "School calendar",
        cal_sub: "The days school meets this year, and the days there's no school.",
        cal_back: "Back to my classes",
        cal_loading: "Loading the school calendar…",
        cal_empty_title: "The school calendar hasn't been published yet",
        cal_empty_body: "When the school adds its school year, the days will show up here.",
        cal_load_error: "We could not load the school calendar just now. Please try again.",
        cal_retry: "Try again",
        cal_upcoming: "Coming up",
        cal_no_upcoming: "There are no more school days this year.",
        cal_school_day: "School day",
        cal_no_school: "No school",
        cal_today: "Today",
        cal_year: "School year",
        // {x} is the weekday's name from Intl, already in the portal's language.
        cal_meets_every: "Classes meet every {x}",
        cal_no_days: "No school days are listed for this year.",

        // ------------------------------- translating what the school wrote
        // These are the only strings in this file rendered in BOTH languages at
        // once (see tBoth): the parent they are addressed to is by definition
        // somebody who could not read the page, so putting the control and its
        // outcome in one language risks landing in the wrong one.
        tr_translate: "Translate",
        tr_show_original: "Show original",
        tr_busy: "Translation is busy just now. Please try again in a minute.",
        tr_unavailable: "Translation is not available right now.",
        tr_failed: "That could not be translated just now.",
        tr_incomplete: "Some of this could not be translated and is still in the language it was written in.",
        tr_machine: "Translated automatically. Tap “Show original” for the school's own words.",

        // ------------------------------------------------------- FamilyClass
        class_all: "All classes",
        class_load_error: "We could not load this class just now. Please try again.",
        tab_story: "Class story",
        tab_messages: "Messages",
        tab_children: "My children",
        tab_reports: "Report cards",
        tab_handouts: "Handouts",
        handouts_empty: "Nothing shared yet. Anything your teacher sends home will appear here.",
        handouts_error: "These could not be loaded just now.",
        handout_download_failed: "That file could not be downloaded.",
        kb: "KB",
        story_no_consent: "The class story is hidden because your consent for class updates is not on file. The school office can record it for you.",
        story_empty: "Nothing posted yet.",
        the_school: "The school",
        media_withheld: "Photos in this post are hidden because photo consent is not on file.",
        message_media_withheld: "Photos in this message are hidden because photo consent is not on file.",
        msg_teacher: "Message the teacher",
        compose_about: "About",
        compose_subject: "Subject",
        compose_subject_ph: "What is this about?",
        compose_body_ph: "Your message to the teacher…",
        compose_private: "Only your child's teacher will see this.",
        compose_rate_limited: "You have started several conversations already. Please continue one of them.",
        compose_failed: "That message could not be sent.",
        threads_empty: "No messages yet.",
        thread_untitled: "Message",
        thread_new: "New",
        thread_about: "About {x}",
        // English has two number forms and Arabic has four, so the count is
        // keyed by grammatical category rather than by a bare singular/plural
        // pair. English fills all four with the two forms it has, which keeps
        // the English on screen byte-for-byte what it was.
        msgs_one: "{x} message",
        msgs_two: "{x} messages",
        msgs_few: "{x} messages",
        msgs_many: "{x} messages",
        msg_you: "You",
        thread_closed: "The school has closed this conversation.",
        reply_ph: "Write a reply…",
        reply_failed: "Your reply could not be sent. Please try again.",
        thread_open_failed: "That conversation could not be opened.",
        choose_avatar: "Choose an avatar",
        handover_starting: "Starting…",
        handover_let: "Let {x} choose",
        handover_failed: "That could not be started. Please try again.",
        section_behaviour: "Behaviour",
        // "Letters", not "Arabic letters": the section now heads both tracks and
        // each names itself underneath. These names are CHROME — this portal's
        // own words for its own sections — and belong here rather than in the
        // content-translation path, which is only ever for what the school
        // wrote. A machine rendering of our own heading would be a translation
        // of a translation.
        section_letters: "Letters",
        alphabet_arabic: "Arabic letters",
        alphabet_english: "English letters",
        // The qāʿidah's five stages. The server sends the English label with the
        // payload; these exist so an Arabic page does not print "The Letters"
        // under a heading that already reads الحروف العربية. Keyed by the stage
        // id, and `stageLabel()` falls back to whatever the server sent when a
        // stage arrives that this table has never heard of.
        stage_letters: "The Letters",
        stage_short_vowels: "Short Vowels",
        stage_sukun_shadda: "Sukun & Shadda",
        stage_tanween: "Tanween",
        stage_madd: "Long Vowels",
        section_quran: "Qur'an",
        // Headings over what a teacher WROTE about the child's Arabic. The
        // headings are ours; the notes under them are the teacher's words and
        // go through the content-translation door like every other note.
        arabic_letter_notes: "Teacher's notes on letters",
        arabic_daily_notes: "Teacher's notes on Arabic lessons",
        // Not "nothing recorded": a request that failed says so, because a
        // parent told there are no notes when there are would not ask.
        notes_unavailable: "These notes could not be loaded right now.",
        count_of: "of",
        letter_not_started: "not started",
        letter_learning: "learning",
        letter_mastered: "mastered",
        surah: "Surah",
        reports_all: "All reports",
        threads_all: "All messages",
        report_preparing: "Preparing…",
        report_download: "Download PDF",
        report_sent: "Sent {x}",
        level_key_summary: "What do 4, 3, 2 and 1 mean?",
        attendance_present: "Present {x}",
        attendance_late: "(includes {x} late)",
        attendance_absent: "Absent {x}",
        learning_behaviours: "Learning behaviours",
        teacher_comment: "Comment from the teacher",
        not_assessed: "Not assessed",
        reports_empty: "No reports have been sent home yet. When one is, it will appear here.",
        reports_partial_error: "Some reports could not be loaded just now.",
        report_open_failed: "That report could not be opened.",
        report_download_failed: "That report could not be downloaded just now.",
        // ------------------------------------------------ marks (the gradebook)
        // "Marks", and deliberately not "Grades". In this school's own portal a
        // GRADE is a year level — `grade_label` is printed as a badge on the
        // report card, on the tab next to this one — so "Grades" would be two
        // different things on one screen for the parent least able to tell them
        // apart. "Marks" is also what the teachers here call them.
        tab_grades: "Marks",
        marks_empty: "No marks yet. When your teacher enters one you will see it here.",
        marks_error: "Some marks could not be loaded just now.",
        marks_section_points: "Points work",
        marks_section_levels: "Performance levels",
        // The count of pieces of work behind a points total, so "86.5 of 100"
        // is not read as a percentage of the term. `count_of` carries the "of".
        marks_pieces_one: "across {x} piece of work",
        marks_pieces_two: "across {x} pieces of work",
        marks_pieces_few: "across {x} pieces of work",
        marks_pieces_many: "across {x} pieces of work",
        // The four performance levels and what they mean. They are a platform
        // constant (App\\Support\\PerformanceLevel), so the payload carries them
        // in English whatever language the portal is set to — the same seam the
        // qāʿidah's stage labels have. Keyed by the level number; a level this
        // table has never heard of falls back to the server's own words.
        level_4: "Exceeds Expectations",
        level_3: "Meets Expectations",
        level_2: "Approaching Expectations",
        level_1: "Needs Support",
        level_short_4: "Exceeds",
        level_short_3: "Meets",
        level_short_2: "Approaching",
        level_short_1: "Needs Support",
        level_desc_4: "Consistently demonstrates mastery; applies skills independently; shows accuracy, depth, and confidence.",
        level_desc_3: "Demonstrates grade-level proficiency; completes tasks with minimal support; shows solid understanding.",
        level_desc_2: "Partial understanding; needs support or reminders; inconsistent performance.",
        level_desc_1: "Limited understanding; requires significant guidance; skills not yet developed.",
        // The NUMBER always; `mean_label` is printed beside it and never in
        // place of it (App\Support\PerformanceLevel::labelForMean).
        marks_levels_mean: "Average level {x}",
        // A status is a sentence, never a number. An unhanded-in piece of work
        // is not a zero on screen even though it is a zero in the average.
        mark_missing: "Not handed in",
        mark_excused: "Excused",
        marks_truncated: "Showing the most recent {x}.",
    },
    ar: {
        // ------------------------------------------------------------ shared
        switch_lang_title: "تغيير اللغة",
        student: "طالب",
        cancel: "إلغاء",
        send: "إرسال",
        sending: "جارٍ الإرسال…",
        nothing_recorded: "لا يوجد شيء مسجَّل بعد.",

        // ------------------------------------------------------- FamilyHome
        home_greeting: "السلام عليكم",
        home_greeting_named: "السلام عليكم، {x}",
        home_sub: "صفوف أبنائك في هذه المدرسة.",
        home_empty_title: "لا يوجد شيء هنا بعد",
        home_empty_body: "لم تُضِفك المدرسة إلى أي صف بعد. وإن كنت ترى أن هذا خطأ، تواصل مع مكتب المدرسة — يمكنهم الاطلاع على سجلّك.",
        home_no_consent: "لم تمنح موافقتك على استقبال أخبار الصف، لذا فإن يوميات الصف مخفية. ويمكن لمكتب المدرسة تسجيل موافقتك.",
        home_load_error: "تعذّر تحميل صفوف أبنائك الآن. يرجى المحاولة مرة أخرى.",
        signin_panel_title: "تسجيل الدخول",
        signin_panel_has_pw: "لديك كلمة مرور محفوظة. ويمكنك مع ذلك طلب رمز على بريدك الإلكتروني في أي وقت.",
        signin_panel_no_pw: "تدخل إلى البوابة برمز من ستة أرقام نرسله إلى بريدك الإلكتروني. وإن كنت تفضّل كلمة مرور، يمكنك تعيين واحدة هنا — وستظل الرموز تعمل في الحالتين.",
        pw_new: "كلمة المرور الجديدة",
        pw_again: "أعد كتابتها",
        pw_hint: "اثنا عشر حرفاً على الأقل. وعبارة قصيرة تتذكّرها خيار موفّق.",
        pw_saving: "جارٍ الحفظ…",
        pw_change: "تغيير كلمة المرور",
        pw_set: "تعيين كلمة المرور",
        pw_change_mine: "تغيير كلمة مروري",
        pw_set_a: "تعيين كلمة مرور",
        pw_remove: "إزالتها",
        pw_mismatch: "كلمتا المرور غير متطابقتين.",
        pw_saved: "تم تعيين كلمة المرور. يمكنك تسجيل الدخول بها من الآن فصاعداً.",
        pw_save_failed: "تعذّر حفظ كلمة المرور هذه.",
        pw_removed: "تمت إزالة كلمة المرور. سجّل الدخول برمز يصلك على بريدك الإلكتروني من الآن فصاعداً.",
        pw_remove_failed: "تعذّرت إزالتها.",

        // ----------------------------------------------------- FamilySignIn
        signin_title: "دخول أولياء الأمور",
        signin_sub: "نرسل إليك رمزاً من ستة أرقام على بريدك الإلكتروني. وإن كنت قد عيّنت كلمة مرور، يمكنك استخدامها بدلاً منه.",
        signin_email_label: "بريدك الإلكتروني",
        signin_email_hint: "استخدم العنوان المسجَّل لدى المدرسة.",
        signin_password_label: "كلمة المرور",
        signin_submit: "تسجيل الدخول",
        signin_code_instead: "أرسلوا لي رمزاً بدلاً من ذلك",
        signin_email_code: "أرسلوا لي رمزاً على بريدي",
        signin_have_password: "لديّ كلمة مرور",
        signin_sent_before: "إذا كان",
        signin_sent_after: "مسجَّلاً لدينا، فإن رمزاً من ستة أرقام في طريقه إليك. تنتهي صلاحيته بعد قليل، ولا يمكن استخدامه إلا مرة واحدة.",
        signin_code_label: "الرمز المكوَّن من ستة أرقام",
        signin_other_address: "استخدام عنوان آخر",
        signin_code_failed: "لم يعمل هذا الرمز. ربما انتهت صلاحيته أو استُخدم من قبل — اطلب رمزاً جديداً.",
        signin_password_failed: "البريد الإلكتروني وكلمة المرور غير متطابقين. ويمكنك طلب رمز بدلاً من ذلك.",

        // ------------------------------------------------------ StudentMode
        student_back: "العودة إلى بوابة الأسرة",
        student_greeting: "السلام عليكم يا {x}!",
        student_sub: "اختر شكلك.",
        student_who: "من أنت؟",
        student_skin: "لون بشرتك",
        student_hijab: "حجابك",
        student_kufi: "طاقيتك",
        student_saved: "تم الحفظ!",
        student_this_is_me: "هذا أنا",
        student_hand_back: "أعِد الهاتف",
        student_session_over: "انتهت جلسة الطالب. اطلب من شخص كبير أن يبدأها من جديد.",
        student_error: "حدث خطأ ما. اطلب المساعدة من شخص كبير.",
        student_save_failed: "تعذّر الحفظ. اطلب المساعدة من شخص كبير.",

        // ------------------------------------------------- FamilyAttachment
        attachment: "مرفق",

        // ------------------------------------------------------ FamilyLayout
        layout_portal: "بوابة الأسرة",
        layout_sign_out: "تسجيل الخروج",
        layout_calendar: "التقويم المدرسي",

        // --------------------------------------------------- FamilyCalendar
        cal_title: "التقويم المدرسي",
        cal_sub: "أيام الدراسة في هذا العام، والأيام التي لا دراسة فيها.",
        cal_back: "العودة إلى صفوف أبنائي",
        cal_loading: "جارٍ تحميل التقويم المدرسي…",
        cal_empty_title: "لم تنشر المدرسة تقويمها الدراسي بعد",
        cal_empty_body: "عندما تضيف المدرسة عامها الدراسي، ستظهر الأيام هنا.",
        cal_load_error: "تعذّر تحميل التقويم المدرسي الآن. يرجى المحاولة مرة أخرى.",
        cal_retry: "إعادة المحاولة",
        cal_upcoming: "الأيام القادمة",
        cal_no_upcoming: "لا توجد أيام دراسة أخرى في هذا العام.",
        cal_school_day: "يوم دراسي",
        cal_no_school: "لا دراسة",
        cal_today: "اليوم",
        cal_year: "العام الدراسي",
        // Intl supplies the weekday with its article ("الأحد"), so the sentence
        // reads "كل يوم الأحد" — every Sunday.
        cal_meets_every: "تُعقد الدراسة كل يوم {x}",
        cal_no_days: "لا توجد أيام دراسة مدرجة لهذا العام.",

        // ------------------------------- translating what the school wrote
        tr_translate: "ترجمة",
        tr_show_original: "إظهار الأصل",
        tr_busy: "خدمة الترجمة مشغولة الآن. يرجى المحاولة بعد دقيقة.",
        tr_unavailable: "الترجمة غير متاحة في الوقت الحالي.",
        tr_failed: "تعذّرت الترجمة الآن.",
        tr_incomplete: "تعذّرت ترجمة جزء مما هنا، وهو معروض بلغته الأصلية.",
        tr_machine: "ترجمة آلية. اضغط «إظهار الأصل» لقراءة كلام المدرسة نفسه.",

        // ------------------------------------------------------- FamilyClass
        class_all: "كل الصفوف",
        class_load_error: "تعذّر تحميل هذا الصف الآن. يرجى المحاولة مرة أخرى.",
        tab_story: "يوميات الصف",
        tab_messages: "الرسائل",
        tab_children: "أبنائي",
        tab_reports: "بطاقات التقرير",
        tab_handouts: "النشرات",
        handouts_empty: "لم تتم مشاركة أي شيء بعد. وكل ما يرسله المعلّم إلى البيت سيظهر هنا.",
        handouts_error: "تعذّر تحميلها الآن.",
        handout_download_failed: "تعذّر تنزيل هذا الملف.",
        kb: "ك.ب",
        story_no_consent: "يوميات الصف مخفية لأن موافقتك على استقبال أخبار الصف غير مسجَّلة. ويمكن لمكتب المدرسة تسجيلها لك.",
        story_empty: "لم يُنشر شيء بعد.",
        the_school: "المدرسة",
        media_withheld: "الصور في هذا المنشور مخفية لأن الموافقة على نشر الصور غير مسجَّلة.",
        message_media_withheld: "الصور في هذه الرسالة مخفية لأن الموافقة على نشر الصور غير مسجَّلة.",
        msg_teacher: "مراسلة المعلّم",
        compose_about: "بخصوص",
        compose_subject: "الموضوع",
        compose_subject_ph: "ما موضوع رسالتك؟",
        compose_body_ph: "رسالتك إلى المعلّم…",
        compose_private: "لن يطّلع على هذه الرسالة إلا معلّم ابنك.",
        compose_rate_limited: "لقد بدأت عدة محادثات بالفعل. يرجى متابعة إحداها.",
        compose_failed: "تعذّر إرسال هذه الرسالة.",
        threads_empty: "لا توجد رسائل بعد.",
        thread_untitled: "رسالة",
        thread_new: "جديد",
        thread_about: "بخصوص {x}",
        // No {x} in the first two on purpose: Arabic carries one and two in the
        // noun itself, and "1 رسالة واحدة" is how a portal announces that it
        // was translated by a machine. t() leaves a string without a slot alone.
        msgs_one: "رسالة واحدة",
        msgs_two: "رسالتان",
        msgs_few: "{x} رسائل",
        msgs_many: "{x} رسالة",
        msg_you: "أنت",
        thread_closed: "أغلقت المدرسة هذه المحادثة.",
        reply_ph: "اكتب رداً…",
        reply_failed: "تعذّر إرسال ردّك. يرجى المحاولة مرة أخرى.",
        thread_open_failed: "تعذّر فتح هذه المحادثة.",
        choose_avatar: "اختر صورة رمزية",
        handover_starting: "جارٍ البدء…",
        handover_let: "دع {x} يختار",
        handover_failed: "تعذّر البدء. يرجى المحاولة مرة أخرى.",
        section_behaviour: "السلوك",
        section_letters: "الحروف",
        alphabet_arabic: "الحروف العربية",
        alphabet_english: "الحروف الإنجليزية",
        stage_letters: "الحروف",
        stage_short_vowels: "الحركات",
        stage_sukun_shadda: "السكون والشدّة",
        stage_tanween: "التنوين",
        stage_madd: "المدود",
        section_quran: "القرآن",
        arabic_letter_notes: "ملاحظات المعلّم على الحروف",
        arabic_daily_notes: "ملاحظات المعلّم على دروس العربية",
        notes_unavailable: "تعذّر تحميل هذه الملاحظات الآن.",
        count_of: "من",
        letter_not_started: "لم يبدأ",
        letter_learning: "قيد التعلّم",
        letter_mastered: "متقن",
        surah: "سورة",
        reports_all: "كل التقارير",
        threads_all: "كل الرسائل",
        report_preparing: "جارٍ التجهيز…",
        report_download: "تنزيل ملف PDF",
        report_sent: "أُرسل في {x}",
        // Western digits on purpose: the levels themselves arrive from the
        // server as 4/3/2/1 and are shown that way on the badges, so a question
        // asking about ٤ و٣ would be asking about numbers the parent cannot see.
        level_key_summary: "ماذا تعني 4 و3 و2 و1؟",
        attendance_present: "الحضور {x}",
        attendance_late: "(منها {x} تأخير)",
        attendance_absent: "الغياب {x}",
        learning_behaviours: "سلوكيات التعلّم",
        teacher_comment: "ملاحظة المعلّم",
        not_assessed: "لم يُقيَّم",
        reports_empty: "لم يُرسَل أي تقرير إلى البيت بعد. وعندما يُرسَل تقرير، سيظهر هنا.",
        reports_partial_error: "تعذّر تحميل بعض التقارير الآن.",
        report_open_failed: "تعذّر فتح هذا التقرير.",
        report_download_failed: "تعذّر تنزيل هذا التقرير الآن.",
        // ------------------------------------------------------------ الدرجات
        // "الدرجات" for the marks themselves; the report card's year level is
        // "الصف" elsewhere, so the two do not collide in Arabic the way "Marks"
        // and "Grades" would in English.
        tab_grades: "الدرجات",
        marks_empty: "لا توجد درجات بعد. وعندما يسجّل المعلّم درجةً، ستظهر هنا.",
        marks_error: "تعذّر تحميل بعض الدرجات الآن.",
        marks_section_points: "الأعمال المُقيَّمة بالنقاط",
        marks_section_levels: "مستويات الأداء",
        marks_pieces_one: "في عمل واحد",
        marks_pieces_two: "في عملين",
        marks_pieces_few: "في {x} أعمال",
        marks_pieces_many: "في {x} عملًا",
        level_4: "يفوق التوقعات",
        level_3: "يحقق التوقعات",
        level_2: "يقترب من التوقعات",
        level_1: "يحتاج إلى دعم",
        level_short_4: "يفوق",
        level_short_3: "يحقق",
        level_short_2: "يقترب",
        level_short_1: "يحتاج دعمًا",
        level_desc_4: "يُظهر إتقانًا ثابتًا، ويطبّق المهارات باستقلالية، بدقة وعمق وثقة.",
        level_desc_3: "يُظهر إتقانًا للمستوى الدراسي، ويُنجز المهام بأقل قدر من المساعدة، وفهمه راسخ.",
        level_desc_2: "فهم جزئي، ويحتاج إلى مساعدة أو تذكير، وأداؤه غير مستقر.",
        level_desc_1: "فهم محدود، ويحتاج إلى توجيه كبير، والمهارات لم تتكوّن بعد.",
        marks_levels_mean: "متوسط المستوى {x}",
        mark_missing: "لم يُسلَّم",
        mark_excused: "معفى منه",
        marks_truncated: "تُعرض أحدث {x} من الدرجات.",
    },
};

export function useFamilyLang() {
    const isAr = computed(() => lang.value === "ar");

    /**
     * Right-to-left — Arabic, Urdu, Pashto or Dari. Anything that points a
     * direction (a back arrow, a chevron) reads this, never `isAr`: an Urdu
     * page is laid out exactly like an Arabic one.
     */
    const isRtl = computed(() => INFO[lang.value].dir === "rtl");
    const dir = computed(() => INFO[lang.value].dir);

    /**
     * The locale handed to toLocaleDateString.
     *
     * `ar-u-nu-latn` — Arabic month names, Western digits. Plain `ar` would
     * render the date in Arabic-Indic numerals (٢٥) while every other number on
     * the same screen (a behaviour award's points, a report level, a letter
     * count, an attendance figure) arrives from the server as 25 and is printed
     * as 25. One screen showing a date in one numeral system and its counts in
     * another is harder to read than either would be alone.
     */
    const locale = computed(() => INFO[lang.value].intl);

    /** Choose a language; anything that is not one of ours is ignored. */
    function setLang(next: unknown): void {
        const code = normaliseFamilyLang(next);

        if (!code) return;

        lang.value = code;
        try {
            localStorage.setItem(LANG_KEY, code);
        } catch {
            /* private mode / storage disabled — the in-memory ref still works */
        }
    }

    /**
     * English and back again. Kept for any screen still carrying the old
     * two-way button; the five screens use FamilyLangPicker.vue.
     */
    function toggle(): void {
        setLang(lang.value === "en" ? suggested : "en");
    }

    /**
     * The language a "Translate" tap asks for: the portal's own language, or —
     * while the portal is in English — the one the second label names, so the
     * button always translates into the language written on it.
     */
    const translationTarget = computed<Exclude<FamilyLang, "en">>(() =>
        lang.value === "en" ? suggested : lang.value,
    );

    /** Translate a key for the current language, with a `{x}` interpolation slot. */
    function t(key: string, x?: string): string {
        const s = STRINGS[lang.value][key] ?? STRINGS.en[key] ?? key;
        return x !== undefined ? s.replace("{x}", x) : s;
    }

    /**
     * "3 messages" in either language.
     *
     * Arabic counts in four shapes — one, two, a few (3–10) and many (11+) — and
     * a portal that says "1 رسائل" reads as broken to the parent it is meant to
     * serve. English defines all four keys with the two forms it has, so this
     * returns exactly today's wording when the portal is in English.
     */
    function tCount(base: string, n: number): string {
        // Urdu, Pashto, Dari and Spanish have one form for one and one for the
        // rest, the same two English has; each table fills all four keys so a
        // missing one can never fall through to English mid-sentence.
        const suffix = lang.value !== "ar"
            ? (n === 1 ? "one" : "many")
            : n === 1 ? "one" : n === 2 ? "two" : n >= 3 && n <= 10 ? "few" : "many";
        return t(`${base}_${suffix}`, String(n));
    }

    /** Render a {@see FamilyMessage} slot: our key translated, or the API's own words. */
    function tMessage(m: FamilyMessage | null | undefined): string {
        if (!m) return "";
        return m.text ?? (m.key ? t(m.key) : "");
    }

    /**
     * One key in BOTH languages, current one first — "Translate · ترجمة".
     *
     * Used only by the "translate what the school wrote" control and the two
     * notices under it, and only there for a reason. Everything else in this
     * portal follows the toggle, which is right: a parent picks a language and
     * the chrome obeys. But the parent who most needs this particular button is
     * the one who has NOT found the toggle — they opened an emailed link, the
     * portal came up in English, and English is the language they cannot read.
     * A button labelled only in the language they are stuck in is a button they
     * will never press, and an error message under it is a sentence they cannot
     * act on. So these few strings pay for the extra width by being legible
     * whichever way round the parent's problem is.
     */
    function tBoth(key: string): string {
        // The current language first, then the other one: English when the
        // portal is in any other language, and the browser's own language
        // (Arabic when it names none of ours) when the portal is in English.
        const other: FamilyLang = lang.value === "en" ? suggested : "en";
        const here = STRINGS[lang.value][key] ?? STRINGS.en[key] ?? key;
        const there = STRINGS[other][key] ?? STRINGS.en[key] ?? key;

        if (here === there) return here;

        return `${here} · ${there}`;
    }

    /** {@see tMessage}, in both languages. The API's own words pass through as they came. */
    function tMessageBoth(m: FamilyMessage | null | undefined): string {
        if (!m) return "";
        return m.text ?? (m.key ? tBoth(m.key) : "");
    }

    /** The label shown ON the old toggle button (the language it switches TO). */
    const switchLabel = computed(() => INFO[lang.value === "en" ? suggested : "en"].label);

    return {
        lang, isAr, isRtl, dir, locale, setLang, toggle, translationTarget,
        t, tCount, tMessage, tBoth, tMessageBoth, switchLabel, languages: FAMILY_LANGS,
    };
}
