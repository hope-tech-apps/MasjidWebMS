<?php

use App\Support\FormAnswersText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_responses.answers_text
 *
 * The admin search looks for a person named inside the answers (a child in an enrolment
 * their parent submitted). The answers are a JSON document, and matching that document
 * as text also matches its keys and the digests an import stores beside the answers, so
 * an ordinary first name found every row. This column holds the WORDS of the answers to
 * the form's declared questions, lower-cased, with one space before each
 * (App\Support\FormAnswersText); the FormResponse model keeps it in step with `data` on
 * every save, and the search reads it, matching a typed word at the start of a word.
 *
 * MEDIUMTEXT, because its length is decided by what families type and how many children
 * they enrol: the writer caps it at FormAnswersText::MAX_BYTES, well inside the type. On
 * SQLite the type is plain text and enforces nothing; tests/Mysql pins the real one.
 * Nullable: NULL means "not written yet", which is how the model and the backfill know a
 * row still needs its text (an answered form with nothing searchable holds '').
 *
 * NO INDEX. The search is a `LIKE '% word%'`, which no B-tree serves, inside one form's
 * rows, which the existing (masjid_id, form_id, submitted_at) index already narrows to.
 *
 * Backfill: existing rows are written here, so the search is right as soon as migrate
 * ends. In chunks by primary key and only over rows still NULL, so a run that was
 * interrupted, or run twice, picks up where it stopped. The same code is
 * `php artisan forms:rebuild-answers-text`. Nothing here is raw SQL, so it runs unchanged
 * on MySQL and on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL commits the ALTER on its own, so a run that stopped during the backfill
        // comes back here with the column already there.
        if (! Schema::hasColumn(FormAnswersText::TABLE, FormAnswersText::COLUMN)) {
            Schema::table(FormAnswersText::TABLE, function (Blueprint $table) {
                $table->mediumText(FormAnswersText::COLUMN)->nullable()->after('data');
            });
        }

        // The model asks once per process whether the column is there.
        FormAnswersText::forget();

        FormAnswersText::fill();
    }

    public function down(): void
    {
        if (Schema::hasColumn(FormAnswersText::TABLE, FormAnswersText::COLUMN)) {
            Schema::table(FormAnswersText::TABLE, function (Blueprint $table) {
                $table->dropColumn(FormAnswersText::COLUMN);
            });
        }

        // The model asks once per process whether the column is there.
        FormAnswersText::forget();
    }
};
