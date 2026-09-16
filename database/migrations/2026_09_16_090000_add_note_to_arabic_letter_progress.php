<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher's words about one child on one drill.
 *
 * WHY THIS RIDES THE EXISTING ROW RATHER THAN GETTING ITS OWN TABLE
 * -----------------------------------------------------------------
 * `arabic_letter_progress` is already one row per (student, alphabet, drill),
 * written through an upsert. A note about that drill is a property of that
 * record, not a separate event: there is no version of "what the teacher said
 * about her ḥarf ba" that is meaningful apart from her status on ḥarf ba. So it
 * is a column, the upsert already carries it, and no new uniqueness question
 * arises.
 *
 * NULLABLE, AND NOT BACKFILLED. Every existing row pre-dates the field and a
 * teacher said nothing about those drills — which is different from having said
 * nothing of note. An empty string would assert the second; NULL asserts only
 * that nobody has written here.
 *
 * `text`, not `string`. The repository has been bitten by a varchar(255) that
 * MySQL rejected and SQLite accepted silently, in a column holding prose a
 * person types. A teacher writing about a child's pronunciation is prose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arabic_letter_progress', function (Blueprint $table) {
            $table->text('note')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('arabic_letter_progress', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
