<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\SectionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Masjids\SetAssistantAccessRequest;
use App\Http\Requests\Admin\Masjids\SetCapabilityRequest;
use App\Http\Requests\Admin\Masjids\SetCrmAccessRequest;
use App\Http\Requests\Admin\Masjids\SetDirectoryListingRequest;
use App\Http\Requests\Admin\Masjids\StoreMasjidRequest;
use App\Http\Requests\Admin\Masjids\UpdateMasjidRequest;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use App\Models\PrayerCalculationSetting;
use App\Models\User;
use App\Support\CapabilityLedger;
use App\Support\MobileCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MasjidsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $masjids = Masjid::with('logo', 'footer_logo', 'admin.avatar', 'country', 'city')
            ->get()
            ->append(Masjid::ADMIN_APPENDS);
        return response()->json([
            'status' => 'success',
            'data' => $masjids
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMasjidRequest $request)
    {
        try {
            $payload = $request->safe()->only([
                'name', 'email', 'phone', 'longitude', 'latitude',
                'address', 'user_id', 'country_id', 'city_id',
            ]);
            $payload['created_by'] = Auth::id();

            $masjid = Masjid::create($payload);

            if ($masjid) {
                // Store logo
                $masjid->addMediaFromRequest('logo')->toMediaCollection('logos');

                // Store footer logo
                $masjid->addMediaFromRequest('footer_logo')->toMediaCollection('footer_logos');

                // Assign masjid mobile app features
                $features = MobileAppFeature::all();
                foreach ($features as $feature) {
                    MasjidMobileAppFeature::create([
                        'masjid_id' => $masjid->id,
                        'feature_id' => $feature->id,
                        'is_available' => true,
                    ]);
                }

                // Assign masjid Iqama time settings
                IqamaTimeSetting::create([
                    'masjid_id' => $masjid->id,
                    'fajr' => 20,
                    'dhuhr' => 10,
                    'asr' => 10,
                    'maghrib' => 10,
                    'isha' => 10,
                ]);

                // Assign masjid Prayer Calculation settings
                PrayerCalculationSetting::create([
                    'masjid_id' => $masjid->id,
                    'method' => 'MoonsightingCommittee',
                    'madhab' => 'Shafi',
                    'high_latitude_rule' => 'MiddleOfTheNight',
                ]);
            }

            MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

            return response()->json([
                'status' => 'success',
                'data' => $masjid
            ], Response::HTTP_OK);
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
    public function show(string $id)
    {
        // The SPA's masjidStore loads the tenant from here, so this is where the
        // vertical + terminology pack has to ride along.
        $masjid = Masjid::with('admin.avatar', 'logo', 'footer_logo', 'country', 'city')
            ->findOrFail($id)
            ->append(Masjid::ADMIN_APPENDS);
        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    public function gallery($masjid_id)
    {
        $masjid = Masjid::with('gallery')->findOrFail($masjid_id);
        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    /**
     * SuperAdmin-only: flip the per-masjid CRM feature gate (masjids.crm_enabled).
     *
     * This is the lever that turns the `crm` gate on/off, so the route is
     * deliberately NOT behind that gate — a SuperAdmin needs it to turn the CRM
     * on. Super-ness is enforced here with abort(403) rather than the shared
     * `super` middleware: that middleware answers non-super callers with 401, but
     * the CRM-access contract is a clean 403 "forbidden" for anyone who isn't a
     * SuperAdmin (a MasjidAdmin must never enable the CRM on their own masjid).
     */
    public function setCrmAccess(SetCrmAccessRequest $request, string $masjid_id)
    {
        if (Auth::user()?->type !== 'SuperAdmin') {
            abort(Response::HTTP_FORBIDDEN, 'Only a super admin can change CRM access.');
        }

        $masjid = Masjid::findOrFail($masjid_id);
        $before = (bool) $masjid->crm_enabled;

        // The save and its ledger row commit together (CapabilityLedger).
        DB::transaction(function () use ($masjid, $request, $before) {
            $masjid->crm_enabled = $request->boolean('enabled');
            $masjid->updated_by = Auth::id();
            $masjid->save();

            CapabilityLedger::record($masjid, 'crm', $before, (bool) $masjid->crm_enabled, null, Auth::id());
        });

        return response()->json([
            'status' => 'success',
            'data' => $masjid,
        ], Response::HTTP_OK);
    }

    /**
     * SuperAdmin-only: publish/unpublish this organisation in the mobile app's
     * public directory (masjids.listed_at).
     *
     * This is the only way the gate added by add_listed_at_to_masjids_table gets
     * opened. Provisioning deliberately leaves a new organisation unlisted, so
     * without this endpoint a newly created school would be invisible forever.
     *
     * Super-ness is enforced here with abort(403) rather than the `super`
     * middleware, matching setCrmAccess above: a MasjidAdmin must never be able
     * to publish their own tenant, and the answer for them is a clean forbidden
     * rather than the middleware's 401.
     *
     * Listing an already-listed organisation keeps its original timestamp — the
     * column answers "live since when", and a no-op toggle must not rewrite it.
     */
    public function setDirectoryListing(SetDirectoryListingRequest $request, string $masjid_id)
    {
        if (Auth::user()?->type !== 'SuperAdmin') {
            abort(Response::HTTP_FORBIDDEN, 'Only a super admin can change directory listing.');
        }

        $masjid = Masjid::findOrFail($masjid_id);
        $before = $masjid->isListed();

        DB::transaction(function () use ($masjid, $request, $before) {
            if ($request->boolean('listed')) {
                $masjid->listed_at = $masjid->listed_at ?? now();
            } else {
                $masjid->listed_at = null;
            }

            $masjid->updated_by = Auth::id();
            $masjid->save();

            // Not a catalogue key, but a SuperAdmin switch on the same screen, so
            // it lands in the same ledger.
            CapabilityLedger::record($masjid, CapabilityLedger::DIRECTORY_LISTING, $before, $masjid->isListed(), null, Auth::id());
        });

        // The directory is cached for a day; without this flush the decision
        // does not reach the apps until the entry expires.
        MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

        return response()->json([
            'status' => 'success',
            'data' => $masjid,
        ], Response::HTTP_OK);
    }

    /**
     * SuperAdmin-only toggle for the Masjid Assistant (per-masjid feature gate).
     * Deliberately NOT behind the `assistant` gate — it is how the gate is opened.
     */
    public function setAssistantAccess(SetAssistantAccessRequest $request, string $masjid_id)
    {
        if (Auth::user()?->type !== 'SuperAdmin') {
            abort(Response::HTTP_FORBIDDEN, 'Only a super admin can change Masjid Assistant access.');
        }

        $masjid = Masjid::findOrFail($masjid_id);
        $before = (bool) $masjid->assistant_enabled;

        DB::transaction(function () use ($masjid, $request, $before) {
            $masjid->assistant_enabled = $request->boolean('enabled');
            $masjid->updated_by = Auth::id();
            $masjid->save();

            CapabilityLedger::record($masjid, 'assistant', $before, (bool) $masjid->assistant_enabled, null, Auth::id());
        });

        return response()->json([
            'status' => 'success',
            'data' => $masjid,
        ], Response::HTTP_OK);
    }

    /**
     * SuperAdmin-only: switch one catalogue capability on or off for an
     * organisation (config/capabilities.php -> masjids.capability_overrides).
     *
     * Column-backed capabilities (crm, assistant) are refused here and keep
     * their own endpoints, so every capability has exactly one writer. The
     * decision is stored explicitly even when it equals the default, so a later
     * change to a catalogue default never silently moves an organisation a
     * SuperAdmin already decided about.
     *
     * Grants and modules go through here alike. Every flip, a no-op included,
     * writes a masjid_capability_changes row in the same transaction.
     */
    public function setCapability(SetCapabilityRequest $request, string $masjid_id, string $capability)
    {
        if (Auth::user()?->type !== 'SuperAdmin') {
            abort(Response::HTTP_FORBIDDEN, 'Only a super admin can change what an organisation has.');
        }

        $definition = config("capabilities.{$capability}");

        if (! is_array($definition) || ! empty($definition['column'])) {
            return response()->json([
                'status' => 'failed',
                'data' => ['capability' => [
                    is_array($definition)
                        ? 'This capability has its own switch on this screen.'
                        : 'There is no such capability.',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $masjid = Masjid::findOrFail($masjid_id);
        $overrides = is_array($masjid->capability_overrides) ? $masjid->capability_overrides : [];
        $before = $masjid->hasCapability($capability);
        $overrideBefore = array_key_exists($capability, $overrides) ? (bool) $overrides[$capability] : null;

        DB::transaction(function () use ($masjid, $request, $capability, $overrides, $before, $overrideBefore) {
            $overrides[$capability] = $request->boolean('enabled');

            $masjid->capability_overrides = $overrides;
            $masjid->updated_by = Auth::id();
            $masjid->save();

            CapabilityLedger::record($masjid, $capability, $before, $masjid->hasCapability($capability), $overrideBefore, Auth::id());
        });

        return response()->json([
            'status' => 'success',
            'data' => $masjid->fresh()->append(Masjid::ADMIN_APPENDS),
        ], Response::HTTP_OK);
    }

    /**
     * SuperAdmin-only: everything the switch panel shows for one organisation.
     *
     * Every catalogue entry, grouped (config/capability_groups.php order), with
     * whether the organisation has it, its org_type default, whether a
     * SuperAdmin overrode it, which endpoint writes it, and — for the entries a
     * page section depends on — how many active sections on active pages show
     * it, so a switch-off is decided with the facts. Plus this organisation's
     * last 25 flips, newest first.
     *
     * `enabled` for a module is `! moduleIsOff()`, the same answer every gate
     * gives, so the panel can never show "on" for a screen the server refuses.
     */
    public function capabilities(string $masjid_id)
    {
        if (Auth::user()?->type !== 'SuperAdmin') {
            abort(Response::HTTP_FORBIDDEN, 'Only a super admin can see what an organisation has.');
        }

        $masjid = Masjid::findOrFail($masjid_id);
        $overrides = is_array($masjid->capability_overrides) ? $masjid->capability_overrides : [];
        $inUse = $this->sectionsInUse($masjid);

        $groupLabels = config('capability_groups', []);
        $entries = [];

        foreach (config('capabilities', []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $column = $definition['column'] ?? null;
            $isModule = ($definition['kind'] ?? null) === 'module';

            $entries[$definition['group'] ?? 'tools'][] = [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'description' => $definition['description'] ?? '',
                'kind' => $isModule ? 'module' : 'grant',
                // crm and assistant keep their own endpoints; everything else is
                // PATCH .../capabilities/{key}.
                'writer' => $column ? $key : 'capability',
                'enabled' => $isModule ? ! $masjid->moduleIsOff($key) : $masjid->hasCapability($key),
                'default_for_org_type' => $column ? null : (bool) ($definition['defaults'][$masjid->orgType()] ?? false),
                'overridden' => ! $column && array_key_exists($key, $overrides),
                'in_use' => $inUse[$key] ?? null,
            ];
        }

        $groups = [];

        // Configured groups first, in their order; a group the config does not
        // name (a stale config cache) still shows, labelled by its key.
        foreach (array_unique(array_merge(array_keys($groupLabels), array_keys($entries))) as $groupKey) {
            if (empty($entries[$groupKey])) {
                continue;
            }

            $groups[] = [
                'key' => $groupKey,
                'label' => $groupLabels[$groupKey] ?? $groupKey,
                'entries' => $entries[$groupKey],
            ];
        }

        $changes = MasjidCapabilityChange::where('masjid_id', $masjid->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        // withTrashed: a soft-deleted operator still has a name worth showing on
        // an audit row. Null only when the user row is gone entirely.
        $actors = User::withTrashed()
            ->whereIn('id', $changes->pluck('actor_user_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        $history = $changes->map(fn (MasjidCapabilityChange $change) => [
            'id' => (int) $change->id,
            'capability' => $change->capability,
            'label' => $change->capability === CapabilityLedger::DIRECTORY_LISTING
                ? 'Directory listing'
                : config("capabilities.{$change->capability}.label", $change->capability),
            'enabled_before' => $change->enabled_before,
            'enabled_after' => $change->enabled_after,
            'override_before' => $change->override_before,
            'actor_name' => $change->actor_user_id !== null ? ($actors[$change->actor_user_id] ?? null) : null,
            'created_at' => $change->created_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'org' => [
                    'id' => (int) $masjid->id,
                    'name' => $masjid->name,
                    'org_type' => $masjid->orgType(),
                ],
                'groups' => $groups,
                'history' => $history,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Module key => how many active sections on this organisation's active pages
     * show it (SectionType::requiresModule). Only keys some section type depends
     * on appear; the rest read as null on the panel.
     *
     * Hand-filtered by masjid_id on BOTH pages and sections: neither model
     * carries the tenant scope, and a SuperAdmin request binds no tenant.
     *
     * @return array<string,int>
     */
    private function sectionsInUse(Masjid $masjid): array
    {
        $counts = DB::table('page_section')
            ->join('pages', 'pages.id', '=', 'page_section.page_id')
            ->join('sections', 'sections.id', '=', 'page_section.section_id')
            ->where('pages.masjid_id', $masjid->id)
            ->where('sections.masjid_id', $masjid->id)
            ->where('pages.is_active', true)
            ->whereNull('pages.deleted_at')
            ->where('sections.is_active', true)
            ->groupBy('sections.section_type')
            ->selectRaw('sections.section_type as section_type, COUNT(DISTINCT sections.id) as total')
            ->pluck('total', 'section_type');

        $out = [];

        foreach (SectionType::cases() as $type) {
            $module = $type->requiresModule();

            if ($module === null) {
                continue;
            }

            $out[$module] = ($out[$module] ?? 0) + (int) ($counts[$type->value] ?? 0);
        }

        return $out;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMasjidRequest $request, string $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $payload = $request->safe()->only([
                'name', 'email', 'phone', 'longitude', 'latitude',
                'address', 'user_id', 'country_id', 'city_id',
            ]);
            $payload['user_id'] = $payload['user_id'] ?? null;
            $payload['updated_by'] = Auth::id();

            $masjid->update($payload);

            if ($request->hasFile('logo')) {
                $masjid->clearMediaCollection('logos');
                $masjid->addMediaFromRequest('logo')->toMediaCollection('logos');
            }

            if ($request->hasFile('footer_logo')) {
                $masjid->clearMediaCollection('footer_logos');
                $masjid->addMediaFromRequest('footer_logo')->toMediaCollection('footer_logos');
            }

            MobileCache::flushMasjidAll((int) $masjid_id);
            MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

            return response()->json([
                'status' => 'success',
                'data' => $masjid
            ], Response::HTTP_OK);
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
    /**
     * SCRUM-10: archive instead of hard-delete.
     *
     * forceDelete() permanently removed the row, orphaning every record and API
     * consumer keyed on this masjid id (prayer settings, announcements, events,
     * mobile devices…) and breaking the app. Soft-deleting preserves the row +
     * id, drops the masjid out of normal queries (SoftDeletes global scope), and
     * is fully reversible via restore(). Permanent deletion is no longer exposed.
     */
    public function destroy($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $masjid->deleted_by = Auth::id();
        $masjid->save();
        $masjid->delete();

        MobileCache::flushMasjidAll((int) $masjid_id);
        MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    public function moveToTrash($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $masjid->deleted_by = Auth::id();
        $masjid->save();
        $masjid->delete();

        MobileCache::flushMasjidAll((int) $masjid_id);
        MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    /**
     * List archived (soft-deleted) masjids so the panel can offer a restore UI.
     */
    public function trashed()
    {
        $masjids = Masjid::onlyTrashed()
            ->with('logo', 'footer_logo', 'admin.avatar', 'country', 'city')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $masjids
        ], Response::HTTP_OK);
    }

    /**
     * Restore an archived masjid, keeping the same id so all API dependencies
     * remain valid (SCRUM-10).
     */
    public function restore($masjid_id)
    {
        $masjid = Masjid::onlyTrashed()->findOrFail($masjid_id);
        $masjid->restore();
        $masjid->deleted_by = null;
        $masjid->save();

        MobileCache::flushMasjidAll((int) $masjid_id);
        MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }
}
