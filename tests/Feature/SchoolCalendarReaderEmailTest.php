<?php

namespace Tests\Feature;

use App\Mail\WeeklyPointsReportMail;
use App\Models\{Masjid, MasjidUser, User, Group, GroupStaff, Contact, GroupMembership, BehaviorAward, SchoolYear, SchoolClosure, BehaviorWeek};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, Mail, DB};
use PHPUnit\Framework\Attributes\{Test, DataProvider};
use Tests\TestCase;

class SchoolCalendarReaderEmailTest extends TestCase
{
    use RefreshDatabase;

    public static function weeks(): array
    {
        return [[true, ['2026-10-06'], true], [true, ['2026-10-05','2026-10-06','2026-10-07','2026-10-08','2026-10-09'], false], [false, ['2026-10-06'], false], [true, [], true]];
    }

    #[Test, DataProvider('weeks')]
    public function scheduled_email_skips_only_when_the_on_week_has_no_open_school_day(bool $on, array $closed, bool $sent): void
    {
        app(TenantContext::class)->forgetTenant();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-09 19:00:00', 'UTC'));
        $org = Masjid::create(['name' => 'Email School', 'email' => 'email-school@example.invalid', 'phone' => '+15550007711', 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true, 'timezone' => 'America/New_York']);
        $org->forceFill(['capability_overrides' => ['points_weekly_report' => true, 'school_calendar_terms' => $on]])->save();
        $year = SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-05', 'last_day' => '2026-10-30', 'meeting_weekdays' => [1,2,3,4,5]]);
        foreach ($closed as $day) SchoolClosure::create(['masjid_id' => $org->id, 'school_year_id' => $year->id, 'closed_on' => $day, 'reason' => 'Staff day']);
        $group = Group::factory()->create(['masjid_id' => $org->id, 'kind' => 'class']);
        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15550007712', 'email' => 'teacher@example.invalid']);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
        $group->staff()->attach($teacher->id, ['masjid_id' => $org->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now()]);
        $child = Contact::factory()->create(['masjid_id' => $org->id]);
        $member = GroupMembership::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'contact_id' => $child->id, 'role' => 'member']);
        BehaviorAward::factory()->create(['masjid_id' => $org->id, 'group_id' => $group->id, 'group_membership_id' => $member->id, 'awarded_at' => '2026-10-07 15:00:00']);
        Mail::fake();
        $this->assertSame(0, Artisan::call('points:weekly-report'));
        if ($sent) Mail::assertSent(WeeklyPointsReportMail::class, 1); else Mail::assertNothingSent();
        $this->assertSame($sent ? 1 : 0, BehaviorWeek::count());
        $this->assertFalse(request()->attributes->has(\App\Support\SchoolCalendarReaders::ATTRIBUTE));
        // Same console Request/command, fresh calendar decision after a raw flip.
        if (! $sent && ! $on) {
            DB::table('masjids')->where('id', $org->id)->update(['capability_overrides' => json_encode(['points_weekly_report' => true, 'school_calendar_terms' => true])]);
            $this->assertSame(0, Artisan::call('points:weekly-report'));
            Mail::assertSent(WeeklyPointsReportMail::class, 1);
        }
    }
}
