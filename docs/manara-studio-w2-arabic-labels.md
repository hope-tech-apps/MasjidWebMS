# Studio W2 S12: Arabic starter labels, a DRAFT for review

**Status: draft, NOT loaded by the app.** `config/studio_layouts.php` has no `labels.ar`,
so Studio does not offer an Arabic website yet (the slice ships dark). The owner decided on
2026-09-24 that the Arabic labels are reviewed before they ship (`docs/manara-studio-w2.md`
§8, "Arabic labels: the owner reviews them"). This table is the proposal for that review.
It was drafted by the build session, not by a fluent reader, so treat every row as a
suggestion.

**What these are.** Interface words only: page names, section headings, button and link
text that a new Studio organisation's starter website is written with (D8: nothing about a
congregation is invented). They contain no digits. The site's own chrome (menus, dates,
"Read more" inside components) comes from the renderer's Arabic catalogue, which already
exists and is not part of this table.

**How it ships once approved.**
1. The reviewer corrects the right-hand column (and names themselves and the date).
2. The approved strings go into `config/studio_layouts.php` as `labels.ar`, with exactly the
   35 keys of `labels.en` (`StudioLayoutPresetsTest::an_arabic_label_table_when_present_has_exactly_the_english_keys`
   fails otherwise), and a config comment records who reviewed it and when.
3. From that deploy, Studio's Step 0 offers "Arabic" (`LayoutPresets::websiteLocales`), and
   Step 3 accepts `website_locale: ar`.

| Key | English | Proposed Arabic (draft) | Note for the reviewer |
|---|---|---|---|
| page.home | Home | الرئيسية | |
| page.about | About | من نحن | |
| page.contact | Contact | اتصل بنا | |
| page.donate | Donate | تبرّع | Imperative; "التبرعات" (Donations) is the noun alternative. |
| page.events | Events | الفعاليات | |
| page.announcements | Announcements | الإعلانات | |
| page.gallery | Gallery | معرض الصور | |
| page.programs | Programs | البرامج | |
| page.staff | Staff | الكادر | Schools; "فريق العمل" is the alternative. |
| page.admissions | Admissions | القبول والتسجيل | |
| page.services | Services | الخدمات | |
| page.team | Team | الفريق | Community organisations. |
| heading.prayer_times | Prayer Times | مواقيت الصلاة | |
| heading.about | About Us | من نحن | |
| heading.mission_vision | Mission and Vision | الرسالة والرؤية | |
| heading.contact | Contact Us | تواصل معنا | |
| heading.events | Events | الفعاليات | |
| heading.announcements | Announcements | الإعلانات | |
| heading.gallery | Gallery | معرض الصور | |
| heading.connect | Connect | تابعونا | The social-links block; literally "Follow us". |
| heading.programs | Programs | البرامج | |
| heading.staff | Staff | الكادر | Same choice as page.staff. |
| heading.admissions | Admissions | القبول والتسجيل | |
| heading.services | Services | الخدمات | |
| heading.team | Team | الفريق | |
| button.read_more | Read More | اقرأ المزيد | |
| button.contact | Contact Us | تواصل معنا | |
| button.apply | Apply | قدّم طلبك | Admissions button; "سجّل الآن" (Register now) is the alternative. |
| button.donate | Donate | تبرّع | |
| link.call | Call | اتصل | |
| link.email | Email | البريد الإلكتروني | |
| link.facebook | Facebook | فيسبوك | Brand names transliterated; keeping the Latin names is also usual. |
| link.instagram | Instagram | إنستغرام | |
| link.youtube | YouTube | يوتيوب | |
| link.whatsapp | WhatsApp | واتساب | |

Reviewed by: ________ on ________ (owner to name the reviewer).
