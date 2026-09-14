<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\ContactServiceInterest;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\Service;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public account-deletion page, `/account-deletion` — Google Play's required
 * web link, and the same deletion the app runs.
 *
 * What must hold, in order of how badly it goes wrong if it does not:
 *
 *  1. It is not a directory. Asking for a code looks identical, and mails a code,
 *     whether or not the address has an account; only a person holding the code
 *     learns the answer. Unlisted organisations are not offered.
 *  2. Nothing is deleted without the right, unexpired, unspent deletion code, and
 *     a sign-in code is not a deletion code (or the reverse).
 *  3. It shares the app sign-in door's limits rather than adding its own.
 *  4. When it does delete, it deletes exactly what the app's "Delete account"
 *     deletes (MemberAccountDeletionTest covers that service in depth).
 *
 * CSRF is not exercised here: Laravel skips the token check while running unit
 * tests. The page renders the token field, which is asserted.
 */
class AccountDeletionPageTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $listed;
    private Masjid $otherListed;
    private Masjid $unlisted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listed = $this->makeMasjid('Listed Masjid', listed: true);
        $this->otherListed = $this->makeMasjid('Another Listed Organisation', listed: true);
        $this->unlisted = $this->makeMasjid('Hidden Sandbox', listed: false);
    }

    #[Test]
    public function the_page_offers_only_organisations_in_the_app_directory(): void
    {
        $response = $this->get('/account-deletion');

        $response->assertOk();
        // Served by this page, not swallowed by the SPA catch-all.
        $response->assertViewIs('account-deletion.page');
        $response->assertSee('Delete your app account');
        $response->assertSee($this->listed->name);
        $response->assertSee($this->otherListed->name);
        $response->assertDontSee($this->unlisted->name);

        // A form a screen reader can use, carrying the CSRF token.
        $response->assertSee('name="_token"', false);
        $response->assertSee('<label for="masjid_id">', false);
        $response->assertSee('<label for="email">', false);
        $response->assertSee('autocomplete="email"', false);
    }

    #[Test]
    public function asking_for_a_code_looks_the_same_with_or_without_an_account(): void
    {
        Mail::fake();

        $member = $this->appMember($this->listed, 'known@test.local');

        $known = $this->requestCode($this->listed, 'known@test.local');
        $unknown = $this->requestCode($this->listed, 'nobody@test.local');

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame(
            $this->withoutPerRequestNoise($known->getContent(), 'known@test.local'),
            $this->withoutPerRequestNoise($unknown->getContent(), 'nobody@test.local'),
        );

        // A code goes to both, so the time taken does not tell them apart either.
        Mail::assertSent(FamilyLoginCodeMail::class, 2);
        foreach (['known@test.local', 'nobody@test.local'] as $address) {
            Mail::assertSent(FamilyLoginCodeMail::class, fn (FamilyLoginCodeMail $mail) => $mail->hasTo($address)
                && $mail->isForAccountDeletion()
                && $mail->recipientName === null
                && $mail->envelope()->subject === 'Your account deletion code');
        }

        // Asking deletes nothing.
        $this->assertNotNull(Contact::withoutMasjidScope()->findOrFail($member->id)->verified_at);
    }

    #[Test]
    public function a_member_who_proves_the_address_is_deleted(): void
    {
        Mail::fake();

        $member = $this->appMember($this->listed, 'amina@test.local');
        $member->createMemberToken();
        $phone = MobileAppUser::create([
            'masjid_id' => $this->listed->id,
            'contact_id' => $member->id,
            'device_id' => 'device-' . uniqid('', true),
            'user_agent' => 'PHPUnit',
        ]);

        // Typed with capitals: the address, not its spelling, is the account.
        $this->requestCode($this->listed, 'Amina@Test.Local')->assertOk();

        $response = $this->confirm($this->listed, 'amina@test.local', $this->lastDeletionCodeFor('amina@test.local'));

        $response->assertOk();
        $response->assertSee('Your account has been deleted');
        $response->assertSee('Your contact details have been deleted as well.');

        $this->assertNull(Contact::withoutMasjidScope()->withTrashed()->find($member->id));
        $this->assertNull($phone->fresh()->contact_id);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)
            ->where('tokenable_type', $member->getMorphClass())->count());
    }

    #[Test]
    public function a_contact_the_office_knows_keeps_its_record(): void
    {
        Mail::fake();

        $member = $this->appMember($this->listed, 'known-to-office@test.local');
        Contact::withoutMasjidScope()->whereKey($member->id)->update(['phone' => '+17045550123']);

        // What the web door must take away even when the record stays: every
        // sign-in, the handset and the notification choices.
        $member->createMemberToken();
        $phone = MobileAppUser::create([
            'masjid_id' => $this->listed->id,
            'contact_id' => $member->id,
            'device_id' => 'device-' . uniqid('', true),
            'user_agent' => 'PHPUnit',
        ]);
        $service = Service::create([
            'masjid_id' => $this->listed->id,
            'title' => 'Halal Kitchen',
            'summary' => 'Halal Kitchen',
            'description' => 'Halal Kitchen',
            'text' => 'Halal Kitchen',
        ]);
        ContactServiceInterest::withoutMasjidScope()->create([
            'masjid_id' => $this->listed->id,
            'contact_id' => $member->id,
            'service_id' => $service->id,
        ]);

        $this->requestCode($this->listed, 'known-to-office@test.local')->assertOk();
        $response = $this->confirm($this->listed, 'known-to-office@test.local', $this->lastDeletionCodeFor('known-to-office@test.local'));

        $response->assertOk();
        $response->assertSee('Your account has been deleted');
        $response->assertSee('keeps its own records about you');

        $kept = Contact::withoutMasjidScope()->findOrFail($member->id);
        $this->assertNull($kept->verified_at);
        $this->assertSame('+17045550123', $kept->phone);

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)
            ->where('tokenable_type', $member->getMorphClass())->count());
        $this->assertNull($phone->fresh()->contact_id);
        $this->assertSame(0, ContactServiceInterest::withoutMasjidScope()->where('contact_id', $member->id)->count());
    }

    #[Test]
    public function the_page_names_the_apps_and_publisher_and_says_what_is_kept(): void
    {
        config([
            'member.account_deletion.publisher' => 'Example Publisher Inc.',
            'member.account_deletion.apps' => ['First App', 'Second App'],
            'member.account_deletion.log_retention_days' => 30,
        ]);

        $response = $this->get('/account-deletion');

        $response->assertOk();
        $response->assertSee('First App and Second App');
        $response->assertSee('published by Example Publisher Inc.');
        $response->assertSee('What is kept, and for how long');
        $response->assertSee('for up to 30 days');
        $response->assertDontSee('only as long as we need them');
    }

    #[Test]
    public function a_valid_code_for_an_address_with_no_account_changes_nothing(): void
    {
        Mail::fake();

        // The same address has an account at a DIFFERENT organisation.
        $elsewhere = $this->appMember($this->otherListed, 'wanderer@test.local');

        $this->requestCode($this->listed, 'wanderer@test.local')->assertOk();
        $response = $this->confirm($this->listed, 'wanderer@test.local', $this->lastDeletionCodeFor('wanderer@test.local'));

        $response->assertOk();
        $response->assertSee('There was no account to delete');
        $this->assertNotNull(Contact::withoutMasjidScope()->findOrFail($elsewhere->id)->verified_at);
    }

    #[Test]
    public function a_wrong_code_deletes_nothing_and_counts_as_a_guess(): void
    {
        Mail::fake();

        $member = $this->appMember($this->listed, 'guess@test.local');

        $this->requestCode($this->listed, 'guess@test.local')->assertOk();
        $code = $this->lastDeletionCodeFor('guess@test.local');

        $wrong = $this->confirm($this->listed, 'guess@test.local', $code === '000000' ? '111111' : '000000');

        $wrong->assertStatus(422);
        $wrong->assertSee('That code is not right, or it has expired.');
        $this->assertNotNull(Contact::withoutMasjidScope()->findOrFail($member->id)->verified_at);
        $this->assertSame(1, (int) AppSignupCode::withoutMasjidScope()->where('email', 'guess@test.local')->value('attempts'));

        // The right code, typed the way people paste it, still works afterwards.
        $this->confirm($this->listed, 'guess@test.local', substr($code, 0, 3) . ' ' . substr($code, 3))
            ->assertOk()
            ->assertSee('Your account has been deleted');
    }

    #[Test]
    public function an_expired_code_deletes_nothing(): void
    {
        Mail::fake();

        $member = $this->appMember($this->listed, 'slow@test.local');

        $this->requestCode($this->listed, 'slow@test.local')->assertOk();
        $code = $this->lastDeletionCodeFor('slow@test.local');

        $this->travel((int) config('member.signup.code_ttl_minutes', 10) + 1)->minutes();

        $this->confirm($this->listed, 'slow@test.local', $code)->assertStatus(422);
        $this->assertNotNull(Contact::withoutMasjidScope()->findOrFail($member->id)->verified_at);
    }

    #[Test]
    public function a_spent_code_cannot_be_used_twice(): void
    {
        Mail::fake();

        $this->appMember($this->listed, 'twice@test.local');

        $this->requestCode($this->listed, 'twice@test.local')->assertOk();
        $code = $this->lastDeletionCodeFor('twice@test.local');

        $this->confirm($this->listed, 'twice@test.local', $code)->assertOk();

        // The same person signs up again, then somebody replays the old code.
        $again = $this->appMember($this->listed, 'twice@test.local');
        $this->confirm($this->listed, 'twice@test.local', $code)->assertStatus(422);
        $this->assertNotNull(Contact::withoutMasjidScope()->findOrFail($again->id)->verified_at);
    }

    #[Test]
    public function a_sign_in_code_cannot_delete_and_a_deletion_code_cannot_sign_in(): void
    {
        Mail::fake();

        $member = $this->appMember($this->listed, 'both@test.local');

        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$this->listed->id}/auth/request-code", ['email' => 'both@test.local'])
            ->assertStatus(202);
        $this->asANewRequest();

        $signInCode = null;
        Mail::assertSent(FamilyLoginCodeMail::class, function (FamilyLoginCodeMail $mail) use (&$signInCode) {
            if (! $mail->isForAccountDeletion()) {
                $signInCode = $mail->code;
            }

            return true;
        });

        $this->confirm($this->listed, 'both@test.local', (string) $signInCode)->assertStatus(422);
        $this->assertNotNull(Contact::withoutMasjidScope()->findOrFail($member->id)->verified_at);

        $this->requestCode($this->listed, 'both@test.local')->assertOk();
        $deletionCode = $this->lastDeletionCodeFor('both@test.local');

        $this->postJson("/api/mobile/masjids/{$this->listed->id}/auth/verify-code", [
            'email' => 'both@test.local',
            'code' => $deletionCode,
        ])->assertStatus(410);
        $this->asANewRequest();
    }

    #[Test]
    public function asking_for_a_code_shares_the_app_sign_in_doors_limit(): void
    {
        Mail::fake();

        $perAddress = (int) config('member.signup.requests_per_hour_per_address', 5);

        for ($i = 0; $i < $perAddress; $i++) {
            $this->asANewRequest();
            $this->postJson("/api/mobile/masjids/{$this->listed->id}/auth/request-code", ['email' => 'busy@test.local'])
                ->assertStatus(202);
        }

        $response = $this->requestCode($this->listed, 'busy@test.local');

        $response->assertStatus(429);
        // A page with a sentence, not the API's JSON body.
        $response->assertSee('Too many attempts');
        $response->assertSee('Nothing was deleted.');
        Mail::assertSent(FamilyLoginCodeMail::class, $perAddress);
    }

    #[Test]
    public function checking_codes_is_limited_per_address(): void
    {
        $perAddress = (int) config('member.signup.verifications_per_hour_per_address', 10);

        for ($i = 0; $i < $perAddress; $i++) {
            $this->confirm($this->listed, 'hammer@test.local', '000000')->assertStatus(422);
        }

        $this->confirm($this->listed, 'hammer@test.local', '000000')->assertStatus(429);
    }

    #[Test]
    public function an_organisation_outside_the_directory_cannot_be_chosen(): void
    {
        Mail::fake();

        $this->appMember($this->unlisted, 'hidden@test.local');

        $response = $this->requestCode($this->unlisted, 'hidden@test.local');

        $response->assertStatus(422);
        $response->assertSee('Choose the organisation whose app you use.');
        $response->assertDontSee($this->unlisted->name);
        Mail::assertNothingSent();
    }

    #[Test]
    public function deleting_needs_the_confirmation_box_ticked(): void
    {
        Mail::fake();

        $member = $this->appMember($this->listed, 'unsure@test.local');

        $this->requestCode($this->listed, 'unsure@test.local')->assertOk();
        $code = $this->lastDeletionCodeFor('unsure@test.local');

        $response = $this->confirm($this->listed, 'unsure@test.local', $code, tick: false);

        $response->assertStatus(422);
        $response->assertSee('Tick the box to confirm that you want to delete your account.');
        $response->assertSee('aria-invalid="true"', false);
        $this->assertNotNull(Contact::withoutMasjidScope()->findOrFail($member->id)->verified_at);

        // Refused before the code was checked, so it was neither spent nor charged.
        $row = AppSignupCode::withoutMasjidScope()->where('email', 'unsure@test.local')->firstOrFail();
        $this->assertNull($row->consumed_at);
        $this->assertSame(0, (int) $row->attempts);
    }

    // ---------------------------------------------------------------- fixtures

    private function makeMasjid(string $name, bool $listed): Masjid
    {
        $masjid = Masjid::create([
            'name' => $name . ' ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        if ($listed) {
            // Not fillable: publishing is a SuperAdmin act (directory-listing.md).
            $masjid->forceFill(['listed_at' => now()])->save();
        }

        return $masjid->refresh();
    }

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /** A contact with exactly the columns MemberSignupService writes when it creates one. */
    private function appMember(Masjid $masjid, string $email): Contact
    {
        $this->asANewRequest();

        $contact = new Contact();
        $contact->forceFill([
            'masjid_id' => $masjid->id,
            'first_name' => 'App',
            'last_name' => 'Member',
            'email' => $email,
            'login_email' => $email,
            'signup_source' => 'app',
            'verified_at' => now(),
        ])->save();

        return $contact->refresh();
    }

    private function requestCode(Masjid $masjid, string $email)
    {
        $this->asANewRequest();
        $response = $this->post('/account-deletion', ['masjid_id' => $masjid->id, 'email' => $email]);
        $this->asANewRequest();

        return $response;
    }

    private function confirm(Masjid $masjid, string $email, string $code, bool $tick = true)
    {
        $payload = ['masjid_id' => $masjid->id, 'email' => $email, 'code' => $code];

        if ($tick) {
            $payload['confirm'] = '1';
        }

        $this->asANewRequest();
        $response = $this->post('/account-deletion/confirm', $payload);
        $this->asANewRequest();

        return $response;
    }

    private function lastDeletionCodeFor(string $email): string
    {
        $code = null;

        Mail::assertSent(FamilyLoginCodeMail::class, function (FamilyLoginCodeMail $mail) use (&$code, $email) {
            if ($mail->hasTo($email) && $mail->isForAccountDeletion()) {
                $code = $mail->code;
            }

            return true;
        });

        $this->assertNotNull($code, "No deletion code was mailed to {$email}.");

        return $code;
    }

    /** The CSRF token and the typed address are the only things allowed to differ. */
    private function withoutPerRequestNoise(string $html, string $email): string
    {
        $html = (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value=""', $html);

        return str_replace($email, 'ADDRESS', $html);
    }
}
