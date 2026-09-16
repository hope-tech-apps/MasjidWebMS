<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Member\MemberAccountDeletion;
use App\Services\Member\NewMemberNameRequired;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The app's email + password sign-in (2026-09-16).
 *
 * Two changes, pinned together because each is half of one feature:
 *
 *  1. `POST .../auth/verify-code` takes an optional `password`. With a correct
 *     code it becomes the contact's password in the same transaction. That is
 *     "Create an account" (name + password + code) and "Forgot password?"
 *     (password + code).
 *  2. `POST .../auth/password` signs in with an address and a password. Every
 *     failure is the verify-code 410, byte for byte.
 *
 * What this file holds the line on, beyond "it works":
 *
 *  - NO ORACLE. Every refusal at the password door is compared as a whole BODY
 *    against verify-code's refusal, never as a status. An oracle comes back as
 *    a sentence far more often than as a status code.
 *  - NOTHING IS SPENT BEFORE THE MAILBOX IS PROVEN, AND NOTHING IS SET BY A
 *    REFUSED REDEEM. A short password is refused before the code is read; a
 *    blank name keeps the code and sets no password.
 *  - ONE PASSWORD PER PERSON. Setting it from the app replaces a parent-portal
 *    password and ends that person's other sessions, family ones included.
 *    The shared password is the owner's choice (2026-09-16); ending the other
 *    sessions is the sign-in contract's. It never turns a portal login on.
 */
class MemberPasswordSignInTest extends TestCase
{
    use RefreshDatabase;

    /** verify-code's refusal, verbatim. The password door must answer exactly this. */
    private const GONE = '{"status":"error","message":"That code is no longer usable. Please request a new one.","data":{}}';

    /** Long enough for the rule and not a published phrase (see FamilyPasswordTest::GOOD). */
    private const GOOD = 'jasmine-lantern-42-quiet';

    private const OTHER = 'copper-kettle-19-morning';

    private const SHORT_MESSAGE = 'Please choose a password of at least 12 characters. A short phrase you will remember works well.';

    private Masjid $masjid;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->masjid = $this->makeMasjid();

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    // ------------------------------------------------ create account, sign in

    #[Test]
    public function a_new_member_creates_an_account_with_a_password_and_then_signs_in_with_it(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $created = $this->verify($this->masjid, $email, $code, [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => self::GOOD,
        ]);

        $created->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.contact.first_name', 'Amina');

        $contact = $this->contactAt($this->masjid, $email);
        $this->assertTrue($contact->hasFamilyPassword());
        $this->assertNotSame(self::GOOD, $contact->getAuthPassword(), 'The plaintext must never be stored.');
        $this->assertTrue(Hash::check(self::GOOD, $contact->getAuthPassword()));
        $this->assertNotNull($this->codeRow($email)->consumed_at);

        $signedIn = $this->signIn($this->masjid, $email, self::GOOD);

        $signedIn->assertOk();

        // The same body as verify-code: same keys, same contact projection, the
        // same kind of token, and `created` false.
        $this->assertSame(['status', 'data'], array_keys($signedIn->json()));
        $this->assertSame(['token', 'created', 'contact'], array_keys($signedIn->json('data')));
        $this->assertSame(
            array_keys($created->json('data.contact')),
            array_keys($signedIn->json('data.contact')),
        );
        $this->assertSame(['id', 'masjid_id', 'first_name', 'last_name', 'login_email'], array_keys($signedIn->json('data.contact')));
        $this->assertFalse($signedIn->json('data.created'));
        $this->assertSame((int) $contact->id, $signedIn->json('data.contact.id'));
        $this->assertSame($email, $signedIn->json('data.contact.login_email'));

        $token = $signedIn->json('data.token');
        $row = PersonalAccessToken::findToken($token);
        $this->assertNotNull($row);
        $this->assertSame(Contact::MEMBER_TOKEN_ABILITIES, $row->abilities);
        $this->assertSame(Contact::MEMBER_TOKEN_FOR_LOGIN_EMAIL, $row->name);

        // A real member session, not merely a token-shaped string.
        $this->asANewRequest();
        $this->withToken($token)
            ->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")
            ->assertOk();

        $this->assertNotNull($contact->fresh()->last_login_at);

        foreach ([$created, $signedIn] as $response) {
            $this->assertStringNotContainsString(self::GOOD, $response->getContent());
            $this->assertStringNotContainsString('$2y$', $response->getContent());
        }

        $this->assertPasswordNeverLogged(self::GOOD);
    }

    #[Test]
    public function the_address_is_matched_without_regard_to_case_or_spaces(): void
    {
        $email = $this->address();
        $this->member($this->masjid, $email, password: self::GOOD);

        $this->signIn($this->masjid, '  ' . strtoupper($email) . ' ', self::GOOD)->assertOk();
    }

    #[Test]
    public function the_door_accepts_the_form_encoded_body_a_client_may_send(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $this->asANewRequest();
        $this->post("/api/mobile/masjids/{$this->masjid->id}/auth/verify-code", [
            'email' => $email,
            'code' => $code,
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => self::GOOD,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->asANewRequest();
        $this->post("/api/mobile/masjids/{$this->masjid->id}/auth/password", [
            'email' => $email,
            'password' => self::GOOD,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', false);
    }

    // ------------------------------------------------------- no oracle

    #[Test]
    public function every_refusal_at_the_password_door_is_byte_identical_to_the_verify_code_refusal(): void
    {
        // verify-code's own refusal, produced by the endpoint rather than copied.
        $probe = $this->address();
        $probeCode = $this->requestCode($this->masjid, $probe);
        $codeRefusal = $this->verify($this->masjid, $probe, $this->wrongCode($probeCode));
        $codeRefusal->assertStatus(410);
        $this->assertSame(self::GONE, $codeRefusal->getContent());

        $member = $this->address();
        $this->member($this->masjid, $member, password: self::GOOD);

        $noPassword = $this->address();
        $this->member($this->masjid, $noPassword);

        $neverVerified = $this->address();
        $this->member($this->masjid, $neverVerified, password: self::GOOD, verified: false);

        $revoked = $this->address();
        $this->member($this->masjid, $revoked, password: self::GOOD, extra: ['login_revoked_at' => now()]);

        $trashed = $this->address();
        $this->member($this->masjid, $trashed, password: self::GOOD)->delete();

        // Two contacts behind one address, differing only in case. Production
        // is utf8mb4_bin, so the unique index allows both; the lookup refuses.
        $ambiguous = $this->address();
        $this->member($this->masjid, $ambiguous, password: self::GOOD);
        $this->member($this->masjid, strtoupper($ambiguous), password: self::GOOD);

        // A password on a contact the office knows by `email` alone. The code
        // door links such a contact; a password is a credential for
        // `login_email` only.
        $officeOnly = $this->address();
        $this->member($this->masjid, $officeOnly, password: self::GOOD, extra: ['login_email' => null]);

        // A contact this organisation's CRM holds only at ANOTHER organisation.
        $elsewhere = $this->address();
        $this->member($this->makeMasjid(), $elsewhere, password: self::GOOD);

        $cases = [
            'an address nobody holds' => [$this->address(), self::GOOD],
            'a wrong password' => [$member, self::OTHER],
            'the right password in the wrong case' => [$member, strtoupper(self::GOOD)],
            'a member who never chose a password' => [$noPassword, self::GOOD],
            'a contact that never proved the address to the app' => [$neverVerified, self::GOOD],
            'a revoked contact with the right password' => [$revoked, self::GOOD],
            'a deleted contact with the right password' => [$trashed, self::GOOD],
            'an address two contacts share' => [$ambiguous, self::GOOD],
            'a password that belongs to no login address' => [$officeOnly, self::GOOD],
            'a member of another organisation' => [$elsewhere, self::GOOD],
        ];

        foreach ($cases as $label => [$email, $password]) {
            $response = $this->signIn($this->masjid, $email, $password);

            $response->assertStatus(410);
            $this->assertSame($codeRefusal->getContent(), $response->getContent(), $label);
        }

        $this->assertSame(0, PersonalAccessToken::count(), 'A refusal minted a token.');

        // The control: the same fixtures DO open with the right input, so the
        // refusals above are the gates, not a broken door.
        $this->signIn($this->masjid, $member, self::GOOD)->assertOk();
    }

    /**
     * The body is not the only thing a caller can measure. A refusal that
     * skips the hash returns in a millisecond and a real comparison takes tens,
     * which would say "this address has a password here" as clearly as a
     * different sentence. Every path must do exactly one comparison against a
     * real digest. Counted at the hasher, and only when the digest is
     * non-empty, because comparing against '' returns before doing any work.
     */
    #[Test]
    public function every_refusal_and_the_success_do_exactly_one_real_hash_comparison(): void
    {
        $member = $this->address();
        $this->member($this->masjid, $member, password: self::GOOD);
        $noPassword = $this->address();
        $this->member($this->masjid, $noPassword);
        $neverVerified = $this->address();
        $this->member($this->masjid, $neverVerified, password: self::GOOD, verified: false);
        $revoked = $this->address();
        $this->member($this->masjid, $revoked, password: self::GOOD, extra: ['login_revoked_at' => now()]);
        $officeOnly = $this->address();
        $this->member($this->masjid, $officeOnly, password: self::GOOD, extra: ['login_email' => null]);

        $counter = new class(app('hash')) implements \Illuminate\Contracts\Hashing\Hasher
        {
            public int $realComparisons = 0;

            public function __construct(private \Illuminate\Contracts\Hashing\Hasher $inner)
            {
            }

            public function info($hashedValue)
            {
                return $this->inner->info($hashedValue);
            }

            public function make($value, array $options = [])
            {
                return $this->inner->make($value, $options);
            }

            public function check($value, $hashedValue, array $options = [])
            {
                if ($hashedValue !== null && $hashedValue !== '') {
                    $this->realComparisons++;
                }

                return $this->inner->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = [])
            {
                return $this->inner->needsRehash($hashedValue, $options);
            }

            public function __call($method, $arguments)
            {
                return $this->inner->{$method}(...$arguments);
            }
        };

        Hash::swap($counter);

        $cases = [
            'an address nobody holds' => [$this->address(), self::GOOD, 410],
            'a wrong password' => [$member, self::OTHER, 410],
            'no password chosen' => [$noPassword, self::GOOD, 410],
            'never verified' => [$neverVerified, self::GOOD, 410],
            'revoked' => [$revoked, self::GOOD, 410],
            'a password on no login address' => [$officeOnly, self::GOOD, 410],
            'the right password' => [$member, self::GOOD, 200],
        ];

        foreach ($cases as $label => [$email, $password, $status]) {
            $counter->realComparisons = 0;

            $this->signIn($this->masjid, $email, $password)->assertStatus($status);

            $this->assertSame(1, $counter->realComparisons, $label);
        }
    }

    #[Test]
    public function a_member_who_signed_up_with_a_code_alone_cannot_sign_in_with_any_password(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);
        $this->verify($this->masjid, $email, $code, ['first_name' => 'Amina', 'last_name' => 'Yusuf'])->assertOk();

        $contact = $this->contactAt($this->masjid, $email);
        $this->assertFalse($contact->hasFamilyPassword());
        $this->assertSame('', $contact->getAuthPassword());

        // Each of these reaches the hasher and loses there. A blank one never
        // gets that far: `required` makes it a 422 about the caller's own input.
        foreach ([self::GOOD, 'password', 'x'] as $attempt) {
            $response = $this->signIn($this->masjid, $email, $attempt);
            $response->assertStatus(410);
            $this->assertSame(self::GONE, $response->getContent());
        }
    }

    #[Test]
    public function a_parent_portal_password_does_not_open_the_app_until_the_mailbox_is_proven_here(): void
    {
        $email = $this->address();
        $parent = $this->member($this->masjid, $email, password: self::GOOD, verified: false, extra: [
            'login_enabled_at' => now(),
            'signup_source' => null,
        ]);

        // The same credentials open the portal...
        $this->asANewRequest();
        $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/password", [
            'email' => $email,
            'password' => self::GOOD,
        ])->assertOk();

        // ...but not the app: this contact has never proved the address to it.
        $refused = $this->signIn($this->masjid, $email, self::GOOD);
        $refused->assertStatus(410);
        $this->assertSame(self::GONE, $refused->getContent());

        // "Forgot password?" proves it, and the app then opens.
        $code = $this->requestCode($this->masjid, $email);
        $this->verify($this->masjid, $email, $code, ['password' => self::GOOD])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.contact.id', (int) $parent->id);

        $this->signIn($this->masjid, $email, self::GOOD)->assertOk();
    }

    #[Test]
    public function a_password_set_at_one_organisation_does_not_sign_in_at_another(): void
    {
        $other = $this->makeMasjid();
        $email = $this->address();
        $this->member($this->masjid, $email, password: self::GOOD);

        $elsewhere = $this->signIn($other, $email, self::GOOD);
        $elsewhere->assertStatus(410);
        $this->assertSame(self::GONE, $elsewhere->getContent());

        // A same-address contact at the other organisation with its OWN
        // password: each password opens only its own organisation.
        $this->member($other, $email, password: self::OTHER);

        $this->assertSame(self::GONE, $this->signIn($other, $email, self::GOOD)->getContent());
        $this->assertSame(self::GONE, $this->signIn($this->masjid, $email, self::OTHER)->getContent());

        $here = $this->signIn($this->masjid, $email, self::GOOD)->assertOk();
        $there = $this->signIn($other, $email, self::OTHER)->assertOk();

        $this->assertSame((int) $this->masjid->id, $here->json('data.contact.masjid_id'));
        $this->assertSame((int) $other->id, $there->json('data.contact.masjid_id'));
    }

    // ------------------------------------------- verify-code with a password

    #[Test]
    public function forgot_password_sets_the_new_password_on_a_known_member_and_it_then_signs_in(): void
    {
        $email = $this->address();
        $known = $this->member($this->masjid, $email, password: self::OTHER);

        $code = $this->requestCode($this->masjid, $email);
        $this->verify($this->masjid, $email, $code, ['password' => self::GOOD])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.contact.id', (int) $known->id)
            // A link copies nothing about the person from the request.
            ->assertJsonPath('data.contact.first_name', 'On');

        $fresh = $known->fresh();
        $this->assertTrue(Hash::check(self::GOOD, $fresh->getAuthPassword()));
        $this->assertFalse(Hash::check(self::OTHER, $fresh->getAuthPassword()));

        $this->assertSame(self::GONE, $this->signIn($this->masjid, $email, self::OTHER)->getContent());
        $this->signIn($this->masjid, $email, self::GOOD)->assertOk();

        // No family login, so nothing on the office's family access history,
        // which would otherwise keep this contact forever (see the deletion test).
        $this->assertSame(0, ContactLoginEvent::withoutMasjidScope()->where('contact_id', $known->id)->count());
    }

    #[Test]
    public function forgot_password_links_an_office_contact_known_only_by_email_and_gives_it_the_password(): void
    {
        $email = $this->address();
        $office = $this->member($this->masjid, $email, verified: false, extra: [
            'login_email' => null,
            'signup_source' => null,
        ]);

        $code = $this->requestCode($this->masjid, $email);
        $this->verify($this->masjid, $email, $code, ['password' => self::GOOD])
            ->assertOk()
            ->assertJsonPath('data.contact.id', (int) $office->id);

        $fresh = $office->fresh();
        $this->assertSame($email, $fresh->login_email);
        $this->assertNotNull($fresh->verified_at);

        $this->signIn($this->masjid, $email, self::GOOD)->assertOk();
    }

    #[Test]
    public function a_verify_code_without_a_password_leaves_the_password_alone(): void
    {
        $email = $this->address();
        $known = $this->member($this->masjid, $email, password: self::GOOD);
        $before = $known->getAuthPassword();
        $stale = $known->createMemberToken()->plainTextToken;

        foreach ([[], ['password' => ''], ['password' => null]] as $extra) {
            $code = $this->requestCode($this->masjid, $email);
            $this->verify($this->masjid, $email, $code, $extra)->assertOk();
        }

        $this->assertSame($before, $known->fresh()->getAuthPassword());

        // And a plain code sign-in ends no other session.
        $this->asANewRequest();
        $this->withToken($stale)
            ->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")
            ->assertOk();
    }

    #[Test]
    public function a_short_password_is_a_422_before_the_code_is_read_and_spends_nothing(): void
    {
        $new = $this->address();
        $known = $this->address();
        $this->member($this->masjid, $known, password: self::GOOD);

        $newCode = $this->requestCode($this->masjid, $new);
        $knownCode = $this->requestCode($this->masjid, $known);

        $expected = [
            'status' => 'failed',
            'message' => self::SHORT_MESSAGE,
            'data' => ['password' => [self::SHORT_MESSAGE]],
        ];

        $withRightCode = $this->verify($this->masjid, $new, $newCode, [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => 'too-short',
        ]);
        $withRightCode->assertStatus(422)->assertExactJson($expected);

        // The same answer with a WRONG code, and for an address that has an
        // account: the rule looks only at what was typed.
        $withWrongCode = $this->verify($this->masjid, $new, $this->wrongCode($newCode), ['password' => 'too-short']);
        $forKnown = $this->verify($this->masjid, $known, $knownCode, ['password' => 'too-short']);

        $this->assertSame($withRightCode->getContent(), $withWrongCode->getContent());
        $this->assertSame($withRightCode->getContent(), $forKnown->getContent());

        foreach ([$new, $known] as $email) {
            $row = $this->codeRow($email);
            $this->assertNull($row->consumed_at, 'A refused password spent the code.');
            $this->assertSame(0, (int) $row->attempts, 'A refused password was charged as a guess.');
        }

        $this->assertSame(0, Contact::withoutMasjidScope()->withTrashed()->where('login_email', $new)->count());
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertTrue(Hash::check(self::GOOD, $this->contactAt($this->masjid, $known)->getAuthPassword()));

        // The same codes still work once the password is long enough.
        $this->verify($this->masjid, $new, $newCode, [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => self::OTHER,
        ])->assertOk();
    }

    /**
     * Laravel skips every non-implicit rule for a value made only of spaces, and
     * TrimStrings leaves password fields alone. Without an implicit rule, a
     * row of spaces would pass the length check and become the password while
     * the response said the account was created.
     */
    #[Test]
    public function a_password_of_spaces_is_refused_not_ignored_and_not_stored(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        foreach ([' ', str_repeat(' ', 14), "\t\t\t\t\t\t\t\t\t\t\t\t\t"] as $blank) {
            $this->verify($this->masjid, $email, $code, [
                'first_name' => 'Amina',
                'last_name' => 'Yusuf',
                'password' => $blank,
            ])->assertStatus(422)->assertExactJson([
                'status' => 'failed',
                'message' => self::SHORT_MESSAGE,
                'data' => ['password' => [self::SHORT_MESSAGE]],
            ]);
        }

        $this->assertNull($this->codeRow($email)->consumed_at);
        $this->assertSame(0, Contact::withoutMasjidScope()->withTrashed()->where('email', $email)->count());
    }

    #[Test]
    public function the_password_rule_is_the_parent_portals_rule(): void
    {
        config(['family.password.min_length' => 20]);

        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        // Nineteen characters: long enough for the default, not for the portal's
        // configured minimum. One policy for one password.
        $this->verify($this->masjid, $email, $code, [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => str_repeat('k', 19),
        ])->assertStatus(422)->assertJsonPath('data.password.0', str_replace('12', '20', self::SHORT_MESSAGE));

        $this->assertNull($this->codeRow($email)->consumed_at);
    }

    #[Test]
    public function a_wrong_code_with_a_password_is_the_same_410_and_sets_nothing(): void
    {
        $email = $this->address();
        $known = $this->member($this->masjid, $email, password: self::OTHER);
        $code = $this->requestCode($this->masjid, $email);

        $response = $this->verify($this->masjid, $email, $this->wrongCode($code), ['password' => self::GOOD]);

        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());
        $this->assertTrue(Hash::check(self::OTHER, $known->fresh()->getAuthPassword()));
        $this->assertSame(1, (int) $this->codeRow($email)->attempts);
    }

    #[Test]
    public function a_revoked_contact_redeeming_a_correct_code_with_a_password_gets_the_410_and_keeps_its_password(): void
    {
        $email = $this->address();
        $revoked = $this->member($this->masjid, $email, password: self::OTHER, extra: ['login_revoked_at' => now()]);

        // `issue()` mails nothing to a revoked contact. Write the row directly.
        $code = $this->mintCode($this->masjid, $email);

        $response = $this->verify($this->masjid, $email, $code, ['password' => self::GOOD]);

        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());
        $this->assertTrue(Hash::check(self::OTHER, $revoked->fresh()->getAuthPassword()));
        $this->assertSame(0, PersonalAccessToken::count());
    }

    #[Test]
    public function a_blank_name_with_a_password_is_asked_for_the_name_keeps_the_code_and_sets_nothing(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);

        $this->verify($this->masjid, $email, $code, [
            'first_name' => 'Amina',
            'last_name' => '',
            'password' => self::GOOD,
        ])->assertStatus(422)->assertExactJson([
            'status' => 'failed',
            'message' => NewMemberNameRequired::MESSAGE,
            'data' => ['last_name' => ['Enter your last name.']],
        ]);

        $row = $this->codeRow($email);
        $this->assertNull($row->consumed_at, 'Asking for the name spent the code.');
        $this->assertSame(0, (int) $row->attempts);
        $this->assertSame(0, Contact::withoutMasjidScope()->withTrashed()->where('email', $email)->count(), 'No contact, so no password either.');
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, ContactLoginEvent::withoutMasjidScope()->count());

        $refused = $this->signIn($this->masjid, $email, self::GOOD);
        $this->assertSame(self::GONE, $refused->getContent());

        // The same code, now with the name.
        $this->verify($this->masjid, $email, $code, [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => self::GOOD,
        ])->assertOk()->assertJsonPath('data.created', true);

        $this->assertTrue(Hash::check(self::GOOD, $this->contactAt($this->masjid, $email)->getAuthPassword()));
        $this->signIn($this->masjid, $email, self::GOOD)->assertOk();
    }

    // ---------------------------------------- one password, both realms

    /**
     * One password per person is the owner's choice (2026-09-16). A parent who
     * uses "Create an account" or "Forgot password?" in the app therefore
     * replaces the portal password. Every other session the contact holds then
     * ends, family and hand-off tokens included — that half comes from the
     * sign-in contract reusing FamilyPasswordService::set(), not from the owner.
     */
    #[Test]
    public function setting_a_password_from_the_app_overwrites_a_portal_password_and_ends_every_other_session(): void
    {
        $email = $this->address();
        $parent = $this->member($this->masjid, $email, password: self::OTHER, extra: [
            'login_enabled_at' => now(),
            'signup_source' => null,
        ]);
        $setBefore = $parent->password_set_at;

        $familyToken = $parent->createFamilyToken()->plainTextToken;
        $memberToken = $parent->createMemberToken(Contact::MEMBER_TOKEN_FOR_LOGIN_EMAIL)->plainTextToken;
        $handoff = $parent->createStudentHandoffToken(1)->plainTextToken;
        $bystander = $this->member($this->masjid, $this->address(), password: self::OTHER);
        $bystanderToken = $bystander->createMemberToken()->plainTextToken;

        // The old sessions are live to begin with.
        $this->asANewRequest();
        $this->withToken($familyToken)->getJson("/api/family/masjids/{$this->masjid->id}/me")->assertOk();
        $this->asANewRequest();
        $this->withToken($memberToken)->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")->assertOk();

        $this->travel(1)->minutes();

        // "Create an account" on an address that already has one: the name is
        // ignored, the password replaces the old one.
        $code = $this->requestCode($this->masjid, $email);
        $response = $this->verify($this->masjid, $email, $code, [
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'password' => self::GOOD,
        ]);
        $response->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.contact.first_name', 'On');
        $newToken = $response->json('data.token');

        $fresh = $parent->fresh();
        $this->assertTrue(Hash::check(self::GOOD, $fresh->getAuthPassword()));
        $this->assertTrue($fresh->password_set_at->greaterThan($setBefore));
        $this->assertNotNull($fresh->login_enabled_at, 'The portal login is untouched.');

        // Every other session is gone. Only the token this exchange minted lives.
        $remaining = PersonalAccessToken::query()
            ->where('tokenable_type', $parent->getMorphClass())
            ->where('tokenable_id', $parent->id)
            ->get();
        $this->assertCount(1, $remaining);
        $this->assertSame(PersonalAccessToken::findToken($newToken)->id, $remaining->first()->id);

        foreach ([$familyToken, $handoff] as $dead) {
            $this->asANewRequest();
            $this->withToken($dead)->getJson("/api/family/masjids/{$this->masjid->id}/me")->assertStatus(401);
        }

        $this->asANewRequest();
        $this->withToken($memberToken)->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")->assertStatus(401);

        $this->asANewRequest();
        $this->withToken($newToken)->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")->assertOk();

        // Another person's session is not touched.
        $this->asANewRequest();
        $this->withToken($bystanderToken)->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")->assertOk();

        // Both doors now take the new password and refuse the old one.
        $this->asANewRequest();
        $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/password", [
            'email' => $email, 'password' => self::GOOD,
        ])->assertOk();
        $this->asANewRequest();
        $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/password", [
            'email' => $email, 'password' => self::OTHER,
        ])->assertStatus(410);

        $this->signIn($this->masjid, $email, self::GOOD)->assertOk();
        $this->assertSame(self::GONE, $this->signIn($this->masjid, $email, self::OTHER)->getContent());

        // A portal login has an access history, and it shows the change, with
        // no operator behind it.
        $event = ContactLoginEvent::withoutMasjidScope()
            ->where('contact_id', $parent->id)
            ->where('action', ContactLoginEvent::ACTION_PASSWORD_SET)
            ->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->actor_name);
        $this->assertNull($event->actor_email);
        $this->assertSame($email, $event->login_email);

        $this->assertPasswordNeverLogged(self::GOOD);
    }

    #[Test]
    public function forgot_password_ends_a_members_other_app_sessions(): void
    {
        $email = $this->address();
        $member = $this->member($this->masjid, $email, password: self::OTHER);
        $phone = $member->createMemberToken(Contact::MEMBER_TOKEN_FOR_LOGIN_EMAIL)->plainTextToken;
        $tablet = $member->createMemberToken(Contact::MEMBER_TOKEN_FOR_LOGIN_EMAIL)->plainTextToken;

        $code = $this->requestCode($this->masjid, $email);
        $fresh = $this->verify($this->masjid, $email, $code, ['password' => self::GOOD])->assertOk()->json('data.token');

        foreach ([$phone, $tablet] as $dead) {
            $this->asANewRequest();
            $this->withToken($dead)->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")->assertStatus(401);
        }

        $this->asANewRequest();
        $this->withToken($fresh)->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")->assertOk();
    }

    #[Test]
    public function a_member_password_never_turns_a_portal_login_on(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);
        $token = $this->verify($this->masjid, $email, $code, [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => self::GOOD,
        ])->assertOk()->json('data.token');

        $signedIn = $this->signIn($this->masjid, $email, self::GOOD)->assertOk()->json('data.token');

        $contact = $this->contactAt($this->masjid, $email);
        $this->assertNull($contact->login_enabled_at);
        $this->assertFalse($contact->familyLoginIsActive());

        // The portal's password door refuses the same credentials...
        $this->asANewRequest();
        $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/password", [
            'email' => $email,
            'password' => self::GOOD,
        ])->assertStatus(410);

        // ...and the portal refuses both member tokens.
        foreach ([$token, $signedIn] as $memberToken) {
            $this->asANewRequest();
            $this->withToken($memberToken)
                ->getJson("/api/family/masjids/{$this->masjid->id}/me")
                ->assertStatus(401);
        }

        $this->assertNull($contact->fresh()->login_enabled_at);
    }

    // --------------------- a password belongs to the address it was chosen for

    /**
     * The review's reproduction (R1). The app links an office guardian known
     * only by a household address, and whoever reads that mailbox chooses a
     * password. The office then enables the parent portal at the parent's own
     * address. Before the fix, that password opened BOTH doors at the new
     * address, which nobody had proved, and its member token could delete the
     * portal login.
     */
    #[Test]
    public function a_password_chosen_through_a_household_address_does_not_follow_the_login_to_another(): void
    {
        $household = $this->address();
        $personal = $this->address();

        $parent = $this->member($this->masjid, $household, verified: false, extra: [
            'login_email' => null,
            'signup_source' => null,
        ]);
        $this->makeGuardian($parent);

        $code = $this->requestCode($this->masjid, $household);
        $codeToken = $this->verify($this->masjid, $household, $code, ['password' => self::GOOD])
            ->assertOk()
            ->assertJsonPath('data.contact.id', (int) $parent->id)
            ->json('data.token');
        $passwordToken = $this->signIn($this->masjid, $household, self::GOOD)->assertOk()->json('data.token');

        $admin = $this->officeAdmin();
        $this->asOffice($admin);
        $this->postJson($this->familyLoginUrl($parent), ['login_email' => $personal])->assertOk();

        $fresh = Contact::withoutMasjidScope()->findOrFail($parent->id);
        $this->assertSame($personal, $fresh->login_email);
        $this->assertNotNull($fresh->login_enabled_at);
        $this->assertFalse($fresh->hasFamilyPassword());
        $this->assertNull($fresh->getRawOriginal('password'));
        $this->assertNull($fresh->verified_at, 'Nobody proved the new address to the app.');
        $this->assertSame(0, $fresh->tokens()->count());

        // Neither password door opens the new address with that password...
        $this->asANewRequest();
        $portal = $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/password", [
            'email' => $personal,
            'password' => self::GOOD,
        ]);
        $portal->assertStatus(410);
        $this->assertSame(self::GONE, $this->signIn($this->masjid, $personal, self::GOOD)->getContent());

        // ...nor the household one, which is no longer this contact's address.
        $this->assertSame(self::GONE, $this->signIn($this->masjid, $household, self::GOOD)->getContent());

        // The sessions opened through the household address are over.
        foreach ([$codeToken, $passwordToken] as $dead) {
            $this->asANewRequest();
            $this->withToken($dead)
                ->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")
                ->assertStatus(401);
        }
        $this->flushHeaders();

        // The access history says what ended, under the address the password
        // belonged to, and which operator's act ended it.
        $events = ContactLoginEvent::withoutMasjidScope()
            ->where('contact_id', $parent->id)
            ->orderBy('id')
            ->get();
        $this->assertSame(
            [ContactLoginEvent::ACTION_PASSWORD_CLEARED, ContactLoginEvent::ACTION_ENABLED],
            $events->pluck('action')->all(),
        );
        $this->assertSame($household, $events[0]->login_email);
        $this->assertSame((int) $admin->id, (int) $events[0]->actor_user_id);
        $this->assertSame($personal, $events[1]->login_email);

        // The household reader no longer reaches this contact: a code at that
        // address is a new member, who is asked for a name.
        $code = $this->requestCode($this->masjid, $household);
        $this->verify($this->masjid, $household, $code, ['password' => self::OTHER])->assertStatus(422);
        $this->assertFalse(Contact::withoutMasjidScope()->findOrFail($parent->id)->hasFamilyPassword());

        // The parent proves their own address and is back in both doors.
        $code = $this->requestCode($this->masjid, $personal);
        $this->verify($this->masjid, $personal, $code, ['password' => self::OTHER])
            ->assertOk()
            ->assertJsonPath('data.contact.id', (int) $parent->id);
        $this->signIn($this->masjid, $personal, self::OTHER)->assertOk();
        $this->asANewRequest();
        $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/password", [
            'email' => $personal,
            'password' => self::OTHER,
        ])->assertOk();
    }

    /** Re-typing the address the app already proved changes nothing. */
    #[Test]
    public function enabling_the_portal_at_the_address_the_app_proved_keeps_the_password_and_the_session(): void
    {
        $household = $this->address();
        $parent = $this->member($this->masjid, $household, verified: false, extra: [
            'login_email' => null,
            'signup_source' => null,
        ]);
        $this->makeGuardian($parent);

        $code = $this->requestCode($this->masjid, $household);
        $token = $this->verify($this->masjid, $household, $code, ['password' => self::GOOD])
            ->assertOk()
            ->json('data.token');

        $this->asOffice($this->officeAdmin());
        $this->postJson($this->familyLoginUrl($parent), ['login_email' => $household])->assertOk();

        $fresh = Contact::withoutMasjidScope()->findOrFail($parent->id);
        $this->assertTrue($fresh->hasFamilyPassword());
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame(
            [ContactLoginEvent::ACTION_ENABLED],
            ContactLoginEvent::withoutMasjidScope()->where('contact_id', $parent->id)->pluck('action')->all(),
        );

        $this->asANewRequest();
        $this->withToken($token)
            ->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")
            ->assertOk();
        $this->flushHeaders();

        $this->signIn($this->masjid, $household, self::GOOD)->assertOk();
        $this->asANewRequest();
        $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/password", [
            'email' => $household,
            'password' => self::GOOD,
        ])->assertOk();
    }

    /**
     * An app member the office never enabled still holds `login_email`, so the
     * office can take the address for a guardian (with the confirmation). The
     * member's password, `verified_at` and sessions go with the address.
     */
    #[Test]
    public function giving_an_app_members_address_to_someone_else_takes_their_password_and_sessions_with_it(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);
        $holderToken = $this->verify($this->masjid, $email, $code, [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => self::GOOD,
        ])->assertOk()->json('data.token');
        $holder = $this->contactAt($this->masjid, $email);

        $guardian = $this->member($this->masjid, $this->address(), verified: false, extra: [
            'login_email' => null,
            'signup_source' => null,
        ]);
        $this->makeGuardian($guardian);

        $admin = $this->officeAdmin();
        $this->asOffice($admin);
        $this->postJson($this->familyLoginUrl($guardian), ['login_email' => $email])
            ->assertStatus(422)
            ->assertJsonPath('reassignable', true);

        $this->asOffice($admin);
        $this->postJson($this->familyLoginUrl($guardian), [
            'login_email' => $email,
            'reassign_address' => true,
        ])->assertOk();

        $released = Contact::withoutMasjidScope()->findOrFail($holder->id);
        $this->assertNull($released->login_email);
        $this->assertFalse($released->hasFamilyPassword());
        $this->assertNull($released->getRawOriginal('password'));
        $this->assertNull($released->verified_at);
        $this->assertSame(0, $released->tokens()->count());

        $this->asANewRequest();
        $this->withToken($holderToken)
            ->getJson("/api/mobile/masjids/{$this->masjid->id}/interests")
            ->assertStatus(401);
        $this->flushHeaders();

        // The address is the guardian's now, and the member's password opens
        // it at neither door.
        $this->assertSame(self::GONE, $this->signIn($this->masjid, $email, self::GOOD)->getContent());
        $this->asANewRequest();
        $this->postJson("/api/family/masjids/{$this->masjid->id}/auth/password", [
            'email' => $email,
            'password' => self::GOOD,
        ])->assertStatus(410);

        $events = ContactLoginEvent::withoutMasjidScope()
            ->where('contact_id', $holder->id)
            ->orderBy('id')
            ->get();
        $this->assertSame(
            [ContactLoginEvent::ACTION_PASSWORD_CLEARED, ContactLoginEvent::ACTION_ADDRESS_RELEASED],
            $events->pluck('action')->all(),
        );
        $this->assertSame($email, $events[0]->login_email);
        $this->assertSame((int) $admin->id, (int) $events[0]->actor_user_id);
    }

    /**
     * A contact with no login address can still carry a password from before
     * the release path cleared one. A code sign-in that gives it an address
     * must not turn that password into this address's password.
     */
    #[Test]
    public function a_code_sign_in_that_gives_a_contact_its_address_drops_a_password_left_from_another(): void
    {
        $email = $this->address();
        $contact = $this->member($this->masjid, $email, password: self::OTHER, extra: [
            'login_email' => null,
            'signup_source' => null,
        ]);

        $code = $this->requestCode($this->masjid, $email);
        $this->verify($this->masjid, $email, $code)
            ->assertOk()
            ->assertJsonPath('data.contact.id', (int) $contact->id);

        $fresh = Contact::withoutMasjidScope()->findOrFail($contact->id);
        $this->assertSame($email, $fresh->login_email);
        $this->assertFalse($fresh->hasFamilyPassword());
        $this->assertNull($fresh->getRawOriginal('password'));

        $this->assertSame(self::GONE, $this->signIn($this->masjid, $email, self::OTHER)->getContent());

        // No family login, so no row that would keep this contact on deletion.
        $this->assertSame(0, ContactLoginEvent::withoutMasjidScope()->where('contact_id', $contact->id)->count());

        // With a password in the same request, that password is the one set.
        $code = $this->requestCode($this->masjid, $email);
        $this->verify($this->masjid, $email, $code, ['password' => self::GOOD])->assertOk();
        $this->signIn($this->masjid, $email, self::GOOD)->assertOk();
    }

    #[Test]
    public function a_leftover_password_is_replaced_not_cleared_when_the_adopting_sign_in_brings_one(): void
    {
        $email = $this->address();
        $contact = $this->member($this->masjid, $email, password: self::OTHER, extra: [
            'login_email' => null,
            'signup_source' => null,
        ]);

        $code = $this->requestCode($this->masjid, $email);
        $this->verify($this->masjid, $email, $code, ['password' => self::GOOD])
            ->assertOk()
            ->assertJsonPath('data.contact.id', (int) $contact->id);

        $this->assertSame(self::GONE, $this->signIn($this->masjid, $email, self::OTHER)->getContent());
        $this->signIn($this->masjid, $email, self::GOOD)->assertOk();
    }

    // ---------------------------------------------- leaving still erases

    /**
     * Nearly every account the app creates now has a password. If the password
     * counted as office data, deleting such an account would keep the contact
     * (name and address) in the office's CRM instead of erasing it.
     */
    #[Test]
    public function an_account_created_with_a_password_is_still_erased_when_its_owner_deletes_it(): void
    {
        $email = $this->address();
        $code = $this->requestCode($this->masjid, $email);
        $this->verify($this->masjid, $email, $code, [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => self::GOOD,
        ])->assertOk();

        $token = $this->signIn($this->masjid, $email, self::GOOD)->assertOk()->json('data.token');
        $id = $this->contactAt($this->masjid, $email)->id;

        $this->asANewRequest();
        $this->deleteJson("/api/mobile/masjids/{$this->masjid->id}/me", [], ['Authorization' => 'Bearer ' . $token])
            ->assertOk();

        $this->assertNull(Contact::withoutMasjidScope()->withTrashed()->find($id), 'The account was kept, not erased.');

        $outcome = collect($this->logged)
            ->filter(fn (MessageLogged $e) => $e->message === 'Member account deleted')
            ->last();
        $this->assertNotNull($outcome);
        $this->assertSame(MemberAccountDeletion::OUTCOME_ERASED, $outcome->context['outcome']);
        $this->assertSame([], $outcome->context['kept_because']);

        $refused = $this->signIn($this->masjid, $email, self::GOOD);
        $this->assertSame(self::GONE, $refused->getContent());
    }

    #[Test]
    public function a_parent_who_reset_from_the_app_is_still_kept_when_they_delete(): void
    {
        $email = $this->address();
        $parent = $this->member($this->masjid, $email, password: self::OTHER, extra: ['login_enabled_at' => now()]);

        $code = $this->requestCode($this->masjid, $email);
        $token = $this->verify($this->masjid, $email, $code, ['password' => self::GOOD])->assertOk()->json('data.token');

        $this->asANewRequest();
        $this->deleteJson("/api/mobile/masjids/{$this->masjid->id}/me", [], ['Authorization' => 'Bearer ' . $token])
            ->assertOk();

        $kept = Contact::withoutMasjidScope()->find($parent->id);
        $this->assertNotNull($kept, 'An office-granted portal login is office data.');
        $this->assertNull($kept->getRawOriginal('password'));
        $this->assertNull($kept->password_set_at);
    }

    // ------------------------------------------------------- the gates

    #[Test]
    public function the_password_door_shares_verify_codes_per_address_allowance_and_throttles_strangers_identically(): void
    {
        $limit = (int) config('member.signup.verifications_per_hour_per_address');
        $this->assertGreaterThan(0, $limit);

        $known = $this->address();
        $this->member($this->masjid, $known, password: self::GOOD);
        $stranger = $this->address();

        $statuses = function (string $email): array {
            $seen = [];

            for ($i = 0; $i <= $this->limit(); $i++) {
                $seen[] = $this->signIn($this->masjid, $email, 'not-the-password-at-all')->getStatusCode();
            }

            return $seen;
        };

        $forKnown = $statuses($known);
        $forStranger = $statuses($stranger);

        $this->assertSame(array_fill(0, $limit, 410) + [$limit => 429], $forKnown);
        $this->assertSame($forKnown, $forStranger, 'A known address and a stranger must throttle at the same attempt.');

        // Once the bucket is spent, even the right password waits.
        $this->signIn($this->masjid, $known, self::GOOD)->assertStatus(429);

        // One allowance for both doors: guesses at verify-code draw it down too.
        $shared = $this->address();
        $this->member($this->masjid, $shared, password: self::GOOD);

        for ($i = 0; $i < $limit; $i++) {
            $this->verify($this->masjid, $shared, '000000')->assertStatus(410);
        }

        $this->signIn($this->masjid, $shared, self::GOOD)->assertStatus(429);
    }

    #[Test]
    public function the_password_door_carries_the_same_gates_as_its_siblings(): void
    {
        $email = $this->address();
        $this->member($this->masjid, $email, password: self::GOOD);

        // A malformed request is a 422 about what was typed, in the same shape
        // as verify-code's.
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/password", ['email' => $email])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['status', 'message', 'data' => ['password']]);

        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/password", ['email' => 'not an address', 'password' => self::GOOD])
            ->assertStatus(422)
            ->assertJsonStructure(['status', 'message', 'data' => ['email']]);

        // No strength rule where a password is presented: a short guess is the
        // same 410 as a long one.
        $this->assertSame(self::GONE, $this->signIn($this->masjid, $email, 'short')->getContent());

        // `whereNumber`: a segment that is not a number never reaches the door.
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$this->masjid->id}abc/auth/password", [
            'email' => $email, 'password' => self::GOOD,
        ])->assertNotFound();

        // `family.guest`: an organisation that does not exist is a 404.
        $this->asANewRequest();
        $this->postJson('/api/mobile/masjids/999999/auth/password', [
            'email' => $email, 'password' => self::GOOD,
        ])->assertNotFound();

        // `crm`: switched off, the door is shut exactly as verify-code is.
        $this->masjid->forceFill(['crm_enabled' => false])->save();

        $this->asANewRequest();
        $viaPassword = $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/password", [
            'email' => $email, 'password' => self::GOOD,
        ]);
        $this->asANewRequest();
        $viaCode = $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/verify-code", [
            'email' => $email, 'code' => '000000',
        ]);

        $viaPassword->assertStatus(403);
        $this->assertSame($viaCode->getStatusCode(), $viaPassword->getStatusCode());
        $this->assertSame($viaCode->getContent(), $viaPassword->getContent());
    }

    // ---------------------------------------------------------------- helpers

    private function limit(): int
    {
        return (int) config('member.signup.verifications_per_hour_per_address');
    }

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

    private function address(): string
    {
        return 'member-' . uniqid() . '@test.local';
    }

    /**
     * A contact as the office or a past sign-in left it. Verified by default,
     * with a password only when one is given.
     *
     * @param  array<string, mixed>  $extra
     */
    private function member(
        Masjid $masjid,
        string $email,
        ?string $password = null,
        bool $verified = true,
        array $extra = [],
    ): Contact {
        $this->asANewRequest();

        $contact = new Contact();
        $contact->forceFill(array_merge([
            'masjid_id' => $masjid->id,
            'first_name' => 'On',
            'last_name' => 'File',
            'email' => $email,
            'login_email' => $email,
            'signup_source' => 'app',
            'verified_at' => $verified ? now() : null,
            'password' => $password === null ? null : Hash::make($password),
            'password_set_at' => $password === null ? null : now(),
        ], $extra))->save();

        return $contact->refresh();
    }

    /**
     * This organisation's admin, who can enable a family login. The roles are
     * seeded first so the admin is bridged to `masjid-admin` on save, as in
     * FamilyLoginEnablementTest. Call once per test.
     */
    private function officeAdmin(): User
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $this->masjid->user_id = $admin->id;
        $this->masjid->save();

        return $admin;
    }

    private function asOffice(User $admin): void
    {
        $this->asANewRequest();
        $this->flushHeaders();
        Sanctum::actingAs($admin);
    }

    private function familyLoginUrl(Contact $contact): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/contacts/{$contact->id}/family-login";
    }

    /**
     * Make `$contact` the confirmed guardian of a new ward, which a family login
     * requires (FamilyAccessService::ineligibilityReason).
     */
    private function makeGuardian(Contact $contact): void
    {
        $name = 'Class ' . uniqid();

        $group = Group::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
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
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $ward->id,
            'joined_at' => now(),
        ]);

        $this->assertTrue(app(\App\Services\Family\FamilyAccessService::class)->mayHoldAFamilyLogin($contact));
    }

    private function contactAt(Masjid $masjid, string $email): Contact
    {
        return Contact::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->where('login_email', $email)
            ->sole();
    }

    /** The newest sign-in code mailed to this address. */
    private function requestCode(Masjid $masjid, string $email): string
    {
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$masjid->id}/auth/request-code", ['email' => $email])
            ->assertStatus(202);

        $code = Mail::sent(
            FamilyLoginCodeMail::class,
            fn (FamilyLoginCodeMail $mail) => $mail->hasTo($email) && ! $mail->isForAccountDeletion(),
        )->last()?->code;

        $this->assertNotNull($code, "No sign-in code was mailed to {$email}.");

        return $code;
    }

    /** @param  array<string, mixed>  $extra */
    private function verify(Masjid $masjid, string $email, string $code, array $extra = []): TestResponse
    {
        $this->asANewRequest();

        return $this->postJson(
            "/api/mobile/masjids/{$masjid->id}/auth/verify-code",
            array_merge(['email' => $email, 'code' => $code], $extra),
        );
    }

    private function signIn(Masjid $masjid, string $email, string $password): TestResponse
    {
        $this->asANewRequest();

        $response = $this->postJson("/api/mobile/masjids/{$masjid->id}/auth/password", [
            'email' => $email,
            'password' => $password,
        ]);

        $this->asANewRequest();

        return $response;
    }

    private function codeRow(string $email): AppSignupCode
    {
        return AppSignupCode::withoutMasjidScope()->where('email', $email)->orderByDesc('id')->firstOrFail();
    }

    private function wrongCode(string $real): string
    {
        return $real === '000000' ? '111111' : '000000';
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

    private function assertPasswordNeverLogged(string $password): void
    {
        foreach ($this->logged as $event) {
            $this->assertStringNotContainsString($password, $event->message);
            $this->assertStringNotContainsString($password, (string) json_encode($event->context));
        }
    }
}
