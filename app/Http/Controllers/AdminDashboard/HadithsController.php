<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\HadithStrength;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Hadiths\StoreHadithRequest;
use App\Http\Requests\Admin\Hadiths\UpdateHadithRequest;
use App\Models\Hadith;
use App\Models\LibraryHadith;
use App\Support\MobileCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
     * List curated library presets, searchable by free text (?search=). Read-only:
     * these are global presets the admin can copy into the live hadiths collection.
     */
    public function library(Request $request)
    {
        $search = $request->input('search');

        $presets = LibraryHadith::query()
            ->searchLike($search)
            ->orderBy('category')
            ->orderBy('title')
            ->paginate($request->input('per_page', 30));

        return response()->json([
            'status' => 'success',
            'data' => $presets,
        ], Response::HTTP_OK);
    }

    /**
     * Copy a chosen library preset into the live hadiths collection as a normal,
     * editable/deletable row. `hadiths.show_date` is unique + required, so we assign
     * the next free future date automatically (the admin can change it afterwards).
     */
    public function addFromLibrary(Request $request)
    {
        try {
            $validated = $request->validate([
                'library_hadith_id' => 'required|integer|exists:library_hadiths,id',
            ]);

            $preset = LibraryHadith::findOrFail($validated['library_hadith_id']);

            $hadith = Hadith::create([
                'title' => $preset->title,
                'isnad' => $preset->isnad ?? '',
                'matn' => $preset->matn,
                'description' => $preset->description,
                'strength' => $preset->strength,
                'muhaddith' => $preset->muhaddith,
                'references' => $preset->references,
                'show_date' => $this->nextAvailableShowDate(),
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $hadith,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Find the next date (starting tomorrow) not already taken by a hadith's unique
     * show_date, so a library copy never collides with an existing scheduled hadith.
     */
    private function nextAvailableShowDate(): string
    {
        $date = Carbon::tomorrow();
        $taken = Hadith::pluck('show_date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->flip();

        while ($taken->has($date->format('Y-m-d'))) {
            $date->addDay();
        }

        return $date->format('Y-m-d');
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
