<?php

// NOTE: this lives in tests/Support, not tests/fixtures — the latter is
// lowercase on disk and holds data files, so PSR-4 (`Tests\` => `tests/`)
// cannot autoload a `Tests\Fixtures\…` class from it on a case-sensitive
// filesystem, even though macOS resolves it locally.
namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

/**
 * A SECOND tokenable model, existing only for tests.
 *
 * The `auth.guards.sanctum.provider` pin in config/auth.php is unfalsifiable
 * while `App\Models\User` is the only model in the app that uses
 * `HasApiTokens` — there is nothing for the provider check to reject. This
 * fixture supplies that second tokenable so the pin can actually be proven,
 * without adding an authenticatable model to app/ ahead of the slice that owns
 * one (T-015c gives `App\Models\Contact` a login).
 *
 * Deliberately shaped like the real hazard rather than a strawman:
 *
 *  - it extends Illuminate\Foundation\Auth\User, exactly as App\Models\User
 *    does, so it is a genuine Authenticatable and could be bound to a guard;
 *  - it carries a `type` column, and the tests set it to 'SuperAdmin'. That is
 *    the point: `UserAdminMiddleware` reads `type` off whatever the guard
 *    resolved, so a foreign principal claiming an admin type is precisely what
 *    the two layers under test have to stop.
 *
 * Its table is created per-test by createTable() rather than by a migration,
 * so nothing about it reaches the application schema.
 */
class ForeignTokenable extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'foreign_tokenables';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * Create the backing table on the active (in-memory) connection. Call from
     * a test's setUp(), after RefreshDatabase has migrated.
     */
    public static function createTable(): void
    {
        if (Schema::hasTable('foreign_tokenables')) {
            return;
        }

        Schema::create('foreign_tokenables', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            // The attribute UserAdminMiddleware / SuperAdminMiddleware read.
            $table->string('type')->nullable();
        });
    }
}
