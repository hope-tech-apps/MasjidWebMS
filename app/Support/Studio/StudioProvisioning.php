<?php

namespace App\Support\Studio;

use App\Http\Requests\Admin\Onboarding\ProvisionMasjidRequest;
use App\Jobs\AttachMasjidDomain;
use App\Models\Masjid;
use App\Models\StudioDraft;
use App\Services\Auth\AccountAccessService;
use App\Support\MobileCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Throwable;

/**
 * Step 3: a draft becomes an organisation (docs/manara-studio.md D7, D8;
 * docs/manara-studio-w1.md S8, R7, R10).
 *
 * THE RULES ARE THE WIZARD'S. The draft is flattened into
 * ProvisionMasjidRequest's keys and validated by that request's own rules,
 * then provisioned by the one OrganisationProvisioner body, so a draft and a
 * direct POST of the same answers produce the same organisation. Only what the
 * wizard has no field for happens here: the brand gate, the logo and its
 * derivatives, the inks, and the draft's own status.
 *
 * BEFORE THE TRANSACTION, nothing is written: the request is validated, the
 * gate is checked and the images are made in a private temporary directory.
 *
 * IN ONE TRANSACTION: the draft is locked FOR UPDATE and must still be a
 * draft (two clicks, two tabs or a retry after a timeout all meet here, and
 * only the first provisions: StudioDraftConflict, 409); then the organisation,
 * the inks, the logo and its three derivatives, and the draft marked
 * provisioned. Any failure unwinds every row, and the files a rollback cannot
 * reach (medialibrary saves a Media row before copying its file, the temporary
 * directory) are deleted before the error is rethrown. The draft keeps its logo
 * then, so a retry can succeed.
 *
 * AFTER THE COMMIT, each step in its own try, because the organisation is real
 * now and none of them may turn it into a 500: the invites, the directory cache
 * flush, the domain attach jobs, deleting the draft's private logo bytes (its
 * metadata stays, the record of what was uploaded), and the temporary
 * directory. A failure is logged at warning (production's level) and reported
 * in the result's `afterCommit`.
 */
class StudioProvisioning
{
    /**
     * @param  array{ios?: array<string, string>, android?: array<string, string>}  $secrets  BYO store credentials, typed at Step 3 and never stored on the draft (R7)
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException 422 from the request's rules or the brand gate
     * @throws StudioDraftConflict when the draft was provisioned first
     */
    public function provision(StudioDraft $draft, array $secrets = []): StudioProvisionResult
    {
        // A Studio organisation is always born through the switches: without a
        // Step 1 map the defaults stand, but the pivot is still derived from
        // them, and the response always says what was applied.
        $payload = $draft->toProvisionPayload($secrets) + ['capabilities' => []];

        $request = ProvisionMasjidRequest::create('/api/admin/onboarding/provision', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $palette = StudioBrandGate::assert($draft, $request);

        $files = $draft->logoExists()
            ? app(LogoDerivatives::class)->generate($draft, (string) $draft->section('brand')['background_color'])
            : null;

        $context = ProvisionContext::fromAuth();
        $invitations = [];
        $mediaDirectories = [];

        try {
            [$masjid, $locked] = DB::transaction(function () use ($draft, $request, $palette, $files, $context, &$invitations, &$mediaDirectories) {
                $locked = StudioDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== StudioDraft::STATUS_DRAFT) {
                    throw new StudioDraftConflict($locked);
                }

                $masjid = null;

                try {
                    $masjid = app(OrganisationProvisioner::class)->create($request, $invitations, $context);

                    ApplyDraftBrand::apply($masjid, $palette['tokens']['color']);

                    if ($files !== null) {
                        ApplyDraftLogo::apply($masjid, $files);
                    }

                    $locked->update([
                        'status' => StudioDraft::STATUS_PROVISIONED,
                        'provisioned_masjid_id' => $masjid->id,
                        'provisioned_at' => now(),
                        'updated_by' => $context->actorId,
                    ]);
                } catch (Throwable $e) {
                    // Read while the rows are still visible: after the rollback
                    // nothing says which directories were written.
                    $mediaDirectories = $masjid === null ? [] : self::mediaDirectories($masjid);

                    throw $e;
                }

                return [$masjid, $locked];
            });
        } catch (Throwable $e) {
            foreach ($mediaDirectories as [$disk, $directory]) {
                Storage::disk($disk)->deleteDirectory($directory);
            }

            if ($files !== null) {
                File::deleteDirectory($files->directory);
            }

            throw $e;
        }

        return new StudioProvisionResult($masjid, $context, $this->afterCommit($locked, $invitations, $context, $files));
    }

    /**
     * @param  array<int, array{0: \App\Models\User, 1: string}>  $invitations
     * @return array{invites_sent: int, invites_failed: int, warnings: list<string>}
     */
    private function afterCommit(StudioDraft $draft, array $invitations, ProvisionContext $context, ?LogoFiles $files): array
    {
        $report = ['invites_sent' => 0, 'invites_failed' => 0, 'warnings' => []];

        foreach ($invitations as [$admin, $orgName]) {
            $sent = $this->attempt($report, "The invitation to {$admin->email} was not sent. Send it again from the organisation's Team & Access screen.",
                fn () => app(AccountAccessService::class)->invite($admin, $orgName) ?: throw new \RuntimeException('The account has no email address.'));

            $sent ? $report['invites_sent']++ : $report['invites_failed']++;
        }

        $this->attempt($report, 'The app directory cache was not refreshed; the new organisation appears in the apps when it expires, within a day.',
            fn () => MobileCache::flushGlobal(MobileCache::MASJIDS_LIST));

        foreach ($context->domains as $domain) {
            $this->attempt($report, "Attaching {$domain->host} was not started. The five-minute domain check picks it up, or press Check now.",
                fn () => AttachMasjidDomain::dispatch($domain->id));
        }

        $this->attempt($report, 'The draft\'s private copy of the logo was not deleted; the daily draft purge does not reach provisioned drafts, so remove it by hand.',
            fn () => $draft->deleteLogoBytes());

        if ($files !== null) {
            $this->attempt($report, 'A temporary folder of logo images was not deleted from the server.',
                fn () => File::deleteDirectory($files->directory) ?: throw new \RuntimeException("Could not delete {$files->directory}."));
        }

        return $report;
    }

    /**
     * Run one after-commit step. A failure is logged at warning, which is what
     * production writes, and its sentence added to the report.
     *
     * @param  array{invites_sent: int, invites_failed: int, warnings: list<string>}  $report
     */
    private function attempt(array &$report, string $warning, callable $step): bool
    {
        try {
            $step();

            return true;
        } catch (Throwable $e) {
            Log::warning('Studio provision: an after-commit step failed', [
                'warning' => $warning,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $report['warnings'][] = $warning;

            return false;
        }
    }

    /**
     * The disk and directory of every Media row the organisation has, which
     * inside the failed transaction is exactly the rows this provision wrote:
     * the organisation did not exist before it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function mediaDirectories(Masjid $masjid): array
    {
        try {
            return Media::query()
                ->where('model_type', Masjid::class)
                ->where('model_id', $masjid->id)
                ->get()
                ->map(fn (Media $media) => [$media->disk, rtrim(PathGeneratorFactory::create($media)->getPath($media), '/')])
                ->all();
        } catch (Throwable $e) {
            Log::warning('Studio provision: could not list the media to clean up after a failure', ['message' => $e->getMessage()]);

            return [];
        }
    }
}
