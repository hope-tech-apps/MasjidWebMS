<?php

namespace Tests\Feature;

use App\Jobs\SendAttendanceAbsenceMail;
use App\Mail\AttendanceAbsenceMail as AbsenceMail;
use App\Models\{AttendanceAbsenceMail, AttendanceAbsenceMailChild, Contact, GroupMembership, Masjid, MasjidPointsSetting, MasjidUser, SchoolClosure, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, Auth, DB, Mail};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Tests\Support\ParentAttendanceFixtures;
use Tests\TestCase;

class AttendanceAbsenceEmailTest extends TestCase
{
    use RefreshDatabase, ParentAttendanceFixtures;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-19 14:00:00', 'UTC'));
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);
        Mail::fake();
    }
    protected function tearDown(): void { app(TenantContext::class)->forgetTenant(); parent::tearDown(); }
    private function enable(Masjid $school): void { $school->forceFill(['capability_overrides' => ['school_calendar' => true, 'school_calendar_terms' => true, 'attendance_absence_emails' => true]])->save(); }
    private function sweep(): void { app(TenantContext::class)->forgetTenant(); $this->artisan('attendance:absence-emails')->assertSuccessful(); }
    private function work(): void
    {
        foreach (DB::table('jobs')->orderBy('id')->get() as $row) {
            $job = unserialize(json_decode($row->payload, true)['data']['command']);
            $job->handle(); $job->handle();
        }
    }

    #[Test]
    public function off_means_no_logs_or_jobs_and_hides_the_grant_even_in_raw_overrides(): void
    {
        $school = $this->makeMasjid(); $this->year($school); $child = $this->enrol($this->classroom($school)); $this->guardian($child); $this->mark($child);
        $school->forceFill(['capability_overrides' => ['attendance_absence_emails' => false]])->save(); $this->sweep();
        $this->assertDatabaseCount('attendance_absence_mails', 0); $this->assertDatabaseCount('jobs', 0);
        $this->assertArrayNotHasKey('attendance_absence_emails', $school->capabilities);
        $this->assertArrayNotHasKey('attendance_absence_emails', $school->attributesToArray()['capability_overrides']);
        $this->assertSame(['masjid' => false, 'school' => false, 'community' => false], config('capabilities.attendance_absence_emails.defaults'));
    }

    #[Test]
    public function all_off_catalogues_hide_the_grant_and_an_off_noop_creates_no_override_or_audit(): void
    {
        $school = $this->makeMasjid();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550106', 'name' => 'Practice Operator', 'email' => 'operator@example.invalid']), ['staff']);
        foreach (["/api/admin/masjids/{$school->id}/capabilities", '/api/admin/studio/catalogue?org_type=school'] as $url) {
            $this->assertStringNotContainsString('attendance_absence_emails', $this->getJson($url)->assertOk()->getContent());
        }
        $this->patchJson("/api/admin/masjids/{$school->id}/capabilities/attendance_absence_emails", ['enabled' => false])->assertOk();
        $this->assertArrayNotHasKey('attendance_absence_emails', $school->fresh()->capability_overrides ?? []);
        $this->assertDatabaseMissing('masjid_capability_changes', ['masjid_id' => $school->id, 'capability' => 'attendance_absence_emails']);
    }

    #[Test]
    public function school_delivery_history_is_an_office_record_when_a_member_removes_their_login(): void
    {
        $school = $this->makeMasjid();
        $parent = new Contact(); $parent->forceFill(['masjid_id' => $school->id, 'first_name' => 'Practice', 'last_name' => 'Guardian', 'email' => 'history@example.invalid', 'login_email' => 'history@example.invalid', 'signup_source' => 'app', 'verified_at' => now()])->save();
        $service = app(\App\Services\Member\MemberAccountDeletion::class);
        $this->assertSame([], $service->reasonsToKeep($parent->fresh()));
        $mail = AttendanceAbsenceMail::create(['masjid_id' => $school->id, 'guardian_contact_id' => $parent->id, 'guardian_email' => $parent->email, 'attendance_date' => '2026-10-19', 'status' => 'sent', 'queued_at' => now(), 'sent_at' => now()]);
        $this->assertContains('attendance_absence_mails', $service->reasonsToKeep($parent->fresh()));
        $result = $service->delete($parent, 'app');
        $this->assertSame('login_removed', $result['outcome']);
        $this->assertNotNull(Contact::find($parent->id)); $this->assertNotNull(AttendanceAbsenceMail::find($mail->id));
    }

    #[Test]
    public function missing_optional_logs_do_not_block_account_checks_before_the_migration(): void
    {
        $school = $this->makeMasjid();
        $parent = new Contact(); $parent->forceFill(['masjid_id' => $school->id, 'first_name' => 'Practice', 'last_name' => 'Guardian', 'email' => 'window@example.invalid', 'login_email' => 'window@example.invalid', 'signup_source' => 'app', 'verified_at' => now()])->save();
        \Illuminate\Support\Facades\Schema::drop('attendance_absence_mail_children');
        \Illuminate\Support\Facades\Schema::drop('attendance_absence_mails');
        $this->assertSame([], app(\App\Services\Member\MemberAccountDeletion::class)->reasonsToKeep($parent->fresh()));
    }

    #[Test]
    public function absent_only_batches_siblings_and_classes_once_per_confirmed_guardian_without_a_portal_login(): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $this->year($school); $class = $this->classroom($school);
        $first = $this->enrol($class); $parent = $this->guardian($first);
        $second = $this->enrol($class); $this->guardian($second, $parent);
        $otherClass = $this->enrol($this->classroom($school, 'Second Class'), $first->contact); $this->guardian($otherClass, $parent);
        $secondParent = Contact::factory()->create(['masjid_id' => $school->id, 'email' => 'second@example.invalid', 'login_email' => null, 'login_enabled_at' => null]); $this->guardian($first, $secondParent);
        foreach ([$first, $second, $otherClass] as $child) $this->mark($child);
        foreach (['late', 'excused', 'present'] as $status) { $child = $this->enrol($class); $this->guardian($child, $parent); $this->mark($child, status: $status); }
        $unmarked = $this->enrol($class); $this->guardian($unmarked, $parent);
        $this->sweep(); $this->sweep();
        $this->assertDatabaseCount('attendance_absence_mails', 2); $this->assertDatabaseCount('attendance_absence_mail_children', 3); $this->assertDatabaseCount('jobs', 2);
        $firstRow = AttendanceAbsenceMail::where('guardian_contact_id', $parent->id)->firstOrFail();
        $this->assertSame('guardian@example.invalid', $firstRow->guardian_email);
        $this->assertCount(2, AttendanceAbsenceMailChild::where('mail_id', $firstRow->id)->where('student_contact_id', $first->contact_id)->firstOrFail()->classes);
        $this->assertStringNotContainsString('guardian@example.invalid', DB::table('jobs')->first()->payload);
        $this->work(); Mail::assertSent(AbsenceMail::class, 2);
        $sent = Mail::sent(AbsenceMail::class)->first(fn ($mail) => $mail->hasTo('guardian@example.invalid'));
        $this->assertSame('Practice School a: Attendance for October 19, 2026', $sent->mailSubject);
        $this->assertSame("Sample was marked absent from Practice Class, Second Class today, October 19, 2026.\nSample was marked absent from Practice Class today, October 19, 2026.\n\nTo reach the office, call +15555550101 or email office-a@example.invalid.", $sent->bodyText);
        $sent->assertFrom(config('mail.from.address'), $school->name); $this->assertTrue($sent->hasReplyTo($school->email));
        $this->assertStringNotContainsString('Staff-only', $sent->render());
        $this->assertSame(['sent'], AttendanceAbsenceMail::pluck('status')->unique()->values()->all());
        // A later register for a new sibling must not cause a second guardian/day email.
        $this->mark($unmarked); $this->sweep(); $this->assertDatabaseCount('attendance_absence_mails', 2);
    }

    #[Test]
    public function the_school_snapshots_once_even_when_nobody_is_absent_and_later_registers_do_not_send(): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $this->year($school); $class = $this->classroom($school);
        $child = $this->enrol($class); $this->guardian($child); $this->mark($child, status: 'present');
        $this->sweep(); $this->assertDatabaseCount('jobs', 0);
        $other = $this->enrol($class); $this->guardian($other, Contact::factory()->create(['masjid_id' => $school->id, 'email' => 'later@example.invalid'])); $this->mark($other);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-19 14:05:00', 'UTC')); $this->sweep();
        $this->assertDatabaseCount('jobs', 0); $this->assertDatabaseCount('attendance_absence_mails', 0);
    }

    #[Test]
    public function unconfirmed_no_email_and_different_familys_class_are_skipped(): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $this->year($school); $class = $this->classroom($school);
        $child = $this->enrol($class); $this->guardian($child, provenance: 'self_asserted');
        $empty = Contact::factory()->create(['masjid_id' => $school->id, 'email' => null, 'login_email' => null]); $this->guardian($child, $empty); $this->mark($child);
        $present = $this->enrol($class); $this->guardian($present, Contact::factory()->create(['masjid_id' => $school->id, 'email' => 'different@example.invalid'])); $this->mark($present, status: 'present');
        $this->sweep(); $this->assertDatabaseCount('attendance_absence_mails', 0); Mail::assertNothingSent();
    }

    #[Test]
    public function correction_before_time_decides_and_office_time_round_trips_in_form_encoding(): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $this->year($school); $child = $this->enrol($this->classroom($school)); $this->guardian($child); $mark = $this->mark($child);
        MasjidPointsSetting::create(['masjid_id' => $school->id, 'attendance_absence_send_time' => '10:30']);
        $this->sweep(); $this->assertDatabaseCount('jobs', 0);
        $mark->update(['status' => 'late']);
        $absent = $this->enrol($child->group); $this->guardian($absent, $child->group->memberships()->where('role', 'guardian')->first()->contact); $this->mark($absent);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-19 14:30:00', 'UTC')); $this->sweep(); $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseMissing('attendance_absence_mail_children', ['student_contact_id' => $child->contact_id]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $office = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15555550104', 'name' => 'Practice Office', 'email' => 'staff@example.invalid']); $office->assignRole('masjid-admin'); MasjidUser::create(['masjid_id' => $school->id, 'user_id' => $office->id, 'role' => 'admin', 'is_default' => true]);
        Auth::forgetGuards(); Sanctum::actingAs($office, ['staff']);
        $url = "/api/admin/masjids/{$school->id}/attendance/absence-emails/settings";
        $this->put($url, ['send_time' => '11:05'])->assertOk()->assertJsonPath('data.send_time', '11:05');
        $this->put($url, ['send_time' => '25:00'])->assertUnprocessable();
        $this->getJson("/api/admin/masjids/{$school->id}/attendance/registers?date=2026-10-19")->assertOk()->assertJsonPath('data.absence_emails.send_time', '11:05')->assertJsonPath('data.absence_emails.rows.0.guardian_email', 'guardian@example.invalid')->assertJsonPath('data.absence_emails.rows.0.status', 'queued');
        app(TenantContext::class)->forgetTenant(); $school->forceFill(['capability_overrides' => []])->save();
        $this->put($url, ['send_time' => '12:00'])->assertNotFound();
        $this->getJson("/api/admin/masjids/{$school->id}/attendance/registers")->assertOk()->assertJsonMissingPath('data.absence_emails');
    }

    public static function unavailableDays(): array { return [['2026-10-19 13:59:00', false, true], ['2026-10-18 16:00:00', false, true], ['2027-07-01 16:00:00', false, true], ['2026-10-19 16:00:00', true, true], ['2026-10-19 16:00:00', false, false]]; }
    #[Test, DataProvider('unavailableDays')]
    public function before_time_closed_nonmeeting_outside_year_or_no_calendar_sends_nothing(string $now, bool $closed, bool $calendar): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $year = $calendar ? $this->year($school) : null;
        $this->travelTo(\Carbon\Carbon::parse($now, 'UTC')); $date = now()->setTimezone('America/New_York')->toDateString();
        if ($closed) SchoolClosure::create(['masjid_id' => $school->id, 'school_year_id' => $year->id, 'closed_on' => $date, 'reason' => 'Practice closure']);
        $child = $this->enrol($this->classroom($school)); $this->guardian($child); $this->mark($child, $date);
        $this->sweep(); $this->assertDatabaseCount('jobs', 0); $this->assertDatabaseCount('attendance_absence_mails', 0);
    }

    #[Test]
    public function failure_records_only_exception_class_and_never_retries_delivery(): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $this->year($school); $child = $this->enrol($this->classroom($school)); $this->guardian($child); $this->mark($child); $this->sweep();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('private details guardian@example.invalid'));
        $this->work(); $this->sweep();
        $row = AttendanceAbsenceMail::firstOrFail(); $this->assertSame('failed', $row->status); $this->assertSame(\RuntimeException::class, $row->exception_class); $this->assertNull($row->sent_at);
        $this->assertStringNotContainsString('private details', json_encode($row->getAttributes())); $this->assertDatabaseCount('attendance_absence_mails', 1);
    }

    #[Test]
    public function a_crashed_claim_is_not_repeated_and_stale_sending_is_visible_as_failed(): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $this->year($school); $child = $this->enrol($this->classroom($school)); $this->guardian($child); $this->mark($child); $this->sweep();
        AttendanceAbsenceMail::firstOrFail()->update(['status' => 'sending', 'attempted_at' => now()->subMinutes(15)]);
        $this->sweep(); $this->work(); Mail::assertNothingSent();
        $this->assertSame('failed', AttendanceAbsenceMail::first()->status);
    }

    public static function revokedBeforeWork(): array { return [['guardian'], ['off'], ['closure'], ['date'], ['email'], ['withdrawn']]; }
    #[Test, DataProvider('revokedBeforeWork')]
    public function jobs_recheck_guardian_switch_calendar_and_date_before_disclosure(string $reason): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $year = $this->year($school); $child = $this->enrol($this->classroom($school)); $parent = $this->guardian($child); $this->mark($child); $this->sweep();
        if ($reason === 'guardian') GroupMembership::where('contact_id', $parent->id)->update(['provenance' => 'self_asserted']);
        if ($reason === 'off') $school->forceFill(['capability_overrides' => []])->save();
        if ($reason === 'closure') SchoolClosure::create(['masjid_id' => $school->id, 'school_year_id' => $year->id, 'closed_on' => '2026-10-19', 'reason' => 'Practice closure']);
        if ($reason === 'date') $this->travelTo(\Carbon\Carbon::parse('2026-10-20 14:00:00', 'UTC'));
        if ($reason === 'email') $parent->update(['email' => 'changed@example.invalid']);
        if ($reason === 'withdrawn') $child->forceFill(['left_on' => '2026-10-19'])->save();
        $this->work(); Mail::assertNothingSent(); $this->assertSame('skipped', AttendanceAbsenceMail::first()->status);
    }

    #[Test]
    public function the_real_queue_middleware_paces_guardians_before_the_send_claim(): void
    {
        config(['attendance.absence_emails_per_minute' => 1]);
        $school = $this->makeMasjid(); $this->enable($school); $this->year($school); $child = $this->enrol($this->classroom($school)); $this->guardian($child);
        $this->guardian($child, Contact::factory()->create(['masjid_id' => $school->id, 'email' => 'second@example.invalid'])); $this->mark($child); $this->sweep();
        $jobs = DB::table('jobs')->orderBy('id')->get()->map(fn ($row) => unserialize(json_decode($row->payload, true)['data']['command']));
        $first = $jobs[0]; $second = $jobs[1];
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('release')->once()->withArgs(fn ($delay) => $delay > 0);
        $second->setJob($queueJob);
        foreach ($jobs as $job) (new \Illuminate\Pipeline\Pipeline(app()))->send($job)->through($job->middleware())->then(fn ($job) => $job->handle());
        Mail::assertSent(AbsenceMail::class, 1);
        $this->assertSame('queued', AttendanceAbsenceMail::findOrFail($second->mailId)->status);
        $this->assertNull(AttendanceAbsenceMail::findOrFail($second->mailId)->attempted_at);
        $this->assertSame(0, $second->tries);
        $this->assertSame('2026-10-20 00:00:00', $second->retryUntil()->setTimezone('America/New_York')->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function production_nondelivery_transports_fail_the_log_and_never_report_sent(): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $this->year($school); $child = $this->enrol($this->classroom($school)); $this->guardian($child); $this->mark($child); $this->sweep();
        $manager = new \Illuminate\Mail\MailManager(app()); Mail::swap($manager);
        $transport = new \Illuminate\Mail\Transport\ArrayTransport();
        $manager->mailer()->setSymfonyTransport(new \Symfony\Component\Mailer\Transport\FailoverTransport([$transport]));
        config(['app.env' => 'production']);
        $this->work();
        $this->assertCount(0, $transport->messages());
        $this->assertSame('failed', AttendanceAbsenceMail::first()->status);
        $this->assertSame(\RuntimeException::class, AttendanceAbsenceMail::first()->exception_class);
    }

    #[Test]
    public function scheduler_and_schema_have_overlap_unique_claim_and_primary_key_guards(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'attendance:absence-emails'));
        $this->assertNotNull($event); $this->assertTrue($event->withoutOverlapping); $this->assertSame('*/5 * * * *', $event->expression);
        foreach (['attendance_absence_mails', 'attendance_absence_mail_children'] as $table) {
            $indexes = \Illuminate\Support\Facades\Schema::getIndexes($table);
            $this->assertNotEmpty(array_filter($indexes, fn ($index) => $index['primary']));
        }
        $batch = collect(\Illuminate\Support\Facades\Schema::getIndexes('attendance_absence_mails'))->firstWhere('name', 'absence_guardian_day_unique');
        $child = collect(\Illuminate\Support\Facades\Schema::getIndexes('attendance_absence_mail_children'))->firstWhere('name', 'absence_guardian_student_day_unique');
        $this->assertTrue($batch['unique']); $this->assertSame(['guardian_contact_id', 'attendance_date'], $batch['columns']);
        $this->assertTrue($child['unique']); $this->assertSame(['guardian_contact_id', 'student_contact_id', 'attendance_date'], $child['columns']);
    }

    #[Test]
    public function logs_and_jobs_never_cross_tenants_and_both_models_stamp_the_bound_tenant(): void
    {
        $a = $this->makeMasjid(); $b = $this->makeMasjid('b');
        foreach ([$a, $b] as $school) { $this->enable($school); $this->year($school); $child = $this->enrol($this->classroom($school)); $this->guardian($child); $this->mark($child); }
        $this->sweep(); $this->assertDatabaseCount('attendance_absence_mails', 2);
        $foreign = AttendanceAbsenceMail::where('masjid_id', $b->id)->firstOrFail(); $foreignChild = AttendanceAbsenceMailChild::where('mail_id', $foreign->id)->firstOrFail();
        app(TenantContext::class)->set($a->id);
        $this->assertNull(AttendanceAbsenceMail::find($foreign->id)); $this->assertNull(AttendanceAbsenceMailChild::find($foreignChild->id));
        $this->assertSame(0, AttendanceAbsenceMail::whereKey($foreign->id)->update(['status' => 'sent']));
        $this->assertSame(0, AttendanceAbsenceMailChild::whereKey($foreignChild->id)->delete());
        $new = AttendanceAbsenceMail::create(['masjid_id' => $b->id, 'guardian_contact_id' => $foreign->guardian_contact_id, 'guardian_email' => 'test@example.invalid', 'attendance_date' => '2026-10-20', 'status' => 'queued', 'queued_at' => now()]);
        $newChild = AttendanceAbsenceMailChild::create(['masjid_id' => $b->id, 'mail_id' => $new->id, 'guardian_contact_id' => $foreign->guardian_contact_id, 'student_contact_id' => $foreignChild->student_contact_id, 'attendance_date' => '2026-10-20', 'child_name' => 'Sample', 'classes' => []]);
        $this->assertSame($a->id, (int) $new->masjid_id); $this->assertSame($a->id, (int) $newChild->masjid_id);
        app(TenantContext::class)->forgetTenant();
    }
}
