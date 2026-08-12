<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactLoginCode;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-TENANT isolation for everything T-015d and T-015e added, at the model
 * layer and over HTTP.
 *
 * `.claude/rules/tenant-scoping.md` requires a cross-tenant Feature test for
 * every new model using `BelongsToMasjid`, and this slice adds exactly one such
 * model: `ContactLoginCode`. Hence the file name — the same
 * `<Model>TenantIsolationTest` convention as GroupTenantIsolationTest,
 * BehaviorTenantIsolationTest and HifzTenantIsolationTest. MySQL has no
 * row-level security, so the bound tenant plus these tests are the ONLY
 * backstop; nothing in the database would catch a missing global scope.
 *
 * The T-015e READ surface is covered here too rather than in a file of its own,
 * because the question is identical and the fixture is the same two masjids:
 * every family endpoint is tenant-scoped by the same binding the codes are.
 *
 * Two things are pinned that the model-layer half alone would miss:
 *
 *  - the SIGN-IN endpoints are unauthenticated, so their tenant comes from the
 *    URL (`family.guest`). An unbound contact lookup there would search every
 *    masjid in the database and mail a code to a parent at a DIFFERENT school —
 *    a cross-tenant existence oracle delivered by SMTP.
 *  - one human may hold a login at two organisations, because `login_email` is
 *    unique PER TENANT and never globally (a global identity table would be the
 *    cross-tenant correlation channel the design refused outright). So the same
 *    address must resolve to a different contact under each masjid, and to
 *    neither under a third.
 */
class ContactLoginCodeTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;

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

        $this->a = $this->makeMasjid();
        $this->b = $this->makeMasjid();
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

    private function makeParent(Masjid $masjid, ?string $loginEmail = null): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $masjid->id]);

        $contact->forceFill([
            'login_email' => $loginEmail ?? ('parent-' . uniqid() . '@test.local'),
            'login_enabled_at' => now(),
        ])->save();

        return $contact->refresh();
    }

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        $this->tenant()->forgetTenant();
    }

    // ------------------------------------------- 1. the model layer (the rule)

    #[Test]
    public function contact_login_codes_are_invisible_across_tenants(): void
    {
        $parentA = $this->makeParent($this->a);
        $parentB = $this->makeParent($this->b);

        // Written UNBOUND (as a console or system caller would), so masjid_id
        // comes from the payload and both rows genuinely exist.
        $codeA = ContactLoginCode::create([
            'masjid_id' => $this->a->id,
            'contact_id' => $parentA->id,
            'code_hash' => str_repeat('a', 64),
            'channel' => ContactLoginCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);
        $codeB = ContactLoginCode::create([
            'masjid_id' => $this->b->id,
            'contact_id' => $parentB->id,
            'code_hash' => str_repeat('b', 64),
            'channel' => ContactLoginCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame(2, ContactLoginCode::withoutMasjidScope()->count());

        $this->tenant()->set($this->a->id);

        // READ: B's row is a MISS, not a filtered result.
        $this->assertSame(1, ContactLoginCode::count());
        $this->assertNotNull(ContactLoginCode::find($codeA->id));
        $this->assertNull(ContactLoginCode::find($codeB->id));

        // UPDATE and DELETE reach nothing of B's either — a global scope that
        // covered SELECT but not the write paths would still be a breach.
        $this->assertSame(0, ContactLoginCode::where('id', $codeB->id)->update(['attempts' => 5]));
        $this->assertSame(0, ContactLoginCode::where('id', $codeB->id)->delete());
        $this->assertSame(0, (int) $codeB->fresh()->attempts);
        $this->assertNotNull(ContactLoginCode::withoutMasjidScope()->find($codeB->id));

        // CREATE stamps the BOUND tenant and overrides client input, so a code
        // cannot be planted in another organisation.
        $planted = ContactLoginCode::create([
            'masjid_id' => $this->b->id,
            'contact_id' => $parentA->id,
            'code_hash' => str_repeat('c', 64),
            'channel' => ContactLoginCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame($this->a->id, (int) $planted->masjid_id);
    }

    #[Test]
    public function the_prune_sweep_crosses_tenants_on_purpose_and_spares_live_codes(): void
    {
        // The one place the scope is deliberately NOT applied. A maintenance
        // command runs with no bound tenant, and `runWithout()` states that
        // rather than relying on the process happening to have bound nothing —
        // so the sweep stays correct if it is ever invoked from inside a bound
        // request, which is exactly the mistake that would leave one masjid's
        // rows growing forever.
        $parentA = $this->makeParent($this->a);
        $parentB = $this->makeParent($this->b);

        $old = collect([[$this->a, $parentA], [$this->b, $parentB]])->map(
            fn ($pair) => ContactLoginCode::create([
                'masjid_id' => $pair[0]->id,
                'contact_id' => $pair[1]->id,
                'code_hash' => str_repeat('a', 64),
                'channel' => ContactLoginCode::CHANNEL_EMAIL,
                'expires_at' => now()->subDays(40),
            ])
        );

        $live = ContactLoginCode::create([
            'masjid_id' => $this->a->id,
            'contact_id' => $parentA->id,
            'code_hash' => str_repeat('b', 64),
            'channel' => ContactLoginCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Bind a tenant FIRST, so a sweep that forgot runWithout() would only
        // reach half the rows and this test would catch it.
        $this->tenant()->set($this->a->id);

        $this->artisan('family:prune-login-codes', ['--days' => 30])->assertSuccessful();

        $this->assertSame(1, ContactLoginCode::withoutMasjidScope()->count());
        $this->assertNotNull(ContactLoginCode::withoutMasjidScope()->find($live->id));

        foreach ($old as $row) {
            $this->assertNull(ContactLoginCode::withoutMasjidScope()->find($row->id));
        }
    }

    // ------------------------------------------------- 2. signing in, per tenant

    #[Test]
    public function a_code_request_is_scoped_to_the_masjid_in_the_url(): void
    {
        $parentA = $this->makeParent($this->a);

        Mail::fake();

        // A's parent, asked for at B's door. B has no such contact, so this is
        // the ordinary "nobody" case — 202 and nothing happens. What must NOT
        // happen is the lookup finding A's row because the context was unbound.
        $this->postJson("/api/family/masjids/{$this->b->id}/auth/request-code", [
            'email' => $parentA->login_email,
        ])->assertStatus(202);

        Mail::assertNothingSent();
        $this->assertSame(0, ContactLoginCode::withoutMasjidScope()->count());

        // The same address at A's door does work — so the silence above is the
        // tenant boundary and not a broken fixture.
        $this->asANewRequest();
        Mail::fake();

        $this->postJson("/api/family/masjids/{$this->a->id}/auth/request-code", [
            'email' => $parentA->login_email,
        ])->assertStatus(202);

        Mail::assertSent(\App\Mail\FamilyLoginCodeMail::class);
        $this->assertSame(1, ContactLoginCode::withoutMasjidScope()->count());
    }

    #[Test]
    public function one_address_can_hold_a_separate_login_at_two_organisations(): void
    {
        // The consequence the design accepts explicitly (§2, §11): uniqueness is
        // `(masjid_id, login_email)`, never global, because a globally unique
        // credential address would be a cross-tenant correlation channel — "is
        // this parent also at that other school?" is the question this product
        // must be unable to answer. The price is one login per organisation, and
        // this test is what proves the price is actually paid rather than
        // accidentally avoided by a global index.
        $shared = 'shared-' . uniqid() . '@test.local';

        $atA = $this->makeParent($this->a, $shared);
        $atB = $this->makeParent($this->b, $shared);

        $this->assertNotSame($atA->id, $atB->id);

        foreach ([[$this->a, $atA], [$this->b, $atB]] as [$masjid, $expected]) {
            $this->asANewRequest();
            Mail::fake();

            $this->postJson("/api/family/masjids/{$masjid->id}/auth/request-code", ['email' => $shared])
                ->assertStatus(202);

            $row = ContactLoginCode::withoutMasjidScope()->latest('id')->firstOrFail();

            $this->assertSame($expected->id, (int) $row->contact_id);
            $this->assertSame($masjid->id, (int) $row->masjid_id);
        }
    }

    #[Test]
    public function a_code_minted_at_one_masjid_cannot_be_redeemed_at_another(): void
    {
        $shared = 'shared-' . uniqid() . '@test.local';
        $atA = $this->makeParent($this->a, $shared);
        $this->makeParent($this->b, $shared);

        Mail::fake();

        $this->postJson("/api/family/masjids/{$this->a->id}/auth/request-code", ['email' => $shared])
            ->assertStatus(202);

        $code = null;
        Mail::assertSent(\App\Mail\FamilyLoginCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        // Presented at B's door with B's copy of the same address. The code is
        // real, unexpired and unconsumed — it simply belongs to another
        // organisation's contact, and the tenant scope means B's lookup never
        // sees it.
        $this->asANewRequest();

        $this->postJson("/api/family/masjids/{$this->b->id}/auth/verify-code", [
            'email' => $shared,
            'code' => $code,
        ])->assertStatus(410);

        $this->assertSame(0, $atA->tokens()->count());

        // And it still works at A's.
        $this->asANewRequest();

        $this->postJson("/api/family/masjids/{$this->a->id}/auth/verify-code", [
            'email' => $shared,
            'code' => $code,
        ])->assertOk();
    }

    // --------------------------------------------- 3. the portal, across tenants

    #[Test]
    public function a_parent_cannot_read_another_organisations_group_by_any_route(): void
    {
        $parentA = $this->makeParent($this->a);
        $groupA = Group::factory()->create(['masjid_id' => $this->a->id]);
        $childA = Contact::factory()->create(['masjid_id' => $this->a->id]);

        GroupMembership::create([
            'masjid_id' => $this->a->id,
            'group_id' => $groupA->id,
            'contact_id' => $childA->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        GroupMembership::create([
            'masjid_id' => $this->a->id,
            'group_id' => $groupA->id,
            'contact_id' => $parentA->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $childA->id,
            'consent_granted_at' => now(),
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        // B's own classroom, with B's own child in it.
        $groupB = Group::factory()->create(['masjid_id' => $this->b->id]);
        $childB = Contact::factory()->create(['masjid_id' => $this->b->id]);
        $membershipB = GroupMembership::create([
            'masjid_id' => $this->b->id,
            'group_id' => $groupB->id,
            'contact_id' => $childB->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $token = $parentA->createFamilyToken()->plainTextToken;

        // Naming B's masjid in the URL: refused by `family.tenant` BEFORE
        // anything binds, because the tenant comes from the token's contact and
        // the URL is only an assertion to check.
        foreach ([
            "/api/family/masjids/{$this->b->id}/groups",
            "/api/family/masjids/{$this->b->id}/groups/{$groupB->id}",
            "/api/family/masjids/{$this->b->id}/groups/{$groupB->id}/posts",
            "/api/family/masjids/{$this->b->id}/groups/{$groupB->id}/threads",
            "/api/family/masjids/{$this->b->id}/groups/{$groupB->id}/members/{$membershipB->id}/awards",
        ] as $uri) {
            $this->asANewRequest();

            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson($uri)
                ->assertStatus(403);

            $this->assertNull(
                $this->tenant()->get(),
                'a refused cross-tenant family request must not have bound anything'
            );
        }

        // Naming B's GROUP under A's own masjid id: a 404 miss, because the
        // bound tenant plus BelongsToMasjid make the row invisible. Not a 403 —
        // to this token that group does not exist.
        foreach ([
            "/api/family/masjids/{$this->a->id}/groups/{$groupB->id}",
            "/api/family/masjids/{$this->a->id}/groups/{$groupB->id}/posts",
            "/api/family/masjids/{$this->a->id}/groups/{$groupB->id}/threads",
            "/api/family/masjids/{$this->a->id}/groups/{$groupB->id}/members/{$membershipB->id}/hifz",
        ] as $uri) {
            $this->asANewRequest();

            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson($uri)
                ->assertStatus(404);
        }

        // Non-vacuity: this token really does work on its OWN group, so the
        // refusals above are the tenant boundary and not a broken fixture.
        $this->asANewRequest();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->a->id}/groups/{$groupA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $groupA->id);
    }

    #[Test]
    public function the_family_listing_never_spans_organisations_even_unbound(): void
    {
        // The failure this guards against is silent: if `family.tenant` were
        // ever bypassed or reordered away, `TenantContext` would be unbound and
        // .claude/rules/tenant-scoping.md defines unbound as NO FILTER — the
        // groups listing would then return every organisation's classrooms. The
        // middleware aborts rather than falling through, and FamilyTenantBindingTest
        // pins that; this pins the consequence, by asserting the request left the
        // context bound to exactly one masjid.
        $parentA = $this->makeParent($this->a);
        $groupA = Group::factory()->create(['masjid_id' => $this->a->id]);
        Group::factory()->create(['masjid_id' => $this->b->id]);

        GroupMembership::create([
            'masjid_id' => $this->a->id,
            'group_id' => $groupA->id,
            'contact_id' => $parentA->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->asANewRequest();

        $this->withHeader('Authorization', 'Bearer ' . $parentA->createFamilyToken()->plainTextToken)
            ->getJson("/api/family/masjids/{$this->a->id}/groups")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $groupA->id);

        $this->assertSame($this->a->id, $this->tenant()->get());
    }
}
