<?php

namespace Tests\Feature\Studio;

use App\Models\StudioDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * PATCH /api/admin/studio/drafts/{id}: Studio's autosave. Each step owns a
 * section and replaces it whole; lock_version stops two tabs clobbering each
 * other; store secrets never reach the row (docs/manara-studio-w1.md S2, R6, R7).
 */
class StudioDraftAutosaveTest extends TestCase
{
    use RefreshDatabase;
    use StudioDraftFixtures;

    private const SECRET_REFUSAL = 'Store credentials are never saved in a draft. Enter them at Step 3, when the organisation is provisioned.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function patch_replaces_a_section_wholesale_and_leaves_others(): void
    {
        $id = $this->newDraft(['org_type' => 'school'])['id'];

        $this->patchDraft($id, 0, [
            'identity' => ['org_type' => 'school', 'name' => 'Al-Noor Academy', 'email' => 'office@alnoor.test', 'phone' => '+1 555 0100'],
            'brand' => ['primary_color' => '#01B151', 'secondary_color' => '#1B1B2E', 'accent_color' => '#FFBA63', 'background_color' => '#F3F8FB'],
        ], ['current_step' => 'foundation'])
            ->assertOk()
            ->assertJsonPath('data.lock_version', 1);

        $response = $this->patchDraft($id, 1, ['identity' => ['org_type' => 'school', 'name' => 'Al-Noor School']])
            ->assertOk()
            ->assertJsonPath('data.lock_version', 2);

        // The whole identity section was replaced: the email and phone the
        // second save did not send are gone, not merged back in.
        $this->assertSame(['org_type' => 'school', 'name' => 'Al-Noor School'], $response->json('data.answers.identity'));
        // The section it did not name is untouched.
        $this->assertSame('#01B151', $response->json('data.answers.brand.primary_color'));

        $draft = StudioDraft::findOrFail($id);
        $this->assertSame('Al-Noor School', $draft->name, 'the list column follows answers.identity.name');
        $this->assertSame('school', $draft->org_type);
        $this->assertSame(2, $draft->lock_version);

        // A section sent as null is cleared; the rest stay.
        $cleared = $this->patchDraft($id, 2, ['brand' => null])->assertOk();
        $this->assertArrayNotHasKey('brand', $cleared->json('data.answers'));
        $this->assertSame('Al-Noor School', $cleared->json('data.answers.identity.name'));
    }

    #[Test]
    public function stale_lock_version_is_a_409_carrying_the_current_draft(): void
    {
        $id = $this->newDraft()['id'];

        // Tab A saves first.
        $this->patchDraft($id, 0, ['content' => ['about' => 'Written in tab A.']])->assertOk();

        // Tab B still holds lock_version 0.
        $this->patchDraft($id, 0, ['content' => ['about' => 'Written in tab B.']])
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.answers.content.about', 'Written in tab A.');

        $draft = StudioDraft::findOrFail($id);
        $this->assertSame('Written in tab A.', $draft->section('content')['about'], 'the stale save changed nothing');
        $this->assertSame(1, $draft->lock_version);

        // The lock is required: an autosave that names no version is refused.
        $this->patchJson(self::DRAFTS . "/{$id}", ['answers' => ['content' => ['about' => 'x']]])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonValidationErrors(['lock_version'], 'data');
    }

    #[Test]
    public function answers_survive_the_clients_encodings(): void
    {
        $id = $this->newDraft()['id'];

        // 1. Form-encoded, the SPA's axios default, with answers as a JSON
        //    string: every type, Arabic and an empty list come back as sent.
        $answers = [
            'identity' => ['org_type' => 'masjid', 'name' => 'مسجد النور', 'country_id' => 38, 'latitude' => 43.3255],
            'prayer' => ['iqama_given' => false, 'iqama' => ['fajr' => 20, 'isha' => 0]],
            'brand' => ['extracted' => []],
        ];

        $this->patch(self::DRAFTS . "/{$id}", [
            'lock_version' => '0',
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.lock_version', 1);

        $draft = StudioDraft::findOrFail($id);
        $this->assertSame('مسجد النور', $draft->section('identity')['name']);
        $this->assertSame('مسجد النور', $draft->name);
        $this->assertSame(38, $draft->section('identity')['country_id']);
        $this->assertSame(43.3255, $draft->section('identity')['latitude']);
        $this->assertFalse($draft->section('prayer')['iqama_given']);
        $this->assertSame(['fajr' => 20, 'isha' => 0], $draft->section('prayer')['iqama']);
        $this->assertSame([], $draft->section('brand')['extracted']);

        // 2. Form-encoded with the object serialised into bracketed fields:
        //    booleans arrive as "true"/"false" and numbers as strings.
        $this->patch(self::DRAFTS . "/{$id}", [
            'lock_version' => '1',
            'answers' => [
                'prayer' => ['iqama_given' => 'true', 'iqama' => ['fajr' => '25']],
                'features' => ['capabilities' => ['giving' => 'false', 'events' => 'true']],
                'identity' => ['name' => 'Masjid An-Nur', 'city_id' => '7', 'longitude' => '-79.7990'],
            ],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.lock_version', 2);

        $draft = StudioDraft::findOrFail($id);
        $this->assertTrue($draft->section('prayer')['iqama_given']);
        $this->assertSame(['fajr' => 25], $draft->section('prayer')['iqama']);
        $this->assertSame(['giving' => false, 'events' => true], $draft->section('features')['capabilities']);
        $this->assertSame(7, $draft->section('identity')['city_id']);
        $this->assertSame(-79.799, $draft->section('identity')['longitude']);

        // 3. A string that is not a JSON object is refused by name, not stored.
        $this->patch(self::DRAFTS . "/{$id}", ['lock_version' => '2', 'answers' => '{not json'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers'], 'data');

        // 4. A switch that is not a boolean in any spelling is refused, never read as false.
        $this->patchDraft($id, 2, ['features' => ['capabilities' => ['giving' => 'maybe']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.features.capabilities.giving'], 'data');

        $this->assertSame(2, StudioDraft::findOrFail($id)->lock_version);
    }

    #[Test]
    public function secret_keys_are_refused_and_never_stored(): void
    {
        $id = $this->newDraft()['id'];
        $this->patchDraft($id, 0, ['platforms' => ['platforms' => ['ios', 'android'], 'apps' => ['ios' => ['account_mode' => 'byo']]]])->assertOk();

        $secrets = [
            'ios' => ['asc_key_p8' => '-----BEGIN PRIVATE KEY-----SECRET-P8', 'asc_key_id' => 'SECRETKEYID', 'asc_issuer_id' => 'SECRET-ISSUER'],
            'android' => ['play_service_account_json' => '{"private_key":"SECRET-PLAY"}'],
        ];

        foreach ($secrets as $platform => $keys) {
            foreach ($keys as $key => $value) {
                $section = ['platforms' => ['ios', 'android'], 'apps' => [$platform => ['account_mode' => 'byo', $key => $value]]];

                // As an object, and as the JSON string the SPA sends; refused
                // as a secret, by name, not merely as an unknown key.
                $errors = $this->patchDraft($id, 1, ['platforms' => $section])
                    ->assertStatus(422)
                    ->assertJsonPath('status', 'failed')
                    ->json('data');
                $this->assertSame([self::SECRET_REFUSAL], $errors["answers.platforms.apps.{$platform}.{$key}"] ?? null, "{$platform}.{$key}");

                $this->patchJson(self::DRAFTS . "/{$id}", ['lock_version' => 1, 'answers' => json_encode(['platforms' => $section])])
                    ->assertStatus(422);
            }
        }

        // Anywhere in the tree, not only where the wizard used to keep them.
        $errors = $this->patchDraft($id, 1, ['identity' => ['name' => 'x', 'asc_key_p8' => 'SECRET-ELSEWHERE']])
            ->assertStatus(422)
            ->json('data');
        $this->assertSame([self::SECRET_REFUSAL], $errors['answers.identity.asc_key_p8'] ?? null);

        $row = (string) DB::table('studio_drafts')->where('id', $id)->value('answers');
        $this->assertStringNotContainsString('SECRET', $row);
        $this->assertSame(1, StudioDraft::findOrFail($id)->lock_version, 'no refused save moved the lock');
        $this->assertFalse(Schema::hasColumn('studio_drafts', 'secrets'), 'there is no column for them either');
    }

    #[Test]
    public function a_provisioned_draft_refuses_patch_and_delete(): void
    {
        $id = $this->newDraft(['name' => 'Already Live'])['id'];
        $draft = $this->markProvisioned($id);

        $this->patchDraft($id, $draft->lock_version, ['content' => ['about' => 'Too late.']])
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('data.status', StudioDraft::STATUS_PROVISIONED)
            ->assertJsonPath('data.provisioned_masjid_id', $draft->provisioned_masjid_id);

        $this->deleteJson(self::DRAFTS . "/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        $this->assertDatabaseHas('studio_drafts', ['id' => $id, 'status' => StudioDraft::STATUS_PROVISIONED]);
        $this->assertSame([], StudioDraft::findOrFail($id)->section('content'));
    }

    #[Test]
    public function an_unknown_section_or_answer_is_refused_rather_than_dropped(): void
    {
        $id = $this->newDraft()['id'];

        $this->patchDraft($id, 0, ['colours' => ['primary' => '#000000']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.colours'], 'data');

        $this->patchDraft($id, 0, ['identity' => ['name' => 'x', 'nickname' => 'y']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.identity.nickname'], 'data');

        $this->patchDraft($id, 0, ['features' => ['capabilities' => ['teleportation' => true]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.features.capabilities.teleportation'], 'data');

        $this->patchDraft($id, 0, ['brand' => ['primary_color' => 'green']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.brand.primary_color'], 'data');

        $this->assertSame(0, StudioDraft::findOrFail($id)->lock_version);
    }

    #[Test]
    public function answers_over_the_byte_ceiling_are_refused(): void
    {
        config(['studio.drafts.max_answers_bytes' => 2048]);
        $id = $this->newDraft()['id'];

        $this->patchDraft($id, 0, ['content' => ['about' => str_repeat('a', 1500)]])->assertOk();

        $this->patchDraft($id, 1, ['content' => ['about' => str_repeat('a', 2100)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers'], 'data');

        $this->assertSame(1, StudioDraft::findOrFail($id)->lock_version);
    }

    #[Test]
    public function a_section_sent_empty_replaces_the_stored_one_with_nothing(): void
    {
        $id = $this->newDraft()['id'];

        $this->patchDraft($id, 0, [
            'identity' => ['org_type' => 'masjid', 'name' => 'Masjid An-Nur', 'admin' => ['name' => 'Imran Ali']],
            'prayer' => ['iqama' => ['fajr' => 20]],
            'content' => ['about' => 'Old text'],
            'domain' => ['custom' => ['host' => 'old.annur.test']],
        ])->assertOk();

        // The Step 2 editor cleared: a JSON {} for the section, not null.
        $response = $this->patchJson(self::DRAFTS . "/{$id}", ['lock_version' => 1, 'answers' => ['content' => new \stdClass]])
            ->assertOk()
            ->assertJsonPath('data.lock_version', 2);

        $this->assertEmpty($response->json('data.answers.content'), 'the save answered with what it recorded');
        $this->assertSame([], StudioDraft::findOrFail($id)->section('content'), 'the old about text is gone');

        // Sections holding only an empty object, sent as the SPA's JSON string.
        $this->patch(self::DRAFTS . "/{$id}", [
            'lock_version' => '2',
            'answers' => json_encode([
                'identity' => ['admin' => new \stdClass],
                'prayer' => ['iqama' => new \stdClass],
                'domain' => ['custom' => new \stdClass],
            ]),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.lock_version', 3);

        $draft = StudioDraft::findOrFail($id);
        $this->assertArrayNotHasKey('custom', $draft->section('domain'), 'the old custom host did not survive');
        $this->assertArrayNotHasKey('iqama', $draft->section('prayer'));
        $this->assertArrayNotHasKey('name', $draft->section('identity'));
        $this->assertNull($draft->name, 'the list columns follow the replaced identity');
        $this->assertNull($draft->org_type);
    }

    #[Test]
    public function a_half_chosen_palette_is_not_graded(): void
    {
        $id = $this->newDraft()['id'];
        $colours = ['primary_color' => '#01B151', 'secondary_color' => '#1B1B2E', 'accent_color' => '#FFBA63', 'background_color' => '#F3F8FB'];

        // One colour, then three: DesignTokens would fill the rest with a live
        // client's, so there is nothing of this client's to grade yet (R25).
        foreach ([1, 3] as $version => $chosen) {
            $data = $this->patchDraft($id, $version, ['brand' => array_slice($colours, 0, $chosen, true)])->assertOk()->json('data');

            $this->assertArrayHasKey('palette', $data);
            $this->assertNull($data['palette'], "{$chosen} of four colours chosen");
        }

        $palette = $this->patchDraft($id, 2, ['brand' => $colours])->assertOk()->json('data.palette');

        $this->assertNotNull($palette, 'all four chosen');
        $this->assertContains('on_primary', array_column($palette['pairs'], 'key'));
    }

    #[Test]
    public function answers_sent_as_a_json_string_over_the_byte_ceiling_are_refused(): void
    {
        config(['studio.drafts.max_answers_bytes' => 2048]);
        $id = $this->newDraft()['id'];

        // The SPA's own encoding, which is measured as the string it arrives as.
        $send = fn (int $version, int $length) => $this->patch(self::DRAFTS . "/{$id}", [
            'lock_version' => (string) $version,
            'answers' => json_encode(['content' => ['about' => str_repeat('a', $length)]]),
        ], ['Accept' => 'application/json']);

        $send(0, 1500)->assertOk();

        $send(1, 2100)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers'], 'data');

        $this->assertSame(1, StudioDraft::findOrFail($id)->lock_version);
        $this->assertSame(1500, strlen(StudioDraft::findOrFail($id)->section('content')['about']));
    }

    #[Test]
    public function a_save_that_lands_between_the_read_and_the_write_is_a_409_not_an_overwrite(): void
    {
        $id = $this->newDraft()['id'];
        $this->patchDraft($id, 0, ['content' => ['about' => 'Loaded by both tabs.']])->assertOk();

        // Tab A's save commits after tab B's PATCH has read lock_version 1 and
        // before it writes: the window a row lock on MySQL would close, and
        // which the suite's SQLite cannot show without an actual interleaving.
        $landed = false;
        DB::connection()->beforeExecuting(function (string $query) use ($id, &$landed) {
            if ($landed || ! str_starts_with(strtolower(ltrim($query)), 'update "studio_drafts"')) {
                return;
            }

            $landed = true;
            DB::table('studio_drafts')->where('id', $id)->update([
                'lock_version' => 2,
                'answers' => json_encode(['content' => ['about' => 'Written in tab A.']]),
            ]);
        });

        $this->patchDraft($id, 1, ['content' => ['about' => 'Written in tab B.']])
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.answers.content.about', 'Written in tab A.');

        $this->assertTrue($landed, 'the interleaved save ran');

        $draft = StudioDraft::findOrFail($id);
        $this->assertSame('Written in tab A.', $draft->section('content')['about'], 'tab B did not overwrite tab A');
        $this->assertSame(2, $draft->lock_version);
    }
}
