<?php

namespace Tests\Feature;

use App\Models\{DutyRota, DutyRotaAssignment, DutyRotaFamily, DutyRotaMail, Masjid, SchoolYear};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DutyRotaModelIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_duty_rota_model_hides_foreign_rows_and_stamps_the_tenant(): void
    {
        $a = $this->makeMasjid('a'); $b = $this->makeMasjid('b'); $tenant = app(TenantContext::class);
        $tenant->forgetTenant();
        $year = SchoolYear::create(['masjid_id' => $b->id, 'label' => 'Practice year', 'first_day' => '2026-09-06', 'last_day' => '2027-06-27']);
        $rota = DutyRota::create(['masjid_id' => $b->id, 'school_year_id' => $year->id, 'name' => 'Practice duty']);
        $family = DutyRotaFamily::create(['masjid_id' => $b->id, 'rota_id' => $rota->id, 'identity_hash' => hash('sha256', 'maple'), 'name' => 'Maple Family']);
        $assignment = DutyRotaAssignment::create(['masjid_id' => $b->id, 'rota_id' => $rota->id, 'family_id' => $family->id, 'duty_on' => '2026-10-18', 'source' => 'office']);
        $mail = DutyRotaMail::create(['masjid_id' => $b->id, 'rota_id' => $rota->id, 'family_id' => $family->id, 'assignment_id' => $assignment->id, 'kind' => 'reminder', 'dedupe_key' => 'practice-b', 'status' => 'queued', 'queued_at' => now()]);

        $tenant->set($a->id);
        // The other school's rows are invisible and untouchable: DutyRota, DutyRotaFamily, DutyRotaAssignment, DutyRotaMail.
        foreach ([$rota, $family, $assignment, $mail] as $row) {
            $model = $row::class;
            $this->assertNull($model::find($row->id));
            $this->assertSame(0, $model::whereKey($row->id)->update(['updated_at' => now()]));
            $this->assertSame(0, $model::whereKey($row->id)->delete());
        }

        // A row created while bound is stamped with the bound school whatever the caller sends.
        $ownYear = SchoolYear::create(['masjid_id' => $a->id, 'label' => 'Practice year', 'first_day' => '2026-09-06', 'last_day' => '2027-06-27']);
        $ownRota = DutyRota::create(['masjid_id' => $b->id, 'school_year_id' => $ownYear->id, 'name' => 'Practice duty']);
        $ownFamily = DutyRotaFamily::create(['masjid_id' => $b->id, 'rota_id' => $ownRota->id, 'identity_hash' => hash('sha256', 'cedar'), 'name' => 'Cedar Family']);
        $ownAssignment = DutyRotaAssignment::create(['masjid_id' => $b->id, 'rota_id' => $ownRota->id, 'family_id' => $ownFamily->id, 'duty_on' => '2026-10-18', 'source' => 'office']);
        $ownMail = DutyRotaMail::create(['masjid_id' => $b->id, 'rota_id' => $ownRota->id, 'family_id' => $ownFamily->id, 'assignment_id' => $ownAssignment->id, 'kind' => 'reminder', 'dedupe_key' => 'practice-a', 'status' => 'queued', 'queued_at' => now()]);
        foreach ([$ownRota, $ownFamily, $ownAssignment, $ownMail] as $row) {
            $this->assertSame($a->id, (int) $row->masjid_id);
        }
        $tenant->forgetTenant();
    }

    private function makeMasjid(string $suffix): Masjid
    {
        return Masjid::create(['name' => 'Practice School '.$suffix, 'email' => 'duty-'.$suffix.'@example.invalid', 'phone' => '+1555555030'.($suffix === 'a' ? '2' : '3'), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    }
}
