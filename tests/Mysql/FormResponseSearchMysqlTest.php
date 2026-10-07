<?php

/*
|--------------------------------------------------------------------------
| The Form Responses search, on the engine production runs
|--------------------------------------------------------------------------
|
| tests/Feature/FormResponseSearchTest.php runs on SQLite, which cannot show what this
| search depends on in production:
|
|  - the TYPE of form_responses.answers_text. SQLite's text has no width, so a column
|    created as TEXT (65,535 bytes) would pass every test there and then refuse a long
|    enrolment in MySQL's strict mode, losing the registration;
|  - that a name in Arabic, or with an accent, is found. SQLite folds ASCII only and
|    compares bytes; MySQL compares under the column's collation, and before this column
|    the two engines did not even store the same text to compare;
|  - that a word is matched at the START of a word of the answers and never inside one.
|    The boundary is the space in `LIKE '% word%'`, and whether a space in a pattern is
|    compared as a space is the collation's to decide (ASSUMPTIONS.md F-7);
|  - that `LIKE ? ESCAPE '!'` treats a typed `%`, `_`, `!` and backslash as themselves in
|    the respondent's name. MySQL's default escape is the backslash and SQLite has none
|    (ASSUMPTIONS.md S-14);
|  - that the backfill reads a JSON column as MySQL returns it.
|
| Runs only in CI's migrations-mysql job, as `pest --group=mysql`.
*/

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Support\FormAnswersText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function searchMysqlForm(): Form
{
    $org = Masjid::create([
        'name' => 'Search MySQL Org ' . uniqid(),
        'org_type' => 'school',
        'email' => 'search-' . uniqid() . '@example.test',
        'phone' => '+1555' . random_int(1000000, 9999999),
        'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
        'latitude' => 0.0, 'longitude' => 0.0,
    ]);

    return Form::create([
        'masjid_id' => $org->id,
        'slug' => 'enrolment-' . uniqid(),
        'name' => 'Enrolment',
        'schema' => ['sections' => [
            ['id' => 'registrant', 'title' => 'Your Information', 'fields' => [
                ['name' => 'registrantName', 'label' => 'Full name', 'type' => 'text'],
                ['name' => 'registrantEmail', 'label' => 'Email', 'type' => 'email'],
                ['name' => 'preferredContact', 'label' => 'How should we reach you', 'type' => 'radio', 'options' => [
                    ['value' => 'email', 'label' => 'Email'],
                    ['value' => 'phone', 'label' => 'Phone call'],
                ]],
                ['name' => 'otherParentEmail', 'label' => 'The other parent\'s email', 'type' => 'email'],
                ['name' => 'waiverAgreement', 'label' => 'I agree to the waiver', 'type' => 'checkbox'],
                ['name' => 'notes', 'label' => 'Anything else', 'type' => 'textarea'],
                ['name' => 'idDocumentRef', 'label' => 'Identity document: website reference', 'type' => 'text'],
                ['name' => 'websitePaymentId', 'label' => 'Payment id', 'type' => 'text'],
            ]],
            ['id' => 'children', 'title' => 'Children', 'repeatable' => true, 'fields' => [
                ['name' => 'firstName', 'label' => 'First name', 'type' => 'text'],
                ['name' => 'lastName', 'label' => 'Last name', 'type' => 'text'],
                ['name' => 'age', 'label' => 'Age', 'type' => 'number'],
            ]],
        ]],
        'settings' => ['identity' => ['name' => 'registrantName', 'email' => 'registrantEmail']],
    ]);
}

/** The answers of one enrolment, with what an import stores beside them. */
function searchMysqlAnswers(string $parent, string $email, array $children, array $answers = []): array
{
    return [
        'registrantName' => $parent,
        'registrantEmail' => $email,
        'children' => $children,
        'waiverAgreement' => true,
        'parent1SpeaksArabic' => true,
        'idDocumentRef' => '5e1c0ffee7ada90deb42f00dbabe1ada77c0de5e1c0ffee7ada90deb42f00d00',
        'websitePaymentId' => 'pi_3QbKiW2eZvKYlo2C1mAiR7uV',
    ] + $answers;
}

function searchMysqlEnrol(Form $form, string $parent, string $email, array $children, array $answers = []): FormResponse
{
    return FormResponse::create([
        'form_id' => $form->id,
        'masjid_id' => $form->masjid_id,
        'data' => searchMysqlAnswers($parent, $email, $children, $answers),
        'respondent_name' => $parent,
        'respondent_email' => $email,
        'entry_count' => max(1, count($children)),
        'status' => 'new',
        'submitted_at' => '2026-07-09 12:00:00',
    ]);
}

/** The respondents the search scope finds on this form, in a fixed order. */
function searchMysqlFind(Form $form, string $term): array
{
    return FormResponse::query()
        ->where('form_id', $form->id)
        ->search($term)
        ->orderBy('respondent_email')
        ->pluck('respondent_email')
        ->all();
}

it('stores the answers text in a nullable MEDIUMTEXT wider than the most the writer gives it', function () {
    $column = DB::selectOne(
        "SELECT DATA_TYPE AS type, IS_NULLABLE AS nullable, CHARACTER_OCTET_LENGTH AS bytes FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'form_responses' AND COLUMN_NAME = 'answers_text'"
    );

    expect($column->type)->toBe('mediumtext')
        ->and($column->nullable)->toBe('YES')
        ->and((int) $column->bytes)->toBeGreaterThan(FormAnswersText::MAX_BYTES);
});

it('keeps an enrolment whose answers are longer than a TEXT column holds', function () {
    $form = searchMysqlForm();

    // 80,000 bytes of two-byte letters: past TEXT's 65,535, which strict mode refuses.
    $notes = str_repeat('م', 40_000);
    $row = searchMysqlEnrol($form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Tariq', 'lastName' => 'Rahmani']], ['notes' => $notes]);

    $stored = DB::table('form_responses')->where('id', $row->id)->value('answers_text');

    expect($stored)->toBe(" samira nasser four example test {$notes} tariq rahmani")
        ->and(searchMysqlFind($form, 'Tariq'))->toBe(['four@example.test']);
});

it('finds a child named in Arabic or with an accent, as typed and in another letter case', function () {
    $form = searchMysqlForm();
    searchMysqlEnrol($form, 'Maryam Idris', 'one@example.test', [['firstName' => 'Yunus', 'lastName' => 'Karimi']]);
    searchMysqlEnrol($form, 'Samira Nasser', 'four@example.test', [['firstName' => 'طارق', 'lastName' => 'الرحماني']]);
    searchMysqlEnrol($form, 'Nadia Farouk', 'five@example.test', [['firstName' => 'Émile', 'lastName' => 'Benoît']]);

    expect(searchMysqlFind($form, 'طارق'))->toBe(['four@example.test'])
        ->and(searchMysqlFind($form, 'الرحماني طارق'))->toBe(['four@example.test'])
        // From the start of the word: the article is written joined, so the name without
        // it is the inside of a word.
        ->and(searchMysqlFind($form, 'الرح'))->toBe(['four@example.test'])
        ->and(searchMysqlFind($form, 'رحماني'))->toBe([])
        ->and(searchMysqlFind($form, 'Émile'))->toBe(['five@example.test'])
        ->and(searchMysqlFind($form, 'émile'))->toBe(['five@example.test'])
        ->and(searchMysqlFind($form, 'ÉMILE BENOÎT'))->toBe(['five@example.test'])
        ->and(searchMysqlFind($form, 'Yunus Karimi'))->toBe(['one@example.test']);
});

it('finds a name with a Turkish dotted capital I however the i is typed', function () {
    $form = searchMysqlForm();
    searchMysqlEnrol($form, 'Maryam Idris', 'one@example.test', [['firstName' => 'Yunus', 'lastName' => 'Karimi']]);
    $row = searchMysqlEnrol($form, 'Zehra Demir', 'six@example.test', [['firstName' => 'İbrahim', 'lastName' => 'Yılmaz']]);

    // One character for one character (FormAnswersText::lower()): the full mapping would
    // store "i" and a combining dot, and whether the plain word is then part of the text
    // would be the collation's to decide.
    expect(DB::table('form_responses')->where('id', $row->id)->value('answers_text'))
        ->toBe(' zehra demir six example test ibrahim yılmaz')
        ->and(searchMysqlFind($form, 'ibrahim'))->toBe(['six@example.test'])
        ->and(searchMysqlFind($form, 'Ibrahim'))->toBe(['six@example.test'])
        ->and(searchMysqlFind($form, 'İbrahim'))->toBe(['six@example.test'])
        ->and(searchMysqlFind($form, 'İBRAHİM'))->toBe(['six@example.test'])
        ->and(searchMysqlFind($form, 'ibrahim yılmaz'))->toBe(['six@example.test']);
});

it('finds only its own row for a name that is part of a question key or of a stored digest', function () {
    $form = searchMysqlForm();
    searchMysqlEnrol($form, 'Maryam Idris', 'one@example.test', [['firstName' => 'Reem', 'lastName' => 'Saleh', 'age' => 6]]);
    searchMysqlEnrol($form, 'Hana Odeh', 'two@example.test', [['firstName' => 'Ada', 'lastName' => 'Qasim', 'age' => 7]]);
    $third = searchMysqlEnrol($form, 'Yusuf Karimi', 'three@example.test', [['firstName' => 'Yunus', 'lastName' => 'Karimi', 'age' => 9]]);

    // "reem" is in `waiverAgreement`, "ada" in the digest (a text question the form
    // declares), "sara" in `parent1SpeaksArabic`, "mai" in `registrantEmail` and in the
    // payment id (another declared text question): all of them in every row's JSON, none
    // of them an answer.
    expect(searchMysqlFind($form, 'Reem'))->toBe(['one@example.test'])
        ->and(searchMysqlFind($form, 'Ada'))->toBe(['two@example.test'])
        ->and(searchMysqlFind($form, 'Sara'))->toBe([])
        ->and(searchMysqlFind($form, 'Mai'))->toBe([])
        // A number is looked for in the answers beside a name, and never on its own.
        ->and(searchMysqlFind($form, 'Yunus 9'))->toBe(['three@example.test'])
        ->and(searchMysqlFind($form, 'Yunus 6'))->toBe([])
        ->and(searchMysqlFind($form, '#' . $third->id))->toBe(['three@example.test']);
});

it('matches a word at the start of a word of the answers and never inside one', function () {
    $form = searchMysqlForm();
    searchMysqlEnrol($form, 'Maryam Idris', 'one@example.test', [['firstName' => 'Yunus', 'lastName' => 'Karimi']]);
    searchMysqlEnrol($form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Ali', 'lastName' => 'Hamdan']]);
    searchMysqlEnrol($form, 'Nadia Farouk', 'five@example.test', [['firstName' => 'Layla', 'lastName' => 'Abdulrahman', 'age' => 8]], [
        'preferredContact' => 'email',
        'notes' => 'Somali at home. Lives with her guardian.',
    ]);
    $six = searchMysqlEnrol($form, 'Huda Mansour', 'six@example.test', [
        ['firstName' => 'Maimuna', 'lastName' => 'Abdul-Rahman'],
        ['firstName' => 'Kareem', 'lastName' => 'al-Rahman'],
    ], ['otherParentEmail' => 'Kareem.Haddad@family.example']);
    searchMysqlEnrol($form, 'Noor Aziz', 'seven@example.test', [['firstName' => 'Ian', 'lastName' => "O'Neil"]]);

    // The text the patterns are matched against: words, one space before each.
    expect(DB::table('form_responses')->where('id', $six->id)->value('answers_text'))
        ->toBe(' huda mansour six example test kareem haddad family example maimuna abdul rahman kareem al rahman')
        // "mai" is inside "email", "ali" inside "Somali", "ian" inside "guardian".
        ->and(searchMysqlFind($form, 'Mai'))->toBe(['six@example.test'])
        ->and(searchMysqlFind($form, 'Ali'))->toBe(['four@example.test'])
        ->and(searchMysqlFind($form, 'Ian'))->toBe(['seven@example.test'])
        ->and(searchMysqlFind($form, 'Somali'))->toBe(['five@example.test'])
        // The first letters of a name, and the first word of the text.
        ->and(searchMysqlFind($form, 'kar'))->toBe(['one@example.test', 'six@example.test'])
        ->and(searchMysqlFind($form, 'hud'))->toBe(['six@example.test'])
        ->and(searchMysqlFind($form, 'areem'))->toBe([])
        // A hyphenated name is its parts; written as one word it is found from its start.
        ->and(searchMysqlFind($form, 'rahman'))->toBe(['six@example.test'])
        ->and(searchMysqlFind($form, 'al-rahman'))->toBe(['six@example.test'])
        ->and(searchMysqlFind($form, 'abdulrahman'))->toBe(['five@example.test'])
        // An apostrophe divides a name the same way.
        ->and(searchMysqlFind($form, "o'neil"))->toBe(['seven@example.test'])
        ->and(searchMysqlFind($form, 'oneil'))->toBe([])
        // An email address typed whole, and a number beside a name.
        ->and(searchMysqlFind($form, 'kareem.haddad@family.example'))->toBe(['six@example.test'])
        ->and(searchMysqlFind($form, 'Layla 8'))->toBe(['five@example.test'])
        ->and(searchMysqlFind($form, 'Layla 9'))->toBe([])
        // The name, email and phone columns are matched in any part, as before.
        ->and(searchMysqlFind($form, 'our@exam'))->toBe(['four@example.test']);
});

it('never treats a typed percent sign, underscore, exclamation mark or backslash as a wildcard or an escape', function () {
    $form = searchMysqlForm();
    searchMysqlEnrol($form, 'Maryam Idris', 'one@example.test', [['firstName' => 'Yunus', 'lastName' => 'Karimi']]);
    $row = searchMysqlEnrol($form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Layla', 'lastName' => 'Rahmani']], [
        'notes' => '100% attendance_award! room a\\b',
    ]);
    // The name, email and phone are matched as typed, so the characters have to be there.
    $row->update(['respondent_name' => 'Desk 100%_x! a\\b']);

    // Each would be "anything" to LIKE, and so every row.
    expect(searchMysqlFind($form, '%'))->toBe(['four@example.test'])
        ->and(searchMysqlFind($form, '_'))->toBe(['four@example.test'])
        ->and(searchMysqlFind($form, 'M_ryam'))->toBe([])
        ->and(searchMysqlFind($form, 'Desk 100%_x!'))->toBe(['four@example.test'])
        ->and(searchMysqlFind($form, 'Desk 100%%x'))->toBe([])
        ->and(searchMysqlFind($form, 'Desk a\\b'))->toBe(['four@example.test'])
        ->and(searchMysqlFind($form, 'Desk a\\c'))->toBe([])
        // In the answers each is one more character between words.
        ->and(searchMysqlFind($form, 'Layla 100%'))->toBe(['four@example.test'])
        ->and(searchMysqlFind($form, 'attendance_award!'))->toBe(['four@example.test'])
        ->and(searchMysqlFind($form, 'Layla 1_9'))->toBe([]);
});

it('fills rows stored before the column from the JSON as MySQL returns it, once', function () {
    $form = searchMysqlForm();

    DB::table('form_responses')->insert([
        'form_id' => $form->id,
        'masjid_id' => $form->masjid_id,
        'uuid' => (string) Str::uuid(),
        'data' => json_encode(searchMysqlAnswers('Samira Nasser', 'four@example.test', [['firstName' => 'طارق', 'lastName' => 'Rahmani']])),
        'respondent_name' => 'Samira Nasser',
        'respondent_email' => 'four@example.test',
        'entry_count' => 1,
        'status' => 'new',
        'submitted_at' => '2026-07-09 12:00:00',
        'created_at' => '2026-07-09 12:00:00',
        'updated_at' => '2026-07-09 12:00:00',
    ]);

    expect(searchMysqlFind($form, 'طارق'))->toBe([]);

    expect(FormAnswersText::fill($form->id))->toBe(1)
        ->and(searchMysqlFind($form, 'طارق'))->toBe(['four@example.test'])
        ->and(FormAnswersText::fill($form->id))->toBe(0)
        ->and(DB::table('form_responses')->where('form_id', $form->id)->value('answers_text'))
        ->toBe(' samira nasser four example test طارق rahmani');
});

it('rebuilds choice labels within an organisation and ID range without changing other columns', function () {
    $form = searchMysqlForm();
    $schema = $form->schema;
    $schema['sections'][0]['fields'][] = ['name' => 'help', 'label' => 'Help', 'type' => 'checkboxGroup',
        'options' => [['value' => 'settingUp', 'label' => 'Setting up']]];
    $form->update(['schema' => $schema]);
    $row = searchMysqlEnrol($form, 'Test Parent', 'test@example.test', [], ['help' => ['settingUp', 'removedOption']]);
    DB::table('form_responses')->where('id', $row->id)->update(['answers_text' => ' settingup removedoption']);
    $before = (array) DB::table('form_responses')->where('id', $row->id)->first();
    expect(searchMysqlFind($form, 'Setting up'))->toBe([]);
    $options = ['--all' => true, '--masjid' => $form->masjid_id,
        '--from-id' => $row->id, '--to-id' => $row->id, '--chunk' => 1];
    $this->artisan('forms:rebuild-answers-text', $options)->expectsOutputToContain('1 form response(s)')->assertSuccessful();
    expect(searchMysqlFind($form, 'Setting up'))->toBe(['test@example.test'])
        ->and(searchMysqlFind($form, 'removedOption'))->toBe(['test@example.test']);
    $after = (array) DB::table('form_responses')->where('id', $row->id)->first();
    unset($before['answers_text'], $after['answers_text']);
    expect($after)->toBe($before);
    $this->artisan('forms:rebuild-answers-text', $options)->expectsOutputToContain('0 form response(s)')->assertSuccessful();
});
