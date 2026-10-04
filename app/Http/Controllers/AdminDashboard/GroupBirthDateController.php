<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\RecordStudentBirthDateRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Support\SchoolCalendar;
use App\Support\StudentAge;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A student's DATE OF BIRTH, for the office.
 *
 * The roster shows a whole-number age and nothing else. The date behind it is
 * read, set and cleared here, one person at a time. The two answers below that
 * carry it (GET and PUT) are the only ones in the application that do; the
 * contacts file of the school records export is the one other place it leaves
 * the database.
 *
 * ---------------------------------------------------------------------------
 * READING AND SETTING: STUDENTS IN CLASSES ONLY, BY ROSTER ROW
 * ---------------------------------------------------------------------------
 *
 * A date of birth is kept so a class list can show a child's age. That purpose
 * does not exist for a guardian entry, a teacher's `leader` row, or a member of
 * a ḥalaqa, a team or a general group, so GET and PUT refuse those with one
 * sentence (422) rather than quietly storing a date nobody asked for about an
 * adult. "A student in a class" is role `member` in a group that
 * `Group::teachesStudents()`: the same test StudentAge::forRoster() uses to
 * decide whose age a roster shows.
 *
 * The date lives on the CONTACT, not on the roster row: a child in two classes
 * has one birthday, and a move to another class takes nothing with it. The
 * roster row in the URL is how the office names the child and how this
 * controller knows the child is a student.
 *
 * ---------------------------------------------------------------------------
 * CLEARING: BY CONTACT, AND NEVER REFUSED
 * ---------------------------------------------------------------------------
 *
 * Because the date is on the contact, it outlives the roster row that let the
 * office type it: Remove on the child's only class row, an import undo, an
 * archived class, a class whose kind was changed, and a merge onto somebody who
 * is not a student all leave it there, encrypted and out of reach of the two
 * routes above. A parent who asks the school to delete it after the child has
 * gone must not meet an office with no button for it. So the clear names the
 * CONTACT, works for a soft-deleted one, and asks nothing else to be true.
 * It answers the same whether or not a date was held, so it cannot be used to
 * learn that a contact who is not a student has one on file.
 *
 * All three verbs take `manage contacts`, GET included: reading a child's date
 * of birth is not something a read-only login needs, and the whole-number age
 * on the roster is what `view contacts` gets.
 *
 * Tenant isolation is the guardrail, not hand-filtering: Group, GroupMembership
 * and Contact are all BelongsToMasjid, so another organization's ids are a 404
 * miss. See .claude/rules/tenant-scoping.md.
 */
class GroupBirthDateController extends Controller
{
    /** GET .../groups/{group_id}/members/{membership_id}/birth-date */
    public function show($masjid_id, $group_id, $membership_id)
    {
        [$contact, $refusal] = $this->student($group_id, $membership_id);

        return $refusal ?? $this->answer($contact);
    }

    /**
     * PUT .../groups/{group_id}/members/{membership_id}/birth-date
     *
     * Idempotent: saving a corrected date simply replaces the one on file, and
     * saving the same date again writes nothing.
     */
    public function update(RecordStudentBirthDateRequest $request, $masjid_id, $group_id, $membership_id)
    {
        [$contact, $refusal] = $this->student($group_id, $membership_id);

        if ($refusal) {
            return $refusal;
        }

        $contact->recordDateOfBirth(
            $request->string('date_of_birth')->toString(),
            $this->actor($request),
            'roster',
        );

        return $this->answer($contact, 'Date of birth saved.');
    }

    /**
     * DELETE .../contacts/{contact_id}/birth-date
     *
     * Clears it, for ANY contact of this organisation, deleted ones included.
     * Never refused: taking a child's date of birth off the record must not
     * depend on the child still being on a class list.
     */
    public function destroy(Request $request, $masjid_id, $contact_id)
    {
        $contact = Contact::withTrashed()->findOrFail($contact_id);   // tenant-scoped

        // Asked fresh, not from the half-minute memory: answering "removed"
        // from a stale "the column is not there yet" would be a success that
        // removed nothing. When it truly is not there, no date is.
        if (StudentAge::columnExists(fresh: true)) {
            $contact->recordDateOfBirth(null, $this->actor($request), 'contact');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Date of birth removed.',
            'data' => ['date_of_birth' => null, 'age' => null, 'unreadable' => false],
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /**
     * The student's contact, or the refusal to send instead.
     *
     * @return array{0: ?Contact, 1: ?\Illuminate\Http\JsonResponse}
     */
    private function student($group_id, $membership_id): array
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        if (! $group->teachesStudents() || $membership->role !== GroupMembership::ROLE_MEMBER) {
            return [null, response()->json([
                'status' => 'error',
                'message' => 'A date of birth is kept only for students in a class.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY)];
        }

        // bin/deploy serves this code a few seconds before `migrate` adds the
        // column. Refuse for that window rather than answer 500, or answer "no
        // date on file" about a child who has one.
        if (! StudentAge::columnExists()) {
            return [null, response()->json([
                'status' => 'error',
                'message' => 'Dates of birth are being switched on. Try again in a minute.',
            ], Response::HTTP_SERVICE_UNAVAILABLE)];
        }

        // The whole row, so the writer saves one column of a complete model.
        // `date_of_birth` is hidden, and this model is never returned.
        return [Contact::findOrFail($membership->contact_id), null];
    }

    /**
     * `{ date_of_birth, age, unreadable, school_today }`, the one shape GET and
     * PUT answer with.
     *
     * `unreadable` is true when something IS stored and cannot be read (written
     * under another key). The office then sees "enter it again" instead of an
     * empty field that looks as though nothing was ever typed. `school_today`
     * is the latest day the form may offer: the school's today, not the
     * browser's.
     */
    private function answer(Contact $contact, ?string $message = null)
    {
        // Read ONCE: each read of an unreadable value writes an ERROR line.
        $date = $contact->dateOfBirthOrNull();
        $today = SchoolCalendar::for((int) $contact->masjid_id)->today();

        return response()->json(array_filter([
            'status' => 'success',
            'message' => $message,
        ]) + [
            'data' => [
                'date_of_birth' => $date,
                'age' => StudentAge::fromDate($date, $today),
                'unreadable' => $date === null && $contact->holdsDateOfBirth(),
                'school_today' => $today,
            ],
        ], Response::HTTP_OK);
    }

    private function actor(Request $request): ?User
    {
        return $request->user() instanceof User ? $request->user() : null;
    }
}
