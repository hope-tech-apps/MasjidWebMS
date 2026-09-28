<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminDashboard\CountriesCitiesController;
use App\Models\City;
use App\Models\Country;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * GET /api/admin/countries/{country_id}/cities and Studio's two variants of it
 * (DECISIONS.md 2026-09-27, the NAFIS walkthrough's city picker).
 *
 * With no parameter it must answer byte for byte as it did: the onboarding
 * wizard and the super masjid form fill a select from it. `?q=` is Studio's
 * type-to-find: names that start with the text, then names that contain it,
 * each name once as its lowest id (the data has no state, so three "Raleigh"
 * rows are one choice), at most SEARCH_LIMIT. `?id=` names one stored city.
 *
 * The `%`, `_`, `!` and `\` cases are the ones SQLite would hide: without the
 * ESCAPE clause SQLite reads a backslash as an ordinary character, so a
 * backslash-escaped pattern would match nothing here and everything a
 * wildcard matches on MySQL. Each asserts the one row that holds the character,
 * not merely an empty answer.
 */
class CitySearchTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/countries';

    private int $usa;

    private int $canada;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // Canada first, so its Raleigh has the lowest id of all: dedupe must be per country.
        $this->canada = Country::forceCreate(['name' => 'Canada', 'code' => 'CA'])->id;
        $this->usa = Country::forceCreate(['name' => 'United States', 'code' => 'US'])->id;
    }

    private function city(int $countryId, string $name): City
    {
        return City::forceCreate(['name' => $name, 'country_id' => $countryId]);
    }

    private function actAsSuper(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh());
    }

    private function url(int $countryId, string $query = ''): string
    {
        return self::BASE . "/{$countryId}/cities" . ($query === '' ? '' : "?{$query}");
    }

    /** @return list<string> */
    private function names(\Illuminate\Testing\TestResponse $response): array
    {
        return array_column($response->json('data'), 'name');
    }

    #[Test]
    public function with_no_parameter_the_answer_is_byte_identical_to_the_full_list(): void
    {
        $this->city($this->canada, 'Raleigh');
        foreach (['Raleigh', 'Durham', 'Raleigh', 'North Raleigh', 'Cary'] as $name) {
            $this->city($this->usa, $name);
        }
        $this->actAsSuper();

        // Exactly what the controller built before the change.
        $expected = response()->json([
            'status' => 'success',
            'data' => Country::findOrFail($this->usa)->cities->toArray(),
        ], Response::HTTP_OK)->getContent();

        $plain = $this->getJson($this->url($this->usa))->assertOk();
        $this->assertSame($expected, $plain->getContent());
        $this->assertCount(5, $plain->json('data'), 'every row, duplicates included');
        $this->assertSame(['id', 'name', 'country_id', 'created_at', 'updated_at'], array_keys($plain->json('data.0')));

        // An empty or blank q is absent, not a search.
        $this->assertSame($expected, $this->getJson($this->url($this->usa, 'q='))->assertOk()->getContent());
        $this->assertSame($expected, $this->getJson($this->url($this->usa, 'q=%20%20%20'))->assertOk()->getContent());
    }

    #[Test]
    public function q_puts_names_that_start_with_it_first_and_offers_each_name_once_as_its_lowest_id(): void
    {
        $first = $this->city($this->usa, 'Raleigh');
        $this->city($this->usa, 'North Raleigh');
        $this->city($this->usa, 'Raleigh');
        $this->city($this->usa, 'Ralston');
        $this->city($this->usa, 'Raleigh');
        $this->city($this->usa, 'Durham');
        $this->actAsSuper();

        $response = $this->getJson($this->url($this->usa, 'q=ral'))->assertOk();

        $this->assertSame(['Raleigh', 'Ralston', 'North Raleigh'], $this->names($response));
        $this->assertSame($first->id, $response->json('data.0.id'), 'the lowest id stands for the name');
        $this->assertSame(
            City::findOrFail($first->id)->toArray(),
            $response->json('data.0'),
            'each row keeps the full list\'s shape'
        );

        // Case does not matter, and the text is trimmed.
        $this->assertSame(['Raleigh', 'Ralston', 'North Raleigh'], $this->names($this->getJson($this->url($this->usa, 'q=%20RAL%20'))->assertOk()));
    }

    #[Test]
    public function q_answers_at_most_the_search_limit(): void
    {
        $now = now();
        DB::table('cities')->insert(array_map(fn (int $i) => [
            'name' => sprintf('Springton %03d', $i), 'country_id' => $this->usa, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, CountriesCitiesController::SEARCH_LIMIT + 10)));
        $this->actAsSuper();

        $response = $this->getJson($this->url($this->usa, 'q=ton'))->assertOk();

        $this->assertSame(50, CountriesCitiesController::SEARCH_LIMIT);
        $this->assertCount(CountriesCitiesController::SEARCH_LIMIT, $response->json('data'));
        $this->assertSame('Springton 001', $response->json('data.0.name'));
    }

    #[Test]
    public function percent_underscore_bang_and_backslash_in_q_match_only_themselves(): void
    {
        foreach (['100% Pure', 'Plain', 'A_B', 'AxB', 'Bang!', 'Back\\slash', 'Backslash'] as $name) {
            $this->city($this->usa, $name);
        }
        $this->actAsSuper();

        $this->assertSame(['100% Pure'], $this->names($this->getJson($this->url($this->usa, 'q=' . rawurlencode('%')))->assertOk()));
        $this->assertSame(['A_B'], $this->names($this->getJson($this->url($this->usa, 'q=' . rawurlencode('_')))->assertOk()));
        $this->assertSame(['Bang!'], $this->names($this->getJson($this->url($this->usa, 'q=' . rawurlencode('!')))->assertOk()));
        $this->assertSame(['Back\\slash'], $this->names($this->getJson($this->url($this->usa, 'q=' . rawurlencode('\\')))->assertOk()));
        $this->assertSame([], $this->names($this->getJson($this->url($this->usa, 'q=' . rawurlencode('%%')))->assertOk()));
    }

    #[Test]
    public function id_answers_that_city_of_that_country_or_nothing(): void
    {
        $this->city($this->usa, 'Raleigh');
        $second = $this->city($this->usa, 'Raleigh');
        $canadian = $this->city($this->canada, 'Burlington');
        $this->actAsSuper();

        $response = $this->getJson($this->url($this->usa, "id={$second->id}"))->assertOk();
        $this->assertSame([City::findOrFail($second->id)->toArray()], $response->json('data'), 'the id asked for, not the lowest of its name');

        $this->assertSame([], $this->getJson($this->url($this->usa, "id={$canadian->id}"))->assertOk()->json('data'));
        $this->assertSame([], $this->getJson($this->url($this->usa, 'id=999999'))->assertOk()->json('data'));
    }

    #[Test]
    public function another_countrys_city_never_appears(): void
    {
        $canadian = $this->city($this->canada, 'Raleigh');
        $this->city($this->canada, 'Ralphton');
        $american = $this->city($this->usa, 'Raleigh');
        $this->actAsSuper();

        $response = $this->getJson($this->url($this->usa, 'q=ral'))->assertOk();

        $this->assertSame([$american->id], array_column($response->json('data'), 'id'));
        $this->assertNotContains($canadian->id, array_column($response->json('data'), 'id'));
        $this->assertSame([$this->usa], array_values(array_unique(array_column($response->json('data'), 'country_id'))));
    }

    #[Test]
    public function a_malformed_parameter_is_refused_in_the_422_envelope(): void
    {
        $city = $this->city($this->usa, 'Raleigh');
        $this->actAsSuper();

        foreach ([
            'q=' . str_repeat('a', 101),
            'q[]=ral',
            'id=abc',
            'id=0',
            'id=-3',
            "q=ral&id={$city->id}",
        ] as $query) {
            $response = $this->getJson($this->url($this->usa, $query));
            $this->assertSame(422, $response->getStatusCode(), "?{$query} answered {$response->getStatusCode()}");
            $this->assertSame('failed', $response->json('status'), "?{$query} left the envelope");
        }

        // 100 characters is the limit, not over it.
        $this->getJson($this->url($this->usa, 'q=' . str_repeat('a', 100)))->assertOk()->assertExactJson(['status' => 'success', 'data' => []]);
    }

    #[Test]
    public function an_unknown_country_is_still_a_404(): void
    {
        $this->actAsSuper();

        $this->getJson($this->url(999999))->assertNotFound();
        $this->getJson($this->url(999999, 'q=ral'))->assertNotFound();
    }

    #[Test]
    public function nobody_below_super_admin_gets_any_variant(): void
    {
        $city = $this->city($this->usa, 'Raleigh');
        $org = Masjid::create([
            'name' => 'City Search Org', 'email' => 'city-search@test.local', 'phone' => '+15550001111',
            'country_id' => $this->usa, 'city_id' => $city->id, 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => Masjid::ORG_TYPE_MASJID,
        ]);
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550002222']);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);

        foreach (['', 'q=ral', "id={$city->id}"] as $query) {
            // The `super` gate's envelope, unchanged (SuperAdminMiddleware).
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($admin->fresh());
            $this->getJson($this->url($this->usa, $query))
                ->assertStatus(401)
                ->assertExactJson(['status' => 'failed', 'data' => 'Unauthorized.']);

            // A guest is refused by the token guard before any gate runs.
            $this->app['auth']->forgetGuards();
            $this->getJson($this->url($this->usa, $query))->assertStatus(401);
        }
    }
}
