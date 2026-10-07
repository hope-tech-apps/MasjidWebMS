<?php

// SQLite omits FOR UPDATE. CI's mysql group pins the actual locked read; this does not
// prove two concurrent sessions. Fixtures follow FormResponseDeleteLockMysqlTest.php.
use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('re-reads the tenant-scoped row FOR UPDATE before deciding whether to restore', function (string $area, string $model) {
    $org = Masjid::create([
        'name' => 'Delete Lock MySQL Org ' . uniqid(),
        'email' => 'delete-lock-' . uniqid() . '@example.test',
        'phone' => '+1555' . random_int(1000000, 9999999),
        'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
        'latitude' => 0.0, 'longitude' => 0.0,
    ]);
    Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]));
    $row = $model::create([
        'masjid_id' => $org->id, 'title' => 'Test item', 'summary' => 'Summary',
        'description' => 'Description', 'details' => 'Details', 'text' => 'Text',
    ]);
    $row->delete();
    $reads = [];
    DB::listen(function ($query) use (&$reads, $area): void {
        if (str_starts_with($query->sql, "select * from `{$area}`")) {
            $reads[] = $query;
        }
    });
    $url = "/api/admin/masjids/{$org->id}/{$area}/{$row->id}/restore";
    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertOk();
    expect($reads)->toHaveCount(2);
    foreach ($reads as $query) {
        expect($query->sql)->toContain('`masjid_id` = ?', "`{$area}`.`id` = ?", 'for update')
            ->not->toContain('`deleted_at` is null');
        expect(array_map('intval', $query->bindings))->toBe([$org->id, $row->id]);
    }
    expect($row->fresh()->trashed())->toBeFalse();
})->with([['services', Service::class], ['announcements', Announcement::class]]);
