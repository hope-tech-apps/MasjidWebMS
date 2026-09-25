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
 * "Copy link" — a portal invite the SuperAdmin carries, not one the app mails.
 *
 * `FamilyInviteService::issue()` was written so the plaintext never reached its
 * caller: generated, mailed, discarded. The property that bought is that **no
 * staff member ever holds a working key to a child's photographs, marks and
 * safeguarding conversations** — an emailed link can only be read by whoever
 * holds the mailbox the office typed, and the row says which mailbox that was.
 *
 * The owner asked for it back, narrowly: "the ability to set Sajida's login
 * myself", "the link for me to text her directly", and — asked who should be
 * able to do that — **"You only (super admin)"**. DECISIONS.md, 2026-09-25.
 *
 * That makes the restriction the load-bearing part of this feature, so it is the
 * first thing asserted and the thing the mutation check below targets. The rest
 * of the file exists to hold the other half of the bargain: the copied link is
 * the SAME credential, under the SAME rules, and no rule was forked to get it.
 *
 *  1. **SuperAdmin only.** A MasjidAdmin of the same school — who CAN send the
 *     emailed invite, and holds every CRM permission — is refused 403, and the
 *     refusal leaves no invite row and no audit row.
 *  2. **The link actually works.** End to end: copied, redeemed, and the session
 *     opens that parent's own record.
 *  3. **It is the same credential.** Single use, dead at seven days and a
 *     minute, and it kills an outstanding EMAILED link — one live link per
 *     contact still holds across both doors.
 *  4. **Every refusal `issue()` makes, `issueCopyableLink()` makes**: a child's
 *     own contact row, another tenant's contact, the flood ceiling.
 *  5. **The act is audited as a DIFFERENT verb**, naming the SuperAdmin.
 *  6. **The plaintext appears exactly once**, in the response to the act, and
 *     never in the panel a later GET serves.
 *
 * No real email is sent anywhere in this file: `Mail::fake()` in `setUp`, and
 * phpunit.xml pins `MAIL_MAILER=array` besides. The copy path must send none at
 * all, which several tests assert directly.
 */
class FamilyPortalInviteCopyLinkTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $superAdmin;
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

        Mail::fake();

        // Before the users, so each is bridged to its spatie role on save —
        // exactly as production does after the seeder runs. The MasjidAdmin
        // below therefore holds the FULL CRM permission set, which is what makes
        // the 403 in the first test mean something.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->adminB = $this->makeAdminFor($this->masjidB);

        $this->superAdmin = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
    }

    // ===================================================================
    // 1. SuperAdmin only. This is the feature.
    // ===================================================================

    #[Test]
    public function a_masjid_admin_of_the_same_school_is_refused(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'refused@example.test');

        // The school's own office staff. They may enable this sign-in, they may
        // revoke it, and they may EMAIL this exact link — the emailed path is
        // theirs and is unchanged. What they may not do is take the link out of
        // the system and keep it.
        $this->asUser($this->adminA);
        $this->postJson($this->copyUrl($this->masjidA, $parent))
            ->assertStatus(403);

        // Refused before anything happened: no credential minted, nothing
        // audited, and — the part that matters most — no link in a response.
        $this->assertSame(0, ContactPortalInvite::withoutMasjidScope()->count());
        $this->assertSame(
            0,
            ContactLoginEvent::withoutMasjidScope()
                ->where('action', ContactLoginEvent::ACTION_INVITE_LINK_COPIED)
                ->count()
        );
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_masjid_admin_can_still_email_the_very_same_invite(): void
    {
        // The control for the test above. Without it, a 403 could equally mean
        // the route is broken, the CRM gate closed, or the contact ineligible —
        // and the test would keep passing with the whole feature deleted.
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'emailable@example.test');

        $this->asUser($this->adminA);
        $this->postJson($this->inviteUrl($this->masjidA, $parent))
            ->assertOk();

        Mail::assertSent(FamilyPortalInviteMail::class);
        $this->assertSame(1, ContactPortalInvite::withoutMasjidScope()->count());

        // …and that path is audited as the OTHER verb. Requirement 6: the
        // emailed path is untouched for everyone else.
        $this->assertSame(
            1,
            ContactLoginEvent::withoutMasjidScope()
                ->where('action', ContactLoginEvent::ACTION_INVITE_SENT)
                ->count()
        );
        $this->assertSame(
            0,
            ContactLoginEvent::withoutMasjidScope()
                ->where('action', ContactLoginEvent::ACTION_INVITE_LINK_COPIED)
                ->count()
        );
    }

    #[Test]
    public function a_super_admin_gets_a_link(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'copied@example.test');

        $this->asUser($this->superAdmin);
        $response = $this->postJson($this->copyUrl($this->masjidA, $parent))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $url = $response->json('data.copied_link.url');
        $this->assertNotEmpty($url, 'copying produced no link');

        // The token is in the FRAGMENT, never the query string — the property
        // the emailed link has for the reason AccountAccessService records (a
        // staff reset token was found in this production host's nginx access
        // logs as a query parameter, beside the account's email).
        $this->assertNull(parse_url($url, PHP_URL_QUERY), 'the copied link carries a query string');
        $this->assertNotEmpty(parse_url($url, PHP_URL_FRAGMENT), 'the copied link carries no fragment');

        // Nothing was mailed. The operator IS the delivery.
        Mail::assertNothingSent();

        $this->assertNotNull($response->json('data.copied_link.expires_at'));
    }

    // ===================================================================
    // 2. The link actually works — end to end, across both realms.
    // ===================================================================

    #[Test]
    public function the_copied_link_signs_the_parent_in(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'worksfor@example.test');

        $token = $this->copyLink($parent);

        // The parent, handed the link by text. One POST, no address typed and no
        // code fetched — the same door the emailed link uses.
        $this->asANewRequest();
        $response = $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.contact.id', $parent->id)
            ->assertJsonPath('data.contact.login_email', 'worksfor@example.test');

        $sessionToken = $response->json('data.token');
        $this->assertNotEmpty($sessionToken, 'redeeming the copied link produced no session token');

        // …and it is an ORDINARY family session. Nothing about config/family.php
        // is loosened by this door either.
        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer '.$sessionToken)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertOk()
            ->assertJsonPath('data.id', $parent->id);

        $this->assertNotNull($parent->fresh()->last_login_at);
    }

    // ===================================================================
    // 3. It is the SAME credential, under the same rules.
    // ===================================================================

    #[Test]
    public function the_copied_link_can_only_be_used_once(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'onceonly@example.test');

        $token = $this->copyLink($parent);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])->assertOk();

        // A forwarded text, a screenshot passed on, the same link opened twice.
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertStatus(410);
    }

    #[Test]
    public function the_copied_link_is_dead_at_seven_days_and_one_minute(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'sevendays@example.test');

        $issuedAt = Carbon::parse('2026-10-01 09:00:00');
        $this->travelTo($issuedAt);

        $token = $this->copyLink($parent);

        // Alive at six days and twenty-three hours, so a pass here cannot be a
        // clock that never moved. The boundary is asserted from both sides.
        $this->travelTo($issuedAt->copy()->addDays(6)->addHours(23));
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])->assertOk();

        $this->travelTo($issuedAt);
        $later = $this->copyLink($parent);

        $this->travelTo($issuedAt->copy()->addDays(7)->addMinute());
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $later])
            ->assertStatus(410);

        $this->travelBack();
    }

    #[Test]
    public function copying_a_link_kills_the_outstanding_emailed_one(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'supersede@example.test');

        // The office emails one. Then the owner, on the phone to the same
        // parent, copies one.
        $emailed = $this->sendInvite($parent);
        $copied = $this->copyLink($parent);

        $this->assertNotSame($emailed, $copied, 'copying produced the same token the email carried');

        // ONE LIVE LINK PER CONTACT, and it holds ACROSS the two doors. If it
        // did not, "I sent you an email, and here is a text as well" would leave
        // two working keys to one child's file in two channels.
        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $emailed])
            ->assertStatus(410);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $copied])
            ->assertOk();
    }

    #[Test]
    public function emailing_a_link_kills_the_outstanding_copied_one(): void
    {
        // The mirror, because "one live link" is a property of the contact and
        // not of whichever door happened to run second.
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'mirror@example.test');

        $copied = $this->copyLink($parent);
        $emailed = $this->sendInvite($parent);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $copied])
            ->assertStatus(410);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $emailed])
            ->assertOk();
    }

    // ===================================================================
    // 4. Every refusal `issue()` makes, this makes.
    // ===================================================================

    #[Test]
    public function a_childs_own_contact_row_cannot_have_a_link_copied(): void
    {
        // Nobody's guardian. A login here would be a STUDENT login, which
        // `GroupAudience::standingIn()` would grant the whole class feed — every
        // classmate's photograph — plus the participant threads where a teacher
        // and a guardian discuss a safeguarding concern. Being a SuperAdmin does
        // not make that a different thing: the rule is about the CONTACT.
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);

        $this->asUser($this->superAdmin);
        $this->postJson($this->copyUrl($this->masjidA, $child))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, ContactPortalInvite::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_member_with_no_sign_in_at_all_gets_no_link(): void
    {
        $parent = $this->makeGuardian($this->masjidA);

        $this->asUser($this->superAdmin);
        $this->postJson($this->copyUrl($this->masjidA, $parent))
            ->assertStatus(422);

        $this->assertSame(0, ContactPortalInvite::withoutMasjidScope()->count());
    }

    #[Test]
    public function another_tenants_contact_cannot_have_a_link_copied(): void
    {
        $parentA = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parentA, 'crosstenant@example.test');

        // A SuperAdmin is bound to the masjid the ROUTE names
        // (ResolveMasjidTenant), so naming B and reaching for A's contact id is
        // a clean 404 from the scoped findOrFail — never a link, and never a 500.
        $this->asUser($this->superAdmin);
        $this->postJson($this->copyUrl($this->masjidB, $parentA))
            ->assertStatus(404);

        $this->assertSame(0, ContactPortalInvite::withoutMasjidScope()->count());

        // And B's own admin, naming A, is refused by the tenant middleware
        // before the controller runs at all.
        $this->asUser($this->adminB);
        $this->postJson($this->copyUrl($this->masjidA, $parentA))
            ->assertStatus(403);

        $this->assertSame(0, ContactPortalInvite::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_copied_link_does_not_open_another_school(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'ownschool@example.test');

        $token = $this->copyLink($parent);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidB), ['token' => $token])
            ->assertStatus(410);

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertOk();
    }

    #[Test]
    public function the_flood_limit_still_applies_and_counts_both_doors(): void
    {
        config(['family.invite.sends_per_hour_per_contact' => 3]);

        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'flooded@example.test');

        // Two copied and one emailed. The ceiling bounds WORKING KEYS IN
        // CIRCULATION for one family, so a separate allowance per door would
        // hand out six an hour under a limit that says three.
        $this->copyLink($parent);
        $this->copyLink($parent);
        $this->sendInvite($parent);

        $this->asUser($this->superAdmin);
        $this->postJson($this->copyUrl($this->masjidA, $parent))
            ->assertStatus(422);

        $this->assertSame(3, ContactPortalInvite::withoutMasjidScope()->count());

        // A rate, not a wall — and counted in the database rather than a cache
        // limiter, so an ordinary deploy cannot silently re-arm it mid-hour.
        $this->travelTo(now()->addHour()->addMinute());
        $this->asUser($this->superAdmin);
        $this->postJson($this->copyUrl($this->masjidA, $parent))->assertOk();
        $this->travelBack();
    }

    #[Test]
    public function a_revoked_sign_ins_copied_link_is_dead(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'revoked@example.test');

        $token = $this->copyLink($parent);

        // The office withdraws access. A link in the owner's text history must
        // stop working at that moment, not seven days later.
        $this->asUser($this->adminA);
        $this->deleteJson($this->panelUrl($this->masjidA, $parent))->assertOk();

        $this->asANewRequest();
        $this->postJson($this->redeemUrl($this->masjidA), ['token' => $token])
            ->assertStatus(410);
    }

    // ===================================================================
    // 5. The record.
    // ===================================================================

    #[Test]
    public function copying_a_link_is_audited_as_its_own_act(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'audit@example.test');

        $this->copyLink($parent);

        $event = ContactLoginEvent::withoutMasjidScope()
            ->where('contact_id', $parent->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'copying a link left nothing on the access history');

        // THE DISTINCTION IS THE POINT. An emailed link went to the address on
        // file; a copied one went to a person in a room, by a channel this
        // application cannot see. A trail that called both `invite_sent` could
        // not answer the question it exists for.
        $this->assertSame(ContactLoginEvent::ACTION_INVITE_LINK_COPIED, $event->action);
        $this->assertNotSame(ContactLoginEvent::ACTION_INVITE_SENT, $event->action);

        // …and it names the SuperAdmin who took it, snapshotted, because staff
        // soft-delete and "who took a key to my daughter's file" has to survive
        // that.
        $this->assertSame($this->superAdmin->id, $event->actor_user_id);
        $this->assertSame($this->superAdmin->name, $event->actor_name);
        $this->assertSame($this->superAdmin->email, $event->actor_email);
        $this->assertSame('audit@example.test', $event->login_email);
        $this->assertSame($this->masjidA->id, $event->masjid_id);

        // No `invite_sent` row was written by this act.
        $this->assertSame(
            0,
            ContactLoginEvent::withoutMasjidScope()
                ->where('contact_id', $parent->id)
                ->where('action', ContactLoginEvent::ACTION_INVITE_SENT)
                ->count()
        );
    }

    #[Test]
    public function the_panel_shows_the_copy_on_the_history_and_never_the_link(): void
    {
        $parent = $this->makeGuardian($this->masjidA);
        $this->enableSignIn($parent, 'panelread@example.test');

        $this->asUser($this->superAdmin);
        $url = $this->postJson($this->copyUrl($this->masjidA, $parent))
            ->assertOk()
            ->json('data.copied_link.url');

        parse_str((string) parse_url($url, PHP_URL_FRAGMENT), $query);
        $token = (string) ($query['token'] ?? '');
        $this->assertNotEmpty($token);

        // THE PLAINTEXT APPEARS EXACTLY ONCE. The panel a later GET serves knows
        // a link was minted and cannot reproduce it — including for the very
        // SuperAdmin who took it.
        $this->asUser($this->superAdmin);
        $body = $this->getJson($this->panelUrl($this->masjidA, $parent))
            ->assertOk()
            ->assertJsonPath('data.invite.state', 'pending')
            ->assertJsonMissingPath('data.copied_link')
            ->getContent();

        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString('token_hash', $body);

        // The act itself IS on the history, which is the other half of the
        // bargain: the link is gone, the record of taking it is not.
        $this->assertStringContainsString(ContactLoginEvent::ACTION_INVITE_LINK_COPIED, $body);

        // Nor is the plaintext what is stored: a keyed digest, so the table
        // alone is worth nothing.
        $stored = ContactPortalInvite::withoutMasjidScope()->latest('id')->first();
        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(64, strlen((string) $stored->token_hash));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Copy one link as the SuperAdmin and return the plaintext token out of it.
     */
    private function copyLink(Contact $contact): string
    {
        $this->asUser($this->superAdmin);

        $url = $this->postJson($this->copyUrl($this->masjidA, $contact))
            ->assertOk()
            ->json('data.copied_link.url');

        $this->assertNotEmpty($url, 'copying produced no link');

        parse_str((string) parse_url((string) $url, PHP_URL_FRAGMENT), $query);
        $token = $query['token'] ?? null;

        $this->assertNotEmpty($token, 'the copied link carried no token');

        return (string) $token;
    }

    /** Email one invite as A's admin and return the plaintext token out of it. */
    private function sendInvite(Contact $contact): string
    {
        Mail::fake();

        $this->asUser($this->adminA);
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

    /** Turn parent-portal sign-in on at an address, as A's admin. */
    private function enableSignIn(Contact $contact, string $email): void
    {
        $this->asUser($this->adminA);
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

    private function copyUrl(Masjid $masjid, Contact $contact): string
    {
        return $this->panelUrl($masjid, $contact).'/invite/copy-link';
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
     * that may hold a family login, and therefore the only kind whose link may
     * be copied.
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
     * anywhere else in the suite: every test in this file crosses between two
     * STAFF principals, and several cross into the family realm and back.
     */
    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    private function asUser(User $user): void
    {
        $this->asANewRequest();
        Sanctum::actingAs($user);
    }
}
