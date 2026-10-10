<?php

namespace Tests\Support;

use App\Models\GroupMembership;
use App\Support\AcademicRecordsHeld;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One record on ANY of the eleven keys into a roster row, built by hand.
 *
 * Factories exist for three of the eleven tables. A suite that has to prove
 * something for every key (a move leaves each kind where it was; the schema
 * holds no twelfth) needs all of them, so each is inserted on the raw table
 * with the columns the schema requires and nothing else. Raw on purpose: what
 * is under test is the KEY, not the model in front of it.
 */
trait PlantsRosterRecords
{
    /**
     * Insert one row on `$table` (a key of AcademicRecordsHeld::KEYS) pointing
     * at this roster row, in the roster row's own class. Returns its id.
     *
     * `$with` overrides or adds columns: a `deleted_at` for a soft-deleted
     * record, a `session_date` for a register mark on a chosen day, a `status`
     * for a scheduled message.
     */
    protected function plantRecord(string $table, GroupMembership $row, array $with = []): int
    {
        [$column] = AcademicRecordsHeld::KEYS[$table];

        $base = ['masjid_id' => $row->masjid_id, 'group_id' => $row->group_id, $column => $row->id];
        $now = now()->toDateTimeString();
        // The `date` cast writes a midnight timestamp on SQLite, and that is
        // the form the readers have to cope with, so a planted day has it too.
        $day = fn (string $d): string => DB::connection()->getDriverName() === 'sqlite' ? $d.' 00:00:00' : $d;

        if (isset($with['session_date'])) {
            $with['session_date'] = $day($with['session_date']);
        }

        $columns = match ($table) {
            'attendance_records' => $base + ['session_date' => $day('2026-09-06'), 'status' => 'present'],
            'assignment_scores' => $base + [
                'class_assignment_id' => DB::table('class_assignments')->insertGetId([
                    'masjid_id' => $row->masjid_id, 'group_id' => $row->group_id,
                    'title' => 'Spelling quiz', 'points_possible' => 10, 'assigned_on' => '2026-09-06',
                ]),
                'status' => 'scored',
            ],
            'report_cards' => $base + ['type' => 'term', 'school_year' => '2026-27', 'term' => 1],
            'hifz_entries' => $base + [
                'kind' => 'sabaq', 'from_surah' => 114, 'from_ayah' => 1, 'to_surah' => 114, 'to_ayah' => 6,
                'quality' => 'good', 'recited_at' => $now,
            ],
            'behavior_awards' => $base + [
                'skill_label' => 'Helping', 'skill_polarity' => 'positive', 'points' => 1, 'awarded_at' => $now,
            ],
            'arabic_letter_progress' => $base + ['drill_id' => 'alif-'.Str::lower(Str::random(6))],
            'prize_ledger_entries' => $base + ['kind' => 'earned', 'amount' => 3, 'occurred_at' => $now],
            'arabic_daily_notes' => $base + ['session_date' => $day('2026-09-06'), 'note' => 'Read the first line.'],
            'group_resource_recipients' => [
                'masjid_id' => $row->masjid_id,
                $column => $row->id,
                'group_resource_id' => DB::table('group_resources')->insertGetId([
                    'masjid_id' => $row->masjid_id, 'group_id' => $row->group_id,
                    'title' => 'Worksheet', 'original_name' => 'worksheet.pdf', 'mime_type' => 'application/pdf',
                    'size_bytes' => 10, 'disk' => 'local', 'path' => 'group-resources/'.Str::random(12).'.pdf',
                ]),
            ],
            // These two name their class through the class's subject, not a `group_id` of their own.
            'subject_piece_marks' => [
                'masjid_id' => $row->masjid_id, $column => $row->id, 'level' => 3,
                'subject_piece_id' => DB::table('subject_pieces')->insertGetId([
                    'masjid_id' => $row->masjid_id, 'class_subject_id' => $this->plantClassSubject($row),
                    'source' => 'own', 'title' => 'Practice piece', 'created_at' => $now, 'updated_at' => $now,
                ]),
            ],
            'subject_notes' => [
                'masjid_id' => $row->masjid_id, $column => $row->id, 'body' => 'Practice note',
                'class_subject_id' => $this->plantClassSubject($row),
            ],
            'group_threads' => $base + ['subject' => 'About reading', 'scope' => 'participant'],
            'group_message_schedules' => $base + [
                'scope' => 'participant', 'subject' => 'A reminder', 'body' => 'Please bring the reader.',
                'send_at' => now()->addDay()->toDateTimeString(),
            ],
        };

        return (int) DB::table($table)->insertGetId(array_merge($columns, $with));
    }

    /** A subject of the roster row's own class, for the records that hang off one. */
    private function plantClassSubject(GroupMembership $row): int
    {
        $name = 'Practice subject '.Str::lower(Str::random(6));

        return (int) DB::table('class_subjects')->insertGetId([
            'masjid_id' => $row->masjid_id, 'group_id' => $row->group_id, 'name' => $name, 'name_key' => Str::lower($name), 'position' => 0,
        ]);
    }

    /** The class a record names: its own `group_id`, its file's for an addressed file, its subject's for subject work. */
    protected function classOfRecord(string $table, int $id): int
    {
        if ($table === 'subject_notes') {
            return (int) DB::table('class_subjects')->where('id', DB::table($table)->where('id', $id)->value('class_subject_id'))->value('group_id');
        }
        if ($table === 'subject_piece_marks') {
            return (int) DB::table('class_subjects')->where('id', DB::table('subject_pieces')
                ->where('id', DB::table($table)->where('id', $id)->value('subject_piece_id'))->value('class_subject_id'))->value('group_id');
        }

        if ($table === 'group_resource_recipients') {
            return (int) DB::table('group_resources')
                ->where('id', DB::table($table)->where('id', $id)->value('group_resource_id'))
                ->value('group_id');
        }

        return (int) DB::table($table)->where('id', $id)->value('group_id');
    }
}
