<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line on a report card: a criterion, and the level a teacher gave it.
 *
 * ---------------------------------------------------------------------------
 * SUBJECT AND CRITERION ARE TEXT, ON PURPOSE
 * ---------------------------------------------------------------------------
 *
 * The obvious design references a template row by id and renders the wording at
 * read time. That is wrong for this document. A report card is something a
 * family keeps, prints, and may produce two years later; if the school rewords
 * "Applies manners (adab) independently" in March, every card issued in
 * December must still read as it read in December. Storing the words is what
 * makes the record a record.
 *
 * The cost — the same criterion appearing as slightly different strings across
 * years — is real and is the correct trade. App\Support\ReportCardTemplate is
 * the source these strings are COPIED from, not joined to.
 *
 * ---------------------------------------------------------------------------
 * `level` is nullable, and null is not zero
 * ---------------------------------------------------------------------------
 *
 * An unmarked criterion is the ABSENCE of a judgement — the same distinction the
 * register makes between unmarked and absent, and the gradebook between no row
 * and a zero. A teacher part-way through a card has nulls; a published card with
 * nulls is a card that says "not assessed this quarter", which is a real thing to
 * say about a child who joined in week eight.
 *
 * `kind` separates the academic criteria from the learning behaviours, because
 * mixing "works well with others" into a subject average is how a quiet child
 * ends up with a lower mark in Mathematics than their maths deserves. They are
 * reported alongside, never inside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_marks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('report_card_id')->constrained('report_cards')->cascadeOnDelete();

            // 'academic' or 'behaviour'.
            $table->string('kind', 16)->default('academic');

            $table->string('subject', 120);
            $table->string('criterion', 200);

            // 1-4 on App\Support\PerformanceLevel, or NULL for not assessed.
            $table->unsignedTinyInteger('level')->nullable();

            $table->text('comment')->nullable();

            // Keeps the printed order stable regardless of insertion or of how
            // the database chooses to return rows.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // One row per criterion per card. Makes re-saving a card an UPDATE
            // rather than a second set of marks, which is what a teacher editing
            // a draft over several days actually does.
            $table->unique(
                ['report_card_id', 'subject', 'criterion'],
                'report_card_mark_unique'
            );

            $table->index(['report_card_id', 'position'], 'report_card_mark_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_marks');
    }
};
