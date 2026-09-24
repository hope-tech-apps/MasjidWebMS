<?php

namespace Tests\Feature\Studio;

use App\Models\IqamaTimeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * On the Studio path iqama is displayed only when the client gave times
 * (docs/manara-studio-w1.md S8, DECISIONS "Iqama"). The wizard's invented
 * 20/10/10/5/10 schedule, shown by default, stays on the wizard's path only.
 */
class ProvisionIqamaTruthTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function the_studio_path_hides_iqama_when_the_client_gave_no_times(): void
    {
        $answers = $this->studioAnswers(sections: ['prayer' => ['method' => 'NorthAmerica', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight', 'iqama_given' => false]]);

        $id = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data.masjid_id');

        $this->assertFalse((bool) IqamaTimeSetting::where('masjid_id', $id)->value('show_iqama_times'));
        $this->assertFalse((bool) $this->getJson('/api/v1/settings', ['masjid-id' => (string) $id])->json('data.iqama_settings.show_iqama_times'), 'and the website is told so');
    }

    #[Test]
    public function the_studio_path_shows_the_times_the_client_gave(): void
    {
        $answers = $this->studioAnswers(sections: ['prayer' => ['method' => 'NorthAmerica', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight', 'iqama' => ['fajr' => 25]]]);

        $id = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data.masjid_id');

        $this->assertTrue((bool) IqamaTimeSetting::where('masjid_id', $id)->value('show_iqama_times'));
        $this->assertSame(25, (int) IqamaTimeSetting::where('masjid_id', $id)->value('fajr'));
    }

    #[Test]
    public function the_legacy_path_is_unchanged(): void
    {
        $payload = $this->draftWith($this->studioAnswers(), logo: false)->toProvisionPayload();
        unset($payload['layout_preset'], $payload['capabilities'], $payload['slug'], $payload['description']);
        $this->assertArrayNotHasKey('show_iqama_times', $payload);

        $id = $this->postJson('/api/admin/onboarding/provision', $payload)->assertCreated()->json('data.masjid_id');

        $row = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertTrue((bool) $row->show_iqama_times);
        $this->assertSame([20, 10, 10, 5, 10], [$row->fajr, $row->dhuhr, $row->asr, $row->maghrib, $row->isha]);
    }
}
