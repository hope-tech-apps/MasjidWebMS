<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\User;
use App\Support\CapabilityWriter;
use App\Support\SchoolSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClassSubjectSwitchesTogetherTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->operator = User::factory()->create([
            'type' => 'SuperAdmin', 'name' => 'Practice Operator',
            'email' => 'operator@example.invalid', 'phone' => '+15555550101',
        ]);
        $this->school = Masjid::create([
            'name' => 'Practice School', 'email' => 'switches@example.invalid',
            'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1',
            'address' => 'Practice', 'latitude' => 0, 'longitude' => 0,
            'org_type' => 'school', 'crm_enabled' => true,
        ]);
        $this->school->forceFill(['capability_overrides' => [
            'class_subjects' => true, 'class_subject_work' => true,
        ]])->save();
        Sanctum::actingAs($this->operator);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forgetTenant();
        parent::tearDown();
    }

    private function assertSwitches(bool $sharing, bool $summary): void
    {
        $school = $this->school->fresh();
        $this->assertSame($sharing, SchoolSettings::classSubjectSharing($school));
        $this->assertSame($summary, SchoolSettings::classSubjectReportSummary($school));
        $map = $school->capabilities;
        $attributes = $school->attributesToArray();
        $panel = $this->getJson("/api/admin/masjids/{$school->id}/capabilities")->assertOk()->json('data');
        $entries = collect($panel['groups'])->flatMap(fn ($group) => $group['entries'])->keyBy('key');
        $history = collect($panel['history'])->pluck('capability');
        foreach (['class_subject_sharing' => $sharing, 'class_subject_report_summary' => $summary] as $key => $enabled) {
            if ($enabled) {
                $this->assertTrue($map[$key]);
                $this->assertTrue($attributes['capability_overrides'][$key]);
                $this->assertTrue($entries[$key]['enabled']);
                $this->assertTrue($history->contains($key));
            } else {
                $this->assertArrayNotHasKey($key, $map);
                $this->assertArrayNotHasKey($key, $attributes['capability_overrides']);
                $this->assertFalse($entries->has($key));
                $this->assertFalse($history->contains($key));
            }
        }
        // Studio offers capabilities for new organisations, so both grants stay hidden there.
        $studio = $this->getJson('/api/admin/studio/catalogue?org_type=school')->assertOk();
        $studio->assertDontSee('class_subject_sharing')->assertDontSee('class_subject_report_summary');
    }

    public static function enabledCombinations(): array
    {
        return ['sharing alone' => [true, false], 'summary alone' => [false, true], 'both together' => [true, true]];
    }

    #[Test]
    #[DataProvider('enabledCombinations')]
    public function each_switch_is_visible_only_while_on_independently_of_its_sibling(bool $sharing, bool $summary): void
    {
        $this->assertSwitches(false, false);
        CapabilityWriter::apply($this->school, [
            'class_subject_sharing' => $sharing, 'class_subject_report_summary' => $summary,
        ], $this->operator->id);
        $this->assertSwitches($sharing, $summary);
    }

    public static function switchesToDisable(): array
    {
        return ['sharing' => ['class_subject_sharing'], 'summary' => ['class_subject_report_summary']];
    }

    #[Test]
    #[DataProvider('switchesToDisable')]
    public function switching_one_off_leaves_the_other_on(string $key): void
    {
        CapabilityWriter::apply($this->school, [
            'class_subject_sharing' => true, 'class_subject_report_summary' => true,
        ], $this->operator->id);
        $this->assertSwitches(true, true);
        // Deliberately reuse the original model: the writer must retain the sibling's stored value.
        CapabilityWriter::apply($this->school, [$key => false], $this->operator->id);
        $this->assertSwitches($key !== 'class_subject_sharing', $key !== 'class_subject_report_summary');
    }

    public static function refusedEnables(): array
    {
        $cases = [];
        foreach (['sharing' => ['class_subject_sharing' => true], 'summary' => ['class_subject_report_summary' => true], 'both' => ['class_subject_sharing' => true, 'class_subject_report_summary' => true]] as $name => $changes) {
            $cases[$name.' with work already off'] = [$changes, false];
            $cases[$name.' while switching work off'] = [$changes, true];
        }
        return $cases;
    }

    #[Test]
    #[DataProvider('refusedEnables')]
    public function subject_work_is_required_and_refusal_writes_nothing(array $changes, bool $switchWorkOff): void
    {
        if (! $switchWorkOff) {
            CapabilityWriter::apply($this->school, ['class_subject_work' => false], $this->operator->id);
        } else {
            $changes['class_subject_work'] = false;
        }
        $before = $this->school->fresh()->capability_overrides;
        $ledgerCount = MasjidCapabilityChange::count();
        try {
            CapabilityWriter::apply($this->school, $changes, $this->operator->id);
            $this->fail('Enabling a dependent switch without subject work must be refused.');
        } catch (ValidationException $e) {
            $message = isset($changes['class_subject_sharing'])
                ? 'Switch on class subjects and subject notes and marks before enabling family sharing.'
                : 'Switch on subject notes and marks before enabling report-card subject summaries.';
            $this->assertSame(['capability' => [$message]], $e->errors());
        }
        $this->assertSame($before, $this->school->fresh()->capability_overrides);
        $this->assertSame($ledgerCount, MasjidCapabilityChange::count());
        $this->assertSwitches(false, false);
    }
}
