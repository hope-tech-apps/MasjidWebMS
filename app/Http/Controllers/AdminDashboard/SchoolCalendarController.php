<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SchoolCalendar\StoreSchoolClosureRequest;
use App\Http\Requests\Admin\SchoolCalendar\StoreSchoolYearRequest;
use App\Http\Requests\Admin\SchoolCalendar\UpdateSchoolClosureRequest;
use App\Http\Requests\Admin\SchoolCalendar\UpdateSchoolYearRequest;
use App\Models\AttendanceRecord;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Support\FormOptionSources;
use App\Support\SchoolCalendar;
use App\Support\SchoolCalendarPayload;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The office's school calendar: years and their no-school days.
 *
 * Behind `capability:school_calendar` (routes/admin.php). Tenant isolation is
 * the guardrail's, not hand-rolled: both models are BelongsToMasjid, so
 * findOrFail() on another organisation's id is a 404 and create() stamps the
 * bound tenant (.claude/rules/tenant-scoping.md).
 *
 * Every write answers with the WHOLE calendar, the shape GET returns, so the
 * screen never reassembles meeting days itself.
 *
 * What the requests refuse is shape and dates. What depends on OTHER records —
 * register marks, closures a year edit would strand, form answers — is checked
 * here, inside the write's transaction and under a lock on the school year's
 * row. The register save takes the same lock (AttendanceController::save), so
 * "no marks on this day" and "this day is closed" cannot both become true.
 * Refusals are thrown as ValidationException, which bootstrap/app.php renders
 * in the same {status:'failed', data:{field:[...]}} envelope as a FormRequest.
 */
class SchoolCalendarController extends Controller
{
    /** GET /api/admin/masjids/{masjid_id}/school-calendar */
    public function index($masjid_id): JsonResponse
    {
        return $this->calendar($masjid_id);
    }

    /** POST .../school-calendar/years */
    public function storeYear(StoreSchoolYearRequest $request, $masjid_id): JsonResponse
    {
        // masjid_id is never taken from the request: the creating hook stamps it.
        SchoolYear::create($request->safe()->only(['label', 'first_day', 'last_day']));

        return $this->calendar($masjid_id, Response::HTTP_CREATED);
    }

    /**
     * PUT .../school-calendar/years/{year_id}
     *
     * Refused when the new dates would strand a no-school day outside the year
     * or off its weekday. The dates are named, so the office can decide which to
     * move; silently deleting them would reopen those days on the register.
     */
    public function updateYear(UpdateSchoolYearRequest $request, $masjid_id, $year_id): JsonResponse
    {
        $year = SchoolYear::findOrFail($year_id);
        $data = $request->safe()->only(['label', 'first_day', 'last_day']);

        DB::transaction(function () use ($year, $data): void {
            $locked = SchoolYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();
            $weekday = SchoolCalendar::day($data['first_day'])->dayOfWeek;

            $stranded = $locked->closures()->orderBy('closed_on')->get()
                ->map(fn (SchoolClosure $c) => $c->closed_on->toDateString())
                ->reject(fn (string $day) => $day >= $data['first_day'] && $day <= $data['last_day']
                    && SchoolCalendar::day($day)->dayOfWeek === $weekday)
                ->values();

            if ($stranded->isNotEmpty()) {
                $field = $stranded->contains(fn (string $day) => $day > $data['last_day'])
                    && ! $stranded->contains(fn (string $day) => $day < $data['first_day'])
                    ? 'last_day' : 'first_day';

                throw ValidationException::withMessages([$field => sprintf(
                    'These dates would leave %s outside the school year or off its meeting day: %s. Remove %s first, or keep the year around %s.',
                    $stranded->count() === 1 ? 'a no-school day' : $stranded->count().' no-school days',
                    $stranded->map(fn (string $day) => SchoolCalendar::label($day))->implode('; '),
                    $stranded->count() === 1 ? 'it' : 'them',
                    $stranded->count() === 1 ? 'it' : 'them',
                )]);
            }

            $locked->update($data);
        });

        return $this->calendar($masjid_id);
    }

    /**
     * DELETE .../school-calendar/years/{year_id}
     *
     * Refused while anything still points at the year's days: its no-school days
     * (deleting would reopen those registers), register marks inside its dates,
     * or form answers naming its meeting days (they would lose their "No school"
     * labels). Every count is named. Deleting is for a year entered by mistake.
     */
    public function destroyYear($masjid_id, $year_id): JsonResponse
    {
        $year = SchoolYear::findOrFail($year_id);
        $masjidId = $this->masjidId($masjid_id);

        DB::transaction(function () use ($year, $masjidId): void {
            $locked = SchoolYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();
            $first = $locked->first_day->toDateString();
            $last = $locked->last_day->toDateString();

            $closures = $locked->closures()->count();
            $marks = AttendanceRecord::query()
                ->whereDate('session_date', '>=', $first)
                ->whereDate('session_date', '<=', $last)
                ->count();
            $answers = FormOptionSources::answersNaming($masjidId, SchoolCalendar::meetingDaysBetween($first, $last));

            $held = array_filter([
                $closures > 0 ? self::count($closures, 'no-school day') : null,
                $marks > 0 ? self::count($marks, 'attendance mark').' inside its dates' : null,
                $answers > 0 ? self::count($answers, 'form answer').' naming its days' : null,
            ]);

            if ($held !== []) {
                throw ValidationException::withMessages([
                    'year' => 'This school year cannot be deleted while it has '.implode(', ', $held).'.',
                ]);
            }

            $locked->delete();
        });

        return $this->calendar($masjid_id);
    }

    /**
     * POST .../school-calendar/closures
     *
     * THE RACE THIS CLOSES: a teacher saving the register while the office closes
     * the same day. The marks are counted inside this transaction, under the
     * year's row lock, which AttendanceController::save also takes before it
     * writes — so one of the two always sees the other.
     */
    public function storeClosure(StoreSchoolClosureRequest $request, $masjid_id): JsonResponse
    {
        $data = $request->safe()->only(['school_year_id', 'closed_on', 'reason']);

        DB::transaction(function () use ($data): void {
            $year = SchoolYear::query()->whereKey($data['school_year_id'])->lockForUpdate()->firstOrFail();

            $marks = AttendanceRecord::query()->whereDate('session_date', $data['closed_on'])->count();

            if ($marks > 0) {
                throw ValidationException::withMessages(['closed_on' => sprintf(
                    'A register was already taken on %s (%s), so it cannot become a no-school day. Clear those marks first if there really was no school.',
                    SchoolCalendar::label($data['closed_on']),
                    self::count($marks, 'attendance mark'),
                )]);
            }

            SchoolClosure::create([
                'school_year_id' => $year->id,
                'closed_on' => $data['closed_on'],
                'reason' => $data['reason'],
            ]);
        });

        return $this->calendar($masjid_id, Response::HTTP_CREATED);
    }

    /** PUT .../school-calendar/closures/{closure_id} — the reason only; a different day is a new closure. */
    public function updateClosure(UpdateSchoolClosureRequest $request, $masjid_id, $closure_id): JsonResponse
    {
        SchoolClosure::findOrFail($closure_id)->update(['reason' => $request->validated('reason')]);

        return $this->calendar($masjid_id);
    }

    /** DELETE .../school-calendar/closures/{closure_id} — the day is a school day again. */
    public function destroyClosure($masjid_id, $closure_id): JsonResponse
    {
        SchoolClosure::findOrFail($closure_id)->delete();

        return $this->calendar($masjid_id);
    }

    private function calendar($masjid_id, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => SchoolCalendarPayload::admin(SchoolCalendar::for($this->masjidId($masjid_id))),
        ], $status);
    }

    /** The bound tenant first, the route only as a fallback (tenant-scoping.md). */
    private function masjidId($masjid_id): int
    {
        return (int) (app(TenantContext::class)->get() ?? $masjid_id);
    }

    private static function count(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }
}
