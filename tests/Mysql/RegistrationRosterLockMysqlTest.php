<?php

/*
|--------------------------------------------------------------------------
| The registration's roster lock, on the engine production runs
|--------------------------------------------------------------------------
|
| RegistrationService::lockRosterSubjects() holds each child's contact row while a
| confirmed registration is put on a class roster, so a class move of the same child
| cannot run between the "already there?" check and the insert.
|
| tests/Feature/RegistrationServiceTest.php pins the statement's place and its order
| on SQLite, but SQLite prints no lock clause at all. Here the statement must be the
| locking read it claims to be: `FOR UPDATE`, by primary key, ids ascending.
|
| What this file cannot show: that another connection WAITS on it. Every test here
| runs inside RefreshDatabase's transaction, and a row this transaction inserted
| carries no visible record lock (tests/Mysql/ShopCheckoutLocksMysqlTest.php says
| why). That is shown by hand, with a second session, on staging.
|
| Runs only in CI's migrations-mysql job, as `pest --group=mysql`.
*/

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\Offering;
use App\Services\Registrations\RegistrationService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

it('locks the children\'s contact rows FOR UPDATE, by primary key and in id order, before it reads the roster', function () {
    // The public register path runs unbound.
    app(TenantContext::class)->forgetTenant();

    $org = Masjid::create([
        'name' => 'Roster Lock Org ' . uniqid(),
        'email' => 'roster-lock-' . uniqid() . '@example.test',
        'phone' => '+1555' . random_int(1000000, 9999999),
        'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
        'latitude' => 0.0, 'longitude' => 0.0,
    ]);
    $group = Group::factory()->create(['masjid_id' => $org->id]);
    $offering = Offering::factory()->forMasjid($org)->withRoster($group)->withCapacity(10)->create();
    $plan = FeePlan::factory()->free()->create(['masjid_id' => $org->id, 'offering_id' => $offering->id]);

    $guardian = Contact::factory()->create(['masjid_id' => $org->id]);
    $childA = Contact::factory()->create(['masjid_id' => $org->id]);
    $childB = Contact::factory()->create(['masjid_id' => $org->id]);

    $seen = [];
    DB::listen(function ($query) use (&$seen): void {
        $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    // Named in descending id order; the lock must still be ascending.
    app(RegistrationService::class)->register($offering, $plan, $guardian, ['full_name' => 'Amal Yusuf'], [$childB, $childA]);

    $locks = array_keys(array_filter(
        $seen,
        fn ($q) => preg_match('/^select `id` from `contacts` where `id` in \(\?, \?\) order by `id` asc for update$/', $q['sql']) === 1
    ));

    expect($locks)->toHaveCount(1, 'one FOR UPDATE statement locks every child');
    expect(array_map('intval', $seen[$locks[0]]['bindings']))->toBe([$childA->id, $childB->id]);

    $rosterTouches = array_keys(array_filter($seen, fn ($q) => str_contains($q['sql'], '`group_memberships`')));
    expect($rosterTouches)->not->toBeEmpty();
    expect($locks[0])->toBeLessThan(min($rosterTouches));

    // The roster checks themselves stay ordinary reads: a locking read on the roster's index is a
    // range lock, and two families registering different children into one class would deadlock.
    foreach ($rosterTouches as $at) {
        expect($seen[$at]['sql'])->not->toContain('for update')->not->toContain('for share')->not->toContain('lock in share mode');
    }
});
