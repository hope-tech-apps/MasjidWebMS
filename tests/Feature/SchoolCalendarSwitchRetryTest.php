<?php

namespace Tests\Feature;

use App\Models\{Masjid, SchoolYear, User, MasjidCapabilityChange};
use App\Support\CapabilityWriter;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\{Test, DataProvider};
use Tests\TestCase;

/** Root transactions prove complete rollback; this replaces the superseded retry policy. */
class SchoolCalendarSwitchRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
    }

    public static function failures(): array
    {
        $cases = [];
        foreach ([1205, 1213] as $code) foreach ([true, false] as $enable) foreach (['direct', 'single', 'bulk'] as $path) $cases[] = [$code, $enable, $path];
        return $cases;
    }

    #[Test, DataProvider('failures')]
    public function busy_switch_fails_once_rolls_back_all_writes_and_surfaces_plain_words(int $code, bool $enable, string $path): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $org = Masjid::create(['name' => 'Busy School', 'email' => 'busy@example.invalid', 'phone' => '+15550008700', 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'capability_overrides' => ['school_calendar_terms' => ! $enable]]);
        $org->forceFill(['capability_overrides' => ['school_calendar_terms' => ! $enable]])->save();
        $actor = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550008701']);
        $year = SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Busy year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25', 'meeting_weekdays' => $enable ? null : [0]]);
        $beforeOrg = $org->fresh()->getRawOriginal(); $beforeYear = $year->fresh()->getRawOriginal();
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($sql) use (&$attempts, $code) {
            // Fail after initialization and the organisation UPDATE, before ledger INSERT.
            if (! str_starts_with($sql, 'insert into "masjid_capability_changes"')) return;
            $attempts++;
            $cause = new \PDOException($code === 1213 ? 'Deadlock found' : 'Lock wait timeout exceeded');
            $cause->errorInfo = [$code === 1213 ? '40001' : 'HY000', $code, 'Calendar lock busy'];
            throw new QueryException('sqlite', $sql, [], $cause);
        });
        $message = 'The school calendar is being edited. Try again in a few moments.';
        if ($path === 'direct') {
            try {
                CapabilityWriter::apply($org, ['school_calendar_terms' => $enable], $actor->id);
                $this->fail('The busy switch must refuse');
            } catch (ValidationException $e) {
                $this->assertSame(['capability' => [$message]], $e->errors());
            }
        } else {
            Sanctum::actingAs($actor);
            $url = '/api/admin/masjids/'.$org->id.'/capabilities';
            $body = $path === 'single' ? ['enabled' => $enable ? '1' : '0'] : ['capabilities' => ['school_calendar_terms' => $enable, 'school_calendar' => true]];
            $this->patchJson($url.($path === 'single' ? '/school_calendar_terms' : ''), $body)->assertUnprocessable()->assertJsonPath('data.capability.0', $message);
        }
        $this->assertSame(1, $attempts, 'There is no retry, whichever transaction would be the victim');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($beforeOrg, $org->fresh()->getRawOriginal());
        $this->assertSame($beforeYear, $year->fresh()->getRawOriginal());
        $this->assertSame(0, MasjidCapabilityChange::count());
    }
}
