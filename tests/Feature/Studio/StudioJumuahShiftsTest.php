<?php

namespace Tests\Feature\Studio;

use App\Models\JumaaSetting;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * Studio Step 0's Jumu'ah list (DECISIONS, 2026-09-27 "Studio Step 0: fixed
 * iqama times and several Jumu'ah times"). NAFIS runs two Jumu'ah. The first
 * time is the jumaa iqama; two or more are also written as `shifts` in the
 * shape the admin screen stores (JumaaSettingsController::save), so the
 * organisation opens its Jumu'ah settings to both, with the khateeb and
 * khutbah left for it to fill. One time, or none, writes exactly the row the
 * single `jumaa_iqama` always did.
 */
class StudioJumuahShiftsTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    private const PRAYER = ['method' => 'NorthAmerica', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function two_times_make_the_first_the_iqama_and_both_the_shifts(): void
    {
        // An older draft's single time is replaced by the list, not kept beside it.
        $jumaa = $this->jumaaAfterProvisioning(['jumaa_times' => ['12:30', '13:30'], 'jumaa_iqama' => '13:15']);

        $this->assertSame('12:30', substr((string) $jumaa->iqama, 0, 5));
        $this->assertFalse($jumaa->isPlaceholder(), 'supplied times are never the W2 S18 placeholder');
        $this->assertSame([
            ['time' => '12:30', 'khateeb_name' => null, 'khateeb_title' => null, 'khutbah_title' => null],
            ['time' => '13:30', 'khateeb_name' => null, 'khateeb_title' => null, 'khutbah_title' => null],
        ], $jumaa->shifts);
        $this->assertSame([], $jumaa->athans);

        // The admin screen reads back exactly what it would have saved itself.
        $this->getJson("/api/admin/masjids/{$jumaa->masjid_id}/jumaa")
            ->assertOk()
            ->assertJsonPath('data.shifts.1.time', '13:30')
            ->assertJsonPath('data.shifts.1.khateeb_name', null);
    }

    #[Test]
    public function four_times_are_all_shifts_in_the_order_given(): void
    {
        $times = ['12:00', '12:45', '13:30', '14:15'];

        $jumaa = $this->jumaaAfterProvisioning(['jumaa_times' => $times]);

        $this->assertSame('12:00', substr((string) $jumaa->iqama, 0, 5));
        $this->assertSame($times, array_column($jumaa->shifts, 'time'));
    }

    #[Test]
    public function one_time_is_the_iqama_and_writes_no_shifts(): void
    {
        $jumaa = $this->jumaaAfterProvisioning(['jumaa_times' => ['13:10']]);

        $this->assertSame('13:10', substr((string) $jumaa->iqama, 0, 5));
        $this->assertFalse($jumaa->isPlaceholder());
        $this->assertNull($jumaa->getAttributes()['shifts'] ?? null);
        $this->assertNull($jumaa->shifts);
        $this->assertSame([], $jumaa->athans);
    }

    #[Test]
    public function with_no_list_the_row_is_todays(): void
    {
        $default = $this->jumaaAfterProvisioning([]);
        $this->assertSame('13:30', substr((string) $default->iqama, 0, 5), 'the provisioner\'s default, as before');
        $this->assertTrue($default->isPlaceholder(), 'nobody supplied it, so it stays flagged (W2 S18)');
        $this->assertNull($default->getAttributes()['shifts'] ?? null);

        $legacy = $this->jumaaAfterProvisioning(['jumaa_iqama' => '13:15']);
        $this->assertSame('13:15', substr((string) $legacy->iqama, 0, 5), 'an older draft\'s single time');
        $this->assertFalse($legacy->isPlaceholder());
        $this->assertNull($legacy->getAttributes()['shifts'] ?? null);
    }

    /**
     * The draft's own rules stop a fifth time at autosave, so these drafts are
     * made with the model: provisioning must refuse them on its own.
     */
    #[Test]
    public function five_times_or_a_time_twice_are_refused(): void
    {
        $this->provisionJumaa(['12:00', '12:30', '13:00', '13:30', '14:00'])
            ->assertStatus(422)
            ->assertJsonPath('data.jumaa_times.0', 'Enter at most four Jumu\'ah times.');

        $this->provisionJumaa(['12:30', '12:30'])
            ->assertStatus(422)
            ->assertJsonPath('data', fn ($errors) => ($errors['jumaa_times.1'][0] ?? null) === 'The Jumu\'ah times must all be different.');

        $this->provisionJumaa(['12:30', '1:30 PM'])
            ->assertStatus(422)
            ->assertJsonPath('data', fn ($errors) => isset($errors['jumaa_times.1']));

        $this->assertSame(0, Masjid::count());
    }

    /** The list is the Prayer panel's, which a school never sees: one left from when it was a masjid is not sent. */
    #[Test]
    public function a_school_is_not_held_to_a_jumuah_list_it_cannot_see(): void
    {
        $answers = $this->studioAnswers('school', sections: ['prayer' => self::PRAYER + ['jumaa_times' => ['12:30', '12:30']]]);

        $id = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data.masjid_id');

        $jumaa = JumaaSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertSame('13:30', substr((string) $jumaa->iqama, 0, 5));
        $this->assertNull($jumaa->getAttributes()['shifts'] ?? null);
    }

    #[Test]
    public function the_draft_holds_up_to_four_times(): void
    {
        $draft = $this->newDraft(['org_type' => 'masjid']);

        $this->patchDraft($draft['id'], $draft['lock_version'], ['prayer' => ['jumaa_times' => ['12:30', '13:30']]])
            ->assertOk()
            ->assertJsonPath('data.answers.prayer.jumaa_times', ['12:30', '13:30']);

        $lock = $draft['lock_version'] + 1;

        $this->patchDraft($draft['id'], $lock, ['prayer' => ['jumaa_times' => ['12:00', '12:30', '13:00', '13:30', '14:00']]])
            ->assertStatus(422)
            ->assertJsonPath('data', fn ($errors) => isset($errors['answers.prayer.jumaa_times']));

        $this->patchDraft($draft['id'], $lock, ['prayer' => ['jumaa_times' => ['12:30', '']]])
            ->assertStatus(422)
            ->assertJsonPath('data', fn ($errors) => isset($errors['answers.prayer.jumaa_times.1']));

        // A time twice is saved (it may be half-way through being changed); provisioning refuses it.
        $this->patchDraft($draft['id'], $lock, ['prayer' => ['jumaa_times' => ['12:30', '12:30']]])->assertOk();
    }

    private function provisionJumaa(array $times): \Illuminate\Testing\TestResponse
    {
        return $this->provision($this->draftWith($this->studioAnswers(sections: ['prayer' => self::PRAYER + ['jumaa_times' => $times]]))->id);
    }

    private function jumaaAfterProvisioning(array $prayer): JumaaSetting
    {
        $id = $this->provision($this->draftWith($this->studioAnswers(sections: ['prayer' => self::PRAYER + $prayer]))->id)
            ->assertCreated()
            ->json('data.masjid_id');

        return JumaaSetting::where('masjid_id', $id)->firstOrFail();
    }
}
