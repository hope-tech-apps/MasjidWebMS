/**
 * Spanish (Español) — parent portal labels.
 *
 * ============================================================================
 * MACHINE-DRAFTED. NOT YET REVIEWED BY A FLUENT SPANISH SPEAKER.
 * ============================================================================
 *
 * Drafted 2026-09-21 from the English column of familyI18n.ts, at the owner's
 * request ("Add them, portal labels too"), so that a parent who reads Spanish
 * can find their way round the portal. It has not been read by anybody who
 * speaks the language. Until it has, treat every line as a best guess: have a
 * fluent reader — ideally a parent or teacher at the school — go through the
 * whole file against the English, then set `reviewed: true` for "es" in
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
export const ES: Record<string, string> = {
    // ------------------------------------------------------ shared
    switch_lang_title: "Cambiar idioma",
    student: "Estudiante",
    cancel: "Cancelar",
    send: "Enviar",
    sending: "Enviando…",
    nothing_recorded: "Todavía no hay nada registrado.",

    // -------------------------------------------------- FamilyHome
    home_greeting: "Assalamu alaikum",
    home_greeting_named: "Assalamu alaikum, {x}",
    home_sub: "Las clases de sus hijos en esta escuela.",
    home_empty_title: "Todavía no hay nada aquí",
    home_empty_body: "La escuela aún no lo ha agregado a ninguna clase. Si cree que es un error, comuníquese con la oficina: ellos pueden ver su registro.",
    home_no_consent: "No ha dado su consentimiento para recibir las novedades de la clase, por eso están ocultas. La oficina de la escuela puede registrar su consentimiento.",
    home_load_error: "No pudimos cargar sus clases en este momento. Inténtelo de nuevo.",
    signin_panel_title: "Inicio de sesión",
    signin_panel_has_pw: "Tiene una contraseña configurada. Aun así, puede pedir un código por correo electrónico en cualquier momento.",
    signin_panel_no_pw: "Usted inicia sesión con un código de seis dígitos que le enviamos por correo electrónico. Si prefiere usar una contraseña, puede configurarla aquí; los códigos seguirán funcionando de todas formas.",
    pw_new: "Nueva contraseña",
    pw_again: "Escríbala de nuevo",
    pw_hint: "Al menos 12 caracteres. Una frase corta que pueda recordar funciona bien.",
    pw_saving: "Guardando…",
    pw_change: "Cambiar contraseña",
    pw_set: "Configurar contraseña",
    pw_change_mine: "Cambiar mi contraseña",
    pw_set_a: "Configurar una contraseña",
    pw_remove: "Quitarla",
    pw_mismatch: "Las dos contraseñas no coinciden.",
    pw_saved: "Su contraseña quedó configurada. A partir de ahora puede iniciar sesión con ella.",
    pw_save_failed: "No se pudo guardar esa contraseña.",
    pw_removed: "Se quitó su contraseña. A partir de ahora inicie sesión con un código enviado por correo electrónico.",
    pw_remove_failed: "No se pudo quitar.",

    // ------------------------------------------------ FamilySignIn
    signin_title: "Acceso para padres",
    signin_sub: "Le enviamos un código de seis dígitos por correo electrónico. Si configuró una contraseña, puede usarla en su lugar.",
    signin_email_label: "Su correo electrónico",
    signin_email_hint: "Use la dirección que la escuela tiene registrada para usted.",
    signin_password_label: "Su contraseña",
    signin_submit: "Iniciar sesión",
    signin_code_instead: "Mejor envíenme un código por correo",
    signin_email_code: "Envíenme un código por correo",
    signin_have_password: "Tengo una contraseña",
    signin_sent_before: "Si",
    signin_sent_after: "está registrado, le enviamos un código de seis dígitos. Caduca pronto y solo se puede usar una vez.",
    signin_code_label: "Código de seis dígitos",
    signin_other_address: "Usar otra dirección",
    signin_code_failed: "Ese código no funcionó. Puede que haya caducado o que ya se haya usado; pida uno nuevo.",
    signin_password_failed: "Ese correo y esa contraseña no coinciden. Puede pedir un código en su lugar.",

    // ------------------------------------------------- StudentMode
    student_back: "Volver al portal familiar",
    student_greeting: "¡Assalamu alaikum, {x}!",
    student_sub: "Elige cómo quieres verte.",
    student_who: "¿Quién eres?",
    student_skin: "Tu piel",
    student_hijab: "Tu hijab",
    student_kufi: "Tu kufi",
    student_saved: "¡Guardado!",
    student_this_is_me: "Este soy yo",
    student_hand_back: "Devuelve el teléfono",
    student_session_over: "Esta sesión de estudiante terminó. Pide a un adulto que la vuelva a iniciar.",
    student_error: "Algo salió mal. Pide ayuda a un adulto.",
    student_save_failed: "No se pudo guardar. Pide ayuda a un adulto.",

    // -------------------------------------------- FamilyAttachment
    attachment: "Archivo adjunto",

    // ------------------------------------------------ FamilyLayout
    layout_portal: "Portal familiar",
    layout_sign_out: "Cerrar sesión",
    layout_calendar: "Calendario escolar",

    // ---------------------------------------------- FamilyCalendar
    cal_title: "Calendario escolar",
    cal_sub: "Los días de clase de este año y los días sin clase.",
    cal_back: "Volver a mis clases",
    cal_loading: "Cargando el calendario escolar…",
    cal_empty_title: "El calendario escolar aún no se ha publicado",
    cal_empty_body: "Cuando la escuela agregue su año escolar, los días aparecerán aquí.",
    cal_load_error: "No pudimos cargar el calendario escolar en este momento. Inténtelo de nuevo.",
    cal_retry: "Intentar de nuevo",
    cal_upcoming: "Próximos días",
    cal_no_upcoming: "No quedan más días de clase este año.",
    cal_school_day: "Día de clase",
    cal_no_school: "Sin clase",
    cal_today: "Hoy",
    cal_year: "Año escolar",
    cal_meets_every: "Hay clase cada {x}",
    cal_no_days: "No hay días de clase registrados para este año.",

    // --------------------------- translating what the school wrote
    tr_translate: "Traducir",
    tr_show_original: "Ver original",
    tr_busy: "El servicio de traducción está ocupado en este momento. Inténtelo de nuevo en un minuto.",
    tr_unavailable: "La traducción no está disponible en este momento.",
    tr_failed: "No se pudo traducir en este momento.",
    tr_incomplete: "Parte de esto no se pudo traducir y sigue en el idioma en que se escribió.",
    tr_machine: "Traducción automática. Toque «Ver original» para leer las palabras de la propia escuela.",

    // ------------------------------------------------- FamilyClass
    class_all: "Todas las clases",
    class_load_error: "No pudimos cargar esta clase en este momento. Inténtelo de nuevo.",
    tab_story: "Novedades de la clase",
    tab_messages: "Mensajes",
    tab_children: "Mis hijos",
    tab_reports: "Boletines",
    tab_handouts: "Materiales",
    handouts_empty: "Todavía no se ha compartido nada. Todo lo que el maestro envíe a casa aparecerá aquí.",
    handouts_error: "No se pudieron cargar en este momento.",
    handout_download_failed: "No se pudo descargar ese archivo.",
    kb: "KB",
    story_no_consent: "Las novedades de la clase están ocultas porque no consta su consentimiento para recibirlas. La oficina de la escuela puede registrarlo por usted.",
    story_empty: "Todavía no se ha publicado nada.",
    the_school: "La escuela",
    media_withheld: "Las fotos de esta publicación están ocultas porque no consta el consentimiento para fotos.",
    message_media_withheld: "Las fotos de este mensaje están ocultas porque no consta el consentimiento para fotos.",
    msg_teacher: "Escribir al maestro",
    compose_about: "Sobre",
    compose_subject: "Asunto",
    compose_subject_ph: "¿De qué se trata?",
    compose_body_ph: "Su mensaje para el maestro…",
    compose_private: "Solo el maestro de su hijo verá esto.",
    compose_rate_limited: "Ya inició varias conversaciones. Por favor, continúe una de ellas.",
    compose_failed: "No se pudo enviar ese mensaje.",
    threads_empty: "Todavía no hay mensajes.",
    thread_untitled: "Mensaje",
    thread_new: "Nuevo",
    thread_about: "Sobre {x}",
    msgs_one: "{x} mensaje",
    msgs_two: "{x} mensajes",
    msgs_few: "{x} mensajes",
    msgs_many: "{x} mensajes",
    msg_you: "Usted",
    thread_closed: "La escuela cerró esta conversación.",
    reply_ph: "Escriba una respuesta…",
    reply_failed: "No se pudo enviar su respuesta. Inténtelo de nuevo.",
    thread_open_failed: "No se pudo abrir esa conversación.",
    choose_avatar: "Elegir un avatar",
    handover_starting: "Iniciando…",
    handover_let: "Dejar que {x} elija",
    handover_failed: "No se pudo iniciar. Inténtelo de nuevo.",
    section_behaviour: "Comportamiento",
    section_letters: "Letras",
    alphabet_arabic: "Letras árabes",
    alphabet_english: "Letras en inglés",
    stage_letters: "Las letras",
    stage_short_vowels: "Vocales cortas",
    stage_sukun_shadda: "Sukun y Shadda",
    stage_tanween: "Tanween",
    stage_madd: "Vocales largas",
    section_quran: "Qur'an",
    arabic_letter_notes: "Notas del maestro sobre las letras",
    arabic_daily_notes: "Notas del maestro sobre las clases de árabe",
    notes_unavailable: "No se pudieron cargar estas notas en este momento.",
    count_of: "de",
    letter_not_started: "sin empezar",
    letter_learning: "aprendiendo",
    letter_mastered: "dominada",
    surah: "Surah",
    reports_all: "Todos los boletines",
    threads_all: "Todos los mensajes",
    report_preparing: "Preparando…",
    report_download: "Descargar PDF",
    report_sent: "Enviado el {x}",
    level_key_summary: "¿Qué significan 4, 3, 2 y 1?",
    attendance_present: "Presente {x}",
    attendance_late: "(incluye {x} tardanzas)",
    attendance_absent: "Ausente {x}",
    learning_behaviours: "Hábitos de aprendizaje",
    teacher_comment: "Comentario del maestro",
    not_assessed: "Sin evaluar",
    reports_empty: "Todavía no se ha enviado ningún boletín a casa. Cuando se envíe, aparecerá aquí.",
    reports_partial_error: "Algunos boletines no se pudieron cargar en este momento.",
    report_open_failed: "No se pudo abrir ese boletín.",
    report_download_failed: "No se pudo descargar ese boletín en este momento.",

    // --------------------------------------- marks (the gradebook)
    tab_grades: "Notas",
    marks_empty: "Todavía no hay notas. Cuando el maestro registre una, la verá aquí.",
    marks_error: "Algunas notas no se pudieron cargar en este momento.",
    marks_section_points: "Trabajos con puntos",
    marks_section_levels: "Niveles de desempeño",
    marks_pieces_one: "en {x} trabajo",
    marks_pieces_two: "en {x} trabajos",
    marks_pieces_few: "en {x} trabajos",
    marks_pieces_many: "en {x} trabajos",
    level_4: "Supera las expectativas",
    level_3: "Cumple las expectativas",
    level_2: "Se acerca a las expectativas",
    level_1: "Necesita apoyo",
    level_short_4: "Supera",
    level_short_3: "Cumple",
    level_short_2: "Se acerca",
    level_short_1: "Necesita apoyo",
    level_desc_4: "Demuestra dominio de forma constante; aplica las habilidades con independencia; muestra precisión, profundidad y confianza.",
    level_desc_3: "Demuestra el dominio esperado para su grado; completa las tareas con un apoyo mínimo; muestra una comprensión sólida.",
    level_desc_2: "Comprensión parcial; necesita apoyo o recordatorios; desempeño irregular.",
    level_desc_1: "Comprensión limitada; requiere mucha orientación; las habilidades aún no se han desarrollado.",
    marks_levels_mean: "Nivel promedio {x}",
    mark_missing: "No entregado",
    mark_excused: "Exento",
    marks_truncated: "Se muestran los {x} más recientes.",
};
