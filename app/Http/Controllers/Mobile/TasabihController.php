<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tasbih;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TasabihController extends Controller
{
    public function index()
    {
        $tasabih = Cache::remember(
            MobileCache::globalKey(MobileCache::TASABIH_ALL),
            MobileCache::TTL_LONG,
            fn() => Tasbih::all()->toArray()
        );

        return response()->json([
            'status' => 'success',
            'data' => $tasabih
        ], Response::HTTP_OK);
    }
}
