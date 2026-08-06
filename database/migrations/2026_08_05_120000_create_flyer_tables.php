<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flyer templates + saved flyers.
 *
 * Same "schema as data" split the forms tables use: a template holds the DESIGN
 * (named slots, their types, their defaults) as JSON, and each flyer holds only the
 * filled values. A design can then be corrected — a zone renamed, a default nudged —
 * without rewriting every flyer already produced from it, and an old flyer still
 * renders against the values it was actually saved with.
 *
 * The one structural difference from forms: templates are SHARED. The handful of
 * system designs (the locked food-drive layout, the event layouts) belong to no
 * masjid and every tenant must see them, so flyer_templates.masjid_id is nullable.
 * See App\Models\FlyerTemplate for how the tenant scope is widened to match, and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flyer_templates', function (Blueprint $table) {
            $table->id();

            // The stable public handle. Globally unique, NOT unique-per-masjid: the
            // seeder, the Assistant's tools and the Studio all resolve a template by
            // key alone, so a key has to name exactly one design. A
            // unique(['masjid_id','key']) would also fail to do the one job that
            // matters most here — MySQL and SQLite both treat NULLs as distinct, so it
            // would happily admit two system rows both claiming 'food.classic'.
            //
            // Tenant-authored templates namespace their key instead (e.g.
            // 'm12.eid-night'), and that prefix is STAMPED, not assumed:
            // FlyerTemplate's saving guard derives it from the bound tenant the same
            // way masjid_id is derived. Without that, this unique() would be a
            // cross-tenant collision — masjid 12 picking 'food.classic' either
            // colliding with the system row or shadowing it in a key-alone lookup.
            $table->string('key')->unique();

            $table->string('name');

            // Drives which slot vocabulary and which renderer a template gets. A DB
            // enum rather than a string because adding a fourth kind is a code change
            // anyway — there is no renderer for a value the app has never heard of.
            $table->enum('kind', ['food', 'event', 'janazah']);

            // The design itself: { slots: [ { name, type, default, … ] }. Single source
            // of truth for the Studio's editor AND for validating a flyer's content, so
            // a hand-posted payload cannot smuggle in a slot the design does not define.
            $table->json('schema');

            $table->boolean('is_active')->default(true);

            // System templates ship with the product: seeded, shared, and not editable
            // by a tenant. A masjid's own template has is_system = false and a
            // masjid_id; the two flags always move together.
            $table->boolean('is_system')->default(false);

            // NULL = shared with every tenant. Set = that tenant's own design.
            // Deliberately NOT ->constrained()->cascadeOnDelete() in the usual way:
            // cascade is right for a tenant row, and NULL is simply never matched by
            // it, so the shared rows survive a masjid being deleted.
            $table->foreignId('masjid_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamps();

            // The picker's query is `(masjid_id IS NULL OR masjid_id = ?) AND kind = ?
            // AND is_active = 1`. The OR keeps any masjid_id-leading index from
            // covering it on its own, so the useful index is the one on the remaining
            // predicate; the second serves the "my own templates" admin list.
            $table->index(['kind', 'is_active']);
            $table->index(['masjid_id', 'is_active']);
        });

        Schema::create('flyers', function (Blueprint $table) {
            $table->id();

            // Opaque public handle, kept separate from the auto-increment id so a
            // rendered flyer can be linked to without leaking row counts.
            $table->uuid('uuid')->unique();

            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // No cascade and no null-on-delete: RESTRICT is what we want. A flyer
            // cannot be re-rendered without the design it was built from, so a
            // template with flyers on it must be deactivated, not deleted.
            $table->foreignId('flyer_template_id')->constrained();

            $table->string('title');

            // The filled slot values, keyed by slot name — the flyer's whole content.
            $table->json('content');

            // The colours as RESOLVED at save time, snapshotted out of the masjid's
            // theme_settings rather than looked up at render time. A masjid that
            // rebrands next month must not silently restyle a flyer its board has
            // already approved and printed.
            $table->json('palette');

            $table->enum('status', ['draft', 'rendered'])->default('draft');

            // Storage-relative paths. rendered_path is the finished image;
            // source_image_path is what the admin uploaded; cutout_path is that same
            // image with its background removed by the cutout worker.
            $table->string('rendered_path')->nullable();
            $table->string('source_image_path')->nullable();
            $table->string('cutout_path')->nullable();

            // Background removal runs out-of-band, so its progress lives beside the
            // flyer rather than in the queue: the Studio polls this to decide whether
            // to show the original, a spinner, or the cutout. 'none' means no cutout
            // was ever asked for, which is the normal case for a flyer with no photo.
            $table->enum('cutout_status', ['none', 'queued', 'processing', 'done', 'failed'])
                ->default('none');

            // Last failure reason, surfaced to the admin so a failed cutout is
            // actionable ("image too large") instead of just broken. Text because
            // worker output is not length-bounded.
            $table->text('cutout_error')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The Studio's default list: this masjid's flyers, newest first.
            $table->index(['masjid_id', 'created_at']);
            $table->index(['masjid_id', 'status']);

            // The cutout worker claims work across every tenant from an unbound
            // context, so this one must NOT lead with masjid_id.
            $table->index('cutout_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flyers');
        Schema::dropIfExists('flyer_templates');
    }
};
