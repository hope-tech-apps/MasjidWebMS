<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A parent chooses their own password (2026-09-08).
 *
 * This slice REVERSES a recorded decision — `Contact::getAuthPassword()` used
 * to return `''` unconditionally, with a docblock saying contacts would never
 * have passwords — so the properties that decision was protecting are asserted
 * here rather than assumed:
 *
 *  1. THE OFFICE NEVER HOLDS THE CREDENTIAL. No admin route sets, reads or
 *     resets it; the hash never appears in any response, including the staff
 *     CRM's, which serializes Contact models wholesale.
 *  2. UN-ENROLLED CONTACTS STILL FAIL CLOSED. The old `''` was a deliberate
 *     fail-closed choice, and it must still hold for every contact who has not
 *     opted in — which is all of them.
 *  3. THE SECOND DOOR IS NOT A DIRECTORY. Password sign-in must be
 *     byte-identical, for a stranger and for a real parent with a wrong
 *     password, to what the code door already answers. A second credential door
 *     is a second chance to build an oracle.
 *  4. IT CANNOT BE AIMED AT ANOTHER FAMILY. The subject is the caller's token
 *     and there is no identifier in the request to get wrong.
 */
class FamilyPasswordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Long enough for the length rule and not a phrase anyone has published —
     * deliberately NOT `correct horse battery staple`, which is in every breach
     * corpus and would fail the real `uncompromised()` rule the moment somebody
     * ran the suite with breach checking on.
     */
    private const GOOD = 'jasmine-lantern-42-quiet';

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

    /** @see FamilyLoginCodeTest::makeParent — forceFill for the same reason. */
    private function makeParent(Masjid $masjid, array $login = []): Contact
    {
        $contact = Contact::factory()->create([
            'masjid_id' => $masjid->id,
            'email' => 'roster-' . uniqid() . '@test.local',
        ]);

        $contact->forceFill(array_merge([
            'login_email' => 'parent-' . uniqid() . '@test.local',
            'login_enabled_at' => now(),
        ], $login))->save();

        return $contact->refresh();
    }

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    private function signInUrl(Masjid $m): string
    {
        return "/api/family/masjids/{$m->id}/auth/password";
    }

    private function passwordUrl(Masjid $m): string
    {
        return "/api/family/masjids/{$m->id}/password";
    }

    /** A live family token for this parent, minted the way sign-in mints one. */
    private function tokenFor(Contact $parent): string
    {
        $token = $parent->createFamilyToken()->plainTextToken;
        $this->asANewRequest();

        return $token;
    }

    /** Set a password through the real endpoint, as the parent themselves. */
    private function choosePassword(Masjid $m, Contact $parent, string $password = self::GOOD): void
    {
        $this->withToken($this->tokenFor($parent))
            ->putJson($this->passwordUrl($m), [
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertOk();

        $this->asANewRequest();
    }

    // ------------------------------------------------- 1. the door works at all

    #[Test]
    public function a_parent_sets_a_password_and_signs_in_with_it(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $this->choosePassword($masjid, $parent);

        $token = $this->postJson($this->signInUrl($masjid), [
            'email' => $parent->login_email,
            'password' => self::GOOD,
        ])->assertOk()->json('data.token');

        $this->assertNotEmpty($token);
        $this->asANewRequest();

        // The token is a REAL family token, not merely a string: it opens the
        // realm. A sign-in endpoint that returns something token-shaped which
        // does not authenticate is the failure this asserts against.
        $this->withToken($token)
            ->getJson("/api/family/masjids/{$masjid->id}/me")
            ->assertOk();
    }

    #[Test]
    public function the_password_is_stored_hashed_and_never_returned(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $this->choosePassword($masjid, $parent);

        $stored = (string) $parent->fresh()->getAuthPassword();

        $this->assertNotSame(self::GOOD, $stored, 'the plaintext must never be stored');
        $this->assertTrue(Hash::check(self::GOOD, $stored));

        $body = $this->postJson($this->signInUrl($masjid), [
            'email' => $parent->login_email,
            'password' => self::GOOD,
        ])->assertOk()->getContent();

        $this->assertStringNotContainsString(self::GOOD, $body);
        $this->assertStringNotContainsString($stored, $body, 'the HASH must not travel either');
    }

    /**
     * The staff CRM serializes Contact models wholesale — `->paginate()` on
     * index, `'data' => $contact` on store/update, `$contact->toArray()` on
     * show. Adding the column without `Contact::$hidden` would have put every
     * parent's bcrypt hash into four staff-facing responses.
     */
    #[Test]
    public function the_hash_does_not_survive_serialization(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);
        $this->choosePassword($masjid, $parent);

        $array = $parent->fresh()->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayHasKey('password_set_at', $array, 'the FLAG is legitimate operator visibility');
        $this->assertStringNotContainsString('$2y$', json_encode($array));
    }

    // ------------------------------------------- 2. un-enrolled fails CLOSED

    /**
     * The property the original `return ''` was built to guarantee, asserted
     * for the population it still applies to: everyone who has not opted in.
     */
    #[Test]
    public function a_parent_who_never_chose_a_password_cannot_sign_in_with_any(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $this->assertFalse($parent->hasFamilyPassword());
        $this->assertSame('', $parent->getAuthPassword(), 'NULL must coalesce to the fail-closed empty string');

        // Including the empty-ish ones: the fail-closed '' must not be
        // satisfiable BY '' either, which is the trap a naive `=== $stored`
        // comparison would fall into.
        foreach ([' ', 'password', self::GOOD] as $attempt) {
            $this->postJson($this->signInUrl($masjid), [
                'email' => $parent->login_email,
                'password' => $attempt,
            ])->assertStatus(410);

            $this->asANewRequest();
        }
    }

    #[Test]
    public function a_revoked_login_cannot_be_reopened_with_a_password_set_earlier(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $this->choosePassword($masjid, $parent);

        // The office withdraws access AFTER the family chose a password. The
        // password must not be a way around that — this is the exact failure a
        // second, hand-written contact lookup in the password path would ship.
        $parent->forceFill(['login_revoked_at' => now()])->save();
        $this->asANewRequest();

        $this->postJson($this->signInUrl($masjid), [
            'email' => $parent->login_email,
            'password' => self::GOOD,
        ])->assertStatus(410);
    }

    // ------------------------------------------------ 3. not a directory

    /**
     * The whole-body comparison is the point, not the status: an oracle is
     * almost always reintroduced as a MESSAGE.
     */
    #[Test]
    public function a_stranger_and_a_wrong_password_get_byte_identical_refusals(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);
        $this->choosePassword($masjid, $parent);

        $wrong = $this->postJson($this->signInUrl($masjid), [
            'email' => $parent->login_email,
            'password' => 'not the right one at all',
        ])->assertStatus(410);
        $this->asANewRequest();

        $stranger = $this->postJson($this->signInUrl($masjid), [
            'email' => 'nobody-' . uniqid() . '@test.local',
            'password' => 'not the right one at all',
        ])->assertStatus(410);
        $this->asANewRequest();

        $unenrolled = $this->postJson($this->signInUrl($masjid), [
            'email' => $this->makeParent($masjid)->login_email,
            'password' => 'not the right one at all',
        ])->assertStatus(410);

        $this->assertSame($wrong->getContent(), $stranger->getContent());
        $this->assertSame($wrong->getContent(), $unenrolled->getContent());
    }

    /**
     * The two doors must not diverge either. They now open the same account, so
     * a difference between them answers "does this family use a password?".
     */
    #[Test]
    public function the_password_door_refuses_in_the_same_words_as_the_code_door(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $viaCode = $this->postJson("/api/family/masjids/{$masjid->id}/auth/verify-code", [
            'email' => $parent->login_email,
            'code' => '000000',
        ])->assertStatus(410);
        $this->asANewRequest();

        $viaPassword = $this->postJson($this->signInUrl($masjid), [
            'email' => $parent->login_email,
            'password' => 'anything',
        ])->assertStatus(410);

        $this->assertSame($viaCode->getContent(), $viaPassword->getContent());
    }

    /**
     * A password set at one school is not a credential at another, even though
     * the address is identical. `resolveContact()` applies the BOUND tenant, and
     * the tenant here comes from the URL.
     */
    #[Test]
    public function a_password_does_not_work_against_another_school(): void
    {
        $here = $this->makeMasjid();
        $elsewhere = $this->makeMasjid();

        $parent = $this->makeParent($here, ['login_email' => 'shared@test.local']);
        $this->choosePassword($here, $parent);

        $this->postJson($this->signInUrl($elsewhere), [
            'email' => 'shared@test.local',
            'password' => self::GOOD,
        ])->assertStatus(410);
    }

    // ------------------------------------- 4. it cannot be aimed at anyone else

    #[Test]
    public function setting_a_password_requires_a_token_and_changes_only_its_owner(): void
    {
        $masjid = $this->makeMasjid();
        $mine = $this->makeParent($masjid);
        $theirs = $this->makeParent($masjid);

        // No token at all.
        $this->putJson($this->passwordUrl($masjid), [
            'password' => self::GOOD, 'password_confirmation' => self::GOOD,
        ])->assertStatus(401);
        $this->asANewRequest();

        // A token, plus every identifier a caller might hope is read. None is:
        // the controller takes the subject from Auth::user() and nothing else.
        $this->withToken($this->tokenFor($mine))
            ->putJson($this->passwordUrl($masjid), [
                'password' => self::GOOD,
                'password_confirmation' => self::GOOD,
                'contact_id' => $theirs->id,
                'email' => $theirs->login_email,
            ])->assertOk();
        $this->asANewRequest();

        $this->assertTrue($mine->fresh()->hasFamilyPassword());
        $this->assertFalse($theirs->fresh()->hasFamilyPassword(), 'the payload must not have chosen the subject');
    }

    // ------------------------------------------------- 5. the session rules

    /**
     * Changing a password is also how a parent responds to "someone else has my
     * phone", so every OTHER session ends — including a live child-handoff
     * token, which is the case that actually matters on a shared family device.
     * The caller's own session survives, because signing them out of the screen
     * they just used to secure the account teaches them that securing it broke
     * something.
     */
    #[Test]
    public function choosing_a_password_ends_other_sessions_but_not_this_one(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $stale = $this->tokenFor($parent);
        $current = $this->tokenFor($parent);

        $this->withToken($current)->putJson($this->passwordUrl($masjid), [
            'password' => self::GOOD, 'password_confirmation' => self::GOOD,
        ])->assertOk();
        $this->asANewRequest();

        $this->withToken($current)
            ->getJson("/api/family/masjids/{$masjid->id}/me")
            ->assertOk();
        $this->asANewRequest();

        $this->withToken($stale)
            ->getJson("/api/family/masjids/{$masjid->id}/me")
            ->assertStatus(401);
    }

    #[Test]
    public function removing_a_password_returns_the_family_to_codes_only(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);
        $this->choosePassword($masjid, $parent);

        $this->withToken($this->tokenFor($parent))
            ->deleteJson($this->passwordUrl($masjid))
            ->assertOk()
            ->assertJsonPath('data.has_password', false);
        $this->asANewRequest();

        $this->assertFalse($parent->fresh()->hasFamilyPassword());
        $this->assertSame('', $parent->fresh()->getAuthPassword());

        $this->postJson($this->signInUrl($masjid), [
            'email' => $parent->login_email,
            'password' => self::GOOD,
        ])->assertStatus(410);
    }

    // --------------------------------------------------- 6. the rule and trail

    #[Test]
    public function a_weak_or_mistyped_password_is_refused_with_a_reason(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        // Authenticated, so a specific 422 discloses nothing.
        $this->withToken($this->tokenFor($parent))
            ->putJson($this->passwordUrl($masjid), [
                'password' => 'short', 'password_confirmation' => 'short',
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->asANewRequest();

        $this->withToken($this->tokenFor($parent))
            ->putJson($this->passwordUrl($masjid), [
                'password' => self::GOOD, 'password_confirmation' => 'typed it differently',
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->asANewRequest();

        $this->assertFalse($parent->fresh()->hasFamilyPassword(), 'a refused attempt must not half-write');
    }

    /**
     * The credential acts land on the same trail the admin on-switch writes to,
     * with NO actor — because no staff user was involved and none can be. That
     * empty actor is the record, and it is what lets a reader of the panel tell
     * an act the office performed from one the family performed.
     */
    #[Test]
    public function the_family_not_the_office_is_recorded_as_having_acted(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeParent($masjid);

        $this->choosePassword($masjid, $parent);

        $event = ContactLoginEvent::where('contact_id', $parent->id)
            ->where('action', ContactLoginEvent::ACTION_PASSWORD_SET)
            ->sole();

        $this->assertNull($event->actor_user_id, 'no staff user can have done this');
        $this->assertNull($event->actor_name);
        $this->assertNull($event->actor_email);
        $this->assertSame($parent->login_email, $event->login_email);
        $this->assertSame($masjid->id, $event->masjid_id);

        $this->withToken($this->tokenFor($parent))
            ->deleteJson($this->passwordUrl($masjid))->assertOk();

        $this->assertDatabaseHas('contact_login_events', [
            'contact_id' => $parent->id,
            'action' => ContactLoginEvent::ACTION_PASSWORD_CLEARED,
        ]);
    }
}
