<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * provisioning_jobs — one row per platform per "Generate apps" request.
 *
 * The portal is the CONTROL PLANE for a self-hosted app-provisioning pipeline: a
 * Super-Admin dispatches a GitHub Actions workflow (running on a self-hosted
 * runner) to scaffold + build + upload a masjid's iOS/Android apps. Each
 * dispatch creates one row here; the workflow then POSTs status updates back to
 * the portal's callback endpoint, which advances this row's status/detail/
 * artifact_url.
 *
 * Columns:
 *   - job_id          : opaque public UUID identifying the job in the contract
 *                       payload + the callback body. Unique.
 *   - masjid_id       : the tenant this job provisions for (server-derived from
 *                       the route, never client input). Cascade on delete.
 *   - platform        : ios | android — one dispatch targets exactly one repo.
 *   - status          : lifecycle. Starts `queued`; becomes `dispatched` once the
 *                       GitHub repository_dispatch is accepted; the runner then
 *                       reports `scaffolding | building | uploaded | built |
 *                       failed` via the callback. `failed` is also set locally
 *                       when the dispatch call itself fails.
 *   - detail          : human-readable status detail (last message from the
 *                       runner, or the local dispatch-failure reason). Nullable.
 *   - artifact_url    : link to the built artifact (TestFlight / Play internal /
 *                       CI artifact), supplied by the runner on success. Nullable.
 *   - callback_token  : a per-job SECRET (Str::random(40)) the runner must present
 *                       as `Authorization: Bearer <token>` to authenticate its
 *                       callback for THIS job. `$hidden` + never returned by any
 *                       read endpoint; constant-time compared. See ProvisioningJob.
 *   - github_repo     : the full `owner/repo` this job was dispatched to (recorded
 *                       so the row is self-describing even if config changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('job_id')->unique();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->enum('platform', ['ios', 'android']);

            // Lifecycle. `dispatched` is portal-internal (set on a successful
            // repository_dispatch); the rest are the contract callback statuses.
            $table->enum('status', [
                'queued',
                'dispatched',
                'scaffolding',
                'building',
                'uploaded',
                'built',
                'failed',
            ])->default('queued');

            $table->text('detail')->nullable();
            $table->string('artifact_url')->nullable();

            // Per-job callback secret — encrypted-at-rest is unnecessary (it is a
            // random bearer, rotated per job), but it is `$hidden` on the model and
            // NEVER echoed; the callback compares it in constant time.
            $table->string('callback_token', 64);

            // Full `owner/repo` the job was dispatched to.
            $table->string('github_repo');

            $table->timestamps();

            // The status panel polls "latest jobs for this masjid".
            $table->index(['masjid_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_jobs');
    }
};
