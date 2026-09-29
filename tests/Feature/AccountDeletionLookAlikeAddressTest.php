<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Member\MemberAccountDeletion;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * Deleting an account deletes the account at the address that was PROVED, not
 * one the collation calls equal to it.
 *
 * The public `/account-deletion` page mails a code to the TYPED address and,
 * when the code is right, deletes the contact whose `login_email` matches it.
 * `login_email` is `utf8mb4_unicode_ci` on production, so somebody who owned a
 * look-alike domain could prove THEIR mailbox and delete the account at
 * `victim@gmail.com`.
 *
 * Premise built as in MemberSignInLookAlikeAddressTest: the account's address is
 * stored ACCENTED, the plain one is typed, and `LOWER()` folds accents.
 */
class AccountDeletionLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the account holds: the accented form. */
    private const STORED = 'person@gmaíl.com';

    /** What is typed: the plain one. */
    private const TYPED = 'person@gmail.com';

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->foldAccentsLikeUnicodeCi();
        $this->masjid = $this->makeListedMasjid();
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    #[Test]
    public function proving_a_look_alike_mailbox_on_the_public_page_deletes_nobody(): void
    {
        $account = $this->appMember(self::STORED);
        $this->assertTheSqlStillFinds($account);
        $before = Contact::withoutMasjidScope()->whereKey($account->id)->firstOrFail()->getAttributes();

        // The attacker owns the mailbox at the typed address, so THEY get the code.
        $this->request($this->page('/account-deletion', self::TYPED))->assertOk();
        $code = $this->deletionCodeMailedTo(self::TYPED);

        $response = $this->request($this->page('/account-deletion/confirm', self::TYPED, ['code' => $code, 'confirm' => '1']));

        // A valid code for an address with no account here: the same page any
        // stranger's address gets. And nothing about the real account moved.
        $response->assertOk();
        $response->assertSee('There was no account to delete');
        $response->assertDontSee('Your account has been deleted');

        $after = Contact::withoutMasjidScope()->withTrashed()->whereKey($account->id)->first();
        $this->assertNotNull($after, 'The account at the look-alike address was deleted.');
        $this->assertSame($before, $after->getAttributes());
    }

    #[Test]
    public function the_service_refuses_a_look_alike_and_deletes_the_exact_address(): void
    {
        $lookAlike = $this->appMember(self::STORED);
        $this->assertTheSqlStillFinds($lookAlike);

        app(TenantContext::class)->set($this->masjid->id);
        $deletion = app(MemberAccountDeletion::class);

        $this->assertNull($deletion->deleteByAddress(self::TYPED, MemberAccountDeletion::VIA_WEB));
        $this->assertNotNull(Contact::withoutMasjidScope()->find($lookAlike->id));

        // Two accounts, two addresses: the exact one is deleted, the look-alike
        // is not, and its presence does not make the real address "ambiguous".
        $exact = $this->appMember(self::TYPED);
        app(TenantContext::class)->set($this->masjid->id);

        $result = $deletion->deleteByAddress('  Person@Gmail.com ', MemberAccountDeletion::VIA_WEB);

        $this->assertNotNull($result, 'The exact address was not found beside a look-alike.');
        $this->assertNull(Contact::withoutMasjidScope()->withTrashed()->find($exact->id));
        $this->assertNotNull(Contact::withoutMasjidScope()->find($lookAlike->id), 'The look-alike account was deleted.');
    }

    // ---------------------------------------------------------------- helpers

    private function makeListedMasjid(): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Listed Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        // Not fillable: publishing is a SuperAdmin act (directory-listing.md).
        $masjid->forceFill(['listed_at' => now()])->save();

        return $masjid->refresh();
    }

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /** A contact with exactly the columns MemberSignupService writes when it creates one. */
    private function appMember(string $address): Contact
    {
        $this->asANewRequest();

        $contact = new Contact();
        $contact->forceFill([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'App',
            'last_name' => 'Member',
            'email' => $address,
            'login_email' => $address,
            'signup_source' => 'app',
            'verified_at' => now(),
        ])->save();

        return $contact->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function page(string $path, string $email, array $extra = []): array
    {
        return [$path, array_merge(['masjid_id' => $this->masjid->id, 'email' => $email], $extra)];
    }

    /** @param  array{0: string, 1: array<string, mixed>}  $request */
    private function request(array $request)
    {
        $this->asANewRequest();
        $response = $this->post($request[0], $request[1]);
        $this->asANewRequest();

        return $response;
    }

    private function deletionCodeMailedTo(string $address): string
    {
        $code = Mail::sent(
            FamilyLoginCodeMail::class,
            fn (FamilyLoginCodeMail $mail) => $mail->hasTo($address) && $mail->isForAccountDeletion(),
        )->last()?->code;

        $this->assertNotNull($code, "No deletion code was mailed to {$address}.");

        return $code;
    }

    /** PREMISE: the collation-equal query returns the account, as MySQL would. */
    private function assertTheSqlStillFinds(Contact $account): void
    {
        $this->assertSame(
            [$account->id],
            Contact::withoutMasjidScope()->whereRaw('LOWER(login_email) = ?', [self::TYPED])->pluck('id')->all(),
            'PREMISE: LOWER(login_email) = the typed address must return the account, or this test proves nothing.',
        );
    }
}
