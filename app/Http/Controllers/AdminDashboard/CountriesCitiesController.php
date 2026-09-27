<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Countries\CountryCitiesRequest;
use App\Models\City;
use App\Models\Country;
use Symfony\Component\HttpFoundation\Response;

class CountriesCitiesController extends Controller
{
    /** The most names one `?q=` search answers with. Studio's picker says "type more" at this many. */
    public const SEARCH_LIMIT = 50;

    public function countries()
    {
        $countries = Country::all();
        return response()->json([
            'status' => 'success',
            'data' => $countries
        ], Response::HTTP_OK);
    }

    /**
     * Every city of a country, or, for Studio's city picker, a search (`?q=`)
     * or one city (`?id=`). The rows keep the full list's shape either way.
     *
     * With no parameter the answer is the unfiltered list, built exactly as it
     * always was: the onboarding wizard and the super masjid form fill a select
     * from it, and CitySearchTest holds it byte-identical.
     */
    public function countryCities(CountryCitiesRequest $request, $country_id)
    {
        $country = Country::findOrFail($country_id);

        $q = $request->validated('q');
        $id = $request->validated('id');

        if ($id !== null) {
            $cities = $country->cities()->whereKey((int) $id)->get()->toArray();
        } elseif ($q !== null) {
            $cities = $this->search($country, (string) $q);
        } else {
            $cities = $country->cities->toArray();
        }

        return response()->json([
            'status' => 'success',
            'data' => $cities
        ], Response::HTTP_OK);
    }

    /**
     * Up to SEARCH_LIMIT cities whose names start with or contain `$q`, ignoring
     * case: names that start with it first, then by name.
     *
     * Each name once, as its lowest id. The data carries no state or province
     * (database/seeders/CountriesCitiesSeeder.php: a name and a country only),
     * so three rows called "Raleigh" cannot be told apart by anyone, and a city
     * is only ever shown by its name; listing all three would offer a choice
     * that does not exist. DECISIONS.md 2026-09-27.
     *
     * The input's `%` and `_` are escaped so they match themselves, with `!` as
     * the escape character. Not `\`: `ESCAPE '\'` has to be spelled differently
     * in MySQL (which reads a backslash in a literal as an escape) and SQLite
     * (which does not), and a driver switch would mean the suite tests a query
     * production never runs. With `!` named, `\` is an ordinary character in
     * both. No schema change: a country's rows are found through the
     * `country_id` foreign-key index (21,008 for the US) and filtered there.
     *
     * @return list<array<string, mixed>>
     */
    private function search(Country $country, string $q): array
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q));

        // The lowest id of each distinct name that contains the text. Grouped on
        // LOWER(name) so the two engines agree: MySQL's _ci collation would fold
        // case in a plain GROUP BY and SQLite's would not.
        $firsts = City::query()
            ->selectRaw('MIN(id) AS id')
            ->where('country_id', $country->id)
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%' . $escaped . '%'])
            ->groupByRaw('LOWER(name)');

        return City::query()
            ->joinSub($firsts, 'firsts', 'firsts.id', '=', 'cities.id')
            ->select('cities.*')
            ->orderByRaw("CASE WHEN LOWER(cities.name) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$escaped . '%'])
            ->orderByRaw('LOWER(cities.name)')
            ->orderBy('cities.id')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->toArray();
    }
}
