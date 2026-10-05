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
 *
 * CONSENT IS CARRIED AS IT IS (2026-10-05). The decision counts, per guardian
 * entry, what will be carried, what is not and why, and what comes back into
 * force; `lines()` says each before the tap and again after it. The counts are
 * also a fingerprint (`consentFingerprint`) that the tap must echo, because the
 * preview is not locked and a consent can be recorded or withdrawn between the
 * read and the tap.
 */
final class RosterMovePlan
{
    /** The old place gets a leaving day and a new place opens in the new class. */
    public const LEFT_AND_STARTED = 'left_and_started';

    /** The student goes back onto the place they held in that class before. */
    public const RETURNED = 'returned';

    public const PATHS = [self::LEFT_AND_STARTED, self::RETURNED];

    /** How a class that was deleted, or is gone, is named in a sentence: the screen's own words for it. */
    public const CLASS_REMOVED = 'a class that was removed';

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

    /** A guardian entry beside the old row is blank and marked: a carried consent was withdrawn there. */
    public bool $carriedConsentWithdrawnHere = false;

    /** Guardian entries that go with the student (current ones only). */
    public int $travelling = 0;

    /** Unconfirmed in the new class, confirmed and current in the old one. */
    public int $confirmedInOldClassOnly = 0;

    /** Every other unconfirmed entry that will stand beside the student there. */
    public int $formClaims = 0;

    /** @var list<int> the adults behind the travelling entries (contact ids); never serialised */
    public array $guardianContactIds = [];

    /** @var list<int> ids of the entries in the old class whose consent WILL be carried; never serialised */
    public array $consentToCarry = [];

    /** @var array{media: int, feed: int} consents that will be carried, by scope */
    public array $consentCarried = ['media' => 0, 'feed' => 0];

    /** @var list<int> the adults whose consent WILL be carried (contact ids); never serialised */
    public array $consentCarriedFor = [];

    /** Vouching entries with no consent in the old class, whose adult receives nothing from the new one either. */
    public int $consentNoneRecorded = 0;

    /** @var list<int> the adults counted in `consentNoneRecorded` (contact ids); never serialised */
    public array $consentNoneRecordedFor = [];

    /** The same, where the adult already receives the new class's story through another child there. */
    public int $consentNoneButReceives = 0;

    /**
     * The same again, where the adult's consent for a BROTHER OR SISTER is
     * carried in the same whole-class move, so the class's story reaches them
     * through that child. Only a whole-class move knows it
     * (`siblingsCarryFor()`); a single move leaves it at zero.
     */
    public int $consentNoneThroughSibling = 0;

    /**
     * Not carried because the adult already stands in the new class for
     * another child with less: what they hold there, `none` or `feed`.
     * `returning`, present only when true: they are not in that class today;
     * the entry is one that has left, for a brother or sister who is in the
     * class being left and may go back in the same whole-class move.
     *
     * @var list<array{guardian: string, holds: string, returning?: true}>
     */
    public array $consentNotCarriedForSibling = [];

    /** Vouching entries WITH consent whose entry in the new class has none: that entry is never written. */
    public int $consentLeftAsItWas = 0;

    /**
     * Every entry the new class holds for this student that has consent.
     * `reopens`: it is closed now and the move opens it. `source_gone_in`: it
     * was carried there from a class that no longer holds an entry for them.
     *
     * @var list<array{guardian: string, scope: string, recorded_on: ?string, reopens: bool, source_gone_in: ?string}>
     */
    public array $consentInForceAgain = [];

    /** What the tap must echo: see `fingerprintOfConsent()`. */
    public string $consentFingerprint = '';

    /** Current students in the new class other than this one. */
    public int $othersInNewClass = 0;

    /** @var array{stories: int, with_media: int} published stories the new class still keeps, and those with a photograph or a video */
    public array $newClassHolds = ['stories' => 0, 'with_media' => 0];

    /**
     * The rule for Manara Bucks on this move (`move`, `from_ended`,
     * `to_ended`), echoed by the tap as `expected_bucks_rule`. NULL: no move
     * carries a balance yet, and the commit that lets one sets it.
     */
    public ?string $bucksRule = null;

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

    /** @var list<array{0: int, 1: int}> a carried consent: [the new entry, the entry it was copied from] */
    public array $consentEntriesCarried = [];

    /** @var list<int> entries in the old class whose consent was NOT carried for a brother or sister's sake (filled by the decision) */
    public array $consentEntriesNotCarried = [];

    /**
     * The consent result as one short string, `m{a}f{b}s{c}n{d}e{e}`: carried
     * for photographs, carried for the story, not carried for a sibling,
     * nothing on record, left as it was. For example `m1f0s0n1e0`.
     *
     * Counts only, so it tells nothing a preview does not already say, and it
     * is a string because the tap's body is form-encoded.
     */
    public function fingerprintOfConsent(): string
    {
        return 'm'.$this->consentCarried['media']
            .'f'.$this->consentCarried['feed']
            .'s'.count($this->consentNotCarriedForSibling)
            .'n'.($this->consentNoneRecorded + $this->consentNoneButReceives + $this->consentNoneThroughSibling)
            .'e'.$this->consentLeftAsItWas;
    }

    /**
     * A WHOLE-CLASS MOVE TELLS THIS STUDENT'S PLAN WHICH ADULTS HAVE A CONSENT
     * CARRIED FOR ANOTHER STUDENT OF THE SAME MOVE. An adult is admitted to a
     * class's story on any ONE of their current entries there, so a guardian
     * with nothing on record for this child and photographs for a brother
     * moved with them does receive the story, and "they receive nothing until
     * consent is recorded" would send the office to record a consent for
     * something that already arrives. Those places move from "none recorded"
     * to their own count and their own sentence. What the tap echoes does not
     * change: the fingerprint counts both under one letter.
     *
     * @param  list<int>  $adults  contact ids
     */
    public function siblingsCarryFor(array $adults): void
    {
        $through = count(array_intersect($this->consentNoneRecordedFor, $adults));

        $this->consentNoneRecorded -= $through;
        $this->consentNoneThroughSibling += $through;
        $this->consentNoneRecordedFor = array_values(array_diff($this->consentNoneRecordedFor, $adults));
    }

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
                    $this->carriedConsentWithdrawnHere => "A guardian's withdrawal of a carried consent is recorded in "
                        ."{$from}, so {$s}'s place here is kept, shown as moved.",
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

        array_push($lines, ...$this->consentLines());

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
     * THE CONSENT BLOCK. Each line only when its count is not zero. Counts of
     * guardians here are adults for ONE child, so "guardian" is the right word.
     *
     * Together the first lines are what the office has to know before it taps:
     * what is carried and with which scope, what those families start to
     * receive (everything the class still keeps, emails included), who gets
     * nothing because none was recorded, and who is not carried because they
     * already stand in the new class for another child with less.
     *
     * @return list<string>
     */
    private function consentLines(): array
    {
        $s = $this->student;
        $from = $this->fromName;
        $to = $this->toName;
        $media = $this->consentCarried['media'];
        $feed = $this->consentCarried['feed'];
        $carried = $media + $feed;
        $lines = [];

        if ($carried > 0) {
            $for = match (true) {
                $carried === 1 => '1 guardian, for the '.self::scopeWords($media === 1 ? 'media' : 'feed').'.',
                $media === 0 || $feed === 0 => "{$carried} guardians, all for the ".self::scopeWords($feed === 0 ? 'media' : 'feed').'.',
                default => "{$carried} guardians: {$media} for the class story and photographs, {$feed} for the class story only.",
            };

            $lines[] = $this->done
                ? "Consent was carried as it is for {$for}"
                : "Consent is carried as it is for {$for} Nobody is asked again.";

            $lines[] = ($this->done ? 'They can now open' : 'From the move on they can open')
                ." everything {$to} has shared and still keeps, including what it shared before {$s} joined: its class "
                .'story, class-wide conversations and class files, and for photograph consent its photographs and '
                ."videos. They also start receiving {$to}'s story emails and its weekly points email.";

            if ($media > 0 && $this->newClassHolds['with_media'] > 0) {
                $q = $this->newClassHolds['with_media'];
                $lines[] = "{$to} still holds ".self::count($q, 'story', 'stories').' with photographs or videos from '
                    ."before this move. They show students who were in {$to} then, and "
                    .($media === 1 ? 'this guardian' : 'these guardians').' will see them.';
            }

            if ($media > 0 && $this->othersInNewClass > 0) {
                $lines[] = "{$to} has ".self::count($this->othersInNewClass, 'other student', 'other students')
                    ." now, so its photographs show other families' children too.";
            }
        }

        if ($this->consentNoneRecorded > 0) {
            $lines[] = self::count($this->consentNoneRecorded, 'guardian has', 'guardians have')
                ." no consent on record in {$from}, so nothing is carried for them. They receive nothing from {$to}'s "
                .'class story, class-wide conversations and class files until consent is recorded there. Files and '
                .'conversations about their own child still reach them.';
        }

        if ($this->consentNoneButReceives > 0) {
            $lines[] = self::count($this->consentNoneButReceives, 'guardian has', 'guardians have')
                ." no consent on record in {$from} for {$s}, so nothing is carried for {$s}. They already receive "
                ."{$to}'s class story through another child there. The weekly points email about {$s} does not reach "
                ."them until consent is recorded on their entry for {$s} in {$to}.";
        }

        if ($this->consentNoneThroughSibling > 0) {
            $lines[] = self::count($this->consentNoneThroughSibling, 'guardian has', 'guardians have')
                ." no consent on record in {$from} for {$s}, so nothing is carried for {$s}. "
                .($this->done
                    ? "Their consent for a brother or sister who moved with {$s} was carried, so {$to}'s class story "
                        .'reaches them through that child.'
                    : "Their consent for a brother or sister in this move is carried, so {$to}'s class story will reach "
                        .'them through that child.')
                ." The weekly points email about {$s} does not reach them until consent is recorded on their entry for "
                ."{$s} in {$to}.";
        }

        foreach ($this->consentNotCarriedForSibling as $not) {
            $g = $not['guardian'];
            $record = "Record it in {$to} if the family agrees.";

            // Not in the class today: the entry is a brother's or sister's
            // that has left and may open again in the same whole-class move.
            if ($not['returning'] ?? false) {
                $with = $not['holds'] === 'feed' ? 'with consent for the class story only' : 'with no consent recorded on it';

                $lines[] = $this->done
                    ? "{$g}'s ".($not['holds'] === 'feed' ? 'photograph consent' : 'consent')." was not carried: they have an "
                        ."earlier entry in {$to} for another child who was in {$from} too, {$with}. {$record}"
                    : "{$g} has an earlier entry in {$to} for another child who is in {$from} too and may go back with this "
                        ."class, {$with}: ".($not['holds'] === 'feed' ? 'photograph consent is ' : '')."not carried. {$record}";

                continue;
            }

            if ($not['holds'] === 'feed') {
                $lines[] = $this->done
                    ? "{$g}'s photograph consent was not carried: they are already in {$to} for another child, with "
                        ."consent for the class story only. {$record}"
                    : "{$g} is already in {$to} for another child, with consent for the class story only: photograph "
                        ."consent is not carried. {$record}";
            } else {
                $lines[] = $this->done
                    ? "{$g}'s consent was not carried: they are already in {$to} for another child, with no consent "
                        ."recorded there. {$record}"
                    : "{$g} is already in {$to} for another child, with no consent recorded there: not carried. {$record}";
            }
        }

        if ($this->consentLeftAsItWas > 0) {
            $lines[] = self::count($this->consentLeftAsItWas, 'guardian', 'guardians')." already had an entry for {$s} in "
                ."{$to}. It stays exactly as it was there and nothing is copied onto it. Check their consent on {$to}'s "
                .'roster.';
        }

        foreach ($this->consentInForceAgain as $consent) {
            $held = self::consentWords($consent['scope'], $consent['recorded_on']);
            $gone = $consent['source_gone_in'] ?? null;

            $lines[] = $gone !== null
                ? "{$consent['guardian']}'s consent in {$to} was carried there from {$gone} earlier, and {$gone} no longer "
                    ."holds an entry for them, so it cannot be checked against it. It is in force again in {$to} "
                    ."({$held}). Withdraw it in {$to} if the family did not mean it for this class."
                : "Consent already recorded in {$to} is in force again: {$consent['guardian']} ({$held}). To withdraw a "
                    .'family\'s consent completely, withdraw it in both classes.';
        }

        if ($carried > 0) {
            $lines[] = "Consent recorded in {$from} stays on record there and is in force again if {$s} goes back. To "
                .'withdraw a family\'s consent completely, withdraw it in both classes.';

            if ($this->done) {
                $lines[] = "Tell {$to}'s teacher: ".self::count($carried, 'more guardian now receives', 'more guardians now receive')
                    .' its class story'.($media > 0 ? ", and {$media} of them its photographs" : '').'.';
            }
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
                'consent_carried' => $this->consentCarried,
                'consent_none_recorded' => $this->consentNoneRecorded,
                'consent_none_but_receives' => $this->consentNoneButReceives,
                'consent_not_carried' => $this->consentNotCarriedForSibling,
                'consent_left_as_it_was' => $this->consentLeftAsItWas,
                'consent_in_force_again' => $this->consentInForceAgain,
            ],
            // What the tap must echo beside the path and the days. The Bucks
            // rule is null until a move can carry a balance.
            'expected_consent' => $this->consentFingerprint,
            'expected_bucks_rule' => $this->bucksRule,
            'others_in_new_class' => $this->othersInNewClass,
            'new_class_holds' => $this->newClassHolds,
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
            'consent_carried' => $this->consentCarried,
            'consent_none_recorded' => $this->consentNoneRecorded,
            'consent_none_but_receives' => $this->consentNoneButReceives,
            'consent_not_carried' => $this->consentNotCarriedForSibling,
            'consent_left_as_it_was' => $this->consentLeftAsItWas,
            'consent_in_force_again' => $this->consentInForceAgain,
            'others_in_new_class' => $this->othersInNewClass,
            'new_class_holds' => $this->newClassHolds,
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

        if ($this->carriedConsentWithdrawnHere) {
            return "A guardian's withdrawal of a carried consent is recorded there, so it stays.";
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

    /** What a consent scope opens, in the words every roster sentence uses. */
    public static function scopeWords(string $scope): string
    {
        return $scope === 'media' ? 'class story and photographs' : 'class story';
    }

    /** "class story and photographs, recorded 4 Sep 2026": a consent as a sentence names one. */
    public static function consentWords(string $scope, ?string $recordedOn): string
    {
        return self::scopeWords($scope).($recordedOn !== null ? ', recorded '.self::day($recordedOn) : '');
    }
}
