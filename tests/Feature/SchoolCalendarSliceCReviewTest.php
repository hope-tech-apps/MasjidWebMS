<?php

namespace Tests\Feature;

use App\Models\{Masjid, SchoolYear, SchoolClosure, SchoolTerm, Form};
use App\Support\{SchoolCalendarReaders, SchoolCalendarRequestMode, FormSchema, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Artisan, DB, Mail};
use PHPUnit\Framework\Attributes\{Test, DataProvider};
use Tests\TestCase;

class SchoolCalendarSliceCReviewTest extends TestCase
{
    use RefreshDatabase;

    private function school(bool $on = true): Masjid
    {
        app(TenantContext::class)->forgetTenant();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-09 19:00:00', 'UTC'));
        $org = Masjid::create(['name' => 'Review School', 'email' => 'review@example.invalid', 'phone' => '+15550007731', 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'timezone' => 'America/New_York']);
        $org->forceFill(['capability_overrides' => ['points_weekly_report' => true, 'school_calendar_terms' => $on]])->save();
        return $org;
    }

    private function http(callable $checks): void
    {
        $request = Request::create('/review');
        $this->app->instance('request', $request);
        (new \App\Http\Middleware\SchoolCalendarHttpRequest)->handle($request, function () use ($checks) {
            $checks();
            return response('ok');
        });
        $this->assertFalse($request->attributes->has(SchoolCalendarReaders::ATTRIBUTE));
    }

    public static function emailWeeks(): array { return [[false], [true]]; }

    #[Test, DataProvider('emailWeeks')]
    public function off_weekly_sweep_matches_main_literal_statements(bool $closure): void
    {
        $org = $this->school(false);
        $year = SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-05', 'last_day' => '2026-10-30', 'meeting_weekdays' => [1,2,3,4,5]]);
        if ($closure) SchoolClosure::create(['masjid_id' => $org->id, 'school_year_id' => $year->id, 'closed_on' => '2026-10-06', 'reason' => 'Staff day']);
        Mail::fake();
        DB::flushQueryLog(); DB::enableQueryLog();
        $exit = Artisan::call('points:weekly-report');
        $sql = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
        $this->assertSame(0, $exit);
        Mail::assertNothingSent();
        $file = base_path('tests/fixtures/calendar-readers/weekly-email-'.($closure ? 'closure' : 'open').'.json');
        if (getenv('CAPTURE_WEEKLY_MAIN') === '1') file_put_contents($file, json_encode(['sql' => $sql], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->assertSame(json_decode(file_get_contents($file), true)['sql'], $sql);
    }

    #[Test]
    public function on_weekly_sweep_has_only_mains_organisation_reads(): void
    {
        $org = $this->school();
        SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-05', 'last_day' => '2026-10-30', 'meeting_weekdays' => [1,2,3,4,5]]);
        Mail::fake(); DB::flushQueryLog(); DB::enableQueryLog();
        $this->assertSame(0, Artisan::call('points:weekly-report'));
        $sql = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
        $this->assertCount(3, array_filter($sql, fn ($q) => str_contains($q, 'from "masjids"')), 'Enumeration, main timezone read, main calendar read only');
    }

    public static function writes(): array
    {
        $out = [];
        foreach (['year', 'closure', 'term'] as $model) foreach (['create', 'update', 'delete'] as $write) $out[$model.' '.$write] = [$model, $write];
        return $out;
    }

    #[Test, DataProvider('writes')]
    public function writes_refresh_the_authority_in_the_same_http_request(string $kind, string $write): void
    {
        $org = $this->school();
        $year = SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-05', 'last_day' => '2026-10-30', 'meeting_weekdays' => [1,2,3,4,5]]);
        $closure = SchoolClosure::create(['masjid_id' => $org->id, 'school_year_id' => $year->id, 'closed_on' => '2026-10-06', 'reason' => 'Before']);
        $term = SchoolTerm::create(['masjid_id' => $org->id, 'school_year_id' => $year->id, 'name' => 'Before', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-30', 'position' => 1]);
        $this->http(function () use ($org, $year, $closure, $term, $kind, $write) {
            $warm = SchoolCalendarReaders::for($org->id);
            $model = match ($kind) { 'year' => $year, 'closure' => $closure, 'term' => $term };
            if ($write === 'delete') $model->delete();
            elseif ($write === 'update') $model->update(match ($kind) { 'year' => ['meeting_weekdays' => [1]], 'closure' => ['closed_on' => '2026-10-07', 'reason' => 'After'], 'term' => ['name' => 'After'] });
            else match ($kind) {
                'year' => SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Next', 'first_day' => '2026-11-02', 'last_day' => '2026-11-30', 'meeting_weekdays' => [1,2,3,4,5]]),
                'closure' => SchoolClosure::create(['masjid_id' => $org->id, 'school_year_id' => $year->id, 'closed_on' => '2026-10-07', 'reason' => 'After']),
                'term' => SchoolTerm::create(['masjid_id' => $org->id, 'school_year_id' => $year->id, 'name' => 'After', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-30', 'position' => 2]),
            };
            $fresh = SchoolCalendarReaders::for($org->id);
            $this->assertNotSame($warm, $fresh);
            if ($kind === 'year') {
                $this->assertCount($write === 'create' ? 2 : ($write === 'delete' ? 0 : 1), $fresh->years());
                if ($write === 'update') $this->assertFalse($fresh->isMeetingDay('2026-10-07'));
            } elseif ($kind === 'closure') {
                $this->assertSame($write !== 'delete', $fresh->schoolDay('2026-10-07')['closed']);
                $this->assertSame($write === 'create', $fresh->schoolDay('2026-10-06')['closed']);
            } else {
                $terms = $fresh->years()->first()->terms;
                $this->assertCount($write === 'create' ? 2 : ($write === 'delete' ? 0 : 1), $terms);
                if ($write === 'update') $this->assertSame('After', $terms->first()->name);
            }
        });
    }

    #[Test]
    public function switch_write_refreshes_the_same_requests_authority(): void
    {
        $org = $this->school();
        SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-05', 'last_day' => '2026-10-30', 'meeting_weekdays' => [1,2,3,4,5]]);
        $this->http(function () use ($org) {
            $warm = SchoolCalendarReaders::for($org->id);
            $org->forceFill(['capability_overrides' => ['school_calendar_terms' => false]])->save();
            $this->assertFalse(SchoolCalendarRequestMode::enabled($org->id));
            $fresh = SchoolCalendarReaders::for($org->id);
            $this->assertNotSame($warm, $fresh);
            $this->assertFalse($fresh->isMeetingDay('2026-10-07'));
        });
    }

    #[Test]
    public function a_bulk_writer_explicitly_invalidates_after_bypassing_model_events(): void
    {
        $org = $this->school();
        SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-05', 'last_day' => '2026-10-30', 'meeting_weekdays' => [1,2,3,4,5]]);
        $this->http(function () use ($org) {
            $warm = SchoolCalendarReaders::for($org->id);
            DB::table('school_years')->where('masjid_id', $org->id)->update(['meeting_weekdays' => '[1]']);
            // Bulk Eloquent/query-builder writes emit no saved/deleted event.
            SchoolCalendarReaders::forget($org->id);
            $fresh = SchoolCalendarReaders::for($org->id);
            $this->assertNotSame($warm, $fresh);
            $this->assertFalse($fresh->isMeetingDay('2026-10-07'));
        });
        // Console bulk writers (including staging scrub) have no memo to clear.
        DB::table('school_years')->where('masjid_id', $org->id)->update(['meeting_weekdays' => '[1,2,3,4,5]']);
        $this->assertTrue(SchoolCalendarReaders::for($org->id)->isMeetingDay('2026-10-07'));
        DB::table('school_years')->where('masjid_id', $org->id)->update(['meeting_weekdays' => '[1]']);
        $this->assertFalse(SchoolCalendarReaders::for($org->id)->isMeetingDay('2026-10-07'));
    }

    public static function unavailable(): array
    {
        $out = [];
        foreach ([false, true] as $on) foreach (['short', 'empty'] as $offer) foreach (['select', 'radio', 'checkboxGroup'] as $type) foreach ([false, true] as $blank) {
            if ($offer === 'short' && $type !== 'checkboxGroup') continue;
            $out[] = [$on, $offer, $type, $blank];
        }
        return $out;
    }

    #[Test, DataProvider('unavailable')]
    public function unavailable_words_follow_the_switch(bool $on, string $offer, string $type, bool $blank): void
    {
        $org = $this->school($on);
        SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-05', 'last_day' => $offer === 'empty' ? '2026-10-09' : '2026-10-16', 'meeting_weekdays' => [1,2,3,4,5]]);
        $field = ['name' => 'days', 'label' => 'Days', 'type' => $type, 'optionsSource' => 'school_meeting_days', 'required' => true];
        if ($offer === 'short') $field += ['minSelections' => 6];
        $form = Form::create(['masjid_id' => $org->id, 'slug' => 'review-form', 'name' => 'Days', 'schema' => ['sections' => [['id' => 'days', 'fields' => [$field]]]]]);
        $this->http(function () use ($form, $on, $offer, $type, $blank) {
            $data = $blank ? [] : ['days' => $type === 'checkboxGroup' ? ['2026-10-12'] : '2026-10-12'];
            if ($on) $this->assertCount($offer === 'short' ? 5 : 0, \App\Support\FormOptionSources::resolve($form, ['optionsSource' => 'school_meeting_days'], \App\Support\FormOptionSources::OFFER));
            $validator = FormSchema::for($form)->validator($data);
            $this->assertTrue($validator->fails());
            $expected = ($offer === 'short' ? 'Not enough ' : 'No ').($on ? 'school days' : 'cleaning Sundays').' are open right now.';
            $this->assertContains($expected, $validator->errors()->all());
        });
    }

    #[Test, DataProvider('emailWeeks')]
    public function on_weekly_summary_counts_active_classes_skipped_for_no_open_school_day(bool $dry): void
    {
        Mail::fake();
        $org = $this->school();
        $year = SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-05', 'last_day' => '2026-10-30', 'meeting_weekdays' => [1,2]]);
        foreach (['2026-10-05', '2026-10-06'] as $day) SchoolClosure::create(['masjid_id' => $org->id, 'school_year_id' => $year->id, 'closed_on' => $day, 'reason' => 'Closed']);
        foreach ([true, true, false] as $active) \App\Models\Group::factory()->create(['masjid_id' => $org->id, 'is_active' => $active]);
        $other = Masjid::create(['name' => 'Other School', 'email' => 'other@example.invalid', 'phone' => '+15550007732', 'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
        \App\Models\Group::factory()->create(['masjid_id' => $other->id]);
        $args = ['--masjid' => $org->id, '--week' => '2026-10-04', '--dry-run' => $dry];
        $this->assertSame(0, Artisan::call('points:weekly-report', $args));
        $this->assertSame('points:weekly-report'.($dry ? ' (dry run)' : '').': 1 organisation(s), 0 class(es) '.($dry ? 'would be sent' : 'sent').', 0 family notice(s), 0 teacher notice(s), 0 already sent, 0 with nobody to tell, 0 undelivered (retried next run), 0 failure(s), 2 class(es) skipped: no school that week.' . PHP_EOL, Artisan::output());
        Mail::assertNothingSent();
        // A week outside the year also has no open school day.
        $args['--week'] = '2026-09-27';
        $this->assertSame(0, Artisan::call('points:weekly-report', $args));
        $this->assertStringContainsString('2 class(es) skipped: no school that week.', Artisan::output());
        $org->forceFill(['capability_overrides' => ['points_weekly_report' => true]])->save();
        $args['--week'] = '2026-10-04';
        $this->assertSame(0, Artisan::call('points:weekly-report', $args));
        $this->assertSame('points:weekly-report'.($dry ? ' (dry run)' : '').': 1 organisation(s), 0 class(es) '.($dry ? 'would be sent' : 'sent').', 0 family notice(s), 0 teacher notice(s), 0 already sent, 0 with nobody to tell, 0 undelivered (retried next run), 0 failure(s).' . PHP_EOL, Artisan::output());
    }
}
