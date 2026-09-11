<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FormStaffCode's own guarantees: how a code is spelled, hashed, minted,
 * retired, and bound to one phone. Cross-tenant behaviour is pinned separately
 * in FormStaffCodeTenantIsolationTest.
 *
 * The code is a credential that decides whose cash a registration lands on, so
 * the properties here are security properties: the plaintext is never stored,
 * the digest is keyed, a mistyped code still matches (a miss costs the whole
 * venue a limiter hit), and "first press wins" holds against a stale copy of
 * the row, not just the one in hand.
 */
class FormStaffCodeModelTest extends TestCase
{
    use RefreshDatabase;

    private const CROCKFORD_CODE = '/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/';

    private Masjid $masjid;
    private Form $form;

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

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $this->form = Form::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => ['sections' => [['id' => 'main', 'title' => 'You', 'fields' => [
                ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
            ]]]],
            'settings' => ['fee' => ['amount' => 15], 'payment' => ['staffCodes' => true]],
            'is_active' => true,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    #[Test]
    public function normalise_forgives_what_people_mistype(): void
    {
        $cases = [
            ['K7QM-2XWD', 'K7QM2XWD'],
            ['k7qm-2xwd', 'K7QM2XWD'],
            [' K7QM 2XWD ', 'K7QM2XWD'],
            ["K7QM\u{2013}2XWD", 'K7QM2XWD'], // an en dash from a pasted message
            ["K7QM\u{00A0}2XWD", 'K7QM2XWD'], // a non-breaking space, likewise
            ['KOQM-2XWD', 'K0QM2XWD'],        // the letter O for zero
            ['K7QM-IXWD', 'K7QM1XWD'],        // I for one
            ['k7qm-lxwd', 'K7QM1XWD'],        // lower-case L for one
        ];

        foreach ($cases as [$typed, $normal]) {
            $this->assertSame($normal, FormStaffCode::normalise($typed), $typed);
            $this->assertSame(FormStaffCode::hashFor($normal), FormStaffCode::hashFor($typed), "{$typed} must hash like {$normal}");
        }
    }

    #[Test]
    public function the_stored_digest_is_keyed_on_the_app_key(): void
    {
        $this->assertSame(
            hash_hmac('sha256', 'K7QM2XWD', (string) config('app.key')),
            FormStaffCode::hashFor('k7qm-2xwd')
        );

        // A bare sha256 of eight characters falls to anyone holding the table.
        $this->assertNotSame(hash('sha256', 'K7QM2XWD'), FormStaffCode::hashFor('K7QM2XWD'));

        $before = FormStaffCode::hashFor('K7QM2XWD');
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        $this->assertNotSame($before, FormStaffCode::hashFor('K7QM2XWD'), 'the digest must depend on the key');
    }

    #[Test]
    public function generated_codes_are_crockford_and_already_in_their_hashed_spelling(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $plain = FormStaffCode::generate();

            $this->assertMatchesRegularExpression(self::CROCKFORD_CODE, $plain);
            $this->assertSame(
                str_replace('-', '', $plain),
                FormStaffCode::normalise($plain),
                "{$plain} contains a symbol normalise() would rewrite"
            );

            $seen[$plain] = true;
        }

        $this->assertCount(200, $seen, '200 draws from 1.1e12 codes should never repeat');
    }

    #[Test]
    public function issue_hands_back_the_plaintext_once_and_stores_only_its_digest(): void
    {
        $admin = $this->makeAdmin();

        [$code, $plain] = FormStaffCode::issue($this->form, 'Hamza Ali', now()->addDay(), $admin);

        $this->assertMatchesRegularExpression(self::CROCKFORD_CODE, $plain);

        $stored = (array) DB::table('form_staff_codes')->where('id', $code->id)->first();

        foreach ($stored as $column => $value) {
            $this->assertStringNotContainsString($plain, (string) $value, "form_staff_codes.{$column} holds the plaintext");
            $this->assertStringNotContainsString(FormStaffCode::normalise($plain), (string) $value, "form_staff_codes.{$column} holds the plaintext");
        }

        $this->assertSame(FormStaffCode::hashFor($plain), $stored['code_hash']);
        $this->assertSame(substr(str_replace('-', '', $plain), -2), $stored['code_hint']);
        $this->assertArrayNotHasKey('code_hash', $code->toArray(), 'the digest never serialises');

        $this->assertSame($this->masjid->id, (int) $code->masjid_id);
        $this->assertSame($admin->id, $code->created_by_user_id);
        $this->assertSame(0, $code->use_count);
        $this->assertTrue($code->isUsable());
    }

    #[Test]
    public function a_code_stops_working_when_revoked_or_at_its_expiry(): void
    {
        $expiry = now()->addHour();
        [$code] = FormStaffCode::issue($this->form, 'Hamza Ali', $expiry);

        $this->assertTrue($code->isUsable());
        $this->assertTrue($code->isUsable($expiry->copy()->subSecond()));
        $this->assertFalse($code->isUsable($expiry->copy()), 'expired AT the stored instant');
        $this->assertFalse($code->isUsable($expiry->copy()->addDay()));

        $this->assertTrue($code->revoke());
        $this->assertTrue($code->isRevoked());
        $this->assertFalse($code->isUsable());
    }

    #[Test]
    public function find_usable_answers_revoked_expired_and_unknown_codes_alike(): void
    {
        $on = ['form_id' => $this->form->id, 'masjid_id' => $this->masjid->id];

        FormStaffCode::factory()->withCode('AAAA-1111')->create($on);
        FormStaffCode::factory()->withCode('BBBB-2222')->revoked()->create($on);
        FormStaffCode::factory()->withCode('CCCC-3333')->expired()->create($on);

        $this->assertNotNull(FormStaffCode::findUsable($this->masjid->id, $this->form->id, 'aaaa 1111'));

        foreach (['BBBB-2222', 'CCCC-3333', 'DDDD-4444', '', '  --  '] as $refused) {
            $this->assertNull(FormStaffCode::findUsable($this->masjid->id, $this->form->id, $refused), "'{$refused}'");
        }
    }

    #[Test]
    public function revoking_is_recorded_by_the_first_press_only(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Hamza Ali', now()->addDay());
        $first = $this->makeAdmin();
        $second = $this->makeAdmin();

        // A colleague's screen, loaded before either press.
        $stale = FormStaffCode::find($code->id);

        $this->assertTrue($code->revoke($first));
        $revokedAt = $code->revoked_at->toDateTimeString();

        $this->travel(1)->minutes();

        $this->assertFalse($stale->revoke($second));
        $this->assertSame($first->id, $stale->revoked_by_user_id);
        $this->assertSame($revokedAt, $stale->revoked_at->toDateTimeString());
    }

    #[Test]
    public function the_first_device_to_use_a_code_owns_it_until_an_admin_releases_it(): void
    {
        [$code] = FormStaffCode::issue($this->form, 'Hamza Ali', now()->addDay());
        $stale = FormStaffCode::find($code->id);

        $this->assertTrue($code->bindToDevice('phone-a'));
        $this->assertTrue($code->bindToDevice('phone-a'), 'the owning phone keeps working');
        $this->assertNotNull($code->bound_at);
        $this->assertSame(1, $code->binding_count, 'one claim, however often its phone uses it');

        // Another phone is refused — even one holding a copy loaded before the claim.
        $this->assertFalse($code->bindToDevice('phone-b'));
        $this->assertFalse($stale->bindToDevice('phone-b'));
        $this->assertSame('phone-a', $code->fresh()->bound_device_id);

        $admin = $this->makeAdmin();
        $this->assertTrue($code->releaseDevice($admin));
        $this->assertNull($code->bound_device_id);
        $this->assertSame($admin->id, $code->binding_released_by_user_id);
        $this->assertNotNull($code->binding_released_at);

        // Nothing is bound now, so a second release records nothing.
        $this->assertFalse($stale->fresh()->releaseDevice($this->makeAdmin()));
        $this->assertSame($admin->id, $code->fresh()->binding_released_by_user_id);

        $this->assertTrue($code->bindToDevice('phone-b'));
        $this->assertSame(2, $code->binding_count, 'two phones have held it');
        $this->assertFalse($code->bindToDevice('   '), 'a blank id binds nothing');
        $this->assertFalse($code->bindToDevice(str_repeat('x', 256)), 'nor does one wider than the column');
        $this->assertSame('phone-b', $code->fresh()->bound_device_id);
    }
}
