<?php

use App\Enums\BroadcastChannel;
use App\Jobs\SendBroadcastJob;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Broadcast\BroadcastComposer;
use Illuminate\Support\Facades\DB;

// MySQL-only, guarded by tests/Pest.php. Not executed on the local SQLite runner.
// Fixture copied from tests/Mysql/TvDisplaySettingsMysqlTest.php (including user phone).
function interruptionMysqlOrganisation(): array
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
    $org->save();

    return [$org, $admin];
}

it('fits the terminal delivery states in the existing 24-character columns and stores nullable claim evidence', function () {
    foreach (['broadcasts', 'broadcast_deliveries'] as $table) {
        $column = DB::selectOne('SELECT CHARACTER_MAXIMUM_LENGTH AS width FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, 'status']);
        expect((int) $column->width)->toBe(24);
    }
    foreach (['sending_started_at', 'send_claim_token', 'send_recovered_at'] as $name) {
        $column = DB::selectOne('SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['broadcasts', $name]);
        expect($column)->not->toBeNull()->and($column->nullable)->toBe('YES');
    }
});

it('takes the parent and outcome locking reads before terminal settlement in strict mode', function () {
    [$org, $admin] = interruptionMysqlOrganisation();
    $broadcast = app(BroadcastComposer::class)->compose($org, [
        'title' => 'Test notice', 'body' => 'Test body',
    ], [BroadcastChannel::ANNOUNCEMENT, BroadcastChannel::EMAIL], authorId: $admin->id);
    $job = new SendBroadcastJob($broadcast->id);
    $directory = storage_path('framework/broadcast-send-locks');
    if (! is_dir($directory)) mkdir($directory, 0775, true);
    touch($directory . '/' . $broadcast->id . '.lock');
    $broadcast->forceFill(['status' => 'sending', 'send_claim_token' => $job->claimToken, 'sending_started_at' => now()])->save();
    $broadcast->deliveries()->where('channel', 'announcement')->update(['status' => 'sending']);
    $lockingReads = [];
    DB::listen(function ($query) use (&$lockingReads) {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains(strtolower($query->sql), 'for update')) {
            $lockingReads[] = $query->sql;
        }
    });
    $job->failed(null);
    expect($broadcast->fresh()->status)->toBe('interrupted')
        ->and($broadcast->deliveries()->orderBy('id')->pluck('status')->all())->toBe(['interrupted', 'not_sent'])
        ->and($lockingReads)->toHaveCount(2)
        ->and($lockingReads[0])->toContain('`broadcasts`')
        ->and($lockingReads[1])->toContain('`broadcast_deliveries`');
});
