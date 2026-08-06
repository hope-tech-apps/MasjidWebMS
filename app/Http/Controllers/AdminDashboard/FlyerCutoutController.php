<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFlyerCutout;
use App\Models\Flyer;
use App\Support\Errors;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The photo pipeline for one flyer: upload the subject picture, have its background
 * removed off the request path, poll that job, and serve the results back.
 *
 * Background removal costs ~3.2s of the production droplet's ONLY vCPU at ~354MB RSS,
 * so it is never run inline — this controller only ever queues App\Jobs\
 * ProcessFlyerCutout and reports what the worker wrote onto the flyer row.
 *
 * Tenancy is the usual guardrail: every lookup is a scoped Flyer::findOrFail, so
 * another masjid's flyer id is a 404 and nobody can upload into, or read, a photo that
 * is not theirs.
 */
class FlyerCutoutController extends Controller
{
    /**
     * Photos live on the PRIVATE `local` disk (storage/app/private), never the
     * web-exposed `public` one. A flyer in progress — a janazah photo above all — must
     * not be reachable by guessing a /storage URL, so the files are served back only
     * through image() below, under the admin session.
     *
     * The disk is handed to the job EXPLICITLY rather than left to
     * config('flyer.cutout.disk'), which still defaults to `public`: the worker has to
     * read the source and write the cutout on the same disk this controller wrote to.
     */
    private const IMAGE_DISK = 'local';

    private const SOURCE_DIRECTORY = 'flyers/sources';

    /** What image() will serve. `composite` follows Flyer::compositeImagePath(). */
    private const VARIANTS = ['source', 'cutout', 'composite'];

    /**
     * POST /api/admin/masjids/{masjid_id}/flyers/{flyer_id}/photo   (multipart)
     *
     * Replaces whatever photo the flyer had. Pass remove_background=0 to keep the
     * picture exactly as uploaded — a photographed dish on a plain white cloth often
     * looks better untouched than cut out.
     */
    public function store(Request $request, $masjid_id, $flyer_id)
    {
        $flyer = Flyer::findOrFail($flyer_id);

        $this->validateUpload($request);

        try {
            $file = $request->file('image');

            // The extension is GUESSED from the file's content type, never taken from
            // the client's filename — the uploaded name reaches a shell-free argv in
            // ImageCutout, but it has no business deciding what we write to disk.
            $name = $flyer->id . '-' . Str::random(20) . '.' . ($file->extension() ?: 'jpg');

            $disk = Storage::disk(self::IMAGE_DISK);
            $path = $disk->putFileAs(self::SOURCE_DIRECTORY, $file, $name);

            if ($path === false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The photo could not be saved.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $previous = [$flyer->source_image_path, $flyer->cutout_path];

            $wantsCutout = $request->boolean('remove_background', true)
                && (bool) config('flyer.cutout.enabled', false);

            $flyer->update([
                'source_image_path' => $path,
                // The old cutout belongs to the old photo. Clearing it here is what
                // stops the renderer compositing last week's picture onto this flyer.
                'cutout_path' => null,
                // 'none' is the resting state for a photo used as-is, which is exactly
                // what an un-provisioned host or an opted-out admin has — not a failure.
                'cutout_status' => $wantsCutout ? ProcessFlyerCutout::STATUS_QUEUED : ProcessFlyerCutout::STATUS_NONE,
                'cutout_error' => null,
            ]);

            if ($wantsCutout) {
                // Only the id travels with the job: it re-reads source_image_path when a
                // worker picks it up, so a photo swapped in between cannot produce a
                // cutout of the wrong image.
                ProcessFlyerCutout::dispatch($flyer->id, self::IMAGE_DISK);
            }

            foreach ($previous as $old) {
                if ($old && $old !== $path) {
                    $disk->delete($old);
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->cutoutState($flyer->fresh()),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/flyers/{flyer_id}/cutout
     *
     * What the Studio polls while `pending` is true.
     */
    public function show($masjid_id, $flyer_id)
    {
        $flyer = Flyer::findOrFail($flyer_id);

        return response()->json([
            'status' => 'success',
            'data' => $this->cutoutState($flyer),
        ], Response::HTTP_OK);
    }

    /**
     * POST /api/admin/masjids/{masjid_id}/flyers/{flyer_id}/cutout/retry
     *
     * For the environmental failure — a run that timed out while the box was swapping.
     * A photo the model genuinely cannot find a subject in will fail again the same
     * way, so this is a button an admin presses, never something we retry on their
     * behalf beyond the job's own single retry.
     */
    public function retry($masjid_id, $flyer_id)
    {
        $flyer = Flyer::findOrFail($flyer_id);

        if (! $flyer->source_image_path) {
            return response()->json([
                'status' => 'error',
                'message' => 'Upload a photo before removing its background.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! config('flyer.cutout.enabled', false)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Background removal is not enabled on this server.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $flyer->update([
            'cutout_status' => ProcessFlyerCutout::STATUS_QUEUED,
            'cutout_error' => null,
        ]);

        ProcessFlyerCutout::dispatch($flyer->id, self::IMAGE_DISK);

        return response()->json([
            'status' => 'success',
            'data' => $this->cutoutState($flyer->fresh()),
        ], Response::HTTP_OK);
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/flyers/{flyer_id}/image/{variant}
     *
     * Streams a photo off the private disk. This endpoint IS the reason the files are
     * not in the public root: it runs behind auth:sanctum + admin + tenant, so the only
     * way to read a masjid's flyer photo is to be an admin of that masjid.
     */
    public function image($masjid_id, $flyer_id, $variant = 'composite')
    {
        $flyer = Flyer::findOrFail($flyer_id);

        if (! in_array($variant, self::VARIANTS, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unknown image.',
            ], Response::HTTP_NOT_FOUND);
        }

        $path = match ($variant) {
            'source' => $flyer->source_image_path,
            'cutout' => $flyer->cutout_status === ProcessFlyerCutout::STATUS_DONE ? $flyer->cutout_path : null,
            default => $flyer->compositeImagePath(),
        };

        $disk = Storage::disk(self::IMAGE_DISK);

        if (! $path || ! $disk->exists($path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This flyer has no image to show yet.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $disk->response(
            $path,
            "flyer-{$flyer->id}-{$variant}." . pathinfo($path, PATHINFO_EXTENSION),
            // Private: an intermediate cache must never hold one masjid's photo and
            // hand it to the next admin who asks for the same URL.
            ['Cache-Control' => 'private, max-age=60'],
        );
    }

    /**
     * DELETE /api/admin/masjids/{masjid_id}/flyers/{flyer_id}/photo
     *
     * Removes the picture and its cutout, returning the flyer to the state it had
     * before any photo was uploaded.
     */
    public function destroy($masjid_id, $flyer_id)
    {
        $flyer = Flyer::findOrFail($flyer_id);

        try {
            $disk = Storage::disk(self::IMAGE_DISK);

            foreach ([$flyer->source_image_path, $flyer->cutout_path] as $path) {
                if ($path) {
                    $disk->delete($path);
                }
            }

            $flyer->update([
                'source_image_path' => null,
                'cutout_path' => null,
                'cutout_status' => ProcessFlyerCutout::STATUS_NONE,
                'cutout_error' => null,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $this->cutoutState($flyer->fresh()),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Upload rules, applied inline because this endpoint takes one file and no other
     * payload. The failure envelope is thrown the way DonationsController does it —
     * ValidationException is not an HttpException, so it would fall through the app's
     * JSON renderer as a 500 rather than a 422.
     */
    private function validateUpload(Request $request): void
    {
        $validator = Validator::make($request->all(), [
            // `image` rejects SVG, which matters: an SVG is a script-bearing document,
            // and Pillow cannot read one anyway.
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                // 12MB. Comfortably above a phone photo and well below what would put
                // a 2GB droplet into swap while PHP holds the upload in memory.
                'max:12288',
                // The cutout downscales to max_edge before inference, but decoding a
                // 30,000px-wide image to get there is its own way to run out of RAM.
                'dimensions:max_width=10000,max_height=10000',
            ],
            'remove_background' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'status' => 'failed',
                'data' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }
    }

    /**
     * Everything the Studio needs to decide between a spinner, the picture, and an
     * actionable error.
     *
     * @return array<string,mixed>
     */
    private function cutoutState(Flyer $flyer): array
    {
        $base = url("/api/admin/masjids/{$flyer->masjid_id}/flyers/{$flyer->id}/image");

        return [
            'flyer_id' => $flyer->id,
            'cutout_status' => $flyer->cutout_status,
            'cutout_error' => $flyer->cutout_error,
            // Poll while this is true; stop when it goes false, whatever the outcome.
            'pending' => $flyer->isCutoutPending(),
            // Whether this host can remove a background at all. False means the Studio
            // should not offer the option, not that something went wrong.
            'available' => (bool) config('flyer.cutout.enabled', false),
            'images' => [
                'source' => $flyer->source_image_path ? "{$base}/source" : null,
                'cutout' => ($flyer->cutout_status === ProcessFlyerCutout::STATUS_DONE && $flyer->cutout_path)
                    ? "{$base}/cutout"
                    : null,
                'composite' => $flyer->compositeImagePath() ? "{$base}/composite" : null,
            ],
        ];
    }
}
