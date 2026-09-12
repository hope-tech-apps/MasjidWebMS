<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\ArabicLetterProgress;
use App\Models\AssignmentScore;
use App\Models\AttendanceRecord;
use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\HifzEntry;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Models\ReportCard;
use App\Models\ReportCardMark;
use App\Support\PerformanceLevel;
use App\Support\SchoolRecordsCsv as Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The school's own records, as files it can take away.
 *
 * This closes R1, the highest item on the vendor assessment: "there is no export
 * endpoint for attendance, grades, ḥifẓ, behaviour, or even the roster. If the
 * school leaves, its academic records sit in a database it does not control."
 * A school that cannot leave has not chosen us; it is stuck with us, and those
 * are different things.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS IN, AND THE TEST FOR IT
 * ---------------------------------------------------------------------------
 *
 * "Would a receiving school need this to reconstruct a child's record?" — not
 * "do we have the table". So: the roster and its guardian edges, classes and who
 * taught them, the register, work set and marked, report cards and their
 * criteria, ḥifẓ, behaviour, Arabic progress, and a year of lesson plans.
 *
 * DELIBERATELY ABSENT, each for its own reason:
 *
 *   - Parent–teacher THREADS and MESSAGES. Private correspondence, which this
 *     system already promises to purge after 365 days. An export copy quietly
 *     defeats a retention promise the school made to its families, and a
 *     departing administrator does not need a dump of what parents said.
 *   - CONTACT LOGIN EVENTS and every credential column — password hash,
 *     login_email, the login_* timestamps. That is the audit trail of OUR
 *     authentication system, not an academic record, and it is append-only here
 *     precisely so no mutable copy exists.
 *   - ATTACHMENT BYTES and every `disk`/`path` value. Photographs of children
 *     are governed per-membership by media consent that a bulk job cannot
 *     re-check per image, and a class photo contains children who are not
 *     transferring. Randomised private-disk filenames must never leave.
 *   - SMS CONSENT and staff NOTES on a contact. Consent belongs to the party
 *     that obtained it; exporting the four consent columns would hand a third
 *     party a pre-cleaned bulk-texting list and silently re-attribute the
 *     congregation's consent to someone who never asked for it.
 *
 * ---------------------------------------------------------------------------
 * THE CONTACTS FILE IS BOUNDED BY THE ROSTER, NOT BY THE TENANT
 * ---------------------------------------------------------------------------
 *
 * `contacts` is the masjid's CRM table, shared by donations, meal orders, event
 * registrations and app members. The masjid is the MOSQUE; the school is a set
 * of classes inside it. A tenant-scoped `Contact::all()` would therefore hand a
 * departing madrasah of sixty families the name, email and phone of every donor
 * and every Jummah-lunch orderer in the congregation — people who never enrolled
 * a child. So the subject set is derived from the school's own rosters and every
 * other file is already per-class.
 *
 * That subject filter is NOT the hand-written tenant filter
 * .claude/rules/tenant-scoping.md forbids. The rule forbids a second `masjid_id`
 * predicate that could disagree with the BelongsToMasjid global scope; this is a
 * subject predicate derived from an already-scoped query, orthogonal to the
 * tenant boundary and unable to disagree with it.
 *
 * ---------------------------------------------------------------------------
 * WHO MAY RUN IT
 * ---------------------------------------------------------------------------
 *
 * `admin` + `tenant` + `permission:manage contacts` — the same gate that guards
 * the roster itself. A new `export records` permission was designed and thrown
 * away: roles here are minted 1:1 from `users.type`, `masjid-admin` is granted
 * every permission in the seeder automatically, and there is no registrar role
 * to hold a narrower one. It would have been a middleware entry that gated
 * nobody while reading as though it gated somebody. `manage contacts` genuinely
 * excludes teachers and members, who hold zero CRM permissions by design.
 */
class SchoolRecordsExportController extends Controller
{
    /**
     * Every dataset, and the order a receiving school should load them in —
     * subjects before the facts that reference them.
     */
    private const DATASETS = [
        'manifest', 'contacts', 'classes', 'enrollments', 'guardians', 'class_staff',
        'attendance', 'assignments', 'assignment_scores',
        'report_cards', 'report_card_marks',
        'hifz', 'behaviour', 'behaviour_skills', 'arabic_progress', 'lesson_plans',
    ];

    /**
     * GET .../records/export?dataset=attendance
     *
     * One dataset per request rather than a ZIP. A ZIP has to be buffered before
     * the first byte can be sent, which gives up the flat-memory property that
     * is the entire reason the donation export streams; and a half-built archive
     * that fails at 80% is a corrupt file, where a failed CSV is an obviously
     * short one. `manifest` names the full set and its row counts so a school
     * can tell whether it has everything.
     */
    public function export(Request $request, $masjid_id): StreamedResponse|Response
    {
        // withTrashed: a departing school is often ALREADY a trashed tenant, and
        // that is precisely the day this endpoint is owed. Refusing to find the
        // masjid then would make the remedy unreachable in the only state it
        // exists for.
        $masjid = Masjid::withTrashed()->findOrFail($masjid_id);

        $dataset = (string) $request->query('dataset', 'manifest');

        if (! in_array($dataset, self::DATASETS, true)) {
            return response()->json([
                'status' => 'failed',
                'data' => ['dataset' => [
                    'Unknown dataset. One of: ' . implode(', ', self::DATASETS) . '.',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $filename = 'records-' . $dataset . '-'
            . (Str::slug($masjid->name) ?: $masjid->id)
            . '-' . now()->format('Y-m-d') . '.csv';

        return response()->stream(function () use ($dataset) {
            $out = Csv::open();
            $this->{'write' . Str::studly($dataset)}($out);
            fclose($out);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            // Never cached anywhere: this is every child in the school.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ------------------------------------------------------------- the subject set

    /**
     * The contacts this school's records are ABOUT: every child on a class or
     * ḥalaqa roster, plus every guardian named against one.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function schoolContactIds()
    {
        $groupIds = Group::query()
            ->whereIn('kind', [Group::KIND_CLASS, Group::KIND_HALAQA])
            ->pluck('id');

        $rows = GroupMembership::query()->whereIn('group_id', $groupIds)->get(['contact_id', 'guardian_of_contact_id']);

        return $rows->pluck('contact_id')
            ->merge($rows->pluck('guardian_of_contact_id'))
            ->filter()
            ->unique()
            ->values();
    }

    /** Classes and ḥalaqāt only — a school does not take the masjid's other groups. */
    private function schoolGroupIds()
    {
        return Group::withTrashed()
            ->whereIn('kind', [Group::KIND_CLASS, Group::KIND_HALAQA])
            ->pluck('id');
    }

    // ------------------------------------------------------------------ writers
    //
    // Every writer follows the same three rules: no ORDER BY (Csv::each refuses
    // one), free text through Csv::text() and numbers through Csv::num(), and a
    // membership id on every fact row so the receiving system can re-link the
    // files without guessing at names.

    /** @param resource $out */
    private function writeManifest($out): void
    {
        Csv::row($out, ['Dataset', 'Rows', 'What it holds']);

        foreach ($this->counts() as $name => [$n, $what]) {
            Csv::row($out, [$name, Csv::num($n), $what]);
        }

        Csv::row($out, []);
        Csv::row($out, ['Generated at', now()->toIso8601String()]);
        Csv::row($out, ['Note', 'Fetch each dataset with ?dataset=<name>. Row counts here are '
            . 'computed independently of the files, so a short download is detectable.']);
        Csv::row($out, ['Not included', 'Parent-teacher messages, login and credential history, '
            . 'photo and file bytes, and SMS consent - see the controller docblock for why.']);
    }

    /** @return array<string, array{0:int, 1:string}> */
    private function counts(): array
    {
        $gids = $this->schoolGroupIds();

        return [
            'contacts' => [Contact::withTrashed()->whereKey($this->schoolContactIds())->count(), 'Children and guardians on a school roster'],
            'classes' => [Group::withTrashed()->whereKey($gids)->count(), 'Classes and halaqat'],
            'enrollments' => [GroupMembership::whereIn('group_id', $gids)->whereIn('role', GroupMembership::PARTICIPANT_ROLES)->count(), 'Who is in which class'],
            'guardians' => [GroupMembership::whereIn('group_id', $gids)->where('role', GroupMembership::ROLE_GUARDIAN)->count(), 'Guardian-to-child edges'],
            'class_staff' => [\App\Models\GroupStaff::whereIn('group_id', $gids)->count(), 'Who taught each class'],
            'attendance' => [AttendanceRecord::whereIn('group_id', $gids)->count(), 'The register'],
            'assignments' => [ClassAssignment::withTrashed()->whereIn('group_id', $gids)->count(), 'Work that was set'],
            'assignment_scores' => [AssignmentScore::whereIn('group_id', $gids)->count(), 'Marks'],
            'report_cards' => [ReportCard::whereIn('group_id', $gids)->count(), 'Report cards and progress reports'],
            'report_card_marks' => [ReportCardMark::count(), 'Per-criterion levels'],
            'hifz' => [HifzEntry::whereIn('group_id', $gids)->count(), 'Quran memorisation positions'],
            'behaviour' => [BehaviorAward::whereIn('group_id', $gids)->count(), 'Behaviour points'],
            'behaviour_skills' => [BehaviorSkill::count(), 'The behaviour vocabulary'],
            'arabic_progress' => [ArabicLetterProgress::whereIn('group_id', $gids)->count(), 'Letter drills, Arabic and English (see the Alphabet column)'],
            'lesson_plans' => [LessonPlan::whereIn('group_id', $gids)->count(), 'Lesson plans'],
        ];
    }

    /** @param resource $out */
    private function writeContacts($out): void
    {
        Csv::row($out, ['Contact id', 'First name', 'Last name', 'Email', 'Phone', 'Created at']);

        Csv::each(
            Contact::withTrashed()->whereKey($this->schoolContactIds()),
            fn (Contact $c) => Csv::row($out, [
                Csv::num($c->id), Csv::text($c->first_name), Csv::text($c->last_name),
                Csv::text($c->email), Csv::text($c->phone), Csv::num($c->created_at),
            ])
        );
    }

    /** @param resource $out */
    private function writeClasses($out): void
    {
        Csv::row($out, ['Class id', 'Name', 'Kind', 'Description', 'Archived on']);

        Csv::each(
            Group::withTrashed()->whereKey($this->schoolGroupIds()),
            fn (Group $g) => Csv::row($out, [
                Csv::num($g->id), Csv::text($g->name), Csv::text($g->kind),
                Csv::text($g->description), Csv::num($g->deleted_at),
            ])
        );
    }

    /** @param resource $out */
    private function writeEnrollments($out): void
    {
        Csv::row($out, ['Membership id', 'Class id', 'Contact id', 'Student name', 'Role',
            'Grade', 'Joined on', 'Consent granted at', 'Consent scope']);

        Csv::each(
            GroupMembership::whereIn('group_id', $this->schoolGroupIds())
                ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
                ->with(['contact' => fn ($q) => $q->withTrashed()]),
            fn (GroupMembership $m) => Csv::row($out, [
                Csv::num($m->id), Csv::num($m->group_id), Csv::num($m->contact_id),
                Csv::text($this->nameOf($m->contact)), Csv::text($m->role),
                Csv::text($m->grade_label), Csv::num($m->joined_at),
                Csv::num($m->consent_granted_at), Csv::text($m->consent_scope),
            ])
        );
    }

    /** @param resource $out */
    private function writeGuardians($out): void
    {
        Csv::row($out, ['Membership id', 'Class id', 'Guardian contact id', 'Guardian name',
            'Ward contact id', 'Joined on', 'Consent granted at', 'Consent scope']);

        Csv::each(
            GroupMembership::whereIn('group_id', $this->schoolGroupIds())
                ->where('role', GroupMembership::ROLE_GUARDIAN)
                ->with(['contact' => fn ($q) => $q->withTrashed()]),
            fn (GroupMembership $m) => Csv::row($out, [
                Csv::num($m->id), Csv::num($m->group_id), Csv::num($m->contact_id),
                Csv::text($this->nameOf($m->contact)), Csv::num($m->guardian_of_contact_id),
                Csv::num($m->joined_at), Csv::num($m->consent_granted_at), Csv::text($m->consent_scope),
            ])
        );
    }

    /** @param resource $out */
    private function writeClassStaff($out): void
    {
        Csv::row($out, ['Row id', 'Class id', 'Staff user id', 'Staff name', 'Role', 'Assigned at']);

        Csv::each(
            \App\Models\GroupStaff::whereIn('group_id', $this->schoolGroupIds())->with('user'),
            fn ($r) => Csv::row($out, [
                Csv::num($r->id), Csv::num($r->group_id), Csv::num($r->user_id),
                Csv::text($r->user?->name), Csv::text($r->role), Csv::num($r->assigned_at),
            ])
        );
    }

    /** @param resource $out */
    private function writeAttendance($out): void
    {
        Csv::row($out, ['Record id', 'Class id', 'Membership id', 'Student name',
            'Session date', 'Status', 'Note']);

        Csv::each(
            AttendanceRecord::whereIn('group_id', $this->schoolGroupIds())
                ->with(['membership.contact' => fn ($q) => $q->withTrashed()]),
            fn (AttendanceRecord $r) => Csv::row($out, [
                Csv::num($r->id), Csv::num($r->group_id), Csv::num($r->group_membership_id),
                Csv::text($this->nameOf($r->membership?->contact)),
                Csv::num($r->session_date?->toDateString()), Csv::text($r->status), Csv::text($r->note),
            ])
        );
    }

    /** @param resource $out */
    private function writeAssignments($out): void
    {
        Csv::row($out, ['Assignment id', 'Class id', 'Title', 'Scale', 'Points possible',
            'Assigned on', 'Withdrawn on']);

        Csv::each(
            ClassAssignment::withTrashed()->whereIn('group_id', $this->schoolGroupIds()),
            fn (ClassAssignment $a) => Csv::row($out, [
                Csv::num($a->id), Csv::num($a->group_id), Csv::text($a->title),
                Csv::text($a->scale), Csv::num($a->points_possible),
                Csv::num($a->assigned_on?->toDateString()), Csv::num($a->deleted_at),
            ])
        );
    }

    /** @param resource $out */
    private function writeAssignmentScores($out): void
    {
        Csv::row($out, ['Score id', 'Assignment id', 'Class id', 'Membership id', 'Student name',
            'Status', 'Points earned', 'Level', 'Note']);

        Csv::each(
            AssignmentScore::whereIn('group_id', $this->schoolGroupIds())
                ->with(['membership.contact' => fn ($q) => $q->withTrashed(),
                        'assignment' => fn ($q) => $q->withTrashed()]),
            function (AssignmentScore $s) use ($out) {
                // A level is only meaningful with its word beside it; a bare 3
                // in a receiving system means nothing.
                $level = $s->assignment && $s->assignment->usesLevels() && $s->points_earned !== null
                    ? PerformanceLevel::label((int) $s->points_earned)
                    : null;

                Csv::row($out, [
                    Csv::num($s->id), Csv::num($s->class_assignment_id), Csv::num($s->group_id),
                    Csv::num($s->group_membership_id), Csv::text($this->nameOf($s->membership?->contact)),
                    Csv::text($s->status), Csv::num($s->points_earned), Csv::text($level), Csv::text($s->note),
                ]);
            }
        );
    }

    /** @param resource $out */
    private function writeReportCards($out): void
    {
        Csv::row($out, ['Report card id', 'Class id', 'Membership id', 'Student name', 'Type',
            'School year', 'Term', 'Grade', 'Days present', 'Days absent', 'Days late',
            'Teacher comment', 'Published at']);

        Csv::each(
            ReportCard::whereIn('group_id', $this->schoolGroupIds())
                ->with(['membership.contact' => fn ($q) => $q->withTrashed()]),
            fn (ReportCard $c) => Csv::row($out, [
                Csv::num($c->id), Csv::num($c->group_id), Csv::num($c->group_membership_id),
                Csv::text($this->nameOf($c->membership?->contact)),
                Csv::text($c->typeLabel()), Csv::text($c->school_year), Csv::num($c->term),
                Csv::text($c->grade_label), Csv::num($c->days_present), Csv::num($c->days_absent),
                Csv::num($c->days_late), Csv::text($c->teacher_comment), Csv::num($c->published_at),
            ])
        );
    }

    /** @param resource $out */
    private function writeReportCardMarks($out): void
    {
        Csv::row($out, ['Mark id', 'Report card id', 'Kind', 'Subject', 'Criterion',
            'Level', 'Level label', 'Comment']);

        Csv::each(
            ReportCardMark::query(),
            fn (ReportCardMark $m) => Csv::row($out, [
                Csv::num($m->id), Csv::num($m->report_card_id), Csv::text($m->kind),
                Csv::text($m->subject), Csv::text($m->criterion),
                Csv::num($m->level), Csv::text($m->levelLabel()), Csv::text($m->comment),
            ])
        );
    }

    /** @param resource $out */
    private function writeHifz($out): void
    {
        Csv::row($out, ['Entry id', 'Class id', 'Membership id', 'Student name', 'Kind',
            'From surah', 'From ayah', 'To surah', 'To ayah', 'Quality',
            'Major mistakes', 'Minor mistakes', 'Note', 'Recited at']);

        Csv::each(
            HifzEntry::whereIn('group_id', $this->schoolGroupIds())
                ->with(['membership.contact' => fn ($q) => $q->withTrashed()]),
            fn (HifzEntry $h) => Csv::row($out, [
                Csv::num($h->id), Csv::num($h->group_id), Csv::num($h->group_membership_id),
                Csv::text($this->nameOf($h->membership?->contact)), Csv::text($h->kind),
                Csv::num($h->from_surah), Csv::num($h->from_ayah),
                Csv::num($h->to_surah), Csv::num($h->to_ayah), Csv::text($h->quality),
                Csv::num($h->major_mistakes), Csv::num($h->minor_mistakes),
                Csv::text($h->note), Csv::num($h->recited_at),
            ])
        );
    }

    /** @param resource $out */
    private function writeBehaviour($out): void
    {
        // The snapshotted label and polarity, not a join: an award carries what
        // it was given with, so renaming a skill later cannot rewrite history.
        Csv::row($out, ['Award id', 'Class id', 'Membership id', 'Student name',
            'Skill', 'Polarity', 'Points', 'Note', 'Awarded at', 'Retained until']);

        Csv::each(
            BehaviorAward::whereIn('group_id', $this->schoolGroupIds())
                ->with(['membership.contact' => fn ($q) => $q->withTrashed()]),
            fn (BehaviorAward $a) => Csv::row($out, [
                Csv::num($a->id), Csv::num($a->group_id), Csv::num($a->group_membership_id),
                Csv::text($this->nameOf($a->membership?->contact)),
                Csv::text($a->skill_label), Csv::text($a->skill_polarity),
                Csv::num($a->points), Csv::text($a->note),
                Csv::num($a->awarded_at), Csv::num($a->retained_until),
            ])
        );
    }

    /** @param resource $out */
    private function writeBehaviourSkills($out): void
    {
        Csv::row($out, ['Skill id', 'Label', 'Polarity', 'Default points', 'Active']);

        Csv::each(
            BehaviorSkill::query(),
            fn (BehaviorSkill $s) => Csv::row($out, [
                Csv::num($s->id), Csv::text($s->label), Csv::text($s->polarity),
                Csv::num($s->default_points), Csv::num($s->is_active ? 1 : 0),
            ])
        );
    }

    /**
     * The section still keys on `arabic_progress`, but the table has held two
     * alphabets since 2026-09-12, so the file carries an 'Alphabet' column.
     * Without it the file merges the qāʿidah and the English A–Z under one
     * header and a receiving school reads `a` and `alif` as one syllabus —
     * silently, because a CSV cannot complain. The section KEY is unchanged: it
     * is the dataset name a departing school's tooling asks for, and renaming it
     * would break that for a cosmetic gain.
     *
     * 'Alphabet' IS APPENDED, NOT INSERTED, for the same reason. A consumer that
     * reads this file positionally — the ones that do are exactly the ones a
     * stable dataset name is for — takes row[4] as the drill id, row[5] as the
     * status and row[6] as the mastery date. Slotting the new column in beside
     * 'Drill id' shifts all three one place right, so every drill id becomes the
     * literal string 'arabic', every status becomes a drill id and every date
     * becomes a status, with no parse error and no empty cell to notice. Appended
     * at the end, the existing positions are exactly what they were and the new
     * field is additive: an old reader ignores it, a new one asks for it by name.
     *
     * @param  resource  $out
     */
    private function writeArabicProgress($out): void
    {
        Csv::row($out, ['Row id', 'Class id', 'Membership id', 'Student name',
            'Drill id', 'Status', 'Mastered at', 'Alphabet']);

        Csv::each(
            ArabicLetterProgress::whereIn('group_id', $this->schoolGroupIds())
                ->with(['membership.contact' => fn ($q) => $q->withTrashed()]),
            fn (ArabicLetterProgress $r) => Csv::row($out, [
                Csv::num($r->id), Csv::num($r->group_id), Csv::num($r->group_membership_id),
                Csv::text($this->nameOf($r->membership?->contact)),
                Csv::text($r->drill_id), Csv::text($r->status),
                Csv::num($r->mastered_at), Csv::text($r->alphabet),
            ])
        );
    }

    /** @param resource $out */
    private function writeLessonPlans($out): void
    {
        Csv::row($out, ['Plan id', 'Class id', 'Session date', 'Title', 'Body']);

        Csv::each(
            LessonPlan::whereIn('group_id', $this->schoolGroupIds()),
            fn (LessonPlan $p) => Csv::row($out, [
                Csv::num($p->id), Csv::num($p->group_id),
                Csv::num($p->session_date?->toDateString()),
                Csv::text($p->title), Csv::text($p->body),
            ])
        );
    }

    /**
     * A name for a row whose contact may be gone.
     *
     * Never used to IDENTIFY anybody — every fact row carries the membership and
     * contact ids for that, so two withdrawn children can never collapse into
     * one another the way they would if identity came from a nullable relation.
     */
    private function nameOf(?Contact $c): string
    {
        return $c ? trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) : '';
    }
}
