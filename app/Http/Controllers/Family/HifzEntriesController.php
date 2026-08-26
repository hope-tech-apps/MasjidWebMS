<?php

namespace App\Http\Controllers\Family;

use App\Models\Contact;
use App\Models\Group;
use App\Models\HifzEntry;
use App\Support\HifzProgress;
use App\Support\QuranIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A parent's view of their own child's ḥifẓ record (T-014 surface, T-015e
 * audience).
 *
 * The same two gates as the behaviour surface, for the same reasons: the
 * endpoint asks `mayReceiveHifzAbout()` about the named membership (an honest
 * 403), and the listing runs through `readableHifzQuery()` (a forbidden entry is
 * never fetched, so it cannot reach a page, a total, or the memorisation
 * aggregate). `HifzProgress::summarize()` is handed an ALREADY audience-
 * constrained query and never decides access itself — that contract is why the
 * summary is safe to compute at all.
 *
 * ---------------------------------------------------------------------------
 * The domain rules travel with the payload, because they are the payload
 * ---------------------------------------------------------------------------
 *
 * `.claude/rules/groups.md` is emphatic and this endpoint inherits all of it by
 * calling the same code rather than re-deriving anything:
 *
 *   - ONLY SABAK ADVANCES. A manzil entry over al-Baqarah does not mean a child
 *     memorising juz 30 went backwards.
 *   - PROGRESS IS A POSITION, NEVER A PERCENTAGE. Surah + ayah + juz. There is
 *     no percentage field here and none must be added "for the parent app" — a
 *     percentage is a number no ḥalaqa uses and no ijāza recognises, and it is
 *     exactly the kind of thing a family-facing screen invents. `total_ayahs`
 *     rides along so a client can render "231 of 6236" without hardcoding the
 *     denominator, NOT so it can divide.
 *   - Current position is the LATEST sabak, not the furthest. Both are served
 *     because they are different facts, and reporting the high-water mark would
 *     tell a parent their child is somewhere they are not.
 *
 * There is deliberately no group-wide progress endpoint and no comparison
 * payload — "who has memorised most" is the public shaming this module refuses,
 * aimed at Qur'an.
 */
class HifzEntriesController extends FamilyController
{
    /**
     * GET .../groups/{group_id}/members/{membership_id}/hifz
     */
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = $this->group($group_id);
        $membership = $this->subject($group, $membership_id);

        $entries = $this->readable($group)
            ->where('group_membership_id', $membership->id)
            ->recitedBetween($request->query('from'), $request->query('to'))
            ->when(
                // is_string, not filled(): `?kind[]=sabak` would otherwise reach
                // a string cast. A malformed filter must be inert, not fatal.
                is_string($request->query('kind')) && $request->query('kind') !== '',
                fn (Builder $q) => $q->ofKind((string) $request->query('kind'))
            )
            ->with(['membership.contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS, 'heardBy:id,name'])
            ->orderByDesc('recited_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25))
            ->through(fn (HifzEntry $entry) => $this->serialize($entry));

        return response()->json([
            'status' => 'success',
            'data' => $entries,
            'meta' => $this->meta(['student' => $this->student($membership)]),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../members/{membership_id}/hifz/progress
     */
    public function progress(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = $this->group($group_id);
        $membership = $this->subject($group, $membership_id);

        $summary = HifzProgress::summarize(
            // Constrained by the audience rule FIRST, then narrowed to this
            // student: the aggregate can only ever be computed over rows this
            // parent was already entitled to.
            $this->readable($group)->where('group_membership_id', $membership->id),
            $this->revisionWindow($request)
        );

        return response()->json([
            'status' => 'success',
            'data' => ['student' => $this->student($membership)] + $summary,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /**
     * Null would mean no standing in the ḥalaqa at all — unreachable, because
     * `subject()` has already refused such a caller. Pinned shut anyway: an
     * unconstrained fallback is the one failure mode this surface must never
     * have.
     */
    private function readable(Group $group): Builder
    {
        $query = $this->audience->readableHifzQuery($this->contact(), $group);

        if ($query === null) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this group\'s ḥifẓ records.');
        }

        return $query;
    }

    private function revisionWindow(Request $request): int
    {
        $default = max(1, (int) config('groups.hifz.revision_window_days', 30));

        if (! $request->filled('window')) {
            return $default;
        }

        return max(1, min(365, $request->integer('window')));
    }

    /**
     * One recitation as a parent sees it.
     *
     * The mistake counts and the teacher's note are included: they are the
     * substance of what was heard, and a record a parent cannot read the detail
     * of is not a record they have been given.
     *
     * @return array<string,mixed>
     */
    private function serialize(HifzEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'student' => $entry->membership ? $this->student($entry->membership) : null,
            'kind' => $entry->kind(),
            // Ayah-precise and edition-independent. No page number, because a
            // page is meaningless without naming which muṣḥaf it refers to.
            'from' => QuranIndex::describe((int) $entry->from_surah, (int) $entry->from_ayah),
            'to' => QuranIndex::describe((int) $entry->to_surah, (int) $entry->to_ayah),
            'ayahs' => $entry->ayahCount(),
            'quality' => $entry->quality(),
            'major_mistakes' => (int) $entry->major_mistakes,
            'minor_mistakes' => (int) $entry->minor_mistakes,
            'note' => $entry->note,
            'recited_at' => optional($entry->recited_at)->toIso8601String(),
            'heard_by' => $entry->heardBy ? ['name' => $entry->heardBy->name] : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function meta(array $extra = []): array
    {
        return parent::meta($extra + [
            'kinds' => HifzEntry::KINDS,
            'qualities' => HifzEntry::QUALITIES,
            'total_ayahs' => QuranIndex::TOTAL_AYAHS,
        ]);
    }
}
