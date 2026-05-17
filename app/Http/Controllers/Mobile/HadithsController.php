<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Hadith;
use App\Support\MobileCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class HadithsController extends Controller
{
    public function todayHadith()
    {
        $today = Carbon::now()->format('Y-m-d');

        // Key includes today's date so the cache naturally expires at day rollover.
        $hadith = Cache::remember(
            MobileCache::globalKey(MobileCache::HADITH_TODAY, $today),
            MobileCache::TTL_DAY,
            function () use ($today) {
                $hadith = Hadith::where('show_date', $today)->first();
                if (!$hadith) {
                    $hadith = Hadith::inRandomOrder()->first();
                }
                return $hadith;
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $hadith
        ]);
    }
}
