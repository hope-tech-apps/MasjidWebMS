<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Support\GroupAudience;
use App\Support\SchoolCalendar;
use App\Support\SchoolSettings;
use App\Support\StudentAge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Base for the teacher realm's OWN controllers (routes/teacher.php).
 *
 * The one place a teacher payload is serialized, and the reason it is a base
 * class rather than a trait: it exists to make "names only" structural. A teacher
 * sees guardian email/phone NOWHERE, and a student is a first name, a last name
 * and an avatar — nothing else. `Contact` and `GroupMembership` have no `$hidden`,
 * so returning either model directly (or `$group->toArray()`) would dump email,
 * phone, notes, login_* and provenance to a teacher. Every teacher payload is
 * therefore hand-built here through student()/classPayload(), never a model.
 *
 * Mirrors the Family realm's serialization discipline (Family\...::student()).
 */
abstract class TeacherController extends Controller
{
    protected function classSubjectsEnabled(int|string|null $masjidId): bool
    {
        if ($masjidId === null) return false;
        return \App\Support\ClassSubjectMode::enabled($masjidId);
    }

    protected function classSubjectsForGroup(int $groupId): bool
    {
        $orgId = app(\App\Support\TenantContext::class)->get() ?? Group::withoutMasjidScope()->whereKey($groupId)->value('masjid_id');
        return $this->classSubjectsEnabled($orgId);
    }

    /** @var array<int, string> the school's today, by organisation, for this request */
    private array $todayByOrganisation = [];

    public function __construct(protected GroupAudience $audience)
    {
    }

    /**
     * The classes the signed-in teacher leads, within the bound tenant.
     * `Group::scopeLedBy` runs group_staff the other way from
     * GroupAudience::leaderGroupIdsFor; both name only the teacher's own classes.
     *
     * @return Collection<int,Group>
     */
    protected function taughtGroups(): Collection
    {
        return Group::query()
            ->ledBy((int) Auth::id())
            ->inDisplayOrder()
            ->get();
    }

    /**
     * A student, NAMES ONLY — the serialization boundary. Never widen this to
     * include a guardian, an email, a phone, notes or a login field.
     *
     * `grade_label` is the one non-name field here, and it belongs: a class that
     * combines Pre-K with KG is still teaching two grades, and the teacher taking
     * the register has to see which child is which. It is roster data the office
     * typed, not a disclosure about a family — no contact detail travels with it.
     *
     * @return array{membership_id:int, grade_label:?string, contact:array{id:int,first_name:mixed,last_name:mixed,avatar:mixed}|null}
     */
    protected function student(GroupMembership $membership): array
    {
        $contact = $membership->contact;

        return [
            'membership_id' => (int) $membership->id,
            'grade_label' => $membership->grade_label,
            'contact' => $contact ? [
                'id' => (int) $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'avatar' => $contact->avatar,
            ] : null,
        ];
    }

    /**
     * One class with its names-only roster. The roster is the PARTICIPANTS
     * (students) — deliberately NOT the guardians: a teacher's window on a
     * guardian is a message thread, where only the name shows (authorLabel), not
     * a directory of contact details.
     *
     * `$unreadMessages` is how many messages from other people this teacher has not
     * seen in the class (GroupThreadUnread), computed by the CALLER so My Classes
     * can do one query for every class; null leaves the key out.
     *
     * `age` is the second non-name field a student carries, and only HERE, on the
     * roster: a whole number computed on read, for students in classes only. The
     * date of birth itself never travels to a teacher, and no guardian field ever
     * does. It is added beside student(), not inside it, so the register, the
     * gradebook, report cards, the class store and the avatar answers keep the
     * exact shape they had.
     */
    protected function classPayload(Group $group, ?int $unreadMessages = null): array
    {
        if ($this->classSubjectsEnabled($group->masjid_id)) {
            return $this->classPayloadWithClassSubjects($group, $unreadMessages);
        }

        $students = $group->memberships()
            ->participants()->current()
            ->with('contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)
            ->get();

        // Students in a class only. This payload serves every kind of group a
        // teacher leads and its roster includes a legacy `leader` row, so the
        // condition lives in StudentAge::forRoster(), which reads the dates in
        // its own query and hands back numbers. For a ḥalaqa, a team or a
        // general group it reads nothing and every `age` below is null.
        $ages = StudentAge::forRoster($group, $students, $this->schoolToday($group));

        return [
            'id' => (int) $group->id,
            'name' => $group->name,
            'kind' => $group->kind(),
            'description' => $group->description,
            'is_active' => (bool) $group->is_active,
            'arabic_stage' => $group->arabicStage(),
            // How this class's points read: 'running' or 'weekly' (T-003.2).
            'points_period' => $group->pointsPeriod(),
            // What THIS teacher teaches in this class: null for everything (every
            // assignment before subjects existed, and a full-time teacher), else a
            // list. The screen hides the tabs a subject owns; the server refuses
            // them regardless (`teacher.teaches:`), so this is a courtesy, not the
            // boundary.
            'my_subjects' => $this->mySubjects($group),
            'subject_labels' => \App\Models\GroupStaff::SUBJECT_LABELS,
            'students' => $students->map(fn (GroupMembership $m): array => $this->student($m) + [
                'age' => $ages[(int) $m->id] ?? null,
            ])->values(),
        ] + ($unreadMessages !== null ? ['unread_messages' => $unreadMessages] : [])
          + $this->classStoreFlag($group);
    }

    protected function classPayloadWithClassSubjects(Group $group, ?int $unreadMessages = null): array
    {
        $students = $group->memberships()
            ->participants()->current()
            ->with('contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)
            ->get();

        // Students in a class only. This payload serves every kind of group a
        // teacher leads and its roster includes a legacy `leader` row, so the
        // condition lives in StudentAge::forRoster(), which reads the dates in
        // its own query and hands back numbers. For a ḥalaqa, a team or a
        // general group it reads nothing and every `age` below is null.
        $ages = StudentAge::forRoster($group, $students, $this->schoolToday($group));

        return [
            'id' => (int) $group->id,
            'name' => $group->name,
            'kind' => $group->kind(),
            'description' => $group->description,
            'is_active' => (bool) $group->is_active,
            'arabic_stage' => $group->arabicStage(),
            // How this class's points read: 'running' or 'weekly' (T-003.2).
            'points_period' => $group->pointsPeriod(),
            // What THIS teacher teaches in this class: null for everything (every
            // assignment before subjects existed, and a full-time teacher), else a
            // list. The screen hides the tabs a subject owns; the server refuses
            // them regardless (`teacher.teaches:`), so this is a courtesy, not the
            // boundary.
            'my_subjects' => $this->mySubjects($group),
            'subject_labels' => \App\Models\GroupStaff::SUBJECT_LABELS,
            'students' => $students->map(fn (GroupMembership $m): array => $this->student($m) + [
                'age' => $ages[(int) $m->id] ?? null,
            ])->values(),
        ] + ($unreadMessages !== null ? ['unread_messages' => $unreadMessages] : [])
          + $this->classStoreFlag($group)
          + \App\Support\SubjectFence::payload($group, Auth::user());
    }

    /**
     * `['class_store' => true]` when this school runs the class store (T-003.4), else NOTHING:
     * the key is added only when on, so a school without the grant gets exactly the payload it
     * always got. The screen shows the Store tab only when it reads `true`.
     *
     * @return array<string,bool>
     */
    private function classStoreFlag(Group $group): array
    {
        return SchoolSettings::classStore(SchoolSettings::org($group->masjid_id)) ? ['class_store' => true] : [];
    }

    /**
     * Today on the school's clock, read once per request however many classes the
     * payload lists (My Classes builds one classPayload per class), and not at all
     * for a group whose students carry no age.
     */
    private function schoolToday(Group $group): ?string
    {
        if (! $group->teachesStudents()) {
            return null;
        }

        return $this->todayByOrganisation[(int) $group->masjid_id]
            ??= SchoolCalendar::for((int) $group->masjid_id)->today();
    }

    /** @return list<string>|null */
    private function mySubjects(Group $group): ?array
    {
        return \App\Support\SubjectFence::assigned((int) $group->id, (int) Auth::id());
    }
}
