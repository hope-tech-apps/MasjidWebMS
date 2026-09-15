<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Member\NewMemberNameRequired;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A brand-new member who leaves a name blank is ASKED for it, and keeps the code.
 *
 * Until 2026-09-15 `verify-code` answered this case with the same 410 as a wrong
 * code, and burned the code on the way. An iOS walk-through on staging typed both
 * names into one field and got "ask for a new code" twice.
 *
 * The line this file guards is the one that made the old behaviour tempting: the
 * 422 must be reachable ONLY with a correct, live code. Before the code is proven,
 * every refusal stays the one silent 410, so the endpoint still never answers
 * "does this address have an account here?" for somebody who merely typed it.
 */
class MemberSignInMissingNameTest extends TestCase
{
    use RefreshDatabase;

    private const GONE = '{"status":"error","message":"That code is no longer usable. Please request a new one.","data":{}}';

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->masjid = $this->makeMasjid();
    }

    // ------------------------------------------------------- asked, code kept

    #[Test]
    public function a_new_member_who_leaves_the_last_name_blank_is_asked_for_it_and_keeps_the_code(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $this->verify($this->masjid, $email, $code, 'Amina', '')
            ->assertStatus(422)
            ->assertExactJson([
                'status' => 'failed',
                'message' => 'Enter your first and last name to create your account.',
                'data' => ['last_name' => ['Enter your last name.']],
            ]);

        $row = $this->codeRow($email);
        $this->assertNull($row->consumed_at, 'Asking for the name must not use the code up.');
        $this->assertSame(0, (int) $row->attempts, 'A correct code is not a wrong guess.');

        $this->assertSame(0, Contact::withoutMasjidScope()->withTrashed()->where('email', $email)->count());
        $this->assertSame(0, PersonalAccessToken::count());
    }

    #[Test]
    public function the_same_code_then_signs_them_in_once_the_name_is_sent(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $this->verify($this->masjid, $email, $code, 'Amina', null)->assertStatus(422);

        $this->verify($this->masjid, $email, $code, 'Amina', 'Yusuf')
            ->assertOk()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.contact.first_name', 'Amina')
            ->assertJsonPath('data.contact.last_name', 'Yusuf');

        $this->assertNotNull($this->codeRow($email)->consumed_at);
        $this->assertSame(1, Contact::withoutMasjidScope()->where('login_email', $email)->count());
    }

    #[Test]
    public function both_names_blank_or_whitespace_lists_both_fields(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $both = [
            'first_name' => [NewMemberNameRequired::FIELD_MESSAGES['first_name']],
            'last_name' => [NewMemberNameRequired::FIELD_MESSAGES['last_name']],
        ];

        $this->verify($this->masjid, $email, $code, null, null)
            ->assertStatus(422)
            ->assertJsonPath('data', $both);

        $this->verify($this->masjid, $email, $code, '   ', "\t")
            ->assertStatus(422)
            ->assertJsonPath('data', $both);

        $this->assertNull($this->codeRow($email)->consumed_at);
    }

    #[Test]
    public function a_first_name_alone_missing_names_only_that_field(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $this->verify($this->masjid, $email, $code, '', 'Yusuf')
            ->assertStatus(422)
            ->assertExactJson([
                'status' => 'failed',
                'message' => NewMemberNameRequired::MESSAGE,
                'data' => ['first_name' => ['Enter your first name.']],
            ]);
    }

    // --------------------------------------------------- returning members

    #[Test]
    public function a_returning_member_still_signs_in_without_a_name(): void
    {
        $email = $this->address();
        $member = $this->existingContact($this->masjid, $email);
        $code = $this->requestCode($this->masjid, $email);

        $this->verify($this->masjid, $email, $code, null, null)
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.contact.id', (int) $member->id)
            ->assertJsonPath('data.contact.first_name', 'On');

        $this->assertNotNull($this->codeRow($email)->consumed_at);
    }

    // ---------------------------------------- still one silent 410 before a proven code

    #[Test]
    public function a_wrong_code_is_still_the_410_for_an_address_that_would_be_new(): void
    {
        $new = $this->address();
        $known = $this->address();
        $this->existingContact($this->masjid, $known);

        $newCode = $this->requestCode($this->masjid, $new);
        $knownCode = $this->requestCode($this->masjid, $known);

        $forNew = $this->verify($this->masjid, $new, $this->wrongCode($newCode), null, null);
        $forKnown = $this->verify($this->masjid, $known, $this->wrongCode($knownCode), null, null);

        // Identical, and neither is the 422: without the right code the endpoint
        // says nothing about whether the address would create somebody.
        $forNew->assertStatus(410);
        $forKnown->assertStatus(410);
        $this->assertSame(self::GONE, $forNew->getContent());
        $this->assertSame(self::GONE, $forKnown->getContent());

        $this->assertSame(1, (int) $this->codeRow($new)->attempts, 'A wrong guess is still charged.');
    }

    #[Test]
    public function a_used_code_is_still_the_410_without_a_name(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $this->verify($this->masjid, $email, $code, 'Amina', 'Yusuf')->assertOk();

        $replay = $this->verify($this->masjid, $email, $code, null, null);
        $replay->assertStatus(410);
        $this->assertSame(self::GONE, $replay->getContent());
    }

    #[Test]
    public function an_expired_code_is_still_the_410_without_a_name(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $this->travel((int) config('member.signup.code_ttl_minutes', 10) + 1)->minutes();

        $response = $this->verify($this->masjid, $email, $code, null, null);
        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());
    }

    #[Test]
    public function a_locked_out_code_is_still_the_410_without_a_name(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        AppSignupCode::withoutMasjidScope()
            ->where('email', $email)
            ->update(['attempts' => AppSignupCode::maxAttempts()]);

        $this->verify($this->masjid, $email, $code, null, null)->assertStatus(410);
    }

    #[Test]
    public function a_revoked_contact_is_still_the_410_and_the_code_is_used_up(): void
    {
        $email = $this->address();
        $this->existingContact($this->masjid, $email, revoked: true);
        $code = $this->requestCode($this->masjid, $email);

        // `issue()` declines to mail a revoked contact. Mint the row directly so
        // the redeem path is what's under test.
        if ($code === null) {
            $code = $this->mintCode($this->masjid, $email);
        }

        $response = $this->verify($this->masjid, $email, $code, null, null);
        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());
        $this->assertNotNull($this->codeRow($email)->consumed_at, 'Unchanged: a revoked link burns the code.');
    }

    #[Test]
    public function a_code_minted_at_another_organisation_is_the_410_not_the_422(): void
    {
        $other = $this->makeMasjid();
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $response = $this->verify($other, $email, $code, null, null);
        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());

        $this->assertNull($this->codeRow($email)->consumed_at);
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

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    private function address(): string
    {
        return 'member-' . uniqid() . '@test.local';
    }

    /** The code mailed for this address, or null when nothing was mailed. */
    private function requestCode(Masjid $masjid, string $email): ?string
    {
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$masjid->id}/auth/request-code", ['email' => $email])
            ->assertStatus(202);

        // Mail::sent, not assertSent: `issue()` mails nothing to a revoked contact,
        // and that case must return null rather than fail the assertion.
        return Mail::sent(
            FamilyLoginCodeMail::class,
            fn (FamilyLoginCodeMail $mail) => $mail->hasTo($email) && ! $mail->isForAccountDeletion(),
        )->last()?->code;
    }

    private function verify(Masjid $masjid, string $email, string $code, ?string $first, ?string $last): TestResponse
    {
        $this->asANewRequest();

        $payload = ['email' => $email, 'code' => $code];

        if ($first !== null) {
            $payload['first_name'] = $first;
        }

        if ($last !== null) {
            $payload['last_name'] = $last;
        }

        return $this->postJson("/api/mobile/masjids/{$masjid->id}/auth/verify-code", $payload);
    }

    private function codeRow(string $email): AppSignupCode
    {
        return AppSignupCode::withoutMasjidScope()->where('email', $email)->orderByDesc('id')->firstOrFail();
    }

    private function wrongCode(string $real): string
    {
        return $real === '000000' ? '111111' : '000000';
    }

    private function existingContact(Masjid $masjid, string $email, bool $revoked = false): Contact
    {
        $this->asANewRequest();

        $contact = new Contact();
        $contact->forceFill([
            'masjid_id' => $masjid->id,
            'first_name' => 'On',
            'last_name' => 'File',
            'email' => $email,
            'login_email' => $email,
            'login_revoked_at' => $revoked ? now() : null,
        ])->save();

        return $contact->refresh();
    }

    /** A live code row written directly, for addresses `issue()` will not mail. */
    private function mintCode(Masjid $masjid, string $email): string
    {
        $code = '482915';

        $service = app(\App\Services\Member\MemberSignupService::class);
        $hash = (new \ReflectionMethod($service, 'hash'))->invoke($service, $code);

        AppSignupCode::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'email' => $email,
            'code_hash' => $hash,
            'channel' => AppSignupCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        return $code;
    }
}
