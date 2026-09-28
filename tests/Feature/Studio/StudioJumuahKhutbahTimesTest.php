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
 * iqama times and several Jumu'ah times", redesigned 2026-09-28). NAFIS runs
 * two Jumu'ah. The list is khutbah times: they are stored as the Jumu'ah
 * `athans`, the one list the TV board, the website, the renderer and both phone
 * apps draw, and the phones never fall back to the iqama. The iqama is left
 * NULL (Studio does not ask for it) and `shifts` is never written. A time
 * supplied is never the W2 S18 placeholder; none supplied still is.
 */
class StudioJumuahKhutbahTimesTest extends TestCase
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
    public function two_times_are_the_athans_with_no_iqama_and_no_shifts(): void
    {
        $jumaa = $this->jumaaAfterProvisioning(['jumaa_times' => ['12:30', '13:30']]);

        $this->assertSame(['12:30', '13:30'], $jumaa->athans);
        $this->assertNull($jumaa->getAttributes()['iqama'], 'Studio does not ask for an iqama, and none is invented');
        $this->assertNull($jumaa->getAttributes()['shifts'] ?? null);
        $this->assertFalse($jumaa->isPlaceholder(), 'supplied times are never the W2 S18 placeholder');

        // The admin screen reads back the list it edits.
        $this->getJson("/api/admin/masjids/{$jumaa->masjid_id}/jumaa")
            ->assertOk()
            ->assertJsonPath('data.athans', ['12:30', '13:30']);
    }

    #[Test]
    public function four_times_given_unsorted_are_stored_earliest_first(): void
    {
        $jumaa = $this->jumaaAfterProvisioning(['jumaa_times' => ['13:30', '12:00', '14:15', '12:45']]);

        $this->assertSame(['12:00', '12:45', '13:30', '14:15'], $jumaa->athans);
        $this->assertNull($jumaa->getAttributes()['shifts'] ?? null);
    }

    #[Test]
    public function one_time_is_one_athan_and_no_iqama(): void
    {
        $jumaa = $this->jumaaAfterProvisioning(['jumaa_times' => ['13:10']]);

        $this->assertSame(['13:10'], $jumaa->athans);
        $this->assertNull($jumaa->getAttributes()['iqama']);
        $this->assertFalse($jumaa->isPlaceholder());
        $this->assertNull($jumaa->getAttributes()['shifts'] ?? null);
    }

    #[Test]
    public function with_no_time_the_row_is_the_flagged_placeholder(): void
    {
        $default = $this->jumaaAfterProvisioning([]);

        $this->assertSame('13:30', substr((string) $default->iqama, 0, 5), 'the provisioner\'s default, as before');
        $this->assertSame([], $default->athans);
        $this->assertTrue($default->isPlaceholder(), 'nobody supplied it, so it stays flagged (W2 S18)');
        $this->assertNull($default->getAttributes()['shifts'] ?? null);
    }

    /** An older draft's lone `jumaa_iqama` is what the panel shows as the list's first entry, so it is sent as that. */
    #[Test]
    public function an_older_drafts_lone_jumaa_iqama_is_provisioned_as_the_first_khutbah_time(): void
    {
        $jumaa = $this->jumaaAfterProvisioning(['jumaa_iqama' => '13:15']);

        $this->assertSame(['13:15'], $jumaa->athans);
        $this->assertNull($jumaa->getAttributes()['iqama']);
        $this->assertFalse($jumaa->isPlaceholder());

        // The list, once edited, wins over the older single time.
        $both = $this->jumaaAfterProvisioning(['jumaa_iqama' => '13:15', 'jumaa_times' => ['12:30', '13:30']]);
        $this->assertSame(['12:30', '13:30'], $both->athans);
        $this->assertNull($both->getAttributes()['iqama']);
    }

    /** Khutbah times are not iqama times: "Client has not given iqama times" drops fixed iqama times, not these. */
    #[Test]
    public function the_not_given_tick_does_not_drop_the_khutbah_times(): void
    {
        $jumaa = $this->jumaaAfterProvisioning(['jumaa_times' => ['12:30', '13:30'], 'iqama_given' => false]);

        $this->assertSame(['12:30', '13:30'], $jumaa->athans);
        $this->assertFalse($jumaa->isPlaceholder());
    }

    #[Test]
    public function the_phone_payload_carries_the_athans_and_no_placeholder_flag(): void
    {
        $jumaa = $this->jumaaAfterProvisioning(['jumaa_times' => ['12:30', '13:30']]);

        $data = $this->getJson("/api/mobile/masjids/{$jumaa->masjid_id}/prayers/settings")->assertOk()->json('data');

        $this->assertSame(['12:30', '13:30'], $data['jumaa']['athans']);
        $this->assertNull($data['jumaa']['iqama']);
        $this->assertArrayNotHasKey('jumaa_is_default', $data);
    }

    /** The wizard sends both answers, and they stay two answers: the times are athans, the iqama is the iqama. */
    #[Test]
    public function the_wizard_keeps_the_khutbah_times_and_the_iqama_apart(): void
    {
        $id = $this->postWizard(['jumaa_times' => ['12:30', '13:00'], 'jumaa_iqama' => '13:30'])
            ->assertCreated()
            ->json('data.masjid_id');

        $jumaa = JumaaSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertSame(['12:30', '13:00'], $jumaa->athans);
        $this->assertSame('13:30', substr((string) $jumaa->iqama, 0, 5));
        $this->assertFalse($jumaa->isPlaceholder());
        $this->assertNull($jumaa->getAttributes()['shifts'] ?? null);
    }

    /** The admin screen's own save needs every athan before the iqama, so provisioning must not store a list it would refuse. */
    #[Test]
    public function a_khutbah_time_not_before_the_iqama_is_refused(): void
    {
        foreach ([['13:30'], ['12:30', '14:00']] as $times) {
            $this->postWizard(['jumaa_times' => $times, 'jumaa_iqama' => '13:30'])
                ->assertStatus(422)
                ->assertJsonPath('data', fn ($errors) => collect($errors)->flatten()->contains('Each Jumu\'ah khutbah time must be before the Jumu\'ah iqama.'));
        }

        $this->assertSame(0, Masjid::count());
        $this->assertSame(0, JumaaSetting::count());
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
        $this->assertSame([], $jumaa->athans);
        $this->assertTrue($jumaa->isPlaceholder());
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

    /** A wizard POST: the Studio draft's payload with the wizard's own keys added, as a direct caller would send it. */
    private function postWizard(array $extra): \Illuminate\Testing\TestResponse
    {
        $payload = $this->draftWith($this->studioAnswers(sections: ['prayer' => self::PRAYER]), logo: false)->toProvisionPayload();
        unset($payload['layout_preset'], $payload['capabilities'], $payload['slug'], $payload['description']);

        return $this->postJson('/api/admin/onboarding/provision', $extra + $payload);
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
