<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The read-through cache behind the parent portal's "Translate to Arabic"
 * button (config/translation.php).
 *
 * A class story does not change after it is posted, and thirty families tap the
 * same button on it. Without a cache that is thirty paid model calls for one
 * paragraph; with one it is one. Retranslating identical text is the whole cost
 * of this feature, so the cache is not an optimisation bolted on afterwards —
 * it is the reason the feature is affordable.
 *
 * THE ROW STORES A HASH OF THE SOURCE, NEVER THE SOURCE ITSELF. `source_hash` is
 * a plain sha256 of the English text, which is all a lookup needs; the English
 * already lives in `group_posts`, `group_messages` and `report_card_marks`,
 * governed there by consent, by GroupAudience and by the retention sweep. A
 * second copy of it here would be a second place a teacher's note about a child
 * exists, outside all three. The ARABIC is stored, because that is the artefact
 * being cached and it exists nowhere else.
 *
 * A plain digest rather than the keyed HMAC that `contact_login_codes` and
 * `form_staff_codes` use, and the difference is deliberate: those hash a SECRET,
 * where an unkeyed digest of an eight-character code is reversible by anyone
 * holding the table. This hashes text the reader is already looking at. There is
 * no secret to protect, and the hash has to be reproducible from the text alone
 * on the next request, which is exactly what a keyed digest is designed to
 * prevent.
 *
 * KEYED PER MASJID, NOT GLOBALLY. Two schools that post the same sentence keep
 * two rows and pay twice, and that is the correct trade. A global cache would be
 * one lookup that answers across tenants — a row written by organisation A
 * served to organisation B — which is precisely the shape
 * .claude/rules/tenant-scoping.md exists to forbid, and it would do it through a
 * table with no `masjid_id` on the query path at all, where the BelongsToMasjid
 * global scope could not help. It would also turn "did anyone at this school
 * ever write this sentence?" into a question the cache could answer by timing.
 * The unique index therefore leads with `masjid_id`.
 *
 * `last_used_at` is stamped on every cache HIT — and on the create, so a row
 * always carries one — because the retention sweep deletes by disuse rather than
 * by age: a post families still re-read every week should not lose its
 * translation on its 181st day. Its index is what makes that nightly sweep a
 * range scan instead of a table scan, which is only true of a predicate that
 * compares the COLUMN; see ContentTranslation::scopeUnusedSince for why the
 * sweep does not wrap it in a COALESCE, and why `updated_at` is indexed too.
 *
 * A NEW table, so `->constrained()` is safe here — the SQLite rebuild trap
 * (.claude/rules/migrations.md) is about adding a foreign key to an EXISTING
 * table. Both index names are written by hand and are well under MySQL's
 * 64-character identifier cap, which SQLite would not have complained about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();

            // sha256 hex of the source text. char(64), fixed width, because
            // every value is exactly that and a varchar would only invite a
            // truncated one.
            $table->char('source_hash', 64);

            // BCP-47-ish, and short on purpose: `ar` today, room for `ar-EG` or
            // `ur` without a migration. The request refuses anything outside
            // config('translation.languages') long before it reaches here.
            $table->string('target_lang', 8);

            // Provenance: which model produced this wording. Never part of the
            // lookup key — see config/translation.php on why a model change does
            // not invalidate the cache — but without it "which rows predate the
            // model change?" is unanswerable.
            $table->string('model', 64);

            $table->text('translated_text');

            // Nullable, though the application always writes one:
            // AnthropicTranslator::remember() stamps it on create as well as on a
            // hit. It stays nullable for rows this application did not write — a
            // fixture, a hand-repaired row, an importer — and the sweep falls
            // back to `updated_at` for exactly those.
            $table->dateTime('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['masjid_id', 'source_hash', 'target_lang'],
                'content_translations_masjid_hash_lang_unique'
            );

            $table->index(['last_used_at'], 'content_translations_last_used_at_idx');

            // The sweep's second arm — the rows with no `last_used_at` at all —
            // ranges on `updated_at`, so it gets an index for the same reason the
            // first arm has one. An OR whose halves are both indexed is a pair of
            // range scans; an OR with one indexed half is a table scan.
            $table->index(['updated_at'], 'content_translations_updated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_translations');
    }
};
