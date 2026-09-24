<?php

namespace Tests\Feature\Studio;

use App\Http\Requests\Admin\Onboarding\ProvisionMasjidRequest;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Studio\OrganisationProvisioner;
use App\Support\Studio\ProvisionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The provisioning body on its own, away from the wizard's endpoint: it runs only
 * inside its caller's transaction, and it leaves everything a rollback cannot
 * undo to that caller. What it writes is pinned through the endpoint by
 * ProvisionResponseSnapshotTest.
 */
class OrganisationProvisionerTest extends TestCase
{
    use RefreshDatabase;

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
    }

    #[Test]
    public function refuses_to_run_outside_a_transaction(): void
    {
        // RefreshDatabase runs every test inside a transaction of its own, which
        // would satisfy the guard. Step out of it for the call and back in
        // afterwards, so the trait still has a transaction to roll back.
        DB::rollBack();

        try {
            $this->assertSame(0, DB::transactionLevel(), 'the premise: nothing is open');

            $invitations = [];

            try {
                app(OrganisationProvisioner::class)->create(
                    ProvisionMasjidRequest::create('/api/admin/onboarding/provision', 'POST', $this->payload(1, 1)),
                    $invitations,
                    new ProvisionContext(null),
                );
                $this->fail('Provisioning ran with no transaction to roll it back.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('inside a database transaction', $e->getMessage());
            }

            $this->assertSame(0, Masjid::count(), 'refused before writing anything');
            $this->assertSame([], $invitations);
        } finally {
            DB::beginTransaction();
        }
    }

    #[Test]
    public function collects_the_invitation_for_the_caller_and_sends_nothing_itself(): void
    {
        Mail::fake();

        $countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $cityId = DB::table('cities')->insertGetId(['name' => 'Burlington', 'country_id' => $countryId]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $request = ProvisionMasjidRequest::create('/api/admin/onboarding/provision', 'POST', $this->payload($countryId, $cityId) + [
            'admin' => ['email' => 'office@provisioner.example.test'],
        ]);
        $request->headers->set('Accept', 'application/json');
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $invitations = [];
        $masjid = DB::transaction(function () use ($request, &$invitations) {
            return app(OrganisationProvisioner::class)->create($request, $invitations, new ProvisionContext(null));
        });

        $admin = User::where('email', 'office@provisioner.example.test')->firstOrFail();
        $this->assertSame($admin->id, $masjid->user_id);

        // An email cannot be recalled by a rollback, so the body only lists it.
        $this->assertCount(1, $invitations);
        $this->assertTrue($invitations[0][0]->is($admin));
        $this->assertSame('Provisioner Org', $invitations[0][1]);
        Mail::assertNothingSent();
    }

    private function payload(int $countryId, int $cityId): array
    {
        return [
            'org_type' => 'masjid',
            'name' => 'Provisioner Org',
            'email' => 'org@provisioner.example.test',
            'phone' => '+15551234567',
            'address' => '1 Test St',
            'latitude' => 43.32,
            'longitude' => -79.79,
            'timezone' => 'America/Toronto',
            'country_id' => $countryId,
            'city_id' => $cityId,
            'method' => 'MuslimWorldLeague',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
            'platforms' => ['web'],
        ];
    }
}
