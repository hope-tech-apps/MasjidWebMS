<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Support\GroupAudience;
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
            ->orderBy('name')
            ->get();
    }

    /**
     * A student, NAMES ONLY — the serialization boundary. Never widen this to
     * include a guardian, an email, a phone, notes or a login field.
     *
     * @return array{membership_id:int, contact:array{id:int,first_name:mixed,last_name:mixed,avatar:mixed}|null}
     */
    protected function student(GroupMembership $membership): array
    {
        $contact = $membership->contact;

        return [
            'membership_id' => (int) $membership->id,
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
     */
    protected function classPayload(Group $group): array
    {
        $students = $group->memberships()
            ->participants()
            ->with('contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)
            ->get();

        return [
            'id' => (int) $group->id,
            'name' => $group->name,
            'kind' => $group->kind(),
            'description' => $group->description,
            'is_active' => (bool) $group->is_active,
            'arabic_stage' => $group->arabicStage(),
            'students' => $students->map(fn (GroupMembership $m): array => $this->student($m))->values(),
        ];
    }
}
