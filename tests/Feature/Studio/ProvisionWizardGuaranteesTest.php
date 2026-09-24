<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Auth\AccountAccessService;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * What the wizard's provision endpoint promises beyond the rows it writes.
 *
 * ProvisionResponseSnapshotTest pins what a provision produces for the payloads
 * it sends; these are the promises no single recording can show. Who may call
 * it at all (OrganisationProvisioner checks no authority of its own, so the
 * route's `super` middleware is the only gate), what an invited administrator's
 * stored credential is, which of two owner fields wins, which store credentials
 * are kept, and when an invitation is allowed to leave.
 */
class ProvisionWizardGuaranteesTest extends TestCase
{
    use RefreshDatabase;

    private const PROVISION = '/api/admin/onboarding/provision';

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
        $this->cityId = DB::table('cities')->insertGetId(['name' => 'Burlington', 'country_id' => $this->countryId]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        Mail::fake();
    }

    #[Test]
    public function an_organisation_admin_can_neither_provision_an_organisation_nor_read_the_wizards_options(): void
    {
        // An admin in good standing of their own organisation, so the outer
        // group (auth, admin, tenant) lets them through and only `super` stands
        // between them and creating tenants.
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550001234']);
        $own = Masjid::create([
            'name' => 'Their Own Masjid',
            'email' => 'own@guarantees.example.test',
            'phone' => '+15550005555',
            'address' => '2 Test St',
            'latitude' => 43.33,
            'longitude' => -79.8,
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'user_id' => $admin->id,
        ]);
        MasjidUser::create(['masjid_id' => $own->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);

        Sanctum::actingAs($admin->fresh());

        $this->postJson(self::PROVISION, $this->payload('Uninvited Org') + ['admin' => ['email' => 'office@uninvited.example.test']])
            ->assertStatus(401)
            ->assertExactJson(['status' => 'failed', 'data' => 'Unauthorized.']);
        $this->getJson('/api/admin/onboarding/options')
            ->assertStatus(401)
            ->assertExactJson(['status' => 'failed', 'data' => 'Unauthorized.']);

        $this->assertSame(1, Masjid::count(), 'no tenant was created');
        $this->assertFalse(User::where('email', 'office@uninvited.example.test')->exists(), 'no account was created');
        Mail::assertNothingSent();

        // The premise: the same payload is one a SuperAdmin may send, so the 401
        // above is the gate and not the payload.
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550009999'])->fresh());
        $this->postJson(self::PROVISION, $this->payload('Uninvited Org'))->assertCreated();
    }

    #[Test]
    public function an_invited_administrator_is_created_with_a_credential_nobody_can_guess(): void
    {
        $this->actingAsOperator();

        $this->postJson(self::PROVISION, $this->payload('Credential Org') + [
            'admin' => ['name' => 'Credential Office', 'email' => 'office@credential.example.test', 'phone' => '+15550006666'],
        ])->assertCreated();

        $stored = User::where('email', 'office@credential.example.test')->firstOrFail()->password;

        $this->assertNotSame('unknown', Hash::info($stored)['algoName'], 'the credential is stored hashed');

        // The admin sets their own password from the emailed link; until then
        // nothing anyone at Manara could type, or read off the org's own
        // details, may open the account.
        foreach (['', 'password', 'secret', '12345678', 'Credential Office', 'office@credential.example.test',
            '+15550006666', 'Credential Org', 'org@credential-org.example.test', 'Credential Org Administrator'] as $guess) {
            $this->assertFalse(Hash::check($guess, $stored), "The invited administrator's password is '{$guess}'.");
        }
    }

    #[Test]
    public function a_named_owner_keeps_the_organisation_when_an_administrator_address_is_sent_alongside(): void
    {
        $owner = User::factory()->create(['type' => 'MasjidAdmin', 'email' => 'owner@named.example.test', 'phone' => '+15550007777']);
        $this->actingAsOperator();

        $id = $this->postJson(self::PROVISION, $this->payload('Named Owner Org') + [
            'user_id' => $owner->id,
            'admin' => ['email' => 'office@displaced.example.test'],
        ])->assertCreated()->json('data.masjid_id');

        $this->assertSame($owner->id, (int) Masjid::findOrFail($id)->user_id);
        $this->assertSame([$owner->id], MasjidUser::where('masjid_id', $id)->pluck('user_id')->map(fn ($u) => (int) $u)->all());
        $this->assertFalse(User::where('email', 'office@displaced.example.test')->exists(), 'no second account was created');
        Mail::assertNothingSent();
    }

    #[Test]
    public function store_credentials_are_kept_only_for_a_platform_that_was_selected(): void
    {
        $this->actingAsOperator();

        // iOS left in BYO mode with its key filled in, but not selected.
        $ios = $this->postJson(self::PROVISION, $this->payload('No iOS Org') + [
            'platforms' => ['web', 'android'],
            'apps' => ['ios' => [
                'account_mode' => 'byo',
                'asc_key_p8' => "-----BEGIN PRIVATE KEY-----\nMIGT\n-----END PRIVATE KEY-----",
                'asc_key_id' => 'ABC123DEFG',
                'asc_issuer_id' => '69a6de70-0000-0000-0000-000000000000',
            ]],
        ])->assertCreated()->assertJsonPath('data.app_publishing.has_asc_key', false);

        $row = DB::table('masjid_app_publishing')->where('masjid_id', $ios->json('data.masjid_id'))->first();
        $this->assertNull($row->asc_key_p8);
        $this->assertNull($row->asc_key_id);
        $this->assertNull($row->asc_issuer_id);

        // And the mirror: Android in BYO mode with its service account, not selected.
        $android = $this->postJson(self::PROVISION, $this->payload('No Android Org') + [
            'platforms' => ['web', 'ios'],
            'apps' => ['android' => [
                'account_mode' => 'byo',
                'play_service_account_json' => '{"type":"service_account","project_id":"guarantees"}',
            ]],
        ])->assertCreated()->assertJsonPath('data.app_publishing.has_play_service_account', false);

        $this->assertNull(DB::table('masjid_app_publishing')->where('masjid_id', $android->json('data.masjid_id'))->value('play_service_account_json'));
    }

    #[Test]
    public function no_invitation_is_sent_for_a_provision_that_fails_after_the_administrator_was_created(): void
    {
        $this->actingAsOperator();

        // Fail at the last write, the owner's membership, which comes after the
        // administrator account is created and its invitation collected. A
        // failure any earlier would leave nothing to send and prove nothing.
        $adminExistedAtFailure = false;
        MasjidUser::creating(function () use (&$adminExistedAtFailure) {
            $adminExistedAtFailure = User::where('email', 'office@rolledback.example.test')->exists();

            throw new RuntimeException('Injected failure after the administrator was created.');
        });

        config(['app.debug' => false]);
        $this->postJson(self::PROVISION, $this->payload('Rolled Back Org') + ['admin' => ['email' => 'office@rolledback.example.test']])
            ->assertStatus(500)
            ->assertJsonPath('status', 'error');

        $this->assertTrue($adminExistedAtFailure, 'the premise: the failure came after the administrator was created');
        $this->assertSame(0, Masjid::count());
        $this->assertFalse(User::where('email', 'office@rolledback.example.test')->exists());
        $this->assertSame(0, DB::table('account_invite_tokens')->count(), 'no invite link was minted');
        Mail::assertNothingSent();
    }

    #[Test]
    public function an_invitation_is_sent_only_once_the_provision_has_committed(): void
    {
        $this->actingAsOperator();

        // RefreshDatabase holds a transaction of its own open around the test,
        // so "committed" is back at this level, not at zero.
        $committed = DB::transactionLevel();
        $levels = new ArrayObject;
        $this->app->instance(AccountAccessService::class, new class($levels) extends AccountAccessService
        {
            public function __construct(private ArrayObject $levels) {}

            public function invite(User $user, ?string $orgName = null): bool
            {
                $this->levels->append(DB::transactionLevel());

                return parent::invite($user, $orgName);
            }
        });

        $this->postJson(self::PROVISION, $this->payload('Committed Org') + ['admin' => ['email' => 'office@committed.example.test']])
            ->assertCreated();

        $this->assertSame([$committed], $levels->getArrayCopy(), 'the invitation went out while the provision could still roll back');
        Mail::assertSentCount(1);
    }

    private function actingAsOperator(): void
    {
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550009999'])->fresh());
    }

    /** A valid wizard payload; its email and phone follow the name, because `masjids` holds both unique. */
    private function payload(string $name): array
    {
        return [
            'name' => $name,
            'email' => 'org@'.Str::slug($name).'.example.test',
            'phone' => '+1555'.str_pad((string) (crc32($name) % 10_000_000), 7, '0', STR_PAD_LEFT),
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
        ];
    }
}
