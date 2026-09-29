<?php

namespace App\Support;

use App\Models\GroupStaff;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Only access specific to the subject they're teaching" (owner, 2026-09-21),
 * applied to the GRADEBOOK and to LESSON PLANS (2026-09-29, W3).
 *
 * Until now the promise held only for the Arabic letters and hifdh, through the
 * `teacher.teaches:` middleware. Grades sat outside both fences, so a BISS teacher
 * limited to Qur'an could list, set, edit and mark Arabic work. This is the same
 * rule for work that carries a subject.
 *
 * ## The rule
 *
 * A teacher whose assignment to a class lists subjects (`group_staff.subjects`)
 * is LIMITED to them; NULL or an empty list is unrestricted, exactly as
 * GroupStaff::teaches() reads it. A limited teacher may touch a piece of work
 * only when its subject maps to a staff subject they teach
 * (SubjectKey::staffKeys). So a Qur'an-only teacher gets "Qur'an" and the
 * school's combined "Qur'an & Islamic Studies" column, and does not get Arabic
 * Language, Islamic Studies alone, or Mathematics. Work with NO subject belongs to
 * no staff subject, so it is hidden from a limited teacher and they cannot create
 * it: a limited teacher must always name the subject, which is what stops "leave
 * the subject blank" from being a way round the fence.
 *
 * ## Who is limited
 *
 * Only a signed-in TEACHER: the office reads the same controller through the
 * admin realm (read-only) and must see everything, so an admin who also holds a
 * group_staff row is never fenced when acting as the office.
 *
 * ## LESSON PLANS are looser on one point, deliberately
 *
 * A plan with no subject is "the day's general plan", first-class since the
 * feature shipped (BISS uses no pacing guide and writes only those). Fencing it
 * would strand every existing general plan. So a limited teacher keeps the general
 * plan and is fenced only on plans that name a subject. Recorded in DECISIONS.md.
 */
final class SubjectFence
{
    /**
     * What `$user` is limited to in `$groupId`: a list of staff subjects, or NULL
     * for everything.
     *
     * @return list<string>|null
     */
    public static function limitsFor(?User $user, int $groupId): ?array
    {
        if ($user === null || $user->type !== 'Teacher') {
            return null;
        }

        return self::assigned($groupId, (int) $user->getKey());
    }

    /**
     * The staff subjects one assignment lists, NULL when it lists none (which
     * means all). The single reader of `group_staff.subjects`, shared with the
     * `my_subjects` field of the teacher's class payload.
     *
     * @return list<string>|null
     */
    public static function assigned(int $groupId, int $userId): ?array
    {
        $subjects = GroupStaff::query()
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->value('subjects');

        if (is_string($subjects)) {
            $subjects = json_decode($subjects, true);
        }

        return is_array($subjects) && $subjects !== [] ? array_values($subjects) : null;
    }

    /** May a teacher with these limits touch work whose subject key is `$subjectKey`? */
    public static function allows(?array $limits, ?string $subjectKey): bool
    {
        if ($limits === null) {
            return true;
        }

        return array_intersect(SubjectKey::staffKeys((string) $subjectKey), $limits) !== [];
    }

    /**
     * The subject keys a query may include for these limits; NULL for no filter.
     * Used as `whereIn('subject_key', ...)`.
     *
     * @return list<string>|null
     */
    public static function allowedKeys(?array $limits): ?array
    {
        return $limits === null ? null : SubjectKey::keysFor($limits);
    }

    /**
     * Refuse, in the words the `teacher.teaches:` middleware uses so a teacher
     * hears one sentence for one rule.
     */
    public static function refuse(?string $subjectName): never
    {
        $label = SubjectKey::clean($subjectName);

        abort(
            Response::HTTP_FORBIDDEN,
            $label === null
                ? 'You teach only some subjects in this class, so choose the subject this is for.'
                : "You do not teach {$label} in this class."
        );
    }
}
