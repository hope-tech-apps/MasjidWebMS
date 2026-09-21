/**
 * Pashto (پښتو) — parent portal labels.
 *
 * ============================================================================
 * MACHINE-DRAFTED. NOT YET REVIEWED BY A FLUENT PASHTO SPEAKER.
 * ============================================================================
 *
 * Drafted 2026-09-21 from the English column of familyI18n.ts, at the owner's
 * request ("Add them, portal labels too"), so that a parent who reads Pashto
 * can find their way round the portal. It has not been read by anybody who
 * speaks the language. Until it has, treat every line as a best guess: have a
 * fluent reader — ideally a parent or teacher at the school — go through the
 * whole file against the English, then set `reviewed: true` for "ps" in
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
export const PS: Record<string, string> = {
    // ------------------------------------------------------ shared
    switch_lang_title: "ژبه بدله کړئ",
    student: "زده کوونکی",
    cancel: "لغوه کړئ",
    send: "واستوئ",
    sending: "لېږل کېږي…",
    nothing_recorded: "تر اوسه هېڅ نه دي ثبت شوي.",

    // -------------------------------------------------- FamilyHome
    home_greeting: "السلام علیکم",
    home_greeting_named: "السلام علیکم، {x}",
    home_sub: "په دې ښوونځي کې ستاسو د ماشومانو ټولګي.",
    home_empty_title: "دلته تر اوسه هېڅ نشته",
    home_empty_body: "ښوونځي تر اوسه تاسو په هېڅ ټولګي کې نه یاست شامل کړي. که فکر کوئ چې دا سمه نه ده، له دفتر سره اړیکه ونیسئ — هغوی ستاسو ریکارډ لیدلای شي.",
    home_no_consent: "تاسو د ټولګي د خبرونو لپاره رضایت نه دی ورکړی، نو د ټولګي خبرونه پټ دي. د ښوونځي دفتر ستاسو رضایت ثبتولای شي.",
    home_load_error: "موږ اوس ستاسو ټولګي نه شو راوړلای. مهرباني وکړئ بیا هڅه وکړئ.",
    signin_panel_title: "ننوتل",
    signin_panel_has_pw: "تاسو پټنوم ټاکلی دی. بیا هم هر وخت کولای شئ په برېښنالیک کوډ وغواړئ.",
    signin_panel_no_pw: "تاسو د شپږ عددي کوډ په مرسته ننوځئ چې موږ یې تاسو ته په برېښنالیک استوو. که پټنوم غوره ګڼئ، دلته یې ټاکلای شئ — کوډونه به په هر حال کار کوي.",
    pw_new: "نوی پټنوم",
    pw_again: "بیا یې ولیکئ",
    pw_hint: "لږ تر لږه 12 توري. یوه لنډه جمله چې په یاد مو پاتې شي ښه انتخاب دی.",
    pw_saving: "خوندي کېږي…",
    pw_change: "پټنوم بدل کړئ",
    pw_set: "پټنوم وټاکئ",
    pw_change_mine: "زما پټنوم بدل کړئ",
    pw_set_a: "یو پټنوم وټاکئ",
    pw_remove: "لرې یې کړئ",
    pw_mismatch: "دواړه پټنومونه سره یو شان نه وو.",
    pw_saved: "ستاسو پټنوم وټاکل شو. له اوس وروسته پرې ننوتلای شئ.",
    pw_save_failed: "دا پټنوم خوندي نه شو.",
    pw_removed: "ستاسو پټنوم لرې شو. له اوس وروسته د برېښنالیک کوډ په مرسته ننوځئ.",
    pw_remove_failed: "دا لرې نه شو.",

    // ------------------------------------------------ FamilySignIn
    signin_title: "د والدینو ننوتل",
    signin_sub: "موږ تاسو ته یو شپږ عددي کوډ په برېښنالیک استوو. که مو پټنوم ټاکلی وي، د هغه پر ځای یې کارولای شئ.",
    signin_email_label: "ستاسو برېښنالیک پته",
    signin_email_hint: "هغه پته وکاروئ چې ښوونځي ستاسو په ریکارډ کې ثبت کړې ده.",
    signin_password_label: "ستاسو پټنوم",
    signin_submit: "ننوځئ",
    signin_code_instead: "پر ځای یې ما ته کوډ واستوئ",
    signin_email_code: "ما ته کوډ په برېښنالیک واستوئ",
    signin_have_password: "زه پټنوم لرم",
    signin_sent_before: "که",
    signin_sent_after: "زموږ په ریکارډ کې وي، یو شپږ عددي کوډ درته په لاره دی. ژر پای ته رسېږي، او یوازې یو ځل کارول کېدای شي.",
    signin_code_label: "شپږ عددي کوډ",
    signin_other_address: "بله پته وکاروئ",
    signin_code_failed: "دې کوډ کار ونه کړ. کېدای شي پای ته رسېدلی وي یا مخکې کارول شوی وي — نوی کوډ وغواړئ.",
    signin_password_failed: "دا برېښنالیک او پټنوم سره سمون نه خوري. کولای شئ پر ځای یې کوډ وغواړئ.",

    // ------------------------------------------------- StudentMode
    student_back: "د کورنۍ پورټل ته بېرته",
    student_greeting: "السلام علیکم، {x}!",
    student_sub: "وټاکه چې څنګه ښکاره شې.",
    student_who: "ته څوک یې؟",
    student_skin: "ستا پوستکی",
    student_hijab: "ستا حجاب",
    student_kufi: "ستا خولۍ",
    student_saved: "خوندي شو!",
    student_this_is_me: "دا زه یم",
    student_hand_back: "ټیلیفون بېرته ورکړه",
    student_session_over: "د زده کوونکي دا ناسته پای ته ورسېده. له یو مشر څخه وغواړه چې بیا یې پیل کړي.",
    student_error: "یوه ستونزه پېښه شوه. له یو مشر څخه مرسته وغواړه.",
    student_save_failed: "دا خوندي نه شو. له یو مشر څخه مرسته وغواړه.",

    // -------------------------------------------- FamilyAttachment
    attachment: "ضمیمه",

    // ------------------------------------------------ FamilyLayout
    layout_portal: "د کورنۍ پورټل",
    layout_sign_out: "وتل",
    layout_calendar: "د ښوونځي جنتري",

    // ---------------------------------------------- FamilyCalendar
    cal_title: "د ښوونځي جنتري",
    cal_sub: "سږکال د ښوونځي ورځې، او هغه ورځې چې ښوونځی نه وي.",
    cal_back: "زما ټولګیو ته بېرته",
    cal_loading: "د ښوونځي جنتري راوړل کېږي…",
    cal_empty_title: "د ښوونځي جنتري تر اوسه نه ده خپره شوې",
    cal_empty_body: "کله چې ښوونځی خپل تعلیمي کال ور زیات کړي، ورځې به دلته ښکاره شي.",
    cal_load_error: "موږ اوس د ښوونځي جنتري نه شوه راوړلای. مهرباني وکړئ بیا هڅه وکړئ.",
    cal_retry: "بیا هڅه وکړئ",
    cal_upcoming: "راتلونکې ورځې",
    cal_no_upcoming: "سږکال د ښوونځي نورې ورځې نشته.",
    cal_school_day: "د ښوونځي ورځ",
    cal_no_school: "رخصتي",
    cal_today: "نن",
    cal_year: "تعلیمي کال",
    cal_meets_every: "ټولګي هره {x} جوړېږي",
    cal_no_days: "د دې کال لپاره د ښوونځي هېڅ ورځې نه دي ثبت شوې.",

    // --------------------------- translating what the school wrote
    tr_translate: "ژباړه",
    tr_show_original: "اصلي متن وښایاست",
    tr_busy: "د ژباړې خدمت اوس بوخت دی. مهرباني وکړئ یوه دقیقه وروسته بیا هڅه وکړئ.",
    tr_unavailable: "ژباړه اوس مهال شتون نه لري.",
    tr_failed: "دا اوس ونه ژباړل شو.",
    tr_incomplete: "د دې یوه برخه ونه ژباړل شوه او لا هم په هغه ژبه ده چې پرې لیکل شوې وه.",
    tr_machine: "په اتومات ډول ژباړل شوی. د ښوونځي د خپلو خبرو لوستلو لپاره «اصلي متن وښایاست» ټک کړئ.",

    // ------------------------------------------------- FamilyClass
    class_all: "ټول ټولګي",
    class_load_error: "موږ اوس دا ټولګی نه شو راوړلای. مهرباني وکړئ بیا هڅه وکړئ.",
    tab_story: "د ټولګي خبرونه",
    tab_messages: "پیغامونه",
    tab_children: "زما ماشومان",
    tab_reports: "د پایلو کارتونه",
    tab_handouts: "پاڼې",
    handouts_empty: "تر اوسه هېڅ نه دي شریک شوي. هر څه چې ستاسو ښوونکی کور ته استوي، دلته به ښکاره شي.",
    handouts_error: "دا اوس نه شوې راوړل کېدای.",
    handout_download_failed: "دا فایل ښکته نه شو.",
    kb: "KB",
    story_no_consent: "د ټولګي خبرونه پټ دي ځکه چې د ټولګي د خبرونو لپاره ستاسو رضایت ثبت نه دی. د ښوونځي دفتر یې ستاسو لپاره ثبتولای شي.",
    story_empty: "تر اوسه هېڅ نه دي خپاره شوي.",
    the_school: "ښوونځی",
    media_withheld: "په دې لیکنه کې انځورونه پټ دي ځکه چې د انځورونو رضایت ثبت نه دی.",
    message_media_withheld: "په دې پیغام کې انځورونه پټ دي ځکه چې د انځورونو رضایت ثبت نه دی.",
    msg_teacher: "ښوونکي ته پیغام واستوئ",
    compose_about: "د چا په اړه",
    compose_subject: "موضوع",
    compose_subject_ph: "دا د څه په اړه دی؟",
    compose_body_ph: "ښوونکي ته ستاسو پیغام…",
    compose_private: "دا به یوازې ستاسو د ماشوم ښوونکی ویني.",
    compose_rate_limited: "تاسو دمخه څو خبرې اترې پیل کړې دي. مهرباني وکړئ یوه یې دوام کړئ.",
    compose_failed: "دا پیغام ونه لېږل شو.",
    threads_empty: "تر اوسه هېڅ پیغام نشته.",
    thread_untitled: "پیغام",
    thread_new: "نوی",
    thread_about: "د {x} په اړه",
    msgs_one: "{x} پیغام",
    msgs_two: "{x} پیغامونه",
    msgs_few: "{x} پیغامونه",
    msgs_many: "{x} پیغامونه",
    msg_you: "تاسو",
    thread_closed: "ښوونځي دا خبرې اترې بندې کړې دي.",
    reply_ph: "ځواب ولیکئ…",
    reply_failed: "ستاسو ځواب ونه لېږل شو. مهرباني وکړئ بیا هڅه وکړئ.",
    thread_open_failed: "دا خبرې اترې پرانیستل نه شوې.",
    choose_avatar: "یو اوتار وټاکئ",
    handover_starting: "پیلېږي…",
    handover_let: "{x} ته اجازه ورکړئ چې وټاکي",
    handover_failed: "دا پیل نه شو. مهرباني وکړئ بیا هڅه وکړئ.",
    section_behaviour: "چلند",
    section_letters: "توري",
    alphabet_arabic: "عربي توري",
    alphabet_english: "انګلیسي توري",
    stage_letters: "توري",
    stage_short_vowels: "حرکتونه",
    stage_sukun_shadda: "سکون او شد",
    stage_tanween: "تنوین",
    stage_madd: "مد",
    section_quran: "قرآن",
    arabic_letter_notes: "پر تورو د ښوونکي یادښتونه",
    arabic_daily_notes: "پر عربي لوستونو د ښوونکي یادښتونه",
    notes_unavailable: "دا یادښتونه اوس نه شول راوړل کېدای.",
    count_of: "/",
    letter_not_started: "نه دی پیل شوی",
    letter_learning: "زده کوي یې",
    letter_mastered: "زده کړی",
    surah: "سورت",
    reports_all: "ټول راپورونه",
    threads_all: "ټول پیغامونه",
    report_preparing: "چمتو کېږي…",
    report_download: "PDF ښکته کړئ",
    report_sent: "په {x} لېږل شوی",
    level_key_summary: "4، 3، 2 او 1 څه معنا لري؟",
    attendance_present: "حاضر {x}",
    attendance_late: "(په دې کې {x} ځله ځنډ)",
    attendance_absent: "غیر حاضر {x}",
    learning_behaviours: "د زده کړې چلند",
    teacher_comment: "د ښوونکي نظر",
    not_assessed: "نه دی ارزول شوی",
    reports_empty: "تر اوسه هېڅ راپور کور ته نه دی لېږل شوی. کله چې ولېږل شي، دلته به ښکاره شي.",
    reports_partial_error: "ځینې راپورونه اوس نه شول راوړل کېدای.",
    report_open_failed: "دا راپور پرانیستل نه شو.",
    report_download_failed: "دا راپور اوس نه شو ښکته کېدای.",

    // --------------------------------------- marks (the gradebook)
    tab_grades: "نمرې",
    marks_empty: "تر اوسه نمرې نشته. کله چې ستاسو ښوونکی نمره ثبت کړي، دلته به یې ووینئ.",
    marks_error: "ځینې نمرې اوس نه شوې راوړل کېدای.",
    marks_section_points: "د نمرو کارونه",
    marks_section_levels: "د کړنې کچې",
    marks_pieces_one: "په {x} کار کې",
    marks_pieces_two: "په {x} کارونو کې",
    marks_pieces_few: "په {x} کارونو کې",
    marks_pieces_many: "په {x} کارونو کې",
    level_4: "له تمې پورته",
    level_3: "له تمې سره سم",
    level_2: "تمې ته نږدې",
    level_1: "مرستې ته اړتیا لري",
    level_short_4: "پورته",
    level_short_3: "سم",
    level_short_2: "نږدې",
    level_short_1: "مرسته پکار",
    level_desc_4: "په دوامداره توګه مهارت ښيي؛ مهارتونه په خپلواکه توګه کاروي؛ دقت، ژوروالی او باور ښيي.",
    level_desc_3: "د خپل ټولګي د کچې وړتیا ښيي؛ دندې په لږه مرسته بشپړوي؛ ښه پوهه لري.",
    level_desc_2: "نیمګړې پوهه؛ مرستې یا یادونې ته اړتیا لري؛ کړنه یې یو شان نه ده.",
    level_desc_1: "محدوده پوهه؛ ډېرې لارښوونې ته اړتیا لري؛ مهارتونه یې لا نه دي جوړ شوي.",
    marks_levels_mean: "منځنۍ کچه {x}",
    mark_missing: "نه دی سپارل شوی",
    mark_excused: "معاف",
    marks_truncated: "وروستي {x} ښودل کېږي.",
};
