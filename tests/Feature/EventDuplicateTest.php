<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminDashboard\EventsController;
use App\Http\Requests\Admin\Events\DuplicateEventRequest;
use App\Models\Event;
use App\Models\Masjid;
use App\Models\User;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * "Duplicate this event" over HTTP — POST
 * /api/admin/masjids/{masjid_id}/events/{event_id}/duplicate (T-042a).
 *
 * This is also the FIRST Feature test the events slice has ever had, which is
 * why it pins more than the new endpoint. Two of the guarantees below describe
 * behaviour that already shipped and had nothing holding it:
 *
 *  - `Event` carries NO `BelongsToMasjid` global scope (it is on
 *    TenantScopingCoverageTest's frozen HAND_SCOPED_LEGACY ledger). Every
 *    isolation guarantee for this table therefore rests on the controller
 *    remembering to write `where('masjid_id', ...)` by hand. Nothing tested
 *    that. `another_masjids_event_id_is_a_404` and
 *    `a_copy_is_never_stamped_into_another_organisation` do now.
 *  - The mobile app reads events through MobileCache::EVENTS. A write that
 *    forgets to flush shows a stale week on every phone for the whole TTL and
 *    looks, to the admin, exactly like a save that did not happen.
 *
 * The rest pin what "duplicate, not a repeat rule" MEANS as a product promise:
 * you see every date before it is created, the copies are ordinary independent
 * rows, and a batch either lands whole or not at all.
 *
 * And one pins something no test in this repository pinned before: that the
 * endpoint is REGISTERED. Every case here was originally written against a
 * route that did not exist. Most of them would simply have failed, but the
 * tenancy 404 would have passed on the dead endpoint, because Laravel answers
 * an unregistered path with the same status the controller's hand-filter
 * returns. `resolveDuplicateRoute()` is how that stays impossible.
 */
class EventDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;
    private Event $sourceA;
    private Event $sourceB;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->sourceA = $this->makeEvent($this->masjidA, [
            'title' => 'Sisters Halaqa',
            'details' => 'Weekly circle in the multipurpose room.',
            'place' => 'Room 2',
            'link' => 'https://example.org/halaqa',
            'start' => '2027-01-05 19:00',
            'end' => '2027-01-05 20:30',
        ]);

        $this->sourceB = $this->makeEvent($this->masjidB, [
            'title' => 'Other Org Fundraiser',
            'start' => '2027-01-05 19:00',
        ]);
    }

    /** Create a Masjid row with the minimum columns the schema requires. */
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
        ], $overrides));
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function makeEvent(Masjid $masjid, array $attributes = []): Event
    {
        return Event::create(array_merge([
            'masjid_id' => $masjid->id,
            'title' => 'Event ' . uniqid(),
            'details' => 'Details',
            'place' => 'Main hall',
            'start' => '2027-02-01 18:00',
            'end' => null,
            'link' => null,
        ], $attributes));
    }

    private function duplicateUrl(Event $event, ?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id
            . '/events/' . $event->id . '/duplicate';
    }

    /**
     * Normalise a stored datetime to 'Y-m-d H:i' for comparison.
     *
     * `Event` declares no casts, so `start` comes back as whatever text the
     * engine stored — SQLite keeps the literal '2027-01-12 19:00', MySQL
     * returns '2027-01-12 19:00:00'. Trimming to 16 characters is the same
     * normalisation the controller's clash check uses, and it is why these
     * assertions mean the same thing on CI's sqlite and on production's MySQL.
     */
    private function startOf(Event $event): string
    {
        return substr((string) $event->start, 0, 16);
    }

    /**
     * Resolve the POST route for $url, or fail saying exactly what is wrong.
     *
     * This exists because of how this file first shipped: the controller, the
     * FormRequest, the Vue modal and all ~20 cases below were written against a
     * route that was never registered. Laravel answers an unregistered path with
     * the SAME 404 that `Event::where('masjid_id', ...)->findOrFail()` produces,
     * so `another_masjids_event_id_is_a_404` would have passed on a completely
     * dead endpoint — green, and pinning nothing but a typo. Every other case
     * would have failed on the route miss rather than on the behaviour it names.
     *
     * A 404 assertion on an unauthenticated-write path is only meaningful once
     * something proves the route exists, so that proof is a helper rather than a
     * comment.
     */
    private function resolveDuplicateRoute(string $url): RoutingRoute
    {
        try {
            return Route::getRoutes()->match(Request::create($url, 'POST'));
        } catch (NotFoundHttpException $e) {
            $this->fail(
                'No POST route is registered at ' . $url . '. A 404 from this URL is a '
                . 'route miss, not the controller refusing a foreign row — register '
                . "Route::post('/{event_id}/duplicate', 'duplicate') in the events "
                . 'group in routes/admin.php.'
            );
        }
    }

    // ---------- the endpoint exists at all ----------

    #[Test]
    public function the_duplicate_endpoint_is_registered_with_the_same_posture_as_the_other_event_writes(): void
    {
        $route = $this->resolveDuplicateRoute($this->duplicateUrl($this->sourceA));

        $this->assertSame(EventsController::class . '@duplicate', $route->getActionName());

        // Duplicate creates the same rows `store` creates, so it must sit behind
        // the same three gates and no fewer. `tenant` in particular is what 403s
        // a foreign {masjid_id}; the controller's hand-filter is the second
        // layer, not the first, because `Event` carries no global scope.
        $middleware = $route->gatherMiddleware();

        foreach (['auth:sanctum', 'admin', 'tenant'] as $gate) {
            $this->assertContains(
                $gate,
                $middleware,
                'The duplicate route must carry ' . $gate . ', exactly as every other event write does.'
            );
        }
    }

    // ---------- auth and tenancy ----------

    #[Test]
    public function duplicating_requires_an_authenticated_admin(): void
    {
        $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [['start' => '2027-01-12 19:00']],
        ])->assertStatus(401);

        $this->assertSame(1, Event::where('masjid_id', $this->masjidA->id)->count());
    }

    #[Test]
    public function another_organisations_route_is_a_403(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->duplicateUrl($this->sourceB, $this->masjidB), [
            'dates' => [['start' => '2027-01-12 19:00']],
        ])->assertStatus(403);

        $this->assertSame(1, Event::where('masjid_id', $this->masjidB->id)->count());
    }

    #[Test]
    public function another_masjids_event_id_is_a_404(): void
    {
        Sanctum::actingAs($this->adminA);

        $url = $this->duplicateUrl($this->sourceB, $this->masjidA);

        // FIRST prove something is routed here. Without this line the assertion
        // below is satisfied by an endpoint that does not exist — which is
        // precisely what happened before the route was registered.
        $this->resolveDuplicateRoute($url);

        // Masjid A's own route, but an event id that belongs to B. `Event` has
        // no global scope, so ONLY the controller's hand-filter stops this.
        $this->postJson($url, [
            'dates' => [['start' => '2027-01-12 19:00']],
        ])->assertStatus(404);

        $this->assertSame(1, Event::where('masjid_id', $this->masjidB->id)->count());
        $this->assertSame(1, Event::where('masjid_id', $this->masjidA->id)->count());
    }

    #[Test]
    public function a_copy_is_never_stamped_into_another_organisation(): void
    {
        Sanctum::actingAs($this->adminA);

        // A client-supplied masjid_id anywhere in the body must be inert: the
        // copy belongs to the organisation that owns the SOURCE row.
        $this->postJson($this->duplicateUrl($this->sourceA), [
            'masjid_id' => $this->masjidB->id,
            'dates' => [
                ['start' => '2027-01-12 19:00', 'masjid_id' => $this->masjidB->id],
            ],
        ])->assertOk();

        $this->assertSame(1, Event::where('masjid_id', $this->masjidB->id)->count());
        $this->assertSame(2, Event::where('masjid_id', $this->masjidA->id)->count());
    }

    // ---------- the copy itself ----------

    #[Test]
    public function duplicating_an_event_copies_its_wording_and_takes_the_new_dates(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [
                ['start' => '2027-01-12 19:00', 'end' => '2027-01-12 20:30'],
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $copy = Event::findOrFail($response->json('data.0.id'));

        // Wording: copied verbatim.
        $this->assertSame($this->sourceA->title, $copy->title);
        $this->assertSame($this->sourceA->details, $copy->details);
        $this->assertSame($this->sourceA->place, $copy->place);
        $this->assertSame($this->sourceA->link, $copy->link);

        // Dates: taken from the body, NOT from the source.
        $this->assertSame('2027-01-12 19:00', $this->startOf($copy));
        $this->assertNotSame($this->startOf($this->sourceA), $this->startOf($copy));
    }

    #[Test]
    public function duplicating_onto_several_dates_creates_one_event_per_date(): void
    {
        Sanctum::actingAs($this->adminA);

        $requested = ['2027-01-12 19:00', '2027-01-19 19:00', '2027-01-26 19:00'];

        $response = $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => array_map(static fn (string $start): array => ['start' => $start], $requested),
        ])->assertOk();

        $this->assertCount(3, $response->json('data'));

        // The source plus one row per requested date, and every created date is
        // a date the caller actually asked for — the promise the review panel in
        // the modal makes to the admin before they press the button.
        $created = Event::where('masjid_id', $this->masjidA->id)
            ->where('id', '!=', $this->sourceA->id)
            ->get();

        $this->assertCount(3, $created);
        $this->assertEqualsCanonicalizing(
            $requested,
            $created->map(fn (Event $event): string => $this->startOf($event))->all()
        );
    }

    #[Test]
    public function an_end_is_optional_on_a_copy(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [['start' => '2027-01-12 19:00']],
        ])->assertOk();

        $this->assertNull(Event::findOrFail($response->json('data.0.id'))->end);
    }

    // ---------- what a batch refuses ----------

    #[Test]
    public function a_partly_invalid_batch_creates_nothing(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [
                ['start' => '2027-01-12 19:00'],
                ['start' => 'next tuesday'],
                ['start' => '2027-01-26 19:00'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertSame(1, Event::where('masjid_id', $this->masjidA->id)->count());
    }

    #[Test]
    public function an_end_before_its_own_start_is_refused(): void
    {
        Sanctum::actingAs($this->adminA);

        // Row 1's end is before ROW 1's start while being after row 0's — the
        // case a non-wildcard `after:` rule would wave through.
        $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [
                ['start' => '2027-01-12 19:00', 'end' => '2027-01-12 20:00'],
                ['start' => '2027-01-19 19:00', 'end' => '2027-01-19 18:00'],
            ],
        ])->assertStatus(422);

        $this->assertSame(1, Event::where('masjid_id', $this->masjidA->id)->count());
    }

    #[Test]
    public function the_same_date_listed_twice_is_refused_before_anything_is_created(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [
                ['start' => '2027-01-12 19:00'],
                ['start' => '2027-01-12 19:00'],
            ],
        ])->assertStatus(422);

        $this->assertSame(1, Event::where('masjid_id', $this->masjidA->id)->count());
    }

    #[Test]
    public function more_than_the_maximum_number_of_dates_is_refused(): void
    {
        Sanctum::actingAs($this->adminA);

        $dates = [];
        for ($day = 1; $day <= DuplicateEventRequest::MAX_DATES + 1; $day++) {
            $dates[] = ['start' => sprintf('2027-03-%02d 19:00', $day)];
        }

        $this->postJson($this->duplicateUrl($this->sourceA), ['dates' => $dates])
            ->assertStatus(422);

        $this->assertSame(1, Event::where('masjid_id', $this->masjidA->id)->count());
    }

    #[Test]
    public function an_empty_batch_is_refused_rather_than_reported_as_a_success(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->duplicateUrl($this->sourceA), ['dates' => []])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function a_date_that_already_holds_the_same_event_is_refused_and_nothing_is_copied(): void
    {
        Sanctum::actingAs($this->adminA);

        $body = [
            'dates' => [
                ['start' => '2027-01-12 19:00'],
                ['start' => '2027-01-19 19:00'],
            ],
        ];

        $this->postJson($this->duplicateUrl($this->sourceA), $body)->assertOk();
        $this->assertSame(3, Event::where('masjid_id', $this->masjidA->id)->count());

        // The double click. The whole batch is refused, including the date that
        // was NOT already taken — a half-applied retry is the thing an admin
        // cannot untangle from the UI.
        $this->postJson($this->duplicateUrl($this->sourceA), $body)
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertSame(3, Event::where('masjid_id', $this->masjidA->id)->count());
    }

    #[Test]
    public function a_clash_only_counts_inside_the_same_organisation(): void
    {
        Sanctum::actingAs($this->adminA);

        // Masjid B already holds an event at this exact time. That must not
        // block masjid A — a cross-tenant read here would be the leak, and a
        // cross-tenant REFUSAL would be an oracle for it.
        $this->makeEvent($this->masjidB, [
            'title' => $this->sourceA->title,
            'start' => '2027-01-12 19:00',
        ]);

        $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [['start' => '2027-01-12 19:00']],
        ])->assertOk();

        $this->assertSame(2, Event::where('masjid_id', $this->masjidA->id)->count());
    }

    // ---------- the copies are independent rows ----------

    #[Test]
    public function editing_one_copy_leaves_every_other_copy_and_the_original_untouched(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [
                ['start' => '2027-01-12 19:00'],
                ['start' => '2027-01-19 19:00'],
            ],
        ])->assertOk();

        $firstCopyId = $response->json('data.0.id');
        $secondCopyId = $response->json('data.1.id');

        $this->putJson('/api/admin/masjids/' . $this->masjidA->id . '/events/' . $firstCopyId, [
            'title' => 'Sisters Halaqa — CANCELLED',
            'details' => 'Cancelled this week only.',
            'place' => 'Room 2',
            'start' => '2027-01-12 19:00',
            'end' => '2027-01-12 20:30',
        ])->assertOk();

        $this->assertSame('Sisters Halaqa — CANCELLED', Event::findOrFail($firstCopyId)->title);
        $this->assertSame($this->sourceA->title, Event::findOrFail($secondCopyId)->title);
        $this->assertSame($this->sourceA->title, Event::findOrFail($this->sourceA->id)->title);
    }

    #[Test]
    public function deleting_one_copy_removes_exactly_one_date(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [
                ['start' => '2027-01-12 19:00'],
                ['start' => '2027-01-19 19:00'],
            ],
        ])->assertOk();

        $firstCopyId = $response->json('data.0.id');

        $this->deleteJson('/api/admin/masjids/' . $this->masjidA->id . '/events/' . $firstCopyId)
            ->assertOk();

        // Exactly one row went: the delete confirmation in EventDetailsView
        // promises "this removes 1 date — this one", and that sentence is only
        // honest while nothing links the copies together.
        $this->assertNull(Event::find($firstCopyId));
        $this->assertSame(2, Event::where('masjid_id', $this->masjidA->id)->count());
        $this->assertNotNull(Event::find($this->sourceA->id));
    }

    // ---------- the cache the phone reads ----------

    #[Test]
    public function duplicating_flushes_the_mobile_events_cache(): void
    {
        Sanctum::actingAs($this->adminA);

        $key = MobileCache::masjidKey((int) $this->masjidA->id, MobileCache::EVENTS);
        Cache::put($key, 'stale', 60);

        $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [['start' => '2027-01-12 19:00']],
        ])->assertOk();

        $this->assertFalse(Cache::has($key));
    }

    #[Test]
    public function a_refused_batch_leaves_the_mobile_cache_alone(): void
    {
        Sanctum::actingAs($this->adminA);

        $key = MobileCache::masjidKey((int) $this->masjidA->id, MobileCache::EVENTS);
        Cache::put($key, 'still-correct', 60);

        $this->postJson($this->duplicateUrl($this->sourceA), [
            'dates' => [['start' => 'not a date']],
        ])->assertStatus(422);

        // Nothing was written, so nothing the phone holds became wrong. Flushing
        // anyway would hide a bug behind a cold cache.
        $this->assertSame('still-correct', Cache::get($key));
    }
}
