<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveFamilyTenant;
use App\Http\Middleware\ResolveMasjidTenant;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * T-015c — invariant 3: a family request is either bound to the caller's OWN
 * organisation or refused. There is no third outcome, and in particular there
 * is no "authenticated but unbound".
 *
 * Why that is worth a suite of its own. Tenant isolation in this application
 * lives entirely in the app layer — MySQL has no row-level security — and an
 * UNBOUND `TenantContext` means every `BelongsToMasjid` model applies NO filter
 * (.claude/rules/tenant-scoping.md). The staff middleware, `ResolveMasjidTenant`,
 * branches on `users.type` and lets everything else fall through unbound, which
 * is correct for the public mobile API and would be a cross-tenant read of
 * children's records on an authenticated family route. The control test at the
 * bottom demonstrates that fall-through rather than asserting it in a comment.
 */
class FamilyTenantBindingTest extends TestCase
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

    private function makeContactWithLogin(Masjid $masjid): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $masjid->id]);

        $contact->forceFill([
            'login_email' => 'parent-' . uniqid() . '@test.local',
            'login_enabled_at' => now(),
        ])->save();

        return $contact->refresh();
    }

    private function meUrl(Masjid $masjid): string
    {
        return "/api/family/masjids/{$masjid->id}/me";
    }

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    // -------------------------------------------------------- the HTTP surface

    #[Test]
    public function a_family_request_leaves_the_tenant_bound_to_the_contacts_own_organisation(): void
    {
        $masjid = $this->makeMasjid();
        $contact = $this->makeContactWithLogin($masjid);

        // Nothing has bound a tenant yet, so a pass below cannot be inherited
        // from earlier setup.
        $this->assertNull($this->tenant()->get());

        $this->withHeader('Authorization', 'Bearer ' . $contact->createFamilyToken()->plainTextToken)
            ->getJson($this->meUrl($masjid))
            ->assertOk();

        // `TenantContext` is a `scoped()` binding and nothing in an HTTP request
        // clears it, so the instance the middleware bound is still readable here
        // — which is how this test observes what the request actually did rather
        // than what its response looked like.
        $this->assertSame($masjid->id, $this->tenant()->get());
    }

    #[Test]
    public function a_family_token_naming_another_organisation_is_refused(): void
    {
        $own = $this->makeMasjid();
        $foreign = $this->makeMasjid();
        $contact = $this->makeContactWithLogin($own);

        $this->withHeader('Authorization', 'Bearer ' . $contact->createFamilyToken()->plainTextToken)
            ->getJson($this->meUrl($foreign))
            ->assertStatus(403);

        // Refused, and refused BEFORE anything bound the masjid the caller
        // named. A 403 that had already bound the wrong tenant would be one
        // forgotten `return` away from serving it.
        $this->assertNull($this->tenant()->get());
    }

    #[Test]
    public function the_crm_feature_gate_still_applies_to_the_family_tree(): void
    {
        // The family surface is the parent-facing half of the same CRM the
        // staff tree is gated on; a masjid that never switched it on has no
        // roster to expose. Pinned because `crm` sitting in the family stack is
        // easy to drop by accident and impossible to notice.
        $masjid = $this->makeMasjid(['crm_enabled' => false]);
        $contact = $this->makeContactWithLogin($masjid);

        $this->withHeader('Authorization', 'Bearer ' . $contact->createFamilyToken()->plainTextToken)
            ->getJson($this->meUrl($masjid))
            ->assertStatus(403);
    }

    // ------------------------------------------------- the middleware, directly

    #[Test]
    public function family_tenant_binds_the_contact_and_hides_every_other_tenants_rows(): void
    {
        $own = $this->makeMasjid();
        $foreign = $this->makeMasjid();

        $contact = $this->makeContactWithLogin($own);
        $foreignContact = Contact::factory()->create(['masjid_id' => $foreign->id]);

        $reached = false;

        $status = $this->runFamilyTenantAs($contact, function () use ($own, $contact, $foreignContact, &$reached) {
            $reached = true;

            // Bound — and bound to the token's masjid.
            $this->assertSame($own->id, $this->tenant()->get());

            // And the binding is doing real work: the global scope on a
            // BelongsToMasjid model now hides the other tenant entirely. This
            // is the assertion that would fail if `set()` were ever swapped for
            // a no-op, which a status-code-only test could not detect.
            $this->assertNotNull(Contact::find($contact->id));
            $this->assertNull(Contact::find($foreignContact->id));
            $this->assertSame(1, Contact::count());

            return response('ok');
        });

        $this->assertTrue($reached, 'a live contact must pass through family.tenant');
        $this->assertSame(200, $status);
    }

    #[Test]
    public function family_tenant_refuses_every_principal_it_cannot_bind(): void
    {
        // A staff User. `family.active` would already have refused this, but
        // the binding must be unobtainable from a non-Contact independently —
        // the two middleware are separate layers, not one check written twice.
        $staff = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550002222']);
        $this->assertSame(403, $this->runFamilyTenantAs($staff));
        $this->assertNull($this->tenant()->get());

        // A contact with no organisation. `contacts.masjid_id` is NOT NULL, so
        // this state is unreachable through the database — which is the reason
        // to pin it: the branch exists precisely because falling through here
        // would be an unfiltered read, and an untested fail-closed branch is
        // just a comment.
        $orphan = new Contact();
        $this->assertSame(403, $this->runFamilyTenantAs($orphan));
        $this->assertNull($this->tenant()->get());

        // A guest.
        $this->assertSame(403, $this->runFamilyTenantAs(null));
        $this->assertNull($this->tenant()->get());
    }

    // ------------------------------------------------------------- the control

    #[Test]
    public function control_the_staff_tenant_middleware_would_leave_a_contact_unbound(): void
    {
        // THE REASON `family.tenant` EXISTS, demonstrated rather than asserted.
        // `ResolveMasjidTenant` matches on `users.type`: MasjidAdmin, then
        // SuperAdmin, then nothing. A Contact carries no `type`, so it falls
        // through both branches and reaches the route with NO tenant bound —
        // and unbound means unfiltered, i.e. this parent would read every
        // masjid's contacts, groups and children's records.
        $own = $this->makeMasjid();
        $foreign = $this->makeMasjid();

        $contact = $this->makeContactWithLogin($own);
        Contact::factory()->create(['masjid_id' => $foreign->id]);

        $reached = false;

        $this->authenticateOnFamilyGuard($contact);

        $request = Request::create($this->meUrl($own), 'GET');
        $request->setUserResolver(fn () => Auth::user());

        app(ResolveMasjidTenant::class)->handle($request, function () use (&$reached) {
            $reached = true;

            $this->assertNull(
                $this->tenant()->get(),
                'if the staff middleware started binding a Contact, this control is stale — '
                . 'read it before deleting it, because family.tenant was written for this.'
            );

            // Two tenants' contacts, visible to one parent. This is the failure
            // `family.tenant` prevents, quantified.
            $this->assertSame(2, Contact::count());

            return response('ok');
        });

        $this->assertTrue($reached, 'the staff middleware admits a Contact — it does not refuse it');
    }

    // ------------------------------------------------ middleware test harness

    /**
     * Authenticate $principal on the `family` guard and run ResolveFamilyTenant
     * against it directly, returning the resulting status code.
     *
     * The middleware `abort()`s, which outside the HTTP kernel surfaces as an
     * HttpException rather than a response, so it is translated here — the
     * status code is the thing under test either way.
     */
    private function runFamilyTenantAs($principal, ?Closure $next = null): int
    {
        $this->authenticateOnFamilyGuard($principal);
        $this->tenant()->forgetTenant();

        $request = Request::create('/api/family/masjids/1/me', 'GET');

        try {
            return app(ResolveFamilyTenant::class)
                ->handle($request, $next ?? fn () => response('ok'))
                ->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    private function authenticateOnFamilyGuard($principal): void
    {
        Auth::forgetGuards();
        $this->tenant()->forgetTenant();

        if ($principal !== null) {
            Auth::guard('family')->setUser($principal);
        }

        Auth::shouldUse('family');
    }
}
