<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\HighLatitudeRule;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\PrayersController;
use App\Http\Requests\Admin\PrayerCalculation\SavePrayerCalculationSettingsRequest;
use App\Models\Masjid;
use App\Models\Prayer;
use App\Models\PrayerCalculationSetting;
use App\Services\PrayerTimes\SettingsCalculationParameters;
use App\Support\MobileCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PrayerCalculationSettingsController extends Controller
{
    /**
     * How far forward the cached rows are rebuilt after a method change.
     *
     * Deliberately the same window a single mobile GET fills by default
     * (PrayersController::index: now-1 day through now+15), so the inline work
     * here is bounded to something an anonymous request already performs on the
     * public endpoint.
     */
    private const REBUILD_DAYS_AHEAD = 15;

    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $settings = $masjid->prayerCalculationSettings;

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ], Response::HTTP_OK);
    }

    public function save(SavePrayerCalculationSettingsRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $data = $request->safe()->only(['method', 'madhab', 'high_latitude_rule']);

            // The write and the invalidation share a transaction ON PURPOSE.
            // Invalidation is only triggered when the values CHANGE, so if the
            // DELETE failed after a committed write, the admin's retry would
            // find the values already equal, skip invalidation, and leave the
            // stale rows behind permanently — a self-concealing bug. Rolling the
            // settings write back instead makes the retry a real retry.
            [$settings, $changed] = DB::transaction(function () use ($masjid, $data) {
                $settings = $masjid->prayerCalculationSettings;

                // Compare RAW column strings, not the cast enums: a corrupt
                // stored value would throw on cast, and this comparison must not
                // be the thing that 500s a save whose whole purpose is to
                // replace that value.
                //
                // With no row yet the effective "before" is the fallback triple,
                // not "nothing" — a masjid with no settings is already being
                // calculated as MoonsightingCommittee/Shafi/MiddleOfTheNight. So
                // creating a row that spells out exactly those defaults changes
                // no times and must not churn the cache.
                $before = $settings
                    ? self::storedTriple($settings)
                    : SettingsCalculationParameters::DEFAULTS;

                if ($settings) {
                    $settings->update($data);
                } else {
                    $settings = $masjid->prayerCalculationSettings()->create($data);
                }

                $changed = self::storedTriple($settings) !== $before;

                // NOTE what deliberately does NOT happen here: the cached rows are
                // not deleted inside this transaction.
                //
                // An earlier revision deleted them here and rebuilt afterwards on a
                // best-effort basis. That inverted the failure mode: anything that
                // killed the request between the commit and the rebuild — a fatal,
                // a timeout, a deploy restart, a masjid with no iqamaTimeSettings —
                // left the masjid with ZERO cached rows and still answered 200. For
                // a masjid whose devices have gone quiet, which is precisely who
                // SendDuePrayerNotifications exists to serve, nothing would ever
                // refill them and the adhan push would go permanently silent. It
                // traded "pushes at the wrong time" for "never pushes again".
                //
                // Rebuilding first makes the delete unnecessary for the served
                // window anyway: PrayersController::store() UPDATES matching rows in
                // place, so every row from yesterday to +REBUILD_DAYS_AHEAD is
                // rewritten with the new method without ever being absent. See
                // rebuildCachedPrayerRows().

                return [$settings, $changed];
            });

            // Mobile clients consume calc params via /prayers/settings — invalidate so
            // they see the new method on next sync.
            MobileCache::flushMasjid((int) $masjid_id, MobileCache::PRAYERS_SETTINGS);

            $rebuilt = $changed ? $this->rebuildCachedPrayerRows($masjid) : true;

            return response()->json([
                'status' => 'success',
                'data' => $settings,
                // Reported rather than swallowed. The settings row is saved either
                // way, but if the rebuild failed the cached rows still hold the
                // PREVIOUS method's times, so the adhan push will keep firing on the
                // old schedule until a mobile request or another save refreshes
                // them. An admin who is told that can retry; a silent 200 would let
                // them believe the change had taken effect everywhere.
                'prayers_rebuilt' => $rebuilt,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getOptions()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'methods' => collect(PrayerCalculationMethod::cases())->map(fn($case) => [
                    'value' => $case->value,
                    'label' => $case->label()
                ]),
                'madhabs' => collect(Madhab::cases())->map(fn($case) => [
                    'value' => $case->value,
                    'label' => $case->label()
                ]),
                'high_latitude_rules' => collect(HighLatitudeRule::cases())->map(fn($case) => [
                    'value' => $case->value,
                    'label' => $case->label()
                ])
            ]
        ], Response::HTTP_OK);
    }

    /**
     * Rewrite this masjid's cached prayer rows with the newly-chosen method,
     * INLINE and OUTSIDE the transaction. Returns whether it succeeded.
     *
     * ## Rebuild first, delete second — the ordering is the safety property
     *
     * The rows are only otherwise rebuilt by a mobile GET, and
     * SendDuePrayerNotifications exists precisely for masjids whose devices have
     * gone quiet. So a window in which the rows are missing is a window in which
     * a quiet masjid gets no adhan push at all — strictly worse than one in which
     * they are merely stale. Because store() UPDATES matching rows in place, no
     * such window needs to exist: rebuilding rewrites the served range without
     * ever removing it, and the only delete left is for rows beyond the rebuilt
     * horizon, which happens after the rebuild has already succeeded.
     *
     * Inline rather than queued even though this codebase has a queue idiom
     * (SendPrayerSyncJob), because it is bounded to REBUILD_DAYS_AHEAD + 1 days of
     * arithmetic plus one bulk write — exactly the work one unauthenticated GET on
     * the public prayers endpoint already does inline — and queueing would make
     * the correction contingent on the worker being up.
     *
     * PrayersController::store() is reused rather than reimplemented so the
     * iqama/jumaa/dedupe handling and the exact stored JSON — which shipped app
     * builds parse byte-for-byte — stay in exactly one place. It is not a routed
     * action: it takes scalars, returns a count, and is already called
     * internally by that controller's own index(). Resolving a controller from
     * the container to reach it is an existing idiom in app/Support. A shared
     * service would be a tidier home for it; that refactor is deliberately not
     * bundled with this fix, because moving the byte-exact generation path is a
     * change of a different risk class than wiring a parameter into it.
     */
    private function rebuildCachedPrayerRows(Masjid $masjid): bool
    {
        $through = Carbon::now()->addDays(self::REBUILD_DAYS_AHEAD)->format('Y-m-d');

        try {
            // store() updates matching rows IN PLACE, so the served window is
            // rewritten with the new method without ever being absent.
            app(PrayersController::class)->store($masjid->id, self::rebuildFrom(), $through);
        } catch (\Throwable $e) {
            // NOTHING IS DELETED ON FAILURE, and that is the point. The rows keep
            // the previous method's times: wrong by up to ~18 minutes, but present,
            // so a masjid whose devices have gone quiet keeps receiving an adhan
            // push. Deleting here would silence it entirely with nothing to refill
            // it. Degraded beats absent; the caller is told via `prayers_rebuilt`.
            Log::error('Prayer calculation settings changed but the cached prayer rows could not be rebuilt; they still hold the PREVIOUS method\'s times.', [
                'masjid_id' => $masjid->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Only now, and only the rows the rebuild could not reach. Inside the
        // window store() already rewrote every row, so there is nothing to remove
        // there. Beyond it there can be rows a client created by requesting a long
        // range (nothing caps end_date on the public endpoint) — those would keep
        // serving the OLD method forever, since no rebuild covers them. Dropping
        // them makes the next request for that range regenerate them correctly.
        Prayer::where('masjid_id', $masjid->id)
            ->where('date', '>', $through)
            ->delete();

        return true;
    }

    /** Yesterday, in the app timezone — the same lower bound both consumers use. */
    private static function rebuildFrom(): string
    {
        return Carbon::now()->subDay()->format('Y-m-d');
    }

    /**
     * The three settings as their RAW stored strings, bypassing the enum casts.
     *
     * @return array{method: ?string, madhab: ?string, high_latitude_rule: ?string}
     */
    private static function storedTriple(PrayerCalculationSetting $settings): array
    {
        $raw = $settings->getAttributes();

        return [
            'method' => $raw['method'] ?? null,
            'madhab' => $raw['madhab'] ?? null,
            'high_latitude_rule' => $raw['high_latitude_rule'] ?? null,
        ];
    }
}
