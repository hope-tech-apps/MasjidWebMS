<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\Contact;
use App\Models\ContactLoginCode;
use App\Models\Masjid;
use App\Services\Family\FamilyLoginService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * The parent portal's sign-in matches the address EXACTLY, not the way the
 * collation does.
 *
 * `contacts.login_email` is `utf8mb4_unicode_ci` on production (read there
 * 2026-09-29), so `LOWER(login_email) = 'victim@gmail.com'` also returns the
 * contact stored under `victim@gmaíl.com`. FamilyLoginService::resolveContact()
 * is shared by the code door and the password door (FamilyPasswordService
 * borrows it), and the password door trusts what it returns.
 *
 * The premise is built the way MemberSignInLookAlikeAddressTest builds it: the
 * other contact's address is stored in its ACCENTED form, the plain one is
 * submitted, and the connection's `LOWER()` folds accents as MySQL does. Each
 * test asserts that the raw query returns the contact before it asserts the
 * door refuses it.
 *
 * The code door mails `$contact->login_email`, never the typed address, so it
 * could not be tricked into mailing a stranger. It is covered anyway, for the
 * resolver it shares: a look-alike must not be sent a code, must not redeem
 * one, and must not make the real address ambiguous.
 */
class FamilySignInLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the other contact holds: the accented form. */
    private const STORED = 'parent@gmaíl.com';

    /** What is submitted: the plain one. */
    private const TYPED = 'parent@gmail.com';

    private const GOOD = 'jasmine-lantern-42-quiet';

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->foldAccentsLikeUnicodeCi();
        $this->masjid = $this->makeMasjid();
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- code door

    #[Test]
    public function a_look_alike_address_is_not_sent_a_code(): void
    {
        $other = $this->parent(self::STORED, self::GOOD);
        $this->assertTheSqlStillFinds($other);

        $this->requestCode(self::TYPED)->assertStatus(202);

        // Before the fix the contact was found and a code went to its stored
        // address. Nobody is sent anything, and nothing is written.
        Mail::assertNothingSent();
        $this->assertSame(0, ContactLoginCode::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_look_alike_address_cannot_redeem_a_code_that_belongs_to_another_contact(): void
    {
        $other = $this->parent(self::STORED, self::GOOD);
        $this->assertTheSqlStillFinds($other);

        // A live code for the OTHER contact, as if it had been mailed to them.
        $code = $this->mintCode($other);
        $before = $this->stored($other);

        $response = $this->verify(self::TYPED, $code);

        $response->assertStatus(410);
        $this->assertNull($response->json('data.token'));
        $this->assertSame($before, $this->stored($other), 'A refused redeem wrote to the contact.');
        $this->assertSame([], $other->tokens()->pluck('id')->all());

        $row = ContactLoginCode::withoutMasjidScope()->where('contact_id', $other->id)->sole();
        $this->assertNull($row->consumed_at, 'The other contact\'s code was spent.');
    }

    // ------------------------------------------------------------ password door

    #[Test]
    public function the_password_door_refuses_a_look_alike_address(): void
    {
        $other = $this->parent(self::STORED, self::GOOD);
        $this->assertTheSqlStillFinds($other);
        $before = $this->stored($other);

        $response = $this->signIn(self::TYPED, self::GOOD);

        // The RIGHT password, at an address that is not theirs.
        $response->assertStatus(410);
        $this->assertNull($response->json('data.token'));
        $this->assertSame($before, $this->stored($other), 'A refused sign-in wrote to the contact (last_login_at).');
        $this->assertSame([], $other->tokens()->pluck('id')->all());
    }

    #[Test]
    public function the_shared_resolver_returns_nobody_for_a_look_alike(): void
    {
        $other = $this->parent(self::STORED, self::GOOD);
        $this->assertTheSqlStillFinds($other);

        app(TenantContext::class)->set($this->masjid->id);

        $this->assertNull(app(FamilyLoginService::class)->resolveContact(self::TYPED));
    }

    // ------------------------------------------------------- ambiguity, counted

    #[Test]
    public function a_look_alike_contact_does_not_make_the_real_address_ambiguous(): void
    {
        $real = $this->parent(self::TYPED, self::GOOD);
        $lookAlike = $this->parent(self::STORED, self::GOOD);
        $this->assertSame(
            [$real->id, $lookAlike->id],
            Contact::withoutMasjidScope()->whereRaw('LOWER(login_email) = ?', [self::TYPED])->orderBy('id')->pluck('id')->all(),
            'PREMISE: both contacts match the typed address through the collation.',
        );

        // Both doors sign the REAL parent in. Counted through the collation they
        // were "two rows", which resolved to nobody and locked the real parent out.
        $this->requestCode(self::TYPED)->assertStatus(202);
        Mail::assertSent(FamilyLoginCodeMail::class, 1);
        Mail::assertSent(FamilyLoginCodeMail::class, fn (FamilyLoginCodeMail $mail) => $mail->hasTo(self::TYPED));

        $viaPassword = $this->signIn(self::TYPED, self::GOOD);

        $viaPassword->assertOk();
        $this->assertSame($real->id, $viaPassword->json('data.contact.id'));
    }

    // ------------------------------------------------- what must still sign in

    #[Test]
    public function an_address_that_differs_only_in_case_still_signs_in_by_code_and_by_password(): void
    {
        $parent = $this->parent('Parent@Gmail.com', self::GOOD);

        $this->requestCode('PARENT@gmail.COM')->assertStatus(202);

        // The code goes to the address the office holds, as it always has.
        $code = Mail::sent(
            FamilyLoginCodeMail::class,
            fn (FamilyLoginCodeMail $mail) => $mail->hasTo('Parent@Gmail.com'),
        )->last()?->code;
        $this->assertNotNull($code, 'No code was mailed to the stored address.');

        $viaCode = $this->verify('PARENT@gmail.COM', $code);
        $viaCode->assertOk();
        $this->assertSame($parent->id, $viaCode->json('data.contact.id'));

        $viaPassword = $this->signIn('pArEnT@GMAIL.com', self::GOOD);
        $viaPassword->assertOk();
        $this->assertSame($parent->id, $viaPassword->json('data.contact.id'));
    }

    #[Test]
    public function an_address_with_surrounding_spaces_still_resolves_to_the_contact(): void
    {
        $parent = $this->parent(self::TYPED, self::GOOD);

        // Direct to the service: the HTTP stack trims before the service sees it.
        app(TenantContext::class)->set($this->masjid->id);

        $this->assertSame(
            $parent->id,
            app(FamilyLoginService::class)->resolveContact("  Parent@Gmail.com \n")?->id,
        );
    }

    // ---------------------------------------------------------------- helpers

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    /**
     * A parent whose family login is on, at `$loginEmail`, with a password.
     * `forceFill` because the `login_*` columns are deliberately not fillable.
     */
    private function parent(string $loginEmail, string $password): Contact
    {
        $this->asANewRequest();

        $contact = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'email' => 'roster-' . uniqid() . '@test.local',
        ]);

        $contact->forceFill([
            'login_email' => $loginEmail,
            'login_enabled_at' => now(),
            'verified_at' => now(),
            'password' => Hash::make($password),
            'password_set_at' => now(),
        ])->save();

        return $contact->refresh();
    }

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /**
     * The raw attributes of a contact as the database holds them now.
     *
     * @return array<string, mixed>
     */
    private function stored(Contact $contact): array
    {
        return Contact::withoutMasjidScope()->withTrashed()->whereKey($contact->getKey())->firstOrFail()->getAttributes();
    }

    /** PREMISE: the collation-equal query returns the other contact, as MySQL would. */
    private function assertTheSqlStillFinds(Contact $other): void
    {
        $this->assertSame(
            [$other->id],
            Contact::withoutMasjidScope()->whereRaw('LOWER(login_email) = ?', [self::TYPED])->pluck('id')->all(),
            'PREMISE: LOWER(login_email) = the typed address must return the other contact, or this test proves nothing.',
        );
    }

    private function requestCode(string $typed): TestResponse
    {
        $this->asANewRequest();

        $response = $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/request-code", ['email' => $typed]);

        $this->asANewRequest();

        return $response;
    }

    private function verify(string $typed, string $code): TestResponse
    {
        $this->asANewRequest();

        $response = $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/verify-code", [
            'email' => $typed,
            'code' => $code,
        ]);

        $this->asANewRequest();

        return $response;
    }

    private function signIn(string $typed, string $password): TestResponse
    {
        $this->asANewRequest();

        $response = $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/password", [
            'email' => $typed,
            'password' => $password,
        ]);

        $this->asANewRequest();

        return $response;
    }

    /** A live code row for `$contact`, written directly, and its plaintext. */
    private function mintCode(Contact $contact): string
    {
        $code = '482915';

        $service = app(FamilyLoginService::class);
        $hash = (new \ReflectionMethod($service, 'hash'))->invoke($service, $code);

        ContactLoginCode::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'contact_id' => $contact->id,
            'code_hash' => $hash,
            'channel' => ContactLoginCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        return $code;
    }
}
