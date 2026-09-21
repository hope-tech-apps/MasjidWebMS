/**
 * Dari (fa-AF) (دری) — parent portal labels.
 *
 * ============================================================================
 * MACHINE-DRAFTED. NOT YET REVIEWED BY A FLUENT DARI SPEAKER.
 * ============================================================================
 *
 * Drafted 2026-09-21 from the English column of familyI18n.ts, at the owner's
 * request ("Add them, portal labels too"), so that a parent who reads Dari
 * can find their way round the portal. It has not been read by anybody who
 * speaks the language. Until it has, treat every line as a best guess: have a
 * fluent reader — ideally a parent or teacher at the school — go through the
 * whole file against the English, then set `reviewed: true` for "fa-AF" in
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
export const FA_AF: Record<string, string> = {
    // ------------------------------------------------------ shared
    switch_lang_title: "تغییر زبان",
    student: "شاگرد",
    cancel: "لغو",
    send: "ارسال",
    sending: "در حال ارسال…",
    nothing_recorded: "هنوز چیزی ثبت نشده است.",

    // -------------------------------------------------- FamilyHome
    home_greeting: "السلام علیکم",
    home_greeting_named: "السلام علیکم، {x}",
    home_sub: "صنف‌های فرزندان شما در این مکتب.",
    home_empty_title: "هنوز چیزی اینجا نیست",
    home_empty_body: "مکتب هنوز شما را به هیچ صنفی اضافه نکرده است. اگر فکر می‌کنید این اشتباه است، با دفتر مکتب تماس بگیرید — آن‌ها می‌توانند سوابق شما را ببینند.",
    home_no_consent: "شما برای دریافت خبرهای صنف رضایت نداده‌اید، به همین دلیل خبرهای صنف پنهان است. دفتر مکتب می‌تواند رضایت شما را ثبت کند.",
    home_load_error: "فعلاً نتوانستیم صنف‌های شما را بارگذاری کنیم. لطفاً دوباره کوشش کنید.",
    signin_panel_title: "ورود",
    signin_panel_has_pw: "شما رمز عبور تعیین کرده‌اید. باز هم می‌توانید هر وقت خواستید یک کد از طریق ایمیل درخواست کنید.",
    signin_panel_no_pw: "شما با یک کد شش‌رقمی که به ایمیل‌تان می‌فرستیم وارد می‌شوید. اگر رمز عبور را ترجیح می‌دهید، می‌توانید اینجا تعیین کنید — کدها در هر حال کار می‌کنند.",
    pw_new: "رمز عبور جدید",
    pw_again: "دوباره بنویسید",
    pw_hint: "دست‌کم 12 حرف. یک جملهٔ کوتاه که به یادتان بماند انتخاب خوبی است.",
    pw_saving: "در حال ذخیره…",
    pw_change: "تغییر رمز عبور",
    pw_set: "تعیین رمز عبور",
    pw_change_mine: "تغییر رمز عبور من",
    pw_set_a: "تعیین یک رمز عبور",
    pw_remove: "حذف آن",
    pw_mismatch: "دو رمز عبور یکسان نبودند.",
    pw_saved: "رمز عبور شما تعیین شد. از این پس می‌توانید با آن وارد شوید.",
    pw_save_failed: "این رمز عبور ذخیره نشد.",
    pw_removed: "رمز عبور شما حذف شد. از این پس با کدی که به ایمیل‌تان فرستاده می‌شود وارد شوید.",
    pw_remove_failed: "حذف نشد.",

    // ------------------------------------------------ FamilySignIn
    signin_title: "ورود والدین",
    signin_sub: "ما یک کد شش‌رقمی به ایمیل شما می‌فرستیم. اگر رمز عبور تعیین کرده‌اید، می‌توانید به جای آن از رمز استفاده کنید.",
    signin_email_label: "آدرس ایمیل شما",
    signin_email_hint: "آدرسی را به کار ببرید که در سوابق مکتب ثبت است.",
    signin_password_label: "رمز عبور شما",
    signin_submit: "ورود",
    signin_code_instead: "به جای آن برایم کد ایمیل کنید",
    signin_email_code: "برایم کد ایمیل کنید",
    signin_have_password: "رمز عبور دارم",
    signin_sent_before: "اگر",
    signin_sent_after: "در سوابق ما ثبت باشد، یک کد شش‌رقمی در راه است. این کد به‌زودی منقضی می‌شود و فقط یک بار قابل استفاده است.",
    signin_code_label: "کد شش‌رقمی",
    signin_other_address: "استفاده از آدرس دیگر",
    signin_code_failed: "این کد کار نکرد. شاید منقضی شده یا قبلاً استفاده شده باشد — یک کد جدید درخواست کنید.",
    signin_password_failed: "این ایمیل و رمز عبور با هم مطابقت ندارند. می‌توانید به جای آن کد درخواست کنید.",

    // ------------------------------------------------- StudentMode
    student_back: "بازگشت به پورتال خانواده",
    student_greeting: "السلام علیکم، {x}!",
    student_sub: "انتخاب کن که چطور دیده شوی.",
    student_who: "تو کی هستی؟",
    student_skin: "رنگ پوستت",
    student_hijab: "حجابت",
    student_kufi: "کلاهت",
    student_saved: "ذخیره شد!",
    student_this_is_me: "این من هستم",
    student_hand_back: "تلفون را پس بده",
    student_session_over: "این جلسهٔ شاگرد تمام شد. از یک بزرگ‌تر بخواه که دوباره آن را شروع کند.",
    student_error: "مشکلی پیش آمد. از یک بزرگ‌تر کمک بخواه.",
    student_save_failed: "ذخیره نشد. از یک بزرگ‌تر کمک بخواه.",

    // -------------------------------------------- FamilyAttachment
    attachment: "ضمیمه",

    // ------------------------------------------------ FamilyLayout
    layout_portal: "پورتال خانواده",
    layout_sign_out: "خروج",
    layout_calendar: "تقویم مکتب",

    // ---------------------------------------------- FamilyCalendar
    cal_title: "تقویم مکتب",
    cal_sub: "روزهای درسی امسال، و روزهایی که مکتب رخصت است.",
    cal_back: "بازگشت به صنف‌هایم",
    cal_loading: "در حال بارگذاری تقویم مکتب…",
    cal_empty_title: "تقویم مکتب هنوز نشر نشده است",
    cal_empty_body: "وقتی مکتب سال تعلیمی خود را اضافه کند، روزها اینجا نمایش داده می‌شوند.",
    cal_load_error: "فعلاً نتوانستیم تقویم مکتب را بارگذاری کنیم. لطفاً دوباره کوشش کنید.",
    cal_retry: "دوباره کوشش کنید",
    cal_upcoming: "روزهای پیش رو",
    cal_no_upcoming: "امسال روز درسی دیگری باقی نمانده است.",
    cal_school_day: "روز درسی",
    cal_no_school: "رخصتی",
    cal_today: "امروز",
    cal_year: "سال تعلیمی",
    cal_meets_every: "صنف‌ها هر {x} برگزار می‌شوند",
    cal_no_days: "برای این سال هیچ روز درسی فهرست نشده است.",

    // --------------------------- translating what the school wrote
    tr_translate: "ترجمه",
    tr_show_original: "نمایش متن اصلی",
    tr_busy: "خدمت ترجمه فعلاً مصروف است. لطفاً یک دقیقه بعد دوباره کوشش کنید.",
    tr_unavailable: "ترجمه فعلاً در دسترس نیست.",
    tr_failed: "فعلاً ترجمه نشد.",
    tr_incomplete: "بخشی از این ترجمه نشد و هنوز به همان زبانی است که نوشته شده بود.",
    tr_machine: "ترجمهٔ خودکار. برای خواندن سخنان خود مکتب «نمایش متن اصلی» را لمس کنید.",

    // ------------------------------------------------- FamilyClass
    class_all: "همهٔ صنف‌ها",
    class_load_error: "فعلاً نتوانستیم این صنف را بارگذاری کنیم. لطفاً دوباره کوشش کنید.",
    tab_story: "خبرهای صنف",
    tab_messages: "پیام‌ها",
    tab_children: "فرزندانم",
    tab_reports: "نتیجه‌نامه‌ها",
    tab_handouts: "ورقه‌ها",
    handouts_empty: "هنوز چیزی شریک نشده است. هر چیزی که معلم شما به خانه بفرستد اینجا نمایش داده می‌شود.",
    handouts_error: "فعلاً بارگذاری نشدند.",
    handout_download_failed: "این فایل دانلود نشد.",
    kb: "KB",
    story_no_consent: "خبرهای صنف پنهان است، زیرا رضایت شما برای خبرهای صنف ثبت نشده است. دفتر مکتب می‌تواند آن را برای شما ثبت کند.",
    story_empty: "هنوز چیزی نشر نشده است.",
    the_school: "مکتب",
    media_withheld: "عکس‌های این پست پنهان است، زیرا رضایت نشر عکس ثبت نشده است.",
    message_media_withheld: "عکس‌های این پیام پنهان است، زیرا رضایت نشر عکس ثبت نشده است.",
    msg_teacher: "پیام به معلم",
    compose_about: "دربارهٔ",
    compose_subject: "موضوع",
    compose_subject_ph: "این دربارهٔ چیست؟",
    compose_body_ph: "پیام شما به معلم…",
    compose_private: "فقط معلم فرزند شما این را می‌بیند.",
    compose_rate_limited: "شما قبلاً چند گفتگو را شروع کرده‌اید. لطفاً یکی از آن‌ها را ادامه دهید.",
    compose_failed: "این پیام ارسال نشد.",
    threads_empty: "هنوز پیامی نیست.",
    thread_untitled: "پیام",
    thread_new: "جدید",
    thread_about: "دربارهٔ {x}",
    msgs_one: "{x} پیام",
    msgs_two: "{x} پیام",
    msgs_few: "{x} پیام",
    msgs_many: "{x} پیام",
    msg_you: "شما",
    thread_closed: "مکتب این گفتگو را بسته است.",
    reply_ph: "پاسخ بنویسید…",
    reply_failed: "پاسخ شما ارسال نشد. لطفاً دوباره کوشش کنید.",
    thread_open_failed: "این گفتگو باز نشد.",
    choose_avatar: "یک آواتار انتخاب کنید",
    handover_starting: "در حال شروع…",
    handover_let: "بگذارید {x} انتخاب کند",
    handover_failed: "شروع نشد. لطفاً دوباره کوشش کنید.",
    section_behaviour: "رفتار",
    section_letters: "حروف",
    alphabet_arabic: "حروف عربی",
    alphabet_english: "حروف انگلیسی",
    stage_letters: "حروف",
    stage_short_vowels: "حرکات",
    stage_sukun_shadda: "سکون و تشدید",
    stage_tanween: "تنوین",
    stage_madd: "مد",
    section_quran: "قرآن",
    arabic_letter_notes: "یادداشت‌های معلم دربارهٔ حروف",
    arabic_daily_notes: "یادداشت‌های معلم دربارهٔ درس‌های عربی",
    notes_unavailable: "این یادداشت‌ها فعلاً بارگذاری نشدند.",
    count_of: "از",
    letter_not_started: "شروع نشده",
    letter_learning: "در حال یادگیری",
    letter_mastered: "یاد گرفته",
    surah: "سوره",
    reports_all: "همهٔ نتیجه‌نامه‌ها",
    threads_all: "همهٔ پیام‌ها",
    report_preparing: "در حال آماده‌سازی…",
    report_download: "دانلود PDF",
    report_sent: "ارسال‌شده در {x}",
    level_key_summary: "4، 3، 2 و 1 چه معنایی دارند؟",
    attendance_present: "حاضر {x}",
    attendance_late: "(شامل {x} تأخیر)",
    attendance_absent: "غیرحاضر {x}",
    learning_behaviours: "رفتارهای یادگیری",
    teacher_comment: "نظر معلم",
    not_assessed: "ارزیابی نشده",
    reports_empty: "هنوز هیچ نتیجه‌نامه‌ای به خانه فرستاده نشده است. وقتی فرستاده شود، اینجا نمایش داده می‌شود.",
    reports_partial_error: "بعضی نتیجه‌نامه‌ها فعلاً بارگذاری نشدند.",
    report_open_failed: "این نتیجه‌نامه باز نشد.",
    report_download_failed: "این نتیجه‌نامه فعلاً دانلود نشد.",

    // --------------------------------------- marks (the gradebook)
    tab_grades: "نمره‌ها",
    marks_empty: "هنوز نمره‌ای نیست. وقتی معلم شما نمره‌ای ثبت کند، آن را اینجا می‌بینید.",
    marks_error: "بعضی نمره‌ها فعلاً بارگذاری نشدند.",
    marks_section_points: "کارهای نمره‌دار",
    marks_section_levels: "سطح‌های عملکرد",
    marks_pieces_one: "در {x} کار",
    marks_pieces_two: "در {x} کار",
    marks_pieces_few: "در {x} کار",
    marks_pieces_many: "در {x} کار",
    level_4: "فراتر از انتظار",
    level_3: "مطابق انتظار",
    level_2: "نزدیک به انتظار",
    level_1: "نیاز به کمک",
    level_short_4: "فراتر",
    level_short_3: "مطابق",
    level_short_2: "نزدیک",
    level_short_1: "نیاز به کمک",
    level_desc_4: "به‌طور مستمر تسلط نشان می‌دهد؛ مهارت‌ها را به‌طور مستقل به کار می‌برد؛ دقت، عمق و اعتماد به نفس نشان می‌دهد.",
    level_desc_3: "مهارت در سطح صنف خود را نشان می‌دهد؛ کارها را با کمترین کمک انجام می‌دهد؛ درک محکمی دارد.",
    level_desc_2: "درک ناقص؛ به کمک یا یادآوری نیاز دارد؛ عملکردش یکسان نیست.",
    level_desc_1: "درک محدود؛ به راهنمایی زیاد نیاز دارد؛ مهارت‌ها هنوز شکل نگرفته‌اند.",
    marks_levels_mean: "سطح اوسط {x}",
    mark_missing: "تحویل داده نشده",
    mark_excused: "معاف",
    marks_truncated: "آخرین {x} مورد نمایش داده می‌شود.",
    // Reactions and read receipts (2026-09-21) — machine-drafted like the rest.
    seen_by: "دیده شده توسط {x}",
    not_seen: "هنوز دیده نشده",
    reaction_others: "{n} نفر دیگر",
    reactions_group: "واکنش‌ها",
    reaction_ameen: "آمین",
    reaction_thumbs_up: "عالی",
    reaction_hundred: "100",
    reaction_question: "سوال",
    reaction_failed: "این واکنش ذخیره نشد.",
};
