<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\ListStudioDraftsRequest;
use App\Http\Requests\Admin\Studio\StoreStudioDraftLogoRequest;
use App\Http\Requests\Admin\Studio\StoreStudioDraftRequest;
use App\Http\Requests\Admin\Studio\UpdateStudioDraftRequest;
use App\Http\Resources\Admin\StudioDraftResource;
use App\Models\StudioDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manara Studio's drafts: create, list, autosave, discard, and the draft's logo
 * (docs/manara-studio-w1.md S2). Nothing here provisions an organisation.
 *
 * SuperAdmin-only through the `super` middleware on the studio route group. A
 * draft has no tenant, so there is no scope to rely on and none to bypass: every
 * lookup is StudioDraft::findOrFail, kept out of any try/catch so a missing
 * draft is the app's clean 404 rather than a 500.
 *
 * A provisioned draft is frozen. It is the record of what Step 3 created, so
 * autosave, discard and the logo writes all answer 409 for it.
 */
class StudioDraftsController extends Controller
{
    private const LIST_LIMIT = 100;

    /** Sniffed type => stored extension. The client's filename never decides it. */
    private const LOGO_EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    /** GET /api/admin/studio/drafts?status=draft|provisioned|all */
    public function index(ListStudioDraftsRequest $request)
    {
        $status = $request->validated('status');

        $drafts = StudioDraft::query()
            ->when($status !== ListStudioDraftsRequest::ALL, fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (StudioDraft $draft) => [
                'id' => $draft->id,
                'status' => $draft->status,
                'name' => $draft->name,
                'org_type' => $draft->org_type,
                'current_step' => $draft->current_step,
                'has_logo' => $draft->hasLogo(),
                'provisioned_masjid_id' => $draft->provisioned_masjid_id,
                'updated_at' => $draft->updated_at?->toIso8601String(),
            ]);

        return response()->json(['status' => 'success', 'data' => $drafts], Response::HTTP_OK);
    }

    /**
     * POST /api/admin/studio/drafts
     *
     * A new draft has no colours (R25): Step 0 requires a choice, because a
     * default that is a live client's palette gets shipped by accident.
     */
    public function store(StoreStudioDraftRequest $request)
    {
        $identity = array_filter([
            'org_type' => $request->validated('org_type'),
            'name' => $request->validated('name'),
        ], fn ($value) => $value !== null);

        $draft = StudioDraft::create([
            'status' => StudioDraft::STATUS_DRAFT,
            'answers' => $identity === [] ? null : ['identity' => $identity],
            'name' => $identity['name'] ?? null,
            'org_type' => $identity['org_type'] ?? null,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return $this->draft($draft->fresh(), Response::HTTP_CREATED);
    }

    /** GET /api/admin/studio/drafts/{draft_id} */
    public function show(int $draft_id)
    {
        return $this->draft(StudioDraft::findOrFail($draft_id));
    }

    /**
     * PATCH /api/admin/studio/drafts/{draft_id}
     *
     * Each section sent replaces that section wholesale; sections not sent are
     * left alone, and a section sent as null is cleared. The caller names the
     * lock_version it read, and a stale one is refused with the draft as it now
     * stands, so the SPA can show "Reload draft" instead of overwriting another
     * tab's work.
     */
    public function update(UpdateStudioDraftRequest $request, int $draft_id)
    {
        StudioDraft::findOrFail($draft_id);

        return DB::transaction(function () use ($request, $draft_id) {
            // Locked so two saves naming the same lock_version cannot both win.
            $draft = StudioDraft::query()->lockForUpdate()->findOrFail($draft_id);

            if ($draft->isProvisioned()) {
                return $this->conflict($draft, 'This draft has already been provisioned and can no longer change.');
            }

            if ((int) $request->validated('lock_version') !== $draft->lock_version) {
                return $this->conflict($draft, 'This draft was saved somewhere else since you loaded it. Reload it to carry on.');
            }

            $answers = $draft->answers;
            $sections = $request->validated('answers') ?? [];

            foreach ($sections as $section => $value) {
                if ($value === null) {
                    unset($answers[$section]);
                } else {
                    $answers[$section] = $value;
                }
            }

            $draft->answers = $answers;

            if (array_key_exists('identity', $sections)) {
                $identity = is_array($sections['identity']) ? $sections['identity'] : [];
                $name = is_string($identity['name'] ?? null) && $identity['name'] !== '' ? $identity['name'] : null;

                $draft->name = $name === null ? null : mb_substr($name, 0, 255);
                $draft->org_type = $identity['org_type'] ?? null;
            }

            if ($request->has('current_step')) {
                $draft->current_step = $request->validated('current_step');
            }

            $draft->lock_version = $draft->lock_version + 1;
            $draft->updated_by = $request->user()?->id;
            $draft->save();

            return $this->draft($draft->fresh());
        });
    }

    /**
     * DELETE /api/admin/studio/drafts/{draft_id}
     *
     * A hard delete through the model, so its `deleting` hook takes the logo
     * bytes with the row.
     */
    public function destroy(int $draft_id)
    {
        $draft = StudioDraft::findOrFail($draft_id);

        if ($draft->isProvisioned()) {
            return $this->conflict($draft, 'A provisioned draft is the record of what Studio created, and is kept.');
        }

        $draft->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Draft discarded.',
            'data' => ['id' => $draft_id],
        ], Response::HTTP_OK);
    }

    /**
     * POST /api/admin/studio/drafts/{draft_id}/logo   (multipart `logo`)
     *
     * Replaces any logo the draft had. The new file is written first and the old
     * one deleted only once the row points at the new one, so a failure part way
     * leaves the draft with a logo, never with none.
     *
     * The logo is not part of `answers`, so it does not move lock_version: an
     * autosave cannot overwrite it, and uploading one must not make the next
     * autosave look stale.
     */
    public function storeLogo(StoreStudioDraftLogoRequest $request, int $draft_id)
    {
        $draft = StudioDraft::findOrFail($draft_id);

        if ($draft->isProvisioned()) {
            return $this->conflict($draft, 'This draft has already been provisioned; change the logo on the organisation instead.');
        }

        $file = $request->file('logo');
        $mime = $file->getMimeType();
        $diskName = (string) config('studio.logo.disk', 'local');
        $disk = Storage::disk($diskName);

        $path = $disk->putFileAs(
            config('studio.logo.directory', 'studio-drafts') . '/' . $draft->id,
            $file,
            // A type added to config('studio.logo.mime_types') later still gets
            // an extension guessed from the sniffed type, never the client's.
            Str::random(40) . '.' . (self::LOGO_EXTENSIONS[$mime] ?? $file->guessExtension()),
        );

        if ($path === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'The logo could not be saved.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        [$width, $height] = getimagesize($file->getRealPath()) ?: [null, null];
        $previous = $draft->hasLogo() ? [$draft->logo_disk, $draft->logo_path] : null;

        try {
            $draft->update([
                'logo_disk' => $diskName,
                'logo_path' => $path,
                // The uploader's name is data, shown back to them; it never
                // touches the filesystem, and an overlong one is cut, not refused.
                'logo_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'logo_mime_type' => $mime,
                'logo_size_bytes' => $file->getSize(),
                'logo_width' => $width,
                'logo_height' => $height,
                'logo_sha256' => hash_file('sha256', $file->getRealPath()),
                'updated_by' => $request->user()?->id,
            ]);
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($previous !== null && $previous !== [$diskName, $path]) {
            Storage::disk($previous[0])->delete($previous[1]);
        }

        return $this->draft($draft->fresh());
    }

    /**
     * GET /api/admin/studio/drafts/{draft_id}/logo
     *
     * Served inline with the type sniffed at upload, and never cached by anyone
     * in between: a client's unannounced brand is not a public asset yet.
     */
    public function showLogo(int $draft_id)
    {
        $draft = StudioDraft::findOrFail($draft_id);

        if (! $draft->logoExists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This draft has no logo.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $draft->logoStorage()->response(
            $draft->logo_path,
            // Our name, not the uploader's: theirs is shown in the resource, and
            // a header built from it could carry characters Symfony refuses.
            "draft-{$draft->id}-logo." . pathinfo($draft->logo_path, PATHINFO_EXTENSION),
            [
                'Content-Type' => $draft->logo_mime_type,
                'Cache-Control' => 'private, no-store',
            ],
            'inline',
        );
    }

    /** DELETE /api/admin/studio/drafts/{draft_id}/logo */
    public function destroyLogo(int $draft_id)
    {
        $draft = StudioDraft::findOrFail($draft_id);

        if ($draft->isProvisioned()) {
            return $this->conflict($draft, 'This draft has already been provisioned; change the logo on the organisation instead.');
        }

        $draft->deleteLogoBytes();
        $draft->update(StudioDraft::withoutLogo() + ['updated_by' => request()->user()?->id]);

        return $this->draft($draft->fresh());
    }

    private function draft(StudioDraft $draft, int $status = Response::HTTP_OK)
    {
        return response()->json([
            'status' => 'success',
            'data' => StudioDraftResource::payload($draft),
        ], $status);
    }

    /** 409 carrying the draft as it now stands, so the SPA can show it rather than guess. */
    private function conflict(StudioDraft $draft, string $message)
    {
        return response()->json([
            'status' => 'conflict',
            'message' => $message,
            'data' => StudioDraftResource::payload($draft),
        ], Response::HTTP_CONFLICT);
    }
}
