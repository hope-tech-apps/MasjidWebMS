<?php

namespace Tests\Feature\Studio;

use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * On the Studio path iqama is displayed only when the client gave times
 * (docs/manara-studio-w1.md S8, DECISIONS "Iqama"). The wizard's invented
 * 20/10/10/5/10 schedule, shown by default, stays on the wizard's path only:
 * a Studio organisation never stores an offset nobody gave as if it were the
 * congregation's, and never shows one.
 */
class ProvisionIqamaTruthTest extends TestCase
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
    public function the_studio_path_hides_iqama_when_the_client_gave_no_times(): void
    {
        $answers = $this->studioAnswers(sections: ['prayer' => self::PRAYER + ['iqama_given' => false]]);

        $id = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data.masjid_id');

        $this->assertFalse((bool) IqamaTimeSetting::where('masjid_id', $id)->value('show_iqama_times'));
        $this->assertFalse((bool) $this->getJson('/api/v1/settings', ['masjid-id' => (string) $id])->json('data.iqama_settings.show_iqama_times'), 'and the website is told so');
    }

    /**
     * The panel's default: nothing typed and the "not given" box unticked.
     * Nothing was given, so nothing is shown, and no offset is invented.
     */
    #[Test]
    public function an_untouched_iqama_panel_hides_iqama_and_invents_no_offsets(): void
    {
        $answers = $this->studioAnswers(sections: ['prayer' => self::PRAYER]);
        $this->assertArrayNotHasKey('iqama_given', $answers['prayer'], 'the premise: the box was never ticked');

        $id = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data.masjid_id');

        $row = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertFalse((bool) $row->show_iqama_times);
        $this->assertSame([0, 0, 0, 0, 0], [$row->fajr, $row->dhuhr, $row->asr, $row->maghrib, $row->isha], 'not the wizard\'s 20/10/10/5/10');
        $this->assertFalse((bool) $this->getJson('/api/v1/settings', ['masjid-id' => (string) $id])->json('data.iqama_settings.show_iqama_times'));
    }

    /**
     * Only Fajr typed: showing would publish four times nobody gave, and
     * hiding would drop the one they did. The draft is refused, naming what
     * is missing, and nothing is created.
     */
    #[Test]
    public function some_offsets_without_the_rest_are_refused_by_name(): void
    {
        $answers = $this->studioAnswers(sections: ['prayer' => self::PRAYER + ['iqama' => ['fajr' => 25, 'dhuhr' => null]]]);

        $this->provision($this->draftWith($answers)->id)
            ->assertStatus(422)
            ->assertJsonPath('data.iqama.0', 'The iqama times are incomplete: Dhuhr, Asr, Maghrib, Isha are missing. Enter all five in Foundation, or tick "Client has not given iqama times".');

        $this->assertSame(0, Masjid::count());

        // Ticked, the same offsets provision with iqama hidden.
        $ticked = $this->studioAnswers(sections: ['prayer' => self::PRAYER + ['iqama' => ['fajr' => 25], 'iqama_given' => false]]);
        $id = $this->provision($this->draftWith($ticked)->id)->assertCreated()->json('data.masjid_id');
        $this->assertFalse((bool) IqamaTimeSetting::where('masjid_id', $id)->value('show_iqama_times'));
    }

    #[Test]
    public function the_studio_path_shows_the_times_the_client_gave(): void
    {
        $given = ['fajr' => 25, 'dhuhr' => 0, 'asr' => 12, 'maghrib' => 7, 'isha' => 15];
        $answers = $this->studioAnswers(sections: ['prayer' => self::PRAYER + ['iqama' => $given]]);

        $id = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data.masjid_id');

        $row = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertTrue((bool) $row->show_iqama_times);
        $this->assertSame(array_values($given), [$row->fajr, $row->dhuhr, $row->asr, $row->maghrib, $row->isha]);
    }

    /** A school is never asked for iqama; offsets left from when the draft was a masjid do not show. */
    #[Test]
    public function an_organisation_that_is_not_a_masjid_never_shows_iqama(): void
    {
        $answers = $this->studioAnswers('school', sections: ['prayer' => self::PRAYER + ['iqama' => ['fajr' => 25]]]);

        $id = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data.masjid_id');

        $this->assertFalse((bool) IqamaTimeSetting::where('masjid_id', $id)->value('show_iqama_times'));
    }

    #[Test]
    public function the_legacy_path_is_unchanged(): void
    {
        $payload = $this->draftWith($this->studioAnswers(), logo: false)->toProvisionPayload();
        // The wizard sends none of Studio's keys.
        unset($payload['layout_preset'], $payload['capabilities'], $payload['slug'], $payload['description'], $payload['show_iqama_times']);

        $id = $this->postJson('/api/admin/onboarding/provision', $payload)->assertCreated()->json('data.masjid_id');

        $row = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertTrue((bool) $row->show_iqama_times);
        $this->assertSame([20, 10, 10, 5, 10], [$row->fajr, $row->dhuhr, $row->asr, $row->maghrib, $row->isha]);

        // With only some offsets the wizard still fills the rest, as it always did.
        $payload = $this->draftWith($this->studioAnswers(sections: ['prayer' => self::PRAYER + ['iqama' => ['fajr' => 25]]]), logo: false)->toProvisionPayload();
        unset($payload['layout_preset'], $payload['capabilities'], $payload['slug'], $payload['description'], $payload['show_iqama_times']);

        $id = $this->postJson('/api/admin/onboarding/provision', $payload)->assertCreated()->json('data.masjid_id');

        $row = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertTrue((bool) $row->show_iqama_times);
        $this->assertSame([25, 10, 10, 5, 10], [$row->fajr, $row->dhuhr, $row->asr, $row->maghrib, $row->isha]);
    }
}
