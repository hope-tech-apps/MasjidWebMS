<?php

namespace Tests\Feature\Studio;

use App\Mail\AccountAccessMail;
use App\Models\DonationLink;
use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MasjidMobileAppFeature;
use App\Models\MasjidSocialMediaLink;
use App\Models\MobileAppFeature;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Characterisation of the wizard's provision endpoint, recorded BEFORE its body
 * moved into OrganisationProvisioner (Studio W1, S6).
 *
 * The extraction promises that nothing a provision produces changes. The tests
 * that already cover provisioning each assert the few facts they care about, so
 * a moved line that writes one extra column, drops a pivot row or reorders the
 * response would pass all of them. This records EVERYTHING instead: every row
 * of every table the call adds, changes or removes, the mail it sends, and the
 * status and body it answers, for an organisation of each vertical and for a
 * provision that fails part-way. The fixtures under tests/fixtures/provision-
 * snapshot were written by the unrefactored controller (90e4d182's, swapped in
 * for every recording since) and are never edited by hand; a difference is a
 * behaviour change, not a stale snapshot.
 *
 * Ids are replaced by "table#n" (the row's position in its table) and every
 * foreign key that can be traced is replaced the same way, so the snapshot says
 * WHICH row a key points at rather than which number it happened to get. A key
 * that is not an integer keeps its JSON type in the label ("users#1:string"):
 * PHP files "5" and 5 under the same array key, so without it a response that
 * started answering `"created_by": "5"` would still match, and a typed mobile or
 * SPA decoder would break with every snapshot green.
 * Timestamps, password hashes and token digests are replaced by what they are.
 * Encrypted columns are decrypted, because the ciphertext changes on every run
 * but what it holds must not.
 *
 * Another organisation already exists, with a row in each table provisioning
 * writes per organisation, so "the new organisation" and "the first one" are
 * different rows. With an empty `masjids` table a row written against the wrong
 * tenant would get the same label as the right one and pass.
 *
 * To record a fixture (only ever against code whose behaviour is the one to
 * keep): PROVISION_SNAPSHOT_RECORD=1. Recording never passes, so a recorded run
 * cannot be mistaken for a checked one.
 */
class ProvisionResponseSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = 'tests/fixtures/provision-snapshot';

    /** The seeded catalogue (MobileAppFeaturesSeeder); `quran` is renamed to production's bytes below. */
    private const CATALOGUE = [
        'quran', 'hadith', 'adhkar', 'qibla', 'tasbih', 'donate',
        'about_us', 'gallery', 'services', 'announcements', 'contact_us',
    ];

    private int $countryId;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $this->cityId = DB::table('cities')->insertGetId([
            'name' => 'Burlington',
            'country_id' => $this->countryId,
        ]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        foreach (self::CATALOGUE as $key) {
            MobileAppFeature::create(['name' => ucfirst($key), 'key' => $key]);
        }

        // Production's Qur'an row is keyed with U+2019 (QuranFeatureKeySpellingTest),
        // so the snapshot runs on the catalogue production actually has.
        MobileAppFeature::where('key', 'quran')->firstOrFail()
            ->forceFill(['key' => "qur\u{2019}an", 'name' => "Qur\u{2019}an"])->save();

        $this->seedNeighbour();

        Mail::fake();
    }

    #[Test]
    public function provisioning_a_masjid_through_the_wizard_writes_exactly_the_recorded_rows_and_response(): void
    {
        // Every optional branch taken: prose, a donation link, all five social
        // links, custom iqama offsets, every platform with both stores BYO, the
        // vertical's default bundle, and an administrator created and invited.
        $this->assertMatchesRecording('masjid', $this->payload([
            'brand' => [
                'primary_color' => '#0A5C36',
                'secondary_color' => '#F2E8CF',
                'accent_color' => '#C9A227',
                'background_color' => '#FFFFFF',
            ],
            'about' => 'A community masjid.',
            'mission' => 'Serve.',
            'vision' => 'Grow.',
            'iqama_type' => 'minutes_after_adhan',
            'iqama' => ['fajr' => 25, 'dhuhr' => 15, 'asr' => 12, 'maghrib' => 7, 'isha' => 14],
            'jumaa_iqama' => '13:15',
            'donation_link' => 'https://give.example.test/masjid',
            'donation_title' => 'Support us',
            'donation_message' => 'Give today',
            'facebook_url' => 'https://facebook.example.test/masjid',
            'youtube_url' => 'https://youtube.example.test/masjid',
            'instagram_url' => 'https://instagram.example.test/masjid',
            'whatsapp_url' => 'https://wa.example.test/masjid',
            'whatsapp_number' => '+15550001111',
            'platforms' => ['ios', 'android', 'tvos', 'web'],
            'apps' => [
                'ios' => [
                    'account_mode' => 'byo',
                    'asc_key_p8' => "-----BEGIN PRIVATE KEY-----\nMIGT\n-----END PRIVATE KEY-----",
                    'asc_key_id' => 'ABC123DEFG',
                    'asc_issuer_id' => '69a6de70-0000-0000-0000-000000000000',
                ],
                'android' => [
                    'account_mode' => 'byo',
                    'play_service_account_json' => '{"type":"service_account","project_id":"snapshot"}',
                ],
                'web' => ['account_mode' => 'managed'],
            ],
            'admin' => [
                'name' => 'Masjid Office',
                'email' => 'office@masjid.example.test',
                'phone' => '+15550002222',
            ],
        ]));
    }

    #[Test]
    public function provisioning_a_school_through_the_wizard_writes_exactly_the_recorded_rows_and_response(): void
    {
        // An existing MasjidAdmin as owner, an explicit feature selection posted
        // in the ASCII spelling, the CRM deliberately left off, and the school's
        // starter form templates.
        $owner = User::factory()->create([
            'name' => 'Existing Owner',
            'email' => 'owner@school.example.test',
            'phone' => '+15550003333',
            'type' => 'MasjidAdmin',
        ]);

        $this->assertMatchesRecording('school', $this->payload([
            'org_type' => 'school',
            'user_id' => $owner->id,
            'crm_enabled' => false,
            'feature_keys_provided' => '1',
            'feature_keys' => ['quran', 'announcements'],
            'brand' => ['primary_color' => '#123456'],
            'platforms' => ['web', 'android'],
            'apps' => ['android' => ['account_mode' => 'managed']],
        ]));
    }

    #[Test]
    public function provisioning_a_community_organisation_through_the_wizard_writes_exactly_the_recorded_rows_and_response(): void
    {
        // The minimum the wizard can send: no owner, no prose, no links, the
        // vertical's default bundle.
        $this->assertMatchesRecording('community', $this->payload([
            'org_type' => 'community',
        ]));
    }

    #[Test]
    public function a_provision_that_fails_part_way_writes_nothing_sends_nothing_and_answers_the_recorded_500(): void
    {
        // The social links are written after the masjid, theme, prayer, iqama,
        // jumaa and donation rows, so losing their table fails the call with all
        // of those already inserted: the recording pins that they are rolled
        // back, that the invite is never sent, and the error envelope.
        config(['app.debug' => false]);
        Schema::drop('masjid_social_media_links');

        $this->assertMatchesRecording('failure', $this->payload([
            'donation_link' => 'https://give.example.test/failure',
            'facebook_url' => 'https://facebook.example.test/failure',
            'admin' => ['email' => 'office@failure.example.test'],
        ]));
    }

    private function assertMatchesRecording(string $case, array $payload): void
    {
        Sanctum::actingAs(User::factory()->create([
            'name' => 'Platform Operator',
            'email' => 'operator@manara.example.test',
            'phone' => '+15550009999',
            'type' => 'SuperAdmin',
        ]));

        $before = $this->everyRow();

        $response = $this->postJson('/api/admin/onboarding/provision', $payload);

        $after = $this->everyRow();
        $ids = $this->idMap($after);

        $snapshot = [
            'status' => $response->getStatusCode(),
            'body' => $this->normaliseValue(json_decode($response->getContent(), true), null, $ids),
            'written' => $this->normaliseTables($this->rowsOnlyIn($after, $before), $ids),
            'removed' => $this->normaliseTables($this->rowsOnlyIn($before, $after), $this->idMap($before)),
            'mail' => Mail::sent(AccountAccessMail::class)->map(fn (AccountAccessMail $mail) => [
                'to' => $mail->user->email,
                'mode' => $mail->mode,
                'org_name' => $mail->orgName,
                'expires_in_minutes' => $mail->expiresInMinutes,
                'url' => preg_replace('/[A-Fa-f0-9]{64}/', '<token>', $mail->url),
            ])->values()->all(),
        ];

        // Checked before anything is recorded, so a recording cannot capture it.
        $this->assertWrittenOnlyForTheNewOrganisation($snapshot);

        $actual = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $path = base_path(self::FIXTURES."/{$case}.json");

        if (getenv('PROVISION_SNAPSHOT_RECORD') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $actual);
            $this->fail("Recorded {$path}. A recording run never passes: run again without PROVISION_SNAPSHOT_RECORD.");
        }

        $this->assertFileExists($path, "No recording for '{$case}'.");
        $this->assertSame(file_get_contents($path), $actual, "Provisioning a {$case} no longer writes or answers what was recorded.");
    }

    /**
     * An organisation that is not the one being provisioned, with a donation
     * link, a social link, a feature toggle per catalogue entry and a publishing
     * row: every per-organisation table the provision writes to has a row that
     * is not the new tenant's.
     */
    private function seedNeighbour(): void
    {
        $neighbour = Masjid::create([
            'name' => 'Neighbour Masjid',
            'org_type' => 'masjid',
            'email' => 'office@neighbour.example.test',
            'phone' => '+15550004444',
            'address' => '2 Test St',
            'latitude' => 43.33,
            'longitude' => -79.8,
            'timezone' => 'America/Toronto',
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
        ]);

        DonationLink::create([
            'masjid_id' => $neighbour->id,
            'link' => 'https://give.example.test/neighbour',
            'title' => 'Neighbour giving',
            'message' => 'Give to the neighbour',
        ]);
        MasjidSocialMediaLink::create([
            'masjid_id' => $neighbour->id,
            'type' => 'Facebook',
            'value' => 'https://facebook.example.test/neighbour',
        ]);
        foreach (MobileAppFeature::all() as $feature) {
            MasjidMobileAppFeature::create([
                'masjid_id' => $neighbour->id,
                'feature_id' => $feature->id,
                'is_available' => true,
            ]);
        }
        MasjidAppPublishing::create([
            'masjid_id' => $neighbour->id,
            'enabled_platforms' => ['web'],
            'ios_account_mode' => 'managed',
            'android_account_mode' => 'managed',
            'web_account_mode' => 'managed',
        ]);
    }

    /**
     * Every row the call wrote that belongs to an organisation belongs to the one
     * it answered with. The recording would show a stray `masjids#1` too; this
     * says what is wrong in one line instead of a fixture diff.
     */
    private function assertWrittenOnlyForTheNewOrganisation(array $snapshot): void
    {
        $created = $snapshot['body']['data']['masjid_id'] ?? null;

        foreach ($snapshot['written'] as $table => $rows) {
            foreach ($rows as $row) {
                if ($table === 'masjids' || ! array_key_exists('masjid_id', $row)) {
                    continue;
                }
                $this->assertSame($created, $row['masjid_id'], "A {$table} row was written for an organisation other than the one provisioned.");
            }
        }
    }

    /** @return array<string, list<array<string, mixed>>> every row of every table, by table */
    private function everyRow(): array
    {
        $tables = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"))
            ->pluck('name');

        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    /**
     * Rows of $a that $b does not hold identically, counted as a multiset so two
     * identical rows are not mistaken for one.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rowsOnlyIn(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $table => $rows) {
            $remaining = [];
            foreach ($b[$table] ?? [] as $row) {
                $key = json_encode($row);
                $remaining[$key] = ($remaining[$key] ?? 0) + 1;
            }

            foreach ($rows as $row) {
                $key = json_encode($row);
                if (($remaining[$key] ?? 0) > 0) {
                    $remaining[$key]--;

                    continue;
                }
                $out[$table][] = $row;
            }
        }

        return $out;
    }

    /** @return array<string, array<int|string, string>> table => id => "table#n" */
    private function idMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $table => $tableRows) {
            $ids = array_values(array_filter(array_column($tableRows, 'id'), fn ($id) => $id !== null));
            sort($ids);
            foreach ($ids as $i => $id) {
                $map[$table][$id] = $table.'#'.($i + 1);
            }
        }

        return $map;
    }

    private function normaliseTables(array $tables, array $ids): array
    {
        ksort($tables);

        $out = [];
        foreach ($tables as $table => $rows) {
            $normalised = array_map(fn (array $row) => $this->normaliseRow($row, $table, $ids), $rows);
            usort($normalised, fn ($x, $y) => strcmp(json_encode($x), json_encode($y)));
            $out[$table] = $normalised;
        }

        return $out;
    }

    private function normaliseRow(array $row, ?string $table, array $ids): array
    {
        $out = [];
        foreach ($row as $column => $value) {
            $out[$column] = $this->normaliseColumn((string) $column, $value, $table, $row, $ids);
        }

        return $out;
    }

    /**
     * Walk a decoded response. An object's `id` is looked up in the table its
     * key names (`masjid` → masjids, `app_publishing` → masjid_app_publishing).
     */
    private function normaliseValue(mixed $value, ?string $table, array $ids): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $out[$key] = $this->normaliseValue($item, is_string($key) ? ($this->tableNamed($key) ?? $table) : $table, $ids);
            } else {
                $out[$key] = is_string($key)
                    ? $this->normaliseColumn($key, $item, $table, $value, $ids)
                    : $item;
            }
        }

        return $out;
    }

    private function normaliseColumn(string $column, mixed $value, ?string $table, array $row, array $ids): mixed
    {
        if ($value === null) {
            return null;
        }

        if (str_ends_with($column, '_at')) {
            return '<timestamp>';
        }

        if (in_array($column, ['password', 'remember_token', 'token'], true)) {
            return '<'.$column.'>';
        }

        if ($column === 'id' && $table !== null && (is_int($value) || is_string($value))) {
            return $this->label($ids, $table, $value);
        }

        $target = $this->foreignTable($column, $row);
        if ($target !== null && (is_int($value) || is_string($value))) {
            return $this->label($ids, $target, $value);
        }

        if (is_string($value) && ($plain = $this->decrypted($value)) !== null) {
            return '<encrypted>'.$plain;
        }

        return $value;
    }

    /** "table#n" for an integer key; a key of any other JSON type says which, since the map cannot. */
    private function label(array $ids, string $table, int|string $value): int|string
    {
        $label = $ids[$table][$value] ?? null;
        if ($label === null) {
            return $value;
        }

        return is_int($value) ? $label : $label.':'.get_debug_type($value);
    }

    private function foreignTable(string $column, array $row): ?string
    {
        if (in_array($column, ['created_by', 'updated_by', 'deleted_by'], true)) {
            return 'users';
        }

        if (! str_ends_with($column, '_id')) {
            return null;
        }

        $base = Str::beforeLast($column, '_id');

        // A morph pair: `model_id` is keyed by the class in `model_type`.
        if (isset($row[$base.'_type']) && is_string($row[$base.'_type'])) {
            $class = Relation::getMorphedModel($row[$base.'_type']) ?? $row[$base.'_type'];

            return class_exists($class) ? (new $class)->getTable() : null;
        }

        return match ($column) {
            'feature_id' => 'mobile_app_features',
            default => $this->tableNamed($base),
        };
    }

    private function tableNamed(string $name): ?string
    {
        $snake = Str::snake($name);
        foreach ([Str::plural($snake), $snake, 'masjid_'.Str::plural($snake), 'masjid_'.$snake] as $candidate) {
            if (Schema::hasTable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function decrypted(string $value): ?string
    {
        $decoded = json_decode((string) base64_decode($value, true), true);
        if (! is_array($decoded) || ! isset($decoded['iv'], $decoded['value'], $decoded['mac'])) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function payload(array $overrides): array
    {
        return array_merge([
            'name' => 'Snapshot Org',
            'email' => 'org@snapshot.example.test',
            'phone' => '+15551234567',
            'address' => '1 Test St',
            'latitude' => 43.32,
            'longitude' => -79.79,
            'timezone' => 'America/Toronto',
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'method' => 'MuslimWorldLeague',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
            'platforms' => ['web'],
        ], $overrides);
    }
}
