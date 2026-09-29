<?php

namespace App\Support;

use App\Models\Masjid;
use App\Support\Renderer\RendererPurgeScheduler;
use App\Support\Studio\LogoDerivatives;
use App\Support\Studio\LogoFiles;
use App\Support\Studio\LogoTooLarge;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Throwable;

/**
 * An organisation's favicon, touch icon and share image, rebuilt from the logo
 * it has now (Studio W2 S8). The same three images Studio makes at provision
 * (LogoDerivatives), for an organisation that already exists.
 *
 * LIVE EFFECT. An organisation with none of the three, which is every live
 * organisation, gains three keys on /api/v1/settings and a new tab icon and
 * share card the moment this runs: the change W1 kept away from Burlington and
 * MEC (docs/manara-studio-w1.md R11). So it runs only on an explicit per-org
 * SuperAdmin action, or after a logo upload for an organisation that ALREADY
 * has derivatives (afterLogoUpload). Nothing reaches the mobile payloads: they
 * read `logos`, which this never touches.
 *
 * NEW FIRST, OLD AFTER COMMIT. The three new rows are added in one
 * transaction; the previous rows of those collections are deleted only once it
 * has committed. medialibrary removes a row's files as the row is deleted, not
 * at commit, so clearing first inside the transaction would lose the old files
 * on a rollback. A failure before the commit deletes the directories of the
 * new rows it made (StudioProvisioning's pattern) and leaves the old
 * derivatives exactly as they were.
 *
 * ONE RUN PER ORGANISATION. A per-org cache lock is taken before the previous
 * rows are read and released only when the old rows are gone (or the outer
 * transaction they wait on has rolled back), so a double click, or the upload
 * hook racing the route, cannot each add three rows and leave six. The
 * deletion of the old rows is registered with DB::afterCommit, so it is right
 * both when nothing is open (it runs at once) and when a caller's transaction
 * is (it waits for that commit).
 *
 * TOO BIG TO DECODE. LogoDerivatives refuses a logo over its edge cap or the
 * memory this process has left, from the header, before GD decodes anything
 * (LogoTooLarge). The route answers that as a 422; an upload skips.
 */
final class BrandAssets
{
    /** The collections this rebuilds; `logos` is the source and is never written. */
    public const COLLECTIONS = [Masjid::FAVICONS, Masjid::TOUCH_ICONS, Masjid::SHARE_IMAGES];

    public const NO_LOGO = 'Upload a PNG or JPEG logo first.';

    public const NO_BACKGROUND = 'Choose a background colour as #RRGGBB: the organisation\'s theme has none.';

    /**
     * How long a holder may keep the lock if it never releases (a killed
     * process, an outer transaction that never ends): well over a run, short
     * enough that nobody waits on a dead one.
     */
    private const LOCK_SECONDS = 60;

    /**
     * TEST SEAM: how long regenerate() waits for the lock before it gives up
     * with BrandAssetsBusy. A test sets 0 to make a held lock fail at once and
     * must put it back (BrandAssetRegenerationTest does, in tearDown).
     */
    public static float $lockWaitSeconds = 3.0;

    public static function lockKey(int $masjidId): string
    {
        return "brand-assets:regenerate:{$masjidId}";
    }

    /**
     * @param  string|null  $backgroundColor  #RRGGBB; null takes the theme's background colour
     * @return array{logo_url: string, favicon_url: string, touch_icon_url: string, share_image_url: string}
     *
     * @throws ValidationException 422 when there is no PNG/JPEG logo or no usable background colour
     * @throws LogoTooLarge 422 (a ValidationException) when the logo is too large to decode safely
     * @throws BrandAssetsBusy when another regeneration for this organisation did not finish inside the wait
     */
    public static function regenerate(Masjid $org, ?string $backgroundColor, ?int $actor): array
    {
        $lock = Cache::lock(self::lockKey((int) $org->id), self::LOCK_SECONDS);

        try {
            $lock->block(self::$lockWaitSeconds);
        } catch (LockTimeoutException) {
            throw new BrandAssetsBusy();
        }

        try {
            return self::run($org, $backgroundColor, $actor, $lock);
        } catch (Throwable $e) {
            // run() hands the lock on to its commit callbacks only when it
            // returns; any throw before that leaves it here.
            $lock->release();

            throw $e;
        }
    }

    /**
     * @return array{logo_url: string, favicon_url: string, touch_icon_url: string, share_image_url: string}
     */
    private static function run(Masjid $org, ?string $backgroundColor, ?int $actor, Lock $lock): array
    {
        $logo = $org->logo()->first();
        $source = $logo?->getPath();

        if ($logo === null || ! is_string($source) || ! is_readable($source)
            || ! in_array(@getimagesize($source)[2] ?? null, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw ValidationException::withMessages(['logo' => [self::NO_LOGO]]);
        }

        $background = $backgroundColor ?? $org->themeSettings()->value('background_color');

        if (! is_string($background) || preg_match('/^#[0-9A-Fa-f]{6}$/', $background) !== 1) {
            throw ValidationException::withMessages(['background_color' => [self::NO_BACKGROUND]]);
        }

        try {
            $files = app(LogoDerivatives::class)->fromFile($source, $background);
        } catch (LogoTooLarge $e) {
            // Not "no logo": the caller is told the size, and nothing was written.
            throw $e;
        } catch (Throwable $e) {
            // A header GD accepted and data it could not decode: the same
            // answer as no logo, and the reason in the log.
            Log::warning('Brand assets: the logo could not be read', ['masjid_id' => (int) $org->id, 'message' => $e->getMessage()]);

            throw ValidationException::withMessages(['logo' => [self::NO_LOGO]]);
        }

        try {
            $previous = self::derivatives($org)->get();
            $created = self::replace($org, $files, $previous->modelKeys());
        } finally {
            File::deleteDirectory($files->directory);
        }

        // Only once the outermost transaction has committed do the old rows,
        // and with them their files, go, and the lock with them. With none open
        // (a request) DB::afterCommit runs this at once.
        DB::afterCommit(function () use ($org, $previous, $background, $actor, $lock) {
            try {
                self::finish($org, $previous, $background, $actor);
            } finally {
                $lock->release();
            }
        });

        // A caller's transaction that rolls back takes the new rows with it: the
        // files it copied are strays, and the lock has to go. (No-op with none
        // open, and then the commit callback above has already run.)
        $newDirectories = array_values(array_map(fn (Media $media) => self::directoryOf($media), $created));
        DB::afterRollBack(function () use ($org, $newDirectories, $lock) {
            try {
                self::deleteDirectories($newDirectories, (int) $org->id);
            } finally {
                $lock->release();
            }
        });

        return [
            'logo_url' => $logo->original_url,
            'favicon_url' => $created[Masjid::FAVICONS]->original_url,
            'touch_icon_url' => $created[Masjid::TOUCH_ICONS]->original_url,
            'share_image_url' => $created[Masjid::SHARE_IMAGES]->original_url,
        ];
    }

    /**
     * The after-commit half of a regeneration. Nothing here may throw: it runs
     * inside somebody's commit, and the new images are already what is served.
     *
     * @param  \Illuminate\Support\Collection<int, Media>  $previous
     */
    private static function finish(Masjid $org, $previous, string $background, ?int $actor): void
    {
        foreach ($previous as $media) {
            try {
                $media->delete();
            } catch (Throwable $e) {
                // The new row is the latest and is what every reader takes; an
                // old one left behind is clutter, not a wrong icon.
                Log::warning('Brand assets: a previous derivative was not deleted', [
                    'masjid_id' => (int) $org->id, 'media_id' => (int) $media->id, 'message' => $e->getMessage(),
                ]);
            }
        }

        // The renderer's cached pages carry the old head. The by-host lookup's
        // KV record rewrites itself when its value changes (W1 R5).
        try {
            RendererPurgeScheduler::afterSave((int) $org->id);
        } catch (Throwable $e) {
            Log::warning('Brand assets: the renderer purge was not scheduled', ['masjid_id' => (int) $org->id, 'message' => $e->getMessage()]);
        }

        // Warning, not info: production runs LOG_LEVEL=warning, and this changes
        // what a live site shows.
        Log::warning('Brand assets regenerated', [
            'masjid_id' => (int) $org->id,
            'actor_user_id' => $actor,
            'background_color' => $background,
            'replaced' => $previous->count(),
        ]);
    }

    /**
     * After an admin logo upload has committed: keep the derivatives in step
     * with the new logo, but ONLY for an organisation that already has any.
     * One with none (every live organisation) is left exactly as it was.
     *
     * Never throws and never changes the upload's answer. A failure (a logo GD
     * cannot read, say) is logged at warning, the deployed level, and the
     * previous derivatives stay.
     */
    public static function afterLogoUpload(Masjid $org, ?int $actor): void
    {
        try {
            if (! self::derivatives($org)->exists()) {
                return;
            }

            self::regenerate($org, null, $actor);
        } catch (LogoTooLarge $e) {
            // One warning, with the size, and no other log line on this path.
            Log::warning('Brand assets were not regenerated after a logo upload: the logo is too large to make the icons from; the previous favicon, touch icon and share image stay', [
                'masjid_id' => (int) $org->id,
                'actor_user_id' => $actor,
                'width' => $e->width,
                'height' => $e->height,
                'limit' => $e->limit,
            ]);
        } catch (BrandAssetsBusy) {
            Log::warning('Brand assets were not regenerated after a logo upload: another regeneration was still running; the previous favicon, touch icon and share image stay', [
                'masjid_id' => (int) $org->id,
                'actor_user_id' => $actor,
            ]);
        } catch (Throwable $e) {
            Log::warning('Brand assets were not regenerated after a logo upload; the previous favicon, touch icon and share image stay', [
                'masjid_id' => (int) $org->id,
                'actor_user_id' => $actor,
                'message' => $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage(),
            ]);
        }
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Media> */
    private static function derivatives(Masjid $org)
    {
        return Media::query()
            ->where('model_type', Masjid::class)
            ->where('model_id', $org->id)
            ->whereIn('collection_name', self::COLLECTIONS);
    }

    /**
     * The three new rows, in one transaction. On a failure the directories of
     * rows this made are read while they are still visible, and deleted once
     * the rollback has removed the rows.
     *
     * @param  list<int>  $previousIds
     * @return array<string, Media> collection => the new row
     */
    private static function replace(Masjid $org, LogoFiles $files, array $previousIds): array
    {
        $stray = [];

        try {
            return DB::transaction(function () use ($org, $files, $previousIds, &$stray) {
                try {
                    return [
                        Masjid::FAVICONS => self::add($org, $files->favicon, Masjid::FAVICONS, 'favicon.png'),
                        Masjid::TOUCH_ICONS => self::add($org, $files->touchIcon, Masjid::TOUCH_ICONS, 'touch-icon.png'),
                        Masjid::SHARE_IMAGES => self::add($org, $files->shareImage, Masjid::SHARE_IMAGES, 'share-image.png'),
                    ];
                } catch (Throwable $e) {
                    $stray = self::directoriesOfNewRows($org, $previousIds);

                    throw $e;
                }
            });
        } catch (Throwable $e) {
            self::deleteDirectories($stray, (int) $org->id);

            throw $e;
        }
    }

    /**
     * `preservingOriginal()` is mandatory, as in ApplyDraftLogo: without it the
     * source is deleted once copied, and a rolled-back attempt could not be
     * retried from the same files.
     */
    private static function add(Masjid $org, string $path, string $collection, string $fileName): Media
    {
        return $org->addMedia($path)
            ->preservingOriginal()
            ->usingFileName($fileName)
            ->usingName(pathinfo($fileName, PATHINFO_FILENAME))
            ->toMediaCollection($collection);
    }

    /**
     * @param  list<int>  $previousIds
     * @return list<array{0: string, 1: string}>
     */
    private static function directoriesOfNewRows(Masjid $org, array $previousIds): array
    {
        try {
            return self::derivatives($org)
                ->whereNotIn('id', $previousIds)
                ->get()
                ->map(fn (Media $media) => self::directoryOf($media))
                ->all();
        } catch (Throwable $e) {
            Log::warning('Brand assets: could not list the media to clean up after a failure', ['masjid_id' => (int) $org->id, 'message' => $e->getMessage()]);

            return [];
        }
    }

    /** @return array{0: string, 1: string} the media's disk and its directory on it */
    private static function directoryOf(Media $media): array
    {
        return [$media->disk, rtrim(PathGeneratorFactory::create($media)->getPath($media), '/')];
    }

    /** @param  list<array{0: string, 1: string}>  $directories */
    private static function deleteDirectories(array $directories, int $masjidId): void
    {
        foreach ($directories as [$disk, $directory]) {
            try {
                Storage::disk($disk)->deleteDirectory($directory);
            } catch (Throwable $e) {
                Log::warning('Brand assets: a directory of a rolled-back regeneration was not deleted', [
                    'masjid_id' => $masjidId, 'directory' => $directory, 'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
