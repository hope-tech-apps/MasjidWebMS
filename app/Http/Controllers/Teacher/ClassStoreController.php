<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\ClassStore\CashOutRequest;
use App\Http\Requests\ClassStore\RedeemPrizeRequest;
use App\Http\Requests\ClassStore\ReversePrizeEntryRequest;
use App\Http\Requests\ClassStore\SavePrizeRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Prize;
use App\Models\PrizeLedgerEntry;
use App\Support\ClassStore;
use App\Support\ClassStorePayload;
use App\Support\ClassStoreRefusal;
use App\Support\ClassStoreSettings;
use App\Support\SchoolCalendar;
use App\Support\SchoolPointsWeek;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The class store, for the class's own teachers (T-003.4, owner B6: "points convert to
 * Manara Bucks; students spend them in a class store the teacher runs; the app keeps each
 * balance").
 *
 * Every route here sits inside `teacher.leads` (this teacher leads THIS class) AND
 * `capability:class_store` (the school has the store on: OFF for every organisation until a
 * SuperAdmin decides). What is served:
 *
 *   - `index`     each student's balance, in ROSTER order: no rank, no sort by bucks, no prize
 *                 wall, no class-wide comparison. The teacher's overview, like the Points tab;
 *   - `forMember` one student's history and balance;
 *   - `prizes`    the shelf: the school-wide list plus THIS class's own;
 *   - the writes: redeem, reverse, cash out (built, OFF), and a class prize (create, edit,
 *     retire). The realm's +5 write verbs.
 *
 * EVERY BALANCE IS READ THROUGH GroupAudience (`readablePrizeLedgerQuery`): a forbidden row
 * is never fetched, so it cannot surface in a page or a SUM. A write is authorised by the
 * same audience first, and then ClassStore does the locked, checked, idempotent write.
 * There is NO update route and NO delete route for a ledger entry: a correction is a
 * reversal, a new row.
 *
 * Tenant isolation is the guardrail, not hand-filtering: Group, GroupMembership, Prize and
 * PrizeLedgerEntry are BelongsToMasjid, so another school's id anywhere is a MISS.
 */
class ClassStoreController extends TeacherController
{
    /** GET .../groups/{group_id}/bucks */
    public function index($masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $readable = $this->readable($group);

        $students = $group->memberships()
            ->participants()->current()
            ->with('contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)
            ->orderBy('id')
            ->get();

        $balances = ClassStore::balances($readable, $students->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $settings = ClassStoreSettings::for((int) $group->masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'students' => $students->map(fn (GroupMembership $m): array => $this->student($m) + [
                    'balance' => (int) ($balances[$m->id] ?? 0),
                ])->values(),
                'settings' => [
                    'points_per_buck' => $settings['points_per_buck'],
                    'paper_bucks_enabled' => $settings['paper_bucks_enabled'],
                ],
            ],
        ], Response::HTTP_OK);
    }

    /** GET .../groups/{group_id}/members/{membership_id}/bucks */
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->with('contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)->findOrFail($membership_id);

        $this->authorizeSubject($request, $group, $membership);

        $readable = $this->readable($group);

        $page = $readable->clone()
            ->where('group_membership_id', $membership->id)
            ->with('createdBy:id,name')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        $reversed = $this->reversedAmong($readable, $page->getCollection()->pluck('id')->all());

        $page->through(fn (PrizeLedgerEntry $e): array => ClassStorePayload::entryForTeacher($e, in_array((int) $e->id, $reversed, true)));

        return response()->json([
            'status' => 'success',
            'data' => $page,
            'meta' => [
                'student' => $this->student($membership),
                'balance' => (int) (ClassStore::balances($readable, [(int) $membership->id])[$membership->id] ?? 0),
            ],
        ], Response::HTTP_OK);
    }

    /** GET .../groups/{group_id}/prizes */
    public function prizes($masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $prizes = Prize::query()->availableTo($group)->inShelfOrder()->get();

        return response()->json([
            'status' => 'success',
            'data' => $prizes->map(fn (Prize $p): array => ClassStorePayload::prize($p, (int) $group->id))->values(),
        ], Response::HTTP_OK);
    }

    /** POST .../groups/{group_id}/members/{membership_id}/prizes/redeem */
    public function redeem(RedeemPrizeRequest $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $this->authorizeSubject($request, $group, $membership);

        // Tenant-scoped, so another school's prize is simply not found. The 422 (not a 404)
        // because the id arrived in the payload, not the path: no resource is being addressed,
        // and a 404 would confirm which ids exist elsewhere.
        $prize = Prize::query()->find($request->integer('prize_id'));

        if ($prize === null) {
            return $this->refusal(new ClassStoreRefusal('prize_unknown', 'That id names no prize in this school.'));
        }

        try {
            $done = ClassStore::redeem(
                $group, $membership, $prize, $request->user(),
                $request->filled('request_id') ? (string) $request->input('request_id') : null,
                $request->filled('note') ? (string) $request->input('note') : null,
            );
        } catch (ClassStoreRefusal $e) {
            return $this->refusal($e);
        }

        return $this->written($group, $membership, $done, $prize);
    }

    /** POST .../groups/{group_id}/prize-entries/{entry_id}/reverse */
    public function reverse(ReversePrizeEntryRequest $request, $masjid_id, $group_id, $entry_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        // Found through the audience-constrained query, so an entry this teacher may not read
        // (another class's, another school's) is a plain 404.
        $entry = $this->readable($group)->whereKey($entry_id)->firstOrFail();

        try {
            $done = ClassStore::reverse(
                $group, $entry, $request->user(),
                $request->filled('note') ? (string) $request->input('note') : null,
            );
        } catch (ClassStoreRefusal $e) {
            return $this->refusal($e);
        }

        $membership = $group->memberships()->findOrFail($entry->group_membership_id);

        return $this->written($group, $membership, $done, null);
    }

    /** POST .../groups/{group_id}/members/{membership_id}/prizes/cash-out (built, OFF) */
    public function cashOut(CashOutRequest $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $this->authorizeSubject($request, $group, $membership);

        try {
            $done = ClassStore::cashOut(
                $group, $membership, $request->integer('amount'), $request->user(),
                $request->filled('request_id') ? (string) $request->input('request_id') : null,
                $request->filled('note') ? (string) $request->input('note') : null,
            );
        } catch (ClassStoreRefusal $e) {
            return $this->refusal($e);
        }

        return $this->written($group, $membership, $done, null);
    }

    /**
     * GET .../groups/{group_id}/bucks/handout[?date=YYYY-MM-DD] (built, OFF)
     *
     * The printable class hand-out: every cash-out entry of one school day (today unless
     * `date`), not since reversed, with the 20/10/5/1 notes to hand each student and the
     * class's total notes to count out. Refused while paper Bucks are off.
     */
    public function handout(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        if (! ClassStoreSettings::paperEnabled((int) $group->masjid_id)) {
            return $this->refusal(new ClassStoreRefusal('paper_bucks_off', 'Paper Manara Bucks are not switched on for this school.', 403));
        }

        $tz = SchoolPointsWeek::timezone((int) $group->masjid_id);
        $day = $request->query('date');

        if ($day !== null && $day !== '' && (! is_string($day) || SchoolCalendar::day($day) === null)) {
            return response()->json(['status' => 'error', 'message' => 'The date must be a day such as 2026-10-04.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $localDay = is_string($day) && $day !== '' ? $day : CarbonImmutable::now($tz)->format('Y-m-d');
        $start = CarbonImmutable::parse($localDay.' 00:00:00', $tz)->utc();
        $end = $start->setTimezone($tz)->addDay()->startOfDay()->utc();

        $readable = $this->readable($group);

        $entries = $readable->clone()
            ->where('kind', PrizeLedgerEntry::KIND_CASHED_OUT)
            ->where('occurred_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('occurred_at', '<', $end->format('Y-m-d H:i:s'))
            ->with('membership.contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)
            ->orderBy('group_membership_id')
            ->orderBy('id')
            ->get();

        $reversed = $this->reversedAmong($readable, $entries->pluck('id')->all());
        $live = $entries->reject(fn (PrizeLedgerEntry $e) => in_array((int) $e->id, $reversed, true));

        $notes = array_fill_keys(array_map('strval', PrizeLedgerEntry::NOTES), 0);
        foreach ($live as $e) {
            foreach ((array) $e->breakdown as $note => $count) {
                $notes[(string) $note] = ($notes[(string) $note] ?? 0) + (int) $count;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'date' => $localDay,
                'entries' => $live->map(fn (PrizeLedgerEntry $e): array => [
                    'id' => (int) $e->id,
                    'student' => $e->membership ? $this->student($e->membership) : null,
                    // A cash-out is stored as a debit, so the amount handed over is its magnitude.
                    'amount' => abs((int) $e->amount),
                    'breakdown' => $e->breakdown,
                ])->values(),
                'totals' => [
                    'amount' => (int) $live->sum(fn (PrizeLedgerEntry $e) => abs((int) $e->amount)),
                    'notes' => $notes,
                ],
            ],
        ], Response::HTTP_OK);
    }

    /** POST .../groups/{group_id}/prizes: this class's own prize */
    public function storePrize(SavePrizeRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $prize = Prize::create([
            // The route decides the shelf: a teacher can only ever write their own class's.
            'group_id' => $group->id,
            'title' => (string) $request->input('title'),
            'description' => $request->input('description'),
            'cost_bucks' => $request->integer('cost_bucks'),
            'stock' => $request->filled('stock') ? $request->integer('stock') : null,
            'is_active' => $request->has('is_active') ? (bool) $request->boolean('is_active') : true,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => ClassStorePayload::prize($prize, (int) $group->id),
        ], Response::HTTP_CREATED);
    }

    /** PUT .../groups/{group_id}/prizes/{prize_id}: edit or retire this class's own prize */
    public function updatePrize(SavePrizeRequest $request, $masjid_id, $group_id, $prize_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        // ONLY this class's own prize: a school-wide prize (the office's) and another class's
        // are both a MISS here.
        $prize = Prize::query()->where('group_id', $group->id)->findOrFail($prize_id);

        foreach (['title', 'description', 'cost_bucks', 'stock', 'is_active'] as $field) {
            if (! $request->exists($field)) {
                continue;
            }

            $prize->{$field} = match ($field) {
                'cost_bucks' => $request->integer('cost_bucks'),
                'stock' => $request->filled('stock') ? $request->integer('stock') : null,
                'is_active' => (bool) $request->boolean('is_active'),
                default => $request->input($field),
            };
        }

        $prize->save();

        return response()->json([
            'status' => 'success',
            'data' => ClassStorePayload::prize($prize->fresh(), (int) $group->id),
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /**
     * The class's readable ledger, or a 403. Always through GroupAudience, so a balance is as
     * private as an award.
     */
    private function readable(Group $group): Builder
    {
        $query = $this->audience->readablePrizeLedgerQuery(request()->user(), $group);

        if ($query === null) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this class\'s Manara Bucks.');
        }

        return $query;
    }

    private function authorizeSubject(Request $request, Group $group, GroupMembership $subject): void
    {
        if (! $this->audience->mayReceiveAwardsAbout($request->user(), $group, $subject)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this student\'s Manara Bucks.');
        }
    }

    /**
     * Which of these entry ids has a reversal pointing at it, asked of the SAME audience-
     * constrained query so a reversal the caller may not read never shows.
     *
     * @param  Builder<PrizeLedgerEntry>  $readable
     * @param  array<int,int|string>  $ids
     * @return array<int,int>
     */
    private function reversedAmong(Builder $readable, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $readable->clone()
            ->whereIn('reverses_entry_id', $ids)
            ->pluck('reverses_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * What a write answers: the row, and the student's balance now (read back through the
     * audience, never the private figure the write checked).
     *
     * @param  array{entry:PrizeLedgerEntry,replayed:bool}  $done
     */
    private function written(Group $group, GroupMembership $membership, array $done, ?Prize $prize): JsonResponse
    {
        $readable = $this->readable($group);
        $entry = $done['entry']->load('createdBy:id,name');
        $reversed = $this->reversedAmong($readable, [(int) $entry->id]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'entry' => ClassStorePayload::entryForTeacher($entry, in_array((int) $entry->id, $reversed, true)),
                'balance' => (int) (ClassStore::balances($readable, [(int) $membership->id])[$membership->id] ?? 0),
                'prize' => $prize !== null ? ClassStorePayload::prize($prize->fresh() ?? $prize, (int) $group->id) : null,
                // True when this was a repeat of a request already written: nothing new happened.
                'replayed' => $done['replayed'],
            ],
        ], $done['replayed'] ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    private function refusal(ClassStoreRefusal $e): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'reason' => $e->reason,
            'message' => $e->getMessage(),
        ], $e->status);
    }
}
