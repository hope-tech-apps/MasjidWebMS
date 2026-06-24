<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Hadith;
use App\Support\ArabicText;
use App\Support\MobileCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HadithsController extends Controller
{
    /**
     * Display a paginated listing of hadiths, optionally filtered by category
     * and/or a free-text search over title + matn (content).
     *
     * Not cached: the result varies with category_id/search/page, so it follows
     * the same uncached, paginated pattern as the /api/v1 list endpoints rather
     * than the cached single-row /today endpoint.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $categoryId = $request->input('category_id');
        $search = $request->input('search');

        $query = Hadith::query()->latest();

        if ($categoryId) {
            $query->where('hadith_category_id', $categoryId);
        }

        if ($search) {
            // Diacritic-insensitive Arabic search: match the incoming query against
            // the normalized shadow columns (tashkeel stripped, hamza/letter variants
            // folded) so a bare query like "الايمان" still finds marked stored text
            // like "الإِيمَان". The raw title/matn LIKE is kept as a fallback so
            // Latin/partial searches and any not-yet-backfilled rows still match.
            $normalized = ArabicText::normalize($search);

            $query->where(function ($q) use ($search, $normalized) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('matn', 'LIKE', "%{$search}%");

                if ($normalized !== '') {
                    $q->orWhere('title_normalized', 'LIKE', "%{$normalized}%")
                      ->orWhere('matn_normalized', 'LIKE', "%{$normalized}%");
                }
            });
        }

        $hadiths = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $hadiths->items(),
                'pagination' => [
                    'current_page' => $hadiths->currentPage(),
                    'last_page' => $hadiths->lastPage(),
                    'per_page' => $hadiths->perPage(),
                    'total' => $hadiths->total(),
                    'from' => $hadiths->firstItem(),
                    'to' => $hadiths->lastItem(),
                ]
            ]
        ]);
    }

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
