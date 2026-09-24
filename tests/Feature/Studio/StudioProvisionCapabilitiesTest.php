<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidMobileAppFeature;
use App\Support\AppFeaturePivot;
use App\Support\CapabilityCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * What a Studio organisation is born with (docs/manara-studio-w1.md S8, R9,
 * R21, R26): Step 1's full map is compared with each key's default at
 * creation, only the departures are stored and ledgered, and the legacy Mobile
 * App Features pivot is derived from the result by id.
 */
class StudioProvisionCapabilitiesTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    private int $superAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->superAdminId = $this->actAsSuperAdmin()->id;
    }

    #[Test]
    public function a_full_map_with_every_key_at_its_default_writes_no_override_and_no_ledger_row(): void
    {
        foreach (Masjid::ORG_TYPES as $orgType) {
            $map = CapabilityCatalogue::resolve($orgType, []);
            $draft = $this->draftWith($this->studioAnswers($orgType, sections: ['features' => ['capabilities' => $map]]));

            $data = $this->provision($draft->id)->assertCreated()->json('data');
            $masjid = Masjid::findOrFail($data['masjid_id']);

            $this->assertNull($masjid->capability_overrides, "{$orgType}: no override");
            $this->assertTrue((bool) $masjid->crm_enabled, "{$orgType}: CRM at its provision default");
            $this->assertFalse((bool) $masjid->assistant_enabled, "{$orgType}: Assistant at its provision default");
            $this->assertSame(0, MasjidCapabilityChange::where('masjid_id', $masjid->id)->count(), "{$orgType}: no ledger row");
            $this->assertSame([], $data['capabilities_applied']['changed']);
            $this->assertEqualsCanonicalizing(array_keys($map), $data['capabilities_applied']['unchanged']);
        }
    }

    #[Test]
    public function departures_are_stored_and_ledgered_with_the_super_admin_as_actor(): void
    {
        // gallery: a module on by default; web_pages: a grant off by default.
        $map = ['gallery' => false, 'web_pages' => true] + CapabilityCatalogue::resolve('masjid', []);
        $draft = $this->draftWith($this->studioAnswers(sections: ['features' => ['capabilities' => $map]]));

        $masjid = Masjid::findOrFail($this->provision($draft->id)->assertCreated()->json('data.masjid_id'));

        $overrides = $masjid->capability_overrides;
        ksort($overrides);
        $this->assertSame(['gallery' => false, 'web_pages' => true], $overrides, 'exactly the two departures');
        $this->assertTrue($masjid->moduleIsOff('gallery'));
        $this->assertTrue($masjid->hasCapability('web_pages'));

        $ledger = MasjidCapabilityChange::where('masjid_id', $masjid->id)->orderBy('capability')->get();
        $this->assertSame(['gallery', 'web_pages'], $ledger->pluck('capability')->all(), 'one row per departure, none for the rest');
        $this->assertSame([$this->superAdminId, $this->superAdminId], $ledger->pluck('actor_user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([[true, false], [false, true]], $ledger->map(fn ($row) => [(bool) $row->enabled_before, (bool) $row->enabled_after])->all());
    }

    #[Test]
    public function crm_chosen_off_is_born_dark_and_says_so_in_the_ledger(): void
    {
        $map = ['crm' => false] + CapabilityCatalogue::resolve('school', []);
        $draft = $this->draftWith($this->studioAnswers('school', sections: ['features' => ['capabilities' => $map]]));

        $masjid = Masjid::findOrFail($this->provision($draft->id)->assertCreated()->json('data.masjid_id'));

        $this->assertFalse((bool) $masjid->crm_enabled);
        $this->assertNull($masjid->capability_overrides, 'a column-backed grant is written to its column, not the overrides');

        $row = MasjidCapabilityChange::where('masjid_id', $masjid->id)->sole();
        $this->assertSame('crm', $row->capability);
        $this->assertTrue((bool) $row->enabled_before, 'born at the provision default, on');
        $this->assertFalse((bool) $row->enabled_after);
        $this->assertSame($this->superAdminId, (int) $row->actor_user_id);
    }

    /**
     * The wizard's `: true` is now `capabilities.crm.provision_default` (S8),
     * the value the catalogue's defaultAtCreation already reports, so what a
     * new org is born with and what the writer compares against cannot drift.
     */
    #[Test]
    public function a_new_organisations_crm_starts_at_the_configured_provision_default(): void
    {
        config(['capabilities.crm.provision_default' => false]);

        $payload = $this->draftWith($this->studioAnswers(), logo: false)->toProvisionPayload();
        unset($payload['layout_preset'], $payload['capabilities']);

        $id = $this->postJson('/api/admin/onboarding/provision', $payload)->assertCreated()->json('data.masjid_id');
        $this->assertFalse((bool) Masjid::findOrFail($id)->crm_enabled, 'the wizard path reads the configured default');

        $studio = $this->provision($this->draftWith($this->studioAnswers())->id)->assertCreated()->json('data');
        $this->assertFalse((bool) Masjid::findOrFail($studio['masjid_id'])->crm_enabled);
        $this->assertNotContains('crm', array_column($studio['capabilities_applied']['changed'], 'key'), 'born at the default, so no departure');
    }

    #[Test]
    public function the_app_drawer_rows_are_derived_from_the_chosen_switches(): void
    {
        $map = ['gallery' => false, 'announcements' => false, 'services' => true] + CapabilityCatalogue::resolve('school', []);
        $draft = $this->draftWith($this->studioAnswers('school', sections: ['features' => ['capabilities' => $map]]));

        $masjid = Masjid::findOrFail($this->provision($draft->id)->assertCreated()->json('data.masjid_id'));
        $pivot = MasjidMobileAppFeature::where('masjid_id', $masjid->id)->orderBy('feature_id')
            ->pluck('is_available', 'feature_id')->map(fn ($v) => (bool) $v)->all();

        $this->assertCount(11, $pivot, 'one row per catalogue feature');
        $this->assertSame(AppFeaturePivot::rowsFor($masjid), $pivot);
        $this->assertFalse($pivot[8], 'Gallery switched off, so the drawer row is off');
        $this->assertFalse($pivot[10], 'Announcements switched off');
        $this->assertTrue($pivot[9], 'Services switched on at a school');
        $this->assertFalse($pivot[6], 'Donate follows the donation and Giving switches (off at a school), not the school bundle, which lists it');
        $this->assertFalse($pivot[1], 'no Qur’an at a school');
    }

    #[Test]
    public function the_derivation_goes_by_id_so_the_production_quran_key_is_found(): void
    {
        $this->assertSame(self::PRODUCTION_QURAN_KEY, \App\Models\MobileAppFeature::findOrFail(1)->key, 'the premise: production\'s spelling');

        $on = $this->draftWith($this->studioAnswers());
        $off = $this->draftWith($this->studioAnswers(sections: ['features' => ['capabilities' => ['quran' => false] + CapabilityCatalogue::resolve('masjid', [])]]));

        $onId = $this->provision($on->id)->assertCreated()->json('data.masjid_id');
        $offId = $this->provision($off->id)->assertCreated()->json('data.masjid_id');

        $this->assertTrue((bool) MasjidMobileAppFeature::where(['masjid_id' => $onId, 'feature_id' => 1])->value('is_available'));
        $this->assertFalse((bool) MasjidMobileAppFeature::where(['masjid_id' => $offId, 'feature_id' => 1])->value('is_available'));
    }

    /**
     * The org's pivot is derived from its switches, so the app-features cutover
     * finds nothing to decide for it: for each vertical, with the default map
     * and every fact the vertical's switches publish. (A fact for a module the
     * switches leave off, such as a school's donation link, is still reported
     * by the command as a blocking decision; ASSUMPTIONS.md #14.)
     */
    #[Test]
    public function a_studio_org_has_no_blocking_cutover_finding(): void
    {
        foreach (Masjid::ORG_TYPES as $orgType) {
            $facts = self::MAXIMAL;

            if (CapabilityCatalogue::defaultAtCreation('donation_link', $orgType) === false) {
                unset($facts['donation_link']);
            }

            $id = $this->provision($this->draftWith($this->studioAnswers($orgType, $facts))->id)->assertCreated()->json('data.masjid_id');

            $exit = Artisan::call('app-features:cutover-plan', ['--org' => (string) $id, '--json' => true]);
            $plan = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exit, "{$orgType}: " . Artisan::output());
            $this->assertSame(0, $plan['blocking'], "{$orgType}: a Studio org needs no cutover decision");
            $this->assertTrue(collect($plan['plan'][0]['rows'])->every(fn (array $row) => $row['agrees'] === true), "{$orgType}: every pivot row agrees with the switches");
        }
    }

    #[Test]
    public function capabilities_cannot_be_sent_with_the_legacy_feature_fields(): void
    {
        $payload = $this->draftWith($this->studioAnswers(), logo: false)->toProvisionPayload();
        unset($payload['layout_preset']);

        foreach ([['feature_keys_provided' => '1', 'feature_keys' => ['gallery']], ['crm_enabled' => false], ['feature_keys' => ['gallery']]] as $legacy) {
            $this->postJson('/api/admin/onboarding/provision', $payload + $legacy)
                ->assertStatus(422)
                ->assertJsonStructure(['data' => ['capabilities']]);
        }

        $this->assertSame(0, Masjid::count());
    }

    #[Test]
    public function a_hidden_or_unknown_key_creates_no_organisation(): void
    {
        foreach (['report_card_core_subjects', 'teleportation'] as $key) {
            $map = [$key => true] + CapabilityCatalogue::resolve('masjid', []);
            $draft = $this->draftWith($this->studioAnswers(sections: ['features' => ['capabilities' => $map]]));

            $this->provision($draft->id)
                ->assertStatus(422)
                ->assertJsonStructure(['data' => ["capabilities.{$key}"]]);
        }

        $this->assertSame('hidden', CapabilityCatalogue::visibility('report_card_core_subjects', config('capabilities.report_card_core_subjects'), 'masjid'), 'the premise: a school feature is hidden from a masjid');
        $this->assertSame(0, Masjid::count());
        $this->assertSame(0, MasjidCapabilityChange::count());
    }

    /**
     * A key with a dot names a path inside the config, not a catalogue key:
     * `web_pages.defaults` is web_pages' defaults array. Read through
     * config("capabilities.{$key}") it passed as a key and the writer, which
     * walks only real keys, dropped it without a word.
     */
    #[Test]
    public function a_dotted_key_that_names_a_nested_config_array_is_refused_not_dropped(): void
    {
        $this->assertIsArray(config('capabilities.web_pages.defaults'), 'the premise: the path resolves to an array');

        $payload = $this->draftWith($this->studioAnswers(), logo: false)->toProvisionPayload();
        unset($payload['layout_preset']);

        foreach (['web_pages.defaults', 'gallery.defaults'] as $key) {
            $this->postJson('/api/admin/onboarding/provision', array_replace($payload, ['capabilities' => [$key => true] + $payload['capabilities']]))
                ->assertStatus(422)
                ->assertJsonFragment(["\"{$key}\" is not offered to this kind of organisation."]);
        }

        $this->assertSame(0, Masjid::count());
    }

    /**
     * Studio always sends `capabilities`, even for a draft with no Step 1 map
     * (a direct POST, or an SPA that no longer stops it): the organisation is
     * born through the switches at their defaults, and the 201 says so. Without
     * the map it would take the wizard's key-matched pivot loop, and the
     * missing `capabilities_applied` would read in the SPA as unconfirmed.
     */
    #[Test]
    public function a_draft_without_a_feature_map_is_born_through_the_switches_at_their_defaults(): void
    {
        $answers = $this->studioAnswers('school');
        unset($answers['features']);

        $data = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data');

        $this->assertSame([], $data['capabilities_applied']['changed']);
        $this->assertEqualsCanonicalizing(array_keys(CapabilityCatalogue::resolve('school', [])), $data['capabilities_applied']['unchanged']);

        $masjid = Masjid::findOrFail($data['masjid_id']);
        $pivot = MasjidMobileAppFeature::where('masjid_id', $masjid->id)->orderBy('feature_id')
            ->pluck('is_available', 'feature_id')->map(fn ($v) => (bool) $v)->all();
        $this->assertSame(AppFeaturePivot::rowsFor($masjid), $pivot);
        // Where the two paths differ: the school bundle lists Donate, the switches leave it off.
        $this->assertFalse($pivot[6], 'Donate follows the switches, not the wizard\'s school bundle');
        $this->assertSame([], $masjid->capability_overrides ?? []);
    }

    #[Test]
    public function the_multipart_strings_true_and_false_are_read_as_booleans(): void
    {
        $payload = $this->draftWith($this->studioAnswers(), logo: false)->toProvisionPayload();
        unset($payload['layout_preset']);
        $payload['capabilities'] = array_map(fn (bool $v) => $v ? 'true' : 'false', ['gallery' => false, 'web_pages' => true] + CapabilityCatalogue::resolve('masjid', []));
        $payload['show_iqama_times'] = 'false';

        // Form-encoded, as the SPA's global axios default posts.
        $this->post('/api/admin/onboarding/provision', $payload, ['Accept' => 'application/json'])->assertCreated();

        $masjid = Masjid::sole();
        $overrides = $masjid->capability_overrides;
        ksort($overrides);
        $this->assertSame(['gallery' => false, 'web_pages' => true], $overrides);
        $this->assertFalse((bool) $masjid->iqamaTimeSettings->show_iqama_times);

        $this->post('/api/admin/onboarding/provision', ['capabilities' => ['gallery' => 'maybe']] + $payload, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['capabilities.gallery']]);
    }

    #[Test]
    public function the_response_echoes_what_was_applied(): void
    {
        $map = ['gallery' => false, 'web_pages' => true, 'crm' => false] + CapabilityCatalogue::resolve('masjid', []);
        $draft = $this->draftWith($this->studioAnswers(sections: ['features' => ['capabilities' => $map]]));

        $applied = $this->provision($draft->id)->assertCreated()->json('data.capabilities_applied');

        $this->assertEqualsCanonicalizing(
            [['key' => 'gallery', 'enabled' => false], ['key' => 'web_pages', 'enabled' => true], ['key' => 'crm', 'enabled' => false]],
            $applied['changed'],
        );
        $this->assertEqualsCanonicalizing(array_diff(array_keys($map), ['gallery', 'web_pages', 'crm']), $applied['unchanged']);
    }
}
