<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\Contact;
use App\Models\ContactLoginCode;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * T-015d — how a parent gets a token, and every way that must not become a way
 * to learn something.
 *
 * `docs/t015-parent-identity-design.md` §3 and invariant 8. Two properties are
 * load-bearing here and each is asserted rather than described:
 *
 *  1. THE CREDENTIAL IS NEVER READABLE. Not in a response body, not in the
 *     database, not in the mail log. A DB dump must not be a set of working
 *     sign-in codes for a school's families.
 *  2. THE ENDPOINT IS NOT A DIRECTORY. Requesting a code for an address that
 *     belongs to nobody, to a contact whose login was never enabled, to one
 *     revoked this morning, or to a soft-deleted one must be BYTE-IDENTICAL to
 *     requesting one for a live parent — status, body and headers. The roster
 *     behind this is a list of children, so "does this family attend this
 *     school?" is a question the API must be unable to answer.
 *
 * Several assertions below compare whole response bodies rather than a status
 * code, on purpose: an oracle is usually reintroduced as a *message*, not as a
 * status.
 */
class FamilyLoginCodeTest extends TestCase
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

    // ---------------------------------------------------------------- helpers

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ], $overrides));
    }

    /**
     * A contact that may log in.
     *
     * `forceFill`, not `create([...])`: the four `login_*` columns are
     * deliberately absent from `Contact::$fillable` so that no request body can
     * enable a login as a side effect. A test that mass-assigned them would be
     * testing a model this application does not have.
     */
    private function makeParent(Masjid $masjid, array $login = [], array $attributes = []): Contact
    {
        $contact = Contact::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
            'email' => 'roster-' . uniqid() . '@test.local',
        ], $attributes));

        $contact->forceFill(array_merge([
            'login_email' => 'parent-' . uniqid() . '@test.local',
            'login_enabled_at' => now(),
        ], $login))->save();

        return $contact->refresh();
    }

    private function requestUrl(Masjid $masjid): string
    {
        return "/api/family/masjids/{$masjid->id}/auth/request-code";
    }

    private function verifyUrl(Masjid $masjid): string
    {
        return "/api/family/masjids/{$masjid->id}/auth/verify-code";
    }

    private function meUrl(Masjid $masjid): string
    {
        return "/api/family/masjids/{$masjid->id}/me";
    }

    /**
     * Put the process back into the state a genuinely NEW request starts from.
     *
     * Same reasoning as FamilyAuthGuardTest: `RequestGuard::user()` memoizes,
     * and `TenantContext` is a scoped binding nothing clears mid-process, so a
     * second call inside one test would otherwise be answered out of the first
     * call's state and would pass with the check under test deleted.
     */
    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /**
     * Ask for a code and return the plaintext, read from the MAILABLE — the
     * only place in the system it exists after `issue()` returns.
     */
    private function codeSentTo(Masjid $masjid, Contact $parent): string
    {
        Mail::fake();

        $this->postJson($this->requestUrl($masjid), ['email' => $parent->login_email])
            ->assertStatus(202);

        $captured = null;

        Mail::assertSent(FamilyLoginCodeMail::class, function (FamilyLoginCodeMail $mail) use ($parent, &$captured) {
            if (! $mail->hasTo($parent->login_email)) {
                return false;
            }

            $captured = $mail->code;

            return true;
        });

        $this->assertNotNull($captured, 'no code was delivered');
        $this->asANewRequest();

        return $captured;
    }

    // ----------------------------------------------------------- 1. the mint

    #[Test]
    public function a_parent_exchanges_an_emailed_code_for_a_working_family_token(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $code = $this->codeSentTo($masjid, $parent);

        $response = $this->postJson($this->verifyUrl($masjid), [
            'email' => $parent->login_email,
            'code' => $code,
        ])->assertOk();

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);
        $response->assertJsonPath('data.contact.id', $parent->id);
        $response->assertJsonPath('data.contact.masjid_id', $masjid->id);

        // Least disclosure in the mint payload too: the same hand-built
        // projection /me uses, never the model.
        $response->assertJsonMissingPath('data.contact.notes');
        $response->assertJsonMissingPath('data.contact.email');
        $response->assertJsonMissingPath('data.contact.phone');

        $this->asANewRequest();

        // END TO END: the token this endpoint minted actually opens the realm.
        // A test that stopped at "a token came back" would pass for a token
        // minted on the wrong guard or with the wrong abilities.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($this->meUrl($masjid))
            ->assertOk()
            ->assertJsonPath('data.id', $parent->id);

        $this->assertSame(
            Contact::FAMILY_TOKEN_ABILITIES,
            $parent->tokens()->latest('id')->first()->abilities
        );

        $this->assertNotNull($parent->fresh()->last_login_at);
    }

    // ------------------------------------------ 2. the code is never readable

    #[Test]
    public function the_code_is_never_in_a_response_body_and_never_stored_in_plaintext(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        Mail::fake();

        $response = $this->postJson($this->requestUrl($masjid), ['email' => $parent->login_email])
            ->assertStatus(202);

        $code = null;
        Mail::assertSent(FamilyLoginCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        // Nothing in the response resembles the credential.
        $this->assertStringNotContainsString($code, $response->getContent());

        $row = ContactLoginCode::withoutMasjidScope()->firstOrFail();

        // Nor anywhere in the row: not in the stored digest, and not in any
        // other column that a careless addition might start echoing it into.
        foreach ($row->getAttributes() as $column => $value) {
            $this->assertNotSame(
                $code,
                (string) $value,
                "contact_login_codes.{$column} holds the plaintext code"
            );
        }

        // The digest is KEYED, not a bare sha256 — a bare digest of six digits
        // is reversible by anyone holding the table in about a millisecond,
        // which would make "hashed at rest" decoration. If this ever fails
        // because the two are equal, the hashing was weakened.
        $this->assertNotSame(hash('sha256', $code), $row->code_hash);
        $this->assertSame(hash_hmac('sha256', $code, (string) config('app.key')), $row->code_hash);

        // And it does not travel through serialization either.
        $this->assertArrayNotHasKey('code_hash', $row->toArray());
    }

    // ------------------------------------------------ 3. no enumeration oracle

    #[Test]
    public function requesting_a_code_is_byte_identical_for_every_kind_of_address(): void
    {
        $masjid = $this->makeMasjid();

        $live = $this->makeParent($masjid);
        $neverEnabled = $this->makeParent($masjid, ['login_enabled_at' => null]);
        $revoked = $this->makeParent($masjid, ['login_revoked_at' => now()]);

        $trashed = $this->makeParent($masjid);
        $trashedAddress = $trashed->login_email;
        $trashed->delete();

        // A contact that exists in the CRM with an ordinary email but NO login
        // address at all — the state every one of a school's contacts is in
        // until an administrator enables them, and the single most likely
        // address an attacker would try.
        $noLogin = Contact::factory()->create([
            'masjid_id' => $masjid->id,
            'email' => 'plain-' . uniqid() . '@test.local',
        ]);

        $addresses = [
            'live parent' => $live->login_email,
            'never enabled' => $neverEnabled->login_email,
            'revoked' => $revoked->login_email,
            'soft deleted' => $trashedAddress,
            'crm contact with no login' => $noLogin->email,
            'nobody at all' => 'stranger-' . uniqid() . '@test.local',
        ];

        $bodies = [];

        foreach ($addresses as $label => $address) {
            Mail::fake();
            $this->asANewRequest();

            $response = $this->postJson($this->requestUrl($masjid), ['email' => $address]);

            $this->assertSame(202, $response->getStatusCode(), "{$label} must answer 202");
            $bodies[$label] = $response->getContent();

            // Only the live parent is ever written a row or sent a mail — the
            // point is that the CALLER cannot tell.
            if ($label === 'live parent') {
                Mail::assertSent(FamilyLoginCodeMail::class);
            } else {
                Mail::assertNothingSent();
            }
        }

        $this->assertCount(
            1,
            array_unique($bodies),
            'the response body differs between address kinds, which is an enumeration oracle: '
            . json_encode($bodies)
        );

        // Exactly one code exists: the live parent's. A row written for a
        // revoked or deleted contact would be a second oracle — visible to an
        // operator, and a foothold for a later bug that started serving them.
        $this->assertSame(1, ContactLoginCode::withoutMasjidScope()->count());
        $this->assertSame($live->id, (int) ContactLoginCode::withoutMasjidScope()->first()->contact_id);
    }

    #[Test]
    public function verifying_is_byte_identical_for_every_kind_of_failure(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $good = $this->codeSentTo($masjid, $parent);

        // 1. replay — consume the good code first, then present it again.
        $this->postJson($this->verifyUrl($masjid), [
            'email' => $parent->login_email,
            'code' => $good,
        ])->assertOk();
        $this->asANewRequest();

        $bodies = [];
        $statuses = [];

        $attempts = [
            'replayed code' => [$parent->login_email, $good],
            'wrong code' => [$parent->login_email, $this->wrongCodeFor($good)],
            'no code outstanding' => [$this->makeParent($masjid)->login_email, '123456'],
            'unknown address' => ['stranger-' . uniqid() . '@test.local', '123456'],
            'revoked contact' => [$this->makeParent($masjid, ['login_revoked_at' => now()])->login_email, '123456'],
        ];

        foreach ($attempts as $label => [$email, $code]) {
            $this->asANewRequest();

            $response = $this->postJson($this->verifyUrl($masjid), ['email' => $email, 'code' => $code]);

            $statuses[$label] = $response->getStatusCode();
            $bodies[$label] = $response->getContent();
        }

        $this->assertSame(
            array_fill_keys(array_keys($attempts), 410),
            $statuses,
            'every verification failure must answer 410'
        );
        $this->assertCount(
            1,
            array_unique($bodies),
            'verification failures differ from each other: ' . json_encode($bodies)
        );
    }

    // ---------------------------------------------- 4. expiry, replay, lockout

    #[Test]
    public function a_code_is_single_use(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $code = $this->codeSentTo($masjid, $parent);

        $this->postJson($this->verifyUrl($masjid), [
            'email' => $parent->login_email,
            'code' => $code,
        ])->assertOk();

        $this->asANewRequest();

        $this->postJson($this->verifyUrl($masjid), [
            'email' => $parent->login_email,
            'code' => $code,
        ])->assertStatus(410);

        // ONE token, not two. A 410 that had already minted a second token
        // would be the same vulnerability with a polite error message.
        $this->assertSame(1, $parent->tokens()->count());
        $this->assertNotNull(ContactLoginCode::withoutMasjidScope()->first()->consumed_at);
    }

    #[Test]
    public function a_code_expires(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $code = $this->codeSentTo($masjid, $parent);

        // The row's own clock, not a sleep: travelling past the configured TTL
        // proves the EXPIRY is what refuses, and a test that slept for ten
        // minutes would never be run.
        $this->travel((int) config('family.login.code_ttl_minutes') + 1)->minutes();

        $this->postJson($this->verifyUrl($masjid), [
            'email' => $parent->login_email,
            'code' => $code,
        ])->assertStatus(410);

        $this->assertSame(0, $parent->tokens()->count());

        $this->travelBack();
    }

    #[Test]
    public function a_code_locks_out_after_the_configured_number_of_wrong_attempts(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $code = $this->codeSentTo($masjid, $parent);
        $max = ContactLoginCode::maxAttempts();

        for ($i = 0; $i < $max; $i++) {
            $this->asANewRequest();

            $this->postJson($this->verifyUrl($masjid), [
                'email' => $parent->login_email,
                'code' => $this->wrongCodeFor($code),
            ])->assertStatus(410);
        }

        $this->assertSame($max, (int) ContactLoginCode::withoutMasjidScope()->first()->attempts);

        // THE POINT: the CORRECT code no longer works. A lockout that only
        // refused further wrong guesses would stop nothing — an attacker's next
        // guess is the one that matters.
        $this->asANewRequest();

        $this->postJson($this->verifyUrl($masjid), [
            'email' => $parent->login_email,
            'code' => $code,
        ])->assertStatus(410);

        $this->assertSame(0, $parent->tokens()->count());
    }

    #[Test]
    public function a_wrong_guess_charges_every_live_code_the_parent_holds(): void
    {
        // Otherwise the lockout is trivially defeated: request twenty codes,
        // attack one of them, and buy a hundred guesses. A parent who types the
        // right digits never reaches this path, so legitimate retries are
        // unaffected.
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $first = $this->codeSentTo($masjid, $parent);
        $second = $this->codeSentTo($masjid, $parent);

        $this->assertSame(2, ContactLoginCode::withoutMasjidScope()->count());

        $this->postJson($this->verifyUrl($masjid), [
            'email' => $parent->login_email,
            'code' => $this->wrongCodeFor($first, $second),
        ])->assertStatus(410);

        foreach (ContactLoginCode::withoutMasjidScope()->get() as $row) {
            $this->assertSame(1, (int) $row->attempts);
        }

        // And the older code still WORKS — charging the attempt is not the same
        // as invalidating it, and a slow mail relay must not lock a family out
        // of their own retry.
        $this->asANewRequest();

        $this->postJson($this->verifyUrl($masjid), [
            'email' => $parent->login_email,
            'code' => $first,
        ])->assertOk();
    }

    // --------------------------------------------------------- 5. the throttles

    #[Test]
    public function requesting_codes_is_throttled_per_address_and_identically_for_a_stranger(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);
        $stranger = 'stranger-' . uniqid() . '@test.local';

        $limit = (int) config('family.login.requests_per_hour_per_address');

        $known = $this->hammerRequests($masjid, $parent->login_email, $limit + 1);
        $unknown = $this->hammerRequests($masjid, $stranger, $limit + 1);

        // The limiter fires at the same attempt for both, because it is keyed on
        // the SUBMITTED address and never on anything we looked up. A 429 that
        // arrived only for real addresses would be an enumeration oracle built
        // out of a rate limiter.
        $this->assertSame(
            array_fill(0, $limit, 202) + [$limit => 429],
            $known,
            'a known address must be throttled at the configured limit'
        );
        $this->assertSame($known, $unknown, 'known and unknown addresses must throttle identically');
    }

    #[Test]
    public function verification_is_throttled_per_address(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $limit = (int) config('family.login.verifications_per_hour_per_address');
        $statuses = [];

        for ($i = 0; $i <= $limit; $i++) {
            $this->asANewRequest();

            $statuses[] = $this->postJson($this->verifyUrl($masjid), [
                'email' => $parent->login_email,
                'code' => '000000',
            ])->getStatusCode();
        }

        $this->assertSame(array_fill(0, $limit, 410) + [$limit => 429], $statuses);
    }

    // --------------------------------------------- 6. delivery: email, and only

    #[Test]
    public function the_code_goes_to_login_email_and_never_to_the_crm_email_or_a_phone(): void
    {
        $masjid = $this->makeMasjid();

        // The worst case for a careless implementation: a contact whose CRM
        // email differs from their login address AND who carries a fully
        // consented, opted-in mobile number. .claude/rules/broadcasts.md's
        // consent record makes texting this person lawful for ANNOUNCEMENTS;
        // it is still not permission to send them a credential, because a
        // recycled number would then open a specific child's records
        // (docs/t015-parent-identity-design.md §3).
        $parent = $this->makeParent($masjid, [], [
            'email' => 'household-' . uniqid() . '@test.local',
            'phone' => '+15551234567',
            'sms_opt_in' => true,
            'sms_consent_at' => now(),
            'sms_consent_source' => 'web_form',
        ]);

        $this->assertTrue($parent->hasSmsConsent());

        Mail::fake();

        $this->postJson($this->requestUrl($masjid), ['email' => $parent->login_email])
            ->assertStatus(202);

        Mail::assertSent(FamilyLoginCodeMail::class, function (FamilyLoginCodeMail $mail) use ($parent) {
            return $mail->hasTo($parent->login_email)
                && ! $mail->hasTo($parent->email);
        });

        // Asking for a code at the CRM address does nothing at all, even though
        // that address names a real contact. `login_email` is the credential
        // address and `contacts.email` is imported, shared and unverified — the
        // whole reason they are two columns.
        Mail::fake();
        $this->asANewRequest();

        $this->postJson($this->requestUrl($masjid), ['email' => $parent->email])
            ->assertStatus(202);

        Mail::assertNothingSent();
    }

    #[Test]
    public function the_login_mailable_is_not_queued(): void
    {
        // Every other Mailable in this app is ShouldQueue. This one must not be:
        // QUEUE_CONNECTION is `database` in production, so a queued mailable
        // writes its public properties — the plaintext code among them — into
        // `jobs.payload`, and on failure into `failed_jobs` where it stays.
        // Hashing the credential in one table while spooling it in cleartext in
        // another is the same leak with an extra step.
        $this->assertNotInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new FamilyLoginCodeMail('Org', '123456', 10)
        );
    }

    #[Test]
    public function a_mail_relay_outage_does_not_become_an_existence_oracle(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        // The relay is down. If the exception escaped, a REAL address would 500
        // while a stranger's still answered 202 — a perfect directory built out
        // of an error path, and exactly the kind of leak nobody finds by
        // reading the happy path.
        Mail::shouldReceive('to')->andThrow(new RuntimeException('relay down'));

        $this->postJson($this->requestUrl($masjid), ['email' => $parent->login_email])
            ->assertStatus(202)
            ->assertJsonPath('status', 'success');
    }

    // ------------------------------------------------------- 7. the stack itself

    #[Test]
    public function the_sign_in_endpoints_carry_no_admin_permission_or_staff_middleware(): void
    {
        // Structural, enumerated from the router rather than read out of the
        // route file, so a route added tomorrow is covered the moment it exists.
        $checked = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/family')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (! in_array('family.guest', $middleware, true)) {
                continue;
            }

            $checked++;

            $this->assertNotContains('admin', $middleware);
            $this->assertNotContains('super', $middleware);
            $this->assertNotContains('tenant', $middleware);
            $this->assertNotContains('auth:sanctum', $middleware);
            $this->assertContains('crm', $middleware);

            foreach ($middleware as $layer) {
                $this->assertStringStartsNotWith('permission:', $layer);
                $this->assertStringStartsNotWith('role:', $layer);
            }
        }

        $this->assertSame(2, $checked, 'expected exactly the two sign-in routes');
    }

    #[Test]
    public function a_staff_token_gains_nothing_at_the_sign_in_endpoints(): void
    {
        // Design invariant 2 says a staff token must be refused on every route
        // in routes/family.php, and FamilyAuthGuardTest enforces it by
        // enumerating the routes behind `auth:family`. These two sit behind no
        // guard at all — they cannot, a caller signing in has no token — so they
        // fall OUTSIDE that enumeration and the invariant's coverage silently
        // narrowed when they were added. This closes it explicitly.
        //
        // The claim is not "a staff token is refused" (nothing here authenticates
        // anyone, so there is nothing to refuse) but the stronger and more useful
        // one: presenting a staff credential CHANGES NOTHING. No contact is
        // resolved from the staff user, no code is minted for them, and the
        // response is byte-identical to the anonymous one.
        $masjid = $this->makeMasjid();
        $admin = \App\Models\User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $masjid->user_id = $admin->id;
        $masjid->save();

        $staffToken = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password', // UserFactory default
        ])->assertOk()->json('data.token');

        Mail::fake();
        $this->asANewRequest();

        // The staff member's OWN address, presented with their own bearer token.
        // A `users` row is not a `contacts` row, so this names nobody here.
        $withToken = $this->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->postJson($this->requestUrl($masjid), ['email' => $admin->email]);

        $this->asANewRequest();
        $anonymous = $this->postJson($this->requestUrl($masjid), ['email' => $admin->email]);

        $this->assertSame(202, $withToken->getStatusCode());
        $this->assertSame($anonymous->getContent(), $withToken->getContent());

        Mail::assertNothingSent();
        $this->assertSame(0, ContactLoginCode::withoutMasjidScope()->count());

        // And it cannot be traded for a family token either.
        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->postJson($this->verifyUrl($masjid), ['email' => $admin->email, 'code' => '123456'])
            ->assertStatus(410);
    }

    #[Test]
    public function the_crm_feature_gate_applies_to_signing_in(): void
    {
        // A masjid that never switched the CRM on has no roster, so it has no
        // families to sign in. Pinned because `crm` is easy to drop from a new
        // route group by accident and impossible to notice.
        $masjid = $this->makeMasjid(['crm_enabled' => false]);
        $parent = $this->makeParent($masjid);

        Mail::fake();

        $this->postJson($this->requestUrl($masjid), ['email' => $parent->login_email])
            ->assertStatus(403);

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_malformed_payload_is_a_422_and_never_a_lookup(): void
    {
        $masjid = $this->makeMasjid();

        Mail::fake();

        $this->postJson($this->requestUrl($masjid), [])->assertStatus(422);
        $this->asANewRequest();
        $this->postJson($this->requestUrl($masjid), ['email' => 'not-an-address'])->assertStatus(422);

        // 422 is a fact about the caller's OWN input, knowable without a server,
        // so it discloses nothing. What it must not be is the result of an
        // `exists:` rule — docs §11 names the staff login's
        // `exists:users,email` as an oracle the family realm must not copy.
        $this->asANewRequest();
        $this->postJson($this->verifyUrl($masjid), [
            'email' => 'stranger@test.local',
            'code' => 'abcdef',
        ])->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertSame(0, ContactLoginCode::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------- test helpers

    /**
     * A well-formed code of the right length that is none of the given ones.
     */
    private function wrongCodeFor(string ...$avoid): string
    {
        $length = strlen($avoid[0] ?? '000000');

        do {
            $candidate = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        } while (in_array($candidate, $avoid, true));

        return $candidate;
    }

    /**
     * @return array<int,int> the status of each successive request
     */
    private function hammerRequests(Masjid $masjid, string $email, int $times): array
    {
        Mail::fake();

        $statuses = [];

        for ($i = 0; $i < $times; $i++) {
            $this->asANewRequest();

            $statuses[] = $this->postJson($this->requestUrl($masjid), ['email' => $email])
                ->getStatusCode();
        }

        return $statuses;
    }
}
