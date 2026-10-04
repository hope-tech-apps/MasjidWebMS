<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * WHAT A MOVE WILL DO, OR DID: one object, read by the preview and by the move.
 *
 * `RosterMove::decide()` fills it from the roster rows and the record counts,
 * before anything is written. The preview serialises it as it is. The move
 * serialises the same object after its writes, with the ids it wrote. So the
 * numbers and the sentences the office reads before the tap and after it come
 * from one place and cannot drift.
 *
 * THE SENTENCES ARE BUILT HERE AND NOWHERE ELSE (`lines()`). The browser prints
 * them as a list and builds none of its own: two builders, one in PHP and one
 * in TypeScript, are "a copy that agrees today".
 *
 * NO FIGURE ABOUT THE CLASS STORE. The office reads class totals only
 * (.claude/rules/groups.md, "Class store"), so `bucksStaying` is a boolean and
 * the ledger's count is left out of `recordsStaying()`.
 */
final class RosterMovePlan
{
    /** The old place gets a leaving day and a new place opens in the new class. */
    public const LEFT_AND_STARTED = 'left_and_started';

    /** The student goes back onto the place they held in that class before. */
    public const RETURNED = 'returned';

    public const PATHS = [self::LEFT_AND_STARTED, self::RETURNED];

    public string $path = self::LEFT_AND_STARTED;

    public string $student = '';

    public int $fromId = 0;

    public string $fromName = '';

    public int $toId = 0;

    public string $toName = '';

    /** The day the office chose. Printed on both rows as "moved on". */
    public string $movedOn = '';

    /** The first day the new class expects the student. */
    public string $firstDay = '';

    /** The old class marked the student on or after the chosen day, so it keeps those days. */
    public bool $oldClassKeepsMoveDay = false;

    /** The last day the old class marked them, when it keeps days. */
    public ?string $oldClassMarkedUpTo = null;

    /** The new class already took its register for the first day, without this student. */
    public bool $newClassTookRegisterOnFirstDay = false;

    /** RETURNED only: the earlier place keeps the day it first joined. */
    public ?bool $joinedKept = null;

    /** RETURNED only: days the class took a register while the student was away. */
    public ?int $registersMissed = null;

    /** The joining day the place in the new class carries after the move. */
    public ?string $joinedOn = null;

    /** RETURNED only, when the joining day is rewritten: what it was. */
    public ?string $previousJoinedOn = null;

    /** The student's grade today, to pre-fill the field. */
    public ?string $gradeLabel = null;

    /** @var array<string, int> all eleven counts on the old row, by label */
    public array $held = [];

    /** A guardian entry beside the old row carries consent bytes. */
    public bool $consentRecordedHere = false;

    /** Guardian entries that go with the student (current ones only). */
    public int $travelling = 0;

    /** Unconfirmed in the new class, confirmed and current in the old one. */
    public int $confirmedInOldClassOnly = 0;

    /** Every other unconfirmed entry that will stand beside the student there. */
    public int $formClaims = 0;

    public int $consentToRecordAgain = 0;

    /** @var list<array{guardian: string, scope: string, recorded_on: ?string}> */
    public array $consentInForceAgain = [];

    public bool $studentUnconfirmed = false;

    public bool $bucksStaying = false;

    public int $scheduledMessagesStopping = 0;

    /** The old class holds marks or register marks for them and no report card. */
    public bool $reportCardNotStarted = false;

    /** Remove would be accepted on the old row afterwards. */
    public bool $oldEntryRemovable = false;

    // ----------------------------------------------- filled in by the write

    public bool $done = false;

    public ?int $oldMembershipId = null;

    public ?int $membershipId = null;

    /** @var list<int> */
    public array $guardianEntriesCarried = [];

    /** @var list<int> */
    public array $guardianEntriesReopened = [];

    /** @var list<int> */
    public array $guardianEntriesReused = [];

    /**
     * What stays with the old class, as the office is told it: the non-zero
     * kinds, without the class store's ledger.
     *
     * @return array<string, int>
     */
    public function recordsStaying(): array
    {
        return array_filter(
            $this->held,
            fn (int $n, string $label): bool => $n > 0 && $label !== AcademicRecordsHeld::LEDGER_LABEL,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The sentences, in the order the office has to read them. Before the move
     * they say what will happen; after it, what happened and what is left to do.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $s = $this->student;
        $from = $this->fromName;
        $to = $this->toName;
        $records = AcademicRecordsHeld::describeForPeople($this->held);
        $lines = [];

        if ($this->path === self::RETURNED) {
            $lines[] = $this->done
                ? "{$s} is back in {$to}, on the place they held before."
                : "{$s} was in {$to} before. Their earlier place there opens again, with everything recorded on it.";

            if ($this->joinedKept) {
                if ($this->joinedOn !== null) {
                    $lines[] = "They keep the day they first joined {$to}: ".self::day($this->joinedOn).'.';
                }
            } else {
                $g = (int) $this->registersMissed;
                $lines[] = "{$to} took attendance {$g} ".($g === 1 ? 'time' : 'times')." while {$s} was away, so "
                    ."{$s}'s start date there ".($this->done ? 'is now ' : 'becomes ').self::day($this->firstDay).'. '
                    ."They will not be shown as 'not marked' for days before it. Attendance already saved is kept.";
            }

            $lines[] = "Their entry in {$from} is kept, shown as moved. ".$this->oldEntryLine($records);
        } else {
            if ($this->done) {
                $lines[] = "{$s} is now in {$to}.";
                $lines[] = $records !== ''
                    ? "Their records ({$records}) stay with {$from}, where they are shown as moved on ".self::day($this->movedOn).'.'
                    : "Their entry in {$from} is kept, shown as moved. ".$this->oldEntryLine($records);
            } else {
                $lines[] = match (true) {
                    $records !== '' => "{$s} has records in {$from}: {$records}. All of it stays with {$from}, "
                        ."where {$s} will be shown as moved.",
                    $this->consentRecordedHere => "A guardian's consent is recorded in {$from}, so {$s}'s place here "
                        .'is kept, shown as moved.',
                    default => "{$s} has nothing recorded in {$from}. Their entry here is kept, shown as moved. "
                        .$this->oldEntryLine($records),
                };
                $lines[] = "They start fresh in {$to}. Its teacher will see an empty register, gradebook, ḥifẓ log "
                    .'and letter tracker for them.';
            }
        }

        $n = $this->travelling;
        if ($n > 0) {
            $lines[] = $this->done
                ? self::count($n, 'guardian is', 'guardians are')." now on {$to}'s list."
                : self::count($n, 'guardian goes', 'guardians go')." onto {$to}'s list with them. "
                    ."They stay on this class's list too, marked as left.";
        } elseif (! $this->done) {
            $lines[] = 'No guardian is on this roster for them.';
        }

        if ($this->consentToRecordAgain > 0) {
            $c = self::count($this->consentToRecordAgain, 'guardian', 'guardians');
            $lines[] = ($this->done
                    ? "Record consent again for {$c} in {$to}."
                    : "Consent for the class story and photographs does not move. Record it again in {$to} for {$c}.")
                .' Until then they receive nothing from that class\'s story, class-wide conversations and class files, '
                .'and no weekly points email. Files and conversations about their own child still reach them.';
        }

        foreach ($this->consentInForceAgain as $consent) {
            $lines[] = "Consent already recorded in {$to} is in force again: {$consent['guardian']} ("
                .self::scopeWords($consent['scope'])
                .($consent['recorded_on'] !== null ? ', recorded '.self::day($consent['recorded_on']) : '')
                .'). To withdraw a family\'s consent completely, withdraw it in both classes.';
        }

        if ($this->confirmedInOldClassOnly > 0) {
            $a = $this->confirmedInOldClassOnly;
            $lines[] = self::count($a, 'guardian', 'guardians')." you confirmed in {$from} "
                .($a === 1 ? 'is' : 'are')." still waiting to be confirmed in {$to}. Confirm them on that roster so "
                ."they can see {$s} there.";
        }

        if ($this->formClaims > 0) {
            $k = $this->formClaims;
            $lines[] = self::count($k, 'guardian entry', 'guardian entries')." in {$to} "
                .($k === 1 ? 'was' : 'were').' typed into the registration form and nobody here has confirmed '
                .($k === 1 ? 'it' : 'them').'. '.($k === 1 ? 'It' : 'They').' cannot see anything yet. Make sure you '
                .'know who filled in that form before you confirm one.';
        }

        if ($this->studentUnconfirmed) {
            $lines[] = "{$s}'s own entry in {$to} is not confirmed: it came from a registration form. "
                ."Open {$to} and tap Confirm on {$s}'s row.";
        }

        if ($this->oldClassKeepsMoveDay && $this->oldClassMarkedUpTo !== null) {
            $lines[] = ($this->oldClassMarkedUpTo === $this->movedOn
                    ? "{$from} has already marked {$s} on ".self::day($this->movedOn).", so that day stays with {$from}."
                    : "{$from} marked {$s} up to ".self::day($this->oldClassMarkedUpTo).", so those days stay with {$from}.")
                ." {$to} expects them from ".self::day($this->firstDay).'. '
                ."{$to}'s register for the days before will still list {$s}. Leave them unmarked for those days.";
        }

        if ($this->newClassTookRegisterOnFirstDay) {
            $lines[] = "{$to} has already taken the register for ".self::day($this->firstDay).". {$s} will show as "
                .'not marked there until its teacher marks them.';
        }

        if ($this->bucksStaying) {
            $lines[] = "{$s} has Manara Bucks in {$from}. They stay there for now and cannot be spent in {$to}.";
        }

        if ($this->scheduledMessagesStopping > 0) {
            $m = $this->scheduledMessagesStopping;
            $lines[] = self::count($m, 'message', 'messages')." scheduled about {$s} in {$from} will not be sent.";
        }

        if ($this->reportCardNotStarted && ! $this->done) {
            $lines[] = "{$from} has not started a report card for {$s}. Its teacher can no longer start one after "
                .'the move. If one is owed for this term, ask the teacher to open it first.';
        }

        if ($this->done && $n > 0) {
            $lines[] = 'Each guardian is now on both class lists: marked as left in '.$from.", where they can still "
                ."open {$s}'s old records, and current in {$to}. To take a guardian's access away completely, remove "
                .'them from both lists.';
        }

        return $lines;
    }

    /**
     * What the preview answers.
     *
     * @return array<string, mixed>
     */
    public function toPreview(): array
    {
        return [
            'can_move' => true,
            'refusal' => null,
            'open_group' => null,
            'path' => $this->path,
            'to_group' => ['id' => $this->toId, 'name' => $this->toName],
            'moved_on' => $this->movedOn,
            'first_day_in_new_class' => $this->firstDay,
            'old_class_keeps_move_day' => $this->oldClassKeepsMoveDay,
            'new_class_took_register_on_first_day' => $this->newClassTookRegisterOnFirstDay,
            'joined_kept' => $this->joinedKept,
            'registers_missed' => $this->registersMissed,
            'joined_on' => $this->joinedOn,
            'grade_label' => $this->gradeLabel,
            'records_staying' => (object) $this->recordsStaying(),
            'consent_recorded_here' => $this->consentRecordedHere,
            'guardians' => [
                'travelling' => $this->travelling,
                'confirmed_in_old_class_only' => $this->confirmedInOldClassOnly,
                'form_claims' => $this->formClaims,
                'consent_to_record_again' => $this->consentToRecordAgain,
                'consent_in_force_again' => $this->consentInForceAgain,
            ],
            'student_unconfirmed' => $this->studentUnconfirmed,
            'bucks_staying' => $this->bucksStaying,
            'scheduled_messages_stopping' => $this->scheduledMessagesStopping,
            'old_entry_kept' => true,
            'old_entry_removable' => $this->oldEntryRemovable,
            'lines' => $this->lines(),
        ];
    }

    /**
     * What the move answers. No contact is serialised: the screen reloads the
     * roster.
     *
     * @return array<string, mixed>
     */
    public function toAnswer(): array
    {
        return [
            'path' => $this->path,
            'from_group_id' => $this->fromId,
            'to_group_id' => $this->toId,
            'old_membership_id' => $this->oldMembershipId,
            'membership_id' => $this->membershipId,
            'moved_on' => $this->movedOn,
            'first_day_in_new_class' => $this->firstDay,
            'old_class_keeps_move_day' => $this->oldClassKeepsMoveDay,
            'new_class_took_register_on_first_day' => $this->newClassTookRegisterOnFirstDay,
            'joined_kept' => $this->joinedKept,
            'joined_on' => $this->joinedOn,
            'guardians_in_new_class' => $this->travelling,
            'guardians_confirmed_in_old_class_only' => $this->confirmedInOldClassOnly,
            'guardian_form_claims' => $this->formClaims,
            'student_unconfirmed' => $this->studentUnconfirmed,
            'consent_to_record_again' => $this->consentToRecordAgain,
            'consent_in_force_again' => $this->consentInForceAgain,
            'pairs_in_both_classes' => $this->travelling,
            'records_staying' => (object) $this->recordsStaying(),
            'bucks_staying' => $this->bucksStaying,
            'old_entry_kept' => true,
            'old_entry_removable' => $this->oldEntryRemovable,
            'lines' => $this->lines(),
        ];
    }

    // ------------------------------------------------------------- internals

    /** What the old entry holds, and whether Remove will take it afterwards. */
    private function oldEntryLine(string $records): string
    {
        if ($records !== '') {
            return "It holds {$records}, so it stays.";
        }

        if ($this->consentRecordedHere) {
            return "A guardian's consent is recorded there, so it stays.";
        }

        if (! $this->oldEntryRemovable) {
            return 'It stays.';
        }

        return $this->done
            ? 'It holds nothing: remove it there if you do not need it.'
            : 'It holds nothing, so you can remove it afterwards.';
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }

    /** "4 Oct 2026": a day as the roster's own sentences print one. */
    public static function day(string $day): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', substr($day, 0, 10))->format('j M Y');
    }

    private static function scopeWords(string $scope): string
    {
        return $scope === 'media' ? 'class story and photographs' : 'class story';
    }
}
