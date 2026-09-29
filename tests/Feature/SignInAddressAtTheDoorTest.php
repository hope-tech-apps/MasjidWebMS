<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\ContactLoginCode;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Family\FamilyLoginService;
use App\Services\Member\MemberSignupService;
use App\Support\ContactIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The typed address is normalised AT THE DOOR: a non-ASCII domain becomes its
 * punycode form, and a non-ASCII local part is refused with the door's ordinary
 * "not an email address" answer.
 *
 * DEFENCE IN DEPTH behind the exact-match re-check (the *LookAlikeAddress tests).
 * Production's address columns are `utf8mb4_unicode_ci`, where `gmaíl.com` and
 * `gmail.com` compare equal; `gmaíl.com` typed at a door is now `xn--…`, which no
 * collation equates with a stored ASCII domain, and the mail goes to the mailbox
 * that was really typed. Production stores no non-ASCII address, so a non-ASCII
 * local part can only be a look-alike and is turned away.
 *
 * What these tests can and cannot show on SQLite. SQLite compares bytes, so
 * "typed accented, stored plain" never matches here whatever the code does. What
 * IS observable, and asserted, is the transformation: a contact stored under the
 * punycode form is reached by the Unicode spelling (which it was not before),
 * the mail goes to the punycode address and never to the Unicode one, and a
 * non-ASCII local part is refused before a code is issued or a lookup runs.
 */
class SignInAddressAtTheDoorTest extends TestCase
{
    use RefreshDatabase;

    /** The Unicode spelling of a domain somebody could register as a look-alike. */
    private const UNICODE = 'person@gmaíl.com';

    /** A non-ASCII local part: refused, never converted. */
    private const ACCENTED_LOCAL = 'pérson@gmail.com';

    private const NOT_AN_ADDRESS = 'The email field must be a valid email address.';

    private const PASSWORD = 'jasmine-lantern-42-quiet';

    private Masjid $masjid;

    /** The address `UNICODE` becomes: the one a contact stored as ASCII holds. */
    private string $puny;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->masjid = $this->makeMasjid();

        $puny = ContactIdentity::submittedAddress(self::UNICODE);
        $this->assertNotNull($puny);
        $this->puny = $puny;
    }

    // ------------------------------------------------------------- the app doors

    #[Test]
    public function the_app_code_request_mails_the_punycode_address_and_never_the_unicode_one(): void
    {
        $this->member($this->puny, self::PASSWORD);

        $this->appPost('request-code', ['email' => self::UNICODE])->assertStatus(202);

        Mail::assertSent(FamilyLoginCodeMail::class, 1);
        Mail::assertSent(FamilyLoginCodeMail::class, fn (FamilyLoginCodeMail $mail) => $mail->hasTo($this->puny));
        $this->assertSame(0, AppSignupCode::withoutMasjidScope()->where('email', self::UNICODE)->count(), 'A code row holds the Unicode spelling.');
        $this->assertSame(1, AppSignupCode::withoutMasjidScope()->where('email', $this->puny)->count());
    }

    #[Test]
    public function the_app_code_door_reaches_the_contact_stored_under_the_punycode_address(): void
    {
        $member = $this->member($this->puny, self::PASSWORD);

        $this->appPost('request-code', ['email' => self::UNICODE])->assertStatus(202);
        $code = $this->appCodeMailedTo($this->puny);

        $response = $this->appPost('verify-code', ['email' => self::UNICODE, 'code' => $code]);

        $response->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame($member->id, $response->json('data.contact.id'));
        $this->assertSame($this->puny, $response->json('data.contact.login_email'));
    }

    #[Test]
    public function the_app_password_door_reaches_the_contact_stored_under_the_punycode_address(): void
    {
        $member = $this->member($this->puny, self::PASSWORD);

        $response = $this->appPost('password', ['email' => self::UNICODE, 'password' => self::PASSWORD]);

        $response->assertOk();
        $this->assertSame($member->id, $response->json('data.contact.id'));
    }

    #[Test]
    public function a_new_app_member_at_a_unicode_domain_is_stored_with_the_punycode_address_in_both_columns(): void
    {
        // `contacts.email` is written by this door too, not only `login_email`, and
        // both take the form the address is looked up in, never the Unicode spelling.
        $this->appPost('request-code', ['email' => self::UNICODE])->assertStatus(202);
        $code = $this->appCodeMailedTo($this->puny);

        $response = $this->appPost('verify-code', [
            'email' => self::UNICODE,
            'code' => $code,
            'first_name' => 'New',
            'last_name' => 'Member',
        ]);

        $response->assertOk()->assertJsonPath('data.created', true);
        $stored = Contact::withoutMasjidScope()->findOrFail($response->json('data.contact.id'));
        $this->assertSame($this->puny, $stored->email, 'contacts.email holds the Unicode spelling.');
        $this->assertSame($this->puny, $stored->login_email);
    }

    #[Test]
    public function every_app_door_refuses_a_non_ascii_local_part_as_not_an_address(): void
    {
        foreach ([
            'request-code' => ['email' => self::ACCENTED_LOCAL],
            'verify-code' => ['email' => self::ACCENTED_LOCAL, 'code' => '123456'],
            'password' => ['email' => self::ACCENTED_LOCAL, 'password' => self::PASSWORD],
        ] as $door => $body) {
            $this->assertNotAnAddress($this->appPost($door, $body), "app {$door}");
        }

        Mail::assertNothingSent();
        $this->assertSame(0, AppSignupCode::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_app_service_refuses_a_non_ascii_local_part_without_a_request(): void
    {
        app(TenantContext::class)->set($this->masjid->id);
        $service = app(MemberSignupService::class);

        $service->issue(self::ACCENTED_LOCAL);
        $service->issueAccountDeletionCode(self::ACCENTED_LOCAL);

        $this->assertNull($service->redeem(self::ACCENTED_LOCAL, '123456'));
        $this->assertNull($service->attemptPassword(self::ACCENTED_LOCAL, self::PASSWORD));
        $this->assertFalse($service->redeemAccountDeletionCode(self::ACCENTED_LOCAL, '123456'));

        Mail::assertNothingSent();
        $this->assertSame(0, AppSignupCode::withoutMasjidScope()->count());
    }

    // --------------------------------------------------------- the parent doors

    #[Test]
    public function the_family_code_door_mails_the_stored_punycode_address_for_the_unicode_spelling(): void
    {
        $parent = $this->parent($this->puny, self::PASSWORD);

        $this->familyPost('request-code', ['email' => self::UNICODE])->assertStatus(202);

        Mail::assertSent(FamilyLoginCodeMail::class, 1);
        Mail::assertSent(FamilyLoginCodeMail::class, fn (FamilyLoginCodeMail $mail) => $mail->hasTo($this->puny));

        $code = Mail::sent(FamilyLoginCodeMail::class)->last()?->code;
        $this->assertNotNull($code);

        $response = $this->familyPost('verify-code', ['email' => self::UNICODE, 'code' => $code]);

        $response->assertOk();
        $this->assertSame($parent->id, $response->json('data.contact.id'));
    }

    #[Test]
    public function the_family_password_door_reaches_the_contact_stored_under_the_punycode_address(): void
    {
        $parent = $this->parent($this->puny, self::PASSWORD);

        $response = $this->familyPost('password', ['email' => self::UNICODE, 'password' => self::PASSWORD]);

        $response->assertOk();
        $this->assertSame($parent->id, $response->json('data.contact.id'));
    }

    #[Test]
    public function every_family_door_refuses_a_non_ascii_local_part_as_not_an_address(): void
    {
        $this->parent('person@gmail.com', self::PASSWORD);

        foreach ([
            'request-code' => ['email' => self::ACCENTED_LOCAL],
            'verify-code' => ['email' => self::ACCENTED_LOCAL, 'code' => '123456'],
            'password' => ['email' => self::ACCENTED_LOCAL, 'password' => self::PASSWORD],
        ] as $door => $body) {
            $this->assertNotAnAddress($this->familyPost($door, $body), "family {$door}");
        }

        Mail::assertNothingSent();
        $this->assertSame(0, ContactLoginCode::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_family_service_refuses_a_non_ascii_local_part_without_a_request(): void
    {
        $this->parent('person@gmail.com', self::PASSWORD);
        app(TenantContext::class)->set($this->masjid->id);
        $logins = app(FamilyLoginService::class);

        $this->assertNull($logins->resolveContact(self::ACCENTED_LOCAL));
        $logins->issue(self::ACCENTED_LOCAL);
        $this->assertNull($logins->redeem(self::ACCENTED_LOCAL, '123456'));

        Mail::assertNothingSent();
    }

    // -------------------------------------------------- the office's own doors

    #[Test]
    public function enabling_a_family_login_stores_the_punycode_address_and_refuses_a_non_ascii_local_part(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $admin->id;
        $this->masjid->save();

        $guardian = $this->guardian();

        $this->asAdmin($admin);
        $refused = $this->postJson($this->enableUrl($guardian), ['login_email' => self::ACCENTED_LOCAL]);

        $refused->assertStatus(422);
        $this->assertSame('That does not look like an email address.', $refused->json('data.login_email.0'));
        $this->assertNull(Contact::withoutMasjidScope()->findOrFail($guardian->id)->login_email);

        // The Unicode spelling is stored as the address a parent's sign-in will
        // look up, so the grant can actually be used.
        $this->asAdmin($admin);
        $this->postJson($this->enableUrl($guardian), ['login_email' => self::UNICODE])->assertOk();

        $this->assertSame($this->puny, Contact::withoutMasjidScope()->findOrFail($guardian->id)->login_email);
    }

    // ------------------------------------------------ the public deletion page

    #[Test]
    public function the_deletion_page_mails_the_punycode_address_and_refuses_a_non_ascii_local_part(): void
    {
        $this->masjid->forceFill(['listed_at' => now()])->save();

        $this->asANewRequest();
        $this->post('/account-deletion', ['masjid_id' => $this->masjid->id, 'email' => self::UNICODE])->assertOk();
        $this->asANewRequest();

        Mail::assertSent(FamilyLoginCodeMail::class, 1);
        Mail::assertSent(FamilyLoginCodeMail::class, fn (FamilyLoginCodeMail $mail) => $mail->hasTo($this->puny) && $mail->isForAccountDeletion());

        $refused = $this->post('/account-deletion', ['masjid_id' => $this->masjid->id, 'email' => self::ACCENTED_LOCAL]);
        $this->asANewRequest();

        $refused->assertStatus(422);
        $refused->assertSee('Enter a full email address, like name@example.com.');
        Mail::assertSent(FamilyLoginCodeMail::class, 1);
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

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    private function asAdmin(User $admin): void
    {
        $this->asANewRequest();
        Sanctum::actingAs($admin);
    }

    /** An app member (a verified contact) at `$address`, with a password. */
    private function member(string $address, string $password): Contact
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
            'password' => Hash::make($password),
            'password_set_at' => now(),
        ])->save();

        return $contact->refresh();
    }

    /** A parent whose family login is on at `$address`, with a password. */
    private function parent(string $address, string $password): Contact
    {
        $this->asANewRequest();

        $contact = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'email' => 'roster-' . uniqid() . '@test.local',
        ]);

        $contact->forceFill([
            'login_email' => $address,
            'login_enabled_at' => now(),
            'verified_at' => now(),
            'password' => Hash::make($password),
            'password_set_at' => now(),
        ])->save();

        return $contact->refresh();
    }

    /** A contact who is somebody's guardian, with no login yet. */
    private function guardian(): Contact
    {
        $this->asANewRequest();

        $guardian = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $name = 'Class ' . uniqid();
        $group = Group::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'kind' => 'class',
        ]);
        $ward = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'contact_id' => $ward->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);
        GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'contact_id' => $guardian->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $ward->id,
            'joined_at' => now(),
        ]);

        return $guardian;
    }

    private function enableUrl(Contact $contact): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/contacts/{$contact->id}/family-login";
    }

    /** @param  array<string, mixed>  $body */
    private function appPost(string $door, array $body): TestResponse
    {
        return $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/{$door}", $body);
    }

    /** @param  array<string, mixed>  $body */
    private function familyPost(string $door, array $body): TestResponse
    {
        return $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/{$door}", $body);
    }

    private function appCodeMailedTo(string $address): string
    {
        $code = Mail::sent(
            FamilyLoginCodeMail::class,
            fn (FamilyLoginCodeMail $mail) => $mail->hasTo($address) && ! $mail->isForAccountDeletion(),
        )->last()?->code;

        $this->assertNotNull($code, "No sign-in code was mailed to {$address}.");

        return $code;
    }

    private function assertNotAnAddress(TestResponse $response, string $door): void
    {
        $response->assertStatus(422);
        $this->assertSame(self::NOT_AN_ADDRESS, $response->json('data.email.0'), "{$door} did not refuse with the ordinary sentence.");
    }
}
