<?php

namespace App\Console\Commands;

use App\Mail\WeeklyPointsReportMail;
use App\Models\BehaviorWeek;
use App\Models\Group;
use App\Models\Masjid;
use App\Services\Groups\GroupNotificationRecipientResolver;
use App\Support\NudgeRecipient;
use App\Support\PointsReportSchedule;
use App\Support\PointsWeek;
use App\Support\SchoolCalendar;
use App\Support\SchoolPointsWeek;
use App\Support\SchoolSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The Friday points report (T-003.3, owner 2026-09-28): each family is told their
 * child's weekly report is ready, and each class's teachers are told their class
 * summary is.
 *
 * ## What it sends: a notice and a link, never the numbers
 *
 * B5 (owner, 2026-09-29): "your child's weekly report is ready" with a link to the
 * printable portal report, and nothing about the child in the email. The portal page
 * is where consent, identity and the ward edge are checked
 * (Family\BehaviorAwardsController); an inbox forwards, previews on a lock screen and
 * is shared by a household. See WeeklyPointsReportMail.
 *
 * ## When: hourly, and the schedule is the school's
 *
 * Scheduled hourly (routes/console.php, withoutOverlapping). Each run asks, per school
 * that has the `points_weekly_report` grant, whether the school's scheduled moment
 * (PointsReportSchedule: Friday 15:00 unless set, on the SCHOOL's clock) has passed in
 * the points week that contains now, and by less than CATCH_UP_HOURS, so a missed hour
 * (a deploy, a slow run) still sends but a report never goes out days late because the
 * grant was switched on afterwards. The moment is built in local time from the week
 * (PointsWeek::at), which is what makes it right on both daylight-saving weekends.
 *
 * ## Which week: the one that holds the send time, up to that moment
 *
 * The report covers the points week containing the SCHEDULED instant, from its start up
 * to that instant. A child is included only if they have a live award in that span, so
 * an award given after the send (Friday at 15:30, or Saturday) belongs to a week whose
 * report has already gone and appears only in the portal, never in a second email. The
 * cutoff is the scheduled instant, not the moment the run happened to start, so a run
 * that catches up at 16:10 decides exactly as one at 15:00 would have.
 *
 * ## Who: only people the school can stand behind
 *
 * Families: GroupNotificationRecipientResolver::weeklyReportGuardians (a current ward, a
 * confirmed, current, consented guardian edge, a live family login). Teachers: the
 * class's staff logins (`group_staff`); a legacy Contact leader reached through a family
 * login is left out, because the link here is the teacher's sign-in and it would send
 * them to the wrong door. A week the school calendar marks closed is skipped.
 *
 * ## At most once, claimed in the database
 *
 * behavior_weeks is the claim (BehaviorWeek::claim): an insert-or-ignore then a
 * conditional UPDATE, so overlapping runs, a retried run and a second server cannot
 * send a class's report twice. The price is that a crash between the claim and the mail
 * loses that week's notice for that class rather than repeating it; the portal report
 * is there either way. A run that finds nobody to tell claims nothing, so a guardian
 * who signs in later that day is still picked up by the next hourly run.
 *
 * ## Fail-soft, and it leaves a trace
 *
 * One dead address, or one failing class, is logged (warning, which production keeps)
 * and skipped; the rest still go. Every run writes ONE line to the `monitors` channel
 * (monitors.log is pinned at info; production's LOG_LEVEL=warning would drop an info
 * line on the default channel, which would leave no proof the sweep ever ran).
 *
 * --dry-run works out who WOULD be told and sends and records nothing. --week=Y-m-d is
 * an explicit run for one points week (a catch-up or a check); it skips the window but
 * not the claim, and refuses a week whose moment has not come.
 */
class SendWeeklyPointsReports extends Command
{
    /** How long after its scheduled moment a missed run may still send. */
    public const CATCH_UP_HOURS = 12;

    protected $signature = 'points:weekly-report
        {--masjid= : Only this organisation (its id)}
        {--dry-run : Work out who would be told; send and record nothing}
        {--week= : Any day (Y-m-d) of the points week to report. An explicit run: the send window is not applied}';

    protected $description = 'Tell families their child\'s weekly points report is ready, and each class\'s teachers their class summary (grant: points_weekly_report)';

    public function handle(GroupNotificationRecipientResolver $resolver): int
    {
        $only = $this->option('masjid');
        $explicit = $this->option('week');

        if ($only !== null && ! ctype_digit((string) $only)) {
            $this->error('--masjid must be an organisation id.');

            return self::INVALID;
        }

        if ($explicit !== null && ! SchoolCalendar::isIsoDate((string) $explicit)) {
            $this->error('--week must be a date such as 2026-10-04.');

            return self::INVALID;
        }

        $dry = (bool) $this->option('dry-run');
        $now = CarbonImmutable::instance(Date::now());

        $run = [
            'dry_run' => $dry,
            'organisations' => 0,
            'skipped' => ['off' => 0, 'not_due' => 0, 'window_passed' => 0, 'closed_week' => 0],
            'classes_sent' => 0,
            'classes_already_sent' => 0,
            'classes_nobody_to_tell' => 0,
            'classes_undelivered' => 0,
            'family_emails' => 0,
            'teacher_emails' => 0,
            'failures' => 0,
        ];

        $masjids = Masjid::query()
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('id')
            ->get();

        foreach ($masjids as $masjid) {
            $run['organisations']++;

            if (! SchoolSettings::pointsWeeklyReport($masjid)) {
                $run['skipped']['off']++;

                continue;
            }

            try {
                $this->sweepSchool($masjid, $resolver, $now, $explicit !== null ? (string) $explicit : null, $dry, $run);
            } catch (Throwable $e) {
                // One school must not stop the rest.
                $run['failures']++;
                Log::warning('points:weekly-report failed for organisation '.$masjid->id.': '.$e->getMessage());
            }
        }

        Log::channel('monitors')->info('points:weekly-report', $run);

        $this->line(sprintf(
            'points:weekly-report%s: %d organisation(s), %d class(es) %s, %d family notice(s), %d teacher notice(s), %d already sent, %d with nobody to tell, %d undelivered (retried next run), %d failure(s).',
            $dry ? ' (dry run)' : '',
            $run['organisations'],
            $run['classes_sent'],
            $dry ? 'would be sent' : 'sent',
            $run['family_emails'],
            $run['teacher_emails'],
            $run['classes_already_sent'],
            $run['classes_nobody_to_tell'],
            $run['classes_undelivered'],
            $run['failures'],
        ));

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $run */
    private function sweepSchool(
        Masjid $masjid,
        GroupNotificationRecipientResolver $resolver,
        CarbonImmutable $now,
        ?string $explicitDay,
        bool $dry,
        array &$run,
    ): void {
        $tz = SchoolPointsWeek::timezone((int) $masjid->id);
        $schedule = PointsReportSchedule::for($masjid);

        $week = $explicitDay !== null
            ? PointsWeek::startingOn($explicitDay, $tz)
            : PointsWeek::containing($now, $tz);

        if ($week === null) {
            return;
        }

        // The scheduled moment INSIDE this points week. It is also the report's cutoff.
        $sendAt = $week->at($schedule['weekday'], $schedule['time']);

        // A moment late on the week's last day (Saturday 23:00) has its catch-up window
        // running into the NEXT week, where the week containing "now" is a different one
        // and its own moment has not come. So an automatic run also looks at the week
        // before, and reports that one while its window is still open.
        if ($explicitDay === null && $now->lt($sendAt)) {
            $before = $week->previous();
            $beforeAt = $before->at($schedule['weekday'], $schedule['time']);

            if ($now->gte($beforeAt) && $now->lt($beforeAt->addHours(self::CATCH_UP_HOURS))) {
                $week = $before;
                $sendAt = $beforeAt;
            }
        }

        if ($now->lt($sendAt)) {
            $run['skipped']['not_due']++;

            return;
        }

        if ($explicitDay === null && $now->gte($sendAt->addHours(self::CATCH_UP_HOURS))) {
            $run['skipped']['window_passed']++;

            return;
        }

        if (SchoolCalendar::for((int) $masjid->id)->closureWithin($week->startDate(), $week->lastDate()) !== null) {
            $run['skipped']['closed_week']++;

            return;
        }

        // Named explicitly: this command runs with no tenant bound, where the global
        // scope adds no filter at all.
        $groups = Group::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($groups as $group) {
            try {
                $this->sweepClass($masjid, $group, $resolver, $week, $sendAt, $dry, $run);
            } catch (Throwable $e) {
                $run['failures']++;
                Log::warning('points:weekly-report failed for class '.$group->id.': '.$e->getMessage());
            }
        }
    }

    /** @param array<string,mixed> $run */
    private function sweepClass(
        Masjid $masjid,
        Group $group,
        GroupNotificationRecipientResolver $resolver,
        PointsWeek $week,
        CarbonImmutable $sendAt,
        bool $dry,
        array &$run,
    ): void {
        $weekStart = $week->startDate();

        if (BehaviorWeek::sent((int) $group->id, $weekStart)) {
            $run['classes_already_sent']++;

            return;
        }

        // Live awards (the soft-delete scope drops a revoked one) that HAPPENED between
        // the start of the week and the scheduled moment.
        $awardedMembershipIds = $group->behaviorAwards()
            ->awardedWithin($week->startUtc(), $sendAt)
            ->distinct()
            ->pluck('group_membership_id')
            ->all();

        if ($awardedMembershipIds === []) {
            $run['classes_nobody_to_tell']++;

            return;
        }

        // Children still on the roster. An award of a child who has left is still theirs,
        // but the class's report is no longer about them.
        $wardContactIds = $group->memberships()
            ->participants()->current()
            ->whereIn('id', $awardedMembershipIds)
            ->pluck('contact_id')
            ->all();

        $families = $resolver->weeklyReportGuardians($group, array_map('intval', $wardContactIds));

        // The class summary exists only when a child still in the class has a week to
        // summarise, whoever is reachable.
        $teachers = $wardContactIds === []
            ? collect()
            : $resolver->classTeachers($group, null)->filter(fn (NudgeRecipient $r) => $r->realm === 'staff')->values();

        if ($families->isEmpty() && $teachers->isEmpty()) {
            $run['classes_nobody_to_tell']++;

            return;
        }

        if ($dry) {
            $run['classes_sent']++;
            $run['family_emails'] += $families->count();
            $run['teacher_emails'] += $teachers->count();

            return;
        }

        if (! BehaviorWeek::claim((int) $masjid->id, (int) $group->id, $weekStart)) {
            // Another run took it between the read above and now.
            $run['classes_already_sent']++;

            return;
        }

        $orgName = (string) $masjid->name;
        $orgEmail = $masjid->email ?? null;
        $groupLabel = (string) $group->name;
        $base = rtrim((string) config('app.url'), '/');

        // Both links NAME the week that was reported. Without it they open "the week in
        // progress", and a parent or teacher who reads Al-Razi's Friday 15:00 email on the
        // Sunday or Monday after lands on a new, empty week.
        $familyUrl = $base.'/family/'.$masjid->id.'/sign-in?next='
            .rawurlencode('/family/'.$masjid->id.'/classes/'.$group->id.'/report?week='.$weekStart);
        $teacherUrl = $base.'/teacher/classes/'.$group->id.'?tab=points&week='.$weekStart;

        $sentFamilies = $this->deliver($families, WeeklyPointsReportMail::AUDIENCE_FAMILY, $familyUrl, $orgName, $groupLabel, $orgEmail, $run);
        $sentTeachers = $this->deliver($teachers, WeeklyPointsReportMail::AUDIENCE_TEACHER, $teacherUrl, $orgName, $groupLabel, $orgEmail, $run);

        // Every address failed (the mail transport was down): nobody was told, so the
        // claim is given back and the next hourly run, still inside the catch-up window,
        // tries again. ONLY on total failure: after a partial send a retry would tell the
        // families who already have it a second time.
        if ($sentFamilies + $sentTeachers === 0) {
            BehaviorWeek::release((int) $group->id, $weekStart);
            $run['classes_undelivered']++;

            return;
        }

        DB::table('behavior_weeks')
            ->where('group_id', $group->id)
            ->where('week_start', $weekStart)
            ->update(['recipients_count' => $sentFamilies, 'updated_at' => now()]);

        $run['classes_sent']++;
        $run['family_emails'] += $sentFamilies;
        $run['teacher_emails'] += $sentTeachers;
    }

    /**
     * Send one mail per recipient; a dead address is logged and skipped. Returns how many went.
     *
     * @param  \Illuminate\Support\Collection<int,NudgeRecipient>  $recipients
     * @param  array<string,mixed>  $run
     */
    private function deliver($recipients, string $audience, string $url, string $orgName, string $groupLabel, ?string $orgEmail, array &$run): int
    {
        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->address)->send(new WeeklyPointsReportMail(
                    orgName: $orgName,
                    groupLabel: $groupLabel,
                    audience: $audience,
                    url: $url,
                    recipientName: $recipient->name,
                    orgEmail: $orgEmail,
                ));
                $sent++;
            } catch (Throwable $e) {
                $run['failures']++;
                Log::warning('weekly points report email failed for '.$recipient->address.': '.$e->getMessage());
            }
        }

        return $sent;
    }
}
