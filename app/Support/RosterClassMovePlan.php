<?php

namespace App\Support;

/**
 * WHAT MOVING A WHOLE CLASS WILL DO, OR DID: one object, read by the class
 * preview and by the run (App\Support\RosterClassMove).
 *
 * A class is moved one student at a time, by the single move, so everything
 * here is built from the single plans' FIELDS (App\Support\RosterMovePlan):
 * one row per student, the counts over those rows, and the sentences about the
 * class as a whole. Before the run the counts are over every student who can
 * move; after it, over the students who were moved. So what the office reads
 * before the tap and after it is counted the same way.
 *
 * THE SENTENCES ARE BUILT HERE AND NOWHERE ELSE (`lines()`), as the single
 * plan's are. The browser prints them in their groups and builds none of its
 * own. A student's own sentences are the single plan's, word for word.
 *
 * COUNTS OF GUARDIANS HERE ARE COUNTS OF PLACES: one for each parent AND child.
 * A parent of two students in the class is two places, because consent is
 * recorded per place. The first sentence that uses such a count says so.
 *
 * NOTHING ABOUT MANARA BUCKS but the rule the tap echoes (`bucksRule`), which
 * is about the two classes and the clock and is null until a move can carry a
 * balance. No figure, no count of ledger rows, for the class or for a child.
 */
final class RosterClassMovePlan
{
    public const MOVED = 'moved';

    public const NOT_MOVED = 'not_moved';

    public const NOT_REACHED = 'not_reached';

    public int $fromId = 0;

    public string $fromName = '';

    public ?int $toId = null;

    public string $toName = '';

    /** The day the office chose, for every student. */
    public string $movedOn = '';

    /** Today on the school's clock, read once. */
    public string $today = '';

    /** `keep`, `set`, `up`, or null while the office has not chosen. */
    public ?string $gradeMode = null;

    /** The one grade of mode `set`. */
    public ?string $gradeLabel = null;

    /** A refusal about the class as a whole: one sentence, and no student is listed. */
    public ?string $refusal = null;

    /**
     * One row per student. `plan` is the single preview's (null when the
     * student cannot move, with `refusal` and `open_group`); the run adds
     * `outcome`, and `moved` (the single move's own plan) or `reason`.
     *
     * @var list<array{membership_id: int, name: ?string, grade_label: ?string, grade_after: ?string, grade_note: ?string, came_from_target: bool, plan: ?RosterMovePlan, refusal: ?string, open_group: ?array<string, mixed>, held_back_for_consent: bool, outcome?: string, moved?: RosterMovePlan, reason?: string, retry?: bool, fault?: bool}>
     */
    public array $students = [];

    /** Student rows of this class that have left or were moved: not part of this. */
    public int $leftOrMoved = 0;

    /** Legacy leader rows: not students, not part of this. */
    public int $leaders = 0;

    /** The rule for Manara Bucks the tap must echo. Null until a move can carry a balance. */
    public ?string $bucksRule = null;

    /** Teachers assigned to the class being entered. */
    public int $teachersInNewClass = 0;

    /** Programs that are switched on and enrol into the class being left. */
    public int $programsEnrolling = 0;

    /** Programs that are switched off and still point at the class being left. */
    public int $programsSwitchedOff = 0;

    public int $maxStudents = 0;

    // ------------------------------------------------- filled in by the run

    public bool $ran = false;

    /** The run's id: also on each student's `roster.move` log line. */
    public ?string $run = null;

    public bool $stoppedByFault = false;

    /** Students not moved who share a guardian with one who was. */
    public int $siblingsLeftBehind = 0;

    /** The class being left has no current student after the run. */
    public bool $oldClassIsEmpty = false;

    // ------------------------------------------------------------ the counts

    /**
     * The single plans the counts and the class sentences are about: before
     * the run those of every student who can move, after it those of the
     * students who were moved (as each move decided them under its locks).
     *
     * @return list<RosterMovePlan>
     */
    private function counted(): array
    {
        $plans = [];

        foreach ($this->students as $row) {
            $plan = $this->ran ? ($row['moved'] ?? null) : $row['plan'];

            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /** How many students ended the run this way. */
    public function outcomes(string $outcome): int
    {
        return count(array_filter($this->students, fn (array $row): bool => ($row['outcome'] ?? null) === $outcome));
    }

    /**
     * Before the run: what would be done for everyone who can move. After it:
     * what was done. `others_in_new_class` and `new_class_holds` are the same
     * for every student of one class and are read from the first plan; with no
     * plan at all nothing counted them, and they are null.
     *
     * `guardian_form_claims` and `guardians_confirmed_in_old_class_only` are
     * the single plan's two kinds of unconfirmed place, never added into one
     * number. `report_cards_not_started` is counted because it is the one
     * thing on the "afterwards" list that cannot be done afterwards.
     *
     * @return array<string, mixed>
     */
    public function counts(): array
    {
        $plans = $this->counted();
        $sum = fn (\Closure $of): int => (int) array_sum(array_map($of, $plans));
        $again = fn (string $scope): int => $sum(fn (RosterMovePlan $p): int => count(array_filter(
            $p->consentInForceAgain,
            fn (array $consent): bool => $consent['reopens'] && $consent['scope'] === $scope,
        )));

        $who = $this->ran ? [] : [
            'can_move' => count($plans),
            'cannot_move' => count($this->students) - count($plans),
            'held_back_for_consent' => count(array_filter($this->students, fn (array $row): bool => $row['held_back_for_consent'])),
        ];

        return ['students' => count($this->students)] + $who + [
            'guardians_travelling' => $sum(fn (RosterMovePlan $p): int => $p->travelling),
            'consent_carried' => [
                'media' => $sum(fn (RosterMovePlan $p): int => $p->consentCarried['media']),
                'feed' => $sum(fn (RosterMovePlan $p): int => $p->consentCarried['feed']),
            ],
            'consent_none_recorded' => $sum(fn (RosterMovePlan $p): int => $p->consentNoneRecorded),
            'consent_none_but_receives' => $sum(fn (RosterMovePlan $p): int => $p->consentNoneButReceives),
            'consent_not_carried' => $sum(fn (RosterMovePlan $p): int => count($p->consentNotCarriedForSibling)),
            'consent_left_as_it_was' => $sum(fn (RosterMovePlan $p): int => $p->consentLeftAsItWas),
            // Entries the class entered already holds that are closed now and
            // open again with the consent recorded on them.
            'consent_in_force_again' => ['media' => $again('media'), 'feed' => $again('feed')],
            'students_unconfirmed' => $sum(fn (RosterMovePlan $p): int => $p->studentUnconfirmed ? 1 : 0),
            'guardian_form_claims' => $sum(fn (RosterMovePlan $p): int => $p->formClaims),
            'guardians_confirmed_in_old_class_only' => $sum(fn (RosterMovePlan $p): int => $p->confirmedInOldClassOnly),
            'report_cards_not_started' => $sum(fn (RosterMovePlan $p): int => $p->reportCardNotStarted ? 1 : 0),
            'others_in_new_class' => isset($plans[0]) ? $plans[0]->othersInNewClass : null,
            'new_class_holds' => isset($plans[0]) ? $plans[0]->newClassHolds : null,
        ];
    }

    // --------------------------------------------------------- the sentences

    /**
     * The sentences about the class, in the groups the dialog draws. Before
     * the run: who, what follows them (parents and consent, records, Manara
     * Bucks), and what to check afterwards. After it: what was done, consent,
     * Manara Bucks, and the same checks.
     *
     * `bucks` is empty: no move carries a balance yet, and the commit that
     * lets one says the rule here, for the class, never a figure.
     *
     * @return array<string, list<string>>
     */
    public function lines(): array
    {
        if ($this->ran) {
            return [
                'done' => $this->doneLines(),
                'consent' => $this->consentDoneLines(),
                'bucks' => [],
                'afterwards' => $this->afterwardsLines(),
            ];
        }

        if ($this->refusal !== null) {
            return ['who' => [], 'consent' => [], 'records' => [], 'bucks' => [], 'afterwards' => []];
        }

        return [
            'who' => $this->whoLines(),
            'consent' => $this->consentLines(),
            'records' => $this->recordLines(),
            'bucks' => [],
            'afterwards' => $this->afterwardsLines(),
        ];
    }

    /** @return list<string> */
    private function whoLines(): array
    {
        $from = $this->fromName;
        $to = $this->toName;
        $day = RosterMovePlan::day($this->movedOn);
        $n = count($this->students);
        $k = count($this->counted());
        $j = $n - $k;
        $lines = [];

        if ($n === 0) {
            $lines[] = "{$from} has no current students to move.";
        } else {
            $why = $j === 1 ? 'The reason is under their name.' : 'Each one says why below.';
            $stay = "They stay in {$from} and nothing about them changes.";

            $lines[] = match (true) {
                $k === $n && $n === 1 => "The one student in {$from} can move to {$to} from {$day}.",
                $k === $n => "All {$n} students can move to {$to} from {$day}.",
                $k === 0 && $n === 1 => "The one student in {$from} cannot be moved to {$to} yet. {$why} {$stay}",
                $k === 0 => "None of the {$n} students can be moved to {$to} yet. {$why} {$stay}",
                default => "{$k} of {$n} students can move to {$to} from {$day}.",
            };

            if ($k > 0 && $j > 0) {
                $lines[] = "{$j} cannot be moved yet. {$why} {$stay}";
            }

            if ($k > 0 && $n > 1) {
                $lines[] = 'Each student is moved on their own. If one cannot be moved, the others still are, and the '
                    .'result says who and why.';
            }
        }

        if ($this->leftOrMoved > 0) {
            $lines[] = $this->leftOrMoved === 1
                ? '1 student who has already left or moved is not part of this.'
                : "{$this->leftOrMoved} students who have already left or moved are not part of this.";
        }

        if ($this->leaders > 0) {
            $lines[] = $this->leaders === 1
                ? '1 entry on this roster is a leader, not a student, and is not part of this.'
                : "{$this->leaders} entries on this roster are leaders, not students, and are not part of this.";
        }

        return $lines;
    }

    /**
     * PARENTS AND CONSENT, before the run. Each line only when its count is not
     * zero, in the order the office has to weigh them: who goes along, what is
     * carried and what that opens, what the class entered already holds of
     * other families' children, who gets nothing, who is not carried, what
     * comes back into force, and who is stopped.
     *
     * @return list<string>
     */
    private function consentLines(): array
    {
        $from = $this->fromName;
        $to = $this->toName;
        $c = $this->counts();
        $k = $c['can_move'];
        $lines = [];

        // Said once, in the first sentence that counts guardian places.
        $explained = false;
        $places = function (int $n) use (&$explained): string {
            $said = self::count($n, 'guardian place', 'guardian places').($explained ? '' : ' (one for each parent and child)');
            $explained = true;

            return $said;
        };

        if ($k > 0) {
            $g = $c['guardians_travelling'];
            $media = $c['consent_carried']['media'];
            $feed = $c['consent_carried']['feed'];
            $none = $c['consent_none_recorded'];
            $receives = $c['consent_none_but_receives'];
            $notCarried = $c['consent_not_carried'];
            $left = $c['consent_left_as_it_was'];
            $again = $c['consent_in_force_again']['media'] + $c['consent_in_force_again']['feed'];
            $holds = $c['new_class_holds'] ?? ['stories' => 0, 'with_media' => 0];
            $others = (int) ($c['others_in_new_class'] ?? 0);

            if ($g > 0) {
                $lines[] = $places($g).' '.($g === 1 ? 'goes' : 'go')." onto {$to}'s list with "
                    .($g === 1 ? 'its student. It stays' : 'their students. They stay')." on {$from}'s list too, marked as left.";
            } else {
                $lines[] = "No guardian is on {$from}'s roster for ".($k === 1 ? 'this student' : 'these students').'.';
            }

            if ($media + $feed > 0) {
                $lines[] = 'Consent is carried as it is, and nobody is asked again: '.implode(', ', array_filter([
                    $media > 0 ? "{$media} for the class story and photographs" : null,
                    $feed > 0 ? "{$feed} for the class story only" : null,
                ])).'.';

                $lines[] = "From the move on those guardians can open everything {$to} has shared and still keeps, "
                    .'including what it shared before these students joined: its class story, class-wide conversations '
                    .'and class files, and for photograph consent its photographs and videos. They also start receiving '
                    ."{$to}'s story emails and its weekly points email.";
            }

            if ($media > 0 && $holds['with_media'] > 0) {
                $lines[] = "{$to} still holds ".self::count($holds['with_media'], 'story', 'stories').' with photographs or '
                    ."videos from before this move. They show students who were in {$to} then, and the arriving guardians "
                    .'will see them.';
            }

            if ($holds['stories'] > 0) {
                $lines[] = "For a new year with none of last year's story, add a new class and move into that.";
            }

            if ($others > 0) {
                $lines[] = "{$to} already has ".($others === 1 ? '1 student who is' : "{$others} students who are")
                    .' not part of this move. They will share the class.';

                if ($media > 0) {
                    $lines[] = "Guardians who arrive with photograph consent will see the photographs {$to} posts of "
                        .($others === 1 ? 'that student' : 'those students').'.';
                }

                $lines[] = "If {$to}'s own students are moving up too, move them first.";
            }

            // Nobody at all holds consent, here or there: one sentence that
            // says so, in place of the count.
            $nobody = $none > 0 && $media + $feed + $receives + $notCarried + $left === 0
                && ! $this->someConsentStandsInTheNewClass();

            if ($nobody) {
                $lines[] = 'No guardian of '.($k === 1 ? 'this student' : 'these students').' has consent on record, so '
                    ."none is carried, and no family will receive {$to}'s class story until it is recorded on {$to}'s "
                    .'roster, one guardian at a time.';
            } elseif ($none > 0) {
                $lines[] = $places($none).' '.($none === 1 ? 'has' : 'have')." no consent on record in {$from}. Nothing "
                    .'changes for '.($none === 1 ? 'it: that family receives' : 'them: they receive')." nothing from {$to}'s "
                    ."class story until it is recorded on {$to}'s roster.";
            }

            if ($receives > 0) {
                $lines[] = ($none > 0 && ! $nobody ? "{$receives} more" : $places($receives)).' '
                    .($receives === 1 ? 'has' : 'have').' none on record for the child being moved but already '
                    .($receives === 1 ? 'receives' : 'receive')." {$to}'s story through another child there.";
            }

            if ($notCarried > 0) {
                // Counts places too, so the first of these lines explains the word.
                $lines[] = ($explained ? $notCarried : $places($notCarried)).' '.($notCarried === 1 ? 'is' : 'are')
                    ." not carried because the guardian is already in {$to} for another child with less consent recorded "
                    .'there. '.($notCarried === 1 ? 'It is named under its student.' : 'Each is named under their student.');
            }

            if ($left > 0) {
                $lines[] = $places($left)." with consent in {$from} already ".($left === 1 ? 'exists' : 'exist')
                    ." in {$to} for ".($left === 1 ? 'its student' : 'their student').', with none recorded there. '
                    .($left === 1 ? 'It is left exactly as it is.' : 'They are left exactly as they are.');
            }

            if ($again > 0) {
                $p = $c['consent_in_force_again']['media'];
                $lines[] = "Consent already recorded in {$to} comes back into force for ".$places($again)
                    .($p > 0 ? " ({$p} with photographs)" : '').'. '
                    .($again === 1 ? 'It is named under its student.' : 'Each is named under their student.');
            }
        }

        $held = $c['held_back_for_consent'];

        if ($held > 0) {
            $lines[] = self::count($held, 'student', 'students').' cannot be moved because a consent the family has since '
                .'withdrawn or narrowed would come back into force. '
                .($held === 1 ? 'Their row says what to do.' : 'Each one says what to do.');
        }

        $unconfirmed = $c['students_unconfirmed'] + $c['guardian_form_claims'] + $c['guardians_confirmed_in_old_class_only'];

        if ($unconfirmed > 0) {
            $lines[] = $unconfirmed === 1
                ? "1 student or guardian place is unconfirmed and stays unconfirmed in {$to}."
                : "{$unconfirmed} students or guardian places are unconfirmed and stay unconfirmed in {$to}.";
        }

        return $lines;
    }

    /**
     * Does any entry the class entered holds for one of these students have
     * consent, open or closed? Then "no family will receive the class story"
     * would be false for that family.
     */
    private function someConsentStandsInTheNewClass(): bool
    {
        foreach ($this->counted() as $plan) {
            if ($plan->consentInForceAgain !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function recordLines(): array
    {
        $from = $this->fromName;
        $to = $this->toName;
        $plans = $this->counted();

        if ($plans === []) {
            return [];
        }

        $back = count(array_filter($plans, fn (RosterMovePlan $p): bool => $p->path === RosterMovePlan::RETURNED));
        $fresh = count($plans) - $back;
        $stays = 'Everything recorded for each student (register, marks, report cards, points, ḥifẓ, letters) stays with '
            ."{$from}, where they will be shown as moved.";

        $lines = [];

        if ($back === 0) {
            $lines[] = "{$stays} They start fresh in {$to}.";
        } else {
            $lines[] = $stays;

            if ($fresh > 0) {
                $lines[] = self::count($fresh, 'student starts', 'students start')." fresh in {$to}.";
            }

            $lines[] = self::count($back, 'student goes', 'students go')." back to the place they held in {$to} before, "
                .'which opens again with everything recorded on it.';
        }

        $marked = count(array_filter($plans, fn (RosterMovePlan $p): bool => $p->firstDay > $this->movedOn));

        if ($marked > 0) {
            $lines[] = "{$from} has already marked ".self::count($marked, 'student', 'students').' on or after '
                .RosterMovePlan::day($this->movedOn).". Those days stay with {$from}, and "
                .($marked === 1 ? 'that student starts' : 'each of them starts')." in {$to} the day after their last mark.";
        }

        $movable = array_filter($this->students, fn (array $row): bool => $row['plan'] !== null);

        if ($this->gradeMode === RosterClassMove::GRADE_KEEP) {
            // A return re-opens a place, and only a NEW place copies the grade.
            $onReturn = count(array_filter($movable, fn (array $row): bool => $row['plan']->path === RosterMovePlan::RETURNED
                && ! RosterClassMove::sameGrade($row['grade_label'], $row['grade_after'])));

            $lines[] = $onReturn === 0
                ? 'Each student keeps their grade.'
                : 'Each student keeps their grade, except '.self::count($onReturn, 'student', 'students').' going back to a '
                    .'place they held before: '.($onReturn === 1 ? 'they get' : 'each gets').' the grade recorded on that '
                    .'place. The list shows '.($onReturn === 1 ? 'which one' : 'each one').'.';
        } elseif ($this->gradeMode === RosterClassMove::GRADE_SET) {
            $lines[] = "Every student's grade becomes {$this->gradeLabel}.";
        } elseif ($this->gradeMode === RosterClassMove::GRADE_UP) {
            $kept = count(array_filter($movable, fn (array $row): bool => $row['grade_note'] !== null));

            $lines[] = 'Each grade goes up one.'.($kept === 0 ? '' : ' '.($kept === 1
                ? '1 student has no grade, a grade this cannot read, or is already in the last grade, and nothing changes for theirs.'
                : "{$kept} students have no grade, a grade this cannot read, or are already in the last grade, and nothing "
                    .'changes for theirs.'));
        }

        return $lines;
    }

    /**
     * AFTERWARDS, CHECK. True and cheap, and none of them is a consequence the
     * office must weigh before the tap, with one exception: a report card that
     * was never started cannot be started after the move, so that line is
     * printed before the run only (and its count is in `counts()`).
     *
     * @return list<string>
     */
    private function afterwardsLines(): array
    {
        $from = $this->fromName;
        $to = $this->toName;
        $lines = [];

        $lines[] = $this->teachersInNewClass === 0
            ? "{$to} has no teacher yet. Add one on the Teachers screen, or nobody can take its register."
            : "{$from}'s teachers do not move with the class. {$to} keeps its own.";

        if ($this->programsEnrolling > 0) {
            $lines[] = "A program still enrols new students into {$from}. Point it at the right class on the Programs "
                .'screen after this move.';
        } elseif ($this->programsSwitchedOff > 0) {
            $lines[] = "A program that is switched off still points at {$from}. Point it at the right class on the "
                .'Programs screen before it is opened again.';
        }

        if (! $this->ran) {
            $notStarted = count(array_filter($this->counted(), fn (RosterMovePlan $p): bool => $p->reportCardNotStarted));

            if ($notStarted > 0) {
                $lines[] = self::count($notStarted, 'student has', 'students have')." no report card started in {$from}. "
                    .'Its teacher can no longer start one after the move. If one is owed, ask the teacher to open it first.';
            }
        }

        $lines[] = "{$from} keeps its teachers, class story, conversations, files, lesson plans and assignments. None of "
            ."that is copied to {$to}. {$from} itself is not changed and stays on the Classes page.";

        return $lines;
    }

    /**
     * WHAT WAS DONE, by count. Every student is also listed by name with their
     * own outcome, because a count alone hides the one that failed.
     *
     * @return list<string>
     */
    private function doneLines(): array
    {
        $from = $this->fromName;
        $to = $this->toName;
        $named = count($this->students);
        $moved = $this->outcomes(self::MOVED);
        $reached = $this->outcomes(self::NOT_REACHED);
        $busy = count(array_filter($this->students, fn (array $row): bool => $row['retry'] ?? false));
        $faulted = count(array_filter($this->students, fn (array $row): bool => $row['fault'] ?? false));
        $refused = $this->outcomes(self::NOT_MOVED) - $busy - $faulted;
        $untouched = 'Nothing about them was changed.';
        $lines = [];

        $lines[] = match (true) {
            $moved === 0 => "No student was moved to {$to}.",
            $moved < $named => "{$moved} of {$named} students ".($moved === 1 ? 'is' : 'are')." now in {$to}.",
            $moved === 1 => "1 student is now in {$to}.",
            default => "{$moved} students are now in {$to}.",
        };

        if ($this->stoppedByFault) {
            $lines[] = 'The move stopped early because something went wrong on our side. '
                .($moved === 1 ? '1 student was' : "{$moved} students were").' moved before it and the rest were not '
                .'touched. It has been recorded. You can move the rest again.';
        }

        if ($refused > 0) {
            $lines[] = $refused === 1
                ? "1 was not moved. The reason is under their name. {$untouched}"
                : "{$refused} were not moved. Each one says why below. {$untouched}";
        }

        if ($busy > 0) {
            $lines[] = "{$busy} could not be moved just now because something about them was being saved or had changed. "
                .$untouched;
        }

        if ($reached > 0 && ! $this->stoppedByFault) {
            $lines[] = ($reached === 1 ? '1 was' : "{$reached} were")." not reached before the time ran out. {$untouched}";
        }

        if ($this->siblingsLeftBehind > 0) {
            $s = $this->siblingsLeftBehind;
            $lines[] = ($s === 1 ? '1 student who was not moved has' : "{$s} students who were not moved have")
                .' a brother or sister who was. The next check can show a different consent result for their guardians '
                .'than this one did; read it before you move them.';
        }

        if ($moved > 0) {
            $lines[] = "To put this back: open {$to}, press Move the class, choose {$from}, then 'Only the students who "
                ."came from {$from}' and 'Keep each student's grade'. If you have switched {$from} off, switch it on again "
                .'first.';

            // After the way back, never before it: switching the old class
            // off is the tidy-up that takes the way back off the list.
            if ($this->oldClassIsEmpty) {
                $lines[] = "{$from} now has no current students. It stays on your lists with everything recorded in it. "
                    .'Once you are sure the move is right, you can show it is no longer running by switching off Active in '
                    .'its Edit form. Do not use Deactivate: that hides its records from its teachers and its families. '
                    .'And do not type an end date for it in the past.';
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function consentDoneLines(): array
    {
        $to = $this->toName;
        $c = $this->counts();
        $media = $c['consent_carried']['media'];
        $feed = $c['consent_carried']['feed'];
        $carried = $media + $feed;
        $none = $c['consent_none_recorded'];
        $notCarried = $c['consent_not_carried'];
        $again = $c['consent_in_force_again']['media'] + $c['consent_in_force_again']['feed'];
        $lines = [];

        // Said once, in the first sentence that counts guardian places.
        $explained = false;
        $places = function (int $n) use (&$explained): string {
            $said = self::count($n, 'guardian place', 'guardian places').($explained ? '' : ' (one for each parent and child)');
            $explained = true;

            return $said;
        };

        if ($carried > 0) {
            $lines[] = 'Consent was carried as it is for '.$places($carried).': '.implode(', ', array_filter([
                $media > 0 ? "{$media} class story and photographs" : null,
                $feed > 0 ? "{$feed} class story only" : null,
            ])).'. Nobody was asked again; the roster shows each one as carried.';
        }

        if ($none > 0) {
            $lines[] = $places($none).' '.($none === 1 ? 'has' : 'have').' none on record and '
                .($none === 1 ? 'that family receives' : 'those families receive')." nothing from {$to}'s class story until "
                .'it is recorded there.';
        }

        if ($notCarried > 0) {
            $lines[] = ($explained ? $notCarried : $places($notCarried)).' '.($notCarried === 1 ? 'was' : 'were')
                ." not carried because the guardian is already in {$to} for another child with less consent recorded "
                .'there. '.($notCarried === 1 ? 'It is named under its student.' : 'Each is named under their student.');
        }

        if ($again > 0) {
            $lines[] = "Consent already recorded in {$to} is in force again for ".$places($again).'. '
                .($again === 1 ? 'It is named under its student.' : 'Each is named under their student.');
        }

        if ($carried > 0) {
            $lines[] = "Tell {$to}'s teacher: {$carried} more ".($carried === 1 ? 'guardian place now receives' : 'guardian places now receive')
                .' its class story'.($media > 0 ? ", {$media} of them its photographs" : '').'.';
        }

        return $lines;
    }

    // ------------------------------------------------------- what is answered

    /**
     * What the class preview answers. A refusal about the class as a whole is
     * `can_move: false`, the one sentence and no students: never one copy of it
     * per student.
     *
     * @return array<string, mixed>
     */
    public function toPreview(): array
    {
        return [
            'can_move' => $this->refusal === null,
            'refusal' => $this->refusal,
            'open_group' => null,
            'from_group' => ['id' => $this->fromId, 'name' => $this->fromName],
            'to_group' => $this->toId === null ? null : ['id' => $this->toId, 'name' => $this->toName],
            'moved_on' => $this->movedOn,
            'school_today' => $this->today,
            'expected_bucks_rule' => $this->bucksRule,
            'lines' => $this->lines(),
            'students' => array_map(fn (array $row): array => $this->listed($row), $this->students),
            'not_listed' => ['left_or_moved' => $this->leftOrMoved, 'leaders' => $this->leaders],
            'counts' => $this->counts(),
            'limits' => ['max_students' => $this->maxStudents],
        ];
    }

    /**
     * One student as the preview lists them. What the tap must echo is here:
     * the path, the first day, on a return the joining day, the consent result
     * and the grade the student will hold (`grade_after`).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function listed(array $row): array
    {
        /** @var RosterMovePlan|null $plan */
        $plan = $row['plan'];

        return [
            'membership_id' => $row['membership_id'],
            'name' => $row['name'],
            'grade_label' => $row['grade_label'],
            'grade_after' => $row['grade_after'],
            'grade_note' => $row['grade_note'],
            'can_move' => $plan !== null,
            'refusal' => $row['refusal'],
            'open_group' => $row['open_group'],
            'path' => $plan?->path,
            'first_day_in_new_class' => $plan?->firstDay,
            'joined_on' => $plan?->joinedOn,
            'expected_consent' => $plan?->consentFingerprint,
            'came_from_target' => $row['came_from_target'],
            // A refusal can be several lines (one per guardian, then what to
            // do): the row shows the first, and the whole of it below.
            'summary' => $plan !== null ? $this->summary($plan) : explode("\n", (string) $row['refusal'])[0],
            'lines' => $plan !== null ? $plan->lines() : [],
        ];
    }

    /**
     * What the run answers: every student by name, with what happened to them.
     * A student who was moved carries the single move's own sentences; one who
     * was not, the single move's own refusal, word for word.
     *
     * @return array<string, mixed>
     */
    public function toAnswer(): array
    {
        return [
            'run' => $this->run,
            'from_group' => ['id' => $this->fromId, 'name' => $this->fromName],
            'to_group' => ['id' => $this->toId, 'name' => $this->toName],
            'moved_on' => $this->movedOn,
            'moved' => $this->outcomes(self::MOVED),
            'not_moved' => $this->outcomes(self::NOT_MOVED),
            'not_reached' => $this->outcomes(self::NOT_REACHED),
            'stopped_by_fault' => $this->stoppedByFault,
            'siblings_left_behind' => $this->siblingsLeftBehind,
            'students' => array_map(function (array $row): array {
                $student = ['membership_id' => $row['membership_id'], 'name' => $row['name'], 'outcome' => $row['outcome']];

                return $student + match ($row['outcome']) {
                    self::MOVED => ['new_membership_id' => $row['moved']->membershipId, 'lines' => $row['moved']->lines()],
                    self::NOT_MOVED => [
                        'reason' => $row['reason'],
                        'open_group' => $row['open_group'],
                        'retry' => $row['retry'] ?? false,
                    ],
                    default => [],
                };
            }, $this->students),
            'counts' => $this->counts(),
            'lines' => $this->lines(),
        ];
    }

    // ------------------------------------------------------------- internals

    /**
     * One sentence for a student's row: where they go and from which day, how
     * many guardians go with them, and what happens to consent. The whole of
     * it is under "Details", in the single preview's own sentences.
     */
    private function summary(RosterMovePlan $plan): string
    {
        $to = $this->toName;
        $first = RosterMovePlan::day($plan->firstDay);
        $carried = $plan->consentCarried['media'] + $plan->consentCarried['feed'];
        $not = count($plan->consentNotCarriedForSibling);
        $again = count(array_filter($plan->consentInForceAgain, fn (array $consent): bool => $consent['reopens']));

        $said = ($plan->path === RosterMovePlan::RETURNED
                ? "Goes back to the place they held in {$to}, from {$first}"
                : "Moves to {$to} from {$first}")
            .($plan->travelling > 0
                ? ', with '.self::count($plan->travelling, 'guardian', 'guardians')
                : ', with no guardian on this roster');

        $consent = array_filter([
            $carried > 0 ? "consent carried for {$carried}" : null,
            $not > 0 ? "consent not carried for {$not}" : null,
            $again > 0 ? "consent already recorded there in force again for {$again}" : null,
        ]);

        if ($consent === [] && $plan->travelling > 0) {
            $consent = ['no consent to carry'];
        }

        return $said.($consent === [] ? '' : '; '.implode(', ', $consent)).'.';
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }
}
