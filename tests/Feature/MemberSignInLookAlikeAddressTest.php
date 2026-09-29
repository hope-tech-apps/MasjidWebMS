<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Member\MemberSignupService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * Sign-in to the app matches the address EXACTLY, not the way the collation does.
 *
 * THE DEFECT (verified on production, read-only, 2026-09-29). `contacts.login_email`,
 * `contacts.email` and `app_signup_codes.email` are `utf8mb4_unicode_ci`, where
 * `'victim@gmail.com' = 'victim@gmaíl.com'` is TRUE. `resolveContact()` looked a
 * contact up with `LOWER(login_email) = ?`, and `deliver()` mails the code to the
 * address that was TYPED. Whoever owned a look-alike domain got a code, and
 * redeeming it linked them to the victim's contact: with a password in the
 * request, that SET the victim's password and ended their sessions.
 *
 * HOW THESE TESTS REACH IT ON SQLITE. SQLite compares bytes, so the premise has
 * to be built (Tests\Support\FoldsAccentsLikeUnicodeCi). The other contact's
 * address is stored in its ACCENTED form and the plain one is submitted, and the
 * connection's `LOWER()` is overridden to fold accents: the SQL now returns that
 * contact for the plain address, as MySQL would, and only the PHP re-check can
 * refuse it. Every test asserts that premise first, because a test whose SQL
 * never returned the look-alike would pass against the unfixed code.
 *
 *  - `STORED` is what the other contact holds. `TYPED` is what is submitted. The
 *    collation is symmetric, so which one carries the accent does not matter to
 *    the database.
 */
class MemberSignInLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the other contact holds: the accented form. */
    private const STORED = 'person@gmaíl.com';

    /** What is submitted: the plain one. */
    private const TYPED = 'person@gmail.com';

    /** verify-code's refusal, verbatim. Every refused redeem answers exactly this. */
    private const GONE = '{"status":"error","message":"That code is no longer usable. Please request a new one.","data":{}}';

    private const THEIRS = 'jasmine-lantern-42-quiet';

    private const MINE = 'copper-kettle-19-morning';

    private Masjid $masjid;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->foldAccentsLikeUnicodeCi();
        $this->masjid = $this->makeMasjid();

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    // ------------------------------------------------------- the login_email arm

    #[Test]
    public function a_code_for_a_look_alike_address_does_not_link_the_contact_holding_the_login_email(): void
    {
        $other = $this->contact(self::STORED, $this->office(), self::THEIRS);
        $other->createMemberToken();
        $this->assertTheSqlStillFinds('login_email', $other);

        $before = $this->stored($other);
        $tokensBefore = $other->tokens()->pluck('id')->all();

        $code = $this->requestCode(self::TYPED);
        $response = $this->verify(self::TYPED, $code, [
            'first_name' => 'Nobody',
            'last_name' => 'Special',
            'password' => self::MINE,
        ]);

        // The look-alike is a different address: it gets a contact of ITS OWN,
        // and never the other person's.
        $response->assertOk()->assertJsonPath('data.created', true);
        $this->assertNotSame($other->id, $response->json('data.contact.id'));
        $this->assertSame(self::TYPED, $response->json('data.contact.login_email'));

        $this->assertNothingHappenedTo($other, $before, $tokensBefore);
        $this->assertTrue(Hash::check(self::THEIRS, $this->stored($other)['password']), 'Their password was replaced.');
        $this->assertFalse(Hash::check(self::MINE, $this->stored($other)['password']));
    }

    #[Test]
    public function a_code_for_a_look_alike_address_does_not_link_the_contact_holding_the_office_email(): void
    {
        // The `email` arm: a contact the office has on file, with no login address.
        $other = $this->contact(null, self::STORED);
        $this->assertTheSqlStillFinds('email', $other);

        $before = $this->stored($other);
        $tokensBefore = $other->tokens()->pluck('id')->all();

        $code = $this->requestCode(self::TYPED);
        $response = $this->verify(self::TYPED, $code, [
            'first_name' => 'Nobody',
            'last_name' => 'Special',
            'password' => self::MINE,
        ]);

        $response->assertOk()->assertJsonPath('data.created', true);
        $this->assertNotSame($other->id, $response->json('data.contact.id'));

        // Linking would have adopted the typed address as THEIR login, marked
        // them verified and given them this password.
        $this->assertNothingHappenedTo($other, $before, $tokensBefore);
        $this->assertNull($this->stored($other)['login_email']);
        $this->assertNull($this->stored($other)['verified_at']);
    }

    #[Test]
    public function a_look_alike_contact_does_not_make_the_real_address_ambiguous_or_stand_in_for_it(): void
    {
        // Two contacts, two different addresses: the real one and its look-alike.
        // Counted through the collation they were "two rows", and "two rows is no
        // row" locked the real owner out. Counted as addresses, there is one.
        $real = $this->contact(self::TYPED, $this->office(), self::THEIRS);
        $lookAlike = $this->contact(self::STORED, $this->office(), self::MINE);
        $this->assertSame(
            [$real->id, $lookAlike->id],
            Contact::withoutMasjidScope()->whereRaw('LOWER(login_email) = ?', [self::TYPED])->orderBy('id')->pluck('id')->all(),
            'PREMISE: both contacts match the typed address through the collation.',
        );

        $lookAlikeBefore = $this->stored($lookAlike);

        $code = $this->requestCode(self::TYPED);
        $viaCode = $this->verify(self::TYPED, $code);

        $viaCode->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame($real->id, $viaCode->json('data.contact.id'));

        $viaPassword = $this->signIn(self::TYPED, self::THEIRS);

        $viaPassword->assertOk();
        $this->assertSame($real->id, $viaPassword->json('data.contact.id'));

        $this->assertSame($lookAlikeBefore, $this->stored($lookAlike), 'The look-alike contact was written to.');
    }

    // ---------------------------------------------------- the reverse: the code row

    #[Test]
    public function a_code_issued_to_a_look_alike_address_cannot_be_redeemed_at_the_real_one(): void
    {
        // `app_signup_codes.email` is utf8mb4_unicode_ci too, and matchLiveCode()
        // compares with `where('email', ...)` and never calls LOWER, so this column
        // gets the collation itself rather than the function override.
        $this->collateColumnLikeUnicodeCi('app_signup_codes', 'email');

        $victim = $this->contact(self::TYPED, $this->office(), self::THEIRS);
        $victim->createMemberToken();

        // The attacker's mailbox got this code: it was issued to the look-alike.
        $code = $this->mintCode(self::STORED);
        $this->assertSame(
            1,
            AppSignupCode::withoutMasjidScope()->where('email', self::TYPED)->count(),
            'PREMISE: the collation makes the look-alike\'s code row match the victim\'s address.',
        );

        $before = $this->stored($victim);
        $tokensBefore = $victim->tokens()->pluck('id')->all();

        $response = $this->verify(self::TYPED, $code, ['password' => self::MINE]);

        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());
        $this->assertNothingHappenedTo($victim, $before, $tokensBefore);

        $row = AppSignupCode::withoutMasjidScope()->where('email', self::STORED)->sole();
        $this->assertNull($row->consumed_at, 'The look-alike\'s code was spent by a redeem at another address.');
        $this->assertSame(0, (int) $row->attempts, 'A guess at the victim\'s address charged the look-alike\'s code.');
    }

    // ------------------------------------------------------------ the create path

    #[Test]
    public function creating_a_contact_that_collides_with_a_look_alike_is_refused_cleanly(): void
    {
        // Production's unique index on (masjid_id, login_email) is collation-equal
        // too, so a contact created at the look-alike collides with the other one.
        $this->collateContactLoginEmailIndexLikeUnicodeCi();

        $other = $this->contact(self::STORED, $this->office(), self::THEIRS);
        $this->assertTheSqlStillFinds('login_email', $other);
        $before = $this->stored($other);
        $tokensBefore = $other->tokens()->pluck('id')->all();
        $contactsBefore = Contact::withoutMasjidScope()->count();

        $code = $this->requestCode(self::TYPED);
        $response = $this->verify(self::TYPED, $code, [
            'first_name' => 'Nobody',
            'last_name' => 'Special',
            'password' => self::MINE,
        ]);

        // Not a 500, and not a sentence about whose address it collided with: the
        // one 410 every refused redeem answers, byte for byte.
        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());

        $this->assertSame($contactsBefore, Contact::withoutMasjidScope()->count(), 'A contact was created.');
        $this->assertNothingHappenedTo($other, $before, $tokensBefore);

        // The code is SPENT: a second try with it is the same refusal.
        $this->assertNotNull(AppSignupCode::withoutMasjidScope()->where('email', self::TYPED)->sole()->consumed_at);
        $again = $this->verify(self::TYPED, $code, [
            'first_name' => 'Nobody',
            'last_name' => 'Special',
            'password' => self::MINE,
        ]);
        $again->assertStatus(410);
        $this->assertSame(self::GONE, $again->getContent());

        // One warning, naming the organisation and nothing about the address.
        $warnings = array_values(array_filter(
            $this->logged,
            fn (MessageLogged $event) => $event->level === 'warning' && str_contains($event->message, 'collides'),
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame($this->masjid->id, $warnings[0]->context['masjid_id']);
        $this->assertNoAddressWasLogged();
    }

    #[Test]
    public function a_soft_deleted_contact_holding_the_address_is_the_same_clean_refusal(): void
    {
        // The unique index also pins a soft-deleted contact's address, and
        // resolveContact() never returns a trashed row, so redeeming a code at that
        // address tries to create a second contact there. That collision would have
        // been a 500 too; it goes through the same door.
        $gone = $this->contact(self::TYPED, $this->office());
        $gone->delete();
        $contactsBefore = Contact::withoutMasjidScope()->withTrashed()->count();

        $code = $this->requestCode(self::TYPED);
        $response = $this->verify(self::TYPED, $code, [
            'first_name' => 'Nobody',
            'last_name' => 'Special',
        ]);

        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());
        $this->assertSame($contactsBefore, Contact::withoutMasjidScope()->withTrashed()->count());
        $this->assertNotNull(AppSignupCode::withoutMasjidScope()->where('email', self::TYPED)->sole()->consumed_at);
        $this->assertNoAddressWasLogged();
    }

    // -------------------------------------------------------------- password door

    #[Test]
    public function the_password_door_refuses_a_look_alike_address(): void
    {
        // This door was already guarded: mayUsePassword() compares the stored
        // address with the typed one exactly. Pinned so that the resolver change
        // cannot loosen it, and so it is seen refusing on the same premise.
        $other = $this->contact(self::STORED, $this->office(), self::THEIRS);
        $this->assertTheSqlStillFinds('login_email', $other);
        $before = $this->stored($other);

        $response = $this->signIn(self::TYPED, self::THEIRS);

        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());
        $this->assertSame($before, $this->stored($other), 'A refused password sign-in wrote to the contact.');
        $this->assertSame([], $other->tokens()->pluck('id')->all());
    }

    // ------------------------------------------------- what must still sign in

    #[Test]
    public function an_address_that_differs_only_in_case_still_signs_in_by_code_and_by_password(): void
    {
        $member = $this->contact('Person@Gmail.com', $this->office());

        $code = $this->requestCode('PERSON@GMAIL.COM', mailedTo: 'person@gmail.com');
        $viaCode = $this->verify('PERSON@GMAIL.COM', $code, ['password' => self::MINE]);

        $viaCode->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame($member->id, $viaCode->json('data.contact.id'));

        $viaPassword = $this->signIn('pErSoN@gMaIl.CoM', self::MINE);

        $viaPassword->assertOk();
        $this->assertSame($member->id, $viaPassword->json('data.contact.id'));
    }

    #[Test]
    public function an_address_with_surrounding_spaces_still_resolves_to_the_same_contact(): void
    {
        $member = $this->contact(self::TYPED, $this->office());

        // Direct to the service: the HTTP stack trims before the service sees it.
        app(TenantContext::class)->set($this->masjid->id);
        $service = app(MemberSignupService::class);

        $service->issue("  Person@Gmail.com \n");

        $code = Mail::sent(
            FamilyLoginCodeMail::class,
            fn (FamilyLoginCodeMail $mail) => $mail->hasTo(self::TYPED),
        )->last()?->code;
        $this->assertNotNull($code, 'No code was mailed to the trimmed, lower-cased address.');

        $result = $service->redeem("\tPERSON@gmail.com  ", $code);

        $this->assertNotNull($result);
        $this->assertFalse($result['created']);
        $this->assertSame($member->id, $result['contact']->id);
    }

    // ---------------------------------------------------------------- helpers

    private function makeMasjid(): Masjid
    {
        $this->asANewRequest();

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

    /** A real request clears the guard cache and the tenant; so must a test between requests. */
    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /** A contact address the office typed, distinct from every address under test. */
    private function office(): string
    {
        return 'office-' . uniqid() . '@test.local';
    }

    /**
     * A contact as the office or a past sign-in left it. `$loginEmail` null is a
     * contact with no login address, which only the `email` arm can find. A
     * contact WITH a login address is verified, as one that has signed in is.
     */
    private function contact(?string $loginEmail, ?string $officeEmail, ?string $password = null): Contact
    {
        $this->asANewRequest();

        $contact = new Contact();
        $contact->forceFill([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'On',
            'last_name' => 'File',
            'email' => $officeEmail,
            'login_email' => $loginEmail,
            'signup_source' => 'app',
            'verified_at' => $loginEmail === null ? null : now(),
            'password' => $password === null ? null : Hash::make($password),
            'password_set_at' => $password === null ? null : now(),
        ])->save();

        return $contact->refresh();
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
    private function assertTheSqlStillFinds(string $column, Contact $other): void
    {
        $this->assertSame(
            [$other->id],
            Contact::withoutMasjidScope()->whereRaw("LOWER({$column}) = ?", [self::TYPED])->pluck('id')->all(),
            "PREMISE: LOWER({$column}) = the typed address must return the other contact, or this test proves nothing.",
        );
    }

    /**
     * No write to the other contact, no session of theirs ended, none minted.
     *
     * @param  array<string, mixed>  $before
     * @param  list<int|string>  $tokensBefore
     */
    private function assertNothingHappenedTo(Contact $other, array $before, array $tokensBefore): void
    {
        $this->assertSame($before, $this->stored($other), 'The other contact was written to.');
        $this->assertSame($tokensBefore, $other->tokens()->pluck('id')->all(), 'Their sessions changed.');
    }

    private function assertNoAddressWasLogged(): void
    {
        foreach ($this->logged as $event) {
            $text = $event->message . json_encode($event->context);

            foreach (['person@', 'gmail', 'gmaíl', 'gmaí'] as $fragment) {
                $this->assertStringNotContainsString($fragment, $text, 'An address reached the log.');
            }
        }
    }

    /** The newest sign-in code mailed to `$mailedTo` (default: the typed address, lower-cased). */
    private function requestCode(string $typed, ?string $mailedTo = null): string
    {
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/request-code", ['email' => $typed])
            ->assertStatus(202);

        $to = $mailedTo ?? mb_strtolower($typed);
        $code = Mail::sent(
            FamilyLoginCodeMail::class,
            fn (FamilyLoginCodeMail $mail) => $mail->hasTo($to) && ! $mail->isForAccountDeletion(),
        )->last()?->code;

        $this->assertNotNull($code, "No sign-in code was mailed to {$to}.");

        return $code;
    }

    /** @param  array<string, mixed>  $extra */
    private function verify(string $email, string $code, array $extra = []): TestResponse
    {
        $this->asANewRequest();

        return $this->postJson(
            "/api/mobile/masjids/{$this->masjid->id}/auth/verify-code",
            array_merge(['email' => $email, 'code' => $code], $extra),
        );
    }

    private function signIn(string $email, string $password): TestResponse
    {
        $this->asANewRequest();

        $response = $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/password", [
            'email' => $email,
            'password' => $password,
        ]);

        $this->asANewRequest();

        return $response;
    }

    /** A live code row written directly, for an address `issue()` would not store as typed. */
    private function mintCode(string $email): string
    {
        $code = '482915';

        $service = app(MemberSignupService::class);
        $hash = (new \ReflectionMethod($service, 'hash'))->invoke($service, $code);

        AppSignupCode::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'email' => $email,
            'code_hash' => $hash,
            'channel' => AppSignupCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        return $code;
    }
}
