<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The unauthenticated /api/v1 surface must never answer a tenant-less request
 * with every tenant's rows.
 *
 * ## The bug this pins
 *
 * `SearchableTrait::scopeFilterByMasjid()` read:
 *
 *     $resourceId = request()->header('masjid-id');
 *     if ($resourceId) $query->where('masjid_id', $resourceId);
 *
 * so a request with NO header — or a falsy one such as `masjid-id: 0` — added
 * no WHERE clause at all. `Announcement`, `Service`, `Page` and `Section` do
 * not use BelongsToMasjid, so nothing else scoped them and the query returned
 * every organisation's rows to an anonymous caller. `Page` and `Section` each
 * carried their own copy of the same fail-open scope, shadowing the trait.
 *
 * Measured against production on 2026-08-11, before the fix:
 *
 *     masjid-id: 1   -> 11 announcements
 *     masjid-id: 13  ->  3 announcements
 *     (no header)    -> 14 announcements   <- both tenants
 *     masjid-id: 0   -> 14 announcements   <- falsy bypass
 *
 * The assertions below are written as that arithmetic — A's count, B's count,
 * and a refusal where the sum used to be — so a regression shows up as a leak
 * rather than as a message about a status code.
 */
class PublicApiTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

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

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        // A has two of everything, B has one — so "A's rows" and "everything"
        // can never be confused for one another by a count.
        foreach (['A-first', 'A-second'] as $title) {
            $this->makeAnnouncement($this->masjidA, $title);
            $this->makeService($this->masjidA, $title);
        }
        $this->makeAnnouncement($this->masjidB, 'B-only');
        $this->makeService($this->masjidB, 'B-only');

        $this->makePage($this->masjidA, 'a-one');
        $this->makePage($this->masjidA, 'a-two');
        $this->makePage($this->masjidB, 'b-only');
    }

    // ------------------------------------------------- the leak itself

    #[Test]
    public function announcements_are_confined_to_the_masjid_in_the_header(): void
    {
        $response = $this->getJson('/api/v1/announcements?per_page=100', [
            'masjid-id' => (string) $this->masjidA->id,
        ]);

        $response->assertOk();

        $titles = collect($response->json('data.items'))->pluck('title')->all();

        $this->assertCount(2, $titles);
        $this->assertNotContains('B-only', $titles, 'another tenant\'s announcement leaked');
    }

    #[Test]
    public function services_are_confined_to_the_masjid_in_the_header(): void
    {
        $response = $this->getJson('/api/v1/services', [
            'masjid-id' => (string) $this->masjidA->id,
        ]);

        $response->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('B-only', $body, 'another tenant\'s service leaked');
    }

    #[Test]
    public function pages_are_confined_to_the_masjid_in_the_header(): void
    {
        $response = $this->getJson('/api/v1/pages', [
            'masjid-id' => (string) $this->masjidA->id,
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('b-only', $response->getContent(), 'another tenant\'s page leaked');
    }

    // ------------------------------------------------- the refusals

    #[Test]
    public function a_missing_masjid_header_is_refused_rather_than_answered_with_every_tenant(): void
    {
        // This is the exploit verbatim: no header, a large page size.
        $response = $this->getJson('/api/v1/announcements?per_page=1000');

        $response->assertStatus(400);
        $this->assertStringNotContainsString('B-only', $response->getContent());
        $this->assertStringNotContainsString('A-first', $response->getContent());
    }

    #[Test]
    public function a_falsy_masjid_header_is_refused_rather_than_treated_as_absent(): void
    {
        // `0` and `''` are the values that slipped past the old `if ($id)`.
        foreach (['0', ''] as $value) {
            $this->getJson('/api/v1/announcements?per_page=1000', ['masjid-id' => $value])
                ->assertStatus(400);
        }
    }

    #[Test]
    public function every_public_collection_endpoint_refuses_a_tenant_less_request(): void
    {
        // Guards the whole surface, not just the endpoint the leak was found
        // on — a new controller calling the scope inherits this.
        $endpoints = [
            '/api/v1/announcements',
            '/api/v1/services',
            '/api/v1/pages',
            '/api/v1/pages/menu',
        ];

        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint)->assertStatus(400, "{$endpoint} did not refuse a tenant-less request");
        }
    }

    #[Test]
    public function a_tenant_less_gallery_request_is_a_client_error_not_a_server_fault(): void
    {
        // The gallery resolved its tenant with findOrFail() inside a blanket
        // catch that answered 500, making it the largest single source of real
        // application errors in production's log. A caller who names no
        // organisation has made a client mistake, and the status must say so.
        $this->getJson('/api/v1/gallery')->assertStatus(400);
        $this->getJson('/api/v1/gallery', ['masjid-id' => '0'])->assertStatus(400);
        $this->getJson('/api/v1/gallery', ['masjid-id' => '999999'])->assertStatus(404);

        $this->getJson('/api/v1/gallery', ['masjid-id' => (string) $this->masjidA->id])
            ->assertOk();
    }

    #[Test]
    public function an_unknown_masjid_yields_nothing_rather_than_everything(): void
    {
        // The property this test exists for is unchanged and is asserted below:
        // NOT ONE ROW of any tenant comes back.
        //
        // What changed on 2026-08-12 is the shape of "nothing". This used to be
        // 200 with an empty list, because `filterByMasjid` added a WHERE on an id
        // that matched nothing. That answer was indistinguishable from "this
        // organisation exists and has published no announcements" — and it was
        // also the answer a SOFT-DELETED organisation got for its own rows,
        // which is the far worse half: `masjids` soft-deletes, so an offboarded
        // organisation's id went on matching its own announcements, services and
        // pages forever, and they went on being published.
        //
        // The scope now verifies the organisation exists (App\Support\PublicTenant)
        // and refuses with a 404 — the same contract the gallery on this very
        // surface has always had (see the test above: `masjid-id: 999999` -> 404),
        // and the same one ZakatCalculatorController, ContactUsController,
        // AppointmentRequestsController and PhotoGalleryController each state in
        // their own words. One public API, one answer.
        //
        // Note the two are still distinct, which is the whole reason 400 was not
        // reused: 400 means "you named no organisation", 404 means "the one you
        // named is not there". PublicTenantLifecycleTest walks the whole surface.
        $response = $this->getJson('/api/v1/announcements?per_page=1000', [
            'masjid-id' => '999999',
        ]);

        $response->assertStatus(404);

        $body = $response->getContent();
        $this->assertStringNotContainsString('A-first', $body, 'a tenant\'s announcement leaked to an unknown masjid');
        $this->assertStringNotContainsString('B-only', $body, 'a tenant\'s announcement leaked to an unknown masjid');
        $this->assertNull($response->json('data.items'));
    }

    // ------------------------------------------------- helpers

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Org '.uniqid(),
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function makeAnnouncement(Masjid $masjid, string $title): Announcement
    {
        return Announcement::create([
            'masjid_id' => $masjid->id,
            'title' => $title,
            'summary' => $title,
            'details' => $title,
            'text' => $title,
            'start_date' => now()->subDay()->format('Y-m-d'),
            'end_date' => now()->addDay()->format('Y-m-d'),
        ]);
    }

    private function makeService(Masjid $masjid, string $title): Service
    {
        return Service::create([
            'masjid_id' => $masjid->id,
            'title' => $title,
            'summary' => $title,
            'description' => $title,
            'text' => $title,
        ]);
    }

    private function makePage(Masjid $masjid, string $slug): Page
    {
        return Page::create([
            'masjid_id' => $masjid->id,
            'slug' => $slug,
            'title' => $slug,
            'is_active' => true,
            'order' => 0,
        ]);
    }
}
