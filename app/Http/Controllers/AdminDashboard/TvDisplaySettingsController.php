<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\TvConfigController;
use App\Http\Requests\Admin\TvDisplay\SaveTvDisplaySettingsRequest;
use App\Models\Masjid;
use App\Models\MasjidTvSetting;
use App\Support\Errors;
use App\Support\MobileCache;
use App\Support\TvBoard;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "TV Display" page: an organisation reads and saves what its lobby TV
 * board shows (owner, 2026-10-03). Before this the board's settings were
 * constants in TvConfigController and nobody could change them.
 *
 *   GET  /api/admin/masjids/{masjid_id}/tv-display
 *   POST /api/admin/masjids/{masjid_id}/tv-display
 *
 * Inside the `admin` + `tenant` group: a SuperAdmin, or this organisation's
 * own MasjidAdmin. No permission is minted (Permission::count() stays 8) and
 * no capability gates it: the public tv-config read never follows a module
 * switch (ModuleSideDoorsTest), so neither does the page that feeds it.
 *
 * ## The answer has three parts, on both verbs
 *
 *  - `settings`: what is STORED, null meaning "not chosen". The form is drawn
 *    from this, so opening the page and saving stores nothing new.
 *  - `effective`: what the board is told NOW, from the same resolver the
 *    public endpoint uses (App\Support\TvBoard). The page shows it as "on the
 *    screen now".
 *  - `context`: why an effective value is what it is (not a masjid, no
 *    donation link), the defaults a blank field falls back to, and the limits
 *    the form checks before the server does.
 *
 * ## Tenancy
 *
 * `MasjidTvSetting` is tenant-scoped, so `first()` here is this organisation's
 * row and a save is stamped with the bound tenant. Nothing filters by hand and
 * no `masjid_id` is read from a body.
 *
 * ## The board hears about a save within minutes
 *
 * tv-config is cached for five minutes and the board polls about every three.
 * The save flushes that cache, so the wait is one poll; without the flush the
 * page would look ignored for up to eight minutes.
 */
class TvDisplaySettingsController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::with('donationLink')->findOrFail($masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($masjid, MasjidTvSetting::query()->first()),
        ], Response::HTTP_OK);
    }

    public function save(SaveTvDisplaySettingsRequest $request, $masjid_id)
    {
        $masjid = Masjid::with('donationLink')->findOrFail($masjid_id);

        // Only what the request carried AND validated: an absent key is unchanged.
        $chosen = $this->normalised($request->safe()->only(TvBoard::SETTINGS));

        try {
            try {
                $setting = $this->store($chosen, $request->user()?->id);
            } catch (UniqueConstraintViolationException) {
                // Two first saves at the same moment (two administrators, or two tabs): both
                // found no row and the other one's insert won. Its row exists now, so this
                // save is applied to it instead of being lost as an error.
                $setting = $this->store($chosen, $request->user()?->id);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // The row IS saved from here on. If the cache cannot be flushed the board still
        // gets the change when the entry expires (five minutes), so that is written down
        // and the save is answered as the success it was: "Not saved" beside a board that
        // then changes would be the worse answer.
        try {
            MobileCache::flushMasjid((int) $masjid->id, MobileCache::TV_CONFIG);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($masjid, $setting->fresh()),
        ], Response::HTTP_OK);
    }

    /**
     * Write the chosen settings to this organisation's row, making the row on a first save.
     *
     * @param array<string, mixed> $chosen
     */
    private function store(array $chosen, ?int $userId): MasjidTvSetting
    {
        $setting = MasjidTvSetting::query()->first() ?? new MasjidTvSetting();
        $setting->fill($chosen);
        $setting->updated_by_user_id = $userId;
        $setting->save();

        return $setting;
    }

    /**
     * What is stored, in its one form.
     *
     * A switch left ON is stored as null, not true: the prayer panel and the
     * donation code are derived (the organisation's type, its donation link),
     * and a stored `true` would read as a choice nobody made. Blank text is
     * null, never an empty string: an empty title would hide the board's
     * header where null shows the organisation's name.
     *
     * @param array<string, mixed> $given
     * @return array<string, mixed>
     */
    private function normalised(array $given): array
    {
        foreach (TvBoard::SWITCHES as $key) {
            if (array_key_exists($key, $given) && $given[$key] !== null) {
                $given[$key] = filter_var($given[$key], FILTER_VALIDATE_BOOLEAN) ? null : false;
            }
        }

        foreach (['header_title', 'donate_caption'] as $key) {
            if (array_key_exists($key, $given)) {
                $text = trim((string) ($given[$key] ?? ''));
                $given[$key] = $text === '' ? null : $text;
            }
        }

        if (array_key_exists('carousel_interval_seconds', $given) && $given['carousel_interval_seconds'] !== null) {
            $given['carousel_interval_seconds'] = (int) $given['carousel_interval_seconds'];
        }

        return $given;
    }

    /** @return array<string, mixed> */
    private function payload(Masjid $masjid, ?MasjidTvSetting $setting): array
    {
        $donateUrl = TvBoard::donateUrl($masjid);

        return [
            'settings' => [
                'is_enabled' => $setting?->is_enabled,
                'header_title' => $setting?->header_title,
                'carousel_interval_seconds' => $setting?->carousel_interval_seconds,
                'show_prayer_panel' => $setting?->show_prayer_panel,
                'show_qr' => $setting?->show_qr,
                'donate_caption' => $setting?->donate_caption,
            ],
            'effective' => TvBoard::resolve($masjid, $donateUrl, $setting)->toArray(),
            'context' => [
                'organisation_name' => $masjid->name,
                'is_masjid' => $masjid->isMasjid(),
                'has_donation_link' => $donateUrl !== null,
                'defaults' => [
                    'carousel_interval_seconds' => TvConfigController::CAROUSEL_INTERVAL_SECONDS,
                    'donate_caption' => TvConfigController::DONATE_CAPTION,
                ],
                'limits' => [
                    'header_title_max' => TvBoard::HEADER_TITLE_MAX,
                    'donate_caption_max' => TvBoard::DONATE_CAPTION_MAX,
                    'carousel_interval_min' => TvBoard::CAROUSEL_INTERVAL_MIN,
                    'carousel_interval_max' => TvBoard::CAROUSEL_INTERVAL_MAX,
                ],
                'updated_at' => $setting?->updated_at?->toIso8601String(),
            ],
        ];
    }
}
