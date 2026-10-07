<?php

use App\Models\Donation;
use App\Models\Fund;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Support\SeedsDonationSubscriptions;

uses(BuildsBaskets::class, SeedsDonationSubscriptions::class);

it('keeps exactly the two restrictive foreign keys to funds as the backstop', function () {
    $keys = DB::select("SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c, r.DELETE_RULE AS d
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
         AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'funds'
        ORDER BY k.TABLE_NAME, k.COLUMN_NAME");
    // MySQL reports a foreign key declared without an action as NO ACTION, which it
    // enforces exactly as RESTRICT; either is the backstop this test is here to keep.
    expect(array_map(fn ($key) => [$key->t, $key->c, in_array($key->d, ['RESTRICT', 'NO ACTION'], true) ? 'restrictive' : $key->d], $keys))->toBe([
        ['donation_subscriptions', 'fund_id', 'restrictive'],
        ['donations', 'fund_id', 'restrictive'],
    ]);
});

it('refuses a raw delete with a gift reference even when the controller is bypassed', function (string $table) {
    $org = $this->org();
    $fund = $this->fund($org);
    if ($table === 'donations') {
        Donation::factory()->create(['masjid_id' => $org->id, 'fund_id' => $fund->id]);
    } else {
        $this->seedDonationSubscription($org, $fund, ['status' => 'canceled']);
    }

    try {
        DB::table('funds')->where('id', $fund->id)->delete();
        $this->fail('The restrictive fund FK must remain in place.');
    } catch (QueryException $e) {
        expect((int) $e->errorInfo[1])->toBe(1451);
    }
    expect(Fund::find($fund->id))->not->toBeNull();
})->with(['donations', 'donation_subscriptions']);

it('takes a primary key FOR UPDATE lock on deletion before checking references', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $org = $this->org(['crm_enabled' => true]);
    $fund = $this->fund($org);
    // users.phone has no default on MySQL; SQLite does not mind.
    Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '5550100000']));
    $level = DB::transactionLevel();
    $inside = [];
    DB::listen(function ($query) use (&$inside, $level): void {
        if (DB::transactionLevel() > $level) {
            $inside[] = $query->sql;
        }
    });

    $this->deleteJson("/api/admin/masjids/{$org->id}/funds/{$fund->id}")->assertOk();
    expect($inside[0])->toBe('select * from `funds` where `funds`.`id` = ? and `funds`.`masjid_id` = ? limit 1 for update');
});
