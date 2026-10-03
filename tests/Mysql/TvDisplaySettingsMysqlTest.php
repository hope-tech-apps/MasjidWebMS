<?php

/*
|--------------------------------------------------------------------------
| The TV display settings, on the engine production runs
|--------------------------------------------------------------------------
|
| tests/Feature/TvDisplaySettingsTest.php runs on SQLite, which cannot show three things this
| feature depends on in production:
|
|  - the WIDTH of the two text columns. SQLite's varchar has none, so a limit raised in
|    App\Support\TvBoard without a migration would pass every test and then fail in MySQL's
|    strict mode as a 500 on save;
|  - what a utf8mb4 column does with four-byte characters at the limit (the request counts
|    characters, and the column must hold as many);
|  - the three switches read back from tinyint(1). The tvOS decoder is strict: `1` where
|    `true` is required and the board keeps its old settings, silently.
|
| Runs only in CI's migrations-mysql job, as `pest --group=mysql`.
*/

use App\Models\Masjid;
use App\Models\User;
use App\Support\TvBoard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function tvOrganisationWithAdmin(): array
{
    $org = Masjid::create([
        'name' => 'TV MySQL Org ' . uniqid(),
        'org_type' => 'masjid',
        'email' => 'tv-' . uniqid() . '@example.test',
        'phone' => '+1555' . random_int(1000000, 9999999),
        'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
        'latitude' => 0.0, 'longitude' => 0.0,
    ]);
    $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
    $org->user_id = $admin->id;
    // The page is behind the `tv_display` grant, which is off for every organisation until it is given.
    $org->forceFill(['capability_overrides' => ['tv_display' => true]]);
    $org->save();

    return [$org, $admin];
}

it('gives the two texts columns at least as wide as the limits the request allows', function () {
    foreach (['header_title' => TvBoard::HEADER_TITLE_MAX, 'donate_caption' => TvBoard::DONATE_CAPTION_MAX] as $column => $limit) {
        $width = DB::selectOne(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS width FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'masjid_tv_settings' AND COLUMN_NAME = ?",
            [$column]
        )->width;

        expect((int) $width)->toBeGreaterThanOrEqual($limit, "{$column} is narrower than the {$limit} characters the request accepts");
    }
});

it('stores text at its limit in four-byte and two-byte characters and serves it back unchanged', function () {
    [$org, $admin] = tvOrganisationWithAdmin();
    Cache::flush();
    Sanctum::actingAs($admin);

    $title = str_repeat('🕌', TvBoard::HEADER_TITLE_MAX);
    $caption = str_repeat('م', TvBoard::DONATE_CAPTION_MAX);

    $this->postJson("/api/admin/masjids/{$org->id}/tv-display", ['header_title' => $title, 'donate_caption' => $caption])->assertOk();

    $board = $this->getJson("/api/mobile/masjids/{$org->id}/tv-config")->assertOk()->json('data');

    expect($board['header_title'])->toBe($title)
        ->and($board['donate_caption'])->toBe($caption);
});

it('serves the switches as JSON booleans and the interval as a JSON integer from their MySQL columns', function () {
    [$org, $admin] = tvOrganisationWithAdmin();
    Cache::flush();
    Sanctum::actingAs($admin);

    $this->postJson("/api/admin/masjids/{$org->id}/tv-display", [
        'is_enabled' => false, 'show_prayer_panel' => false, 'show_qr' => false, 'carousel_interval_seconds' => 45,
    ])->assertOk();

    $raw = $this->getJson("/api/mobile/masjids/{$org->id}/tv-config")->assertOk()->getContent();

    expect($raw)->toContain('"is_enabled":false')
        ->toContain('"show_prayer_panel":false')
        ->toContain('"show_qr":false')
        ->toContain('"carousel_interval_seconds":45');
});

it('holds one row per organisation and removes it with the organisation', function () {
    [$org] = tvOrganisationWithAdmin();
    DB::table('masjid_tv_settings')->insert(['masjid_id' => $org->id]);

    expect(fn () => DB::table('masjid_tv_settings')->insert(['masjid_id' => $org->id]))->toThrow(QueryException::class);

    DB::table('masjids')->where('id', $org->id)->delete();

    expect(DB::table('masjid_tv_settings')->where('masjid_id', $org->id)->count())->toBe(0);
});
