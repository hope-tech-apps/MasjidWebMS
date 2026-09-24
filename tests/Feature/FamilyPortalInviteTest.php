<?php

namespace Tests\Feature;

use App\Mail\FamilyPortalInviteMail;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\ContactPortalInvite;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Send portal invite" — the half of the parent portal that was missing.
 *
 * `FamilyAccessService::enable()` opens a family's access and sends them
 * nothing. A parent nobody walks through the portal by hand therefore never
 * arrives: Al-Razi had ten enabled family logins and five that had never been
 * used once. This is the office action that closes that, and the properties
 * below are what make it safe to email a bearer credential to a specific child's
 * photographs, marks and safeguarding conversations.
 *
 * Each is asserted rather than described:
 *
 *  1. **The link works.** End to end, across both realms: an admin enables, an
 *     admin invites, the parent's link is exchanged for a session, and that
 *     session opens their own child's record.
 *  2. **Seven days, and dead at seven days and a minute.**
 *  3. **Single use.** The second click is refused.
 *  4. **One live link.** Issuing a second kills the first, so "they never got
 *     it, send it again" cannot leave two working keys in two inboxes.
 *  5. **Eligibility is the SAME rule `enable()` enforces**, re-run at send time:
 *     a child's own contact row — nobody's guardian — is refused, because a
 *     login there is a student login that opens the whole class feed.
 *  6. **Revocation reaches the inbox.** A revoked login's link is dead, by two
 *     independent mechanisms.
 *  7. **The mail goes to `login_email` and nowhere else** — never
 *     `contacts.email`, which is imported, routinely a shared household address
 *     and verified by nobody.
 *  8. **It is throttled**, in the database rather than in a cache a deploy
 *     flushes.
 *  9. **It cannot cross a tenant boundary**, in either realm.
 *
 * No real email is sent anywhere in this file: `Mail::fake()` in `setUp`, and
 * phpunit.xml pins `MAIL_MAILER=array` besides.
 */
class FamilyPortalInviteTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;
    private User $adminB;

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

        // Nothing in this file may reach a mail transport. The fake also lets
        // the tests read the URL that would have been sent, which is the only
        // place the plaintext token ever exists.
        Mail::fake();

        // Seed the bridged roles BEFORE the admins, so each MasjidAdmin is
        // bridged to `masjid-admin` (which holds the CRM permission set) on
        // save — exactly as production does after the seeder runs.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->adminB = $this->makeAdminFor($this->masjidB);
    }

    // ===================================================================
    // 1. The link works, end to end, across both realms.
    // ===================================================================

    #[Test]
    public function the_emailed_link_signs_the_parent_in(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'khadija@example.test');

        $token = $this->sendInvite($parent);

        // The parent clicks. One POST, no address typed, no code fetched.
        $this->asANewRequest();
        $response = $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.contact.id', $parent->id)
            ->assertJsonPath('data.contact.login_email', 'khadija@example.test');

        $sessionToken = $response->json('data.token');
        $this->assertNotEmpty($sessionToken, 'redeeming the invite produced no session token');

        // …and it is an ORDINARY family session. Nothing about config/family.php
        // is loosened by this door: the token carries the family realm's
        // abilities and opens exactly what a code-minted one opens.
        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer '.$sessionToken)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertOk()
            ->assertJsonPath('data.id', $parent->id);

        // The office can now see that the family actually arrived — the one
        // thing the panel could never say before.
        $this->assertNotNull($parent->fresh()->last_login_at);

        $this->asAdmin($this->adminA);
        $this->getJson($this->panelUrl($this->masjidA, $parent))
            ->assertOk()
            ->assertJsonPath('data.invite.state', 'accepted');
    }

    // ===================================================================
    // 2. Seven days.
    // ===================================================================

    #[Test]
    public function the_link_is_dead_at_seven_days_and_one_minute(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'sevendays@example.test');

        $issuedAt = Carbon::parse('2026-10-01 09:00:00');
        $this->travelTo($issuedAt);

        $token = $this->sendInvite($parent);

        // Still good at six days and twenty-three hours — the boundary is
        // asserted from BOTH sides, so a test that passes because the clock
        // never moved is not one of the possibilities.
        $this->travelTo($issuedAt->copy()->addDays(6)->addHours(23));
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])->assertOk();

        // A second link, and this time nobody uses it in time.
        $this->travelTo($issuedAt);
        $later = $this->sendInvite($parent);

        $this->travelTo($issuedAt->copy()->addDays(7)->addMinute());
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $later])
            ->assertStatus(410);

        $this->travelBack();
    }

    // ===================================================================
    // 3. Single use.
    // ===================================================================

    #[Test]
    public function the_link_can_only_be_used_once(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'once@example.test');

        $token = $this->sendInvite($parent);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])->assertOk();

        // A replay — a double-tapped button, a mail client prefetching the URL,
        // somebody returning to the link a week later — gets nothing.
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertStatus(410);
    }

    // ===================================================================
    // 4. One live link per contact.
    // ===================================================================

    #[Test]
    public function issuing_a_second_invite_kills_the_first(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'resend@example.test');

        $first = $this->sendInvite($parent);
        $second = $this->sendInvite($parent);

        $this->assertNotSame($first, $second, 'a re-send produced the same token twice');

        // "They never got it, send it again" must not leave two working keys in
        // two inboxes — one of them in whatever mailbox lost the first.
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $first])
            ->assertStatus(410);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $second])
            ->assertOk();
    }

    // ===================================================================
    // 5. The eligibility rule is `enable()`'s, re-run at send time.
    // ===================================================================

    #[Test]
    public function a_childs_own_contact_row_cannot_be_invited(): void
    {
        // A child is on a roster as a participant and is nobody's guardian. A
        // login here would be a STUDENT login, which grants the whole class feed
        // — every classmate's photograph, with nobody's consent — plus the
        // participant threads where a teacher and a guardian discuss a
        // safeguarding concern.
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);

        $this->asAdmin($this->adminA);
        $this->postJson($this->inviteUrl($this->masjidA, $child))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        Mail::assertNothingSent();
        $this->assertSame(0, ContactPortalInvite::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_member_whose_standing_lapsed_cannot_be_sent_a_fresh_link(): void
    {
        // The case the re-check exists for. A grant deliberately SURVIVES the
        // roster edge going away — FamilyAccessService argues at length that
        // revoking there would burn a family's sign-in every term — so the
        // credential keeps working. Mailing a FRESH key to a login that no
        // longer qualifies for one is a different act, and it is refused.
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'lapsed@example.test');

        GroupMembership::withoutMasjidScope()
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->delete();

        $this->asAdmin($this->adminA);
        $this->postJson($this->inviteUrl($this->masjidA, $parent))
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_member_with_no_sign_in_at_all_cannot_be_invited(): void
    {
        // A link to a portal that refuses its holder is worse than no link.
        $parent = $this->makeGuardian($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->inviteUrl($this->masjidA, $parent))
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    // ===================================================================
    // 6. Revocation, and re-addressing, reach the inbox.
    // ===================================================================

    #[Test]
    public function a_revoked_sign_ins_link_is_dead(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'revoked@example.test');

        $token = $this->sendInvite($parent);

        $this->asAdmin($this->adminA);
        $this->deleteJson($this->panelUrl($this->masjidA, $parent))->assertOk();

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertStatus(410);

        // TWO MECHANISMS, deliberately not one — the same pairing
        // FamilyAccessService makes for tokens. The row is stamped dead…
        $invite = ContactPortalInvite::withoutMasjidScope()->latest('id')->first();
        $this->assertNotNull($invite->invalidated_at, 'revoking left the invite row live');
        $this->assertNull($invite->consumed_at);
    }

    #[Test]
    public function the_liveness_check_alone_refuses_a_revoked_login(): void
    {
        // …and the redemption path re-reads `familyLoginIsActive()` from the
        // database, so a row nobody remembered to stamp is refused anyway. This
        // is the half that survives a future caller reaching redemption by some
        // other path, so it is asserted WITHOUT the stamp: the row is put back
        // to live by hand, and the link must still be dead.
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'liveness@example.test');

        $token = $this->sendInvite($parent);

        $this->asAdmin($this->adminA);
        $this->deleteJson($this->panelUrl($this->masjidA, $parent))->assertOk();

        ContactPortalInvite::withoutMasjidScope()->update(['invalidated_at' => null]);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertStatus(410);
    }

    #[Test]
    public function moving_the_sign_in_address_kills_the_link_in_the_old_mailbox(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'old@example.test');

        $token = $this->sendInvite($parent);

        // The address is re-typed. In real life this is a separation, a guardian
        // handover, or a typo that reached a stranger — and in every one of them
        // the link sitting in the old mailbox is what the change was meant to
        // end.
        $this->enableSignIn($parent, 'new@example.test');

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertStatus(410);
    }

    #[Test]
    public function the_bound_address_alone_refuses_a_moved_login(): void
    {
        // The structural half of the property above, asserted on its own: the
        // active invalidation is undone by hand, and the link must still be dead
        // because redemption compares the row's address with the contact's.
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'bound-old@example.test');

        $token = $this->sendInvite($parent);
        $this->enableSignIn($parent, 'bound-new@example.test');

        ContactPortalInvite::withoutMasjidScope()->update(['invalidated_at' => null]);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertStatus(410);
    }

    // ===================================================================
    // 7. The mail goes to the address on file, and nowhere else.
    // ===================================================================

    #[Test]
    public function the_invite_goes_only_to_the_sign_in_address(): void
    {
        // `contacts.email` is imported in bulk from an admissions spreadsheet,
        // is routinely a HOUSEHOLD address shared by both parents, and was
        // verified by nobody. The whole family-login design refuses to treat it
        // as a credential; an invite mailed there would be this feature quietly
        // undoing that.
        $parent = $this->makeGuardian($this->masjidA, [
            'email' => 'household-spreadsheet@example.test',
        ]);
        $this->enableSignIn($parent, 'her-own@example.test');

        Mail::fake();
        $this->asAdmin($this->adminA);
        $this->postJson($this->inviteUrl($this->masjidA, $parent))->assertOk();

        Mail::assertSent(FamilyPortalInviteMail::class, 1);
        Mail::assertSent(
            FamilyPortalInviteMail::class,
            fn (FamilyPortalInviteMail $mail) => $mail->hasTo('her-own@example.test')
                && ! $mail->hasTo('household-spreadsheet@example.test')
        );
    }

    #[Test]
    public function the_mail_names_the_school_and_carries_the_lifetime_in_words(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'copy@example.test');

        Mail::fake();
        $this->asAdmin($this->adminA);
        $this->postJson($this->inviteUrl($this->masjidA, $parent))->assertOk();

        Mail::assertSent(FamilyPortalInviteMail::class, function (FamilyPortalInviteMail $mail) {
            // "7 days", never "10080 minutes" — the copy is for a parent.
            $this->assertSame('7 days', $mail->expiresIn);
            $this->assertSame($this->masjidA->name, $mail->orgName);

            // THE CREDENTIAL IS IN THE FRAGMENT. As a query string the staff
            // equivalent was found in this production host's rotated nginx
            // access logs beside the account's email address; see
            // App\Services\Auth\AccountAccessService.
            $this->assertStringContainsString('/family/'.$this->masjidA->id.'/invite#token=', $mail->url);
            $this->assertStringNotContainsString('?token=', $mail->url);

            return true;
        });
    }

    // ===================================================================
    // 7b. A send that fails is REPORTED as a failure, and changes nothing.
    // ===================================================================

    #[Test]
    public function a_mail_failure_is_a_500_that_leaves_the_record_untouched(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'relay@example.test');

        // A link the parent is already holding. The failed send below must not
        // take it away from them, which is what the 500's wording promises.
        $existing = $this->sendInvite($parent);

        // The relay is down. `TransportException` is the real shape of this —
        // and it extends \RuntimeException, which is precisely why the
        // controller needs `InviteDeliveryFailed` caught BEFORE its refusal
        // block: without that ordering this answers 422 with the transport's own
        // message, telling the office to go and fix a member record that is
        // perfectly fine.
        Mail::shouldReceive('to')->andThrow(
            new \Symfony\Component\Mailer\Exception\TransportException(
                'Connection to smtp.example.test:587 timed out'
            )
        );

        $this->asAdmin($this->adminA);
        $response = $this->postJson($this->inviteUrl($this->masjidA, $parent))
            ->assertStatus(500);

        // Not the transport's words, and not the recipient's address.
        $this->assertStringNotContainsString('smtp.example.test', $response->getContent());
        $this->assertStringNotContainsString('relay@example.test', $response->getContent());

        // THE TRANSACTION ROLLED BACK. No second invite row, and no `invite_sent`
        // row claiming a family was written to — the "write fails, UI says
        // success" shape, refused in both directions.
        $this->assertSame(1, ContactPortalInvite::withoutMasjidScope()->count());
        $this->assertSame(
            1,
            ContactLoginEvent::withoutMasjidScope()
                ->where('contact_id', $parent->id)
                ->where('action', ContactLoginEvent::ACTION_INVITE_SENT)
                ->count()
        );

        // …and the link they are already holding still works, exactly as the
        // refusal told the office it would.
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $existing])
            ->assertOk();
    }

    // ===================================================================
    // 8. The throttle.
    // ===================================================================

    #[Test]
    public function a_contact_cannot_be_flooded_with_invites(): void
    {
        config(['family.invite.sends_per_hour_per_contact' => 3]);

        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'flood@example.test');

        for ($i = 0; $i < 3; $i++) {
            $this->asAdmin($this->adminA);
            $this->postJson($this->inviteUrl($this->masjidA, $parent))->assertOk();
        }

        Mail::fake();
        $this->asAdmin($this->adminA);
        $this->postJson($this->inviteUrl($this->masjidA, $parent))
            ->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertSame(3, ContactPortalInvite::withoutMasjidScope()->count());

        // It is a rate, not a wall: an hour later the office can try again. The
        // count lives in the database rather than a cache precisely so that a
        // deploy flushing the cache cannot silently re-arm it mid-hour.
        $this->travelTo(now()->addHour()->addMinute());
        $this->asAdmin($this->adminA);
        $this->postJson($this->inviteUrl($this->masjidA, $parent))->assertOk();
        $this->travelBack();
    }

    // ===================================================================
    // 9. Tenant isolation, in both realms.
    // ===================================================================

    #[Test]
    public function another_tenants_contact_cannot_be_invited(): void
    {
        $parentA = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parentA, 'isolated@example.test');

        // Masjid B's admin, naming their OWN organisation, reaching for A's
        // contact id. The scoped findOrFail sits outside any try/catch, so this
        // is a clean 404 and never a 500 — and never a ContactPortalInvite row.
        Mail::fake();
        $this->asAdmin($this->adminB);
        $this->postJson($this->inviteUrl($this->masjidB, $parentA))
            ->assertStatus(404);

        Mail::assertNothingSent();
        $this->assertSame(0, ContactPortalInvite::withoutMasjidScope()->count());

        // And naming A's organisation is refused before this controller runs at
        // all, by ResolveMasjidTenant.
        $this->asAdmin($this->adminB);
        $this->postJson($this->inviteUrl($this->masjidA, $parentA))
            ->assertStatus(403);

        $this->assertSame(0, ContactPortalInvite::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_link_minted_for_one_school_does_not_open_another(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'cross@example.test');

        $token = $this->sendInvite($parent);

        // The same token, presented at the other organisation's door. The
        // guest-tenant middleware binds from the {masjid_id} in the URL and
        // ContactPortalInvite is BelongsToMasjid, so the row is simply not there
        // — no 403 that would confirm it exists somewhere.
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidB), ['token' => $token])
            ->assertStatus(410);

        // …and it still works at its own.
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertOk();
    }

    // ===================================================================
    // 10. The record, and the screen.
    // ===================================================================

    #[Test]
    public function sending_an_invite_is_written_to_the_access_history(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'audited@example.test');

        $this->sendInvite($parent);

        $event = ContactLoginEvent::withoutMasjidScope()
            ->where('contact_id', $parent->id)
            ->where('action', ContactLoginEvent::ACTION_INVITE_SENT)
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'sending an invite left nothing on the access history');
        $this->assertSame('audited@example.test', $event->login_email);
        $this->assertSame($this->adminA->id, $event->actor_user_id);
        // Snapshotted, not read back through the foreign key: staff soft-delete,
        // and "who mailed a key to my daughter's file" has to survive that.
        $this->assertSame($this->adminA->name, $event->actor_name);
        $this->assertSame($this->masjidA->id, $event->masjid_id);
    }

    #[Test]
    public function the_panel_says_whether_a_link_was_ever_sent(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'panel@example.test');

        // Before: "enabled, never signed in" with no way to tell whether that is
        // a family ignoring the school or a school that never wrote to them.
        $this->asAdmin($this->adminA);
        $this->getJson($this->panelUrl($this->masjidA, $parent))
            ->assertOk()
            ->assertJsonPath('data.invite', null);

        $this->sendInvite($parent);

        $this->asAdmin($this->adminA);
        $this->getJson($this->panelUrl($this->masjidA, $parent))
            ->assertOk()
            ->assertJsonPath('data.invite.state', 'pending')
            ->assertJsonPath('data.invite.login_email', 'panel@example.test');
    }

    #[Test]
    public function the_token_never_appears_in_any_payload(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'secret@example.test');

        $token = $this->sendInvite($parent);

        // The panel is the one screen that reads this table. It must be able to
        // say a link was sent without being able to hand anybody the link.
        $this->asAdmin($this->adminA);
        $body = $this->getJson($this->panelUrl($this->masjidA, $parent))
            ->assertOk()
            ->assertJsonPath('data.invite.state', 'pending')
            ->getContent();

        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString('token_hash', $body);

        // Nor is the token itself what is stored. The column holds a keyed
        // digest, so the table alone is worth nothing.
        $stored = ContactPortalInvite::withoutMasjidScope()->latest('id')->first();
        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(64, strlen((string) $stored->token_hash));
    }

    #[Test]
    public function a_malformed_token_is_a_422_and_a_wrong_one_is_a_410(): void
    {
        // The difference is a fact about the caller's own input, knowable
        // without any server, so it discloses nothing — and it keeps a client
        // able to tell "nothing was in the link" from "that link is dead".
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => 'short'])
            ->assertStatus(422);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => str_repeat('a', 64)])
            ->assertStatus(410);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Send one invite as masjid A's admin and return the plaintext token out of
     * the link, which is the only place it exists after `issue()` returns.
     */
    private function sendInvite(Contact $contact): string
    {
        Mail::fake();

        $this->asAdmin($this->adminA);
        $this->postJson($this->inviteUrl($this->masjidA, $contact))->assertOk();

        $token = null;

        Mail::assertSent(FamilyPortalInviteMail::class, function (FamilyPortalInviteMail $mail) use (&$token) {
            parse_str((string) parse_url($mail->url, PHP_URL_FRAGMENT), $query);
            $token = $query['token'] ?? null;

            return true;
        });

        $this->assertNotEmpty($token, 'the invite email carried no token');

        return (string) $token;
    }

    /** Turn parent-portal sign-in on (or move it) at an address, as A's admin. */
    private function enableSignIn(Contact $contact, string $email): void
    {
        $this->asAdmin($this->adminA);
        $this->postJson($this->panelUrl($this->masjidA, $contact), ['login_email' => $email])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled');
    }

    private function panelUrl(Masjid $masjid, Contact $contact): string
    {
        return "/api/admin/masjids/{$masjid->id}/contacts/{$contact->id}/family-login";
    }

    private function inviteUrl(Masjid $masjid, Contact $contact): string
    {
        return $this->panelUrl($masjid, $contact).'/invite';
    }

    private function redeemUrl(Masjid $masjid): string
    {
        return "/api/family/masjids/{$masjid->id}/auth/invite";
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    /**
     * A contact who is somebody's confirmed GUARDIAN — the only kind of contact
     * that may hold a family login at all, and therefore the only kind that may
     * be invited to one.
     */
    private function makeGuardian(Masjid $masjid, array $attributes = []): Contact
    {
        $guardian = Contact::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
        ], $attributes));

        $name = 'Class '.uniqid();

        $group = Group::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'kind' => 'class',
        ]);

        $ward = Contact::factory()->create(['masjid_id' => $masjid->id]);

        GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $ward->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $guardian->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $ward->id,
            'joined_at' => now(),
        ]);

        return $guardian;
    }

    /**
     * Put the process back into the state a genuinely NEW request starts from.
     *
     * `RequestGuard::user()` memoizes and `TenantContext` is a scoped binding
     * nothing clears mid-process, so without this a later call inside one test
     * would be answered out of the earlier call's state — and would keep passing
     * with the check under test deleted. It matters more here than almost
     * anywhere else in the suite, because every test in this file crosses from
     * the STAFF realm into the FAMILY realm and back.
     */
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
}
