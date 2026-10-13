<?php

namespace App\Support;

use App\Models\{ClassSubject, GroupStaff, SchoolYear};

/** The teacher portal's dated week, also used by the office's by-teacher view. */
final class TeacherSchedule
{
    public function __construct(private TimetableReader $reader) {}

    /** Resolve every displayed day against its own year and effective dates in bulk. */
    public function read(int $school, int $teacher, ?string $week = null): array
    {
        $calendar = SchoolDateAuthority::for($school);
        $today = $calendar->today();
        $start = SchoolCalendar::day($week ?? $today)->startOfWeek(1);
        $end = $start->addDays(6)->toDateString();
        $years = $calendar->years();
        $yearOn = fn (string $date) => $years->first(fn ($y) => $y->first_day->toDateString() <= $date && $date <= $y->last_day->toDateString());
        $yearIds = $years->filter(fn ($y) => ($y->first_day->toDateString() <= $end && $y->last_day->toDateString() >= $start->toDateString())
            || ($y->first_day->toDateString() <= $today && $y->last_day->toDateString() >= $today))->pluck('id')->all();
        // Read all relevant versions once: an effective change midweek applies to its own day, not Monday's snapshot.
        $versions = $this->reader->teacherVersions($school, $yearIds, $teacher);
        $groups = array_values(array_unique(array_column($versions, 'group_id')));
        $staff = GroupStaff::where('masjid_id', $school)->where('user_id', $teacher)->whereIn('group_id', $groups)->get()->keyBy('group_id');
        $visibleSubjects = ClassSubject::where('masjid_id', $school)->whereIn('group_id', $groups)->whereNull('hidden_at')->pluck('id')->all();
        $subjectsOn = ClassSubjectMode::enabled($school);
        $termsOn = \App\Support\SchoolCalendarRequestMode::enabled($school);
        $weekdays = fn (SchoolYear $y) => $termsOn ? SchoolDateAuthority::weekdays($y) : [$y->meetingWeekday()];
        $day = function (string $date) use ($yearOn, $weekdays, $versions, $staff, $visibleSubjects, $subjectsOn, $teacher): array {
            $year = $yearOn($date);
            $weekday = SchoolCalendar::day($date)->dayOfWeek;
            $closure = $year?->closures->first(fn ($c) => $c->closed_on->toDateString() === $date);
            $meets = $year !== null && in_array($weekday, $weekdays($year), true);
            $rows = [];
            if ($meets && $closure === null) {
                foreach ($versions as $m) {
                    if ($m['school_year_id'] !== (int) $year->id || $m['weekday'] !== $weekday || $m['effective_from'] > $date || ($m['effective_until'] !== null && $m['effective_until'] < $date)) continue;
                    $assignment = $staff->get($m['group_id']);
                    // Match SubjectFence's stored-ID/provenance predicate without one authority query per class.
                    $mapped = $assignment !== null && ($assignment->class_subjects_mapped_at !== null || $assignment->class_subject_ids_edited_at !== null);
                    $ids = $assignment?->class_subject_ids;
                    $mayOpenSubject = $subjectsOn && $m['kind'] === 'subject' && $mapped && SubjectFence::validStoredIds($ids)
                        && ($ids === null || in_array($m['class_subject_id'], array_map('intval', $ids), true))
                        && in_array($m['class_subject_id'], $visibleSubjects, true);
                    $href = '/teacher/classes/'.$m['group_id'].($mayOpenSubject ? '?subject='.$m['class_subject_id'] : '');
                    $row = array_intersect_key($m, array_flip(['id','group_id','group_name','class_subject_id','kind','label','starts_at','ends_at','room_name','effective_from','effective_until']))
                        + ['href' => $href, 'co_teachers' => array_values(array_filter($m['teachers'], fn ($t) => $t['id'] !== $teacher))];
                    // The other classes this lesson is taken with on this very date; only such a lesson carries the key.
                    $with = array_filter($m['joint'] ?? [], fn ($c) => $c['effective_from'] <= $date && ($c['effective_until'] === null || $c['effective_until'] >= $date));
                    if ($with !== []) $row['joint'] = array_values(array_map(fn ($c) => ['id'=>$c['id'], 'group_id'=>$c['group_id'], 'group_name'=>$c['group_name'], 'href'=>null], $with));
                    $rows[] = $row;
                }
                $rows = self::asOneLesson($rows);
            }
            return ['date'=>$date, 'weekday'=>$weekday, 'in_year'=>$year !== null, 'meeting_day'=>$meets, 'reason'=>$closure?->reason, 'closed'=>$closure !== null, 'meetings'=>$rows];
        };
        $todayData = $day($today);
        $state = ! $todayData['in_year']
            ? ($years->contains(fn ($y) => $y->first_day->toDateString() > $today) ? 'not_started' : ($years->isNotEmpty() ? 'ended' : 'unplaced'))
            : ($todayData['closed'] ? 'closure' : (! $todayData['meeting_day'] ? 'non_meeting' : ($todayData['meetings'] === [] ? 'unplaced' : 'meetings')));
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->addDays($i)->toDateString();
            $d = $day($date);
            if ($date === $today || ($d['meeting_day'] && ($d['meetings'] !== [] || $d['closed']))) $days[] = $d;
        }
        return [
            'timezone'=>$calendar->timezone(), 'week_start'=>$start->toDateString(), 'week_end'=>$end,
            'today'=>$todayData + ['state'=>$state], 'days'=>$days,
        ];
    }

    /** A lesson this teacher takes with two classes at once is one slot: the first class's, naming the others with their own links. */
    private static function asOneLesson(array $rows): array
    {
        $kept = [];
        foreach ($rows as $row) {
            foreach ($kept as $i => $first) {
                if (! TimetableReader::together($first, $row)) continue;
                foreach ($first['joint'] ?? [] as $j => $class) if ($class['id'] === $row['id']) $kept[$i]['joint'][$j]['href'] = $row['href'];
                foreach ($row['co_teachers'] as $t) if (! in_array($t['id'], array_column($kept[$i]['co_teachers'], 'id'), true)) $kept[$i]['co_teachers'][] = $t;
                usort($kept[$i]['co_teachers'], fn ($a, $b) => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);
                // Each class's row may name the lesson or its place differently; say each once.
                if ($first['kind'] === 'class' && $row['kind'] !== 'class') { $kept[$i]['kind'] = $row['kind']; $kept[$i]['label'] = $row['label']; }
                elseif ($row['kind'] !== 'class' && ! in_array($row['label'], explode(' / ', $kept[$i]['label']), true)) $kept[$i]['label'] .= ' / '.$row['label'];
                if ($row['room_name'] !== null && ! in_array($row['room_name'], explode(', ', (string) $kept[$i]['room_name']), true)) $kept[$i]['room_name'] = $kept[$i]['room_name'] === null ? $row['room_name'] : $kept[$i]['room_name'].', '.$row['room_name'];
                continue 2;
            }
            $kept[] = $row;
        }
        return $kept;
    }
}
