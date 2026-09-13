<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Events\DuplicateEventRequest;
use App\Http\Requests\Admin\Events\StoreEventRequest;
use App\Http\Requests\Admin\Events\UpdateEventRequest;
use App\Models\Event;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EventsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $events = Event::where('masjid_id', $masjid->id)->paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $events
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $eventInputs = $request->safe()->only(['title', 'details', 'place', 'start', 'end', 'link']);
            $eventInputs['masjid_id'] = $masjid->id;

            $event = Event::create($eventInputs);

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::EVENTS);

            return response()->json([
                'status' => 'success',
                'data' => $event
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'success',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($masjid_id, $event_id)
    {
        $event = Event::where('masjid_id', $masjid_id)->findOrFail($event_id);
        return response()->json([
            'status' => 'success',
            'data' => $event
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEventRequest $request, $masjid_id, $event_id)
    {
        try {
            $event = Event::where('masjid_id', $masjid_id)->findOrFail($event_id);

            $eventInputs = $request->safe()->only(['title', 'details', 'place', 'start', 'end', 'link']);
            $event->update($eventInputs);

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::EVENTS);

            return response()->json([
                'status' => 'success',
                'data' => $event
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'success',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Copy one event onto a list of new dates — "Duplicate this event" (T-042a).
     *
     * ## Why this and not a repeat rule
     *
     * T-042a is "every date of a repeating event is entered by hand today". The
     * two honest shapes are a recurrence RULE that generates dates, and a
     * DUPLICATE action that copies the wording onto dates a human typed. This
     * is the duplicate, chosen for reasons that are about this repository, not
     * about effort:
     *
     * 1. A rule needs a table, and a new table carrying `masjid_id` must either
     *    take `BelongsToMasjid` (with its own isolation test) or join the frozen
     *    HAND_SCOPED_LEGACY inventory in TenantScopingCoverageTest. `Event` is
     *    ON that inventory ("Pre-CRM public content, hand-scoped in
     *    EventsController"), and .claude/rules/tenant-scoping.md says the roster
     *    is a debt ledger that must not grow. A rule would therefore put one
     *    feature under two tenancy regimes at once — the exact shape that makes
     *    a cross-tenant leak easy to miss.
     * 2. Virtual occurrences would have to be re-derived in FOUR independent
     *    readers of this table: this admin index, Mobile\EventsController (which
     *    queries `whereBetween('start', ...)` on the raw column), the `events`
     *    section type on the website, and the assistant's `list_events` tool.
     *    Miss one and the phone shows a series the website does not. Both
     *    failures are silent.
     * 3. `events.start` is a naive datetime and nothing on this model carries a
     *    timezone. "Weekly at 7pm" across a DST boundary is a question this
     *    table cannot currently answer. Typed dates sidestep it: a human decided
     *    each one.
     *
     * What the admin loses is generation; what they keep is the 90% they were
     * actually retyping — title, details, place, link. Each copy is an ORDINARY,
     * INDEPENDENT row: editing one never rewrites another, and deleting one
     * never removes a sibling, because there is no series object to cascade
     * through. That independence is the feature, and EventDuplicateTest pins it.
     *
     * ## The guards, and what breaks without each
     *
     * - The source is resolved with the SAME hand-filter as show():
     *   `Event::where('masjid_id', $masjid_id)->findOrFail($event_id)`. `Event`
     *   has no global scope, so a bare `Event::findOrFail()` here would let a
     *   masjid admin copy — and publish under their own name — another
     *   organisation's event. The route also carries `tenant`, which 403s a
     *   foreign `{masjid_id}`; this filter is the second layer, for the case
     *   where the route is the admin's own and only the event id is foreign.
     * - The copies are stamped from `$source->masjid_id`, the value on the row
     *   we just PROVED belongs here, never from the raw route segment.
     * - An identically-titled event already sitting on one of the requested
     *   starts is refused, whole batch, with the colliding dates named. This is
     *   the double-click guard: without it, a second click quietly produces a
     *   twin of every copy and the admin finds out from a congregant.
     *   The comparison is done IN PHP over normalised `Y-m-d H:i` strings rather
     *   than as a `whereIn` against the DATETIME column, because that comparison
     *   is engine-dependent (MySQL coerces `'... 19:00'` to `19:00:00`, SQLite
     *   compares the literal text it stored) and a guard that silently matches
     *   nothing on production is worse than no guard at all — it would create
     *   exactly the twins it exists to prevent, with a green test suite.
     * - The whole fan-out runs in DB::transaction so a batch that fails halfway
     *   leaves nothing behind; a partially-created "series" is unfixable from
     *   the UI because the admin cannot tell which dates landed.
     * - MobileCache::EVENTS is flushed ONCE after the transaction commits.
     *   Every other write in this controller flushes it; forgetting it means the
     *   phone app shows a stale week for the whole TTL and the admin re-enters
     *   the events believing the save failed.
     *
     * ## No try/catch, on purpose
     *
     * store() and update() above catch \Exception and answer HTTP 500 with
     * `'status' => 'success'` — a live bug (flagged separately; not fixed here
     * because it changes the contract two other clients read). This method must
     * not inherit it: an unhandled failure belongs to the app's exception
     * renderer, which cannot claim success. The one failure this method DOES
     * own — the date collision — is an explicit 422 in the legacy
     * `{status:'failed'}` shape a BaseFormRequest rejection already uses, so the
     * Vue modal has one error path, not two.
     *
     * Response is the legacy `{status:'success', data:[...]}` envelope every
     * other method here returns — deliberately NOT `response()->api()`, which
     * would leave two payload shapes inside one controller and break the
     * `res.data.status === 'success'` check the events screens all make.
     */
    public function duplicate(DuplicateEventRequest $request, $masjid_id, $event_id)
    {
        $source = Event::where('masjid_id', $masjid_id)->findOrFail($event_id);

        // The verified owner of the row, not the raw route segment.
        $ownerMasjidId = (int) $source->masjid_id;

        $dates = $request->validated()['dates'];

        // Normalise both sides to 'Y-m-d H:i' and compare in PHP — see the
        // docblock for why this is not a whereIn on the DATETIME column.
        $requestedStarts = array_map(
            static fn (array $date): string => substr((string) $date['start'], 0, 16),
            $dates
        );

        $takenStarts = Event::where('masjid_id', $ownerMasjidId)
            ->where('title', $source->title)
            ->pluck('start')
            ->map(static fn ($start): string => substr((string) $start, 0, 16))
            ->all();

        $collisions = array_values(array_intersect($requestedStarts, $takenStarts));

        if ($collisions !== []) {
            return response()->json([
                'status' => 'failed',
                'data' => [
                    'dates' => [
                        '"' . $source->title . '" already exists on ' . implode(', ', $collisions)
                            . '. Nothing was copied — remove those dates and try again.',
                    ],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $created = DB::transaction(static function () use ($source, $dates, $ownerMasjidId): array {
            $rows = [];

            foreach ($dates as $date) {
                $rows[] = Event::create([
                    'masjid_id' => $ownerMasjidId,
                    // Copied verbatim: these are what the admin was retyping.
                    'title' => $source->title,
                    'details' => $source->details,
                    'place' => $source->place,
                    'link' => $source->link,
                    // Taken from the body: these are what makes the copy a copy.
                    'start' => $date['start'],
                    'end' => $date['end'] ?? null,
                ]);
            }

            return $rows;
        });

        MobileCache::flushMasjid($ownerMasjidId, MobileCache::EVENTS);

        return response()->json([
            'status' => 'success',
            'data' => $created,
        ], Response::HTTP_OK);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($masjid_id, $event_id)
    {
        $event = Event::where('masjid_id', $masjid_id)->findOrFail($event_id);
        $event->forceDelete();

        MobileCache::flushMasjid((int) $masjid_id, MobileCache::EVENTS);

        return response()->json([
            'status' => 'success',
            'data' => $event
        ], Response::HTTP_OK);
    }
}
