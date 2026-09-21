/**
 * Urdu (اردو) — parent portal labels.
 *
 * ============================================================================
 * MACHINE-DRAFTED. NOT YET REVIEWED BY A FLUENT URDU SPEAKER.
 * ============================================================================
 *
 * Drafted 2026-09-21 from the English column of familyI18n.ts, at the owner's
 * request ("Add them, portal labels too"), so that a parent who reads Urdu
 * can find their way round the portal. It has not been read by anybody who
 * speaks the language. Until it has, treat every line as a best guess: have a
 * fluent reader — ideally a parent or teacher at the school — go through the
 * whole file against the English, then set `reviewed: true` for "ur" in
 * FAMILY_LANGS (familyI18n.ts) and delete this banner.
 *
 * Conventions this draft follows, which a reviewer should check rather than
 * assume:
 *   - Religious terms are kept as the school uses them (Qur'an, Surah, the
 *     qāʿidah's stage names, "Assalamu alaikum"), in the form Muslims who read
 *     this language conventionally write them. No honorifics were added.
 *   - {x} is a slot filled at run time (a name, a date, a count); keep it.
 *   - Numbers stay in Western digits, like every other number on the page.
 *   - Only the portal's own words are here. What teachers write is never in
 *     this file — that goes through the separate, on-request translation.
 *
 * Keys must match the English table exactly;
 * tests/Feature/FamilyLanguagesMirrorTest.php fails when one is missing or
 * extra.
 */
export const UR: Record<string, string> = {
    // ------------------------------------------------------ shared
    switch_lang_title: "زبان تبدیل کریں",
    student: "طالب علم",
    cancel: "منسوخ کریں",
    send: "بھیجیں",
    sending: "بھیجا جا رہا ہے…",
    nothing_recorded: "ابھی تک کچھ درج نہیں کیا گیا۔",

    // -------------------------------------------------- FamilyHome
    home_greeting: "السلام علیکم",
    home_greeting_named: "السلام علیکم، {x}",
    home_sub: "اس اسکول میں آپ کے بچوں کی کلاسیں۔",
    home_empty_title: "یہاں ابھی کچھ نہیں ہے",
    home_empty_body: "اسکول نے ابھی تک آپ کو کسی کلاس میں شامل نہیں کیا۔ اگر آپ کے خیال میں یہ غلط ہے تو دفتر سے رابطہ کریں — وہ آپ کا ریکارڈ دیکھ سکتے ہیں۔",
    home_no_consent: "آپ نے کلاس کی خبروں کے لیے رضامندی نہیں دی، اس لیے کلاس کی خبریں چھپی ہوئی ہیں۔ اسکول کا دفتر آپ کی رضامندی درج کر سکتا ہے۔",
    home_load_error: "ہم ابھی آپ کی کلاسیں لوڈ نہیں کر سکے۔ براہ کرم دوبارہ کوشش کریں۔",
    signin_panel_title: "سائن ان",
    signin_panel_has_pw: "آپ کا پاس ورڈ مقرر ہے۔ آپ اب بھی کسی بھی وقت ای میل پر کوڈ منگوا سکتے ہیں۔",
    signin_panel_no_pw: "آپ چھ ہندسوں کے اس کوڈ سے سائن ان کرتے ہیں جو ہم آپ کو ای میل کرتے ہیں۔ اگر آپ پاس ورڈ استعمال کرنا چاہیں تو یہاں مقرر کر سکتے ہیں — کوڈ دونوں صورتوں میں کام کرتے رہیں گے۔",
    pw_new: "نیا پاس ورڈ",
    pw_again: "دوبارہ لکھیں",
    pw_hint: "کم از کم 12 حروف۔ کوئی مختصر جملہ جو آپ کو یاد رہے، اچھا رہتا ہے۔",
    pw_saving: "محفوظ کیا جا رہا ہے…",
    pw_change: "پاس ورڈ تبدیل کریں",
    pw_set: "پاس ورڈ مقرر کریں",
    pw_change_mine: "میرا پاس ورڈ تبدیل کریں",
    pw_set_a: "پاس ورڈ مقرر کریں",
    pw_remove: "اسے ہٹا دیں",
    pw_mismatch: "دونوں پاس ورڈ ایک جیسے نہیں تھے۔",
    pw_saved: "آپ کا پاس ورڈ مقرر ہو گیا ہے۔ اب سے آپ اس سے سائن ان کر سکتے ہیں۔",
    pw_save_failed: "یہ پاس ورڈ محفوظ نہیں ہو سکا۔",
    pw_removed: "آپ کا پاس ورڈ ہٹا دیا گیا ہے۔ اب سے ای میل پر آنے والے کوڈ سے سائن ان کریں۔",
    pw_remove_failed: "اسے ہٹایا نہیں جا سکا۔",

    // ------------------------------------------------ FamilySignIn
    signin_title: "والدین کا سائن ان",
    signin_sub: "ہم آپ کو چھ ہندسوں کا کوڈ ای میل کرتے ہیں۔ اگر آپ نے پاس ورڈ مقرر کیا ہے تو اس کے بجائے وہ استعمال کر سکتے ہیں۔",
    signin_email_label: "آپ کا ای میل پتہ",
    signin_email_hint: "وہی پتہ استعمال کریں جو اسکول کے ریکارڈ میں آپ کا ہے۔",
    signin_password_label: "آپ کا پاس ورڈ",
    signin_submit: "سائن ان کریں",
    signin_code_instead: "اس کے بجائے مجھے کوڈ ای میل کریں",
    signin_email_code: "مجھے کوڈ ای میل کریں",
    signin_have_password: "میرے پاس پاس ورڈ ہے",
    signin_sent_before: "اگر",
    signin_sent_after: "ہمارے ریکارڈ میں ہے تو چھ ہندسوں کا کوڈ آپ کو بھیج دیا گیا ہے۔ اس کی میعاد جلد ختم ہو جاتی ہے، اور یہ صرف ایک بار استعمال ہو سکتا ہے۔",
    signin_code_label: "چھ ہندسوں کا کوڈ",
    signin_other_address: "کوئی اور پتہ استعمال کریں",
    signin_code_failed: "یہ کوڈ کام نہیں کر سکا۔ ہو سکتا ہے اس کی میعاد ختم ہو گئی ہو یا یہ پہلے استعمال ہو چکا ہو — نیا کوڈ منگوائیں۔",
    signin_password_failed: "یہ ای میل اور پاس ورڈ آپس میں مطابقت نہیں رکھتے۔ آپ اس کے بجائے کوڈ منگوا سکتے ہیں۔",

    // ------------------------------------------------- StudentMode
    student_back: "فیملی پورٹل پر واپس جائیں",
    student_greeting: "السلام علیکم، {x}!",
    student_sub: "چنو کہ تم کیسے دکھنا چاہتے ہو۔",
    student_who: "تم کون ہو؟",
    student_skin: "تمہاری جلد",
    student_hijab: "تمہارا حجاب",
    student_kufi: "تمہاری ٹوپی",
    student_saved: "محفوظ ہو گیا!",
    student_this_is_me: "یہ میں ہوں",
    student_hand_back: "فون واپس کر دو",
    student_session_over: "طالب علم کا یہ سیشن ختم ہو گیا ہے۔ کسی بڑے سے کہو کہ اسے دوبارہ شروع کریں۔",
    student_error: "کچھ غلط ہو گیا۔ کسی بڑے سے مدد مانگو۔",
    student_save_failed: "یہ محفوظ نہیں ہو سکا۔ کسی بڑے سے مدد مانگو۔",

    // -------------------------------------------- FamilyAttachment
    attachment: "منسلکہ",

    // ------------------------------------------------ FamilyLayout
    layout_portal: "فیملی پورٹل",
    layout_sign_out: "سائن آؤٹ",
    layout_calendar: "اسکول کیلنڈر",

    // ---------------------------------------------- FamilyCalendar
    cal_title: "اسکول کیلنڈر",
    cal_sub: "اس سال اسکول کے دن، اور وہ دن جب اسکول نہیں ہوگا۔",
    cal_back: "میری کلاسوں پر واپس",
    cal_loading: "اسکول کیلنڈر لوڈ ہو رہا ہے…",
    cal_empty_title: "اسکول کیلنڈر ابھی شائع نہیں ہوا",
    cal_empty_body: "جب اسکول اپنا تعلیمی سال شامل کرے گا تو دن یہاں نظر آئیں گے۔",
    cal_load_error: "ہم ابھی اسکول کیلنڈر لوڈ نہیں کر سکے۔ براہ کرم دوبارہ کوشش کریں۔",
    cal_retry: "دوبارہ کوشش کریں",
    cal_upcoming: "آنے والے دن",
    cal_no_upcoming: "اس سال اسکول کے مزید دن باقی نہیں ہیں۔",
    cal_school_day: "اسکول کا دن",
    cal_no_school: "چھٹی",
    cal_today: "آج",
    cal_year: "تعلیمی سال",
    cal_meets_every: "کلاسیں ہر {x} کو ہوتی ہیں",
    cal_no_days: "اس سال کے لیے اسکول کے کوئی دن درج نہیں ہیں۔",

    // --------------------------- translating what the school wrote
    tr_translate: "ترجمہ کریں",
    tr_show_original: "اصل دکھائیں",
    tr_busy: "ترجمے کی سروس اس وقت مصروف ہے۔ براہ کرم ایک منٹ بعد دوبارہ کوشش کریں۔",
    tr_unavailable: "ترجمہ اس وقت دستیاب نہیں ہے۔",
    tr_failed: "ابھی اس کا ترجمہ نہیں ہو سکا۔",
    tr_incomplete: "اس کے کچھ حصے کا ترجمہ نہیں ہو سکا اور وہ ابھی تک اسی زبان میں ہے جس میں لکھا گیا تھا۔",
    tr_machine: "خودکار ترجمہ۔ اسکول کے اپنے الفاظ پڑھنے کے لیے «اصل دکھائیں» پر ٹیپ کریں۔",

    // ------------------------------------------------- FamilyClass
    class_all: "تمام کلاسیں",
    class_load_error: "ہم ابھی یہ کلاس لوڈ نہیں کر سکے۔ براہ کرم دوبارہ کوشش کریں۔",
    tab_story: "کلاس کی خبریں",
    tab_messages: "پیغامات",
    tab_children: "میرے بچے",
    tab_reports: "رپورٹ کارڈ",
    tab_handouts: "پرچے",
    handouts_empty: "ابھی تک کچھ شیئر نہیں کیا گیا۔ آپ کے استاد جو کچھ گھر بھیجیں گے وہ یہاں نظر آئے گا۔",
    handouts_error: "یہ ابھی لوڈ نہیں ہو سکے۔",
    handout_download_failed: "یہ فائل ڈاؤن لوڈ نہیں ہو سکی۔",
    kb: "KB",
    story_no_consent: "کلاس کی خبریں چھپی ہوئی ہیں کیونکہ کلاس کی خبروں کے لیے آپ کی رضامندی درج نہیں ہے۔ اسکول کا دفتر اسے آپ کے لیے درج کر سکتا ہے۔",
    story_empty: "ابھی تک کچھ پوسٹ نہیں کیا گیا۔",
    the_school: "اسکول",
    media_withheld: "اس پوسٹ کی تصاویر چھپی ہوئی ہیں کیونکہ تصاویر کی رضامندی درج نہیں ہے۔",
    message_media_withheld: "اس پیغام کی تصاویر چھپی ہوئی ہیں کیونکہ تصاویر کی رضامندی درج نہیں ہے۔",
    msg_teacher: "استاد کو پیغام بھیجیں",
    compose_about: "کس کے بارے میں",
    compose_subject: "موضوع",
    compose_subject_ph: "یہ کس بارے میں ہے؟",
    compose_body_ph: "استاد کے نام آپ کا پیغام…",
    compose_private: "یہ صرف آپ کے بچے کے استاد دیکھیں گے۔",
    compose_rate_limited: "آپ پہلے ہی کئی گفتگوئیں شروع کر چکے ہیں۔ براہ کرم ان میں سے کسی ایک کو جاری رکھیں۔",
    compose_failed: "یہ پیغام نہیں بھیجا جا سکا۔",
    threads_empty: "ابھی تک کوئی پیغام نہیں۔",
    thread_untitled: "پیغام",
    thread_new: "نیا",
    thread_about: "{x} کے بارے میں",
    msgs_one: "{x} پیغام",
    msgs_two: "{x} پیغامات",
    msgs_few: "{x} پیغامات",
    msgs_many: "{x} پیغامات",
    msg_you: "آپ",
    thread_closed: "اسکول نے یہ گفتگو بند کر دی ہے۔",
    reply_ph: "جواب لکھیں…",
    reply_failed: "آپ کا جواب نہیں بھیجا جا سکا۔ براہ کرم دوبارہ کوشش کریں۔",
    thread_open_failed: "یہ گفتگو کھولی نہیں جا سکی۔",
    choose_avatar: "اوتار منتخب کریں",
    handover_starting: "شروع ہو رہا ہے…",
    handover_let: "{x} کو خود چننے دیں",
    handover_failed: "یہ شروع نہیں ہو سکا۔ براہ کرم دوبارہ کوشش کریں۔",
    section_behaviour: "رویہ",
    section_letters: "حروف",
    alphabet_arabic: "عربی حروف",
    alphabet_english: "انگریزی حروف",
    stage_letters: "حروف",
    stage_short_vowels: "حرکات",
    stage_sukun_shadda: "سکون اور شد",
    stage_tanween: "تنوین",
    stage_madd: "مد",
    section_quran: "قرآن",
    arabic_letter_notes: "حروف پر استاد کے نوٹس",
    arabic_daily_notes: "عربی اسباق پر استاد کے نوٹس",
    notes_unavailable: "یہ نوٹس ابھی لوڈ نہیں ہو سکے۔",
    count_of: "/",
    letter_not_started: "شروع نہیں ہوا",
    letter_learning: "سیکھ رہا ہے",
    letter_mastered: "سیکھ لیا",
    surah: "سورۃ",
    reports_all: "تمام رپورٹیں",
    threads_all: "تمام پیغامات",
    report_preparing: "تیار ہو رہا ہے…",
    report_download: "PDF ڈاؤن لوڈ کریں",
    report_sent: "{x} کو بھیجا گیا",
    level_key_summary: "4، 3، 2 اور 1 کا کیا مطلب ہے؟",
    attendance_present: "حاضر {x}",
    attendance_late: "(جن میں {x} بار تاخیر)",
    attendance_absent: "غیر حاضر {x}",
    learning_behaviours: "سیکھنے کے رویے",
    teacher_comment: "استاد کا تبصرہ",
    not_assessed: "جانچ نہیں ہوئی",
    reports_empty: "ابھی تک کوئی رپورٹ گھر نہیں بھیجی گئی۔ جب بھیجی جائے گی تو یہاں نظر آئے گی۔",
    reports_partial_error: "کچھ رپورٹیں ابھی لوڈ نہیں ہو سکیں۔",
    report_open_failed: "یہ رپورٹ کھولی نہیں جا سکی۔",
    report_download_failed: "یہ رپورٹ ابھی ڈاؤن لوڈ نہیں ہو سکی۔",

    // --------------------------------------- marks (the gradebook)
    tab_grades: "نمبر",
    marks_empty: "ابھی کوئی نمبر نہیں۔ جب آپ کے استاد نمبر درج کریں گے تو آپ انہیں یہاں دیکھیں گے۔",
    marks_error: "کچھ نمبر ابھی لوڈ نہیں ہو سکے۔",
    marks_section_points: "پوائنٹس والا کام",
    marks_section_levels: "کارکردگی کی سطحیں",
    marks_pieces_one: "{x} کام کی بنیاد پر",
    marks_pieces_two: "{x} کاموں کی بنیاد پر",
    marks_pieces_few: "{x} کاموں کی بنیاد پر",
    marks_pieces_many: "{x} کاموں کی بنیاد پر",
    level_4: "توقعات سے بڑھ کر",
    level_3: "توقعات کے مطابق",
    level_2: "توقعات کے قریب",
    level_1: "مدد کی ضرورت ہے",
    level_short_4: "بڑھ کر",
    level_short_3: "مطابق",
    level_short_2: "قریب",
    level_short_1: "مدد درکار",
    level_desc_4: "مستقل طور پر مہارت دکھاتا ہے؛ مہارتیں خود سے استعمال کرتا ہے؛ درستی، گہرائی اور اعتماد دکھاتا ہے۔",
    level_desc_3: "اپنی جماعت کی سطح کی مہارت دکھاتا ہے؛ کم سے کم مدد سے کام مکمل کرتا ہے؛ اچھی سمجھ رکھتا ہے۔",
    level_desc_2: "جزوی سمجھ؛ مدد یا یاد دہانی کی ضرورت ہوتی ہے؛ کارکردگی یکساں نہیں۔",
    level_desc_1: "محدود سمجھ؛ کافی رہنمائی کی ضرورت ہے؛ مہارتیں ابھی پیدا نہیں ہوئیں۔",
    marks_levels_mean: "اوسط سطح {x}",
    mark_missing: "جمع نہیں کرایا",
    mark_excused: "معاف",
    marks_truncated: "تازہ ترین {x} دکھائے جا رہے ہیں۔",
    // Reactions and read receipts (2026-09-21) — machine-drafted like the rest.
    seen_by: "{x} نے دیکھ لیا",
    not_seen: "ابھی نہیں دیکھا گیا",
    reaction_others: "{n} مزید",
    reactions_group: "ردِعمل",
    reaction_ameen: "آمین",
    reaction_thumbs_up: "بہت خوب",
    reaction_hundred: "100",
    reaction_question: "سوال",
    reaction_failed: "یہ ردِعمل محفوظ نہیں ہو سکا۔",
    // Three-word marking scale (2026-09-21) — machine-drafted like the rest.
    marks_section_simple: "بہترین / اچھا / مزید محنت درکار",
    simple_mark_3: "بہترین",
    simple_mark_2: "اچھا",
    simple_mark_1: "مزید محنت درکار",
};
