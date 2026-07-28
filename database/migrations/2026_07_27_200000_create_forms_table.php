<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forms are first-class, NOT part of a section's content JSON.
 *
 * A `form` section on a page carries only `content.form_id`. That separation is
 * deliberate: `sections` are hard-deleted (App\Models\Section has no SoftDeletes,
 * and page_section cascades), so a schema stored inside a section would take every
 * submission with it the first time an admin removed the section from a page.
 * Responses have to outlive page edits — a camp registration list cannot disappear
 * because someone restructured the page it was collected on.
 *
 * It also means one form can be placed on more than one page, and keeps a single
 * response list per form rather than one per placement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->onDelete('cascade');

            // Case-sensitive slug, matching the `pages` table convention so a form
            // and its page can share a slug without colliding on case.
            $slug = $table->string('slug');
            if (DB::getDriverName() === 'mysql') {
                $slug->collation('utf8mb4_bin');
            }

            $table->string('name');
            $table->text('description')->nullable();

            // The field definition: { sections: [ { id, title, description, repeatable,
            // minEntries, maxEntries, addButtonLabel, fields: [ … ] } ] }.
            // This is the SINGLE source of truth for rendering AND for server-side
            // validation — the public submit endpoint derives its rules from here so a
            // tampered client cannot bypass a required field.
            $table->json('schema');

            // Presentation + behaviour: submit label, success copy, notification
            // recipients, fee rule, and the identity map that tells us which fields to
            // denormalise onto form_responses for search and sort.
            $table->json('settings')->nullable();

            $table->boolean('is_active')->default(true);

            // Registration window. Null on either side means "unbounded on that side".
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();

            // Hard cap on accepted responses; null means unlimited.
            $table->unsignedInteger('capacity')->nullable();

            // Denormalised so capacity checks and the admin list do not COUNT(*) the
            // response table on every request. Maintained by FormResponse's model events.
            $table->unsignedInteger('response_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['masjid_id', 'slug']);
            $table->index(['masjid_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
