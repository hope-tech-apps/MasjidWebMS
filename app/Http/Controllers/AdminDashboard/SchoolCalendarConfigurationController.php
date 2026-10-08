<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SchoolCalendar\{StoreSchoolYearRequest, UpdateSchoolYearRequest, StoreSchoolClosureRequest, StoreSchoolTermRequest};
use App\Models\{Masjid, SchoolYear, SchoolClosure, SchoolTerm, AttendanceRecord};
use App\Support\{SchoolCalendar, SchoolDateAuthority, SchoolSettings, TenantContext, FormOptionSources};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** ON office writes only. The unchanged calendar controller owns the OFF path. */
class SchoolCalendarConfigurationController extends Controller
{
    /** First statement in each transaction: organisation PK record lock, no read view yet. */
    private function lockOrganisation(int $id): void
    {
        $org = Masjid::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if (! SchoolSettings::calendarTerms($org)) {
            throw ValidationException::withMessages(['calendar' => 'Meeting days and dated terms are switched off. Reload the calendar and try again.']);
        }
    }

    private function org($route): int { return (int) (app(TenantContext::class)->get() ?? $route); }

    private function data(StoreSchoolYearRequest $request): array
    {
        $data = $request->safe()->only(['label','first_day','last_day','meeting_weekdays','term_system']);
        $data['meeting_weekdays'] = array_map('intval', $data['meeting_weekdays']);
        sort($data['meeting_weekdays']);
        return $data;
    }

    private function refuseOverlap(int $org, array $data, ?int $ignore): void
    {
        $overlap = SchoolCalendar::overlappingYear($org, $data['first_day'], $data['last_day'], $ignore);
        if ($overlap) throw ValidationException::withMessages(['first_day' => SchoolCalendar::overlapMessage($overlap)]);
    }

    public function storeYear(StoreSchoolYearRequest $request, $masjid_id): JsonResponse
    {
        $org = $this->org($masjid_id); $data = $this->data($request);
        DB::transaction(function () use ($org, $data) {
            $this->lockOrganisation($org);
            $this->refuseOverlap($org, $data, null);
            SchoolYear::create($data);
        });
        return $this->calendar($masjid_id, 201);
    }

    public function updateYear(UpdateSchoolYearRequest $request, $masjid_id, $year_id): JsonResponse
    {
        $year = SchoolYear::findOrFail($year_id); $org = $this->org($masjid_id); $data = $this->data($request);
        DB::transaction(function () use ($org, $year, $data) {
            $this->lockOrganisation($org);
            $locked = SchoolYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();
            $this->refuseOverlap($org, $data, $year->id);
            $removed = array_diff(SchoolDateAuthority::weekdays($locked), $data['meeting_weekdays']);
            $closures = $locked->closures()->get();
            $off = $closures->filter(fn ($c) => in_array($c->closed_on->dayOfWeek, $removed, true));
            if ($off->isNotEmpty()) {
                throw ValidationException::withMessages(['meeting_weekdays' => 'Remove the no-school days on removed weekdays first: '.$off->map(fn ($c) => SchoolCalendar::label($c->closed_on->toDateString()))->implode('; ').'.']);
            }
            $stranded = $closures->filter(fn ($c) => $c->closed_on->toDateString() < $data['first_day'] || $c->closed_on->toDateString() > $data['last_day']);
            if ($stranded->isNotEmpty()) throw ValidationException::withMessages(['first_day' => 'These dates would leave no-school days outside the school year. Remove those no-school days first.']);
            // Ordinary check after the parent lock: attendance save uses this same year lock.
            $marks = AttendanceRecord::query()->where('masjid_id', $org)
                ->whereDate('session_date', '>=', $locked->first_day->toDateString())
                ->whereDate('session_date', '<=', $locked->last_day->toDateString())->get(['session_date']);
            if ($marks->contains(fn ($m) => in_array(SchoolCalendar::day(substr((string) $m->session_date, 0, 10))->dayOfWeek, $removed, true))) {
                throw ValidationException::withMessages(['meeting_weekdays' => 'Attendance exists on a removed weekday. Keep that weekday or clear those marks first.']);
            }
            if ($locked->terms()->get()->contains(fn ($t) => $t->starts_on->toDateString() < $data['first_day'] || $t->ends_on->toDateString() > $data['last_day'])) {
                throw ValidationException::withMessages(['first_day' => 'These dates would leave a term outside the school year. Change or remove that term first.']);
            }
            // Include closures: stored answers may name any former meeting day.
            $leaving = [];
            $day = SchoolCalendar::day($locked->first_day->toDateString());
            for (; $day->toDateString() <= $locked->last_day->toDateString(); $day = $day->addDay()) {
                $date = $day->toDateString();
                if (in_array($day->dayOfWeek, SchoolDateAuthority::weekdays($locked), true)
                    && ($date < $data['first_day'] || $date > $data['last_day'] || ! in_array($day->dayOfWeek, $data['meeting_weekdays'], true))) {
                    $leaving[] = $date;
                }
            }
            $answers = FormOptionSources::answersNaming($org, $leaving);
            if ($answers) {
                $field = $removed ? 'meeting_weekdays' : 'first_day';
                throw ValidationException::withMessages([$field => 'This change would remove a school day named by '.$answers.' form answer'.($answers === 1 ? '' : 's').'. Keep those days or change those answers first.']);
            }
            $locked->update($data);
        });
        return $this->calendar($masjid_id);
    }

    public function destroyYear($masjid_id, $year_id): JsonResponse
    {
        $year = SchoolYear::findOrFail($year_id); $org = $this->org($masjid_id);
        DB::transaction(function () use ($year, $org) {
            $this->lockOrganisation($org);
            $locked = SchoolYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();
            $first = $locked->first_day->toDateString(); $last = $locked->last_day->toDateString();
            $closures = $locked->closures()->count();
            $marks = AttendanceRecord::query()->where('masjid_id', $org)->whereDate('session_date','>=',$first)->whereDate('session_date','<=',$last)->count();
            $answers = FormOptionSources::answersNaming($org, SchoolDateAuthority::for($org)->meetingDays($locked));
            $held = array_filter([$closures ? "$closures no-school day".($closures === 1 ? '' : 's') : null, $marks ? "$marks attendance mark".($marks === 1 ? '' : 's')." inside its dates" : null, $answers ? "$answers form answer".($answers === 1 ? '' : 's')." naming its days" : null]);
            if ($held) throw ValidationException::withMessages(['year' => 'This school year cannot be deleted while it has '.implode(', ', $held).'.']);
            $locked->delete();
        });
        return $this->calendar($masjid_id);
    }

    public function storeClosure(StoreSchoolClosureRequest $request, $masjid_id): JsonResponse
    {
        $org = $this->org($masjid_id); $data = $request->safe()->only(['school_year_id','closed_on','reason']);
        try {
            DB::transaction(fn () => $this->writeClosure($data, $org));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['closed_on' => SchoolCalendar::label($data['closed_on']).' is already a no-school day.']);
        }
        return $this->calendar($masjid_id, 201);
    }

    /** The year PK is locked before reading marks or inserting a child. */
    public function writeClosure(array $data, int $org): void
    {
        $this->lockOrganisation($org);
        $year = SchoolYear::query()->whereKey($data['school_year_id'])->lockForUpdate()->firstOrFail();
        if ((int) $year->masjid_id !== $org) abort(404);
        $day = $data['closed_on'];
        if ($day < $year->first_day->toDateString() || $day > $year->last_day->toDateString()
            || ! in_array(SchoolCalendar::day($day)?->dayOfWeek, SchoolDateAuthority::weekdays($year), true)) {
            throw ValidationException::withMessages(['closed_on' => 'This date is no longer inside its school year or on a configured meeting weekday. Reload the calendar and try again.']);
        }
        $marks = AttendanceRecord::query()->where('masjid_id',$org)->whereDate('session_date',$day)->count();
        if ($marks) throw ValidationException::withMessages(['closed_on' => sprintf('A register was already taken on %s (%d attendance mark%s), so it cannot become a no-school day. Clear those marks first if there really was no school.', SchoolCalendar::label($day), $marks, $marks === 1 ? '' : 's')]);
        SchoolClosure::create(['school_year_id' => $year->id, 'closed_on' => $day, 'reason' => $data['reason']]);
    }

    /** POST/PUT nested terms; resolve tenant and parent before the transaction. */
    public function storeTerm(StoreSchoolTermRequest $request, $masjid_id, $year_id): JsonResponse
    {
        return $this->writeTerm($request, $masjid_id, $year_id, null);
    }

    public function updateTerm(StoreSchoolTermRequest $request, $masjid_id, $year_id, $term_id): JsonResponse
    {
        return $this->writeTerm($request, $masjid_id, $year_id, $term_id);
    }

    private function writeTerm(StoreSchoolTermRequest $request, $route, $yearId, $termId): JsonResponse
    {
        $this->requireEnabled($route);
        $year = SchoolYear::findOrFail($yearId);
        $term = $termId === null ? null : $year->terms()->findOrFail($termId);
        $org = $this->org($route); $data = $request->safe()->only(['name','starts_on','ends_on','position']);
        DB::transaction(function () use ($org, $year, $term, $data) {
            $this->lockOrganisation($org);
            $locked = SchoolYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();
            if ($data['starts_on'] < $locked->first_day->toDateString() || $data['ends_on'] > $locked->last_day->toDateString()) {
                throw ValidationException::withMessages(['starts_on' => 'A term must be inside its school year.']);
            }
            foreach ($locked->terms()->get() as $other) {
                if ($other->id === $term?->id) continue;
                if ($other->position === (int) $data['position']) throw ValidationException::withMessages(['position' => 'That term position is already in use.']);
                if ($data['starts_on'] <= $other->ends_on->toDateString() && $data['ends_on'] >= $other->starts_on->toDateString()) throw ValidationException::withMessages(['starts_on' => 'Term dates must not overlap.']);
                if (($other->position < (int) $data['position'] && $other->ends_on->toDateString() >= $data['starts_on']) || ($other->position > (int) $data['position'] && $other->starts_on->toDateString() <= $data['ends_on'])) throw ValidationException::withMessages(['position' => 'Term positions must follow date order.']);
            }
            if ($term) {
                // Lock the existing child by PK after its parent, never a range.
                $child = SchoolTerm::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();
                $child->update($data);
            } else {
                $locked->terms()->create($data);
            }
        });
        return $this->calendar($route, $term ? 200 : 201);
    }

    public function destroyTerm($masjid_id, $year_id, $term_id): JsonResponse
    {
        $this->requireEnabled($masjid_id);
        $year = SchoolYear::findOrFail($year_id); $term = $year->terms()->findOrFail($term_id); $org = $this->org($masjid_id);
        DB::transaction(function () use ($org, $year, $term) {
            $this->lockOrganisation($org);
            SchoolYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();
            SchoolTerm::query()->whereKey($term->id)->lockForUpdate()->firstOrFail()->delete();
        });
        return $this->calendar($masjid_id);
    }

    private function requireEnabled($route): void
    {
        if (! \App\Support\SchoolCalendarRequestMode::enabled($this->org($route))) abort(404);
    }

    /** Office-only expanded payload; no change to the existing reader serializer. */
    public function calendar($masjid_id, int $status = 200, ?SchoolDateAuthority $authority = null): JsonResponse
    {
        $authority ??= SchoolDateAuthority::for($this->org($masjid_id));
        $authority->years()->load('terms');
        $years = $authority->years()->map(fn ($year) => [
            'id' => $year->id, 'label' => $year->label,
            'first_day' => $year->first_day->toDateString(), 'last_day' => $year->last_day->toDateString(),
            'meeting_weekday' => $year->meetingWeekday(), 'meeting_weekdays' => SchoolDateAuthority::weekdays($year),
            'meeting_days' => $authority->meetingDays($year), 'term_system' => $year->term_system,
            'terms' => $year->terms->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'starts_on' => $t->starts_on->toDateString(), 'ends_on' => $t->ends_on->toDateString(), 'position' => $t->position])->all(),
            'closures' => $year->closures->map(fn ($c) => ['id' => $c->id, 'closed_on' => $c->closed_on->toDateString(), 'reason' => $c->reason])->all(),
        ])->all();
        return response()->json(['status' => 'success', 'data' => ['timezone' => $authority->timezone(), 'today' => $authority->today(), 'years' => $years]], $status);
    }
}
