<?php

use App\Models\PushApp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->string('push_app_id', 128)->nullable()->index();
        });
        Schema::create('push_apps', function (Blueprint $table) {
            $table->id();
            $table->string('provider_app_id', 128)->unique();
            $table->text('rest_api_key')->nullable();
            $table->string('label');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('push_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('notification_id')->nullable()->unique()->constrained('notifications')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('status', 32)->default('checking');
            $table->unsignedInteger('targeted')->default(0);
            $table->unsignedInteger('delivered')->nullable();
            $table->unsignedInteger('errored')->nullable();
            $table->unsignedInteger('unknown_app')->default(0);
            $table->json('platform_counts')->nullable();
            $table->timestamp('readback_scheduled_at')->nullable();
            $table->timestamps();
            $table->index(['masjid_id', 'created_at']);
        });
        Schema::create('push_send_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('push_send_id')->constrained('push_sends')->cascadeOnDelete();
            $table->string('routing_key', 160);
            $table->string('provider_app_id', 128)->nullable();
            $table->string('app_label');
            $table->boolean('legacy')->default(false);
            $table->string('provider_message_id', 128)->nullable();
            $table->string('status', 32)->default('checking');
            $table->unsignedInteger('targeted')->default(0);
            $table->unsignedInteger('delivered')->nullable();
            $table->unsignedInteger('errored')->nullable();
            $table->json('platform_counts')->nullable();
            $table->unsignedTinyInteger('checks')->default(0);
            $table->timestamps();
            $table->unique(['push_send_id', 'routing_key']);
        });
        try {
            $this->copyExistingAccounts();
        } catch (\Throwable $e) {
            // Database exceptions may include encrypted credential bindings.
            throw new \RuntimeException('Push registry migration could not copy existing accounts.');
        }
    }

    /** Copy runtime shared config and existing encrypted organisation credentials without emitting either. */
    public function copyExistingAccounts(): void
    {
        $appId = config('onesignal.app_id');
        $key = config('onesignal.app_rest_api_key');
        if (filled($appId) && filled($key)) {
            PushApp::firstOrCreate(['provider_app_id' => $appId], ['rest_api_key' => $key, 'label' => 'Shared push account']);
        }
        // Copy the ciphertext verbatim: the existing encrypted cast uses the same APP_KEY.
        foreach (DB::table('masjid_app_publishing')->whereNotNull('onesignal_app_id')
            ->whereNotNull('onesignal_rest_api_key')->orderBy('id')->cursor() as $config) {
            if (blank($config->onesignal_app_id) || blank($config->onesignal_rest_api_key)) {
                continue;
            }
            DB::table('push_apps')->updateOrInsert(['provider_app_id' => $config->onesignal_app_id], [
                'rest_api_key' => $config->onesignal_rest_api_key, 'label' => 'Organisation push account '.$config->masjid_id,
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_send_groups');
        Schema::dropIfExists('push_sends');
        Schema::dropIfExists('push_apps');
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->dropIndex(['push_app_id']);
            $table->dropColumn('push_app_id');
        });
    }
};
