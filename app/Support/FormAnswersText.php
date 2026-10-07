<?php

namespace App\Support;

use App\Models\Form;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The searchable text of one form response: `form_responses.answers_text`.
 *
 * The admin search has to find a person named INSIDE the answers (a child in an enrolment
 * a parent filled in), and the answers live in a JSON document. Matching that document as
 * text also matches its keys and whatever else is stored beside the answers: "Sara" is
 * part of the key `parent1SpeaksArabic`, "Mai" of `registrantEmail`, and an imported row
 * carries hex digests that contain "ada" and "deb". So the answer VALUES are written out
 * once, here, and the search reads this column (FormResponse::scopeSearch()).
 *
 * WHAT IS IN IT. Only what the form's schema DECLARES, walked the way FormSchema::only()
 * walks it: the questions of each plain section, and of each row of a repeating section.
 *
 *  - a text, number, date, email or phone answer: its value;
 *  - a choose-one answer (select, radio): its value and the LABEL of that option, because
 *    the label is what the family read ("Legal guardian", stored as `guardian`);
 *  - a choose-any answer (checkboxGroup): each value picked and its option's label.
 *
 * WHAT IS NOT. A key the form does not declare (whatever an import or an older version of
 * the form left in the document); a `file` question (the stored value is a file name); a
 * `checkbox` (a tick is yes or no, and "1" in every row is noise); anything that is not a
 * string or a number; and a DIGEST or a payment provider's OBJECT ID, wherever it is
 * stored. The school-website import keeps both in text questions the form does declare
 * (database/forms/alrazi-website-registration.json): a SHA-256 of each document's path
 * (`docBirthCertificateRef`) and the Stripe payment id (`websiteStripePaymentId`). A
 * digest is one long word of hex digits that now and then BEGINS "ada", "abe" or "ed", and
 * every payment id begins "pi", so a short word would find rows at random, or all of
 * them. Nobody types either one to find a family.
 *
 * THE MATCH IS AT THE START OF A WORD, never inside one (decided 2026-10-04). Matched
 * anywhere, a short name returned the whole form at a registration desk: "mai" is in an
 * answer of "email", "ali" in "Somali", "ian" in "guardian". Now "kar" finds "Kareem",
 * and "rahman" finds "Abdul-Rahman" and "al-Rahman" but not "Abdulrahman": a name written
 * as one word is found from its first letters only. The same holds in Arabic, where the
 * article is written joined: "الرحماني" is found by "الرح", not by "رحماني".
 *
 * So the text is WORDS, not values. Lower-cased by lower(); every run of characters that
 * is not a letter, a combining mark or a number character becomes ONE space (a letter
 * keeps its marks, Arabic included); and one space leads, so that every word, the first
 * too, follows a space:
 *
 *     "Samira Al-Nasser", "samira@example.test", "2019-04-02"
 *     " samira al nasser samira example test 2019 04 02"
 *
 * The search splits what was typed with the same function (words()), so "al-rahman",
 * "o'neil" and a whole email address fall into the same pieces as the stored answer did,
 * and looks for `% piece%` for each piece (FormResponse::scopeSearch()). Both sides go
 * through one function, so the match does not depend on the engine: SQLite folds ASCII
 * only, and MySQL's collation is not something a test on SQLite can see. The boundary is
 * a plain space and nothing cleverer for the same reason. On MySQL the column's collation
 * (utf8mb4_unicode_ci) additionally ignores accents, which finds more, never less.
 *
 * WHO WRITES IT. The model, on every save that changes `data` or meets an empty column
 * (FormResponse::booted()), so the submit, the basket, a registration and the website
 * import cannot forget it. Existing rows are filled by the migration that adds the column.
 * `staging:scrub` rewrites `data` without the model: it NULLS this column with the rest of
 * the row (config/staging_scrub.php), or staging would keep every real name, and then
 * fills it again from the scrubbed answers (StagingScrub::rebuildAnswersText()).
 *
 * The text follows the schema AS IT WAS when the row was last written. After a form's
 * questions change (one added that old imported rows already answer, one removed, a
 * label reworded), `php artisan forms:rebuild-answers-text --form=<id> --all` brings its
 * rows up to date.
 */
final class FormAnswersText
{
    public const TABLE = 'form_responses';

    public const COLUMN = 'answers_text';

    /**
     * The most the column is given, in BYTES. A MEDIUMTEXT holds 16,777,215; this is under
     * a quarter of it, and far above any real response (the longest form in database/forms
     * declares about 160 questions, which comes to tens of kilobytes). What is cut is the
     * tail of the text, on a character boundary.
     */
    public const MAX_BYTES = 4_000_000;

    /** The escape character of every LIKE this class builds a pattern for. */
    public const LIKE_ESCAPE = '!';

    /** Question types that are never searched. */
    private const SKIPPED_TYPES = ['file', 'checkbox'];

    /** Question types whose answer is one of the declared options. */
    private const CHOOSE_ONE_TYPES = ['select', 'radio'];

    private const CHOOSE_ANY_TYPE = 'checkboxGroup';

    /**
     * A value that is one run of hex digits this long is a digest (MD5 is 32, SHA-256 is 64),
     * never an answer.
     */
    private const DIGEST = '/^[0-9a-f]{32,}$/i';

    /**
     * A value that is one Stripe object id (`pi_3Qa…`, `cs_live_a1B…`): the prefix of a
     * payment object, then one run of at least 14 letters and digits with a digit in it,
     * never an answer. The digit and the single run are what keep a chosen option such as
     * `in_person_attendance` an answer.
     */
    private const PROVIDER_ID = '/^(?:pi|cs|ch|py|in|seti)_(?:(?:live|test)_)?(?=[A-Za-z]*[0-9])[A-Za-z0-9]{14,}$/';

    private const RECHECK_SECONDS = 30;

    /** @var array{0: bool, 1: int}|null [exists, when it was asked] */
    private static ?array $seen = null;

    /**
     * The text of one response. Pure: the schema's sections and the stored answers in, the
     * text out.
     *
     * @param  array<int,mixed>  $sections  Form::sections()
     * @param  mixed  $data  FormResponse::$data; anything but an array has no answers
     */
    public static function build(array $sections, mixed $data): string
    {
        if (! is_array($data)) {
            return '';
        }

        $parts = [];

        foreach ($sections as $section) {
            $fields = is_array($section) ? ($section['fields'] ?? null) : null;

            if (! is_array($fields)) {
                continue;
            }

            $sectionId = $section['id'] ?? null;

            if (! empty($section['repeatable']) && $sectionId) {
                $rows = $data[$sectionId] ?? null;

                foreach (is_array($rows) ? $rows : [] as $row) {
                    if (is_array($row)) {
                        self::collect($parts, $fields, $row);
                    }
                }

                continue;
            }

            self::collect($parts, $fields, $data);
        }

        $words = self::normalise(implode(' ', $parts));

        // One space leads, so that the first word follows a space as every other does.
        return $words === '' ? '' : mb_strcut(' ' . $words, 0, self::MAX_BYTES, 'UTF-8');
    }

    /**
     * Lower case, for the stored text and for a typed word alike: both sides of the search
     * go through here, so they cannot come to differ.
     *
     * The SIMPLE mapping, one character for one character, as LessonPlan::subjectKeyFor()
     * uses. mb_strtolower's full mapping depends on what is around a letter: a Turkish
     * capital İ becomes "i" plus a combining dot, so "ibrahim" was not part of the stored
     * "İbrahim" and "İBRAHİM" (two dots) was not either; and a Greek capital sigma becomes a
     * different letter at the end of a word than inside one, so a word could lower-case one
     * way alone and another way inside the text.
     */
    public static function lower(string $text): string
    {
        return mb_convert_case($text, MB_CASE_LOWER_SIMPLE, 'UTF-8');
    }

    /**
     * The words of $text, in lower case, with exactly one space between them and none
     * around them: every run of characters that is not a letter, a combining mark or a
     * number character is one space. The marks stay with their letter ("é" typed as "e"
     * and an accent, an Arabic letter and its vowel signs), so a word is never cut inside
     * a character.
     *
     * Text that is not valid UTF-8 is scrubbed first: the pattern would otherwise fail and
     * return nothing at all.
     */
    public static function normalise(string $text): string
    {
        // A combining mark belongs to a word only after a letter or a number. One that
        // follows anything else (the variation selector an emoji carries, typed with no
        // space before the next word) would otherwise be glued to the FRONT of that word,
        // and a word that does not start with its first letter is never found.
        $spaced = preg_replace('/[^\p{L}\p{M}\p{N}]+\p{M}*|^\p{M}+/u', ' ', self::lower(mb_scrub($text, 'UTF-8')));

        return trim((string) $spaced, ' ');
    }

    /**
     * The same words as a list: what the stored text holds for an answer, and what the
     * search looks for when that is typed. "Al-Rahman" is ["al", "rahman"], "O'Neil" is
     * ["o", "neil"], "layla@example.test" is ["layla", "example", "test"], "&" is [].
     *
     * @return array<int,string>
     */
    public static function words(string $text): array
    {
        $words = self::normalise($text);

        return $words === '' ? [] : explode(' ', $words);
    }

    /** The text of a response to $form. A form that is gone declares nothing. */
    public static function for(?Form $form, mixed $data): string
    {
        return self::build($form ? self::labelledSections($form) : [], $data);
    }

    /**
     * The LIKE pattern that finds $word anywhere in a value, with the wildcards a person
     * may have typed made literal: for the respondent's name, email and phone, which are
     * matched in any part as they always were. Used with `LIKE ? ESCAPE '!'`: `!` means
     * the same inside a SQL string on MySQL and on SQLite, where a backslash does not
     * (SQLite has no default escape), so a backslash needs no escaping and is matched as
     * itself.
     */
    public static function like(string $word): string
    {
        return '%' . self::escapeLike($word) . '%';
    }

    /**
     * The LIKE pattern that finds one of words() at the START of a word of the stored
     * text: the space in front is the boundary, and build() puts one before the first
     * word too. A word of words() holds letters, marks and numbers only, so there is no
     * wildcard left in it to escape; it is escaped all the same, for a caller that passes
     * something else.
     */
    public static function likeWordStart(string $word): string
    {
        return '% ' . self::escapeLike($word) . '%';
    }

    /**
     * Whether `migrate` has added the column yet.
     *
     * bin/deploy makes the new code live BEFORE it runs `php artisan migrate`. For that
     * window a save that named the column would lose a family's registration to "unknown
     * column", and a search would answer 500. So the model writes the text, and the search
     * reads it, only once the column is there; until then both behave as they did before it.
     *
     * Memoised per process as App\Support\CartTables is: a column that exists is remembered
     * for good (nothing drops it under running code), one that is missing is asked again
     * after RECHECK_SECONDS (a queue worker that saw the window), and a question that could
     * not be answered is "not yet" for that call only.
     */
    public static function columnExists(): bool
    {
        $now = now()->getTimestamp();

        if (self::$seen !== null && (self::$seen[0] || $now - self::$seen[1] < self::RECHECK_SECONDS)) {
            return self::$seen[0];
        }

        try {
            $exists = Schema::hasColumn(self::TABLE, self::COLUMN);
        } catch (Throwable) {
            return false;
        }

        self::$seen = [$exists, $now];

        return $exists;
    }

    /** Forget what was seen: for a test that drops or adds the column, and for nothing else. */
    public static function forget(): void
    {
        self::$seen = null;
    }

    /**
     * Write the text of rows that are already stored, in chunks by primary key.
     *
     * By default only rows whose text is still NULL, which is what the migration runs and
     * what makes a second run a no-op. With $rebuild every row is recomputed from its
     * form's schema as it is now, and only a row whose text would change is written.
     *
     * Plain query-builder writes: no model event, and `updated_at` is left alone, because
     * nothing about the response changed. A row saved by someone else between the read and
     * the write is not overwritten with text from the answers it had before: the fill only
     * writes over NULL, and the rebuild compares timestamp, exact answers and old index.
     * Inclusive organisation/ID bounds and an optional per-chunk progress callback allow
     * a controlled repair. The callback receives last ID, total writes and skipped IDs.
     *
     * @return int rows written
     */
    public static function fill(?int $formId = null, bool $rebuild = false, int $chunk = 500,
        ?int $masjidId = null, ?int $fromId = null, ?int $toId = null, ?callable $progress = null): int
    {
        $sections = [];
        $written = 0;

        DB::table(self::TABLE)
            ->select('id', 'form_id', 'data', 'updated_at', self::COLUMN)
            ->when($formId !== null, fn ($query) => $query->where('form_id', $formId))
            ->when($masjidId !== null, fn ($query) => $query->where('masjid_id', $masjidId))
            ->when($fromId !== null, fn ($query) => $query->where('id', '>=', $fromId))
            ->when($toId !== null, fn ($query) => $query->where('id', '<=', $toId))
            ->when(! $rebuild, fn ($query) => $query->whereNull(self::COLUMN))
            ->chunkById(max(1, $chunk), function ($rows) use (&$sections, &$written, $rebuild, $progress): void {
                $unknown = $rows->pluck('form_id')->unique()->reject(fn ($id) => isset($sections[$id]))->values();

                if ($unknown->isNotEmpty()) {
                    // Straight from the table, so a soft-deleted form's rows are filled too.
                    foreach (DB::table('forms')->whereIn('id', $unknown->all())->get(['id', 'masjid_id', 'schema', 'settings']) as $row) {
                        $form = new Form;
                        $form->setRawAttributes((array) $row);
                        $sections[$row->id] = self::labelledSections($form);
                    }
                }

                $skipped = [];
                foreach ($rows as $row) {
                    $text = self::build(
                        $sections[$row->form_id] ?? [],
                        is_string($row->data) ? json_decode($row->data, true) : null
                    );

                    if ($row->{self::COLUMN} === $text) {
                        continue;
                    }

                    $update = DB::table(self::TABLE)->where('id', $row->id);

                    if (! $rebuild) {
                        $update->whereNull(self::COLUMN);
                    } elseif ($row->updated_at === null) {
                        $update->whereNull('updated_at');
                    } else {
                        $update->where('updated_at', $row->updated_at);
                    }

                    // Timestamps have second precision. Compare the exact answers too,
                    // so a simultaneous edit or scrub cannot restore stale search text.
                    if ($rebuild) {
                        $update->whereRaw(DB::getDriverName() === 'mysql' ? 'BINARY data = BINARY ?' : 'data = ? COLLATE BINARY', [$row->data]);
                        $update->where(self::COLUMN, $row->{self::COLUMN});
                    }
                    $changed = $update->update([self::COLUMN => $text]);
                    $written += $changed;
                    if ($changed === 0) {
                        $skipped[] = $row->id;
                    }
                }
                if ($progress !== null) {
                    $progress($rows->last()->id, $written, $skipped);
                }
            });

        return $written;
    }

    /** Resolve source wording once per form/source, keeping malformed sections harmless. */
    private static function labelledSections(Form $form): array
    {
        $sections = $form->sections();
        $resolve = FormOptionSources::resolver($form, FormOptionSources::LABEL);
        foreach ($sections as &$section) {
            if (! is_array($section) || ! is_array($section['fields'] ?? null)) {
                continue;
            }
            foreach ($section['fields'] as &$field) {
                if (FormOptionSources::isSourced($field) && in_array($field['type'] ?? null, FormOptionSources::TYPES, true)) {
                    $field['options'] = $resolve($field);
                }
            }
            unset($field);
        }
        unset($section);

        return $sections;
    }

    private static function escapeLike(string $word): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word);
    }

    /**
     * @param  array<int,string>  $parts
     * @param  array<int,mixed>  $fields  one section's declared questions
     * @param  array<string|int,mixed>  $answers  the document, or one row of a repeating section
     */
    private static function collect(array &$parts, array $fields, array $answers): void
    {
        foreach ($fields as $field) {
            $name = is_array($field) ? ($field['name'] ?? null) : null;

            if ((! is_string($name) && ! is_int($name)) || $name === '' || ! array_key_exists($name, $answers)) {
                continue;
            }

            $type = $field['type'] ?? null;

            if (in_array($type, self::SKIPPED_TYPES, true)) {
                continue;
            }

            $value = $answers[$name];

            if ($type === self::CHOOSE_ANY_TYPE) {
                foreach (is_array($value) ? $value : [$value] as $picked) {
                    self::addChoice($parts, $field, $picked);
                }

                continue;
            }

            if (in_array($type, self::CHOOSE_ONE_TYPES, true)) {
                self::addChoice($parts, $field, $value);
            } else {
                self::add($parts, $value);
            }
        }
    }

    /** Match exact choice values; labels remain searchable even when raw codes are filtered. */
    private static function addChoice(array &$parts, array $field, mixed $value): void
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || $value === '') {
            return;
        }

        self::add($parts, $value);

        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && is_scalar($option['value'] ?? null) && (string) $option['value'] === (string) $value) {
                self::add($parts, $option['label'] ?? null);

                break;
            }
        }
    }

    /**
     * Add one answer when it is a non-blank string or a number, and neither a digest nor a
     * payment provider's object id. True when it was added.
     *
     * @param  array<int,string>  $parts
     */
    private static function add(array &$parts, mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return false;
        }

        $text = trim((string) $value);

        if ($text === '' || preg_match(self::DIGEST, $text) === 1 || preg_match(self::PROVIDER_ID, $text) === 1) {
            return false;
        }

        $parts[] = $text;

        return true;
    }
}
