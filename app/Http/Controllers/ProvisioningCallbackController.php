<?php

namespace App\Http\Controllers;

use App\Models\ProvisioningJob;
use App\Support\Errors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provisioning callback — the self-hosted runner reports job progress here.
 *
 * This route is registered OUTSIDE auth:sanctum/super (the runner is not a
 * logged-in user), exactly like the Stripe/Pusher webhooks. It is authenticated
 * instead by a per-job bearer: the runner sends
 *
 *   POST /api/provisioning/callback
 *   Authorization: Bearer <callback_token>
 *   { job_id, platform, status, detail?, artifact_url? }
 *
 * We look up the job by `job_id` and CONSTANT-TIME compare the bearer against
 * that job's stored `callback_token`. Any failure — missing/blank token, unknown
 * job, or token mismatch — returns an identical 404 with no body detail, so the
 * endpoint never reveals whether a given job_id exists or why auth failed (no
 * info leak / no enumeration oracle).
 *
 * On success it advances the job's status/detail/artifact_url. Status must be one
 * of the contract-reportable states (ProvisioningJob::CALLBACK_STATUSES).
 */
class ProvisioningCallbackController extends Controller
{
    public function handle(Request $request)
    {
        try {
            $jobId = $request->input('job_id');
            $presented = $this->bearerToken($request);

            // Both required to authenticate; treat absence as an auth failure
            // (same opaque 404 as any other failure).
            if (! is_string($jobId) || $jobId === '' || ! is_string($presented) || $presented === '') {
                return $this->reject();
            }

            $job = ProvisioningJob::where('job_id', $jobId)->first();

            // Constant-time compare regardless of whether the job was found, so a
            // request for an unknown job_id takes the same path as a bad token.
            $expected = $job?->callback_token ?? str_repeat('0', 40);
            $tokenOk = hash_equals($expected, $presented);

            if (! $job || ! $tokenOk) {
                return $this->reject();
            }

            // Authenticated. Validate the reported state.
            $validator = Validator::make($request->all(), [
                'status' => ['required', 'string', \Illuminate\Validation\Rule::in(ProvisioningJob::CALLBACK_STATUSES)],
                'platform' => ['sometimes', 'string', \Illuminate\Validation\Rule::in(['ios', 'android'])],
                'detail' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'artifact_url' => ['sometimes', 'nullable', 'url', 'max:2000'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $update = ['status' => $request->input('status')];

            // Only overwrite detail/artifact_url when the caller actually sent
            // them, so a terse status ping doesn't wipe an earlier detail.
            if ($request->has('detail')) {
                $update['detail'] = $request->input('detail');
            }
            if ($request->filled('artifact_url')) {
                $update['artifact_url'] = $request->input('artifact_url');
            }

            $job->update($update);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'job_id' => $job->job_id,
                    'status' => $job->status,
                ],
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extract the presented bearer token. Uses the framework's parser (handles
     * "Authorization: Bearer <token>") with a defensive manual fallback.
     */
    private function bearerToken(Request $request): ?string
    {
        $token = $request->bearerToken();
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $header = (string) $request->header('Authorization', '');
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return null;
    }

    /**
     * Opaque rejection — identical for unknown job or bad token, so nothing about
     * job existence or auth state leaks.
     */
    private function reject()
    {
        return response()->json([
            'status' => 'error',
            'data' => 'Not found.',
        ], Response::HTTP_NOT_FOUND);
    }
}
