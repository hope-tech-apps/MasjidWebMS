<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Provisioning\ProvisionAppsRequest;
use App\Models\Masjid;
use App\Models\ProvisioningJob;
use App\Services\GithubDispatchService;
use App\Support\Errors;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal control plane for the self-hosted app-provisioning pipeline
 * (Super-Admin only — see routes/admin.php, `super` middleware).
 *
 *   - provision()  POST — for each chosen platform, create a provisioning_jobs
 *                  row (fresh job_id + callback_token) and fire a GitHub
 *                  repository_dispatch carrying the contract client_payload the
 *                  mobile workflows consume. Marks the row `dispatched` on
 *                  success, `failed` (+ detail) if the dispatch call fails.
 *   - index()      GET  — the latest jobs + statuses for the status panel to poll.
 *
 * Tenant safety: the masjid is ALWAYS resolved from the route {masjid_id}
 * (server-derived) — never from the request body. Secrets (the per-job
 * callback_token, the GitHub dispatch token) are never returned by any response:
 * callback_token is `$hidden` on the model, and the dispatch token lives only in
 * config/env.
 */
class AppProvisioningController extends Controller
{
    public function __construct(private GithubDispatchService $dispatcher)
    {
    }

    /**
     * Dispatch scaffold+build+upload workflows for the chosen platforms.
     */
    public function provision(ProvisionAppsRequest $request, $masjid_id)
    {
        try {
            // Server-derived tenant: the route id, not the body.
            $masjid = Masjid::with('appPublishing')->findOrFail($masjid_id);

            // De-dupe (a client could send ['ios','ios']); preserve order.
            $platforms = array_values(array_unique($request->input('platforms', [])));

            $jobs = [];

            foreach ($platforms as $platform) {
                $repo = $this->repoFor($platform);

                // One job row per platform per request. job_id + callback_token
                // are auto-assigned in the model's creating hook.
                $job = ProvisioningJob::create([
                    'masjid_id' => $masjid->id,
                    'platform' => $platform,
                    'github_repo' => (string) $repo,
                    'status' => ProvisioningJob::STATUS_QUEUED,
                ]);

                $payload = $this->buildPayload($masjid, $platform, $job, $request);

                $result = $this->dispatcher->dispatch((string) $repo, $payload);

                if ($result['ok'] ?? false) {
                    $job->update(['status' => ProvisioningJob::STATUS_DISPATCHED]);
                } else {
                    $job->update([
                        'status' => ProvisioningJob::STATUS_FAILED,
                        'detail' => $result['error'] ?? 'Dispatch failed.',
                    ]);
                }

                $jobs[] = [
                    'job_id' => $job->job_id,
                    'platform' => $job->platform,
                    'status' => $job->status,
                    'github_repo' => $job->github_repo,
                    'detail' => $job->detail,
                ];
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'masjid_id' => (int) $masjid->id,
                    'jobs' => $jobs,
                ],
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The latest provisioning jobs for this masjid (status panel poll target).
     * callback_token is `$hidden`, so returning the models never leaks it.
     */
    public function index($masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $jobs = ProvisioningJob::where('masjid_id', $masjid->id)
                ->latest()
                ->limit(20)
                ->get(['id', 'job_id', 'platform', 'status', 'detail', 'artifact_url', 'github_repo', 'created_at', 'updated_at']);

            return response()->json([
                'status' => 'success',
                'data' => $jobs,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Resolve the configured repo (full owner/repo) for a platform. */
    private function repoFor(string $platform): ?string
    {
        return $platform === ProvisioningJob::PLATFORM_IOS
            ? config('services.github.ios_repo')
            : config('services.github.android_repo');
    }

    /**
     * Build the contract client_payload for one job. Common fields are always
     * present; platform-specific fields are added per platform. Every override is
     * optional (request first, then the masjid's app-publishing, then a sensible
     * default derived from the masjid).
     */
    private function buildPayload(Masjid $masjid, string $platform, ProvisioningJob $job, ProvisionAppsRequest $request): array
    {
        $publishing = $masjid->appPublishing;

        // Machine-friendly slugs derived from the masjid name (deterministic
        // fallbacks keep identifiers valid even for odd names).
        $slug = Str::slug($masjid->name) ?: ('masjid-' . $masjid->id);
        $bundleSlug = Str::of($masjid->name)->slug('')->toString() ?: ('masjid' . $masjid->id);

        $displayName = $request->input('display_name') ?: $masjid->name;

        // $publishing may be null (no app-publishing row yet) — use null-safe
        // access throughout so we degrade to sensible defaults.
        $accountMode = $platform === ProvisioningJob::PLATFORM_IOS
            ? ($publishing?->ios_account_mode ?? 'managed')
            : ($publishing?->android_account_mode ?? 'managed');

        // development_team: explicit override -> BYO stored team -> platform default.
        $developmentTeam = $request->input('development_team')
            ?: $publishing?->development_team
            ?: config('services.github.development_team');

        $common = [
            'job_id' => $job->job_id,
            'masjid_id' => (int) $masjid->id,
            'name' => $request->input('name') ?: $masjid->name,
            'display_name' => $displayName,
            'account_mode' => $accountMode,
            'development_team' => $developmentTeam,
            // Absolute URL the runner POSTs status updates to for THIS job.
            'callback_url' => route('provisioning.callback'),
            // Per-job bearer the runner echoes to authenticate its callback.
            'callback_token' => $job->callback_token,
        ];

        if ($platform === ProvisioningJob::PLATFORM_IOS) {
            $bundlePrefix = config('services.github.ios_bundle_prefix', 'com.hopetechapps');

            return array_merge($common, [
                'bundle_id' => $request->input('bundle_id') ?: ($bundlePrefix . '.' . $bundleSlug),
                'include_tvos' => $request->boolean('include_tvos'),
            ]);
        }

        // Android.
        $flavor = $request->input('flavor') ?: (Str::camel($slug) ?: ('masjid' . $masjid->id));

        return array_merge($common, [
            'flavor' => $flavor,
            'application_id_suffix' => $request->input('application_id_suffix') ?: ('.' . $bundleSlug),
            'app_name' => $request->input('app_name') ?: $displayName,
            // Prefer an explicit override, else the masjid's provisioned OneSignal
            // app id (may be null — the workflow wires push later if so).
            'onesignal_app_id' => $request->input('onesignal_app_id') ?: $publishing?->onesignal_app_id,
        ]);
    }
}
