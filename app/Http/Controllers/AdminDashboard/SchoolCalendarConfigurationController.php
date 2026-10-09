<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SchoolCalendar\{StoreSchoolYearRequest, UpdateSchoolYearRequest, StoreSchoolClosureRequest};
use App\Models\{Masjid, SchoolYear, SchoolClosure, SchoolTerm, AttendanceRecord, ReportCard};
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
        $org = $this->org($masjid_id); $data = $this->data($request); $terms = $request->validated('terms');
        DB::transaction(function () use ($org, $data, $terms) {
            $this->lockOrganisation($org);
            $this->refuseOverlap($org, $data, null);
            $year = SchoolYear::create($data);
            if ($terms !== null) $this->saveTerms($year, $data, $terms);
        });
        return $this->calendar($masjid_id, 201);
    }

    public function updateYear(UpdateSchoolYearRequest $request, $masjid_id, $year_id): JsonResponse
    {
        $year = SchoolYear::findOrFail($year_id); $org = $this->org($masjid_id); $data = $this->data($request); $terms = $request->validated('terms');
        DB::transaction(function () use ($org, $year, $data, $terms) {
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
            if ($terms === null && $locked->terms()->get()->contains(fn ($t) => $t->starts_on->toDateString() < $data['first_day'] || $t->ends_on->toDateString() > $data['last_day'])) {
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
            if ($terms !== null) $this->saveTerms($locked, $data, $terms);
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

    /** Replace the submitted draft under the organisation/year mutex, retaining filed-card links by ID. */
    private function saveTerms(SchoolYear $year, array $data, array $terms): void
    {
        $existing = $year->terms()->get()->keyBy('id');
        $errors = [];
        foreach ($terms as $i => $term) {
            $fail = function (string $field, string $message) use (&$errors, $i, $term): void {
                $errors["terms.$i.$field"][] = $term['name'].': '.$message;
            };
            if (isset($term['id']) && ! $existing->has((int) $term['id'])) $fail('id', 'That term does not belong to this school year.');
            if ($term['starts_on'] < $data['first_day']) $fail('starts_on', 'A term must be inside its school year.');
            if ($term['ends_on'] > $data['last_day']) $fail('ends_on', 'A term must be inside its school year.');
            if ($term['ends_on'] < $term['starts_on']) $fail('ends_on', 'A term cannot end before it starts.');
            foreach (array_slice($terms, 0, $i) as $other) {
                if ((int) $other['position'] === (int) $term['position']) $fail('position', 'That term number is already in use.');
                if ($term['starts_on'] <= $other['ends_on'] && $term['ends_on'] >= $other['starts_on']) $fail('starts_on', 'Term dates must not overlap.');
                elseif (((int) $other['position'] < (int) $term['position'] && $other['ends_on'] >= $term['starts_on']) || ((int) $other['position'] > (int) $term['position'] && $other['starts_on'] <= $term['ends_on'])) $fail('position', 'Term numbers must follow date order.');
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);

        $retained = array_column($terms, 'id');
        foreach ($existing as $id => $term) if (! in_array($id, array_map('intval', $retained), true)) $term->delete();
        // 0 is outside submitted term numbers and fits MySQL's unsigned TINYINT.
        // Move changed numbers through that spare slot so swaps never hit the unique index.
        $pending = collect($terms)->filter(fn ($t) => isset($t['id']))->keyBy('id');
        $occupied = $existing->filter(fn ($t) => in_array($t->id, array_map('intval', $retained), true))->mapWithKeys(fn ($t) => [$t->position => $t->id])->all();
        while ($pending->isNotEmpty()) {
            $ready = $pending->first(fn ($t) => ! isset($occupied[(int) $t['position']]) || $occupied[(int) $t['position']] === (int) $t['id']);
            if (! $ready) {
                $t = $existing->get((int) $pending->first()['id']);
                unset($occupied[$t->position]);
                $t->update(['position' => 0]);
                $occupied[0] = $t->id;
                continue;
            }
            $t = $existing->get((int) $ready['id']);
            unset($occupied[$t->position]);
            $t->update(array_intersect_key($ready, array_flip(['name','starts_on','ends_on','position'])));
            $occupied[$t->position] = $t->id;
            $pending->forget($t->id);
        }
        foreach ($terms as $term) if (! isset($term['id'])) $year->terms()->create($term);
    }

    /** Office-only expanded payload; no change to the existing reader serializer. */
    public function calendar($masjid_id, int $status = 200, ?SchoolDateAuthority $authority = null): JsonResponse
    {
        $authority ??= SchoolDateAuthority::for($this->org($masjid_id));
        $authority->years()->load('terms');
        $counts = ReportCard::query()->where('masjid_id', $this->org($masjid_id))->whereNotNull('school_term_id')->select('school_term_id')->selectRaw('COUNT(*) AS filed_count')->groupBy('school_term_id')->pluck('filed_count', 'school_term_id');
        $years = $authority->years()->map(fn ($year) => [
            'id' => $year->id, 'label' => $year->label,
            'first_day' => $year->first_day->toDateString(), 'last_day' => $year->last_day->toDateString(),
            'meeting_weekday' => $year->meetingWeekday(), 'meeting_weekdays' => SchoolDateAuthority::weekdays($year),
            'meeting_days' => $authority->meetingDays($year), 'term_system' => $year->term_system,
            'terms' => $year->terms->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'starts_on' => $t->starts_on->toDateString(), 'ends_on' => $t->ends_on->toDateString(), 'position' => $t->position, 'report_card_count' => (int) ($counts[$t->id] ?? 0)])->all(),
            'closures' => $year->closures->map(fn ($c) => ['id' => $c->id, 'closed_on' => $c->closed_on->toDateString(), 'reason' => $c->reason])->all(),
        ])->all();
        return response()->json(['status' => 'success', 'data' => ['timezone' => $authority->timezone(), 'today' => $authority->today(), 'years' => $years]], $status);
    }
}
