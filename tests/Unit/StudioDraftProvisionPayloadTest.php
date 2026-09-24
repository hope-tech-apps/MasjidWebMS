<?php

namespace Tests\Unit;

use App\Models\StudioDraft;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StudioDraft::toProvisionPayload(): the draft flattened into
 * ProvisionMasjidRequest's keys for Step 3 (docs/manara-studio-w1.md S2, R7,
 * R12). Nothing calls it before S3, so these are the only thing that notices a
 * change to what it sends.
 */
class StudioDraftProvisionPayloadTest extends TestCase
{
    private const COLOURS = [
        'primary_color' => '#01B151',
        'secondary_color' => '#1B1B2E',
        'accent_color' => '#FFBA63',
        'background_color' => '#F3F8FB',
    ];

    private const SECRETS = [
        'ios' => ['asc_key_p8' => '-----BEGIN PRIVATE KEY-----P8', 'asc_key_id' => 'KEYID', 'asc_issuer_id' => 'ISSUER'],
        'android' => ['play_service_account_json' => '{"private_key":"PLAY"}'],
    ];

    private function draft(array $answers): StudioDraft
    {
        return new StudioDraft(['answers' => $answers]);
    }

    #[Test]
    public function credentials_typed_for_a_managed_platform_are_discarded(): void
    {
        $payload = $this->draft([
            'platforms' => [
                'platforms' => ['ios', 'android'],
                'apps' => ['ios' => ['account_mode' => 'managed'], 'android' => ['account_mode' => 'byo']],
            ],
        ])->toProvisionPayload(self::SECRETS);

        $this->assertSame(['account_mode' => 'managed'], $payload['apps']['ios'], 'Hope Tech\'s account; what was typed goes nowhere');
        $this->assertSame(
            ['account_mode' => 'byo', 'play_service_account_json' => '{"private_key":"PLAY"}'],
            $payload['apps']['android'],
        );
        $this->assertSame(['ios', 'android'], $payload['platforms']);

        // The other way round, and a platform with no account mode chosen.
        $payload = $this->draft([
            'platforms' => ['platforms' => ['ios', 'android'], 'apps' => ['ios' => ['account_mode' => 'byo'], 'android' => []]],
        ])->toProvisionPayload(self::SECRETS);

        $this->assertSame(['account_mode' => 'byo'] + self::SECRETS['ios'], $payload['apps']['ios']);
        $this->assertArrayNotHasKey('android', $payload['apps'], 'no mode chosen, so no credentials either');
    }

    /**
     * The Platforms panel keeps a platform's mode when it is unticked. Sent,
     * a leftover `byo` would make the request demand credentials Step 3 has
     * no field for (`required_if:apps.ios.account_mode,byo`).
     */
    #[Test]
    public function an_account_mode_is_sent_only_for_a_platform_still_selected(): void
    {
        $payload = $this->draft([
            'platforms' => [
                'platforms' => ['android', 'web'],
                'apps' => ['ios' => ['account_mode' => 'byo'], 'android' => ['account_mode' => 'managed'], 'web' => ['account_mode' => 'managed']],
            ],
        ])->toProvisionPayload(self::SECRETS);

        $this->assertSame(['android' => ['account_mode' => 'managed'], 'web' => ['account_mode' => 'managed']], $payload['apps']);

        $none = $this->draft(['platforms' => ['platforms' => ['web'], 'apps' => ['ios' => ['account_mode' => 'byo']]]])->toProvisionPayload(self::SECRETS);
        $this->assertArrayNotHasKey('apps', $none, 'nothing selected has a mode, so no apps key at all');
    }

    #[Test]
    public function only_keys_the_draft_set_are_sent_and_studios_own_stay_behind(): void
    {
        $payload = $this->draft([
            'identity' => [
                'org_type' => 'masjid', 'name' => 'Masjid An-Nur', 'email' => 'office@annur.test',
                'admin' => ['name' => 'Imran Ali'],
                'slug' => 'annur', 'description' => 'A masjid in Burlington.', 'vibe' => 'Warm, not corporate.',
            ],
            'prayer' => ['method' => 'ISNA', 'iqama' => ['fajr' => 20], 'iqama_given' => true],
            'brand' => self::COLOURS + ['extracted' => ['#000000'], 'ink_overrides' => ['onPrimary' => '#000000']],
            'content' => ['about' => 'About us.'],
            'features' => ['capabilities' => ['giving' => true]],
            'layout' => ['preset' => 'classic'],
            'domain' => ['custom' => ['host' => 'annur.test']],
        ])->toProvisionPayload();

        $this->assertSame('masjid', $payload['org_type']);
        $this->assertSame('Masjid An-Nur', $payload['name']);
        $this->assertSame(['name' => 'Imran Ali'], $payload['admin']);
        $this->assertSame(['fajr' => 20], $payload['iqama']);
        $this->assertSame('About us.', $payload['about']);
        $this->assertSame(self::COLOURS, $payload['brand'], 'the four colours and nothing else of the brand section');

        // Never set: omitted, not null, so the request's own rules name them.
        foreach (['phone', 'address', 'timezone', 'madhab', 'mission'] as $unset) {
            $this->assertArrayNotHasKey($unset, $payload, $unset);
        }

        // S8's request keys: the slug and the client's description as they
        // are, whether iqama is shown, the Step 1 map as capabilities.
        $this->assertSame('annur', $payload['slug']);
        $this->assertSame('A masjid in Burlington.', $payload['description']);
        $this->assertTrue($payload['show_iqama_times']);
        $this->assertSame(['giving' => true], $payload['capabilities']);

        // Studio's own never leave the draft (R12), and without web selected
        // neither the starter website nor the client's domain is asked for.
        foreach (['vibe', 'iqama_given', 'extracted', 'ink_overrides', 'features', 'preset', 'layout', 'layout_preset', 'custom', 'domain', 'web_domain', 'apps'] as $kept) {
            $this->assertArrayNotHasKey($kept, $payload, $kept);
        }
    }

    #[Test]
    public function the_web_deliverable_is_asked_for_only_with_web_selected(): void
    {
        $answers = [
            'layout' => ['preset' => 'masjid.classic', 'approved_at' => '2026-09-24T10:00:00Z'],
            'domain' => ['custom' => ['host' => 'www.annur.test', 'zone_apex' => 'annur.test']],
        ];

        $withWeb = $this->draft($answers + ['platforms' => ['platforms' => ['ios', 'web']]])->toProvisionPayload();
        $this->assertSame('masjid.classic', $withWeb['layout_preset']);
        $this->assertSame(['custom_host' => 'www.annur.test', 'custom_zone_apex' => 'annur.test'], $withWeb['web_domain']);

        $withoutWeb = $this->draft($answers + ['platforms' => ['platforms' => ['ios']]])->toProvisionPayload();
        $this->assertArrayNotHasKey('layout_preset', $withoutWeb);
        $this->assertArrayNotHasKey('web_domain', $withoutWeb);
    }

    /**
     * DECISIONS, S8 "Iqama": shown only for a masjid whose client gave times.
     * Always sent, because the request's absent value is the wizard's `true`
     * with its invented 20/10/10/5/10. Some offsets without the rest is sent as
     * shown, so the request names the missing ones instead of hiding the
     * times the client gave.
     */
    #[Test]
    public function iqama_is_shown_only_for_a_masjid_whose_client_gave_times(): void
    {
        $five = ['fajr' => 20, 'dhuhr' => 0, 'asr' => 10, 'maghrib' => 5, 'isha' => 15];
        $shows = fn (array $identity, array $prayer) => $this->draft(['identity' => $identity, 'prayer' => $prayer])->toProvisionPayload()['show_iqama_times'];
        $masjid = ['org_type' => 'masjid'];

        $this->assertFalse($shows($masjid, ['method' => 'ISNA']), 'an untouched panel gave no times');
        $this->assertFalse($shows($masjid, ['iqama_given' => null, 'iqama' => ['fajr' => null, 'dhuhr' => '']]), 'cleared fields are no times either');
        $this->assertFalse($shows($masjid, ['iqama_given' => false, 'iqama' => $five]), 'the tick wins over offsets typed before it');
        $this->assertTrue($shows($masjid, ['iqama' => $five]), 'all five, a zero among them');
        $this->assertTrue($shows($masjid, ['iqama' => ['fajr' => 25]]), 'some: shown, for the request to refuse the rest by name');
        $this->assertTrue($shows([], ['iqama' => $five]), 'no type is a masjid, as the request reads it');
        $this->assertFalse($shows(['org_type' => 'school'], ['iqama' => $five]), 'a school is never asked for iqama');
    }

    #[Test]
    public function an_empty_draft_flattens_to_hidden_iqama_and_nothing_else_even_with_secrets_typed(): void
    {
        $this->assertSame(['show_iqama_times' => false], $this->draft([])->toProvisionPayload(self::SECRETS));
    }
}
