<?php

namespace Tests\Feature\Studio;

use App\Http\Requests\Admin\Studio\StudioProvisionRequest;
use App\Models\MasjidAppPublishing;
use App\Models\StudioDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\Feature\Studio\Concerns\ReadsStudioSource;
use Tests\TestCase;

/**
 * Step 3's SPA and the provision endpoint agree on the wire
 * (docs/manara-studio-w1.md S8, R7).
 *
 * The results screen reads the 201 through the types in
 * resources/vue-app/core/types/data/Studio.ts, and the SPA builds the
 * credentials body from the type in core/studio/provision.ts. A key renamed on
 * one side and not the other would not fail a build: the screen would show a
 * blank count, or the server would drop a credential and answer the wizard's
 * "required" 422. So both sides are read here and held equal, against a real
 * provision.
 */
class StudioGenerateWireContractTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use ReadsStudioSource;
    use RefreshDatabase;

    private const TYPES = 'resources/vue-app/core/types/data/Studio.ts';

    private const PROVISION = 'resources/vue-app/core/studio/provision.ts';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function the_spa_types_every_key_the_provision_answer_sends(): void
    {
        $draft = $this->draftWith($this->studioAnswers('masjid', self::MINIMAL));
        $data = $this->provision($draft->id)->assertCreated()->json('data');

        $this->assertEqualsCanonicalizing($this->typeKeys('StudioProvisionResult'), array_keys($data), 'StudioProvisionResult');
        $this->assertEqualsCanonicalizing($this->typeKeys('StudioProvisionResult', 'app_publishing'), array_keys($data['app_publishing']), 'app_publishing');
        $this->assertEqualsCanonicalizing($this->typeKeys('StudioProvisionResult', 'brand_assets'), array_keys($data['brand_assets']), 'brand_assets');
        $this->assertEqualsCanonicalizing($this->typeKeys('StudioProvisionResult', 'web'), array_keys($data['web']), 'web');
        $this->assertEqualsCanonicalizing($this->typeKeys('StudioProvisionAfterCommit'), array_keys($data['after_commit']), 'after_commit');
        $this->assertEqualsCanonicalizing($this->typeKeys('StudioCapabilitiesApplied'), array_keys($data['capabilities_applied']), 'capabilities_applied');
        $this->assertEqualsCanonicalizing($this->typeKeys('StudioStarterSiteResult'), array_keys($data['starter_site']), 'starter_site');

        $this->assertNotEmpty($data['starter_site']['sections_inactive'], 'a minimal draft leaves sections waiting for facts');
        $this->assertEqualsCanonicalizing($this->typeKeys('StudioInactiveSection'), array_keys($data['starter_site']['sections_inactive'][0]), 'sections_inactive');

        // What readProvisionOutcome needs to call it created.
        $this->assertIsInt($data['masjid_id']);
        $this->assertIsArray($data['capabilities_applied']['changed']);
        $this->assertIsArray($data['capabilities_applied']['unchanged']);
    }

    #[Test]
    public function the_conflict_answer_carries_the_id_the_spa_reads(): void
    {
        $draft = $this->draftWith($this->studioAnswers());
        $first = $this->provision($draft->id)->assertCreated()->json('data');

        $conflict = $this->provision($draft->id)->assertStatus(409)->json();

        $this->assertSame('conflict', $conflict['status']);
        $this->assertSame($first['masjid_id'], $conflict['data']['provisioned_masjid_id']);
        $this->assertStringContainsString('idOf(data.provisioned_masjid_id)', $this->withoutComments($this->read(self::PROVISION)));
    }

    #[Test]
    public function the_credentials_the_spa_sends_are_the_ones_the_request_reads_and_the_draft_never_keeps_them(): void
    {
        $spa = $this->secretKeys();
        $request = [];
        foreach (array_keys((new StudioProvisionRequest)->rules()) as $rule) {
            if (preg_match('/^secrets\.(ios|android)\.(\w+)$/', $rule, $m)) {
                $request[$m[1]][] = $m[2];
            }
        }
        $this->assertEqualsCanonicalizing($request['ios'], $spa['ios'], 'iOS credential keys');
        $this->assertEqualsCanonicalizing($request['android'], $spa['android'], 'Android credential keys');
        $this->assertEqualsCanonicalizing(StudioDraft::SECRET_KEYS, array_merge($spa['ios'], $spa['android']));

        $values = [
            'asc_key_p8' => "-----BEGIN PRIVATE KEY-----\nSENTINEL-P8-7f3a\n-----END PRIVATE KEY-----",
            'asc_key_id' => 'SENTINEL-KEYID-7f3a',
            'asc_issuer_id' => 'SENTINEL-ISSUER-7f3a',
            'play_service_account_json' => '{"type":"service_account","client_email":"sentinel-7f3a@example.test"}',
        ];

        $draft = $this->draftWith($this->studioAnswers(sections: [
            'platforms' => [
                'platforms' => ['ios', 'android', 'web'],
                'apps' => ['ios' => ['account_mode' => 'byo'], 'android' => ['account_mode' => 'byo'], 'web' => ['account_mode' => 'managed']],
            ],
        ]));

        // The body exactly as provisionBody() shapes it: {secrets: {ios: {...}, android: {...}}}.
        $body = ['secrets' => [
            'ios' => array_intersect_key($values, array_flip($spa['ios'])),
            'android' => array_intersect_key($values, array_flip($spa['android'])),
        ]];

        $data = $this->provision($draft->id, $body)->assertCreated()->json('data');

        $this->assertTrue($data['app_publishing']['has_asc_key']);
        $this->assertTrue($data['app_publishing']['has_play_service_account']);
        $publishing = MasjidAppPublishing::where('masjid_id', $data['masjid_id'])->firstOrFail();
        $this->assertSame($values['asc_key_id'], $publishing->asc_key_id);
        $this->assertSame($values['play_service_account_json'], $publishing->play_service_account_json);

        $stored = json_encode(DB::table('studio_drafts')->where('id', $draft->id)->first());
        foreach (['SENTINEL-P8-7f3a', 'SENTINEL-KEYID-7f3a', 'SENTINEL-ISSUER-7f3a', 'sentinel-7f3a@example.test'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $stored, 'a credential reached the draft row (R7)');
        }
    }

    /**
     * The Platforms panel keeps iOS's "Bring your own" when iOS is unticked,
     * and Step 3 asks for no credentials for it. The provision must not
     * demand them either: the request's `required_if:…,byo` had no field on
     * Step 3 to satisfy.
     */
    #[Test]
    public function a_bring_your_own_mode_left_on_an_unselected_platform_asks_for_nothing(): void
    {
        $draft = $this->draftWith($this->studioAnswers(sections: [
            'platforms' => ['platforms' => ['android', 'web'], 'apps' => ['ios' => ['account_mode' => 'byo'], 'android' => ['account_mode' => 'managed']]],
        ]));

        $data = $this->provision($draft->id)->assertCreated()->json('data');

        $this->assertFalse($data['app_publishing']['has_asc_key']);
        $this->assertSame('managed', MasjidAppPublishing::where('masjid_id', $data['masjid_id'])->value('ios_account_mode'), 'an unselected platform keeps the default mode');
    }

    /**
     * The top-level keys of `export type NAME = { … };` in Studio.ts, or of the
     * object typed under `$member` inside it. Keys are read by indentation, as
     * the file is formatted: four spaces for a type's own keys.
     *
     * @return list<string>
     */
    private function typeKeys(string $name, ?string $member = null): array
    {
        $source = $this->withoutComments($this->read(self::TYPES));
        $body = $this->braced($source, '/export type ' . $name . ' = (?:\{[^\n]*\} & )?\{/');

        if ($member !== null) {
            $body = $this->braced($body, '/\n    ' . preg_quote($member, '/') . '\??: \{/');
            preg_match_all('/^ {8}(\w+)\??:/m', $body, $keys);

            return $keys[1];
        }

        preg_match_all('/^ {4}(\w+)\??:/m', $body, $keys);

        return $keys[1];
    }

    /** The text inside the braces opened by the first match of `$opening` (which ends at its `{`). */
    private function braced(string $source, string $opening): string
    {
        $this->assertMatchesRegularExpression($opening, $source);
        preg_match($opening, $source, $m, PREG_OFFSET_CAPTURE);
        $start = $m[0][1] + strlen($m[0][0]);

        for ($i = $start, $depth = 1; $i < strlen($source); $i++) {
            $depth += match ($source[$i]) { '{' => 1, '}' => -1, default => 0 };
            if ($depth === 0) {
                return substr($source, $start, $i - $start);
            }
        }

        $this->fail("unbalanced braces after {$opening}");
    }

    /** @return array{ios: list<string>, android: list<string>} the keys of provision.ts `ProvisionSecrets` */
    private function secretKeys(): array
    {
        $body = $this->braced($this->withoutComments($this->read(self::PROVISION)), '/export type ProvisionSecrets = \{/');
        $out = [];
        foreach (['ios', 'android'] as $platform) {
            $this->assertMatchesRegularExpression('/' . $platform . ': \{([^}]*)\}/', $body);
            preg_match('/' . $platform . ': \{([^}]*)\}/', $body, $m);
            preg_match_all('/(\w+): string/', $m[1], $keys);
            $out[$platform] = $keys[1];
        }

        return $out;
    }
}
