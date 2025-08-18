<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CountriesCitiesController extends Controller
{
    public function countries()
    {
        $countries = Country::all();
        return response()->json([
            'status' => 'success',
            'data' => $countries
        ], Response::HTTP_OK);
    }

    public function countryCities($country_id)
    {
        $country = Country::findOrFail($country_id);
        $cities = $country->cities->toArray();
        return response()->json([
            'status' => 'success',
            'data' => $cities
        ], Response::HTTP_OK);
    }
}
