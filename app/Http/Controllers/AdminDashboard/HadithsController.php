<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\HadithStrength;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Hadiths\StoreHadithRequest;
use App\Http\Requests\Admin\Hadiths\UpdateHadithRequest;
use App\Models\Hadith;
use App\Support\MobileCache;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Response;

class HadithsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $hadithList = Hadith::paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $hadithList
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreHadithRequest $request)
    {
        try {
            $validated = $request->validated();

            $hadith = Hadith::create([
                'title' => $validated['title'],
                'isnad' => $validated['isnad'],
                'matn' => $validated['matn'],
                'description' => $validated['description'],
                'strength' => HadithStrength::from($validated['strength'])->toJson(),
                'muhaddith' => [
                    'ar' => $validated['muhaddith_ar'],
                    'en' => $validated['muhaddith_en'],
                ],
                'references' => $validated['references'],
                'show_date' => $validated['show_date'],
            ]);

            // If the new hadith is dated today, invalidate today's cached hadith.
            if (Carbon::parse($validated['show_date'])->isToday()) {
                MobileCache::flushGlobal(MobileCache::HADITH_TODAY, Carbon::now()->format('Y-m-d'));
            }

            return response()->json([
                'status' => 'success',
                'data' => $hadith
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($hadith_id)
    {
        $hadith = Hadith::findOrFail($hadith_id);
        return response()->json([
            'status' => 'success',
            'data' => $hadith
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateHadithRequest $request, string $hadith_id)
    {
        try {
            $hadith = Hadith::findOrFail($hadith_id);
            $validated = $request->validated();

            $hadith->update([
                'title' => $validated['title'],
                'isnad' => $validated['isnad'],
                'matn' => $validated['matn'],
                'description' => $validated['description'],
                'strength' => HadithStrength::from($validated['strength'])->toJson(),
                'muhaddith' => [
                    'ar' => $validated['muhaddith_ar'],
                    'en' => $validated['muhaddith_en'],
                ],
                'references' => $validated['references'],
                'show_date' => $validated['show_date'],
            ]);

            // Today's cached hadith may be stale after an admin edit.
            if (Carbon::parse($validated['show_date'])->isToday() || Carbon::parse($hadith->getOriginal('show_date'))->isToday()) {
                MobileCache::flushGlobal(MobileCache::HADITH_TODAY, Carbon::now()->format('Y-m-d'));
            }

            return response()->json([
                'status' => 'success',
                'data' => $hadith
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $hadith_id)
    {
        $hadith = Hadith::findOrFail($hadith_id);
        $wasToday = Carbon::parse($hadith->show_date)->isToday();
        $hadith->delete();

        if ($wasToday) {
            MobileCache::flushGlobal(MobileCache::HADITH_TODAY, Carbon::now()->format('Y-m-d'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $hadith
        ], Response::HTTP_OK);
    }
}
