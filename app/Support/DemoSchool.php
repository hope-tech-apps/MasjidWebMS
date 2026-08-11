<?php

namespace App\Support;

use App\Models\BehaviorSkill;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupThread;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\Offering;

/**
 * Al-Razi Islamic School — the DEMO tenant's marker and its blueprint.
 *
 * This class holds two things and nothing else: the MARKER that makes every row
 * the demo seeder writes identifiable, and the fictional data it writes. The
 * machinery that writes it is App\Support\DemoSchoolSeeder; the only way to run
 * either is `php artisan demo:seed-school`, which is never called by
 * DatabaseSeeder, by provisioning, or by anything that runs on its own.
 *
 * ## THE MARKER, and why it is an email domain
 *
 * Every person this seeder creates — the tenant itself, the staff `users`, and
 * every `contacts` row — carries an address under `al-razi-demo.invalid`.
 *
 *  - **`.invalid` is reserved by RFC 2606** and is guaranteed never to resolve.
 *    Nothing at this domain can receive mail, so the demo cannot email a real
 *    person even if something in the app tried to.
 *  - **It survives editing.** A demoer renaming "Grade 3 — Boys" to "Room 4",
 *    or the school itself to something catchier, must not make the rollback
 *    stop recognising its own rows — which is exactly what a NAME PREFIX marker
 *    ("[DEMO] …") would do the first time somebody tidied a label up for a
 *    screenshot. An address is infrastructure, not copy; nobody edits it to
 *    make a screenshot look better.
 *  - **It needs no schema change.** A `is_demo` metadata flag would mean a
 *    migration on `masjids` and on every table underneath it, and a demo
 *    fixture is not a reason to widen the tenant root. `masjids.email` is
 *    already UNIQUE, so `TENANT_EMAIL` names exactly one row or none.
 *
 * The tenant is the scope. Everything except the staff `users` rows hangs off
 * `masjid_id`, so "delete exactly what the demo created" is "delete the rows of
 * the marker tenant" — a set the database itself defines. `users` has no
 * `masjid_id` column in this schema, which is why staff accounts are recognised
 * by the marker DOMAIN instead; no real account can hold an unroutable address.
 *
 * Nothing here is real. The names are invented, the phone numbers are all in
 * the NANP's reserved 555-01xx fictional range, and the addresses are at a
 * domain that cannot exist.
 */
final class DemoSchool
{
    // ------------------------------------------------------------------
    // The marker
    // ------------------------------------------------------------------

    /** The reserved (RFC 2606) namespace every demo address lives under. */
    public const DOMAIN = 'al-razi-demo.invalid';

    /** Students get their own sub-domain, exactly as a real school issues one. */
    public const STUDENT_DOMAIN = 'students.' . self::DOMAIN;

    /** The tenant's own address — UNIQUE on `masjids`, so it names one row. */
    public const TENANT_EMAIL = 'office@' . self::DOMAIN;

    public const TENANT_NAME = 'Al-Razi Islamic School';

    /**
     * Reserved fictional phone block: NANP 555-0100…555-0199 is set aside for
     * fiction, so no number this seeder writes can ring a real handset. The
     * blueprint stays well inside the block (fewer than 100 people).
     */
    public const PHONE_PREFIX = '+1905555';

    public const PHONE_FIRST = 100;

    /**
     * Is this address inside the demo namespace?
     *
     * Anchored on the separator so a lookalike domain (`notal-razi-demo.invalid`)
     * cannot slip through a bare `str_ends_with`.
     */
    public static function isDemoEmail(?string $email): bool
    {
        if (! is_string($email) || $email === '') {
            return false;
        }

        $email = strtolower(trim($email));

        return str_ends_with($email, '@' . self::DOMAIN)
            || str_ends_with($email, '.' . self::DOMAIN);
    }

    /** `Yusuf Karim` -> `yusuf.karim@students.al-razi-demo.invalid`. */
    public static function email(string $first, string $last, bool $student = false): string
    {
        $local = strtolower(preg_replace('/[^a-z]+/i', '', $first) . '.' . preg_replace('/[^a-z]+/i', '', $last));

        return $local . '@' . ($student ? self::STUDENT_DOMAIN : self::DOMAIN);
    }

    /** A number from the reserved fictional block, stable for a given index. */
    public static function phone(int $index): string
    {
        return self::PHONE_PREFIX . str_pad((string) (self::PHONE_FIRST + $index), 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // The tenant
    // ------------------------------------------------------------------

    /**
     * The provisioning payload, minus the geography, which the command resolves
     * from the rows that already exist (see DemoSchoolSeeder::seed).
     *
     * `org_type = school` is the whole point: provisioning through the real
     * wizard endpoint is what gives the tenant the school FEATURE BUNDLE (no
     * worship modules), the school TERMINOLOGY pack ("Families", "Classrooms")
     * and the three seeded school FORMS, without this file knowing any of it.
     *
     * @return array<string,mixed>
     */
    public static function provisionPayload(): array
    {
        return [
            'org_type' => Masjid::ORG_TYPE_SCHOOL,
            'name' => self::TENANT_NAME,
            'email' => self::TENANT_EMAIL,
            'phone' => self::phone(0),
            'address' => '480 Rayyan Avenue, Burlington, ON',
            'latitude' => 43.3255,
            'longitude' => -79.7990,
            'timezone' => 'America/Toronto',
            'about' => 'Al-Razi Islamic School is a fictional K–8 school used to demonstrate Manara. '
                . 'Everything you see here — the families, the classrooms, the recitation records — is invented.',
            'mission' => 'To raise a generation grounded in the Qur\'an, confident in the sciences, and gentle with people.',
            'vision' => 'A school where every child is known by name, and every family is a partner.',
            'brand' => [
                'primary_color' => '#134E4A',
                'secondary_color' => '#CAA04A',
                'accent_color' => '#0F766E',
                'background_color' => '#F5F1E6',
            ],
            // Prayer settings are required by the wizard for every vertical.
            // A school tenant never loads the worship modules, so these are
            // inert configuration — they exist because provisioning asks.
            'method' => 'NorthAmerica',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
            'jumaa_iqama' => '13:15',
            'platforms' => ['web'],
        ];
    }

    // ------------------------------------------------------------------
    // People
    // ------------------------------------------------------------------

    /**
     * Staff. Each one becomes BOTH a `users` row (so they can author posts and
     * messages — a Contact cannot authenticate anywhere in this application)
     * and a `contacts` row (so they can hold a leader membership, because a
     * membership references a Contact and never duplicates a person).
     *
     * The two rows share an address ON PURPOSE: App\Support\GroupAudience
     * resolves "which person is this caller" by matching the login email to a
     * Contact of the bound tenant, so a teacher whose User and Contact
     * disagreed would be able to publish to their own classroom and not read it
     * back. See .claude/rules/groups.md.
     *
     * @return array<string,array{first:string,last:string,title:string,principal?:bool}>
     */
    public static function staff(): array
    {
        return [
            'khadija' => ['first' => 'Khadija', 'last' => 'Nasser', 'title' => 'Principal', 'principal' => true],
            'tariq' => ['first' => 'Tariq', 'last' => 'Mansour', 'title' => 'Grade 3 Teacher'],
            'hafsa' => ['first' => 'Hafsa', 'last' => 'Iqbal', 'title' => 'Grade 5 Teacher'],
            'bilal' => ['first' => 'Bilal', 'last' => 'Toure', 'title' => 'Ḥifẓ Instructor'],
        ];
    }

    /**
     * Guardians, keyed so one parent shared between two classrooms is ONE
     * contact holding TWO edges — which is the case guardian edges exist for.
     *
     * @return array<string,array{first:string,last:string}>
     */
    public static function guardians(): array
    {
        return [
            'karim-rashid' => ['first' => 'Rashid', 'last' => 'Karim'],
            'karim-sumayya' => ['first' => 'Sumayya', 'last' => 'Karim'],
            'ansari-imran' => ['first' => 'Imran', 'last' => 'Ansari'],
            'ansari-nadia' => ['first' => 'Nadia', 'last' => 'Ansari'],
            'haddad-omar' => ['first' => 'Omar', 'last' => 'Haddad'],
            'haddad-amina' => ['first' => 'Amina', 'last' => 'Haddad'],
            'rahman-fuad' => ['first' => 'Fuad', 'last' => 'Rahman'],
            'diallo-mamadou' => ['first' => 'Mamadou', 'last' => 'Diallo'],
            'diallo-fatou' => ['first' => 'Fatou', 'last' => 'Diallo'],
            'shah-adeel' => ['first' => 'Adeel', 'last' => 'Shah'],
            'shah-ruqayya' => ['first' => 'Ruqayya', 'last' => 'Shah'],
            'bello-ibrahim' => ['first' => 'Ibrahim', 'last' => 'Bello'],
            'toure-aminata' => ['first' => 'Aminata', 'last' => 'Toure'],
            'sultana-bilqis' => ['first' => 'Bilqis', 'last' => 'Sultana'],
            'osman-layla' => ['first' => 'Layla', 'last' => 'Osman'],
            'osman-idris' => ['first' => 'Idris', 'last' => 'Osman'],
            'qureshi-sana' => ['first' => 'Sana', 'last' => 'Qureshi'],
            'najjar-huda' => ['first' => 'Huda', 'last' => 'Najjar'],
            'karimov-timur' => ['first' => 'Timur', 'last' => 'Karimov'],
            'siddiqui-junaid' => ['first' => 'Junaid', 'last' => 'Siddiqui'],
            'mahmood-rehana' => ['first' => 'Rehana', 'last' => 'Mahmood'],
        ];
    }

    /**
     * The 20 students, keyed. A student appears in as many rosters as they
     * belong to; the key is what makes "Yusuf Karim in Grade 3 AND in the
     * ḥalaqa" one contact with two memberships rather than two children.
     *
     * @return array<string,array{first:string,last:string}>
     */
    public static function students(): array
    {
        return [
            'yusuf-karim' => ['first' => 'Yusuf', 'last' => 'Karim'],
            'bilal-ansari' => ['first' => 'Bilal', 'last' => 'Ansari'],
            'idris-haddad' => ['first' => 'Idris', 'last' => 'Haddad'],
            'musa-rahman' => ['first' => 'Musa', 'last' => 'Rahman'],
            'adam-diallo' => ['first' => 'Adam', 'last' => 'Diallo'],
            'zayd-shah' => ['first' => 'Zayd', 'last' => 'Shah'],
            'harun-bello' => ['first' => 'Harun', 'last' => 'Bello'],
            'ilyas-toure' => ['first' => 'Ilyas', 'last' => 'Toure'],
            'maryam-karim' => ['first' => 'Maryam', 'last' => 'Karim'],
            'aisha-ansari' => ['first' => 'Aisha', 'last' => 'Ansari'],
            'safiyya-haddad' => ['first' => 'Safiyya', 'last' => 'Haddad'],
            'khadijah-sultana' => ['first' => 'Khadijah', 'last' => 'Sultana'],
            'ruqayyah-diallo' => ['first' => 'Ruqayyah', 'last' => 'Diallo'],
            'hafsah-osman' => ['first' => 'Hafsah', 'last' => 'Osman'],
            'sumayyah-qureshi' => ['first' => 'Sumayyah', 'last' => 'Qureshi'],
            'zahra-najjar' => ['first' => 'Zahra', 'last' => 'Najjar'],
            'anas-karimov' => ['first' => 'Anas', 'last' => 'Karimov'],
            'sulayman-bello' => ['first' => 'Sulayman', 'last' => 'Bello'],
            'ammar-siddiqui' => ['first' => 'Ammar', 'last' => 'Siddiqui'],
            'talha-mahmood' => ['first' => 'Talha', 'last' => 'Mahmood'],
        ];
    }

    // ------------------------------------------------------------------
    // Classrooms
    // ------------------------------------------------------------------

    /**
     * The rosters.
     *
     * `roster` maps a student key to their guardian edges: guardian key =>
     * consent scope, where `null` means NO consent record. Absence of a record
     * means no consent (.claude/rules/groups.md), and one edge is deliberately
     * left at null so the consent gate can actually be demonstrated rather than
     * described — Harun Bello's father in Grade 3.
     *
     * That the SAME father consents for his other son in the ḥalaqa is also
     * deliberate: consent is recorded per (guardian, ward, group) edge, so it
     * cannot leak sideways between a parent's children or their groups.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function classrooms(): array
    {
        return [
            [
                'slug' => 'grade-3-boys',
                'name' => 'Grade 3 — Boys',
                'kind' => Group::KIND_CLASS,
                'description' => 'Homeroom for Grade 3 boys. Qur\'an, Islamic studies, and the Ontario core curriculum.',
                'leader' => 'tariq',
                'roster' => [
                    'yusuf-karim' => ['karim-rashid' => GroupMembership::CONSENT_MEDIA, 'karim-sumayya' => GroupMembership::CONSENT_FEED],
                    'bilal-ansari' => ['ansari-imran' => GroupMembership::CONSENT_MEDIA],
                    'idris-haddad' => ['haddad-omar' => GroupMembership::CONSENT_FEED, 'haddad-amina' => GroupMembership::CONSENT_MEDIA],
                    'musa-rahman' => ['rahman-fuad' => GroupMembership::CONSENT_MEDIA],
                    'adam-diallo' => ['diallo-mamadou' => GroupMembership::CONSENT_FEED],
                    'zayd-shah' => ['shah-adeel' => GroupMembership::CONSENT_MEDIA, 'shah-ruqayya' => GroupMembership::CONSENT_FEED],
                    // THE CONSENT GATE, made demonstrable: no record at all.
                    'harun-bello' => ['bello-ibrahim' => null],
                    'ilyas-toure' => ['toure-aminata' => GroupMembership::CONSENT_FEED],
                ],
            ],
            [
                'slug' => 'grade-5-girls',
                'name' => 'Grade 5 — Girls',
                'kind' => Group::KIND_CLASS,
                'description' => 'Homeroom for Grade 5 girls. Seerah, sciences, and the Ontario core curriculum.',
                'leader' => 'hafsa',
                'roster' => [
                    'maryam-karim' => ['karim-rashid' => GroupMembership::CONSENT_MEDIA, 'karim-sumayya' => GroupMembership::CONSENT_MEDIA],
                    'aisha-ansari' => ['ansari-nadia' => GroupMembership::CONSENT_FEED],
                    'safiyya-haddad' => ['haddad-amina' => GroupMembership::CONSENT_MEDIA],
                    'khadijah-sultana' => ['sultana-bilqis' => GroupMembership::CONSENT_MEDIA],
                    'ruqayyah-diallo' => ['diallo-fatou' => GroupMembership::CONSENT_FEED],
                    'hafsah-osman' => ['osman-layla' => GroupMembership::CONSENT_MEDIA, 'osman-idris' => GroupMembership::CONSENT_FEED],
                    'sumayyah-qureshi' => ['qureshi-sana' => GroupMembership::CONSENT_FEED],
                    'zahra-najjar' => ['najjar-huda' => GroupMembership::CONSENT_MEDIA],
                ],
            ],
            [
                'slug' => 'hifz-halaqa',
                'name' => 'Ḥifẓ Ḥalaqa',
                'kind' => Group::KIND_HALAQA,
                'description' => 'Daily memorisation circle. Sabak after Fajr break, sabqi and manzil before dismissal.',
                'leader' => 'bilal',
                'roster' => [
                    'yusuf-karim' => ['karim-rashid' => GroupMembership::CONSENT_MEDIA],
                    'maryam-karim' => ['karim-sumayya' => GroupMembership::CONSENT_MEDIA],
                    'musa-rahman' => ['rahman-fuad' => GroupMembership::CONSENT_FEED],
                    'hafsah-osman' => ['osman-layla' => GroupMembership::CONSENT_MEDIA],
                    'anas-karimov' => ['karimov-timur' => GroupMembership::CONSENT_MEDIA],
                    // Same father as Harun in Grade 3, who consented to nothing
                    // there: consent is per EDGE, so the two never bleed.
                    'sulayman-bello' => ['bello-ibrahim' => GroupMembership::CONSENT_MEDIA],
                    'ammar-siddiqui' => ['siddiqui-junaid' => GroupMembership::CONSENT_FEED],
                    'talha-mahmood' => ['mahmood-rehana' => GroupMembership::CONSENT_MEDIA],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // The class story
    // ------------------------------------------------------------------

    /**
     * Feed posts per classroom slug. `image` marks the post that carries a
     * photograph — written to the PRIVATE disk through the same
     * App\Support\GroupPostAttachments the upload endpoint uses, never placed
     * on disk by hand.
     *
     * @return array<string,array<int,array{title:string,body:string,days_ago:int,image?:bool}>>
     */
    public static function posts(): array
    {
        return [
            'grade-3-boys' => [
                [
                    'title' => 'Ṣalāh practice and a new sūrah',
                    'body' => "We spent the first half of the morning on wuḍūʾ and ṣalāh practice — everyone led at least one rakʿah for a partner. "
                        . "After the break we started Sūrat al-Aʿlā; please have them read the first five āyāt aloud at home three times this week.\n\n"
                        . "A photo from the practice mats is attached.",
                    'days_ago' => 2,
                    'image' => true,
                ],
                [
                    'title' => 'Math games day',
                    'body' => "Multiplication relay races today. The boys worked in pairs and got through the 6 and 7 times tables faster than any class I have taught. "
                        . "Homework is the green worksheet, due Thursday.",
                    'days_ago' => 6,
                ],
                [
                    'title' => 'Field trip forms — please return by Friday',
                    'body' => "Our trip to the conservation area is on the 24th. Forms went home in the blue folders today. "
                        . "If a form is lost, let me know and I will send another; we cannot take a student without one.",
                    'days_ago' => 11,
                ],
            ],
            'grade-5-girls' => [
                [
                    'title' => 'Seerah project presentations',
                    'body' => "Every group presented their poster on the Madinah period today. The work on the Constitution of Madinah was outstanding — "
                        . "they explained the treaty terms in their own words without reading from the poster.\n\nPhoto of the display wall attached.",
                    'days_ago' => 3,
                    'image' => true,
                ],
                [
                    'title' => 'Science: building water filters',
                    'body' => "We built filters from sand, gravel and cotton and tested them on muddy water. Ask your daughter which layer did the most work — "
                        . "most of them were surprised by the answer.",
                    'days_ago' => 8,
                ],
                [
                    'title' => 'Library books due Thursday',
                    'body' => "A reminder that this term's library books come back on Thursday. New borrowing opens the same afternoon.",
                    'days_ago' => 13,
                ],
            ],
            'hifz-halaqa' => [
                [
                    'title' => 'Juz 30 milestone',
                    'body' => "Four of the students finished their sabak through Sūrat al-Aʿlā this week and two completed Sūrat al-ʿAlaq. "
                        . "We marked it with dates and zamzam after ẓuhr.\n\nA photo from the circle is attached.",
                    'days_ago' => 4,
                    'image' => true,
                ],
                [
                    'title' => 'Revision rota for the new term',
                    'body' => "From next week: sabak every morning, sabqi Monday to Wednesday, manzil on Thursday. "
                        . "Please keep the manzil portion going at home over the weekend — it is the part that slips first.",
                    'days_ago' => 9,
                ],
            ],
        ];
    }

    /**
     * Messaging threads per classroom slug.
     *
     * Every message author is a `users` row, because `group_messages` attributes
     * to a User and a Contact cannot authenticate anywhere in this application
     * yet (the parent/teacher app is a later slice). So these read as the
     * school's side of a conversation whose audience is the guardian — which is
     * exactly what a participant-scoped thread is for.
     *
     * `about` names a student key on a participant thread, and is absent on a
     * group-wide one.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function threads(): array
    {
        return [
            'grade-3-boys' => [
                [
                    'subject' => 'Harun\'s reading at home',
                    'scope' => GroupThread::SCOPE_PARTICIPANT,
                    'about' => 'harun-bello',
                    'opened_by' => 'tariq',
                    'messages' => [
                        ['author' => 'tariq', 'days_ago' => 7, 'body' => 'Assalamu alaykum. Harun is doing well in class but rushes when he reads aloud. Ten minutes of reading at home each evening would help a lot — any book he enjoys is fine.'],
                        ['author' => 'tariq', 'days_ago' => 5, 'body' => 'Following up on the note above: I have put a short reader in his bag today. No need to send it back.'],
                        ['author' => 'khadija', 'days_ago' => 4, 'body' => 'Adding myself so the office knows this is in hand. Happy to arrange a phone call this week if that is easier than writing.'],
                    ],
                ],
            ],
            'grade-5-girls' => [
                [
                    'subject' => 'Science centre trip — your questions',
                    'scope' => GroupThread::SCOPE_GROUP,
                    'opened_by' => 'hafsa',
                    'messages' => [
                        ['author' => 'hafsa', 'days_ago' => 6, 'body' => 'A few families have asked about the trip. We leave at 8:30 and are back before dismissal. Lunch is provided; please tell me about any allergies.'],
                        ['author' => 'hafsa', 'days_ago' => 5, 'body' => 'Two questions came up: yes, ṣalāt al-ẓuhr will be prayed there, and no, the girls do not need money — everything is covered.'],
                        ['author' => 'khadija', 'days_ago' => 3, 'body' => 'The bus company has confirmed. If you have not returned a form yet, Friday is the last day we can add a seat.'],
                    ],
                ],
            ],
            'hifz-halaqa' => [
                [
                    'subject' => 'Musa\'s revision at home',
                    'scope' => GroupThread::SCOPE_PARTICIPANT,
                    'about' => 'musa-rahman',
                    'opened_by' => 'bilal',
                    'messages' => [
                        ['author' => 'bilal', 'days_ago' => 8, 'body' => 'Musa\'s sabak is strong — he is ahead of where I expected. His sabqi is where the mistakes are, so we are slowing the new lesson down for two weeks and doubling the revision.'],
                        ['author' => 'bilal', 'days_ago' => 2, 'body' => 'Update: the change is working. Two minor mistakes yesterday, none today. We will go back to a full sabak next week in shāʾ Allāh.'],
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Behaviour
    // ------------------------------------------------------------------

    /**
     * The tenant's recognition vocabulary. Manara ships no fixed list — this is
     * a school's own pedagogical choice, and the demo makes one so the picker
     * is not empty.
     *
     * "Disruption" carries polarity `negative` with a POSITIVE point value on
     * purpose: polarity and the sign of the value are independent facts, so a
     * school running a plain tally still has a summary that can say which
     * points were encouragement. See .claude/rules/groups.md.
     *
     * @return array<string,array{label:string,polarity:string,points:int}>
     */
    public static function behaviorSkills(): array
    {
        return [
            'participation' => ['label' => 'Participation', 'polarity' => BehaviorSkill::POLARITY_POSITIVE, 'points' => 1],
            'kindness' => ['label' => 'Kindness', 'polarity' => BehaviorSkill::POLARITY_POSITIVE, 'points' => 2],
            'quran' => ['label' => 'Qur\'an Recitation', 'polarity' => BehaviorSkill::POLARITY_POSITIVE, 'points' => 2],
            'disruption' => ['label' => 'Disruption', 'polarity' => BehaviorSkill::POLARITY_NEGATIVE, 'points' => 1],
        ];
    }

    /**
     * Awards, per classroom slug: [student key, skill key, days ago, note].
     *
     * Scattered across the last three weeks and across most — not all — of the
     * roster, because a real term looks like that and a demo where every child
     * has the same total teaches a viewer nothing.
     *
     * @return array<string,array<int,array{0:string,1:string,2:int,3:string}>>
     */
    public static function awards(): array
    {
        return [
            'grade-3-boys' => [
                ['yusuf-karim', 'participation', 1, 'Answered every question on the ṣalāh review.'],
                ['yusuf-karim', 'quran', 8, 'Read Sūrat al-Aʿlā without a prompt.'],
                ['bilal-ansari', 'kindness', 2, 'Gave his snack to a classmate who forgot lunch.'],
                ['bilal-ansari', 'participation', 12, 'Volunteered to lead the line every day this week.'],
                ['idris-haddad', 'participation', 3, 'Explained his method to the whole class.'],
                ['idris-haddad', 'disruption', 9, 'Talking during the reading period; settled after a reminder.'],
                ['musa-rahman', 'quran', 4, 'Perfect recitation in the morning circle.'],
                ['musa-rahman', 'kindness', 14, 'Helped a new student find his classroom.'],
                ['adam-diallo', 'participation', 5, 'First to finish the relay and helped his partner.'],
                ['zayd-shah', 'kindness', 6, 'Tidied the whole reading corner unasked.'],
                ['zayd-shah', 'disruption', 16, 'Out of seat repeatedly during maths.'],
                ['harun-bello', 'participation', 7, 'Read aloud in front of the class for the first time.'],
                ['harun-bello', 'quran', 18, 'Ten āyāt with no mistakes.'],
                ['ilyas-toure', 'kindness', 10, 'Stayed behind to help stack the chairs.'],
            ],
            'grade-5-girls' => [
                ['maryam-karim', 'participation', 2, 'Led her group\'s Seerah presentation.'],
                ['maryam-karim', 'kindness', 11, 'Quietly helped a classmate who was struggling.'],
                ['aisha-ansari', 'quran', 3, 'Recited her portion beautifully.'],
                ['safiyya-haddad', 'participation', 5, 'Asked the best question of the term about the water filters.'],
                ['safiyya-haddad', 'kindness', 13, 'Shared her materials without being asked.'],
                ['khadijah-sultana', 'participation', 6, 'Took careful notes and shared them with the group.'],
                ['ruqayyah-diallo', 'disruption', 8, 'Passing notes during the presentation.'],
                ['ruqayyah-diallo', 'kindness', 15, 'Comforted a classmate who was upset.'],
                ['hafsah-osman', 'quran', 4, 'Flawless revision.'],
                ['hafsah-osman', 'participation', 17, 'Ran the class discussion when asked.'],
                ['sumayyah-qureshi', 'kindness', 9, 'Welcomed the new student and sat with her all week.'],
                ['zahra-najjar', 'participation', 12, 'Excellent work on the poster.'],
            ],
            'hifz-halaqa' => [
                ['yusuf-karim', 'quran', 2, 'Sabak with no mistakes three days running.'],
                ['maryam-karim', 'quran', 5, 'Completed Sūrat at-Takwīr.'],
                ['musa-rahman', 'participation', 7, 'Stayed for the extra revision session.'],
                ['hafsah-osman', 'quran', 3, 'Manzil heard with only one minor slip.'],
                ['anas-karimov', 'kindness', 6, 'Sat with a younger student to help him revise.'],
                ['sulayman-bello', 'quran', 10, 'Finished Sūrat al-ʿAlaq.'],
                ['ammar-siddiqui', 'participation', 4, 'Arrived early every day this week.'],
                ['talha-mahmood', 'quran', 8, 'Steady progress and careful with the waqf.'],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Ḥifẓ
    // ------------------------------------------------------------------

    /**
     * Recitation entries for the ḥalaqa, per student key:
     * [kind, from surah, from ayah, to surah, to ayah, days ago, quality].
     *
     * Every range is a real closed interval inside the surah's actual ayah
     * count (App\Support\QuranIndex is the authority), so nothing here is a
     * coordinate the app would reject.
     *
     * The shape is deliberate: SABAK entries walk FORWARD and are what the
     * progress summary derives a position from; sabqi and manzil cover ground
     * already held and must never move anyone. Each student is at a different
     * point, and the manzil entries deliberately sit EARLIER in the muṣḥaf than
     * the sabak ones — a summary that read those as progress would report every
     * child as having gone backwards, which is the single mistake this module's
     * design exists to prevent.
     *
     * @return array<string,array<int,array{0:string,1:int,2:int,3:int,4:int,5:int,6:string}>>
     */
    public static function hifzEntries(): array
    {
        $sabak = HifzEntry::KIND_SABAK;
        $sabqi = HifzEntry::KIND_SABQI;
        $manzil = HifzEntry::KIND_MANZIL;

        $excellent = HifzEntry::QUALITY_EXCELLENT;
        $good = HifzEntry::QUALITY_GOOD;
        $fair = HifzEntry::QUALITY_FAIR;
        $repeat = HifzEntry::QUALITY_REPEAT;

        return [
            // Furthest along: through an-Naba and into an-Nazi'at.
            'yusuf-karim' => [
                [$sabak, 78, 1, 78, 15, 24, $good],
                [$sabak, 78, 16, 78, 30, 17, $excellent],
                [$sabak, 78, 31, 78, 40, 10, $excellent],
                [$sabak, 79, 1, 79, 15, 3, $good],
                [$sabqi, 78, 1, 78, 40, 6, $good],
                [$manzil, 87, 1, 88, 26, 12, $good],
            ],
            'maryam-karim' => [
                [$sabak, 80, 1, 80, 20, 22, $good],
                [$sabak, 80, 21, 80, 42, 15, $good],
                [$sabak, 81, 1, 81, 14, 8, $excellent],
                [$sabak, 81, 15, 81, 29, 1, $excellent],
                [$sabqi, 80, 1, 80, 42, 5, $good],
                [$manzil, 89, 1, 90, 20, 11, $fair],
            ],
            'musa-rahman' => [
                [$sabak, 87, 1, 87, 19, 20, $good],
                [$sabak, 88, 1, 88, 13, 13, $fair],
                [$sabak, 88, 14, 88, 26, 6, $good],
                [$sabqi, 87, 1, 87, 19, 9, $repeat],
                [$sabqi, 87, 1, 88, 26, 2, $good],
                [$manzil, 91, 1, 93, 11, 14, $good],
            ],
            'hafsah-osman' => [
                [$sabak, 89, 1, 89, 15, 18, $excellent],
                [$sabak, 89, 16, 89, 30, 11, $excellent],
                [$sabak, 90, 1, 90, 20, 4, $good],
                [$sabqi, 89, 1, 89, 30, 7, $excellent],
                [$manzil, 94, 1, 96, 19, 13, $good],
            ],
            'anas-karimov' => [
                [$sabak, 91, 1, 91, 15, 19, $good],
                [$sabak, 92, 1, 92, 21, 12, $fair],
                [$sabak, 93, 1, 93, 11, 5, $good],
                [$sabqi, 91, 1, 92, 21, 8, $good],
                [$manzil, 97, 1, 99, 8, 15, $good],
            ],
            'sulayman-bello' => [
                [$sabak, 94, 1, 94, 8, 16, $excellent],
                [$sabak, 95, 1, 95, 8, 9, $good],
                [$sabak, 96, 1, 96, 19, 2, $good],
                [$sabqi, 94, 1, 95, 8, 6, $good],
                [$manzil, 100, 1, 102, 8, 12, $fair],
            ],
            'ammar-siddiqui' => [
                [$sabak, 97, 1, 97, 5, 14, $good],
                [$sabak, 98, 1, 98, 8, 7, $fair],
                [$sabqi, 97, 1, 97, 5, 3, $good],
                [$manzil, 103, 1, 106, 4, 10, $good],
            ],
            // Newest to the circle: the short surahs at the end of juz 30.
            'talha-mahmood' => [
                [$sabak, 99, 1, 99, 8, 13, $good],
                [$sabak, 100, 1, 100, 11, 6, $repeat],
                [$sabqi, 99, 1, 99, 8, 2, $good],
                [$manzil, 108, 1, 110, 3, 9, $excellent],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Money — LOCAL ROWS ONLY
    // ------------------------------------------------------------------

    /**
     * One free offering and one paid one.
     *
     * NOTHING HERE TOUCHES STRIPE. The free plan's registrations confirm
     * synchronously through RegistrationService's declared free-path carve-out;
     * the paid plan's registrations are ordinary intake rows holding a seat with
     * `payment_status = awaiting` and a locally minted idempotency key. No
     * Checkout Session, Customer, Subscription or Price is created, and no
     * Stripe identifier is written to any row.
     *
     * `intake_form` is created alongside because `offerings.intake_form_id` is
     * NOT NULL — an offering cannot exist without the form that collects its
     * answers.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function offerings(): array
    {
        return [
            [
                'slug' => 'parent-night-fall',
                'name' => 'Parent Night — Fall Term',
                'kind' => Offering::KIND_EVENT,
                'capacity' => 120,
                'plan' => [
                    'kind' => FeePlan::KIND_FREE,
                    'amount_minor' => 0,
                    'label' => 'Free',
                ],
                'form' => [
                    'slug' => 'parent-night-fall-rsvp',
                    'name' => 'Parent Night RSVP',
                    'description' => 'Let us know who is coming so we can set out enough chairs.',
                ],
                // Payer key (a guardian) => how many seats in the household.
                'registrations' => ['karim-rashid', 'osman-layla', 'diallo-fatou'],
            ],
            [
                'slug' => 'after-school-quran-club',
                'name' => 'After-School Qur\'an Club',
                'kind' => Offering::KIND_PROGRAM,
                'capacity' => 24,
                'plan' => [
                    'kind' => FeePlan::KIND_ONE_TIME,
                    // Minor units, always integers: CAD 120.00 for the term.
                    'amount_minor' => 12000,
                    'label' => 'Fall term',
                ],
                'form' => [
                    'slug' => 'after-school-quran-club-intake',
                    'name' => 'After-School Qur\'an Club — Registration',
                    'description' => 'Tuesdays and Thursdays, 3:30–5:00 PM, for the fall term.',
                ],
                'registrations' => ['ansari-imran', 'shah-adeel'],
            ],
        ];
    }

    /**
     * The intake schema both offerings use: the smallest form that is still a
     * real one — who is registering, how to reach them, and anything the office
     * should know. It uses only `FormSchema::FIELD_TYPES` and its `settings`
     * identity map points at real questions, so it validates exactly as an
     * admin-built form does.
     *
     * @return array{schema:array<string,mixed>,settings:array<string,mixed>}
     */
    public static function offeringFormDefinition(): array
    {
        return [
            'schema' => [
                'sections' => [
                    [
                        'id' => 'family',
                        'title' => 'Family',
                        'fields' => [
                            ['name' => 'parentName', 'label' => 'Parent or guardian name', 'type' => 'text', 'required' => true],
                            ['name' => 'parentEmail', 'label' => 'Email address', 'type' => 'email', 'required' => true],
                            ['name' => 'parentPhone', 'label' => 'Phone number', 'type' => 'tel', 'required' => true],
                            ['name' => 'students', 'label' => 'Which of your children is this for?', 'type' => 'text', 'required' => true],
                            ['name' => 'notes', 'label' => 'Anything we should know?', 'type' => 'textarea'],
                        ],
                    ],
                ],
            ],
            'settings' => [
                'identity' => [
                    'name' => 'parentName',
                    'email' => 'parentEmail',
                    'phone' => 'parentPhone',
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Public site
    // ------------------------------------------------------------------

    /**
     * Two pages built from the SCHOOL section types added for this vertical
     * (`staff_directory`, `programs`, `admissions_tuition`), so the public-site
     * half of the story is demoable too.
     *
     * Every money value in `admissions_tuition` is DISPLAY TEXT, never a
     * number — a real fee schedule mixes "$8,000", "Included" and "Contact us"
     * in one table, and nothing in this app charges from these values.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pages(): array
    {
        return [
            [
                'slug' => 'our-school',
                'title' => 'Our School',
                'order' => 1,
                'sections' => [
                    [
                        'type' => 'staff_directory',
                        'title' => 'Faculty & Staff',
                        'content' => [
                            'heading' => 'The people who teach here',
                            'description' => 'A small faculty who know every child by name.',
                            'members' => [
                                ['name' => 'Ustadha Khadija Nasser', 'role' => 'Principal', 'department' => 'Administration', 'bio' => 'Twelve years in Islamic education; leads the school\'s curriculum and family partnership work.'],
                                ['name' => 'Br. Tariq Mansour', 'role' => 'Grade 3 Teacher', 'department' => 'Lower School', 'bio' => 'Teaches the Grade 3 boys\' homeroom and coordinates the school\'s reading programme.'],
                                ['name' => 'Sr. Hafsa Iqbal', 'role' => 'Grade 5 Teacher', 'department' => 'Upper School', 'bio' => 'Teaches Grade 5 girls; leads the Seerah and science projects.'],
                                ['name' => 'Qari Bilal Toure', 'role' => 'Ḥifẓ Instructor', 'department' => 'Qur\'an', 'bio' => 'Runs the daily ḥalaqa and the after-school Qur\'an club.'],
                            ],
                            'layout' => 'grid',
                            'columns' => 4,
                            // Publishing a teacher's address is a decision
                            // somebody makes on purpose. The demo does not
                            // make it for them.
                            'show_contact' => false,
                            'background_color' => '#F5F1E6',
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'admissions',
                'title' => 'Admissions',
                'order' => 2,
                'sections' => [
                    [
                        'type' => 'programs',
                        'title' => 'Programs',
                        'content' => [
                            'heading' => 'Programs & curriculum',
                            'description' => 'The Ontario core curriculum alongside Qur\'an, Arabic and Islamic studies.',
                            'programs' => [
                                [
                                    'name' => 'Lower School',
                                    'level' => 'Grades 1–4',
                                    'schedule' => 'Mon–Fri, 8:15 AM – 3:15 PM',
                                    'summary' => 'Foundations in literacy and numeracy with daily Qur\'an and Arabic.',
                                    'highlights' => ['Daily Qur\'an circle', 'Arabic from Grade 1', 'Small homerooms'],
                                    'link_url' => '',
                                    'link_text' => '',
                                ],
                                [
                                    'name' => 'Upper School',
                                    'level' => 'Grades 5–8',
                                    'schedule' => 'Mon–Fri, 8:15 AM – 3:30 PM',
                                    'summary' => 'Subject teaching, project work, and a Seerah and fiqh sequence.',
                                    'highlights' => ['Science lab', 'Seerah & fiqh sequence', 'Public speaking'],
                                    'link_url' => '',
                                    'link_text' => '',
                                ],
                                [
                                    'name' => 'Ḥifẓ Programme',
                                    'level' => 'Grades 3–8, by placement',
                                    'schedule' => 'Daily, alongside the academic timetable',
                                    'summary' => 'Sabak, sabqi and manzil heard daily, with progress shared with families each term.',
                                    'highlights' => ['Daily sabak', 'Structured revision rota', 'Termly progress report'],
                                    'link_url' => '',
                                    'link_text' => '',
                                ],
                            ],
                            'layout' => 'cards',
                            'columns' => 3,
                            'background_color' => '#ffffff',
                        ],
                    ],
                    [
                        'type' => 'admissions_tuition',
                        'title' => 'Admissions & Tuition',
                        'content' => [
                            'heading' => 'Admissions & tuition',
                            'description' => 'Applications for the coming year open in January.',
                            'school_year' => '2026–2027',
                            'tiers' => [
                                ['name' => 'Kindergarten', 'badge' => '', 'amount' => '$6,400', 'period' => 'per year', 'note' => 'Half-day option available', 'includes' => ['Books and materials', 'Snack programme']],
                                ['name' => 'Grades 1–4', 'badge' => 'Most families', 'amount' => '$8,200', 'period' => 'per year', 'note' => '', 'includes' => ['Books and materials', 'Qur\'an programme']],
                                ['name' => 'Grades 5–8', 'badge' => '', 'amount' => '$8,900', 'period' => 'per year', 'note' => '', 'includes' => ['Books and materials', 'Science lab fee', 'Qur\'an programme']],
                            ],
                            'fees' => [
                                ['label' => 'Application fee', 'amount' => '$75', 'note' => 'Non-refundable, per family'],
                                ['label' => 'Second child', 'amount' => '$500 discount', 'note' => 'Applied automatically'],
                                ['label' => 'Third child and beyond', 'amount' => '$900 discount', 'note' => 'Applied automatically'],
                                ['label' => 'Bus service', 'amount' => 'Contact us', 'note' => 'Routes depend on enrolment'],
                            ],
                            'payment_plans' => [
                                ['label' => 'Annual', 'detail' => 'Paid in full by 1 August — 3% discount'],
                                ['label' => 'Termly', 'detail' => 'Three instalments: August, November, February'],
                                ['label' => 'Monthly', 'detail' => 'Ten instalments, August to May'],
                            ],
                            'steps' => [
                                ['title' => 'Tell us about your child', 'description' => 'Complete the Admissions Interest form and we will be in touch within a week.'],
                                ['title' => 'Visit us', 'description' => 'Come and see a classroom during a normal school day.'],
                                ['title' => 'Placement', 'description' => 'A short assessment so we place your child in the right group.'],
                                ['title' => 'Offer and enrolment', 'description' => 'We confirm a place and agree a payment plan with you.'],
                            ],
                            'disclaimer' => 'Fictional figures for demonstration only. Financial aid is available to every family who needs it — please ask.',
                            'button_text' => 'Start an application',
                            'button_page_id' => null,
                            'button_link' => null,
                            'background_color' => '#ffffff',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The stand-in "classroom photo", as raw PNG bytes.
     *
     * A flat geometric motif rather than anything resembling a photograph of a
     * child: the point of the attachment is to exercise the private-disk write
     * and the consent-gated download, and a demo fixture has no business
     * carrying an image of a person, invented or otherwise.
     */
    public static function sampleImageBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAMAAAAB4CAIAAAArJ2pIAAABfUlEQVR42u3Suw1CMRBFwe2BrgiokXoo'
            . 'ggZIKIGQBAH282dXGukUcNeeOF3OUnfhCQSQABJAAkgCSAAJIAEkASSABJAAkgASQAJIAEkACSABJIAE'
            . 'kASQABJAAkgCSAAJIAEkASSABJAAkgASQAJIAAkgCSABJIAEkASQABJAAkgCSKUB3a4NbXwLO3MBarpz'
            . '4w/ZmQvQwTuXXW5nRkDDr530N3amAzTp1OFn2zl2Z1S5dsjNdg43FIX0HLzZzhmGohCd7rPtnMcIIDs3'
            . 'Adp4bdPNdk41FEX1/HmznbMNRV09P2+2c4EhgOxcCyjVtV9utnONIYDsXAgo4bUfb7ZzmSGA7AQIIIAA'
            . 'AsjHAASQnQDZCRBAAPkYgACyEyA7AQIIIB8DkI8BCCA7AQIIIB8DkI8BCCA7AQIIIIBKA3o+7lJ3AAkg'
            . 'ASSABJAEkAASQAJIAkgACSABJAEkgASQAJIAEkACSAAJIAkgASSABJAEkAASQAJIAkgACSABJL17AVFv'
            . '2zbuwfjgAAAAAElFTkSuQmCC',
            true
        );
    }
}
