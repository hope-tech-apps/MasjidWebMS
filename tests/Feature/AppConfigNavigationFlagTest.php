<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\AppConfig\UpdateAppConfigRequest;
use App\Models\AppVersionSetting;
use App\Models\User;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * `navigation` — the lever that puts one organisation's app back on the old
 * layout without a release.
 *
 * This flag was the highest-consequence disagreement in the three lane plans:
 * the server was going to emit `legacy`, both clients were compiled to
 * recognise `tabs_drawer`, and both clients map an unrecognised value to the
 * NEW shell. Set end to end, that lever would have saved cleanly, returned
 * 200, and changed nothing on any phone — a silent success, which is the worst
 * kind of emergency control to discover you have.
 *
 * So the assertions here are mostly about the paths a value has to survive
 * rather than about the value itself:
 *
 *   - all four spellings are ACCEPTED, because refusing one the apps honour is
 *     the failure above wearing a validation error;
 *   - the value is persisted, not dropped by the controller's explicit
 *     `only()` list;
 *   - it is emitted INSIDE `data.ios` / `data.android`, never as a sibling of
 *     them (a sibling key under `data` fails iOS's whole decode and takes
 *     force-update and maintenance mode down with it);
 *   - it is OMITTED, not null, when nothing was chosen — which is what keeps
 *     every untouched organisation's body identical after the deploy;
 *   - and clearing it back to null works, because a lever you cannot un-pull
 *     is not a lever.
 *
 * That an app actually DRAWS the legacy shell when it reads this is not
 * testable here; it is a staging walk on a real build (plan v3 §4, G6), and no
 * assertion in this file should be read as covering it.
 */
class AppConfigNavigationFlagTest extends TestCase
{
    use RefreshDatabase;
    use MakesMenuOrganisations;

    /** The eight keys every platform block has always had, in order. */
    private const LEGACY_BLOCK_KEYS = [
        'minimum_version', 'minimum_build', 'force_update', 'update_message',
        'latest_version', 'store_url', 'maintenance_mode', 'maintenance_message',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
    }

    #[Test]
    public function all_four_spellings_are_accepted_and_stored(): void
    {
        // `menu`/`legacy` are the server's vocabulary; `side_menu`/`tabs_drawer`
        // are the clients'. An operator reading either document gets the
        // layout they meant.
        $org = $this->listedOrg('Muslim Education Center');
        Sanctum::actingAs($this->superAdmin());

        foreach (['menu', 'legacy', 'side_menu', 'tabs_drawer'] as $value) {
            $this->save($org->id, 'ios', ['navigation' => $value])->assertOk();

            $this->assertSame($value, $this->row($org->id, 'ios')->navigation, "spelling: {$value}");
        }
    }

    #[Test]
    public function a_spelling_nobody_ships_is_refused(): void
    {
        $org = $this->listedOrg('Muslim Education Center');
        Sanctum::actingAs($this->superAdmin());

        // No trailing-whitespace case: TrimStrings runs before validation, so
        // 'menu ' arrives as 'menu' and is legitimately accepted.
        //
        // The refusal envelope is this repo's own — BaseFormRequest renders
        // `{status: failed, data: {field: [...]}}`, not Laravel's `errors` —
        // so assertJsonValidationErrors would look in the wrong place.
        foreach (['drawer', 'MENU', 'new', 'sidemenu', '1'] as $value) {
            $this->save($org->id, 'ios', ['navigation' => $value])
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed')
                ->assertJsonStructure(['data' => ['navigation']]);
        }

        $this->assertNull($this->row($org->id, 'ios'));
    }

    #[Test]
    public function the_value_survives_the_controllers_explicit_field_list(): void
    {
        // The admin controller saves `$request->safe()->only([...])`. A
        // validated field missing from that list is dropped in silence and the
        // save still answers 200 with a row that looks right — the operator
        // sees "Saved", the column stays null, and every phone keeps the new
        // shell. This asserts the round trip, not the request.
        $org = $this->listedOrg('Muslim Education Center');
        Sanctum::actingAs($this->superAdmin());

        $response = $this->save($org->id, 'android', [
            'minimum_version' => '2.5.0',
            'minimum_build' => 44,
            'navigation' => 'legacy',
        ])->assertOk();

        $this->assertSame('legacy', $response->json('data.navigation'));
        $this->assertSame('legacy', $this->row($org->id, 'android')->navigation);
        $this->assertSame('2.5.0', $this->row($org->id, 'android')->minimum_version);
    }

    #[Test]
    public function it_can_be_cleared_back_to_the_apps_own_default(): void
    {
        $org = $this->listedOrg('Muslim Education Center');
        Sanctum::actingAs($this->superAdmin());

        $this->save($org->id, 'ios', ['navigation' => 'legacy'])->assertOk();
        $this->save($org->id, 'ios', ['navigation' => null])->assertOk();

        $this->assertNull($this->row($org->id, 'ios')->navigation);
        $this->assertArrayNotHasKey('navigation', $this->publicBlock($org->id, 'ios'));
    }

    #[Test]
    public function it_is_emitted_inside_the_platform_block_and_never_beside_it(): void
    {
        // iOS decodes `data` as [String: AppConfig] with non-optional fields. A
        // sibling key under `data` is not a stray field it ignores — it fails
        // the whole decode, on launch, on every iPhone, taking force-update and
        // maintenance mode with it.
        $org = $this->listedOrg('Muslim Education Center');
        $this->setting($org->id, 'ios', ['navigation' => 'legacy']);
        $this->setting($org->id, 'android', ['navigation' => 'menu']);

        $data = $this->publicConfig($org->id);

        $this->assertSame(['android', 'ios'], collect(array_keys($data))->sort()->values()->all());
        $this->assertSame('legacy', $data['ios']['navigation']);
        $this->assertSame('menu', $data['android']['navigation']);
    }

    #[Test]
    public function a_null_flag_leaves_the_block_exactly_as_the_installed_builds_receive_it(): void
    {
        // The whole point of omitting rather than sending null: after the S1
        // deploy, every organisation nobody has touched serves a byte-identical
        // app-config, so the single production payload diff stays /orgs alone.
        $org = $this->listedOrg('Muslim Education Center');
        $this->setting($org->id, 'ios', ['minimum_version' => '2.5.0', 'minimum_build' => 44]);

        $block = $this->publicBlock($org->id, 'ios');

        $this->assertSame(self::LEGACY_BLOCK_KEYS, array_keys($block));
    }

    #[Test]
    public function a_set_flag_is_appended_after_the_eight_keys_and_replaces_none_of_them(): void
    {
        $org = $this->listedOrg('Muslim Education Center');
        $this->setting($org->id, 'ios', [
            'minimum_version' => '2.5.0',
            'minimum_build' => 44,
            'force_update' => true,
            'update_message' => 'Please update.',
            'maintenance_mode' => false,
            'navigation' => 'legacy',
        ]);

        $block = $this->publicBlock($org->id, 'ios');

        $this->assertSame([...self::LEGACY_BLOCK_KEYS, 'navigation'], array_keys($block));
        $this->assertSame('2.5.0', $block['minimum_version']);
        $this->assertSame(44, $block['minimum_build']);
        $this->assertTrue($block['force_update']);
        $this->assertSame('Please update.', $block['update_message']);
        $this->assertFalse($block['maintenance_mode']);
    }

    #[Test]
    public function one_platform_can_be_rolled_back_without_the_other(): void
    {
        // The realistic emergency: the new shell is wrong on Android and fine
        // on iOS, or a store review has one platform a build behind.
        $org = $this->listedOrg('Muslim Education Center');
        $this->setting($org->id, 'ios', []);
        $this->setting($org->id, 'android', ['navigation' => 'legacy']);

        $data = $this->publicConfig($org->id);

        $this->assertArrayNotHasKey('navigation', $data['ios']);
        $this->assertSame('legacy', $data['android']['navigation']);
    }

    #[Test]
    public function the_global_app_config_is_untouched_by_any_of_this(): void
    {
        // The live Burlington v2.5 b44 calls the GLOBAL endpoint on launch and
        // fails open on it. It answers an empty object and must keep doing so
        // — a 404 here once hung that build on its splash screen.
        $this->getJson('/api/mobile/app-config')
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'data' => []]);
    }

    #[Test]
    public function changing_the_flag_is_felt_once_the_admin_screen_flushes(): void
    {
        // The public endpoint is cached for ten minutes; the admin save flushes
        // APP_CONFIG. An emergency lever whose effect waits out a TTL is not
        // one, so this pins that the save — not a later request — is what
        // clears it.
        $org = $this->listedOrg('Muslim Education Center');
        $this->setting($org->id, 'ios', []);

        $this->assertArrayNotHasKey('navigation', $this->publicBlock($org->id, 'ios'));

        Sanctum::actingAs($this->superAdmin());
        $this->save($org->id, 'ios', ['navigation' => 'legacy'])->assertOk();

        $this->assertSame('legacy', $this->publicBlock($org->id, 'ios')['navigation']);
    }

    #[Test]
    public function the_documented_aliases_are_the_ones_the_rule_accepts(): void
    {
        // config/app_menu.php is where an operator reads what to type, and the
        // request rule is what the server will take. If they drifted, the
        // documentation would be telling somebody to type a value that 422s in
        // the middle of the incident it exists for.
        $aliases = config('app_menu.navigation_aliases');

        $this->assertSame([
            'menu' => 'menu',
            'side_menu' => 'menu',
            'legacy' => 'legacy',
            'tabs_drawer' => 'legacy',
        ], $aliases);

        $rule = (new UpdateAppConfigRequest())->rules()['navigation'];
        $accepted = explode(',', substr($rule, strpos($rule, 'in:') + 3));

        sort($accepted);
        $documented = array_keys($aliases);
        sort($documented);

        $this->assertSame($documented, $accepted);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ])->fresh();
    }

    /** @param array<string, mixed> $payload */
    private function save(int $masjidId, string $platform, array $payload)
    {
        return $this->postJson("/api/admin/masjids/{$masjidId}/app-config/{$platform}", $payload);
    }

    /** @param array<string, mixed> $attributes */
    private function setting(int $masjidId, string $platform, array $attributes): AppVersionSetting
    {
        MobileCache::flushMasjid($masjidId, MobileCache::APP_CONFIG);

        return AppVersionSetting::create(array_merge([
            'masjid_id' => $masjidId,
            'platform' => $platform,
            'minimum_version' => '1.0.0',
            'minimum_build' => 1,
        ], $attributes));
    }

    private function row(int $masjidId, string $platform): ?AppVersionSetting
    {
        return AppVersionSetting::where('masjid_id', $masjidId)->where('platform', $platform)->first();
    }

    /** @return array<string, mixed> */
    private function publicConfig(int $masjidId): array
    {
        return $this->getJson("/api/mobile/masjids/{$masjidId}/app-config")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function publicBlock(int $masjidId, string $platform): array
    {
        return $this->publicConfig($masjidId)[$platform];
    }
}
