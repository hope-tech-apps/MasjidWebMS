<?php

namespace Tests\Feature\Studio;

use App\Models\IqamaTimeSetting;
use App\Models\JumaaSetting;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * W2 S18: a Jumu'ah time nobody supplied is hidden by the TV board and both
 * phone apps. Provisioning still stores its 13:30 placeholder (W1 S8), now
 * flagged `jumaa_settings.is_default = true`, and /prayers/settings says so
 * with `jumaa_is_default: true`, sent ONLY when it is true.
 *
 * The live organisations' rows predate the column (NULL, no backfill), so their
 * payloads must not change by a byte. The key lists below are production's, read
 * from /api/mobile/masjids/{1,5,13,14,18}/prayers/settings on 2026-09-27 before
 * this column existed: every one of the five answered exactly these keys.
 */
class JumaaIsDefaultTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    private const DATA_KEYS = ['calculation', 'iqama', 'jumaa', 'masjid'];

    private const JUMAA_KEYS = ['athans', 'created_at', 'id', 'iqama', 'masjid_id', 'shifts', 'updated_at'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
    }

    private function organisation(?bool $isDefault): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Jumuah Org ' . uniqid(),
            'email' => 'jumuah-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => (string) $this->countryId,
            'city_id' => (string) $this->cityId,
            'address' => '1 Test St',
            'latitude' => 43.3255,
            'longitude' => -79.799,
            'timezone' => 'America/Toronto',
        ]);

        $row = ['masjid_id' => $masjid->id, 'iqama' => '13:30', 'athans' => []];
        if ($isDefault !== null) {
            $row['is_default'] = $isDefault;
        }
        JumaaSetting::create($row);

        return $masjid;
    }

    private function settings(int $masjidId): array
    {
        return $this->getJson("/api/mobile/masjids/{$masjidId}/prayers/settings")->assertOk()->json('data');
    }

    /** Provisions the draft and returns the new masjid id, naming the refusal if there is one. */
    private function provisioned(int $draftId): int
    {
        $response = $this->provision($draftId);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        return (int) $response->json('data.masjid_id');
    }

    private static function keys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    #[Test]
    public function a_row_that_predates_the_flag_keeps_exactly_the_live_keys(): void
    {
        $masjid = $this->organisation(null);

        $response = $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers/settings")->assertOk();

        $this->assertSame(self::DATA_KEYS, self::keys($response->json('data')));
        $this->assertSame(self::JUMAA_KEYS, self::keys($response->json('data.jumaa')));
        $this->assertStringNotContainsString('is_default', $response->getContent());
    }

    #[Test]
    public function a_supplied_time_sends_no_flag_either(): void
    {
        $masjid = $this->organisation(false);

        $data = $this->settings($masjid->id);

        $this->assertSame(self::DATA_KEYS, self::keys($data));
        $this->assertSame(self::JUMAA_KEYS, self::keys($data['jumaa']));
    }

    #[Test]
    public function the_placeholder_adds_one_true_key_and_changes_nothing_else(): void
    {
        $flagged = $this->organisation(true);
        $plain = $this->organisation(null);

        $data = $this->settings($flagged->id);

        $expected = [...self::DATA_KEYS, 'jumaa_is_default'];
        sort($expected);
        $this->assertSame($expected, self::keys($data));
        $this->assertTrue($data['jumaa_is_default'], 'a JSON true, not 1 or "1": both apps decode a boolean');
        $this->assertSame(self::JUMAA_KEYS, self::keys($data['jumaa']), 'the jumaa object itself is untouched');
        $this->assertSame($this->settings($plain->id)['jumaa']['iqama'], $data['jumaa']['iqama'],
            'the stored time is still sent as before; the flag says not to show it');
    }

    #[Test]
    public function provisioning_without_a_jumuah_time_flags_the_placeholder(): void
    {
        $this->actAsSuperAdmin();

        $id = $this->provisioned($this->draftWith($this->studioAnswers())->id);

        $row = JumaaSetting::where('masjid_id', $id)->sole();
        $this->assertSame('13:30', substr((string) $row->iqama, 0, 5));
        $this->assertTrue($row->is_default);
        $this->assertTrue($this->settings($id)['jumaa_is_default']);
    }

    #[Test]
    public function provisioning_with_a_jumuah_time_does_not(): void
    {
        $this->actAsSuperAdmin();
        $answers = $this->studioAnswers(sections: ['prayer' => [
            'method' => 'NorthAmerica', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
            'jumaa_times' => ['13:15'],
        ]]);

        $id = $this->provisioned($this->draftWith($answers)->id);

        // Studio's list is khutbah times: stored as the athans the phones draw.
        $row = JumaaSetting::where('masjid_id', $id)->sole();
        $this->assertSame(['13:15'], $row->athans);
        $this->assertFalse($row->is_default);
        $data = $this->settings($id);
        $this->assertArrayNotHasKey('jumaa_is_default', $data);
        $this->assertSame(['13:15'], $data['jumaa']['athans']);
    }

    /**
     * The admin Jumu'ah screen (JumaaSettingsView.vue) has had no iqama field
     * since 1c92bbb5: it posts athans and shifts only. Saving khutbah times on
     * the placeholder supplies Jumu'ah, but the invented 13:30 iqama must not
     * ride along as a time someone gave: the board would draw "Iqama 1:30 PM"
     * and count down to it.
     */
    #[Test]
    public function saving_khutbah_times_on_the_placeholder_supplies_them_and_drops_the_invented_iqama(): void
    {
        $this->actAsSuperAdmin();
        $masjid = $this->organisation(true);
        $this->assertTrue($this->settings($masjid->id)['jumaa_is_default'], 'cached with the flag first');

        $this->postJson("/api/admin/masjids/{$masjid->id}/jumaa", ['athans' => ['14:00']])->assertOk();

        $row = JumaaSetting::where('masjid_id', $masjid->id)->sole();
        $this->assertFalse($row->is_default);
        $this->assertNull($row->iqama, 'the placeholder 13:30 is dropped, not promoted');
        $data = $this->settings($masjid->id);
        $this->assertArrayNotHasKey('jumaa_is_default', $data, 'the save flushed the cached payload');
        $this->assertSame(['14:00'], $data['jumaa']['athans']);
        $this->assertNull($data['jumaa']['iqama']);
        $this->assertSame(self::JUMAA_KEYS, self::keys($data['jumaa']));
    }

    #[Test]
    public function saving_shifts_only_on_the_placeholder_supplies_them_and_drops_the_invented_iqama(): void
    {
        $this->actAsSuperAdmin();
        $masjid = $this->organisation(true);

        $this->postJson("/api/admin/masjids/{$masjid->id}/jumaa", [
            'shifts' => [['time' => '13:00', 'khateeb_name' => 'Sh. Test', 'khateeb_title' => '', 'khutbah_title' => '']],
        ])->assertOk();

        $row = JumaaSetting::where('masjid_id', $masjid->id)->sole();
        $this->assertFalse($row->is_default);
        $this->assertNull($row->iqama);
        $this->assertSame('13:00', $this->settings($masjid->id)['jumaa']['shifts'][0]['time']);
    }

    /** A save that gives no time at all leaves the placeholder a placeholder. */
    #[Test]
    public function saving_nothing_on_the_placeholder_keeps_it_flagged(): void
    {
        $this->actAsSuperAdmin();
        $masjid = $this->organisation(true);

        $this->postJson("/api/admin/masjids/{$masjid->id}/jumaa", [])->assertOk();

        $row = JumaaSetting::where('masjid_id', $masjid->id)->sole();
        $this->assertTrue($row->is_default);
        $this->assertTrue($this->settings($masjid->id)['jumaa_is_default']);
    }

    /** An iqama in the request (the API accepts one) is a time someone gave. */
    #[Test]
    public function an_iqama_in_the_request_is_supplied(): void
    {
        $this->actAsSuperAdmin();
        $masjid = $this->organisation(true);

        $this->postJson("/api/admin/masjids/{$masjid->id}/jumaa", ['iqama' => '13:20', 'athans' => ['13:00']])->assertOk();

        $row = JumaaSetting::where('masjid_id', $masjid->id)->sole();
        $this->assertFalse($row->is_default);
        $this->assertSame('13:20', substr((string) $row->iqama, 0, 5));
        $this->assertArrayNotHasKey('jumaa_is_default', $this->settings($masjid->id));
    }

    /**
     * Every live organisation's row predates the flag. Its admins save athans
     * and shifts every week: the stored iqama must survive the save exactly as
     * before S18, and nothing about the flag may appear.
     */
    #[Test]
    public function a_live_rows_admin_save_keeps_its_iqama_and_its_bytes(): void
    {
        $this->actAsSuperAdmin();
        $masjid = $this->organisation(null);
        JumaaSetting::where('masjid_id', $masjid->id)->update(['iqama' => '13:20:00']);

        $this->postJson("/api/admin/masjids/{$masjid->id}/jumaa", ['athans' => ['13:00', '14:00']])->assertOk();

        $row = JumaaSetting::where('masjid_id', $masjid->id)->sole();
        $this->assertSame('13:20', substr((string) $row->iqama, 0, 5));
        $this->assertNotTrue($row->is_default);
        $data = $this->settings($masjid->id);
        $this->assertSame(self::DATA_KEYS, self::keys($data));
        $this->assertSame(self::JUMAA_KEYS, self::keys($data['jumaa']));
    }

    /**
     * Studio's "client has not given iqama times" tick is about iqama times;
     * the Jumu'ah list is khutbah times and sits outside it (DECISIONS
     * 2026-09-27 Studio Step 0, 2026-09-28 addendum). So a time typed before
     * the tick is supplied: it is stored as the Jumu'ah athans every screen
     * draws, never the flagged placeholder, while the daily iqama stays hidden.
     * This replaces W2 S18's "the tick wins", written when the field was the
     * greyed-out "Jumu'ah iqama".
     */
    #[Test]
    public function the_studio_tick_leaves_a_jumuah_time_typed_before_it_supplied(): void
    {
        $this->actAsSuperAdmin();
        $answers = $this->studioAnswers(sections: ['prayer' => [
            'method' => 'NorthAmerica', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight',
            'iqama_type' => 'minutes_after_adhan', 'iqama' => ['fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 5, 'isha' => 10],
            'iqama_given' => false, 'jumaa_iqama' => '13:15',
        ]]);

        $id = $this->provisioned($this->draftWith($answers)->id);

        $row = JumaaSetting::where('masjid_id', $id)->sole();
        $this->assertFalse($row->is_default);
        $this->assertSame(['13:15'], $row->athans);
        $this->assertNull($row->getAttributes()['iqama'], 'a khutbah time is not an iqama');
        $this->assertNull($row->getAttributes()['shifts'] ?? null);

        $data = $this->settings($id);
        $this->assertArrayNotHasKey('jumaa_is_default', $data);
        $this->assertSame(['13:15'], $data['jumaa']['athans']);

        $this->assertFalse((bool) IqamaTimeSetting::where('masjid_id', $id)->value('show_iqama_times'), 'the tick still hides the daily iqama times');
    }

    #[Test]
    public function the_flag_never_rides_the_rows_other_serializations(): void
    {
        $this->actAsSuperAdmin();
        $masjid = $this->organisation(true);

        $admin = $this->getJson("/api/admin/masjids/{$masjid->id}/jumaa")->assertOk();
        $this->assertSame(self::JUMAA_KEYS, self::keys($admin->json('data')), 'the admin Jumu\'ah screen reads the row unchanged');

        $website = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $masjid->id])->assertOk();
        $this->assertStringNotContainsString('is_default', $website->getContent());

        // PrayersController::store writes every Friday's `prayers.jumaa_data`
        // as json_encode() of this row, so that encoding is what must stay clean.
        $this->assertStringNotContainsString('is_default', json_encode(JumaaSetting::where('masjid_id', $masjid->id)->sole()));
    }

    #[Test]
    public function the_flag_is_cached_like_the_rest_of_the_payload(): void
    {
        $masjid = $this->organisation(true);
        $this->settings($masjid->id);

        $cached = Cache::get(MobileCache::masjidKey($masjid->id, MobileCache::PRAYERS_SETTINGS));

        $this->assertTrue($cached['jumaa_is_default']);
    }
}
