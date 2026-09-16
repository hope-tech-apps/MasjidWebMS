<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which build each handset is actually running.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS, AND WHAT DECISION IT UNBLOCKS
 * ---------------------------------------------------------------------------
 * The retirement of the legacy `GET /mobile/masjids/{id}/features` list ends on
 * one question nobody can answer today: is anybody still running a build that
 * reads it? Burlington's store build (v2.5 b44) does, Play vc13 does, and the
 * only record this server keeps of any handset is a free-text `user_agent`
 * written by whatever HTTP stack the app happened to use — an OkHttp or
 * CFNetwork default that names the library, not the app.
 *
 * So three columns, filled from the `X-Manara-App` header the R1 builds send
 * (App\Support\AppClientHeader) or from the request body, and read back by
 * `php artisan app-telemetry:builds`. Paired with the `/features` hit counter
 * (App\Http\Middleware\CountLegacyFeaturesHit), that is the evidence for the
 * S3b decision: delete the endpoint, or keep answering it.
 *
 * ---------------------------------------------------------------------------
 * NULL IS THE NORMAL STATE AND MUST STAY WRITABLE-ONCE-ONLY-UPWARDS
 * ---------------------------------------------------------------------------
 * Every row is NULL on the day this runs, and a row stays NULL for as long as
 * its handset runs a build that predates the header — which is the whole point:
 * "NULL" is itself the pre-R1 reading, printed by the command as `pre-R1`.
 *
 * The rule that makes that reading trustworthy lives in the controller, not
 * here: an absent value NEVER nulls a stored one. A phone that upgrades to R1,
 * records `ios/1.0/47`, and then has the app downgraded or reinstalled from an
 * older TestFlight build must not silently erase what we learned, or the
 * "everybody is on R1" reading would be one stale install away from wrong.
 * See MobileAppUsersController and HeartbeatBuildTelemetryTest.
 *
 * ---------------------------------------------------------------------------
 * THE LENGTHS ARE LOAD-BEARING, AND SQLITE CANNOT SEE THEM
 * ---------------------------------------------------------------------------
 * varchar(10) / varchar(20) / varchar(20). The suite runs in-memory SQLite,
 * which enforces no VARCHAR length at all, so a body field longer than the
 * column passes every local test and throws a 1406 on MySQL in production. The
 * defence is in two places: the request rules cap each field at the column's
 * length (`max:10` / `max:20`), and HeartbeatBuildTelemetryTest asserts the
 * TYPE off the schema and the LENGTH off this migration's own Blueprint rather
 * than off the SQLite table. Same measurement as `form_responses.paid_via`.
 *
 * Why not an enum for `app_platform`: the parser already pins it to `ios` or
 * `android`, and a third client (tvOS reports its own build today through
 * nothing at all) would then be a migration rather than a validation rule.
 *
 * No index. Nothing looks a device up BY build — `app-telemetry:builds` reads
 * the whole active set once a day at most, and the only hot query on this table
 * (`device_id`) already has its unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->string('app_platform', 10)->nullable()->after('user_agent');
            $table->string('app_version', 20)->nullable()->after('app_platform');
            $table->string('app_build', 20)->nullable()->after('app_version');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->dropColumn(['app_platform', 'app_version', 'app_build']);
        });
    }
};
