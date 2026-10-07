<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\User;
use App\Support\FormAnswersText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Form Responses search, inside the answers.
 *
 * An enrolment is submitted by a parent and names the children in a repeating section,
 * so the office has to be able to find a child. The search reads `answers_text`, the
 * WORDS of the answers to the form's declared questions (App\Support\FormAnswersText),
 * and never the JSON document as text: that holds every question's key and whatever an
 * import stored beside the answers, and an ordinary first name found every row.
 *
 * In the answers a typed word is matched at the START of a word, never inside one, so
 * "mai" does not find every row that answered "email". The respondent's name, email and
 * phone are matched in any part, as they always were.
 *
 * The form here is shaped to make that visible. Every row carries a declared key that
 * contains "reem" (`waiverAgreement`), one that contains "mai" (`registrantEmail`), an
 * undeclared key that contains "sara", and two digests that contain "ada": one under a
 * key the form does not declare, and one in a text question it does declare, which is
 * where the school-website import keeps the reference of each uploaded document. It also
 * carries, in a declared text question as that import does, a payment id whose random
 * letters contain "mai".
 *
 * SQLite only. The column's real type and the same search on MySQL are pinned by
 * tests/Mysql/FormResponseSearchMysqlTest.php.
 */
class FormResponseSearchTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Masjid $otherMasjid;
    private Form $form;
    private Form $otherForm;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->masjid = $this->makeMasjid();
        $this->otherMasjid = $this->makeMasjid();

        $this->form = $this->makeForm($this->masjid, 'enrolment');
        $this->otherForm = $this->makeForm($this->otherMasjid, 'other-enrolment');

        $this->admin = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        // One case drops the column; what it saw must not outlive it in this process.
        FormAnswersText::forget();

        parent::tearDown();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        return [
            'sections' => [
                [
                    'id' => 'registrant',
                    'title' => 'Your Information',
                    'fields' => [
                        ['name' => 'registrantName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                        ['name' => 'registrantEmail', 'label' => 'Email', 'type' => 'email', 'required' => true],
                        ['name' => 'relationship', 'label' => 'Relationship to the children', 'type' => 'select', 'options' => [
                            ['value' => 'father', 'label' => 'Father'],
                            ['value' => 'mother', 'label' => 'Mother'],
                            ['value' => 'guardian', 'label' => 'Legal guardian'],
                        ]],
                        ['name' => 'helpWith', 'label' => 'I can help with', 'type' => 'checkboxGroup', 'options' => [
                            ['value' => 'carpool', 'label' => 'Driving'],
                            ['value' => 'cleanup', 'label' => 'Tidying up'],
                        ]],
                        ['name' => 'preferredContact', 'label' => 'How should we reach you', 'type' => 'radio', 'options' => [
                            ['value' => 'email', 'label' => 'Email'],
                            ['value' => 'phone', 'label' => 'Phone call'],
                        ]],
                        ['name' => 'otherParentEmail', 'label' => 'The other parent\'s email', 'type' => 'email'],
                        ['name' => 'waiverAgreement', 'label' => 'I agree to the waiver', 'type' => 'checkbox'],
                        ['name' => 'immunisationRecord', 'label' => 'Immunisation record', 'type' => 'file'],
                        ['name' => 'immunisationRecordRef', 'label' => 'Immunisation record: website reference', 'type' => 'text'],
                        ['name' => 'websitePaymentId', 'label' => 'Payment id', 'type' => 'text'],
                    ],
                ],
                [
                    'id' => 'children',
                    'title' => 'Children',
                    'repeatable' => true,
                    'minEntries' => 1,
                    'fields' => [
                        ['name' => 'firstName', 'label' => 'First name', 'type' => 'text', 'required' => true],
                        ['name' => 'lastName', 'label' => 'Last name', 'type' => 'text', 'required' => true],
                        ['name' => 'age', 'label' => 'Age', 'type' => 'number'],
                        ['name' => 'dob', 'label' => 'Date of birth', 'type' => 'date'],
                        ['name' => 'grade', 'label' => 'Grade', 'type' => 'text'],
                    ],
                ],
            ],
        ];
    }

    private function makeForm(Masjid $masjid, string $slug): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => $slug,
            'name' => 'Enrolment',
            'schema' => $this->schema(),
            'settings' => [
                'identity' => ['name' => 'registrantName', 'email' => 'registrantEmail'],
            ],
        ]);
    }

    /**
     * What every row stores: the declared tick (its key contains "reem"), and beside the
     * answers what an import leaves there, none of which the form declares.
     *
     * @return array<string,mixed>
     */
    private function beside(): array
    {
        return [
            'waiverAgreement' => true,
            'parent1SpeaksArabic' => true,
            'idDocumentRef' => '5e1c0ffee7ada90deb42f00dbabe1ada77c0de5e1c0ffee7ada90deb42f00d00',
            'immunisationRecordRef' => 'babe1ada77c0de5e1c0ffee7ada90deb42f00d005e1c0ffee7ada90deb42f00d',
            'websitePaymentId' => 'pi_3QbKiW2eZvKYlo2C1mAiR7uV',
            'websiteNote' => 'Checked by Sara at the office',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $children
     * @param  array<string,mixed>  $answers  more answers of the plain section
     * @return array<string,mixed>
     */
    private function answers(string $parent, string $email, array $children, array $answers = []): array
    {
        return ['registrantName' => $parent, 'registrantEmail' => $email, 'children' => $children] + $answers + $this->beside();
    }

    /**
     * @param  array<int,array<string,mixed>>  $children
     * @param  array<string,mixed>  $answers
     */
    private function enrol(Form $form, string $parent, string $email, array $children, array $answers = []): FormResponse
    {
        return FormResponse::create([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => $this->answers($parent, $email, $children, $answers),
            'respondent_name' => $parent,
            'respondent_email' => $email,
            'entry_count' => max(1, count($children)),
            'amount_due' => 30,
            'status' => 'new',
            'submitted_at' => '2026-07-09 12:00:00',
        ]);
    }

    /**
     * A row as it was stored before the column existed, or by anything that goes round
     * the model: no text.
     */
    private function storeWithoutTheModel(Form $form, string $parent, string $email, string $child, array $answers = []): void
    {
        DB::table('form_responses')->insert([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'uuid' => (string) Str::uuid(),
            'data' => json_encode($this->answers($parent, $email, [['firstName' => $child, 'lastName' => 'Rahmani']]) + $answers),
            'respondent_name' => $parent,
            'respondent_email' => $email,
            'entry_count' => 1,
            'status' => 'new',
            'submitted_at' => '2026-07-09 12:00:00',
            'created_at' => '2026-07-09 12:00:00',
            'updated_at' => '2026-07-09 12:00:00',
        ]);
    }

    private function answersTextMigration(): Migration
    {
        $paths = glob(database_path('migrations/*_add_answers_text_to_form_responses_table.php'));

        $this->assertCount(1, $paths, 'the answers_text migration is missing');

        // `require`, not require_once: a fresh instance of the anonymous class each time,
        // as Laravel's migrator gets.
        $migration = require $paths[0];

        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /** Three families, one of them with a child called Reem. */
    private function threeFamilies(): void
    {
        $this->enrol($this->form, 'Maryam Idris', 'one@example.test', [['firstName' => 'Reem', 'lastName' => 'Saleh', 'age' => 6]]);
        $this->enrol($this->form, 'Hana Odeh', 'two@example.test', [['firstName' => 'Ada', 'lastName' => 'Qasim', 'age' => 7]]);
        $this->enrol($this->form, 'Yusuf Karimi', 'three@example.test', [
            ['firstName' => 'Yunus', 'lastName' => 'Karimi', 'age' => 9],
            ['firstName' => 'Bushra', 'lastName' => 'Karimi', 'age' => 11],
        ]);
    }

    private function url(string $suffix = '', ?Form $form = null): string
    {
        $form ??= $this->form;

        return "/api/admin/masjids/{$form->masjid_id}/forms/{$form->id}/responses{$suffix}";
    }

    /**
     * The respondents the list returns for a search, in a fixed order.
     *
     * @return array<int,string>
     */
    private function find(string $q): array
    {
        return collect($this->getJson($this->url('?q=' . rawurlencode($q)))->assertOk()->json('data.data'))
            ->pluck('respondent_email')
            ->sort()
            ->values()
            ->all();
    }

    // ------------------------------------------------------------ what is found

    #[Test]
    public function a_child_named_in_the_answers_is_found_by_any_of_their_names_in_any_order(): void
    {
        $this->threeFamilies();
        $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [
            ['firstName' => 'Tariq', 'lastName' => 'Rahmani', 'age' => 5],
            ['firstName' => 'Layla', 'lastName' => 'Rahmani', 'age' => 8],
        ]);

        // The child's first name, in any letter case.
        $this->assertSame(['four@example.test'], $this->find('Tariq'));
        $this->assertSame(['four@example.test'], $this->find('tariq'));
        $this->assertSame(['four@example.test'], $this->find('TARIQ'));
        // The full name is two answers, so every word is looked for on its own, in any order.
        $this->assertSame(['four@example.test'], $this->find('Tariq Rahmani'));
        $this->assertSame(['four@example.test'], $this->find('rahmani layla'));
        // A word from the parent and a word from a child.
        $this->assertSame(['four@example.test'], $this->find('Samira Tariq'));
        // Every word must be there: one that is nowhere finds nothing.
        $this->assertSame([], $this->find('Tariq Nobody'));
        // The parent is still found as before.
        $this->assertSame(['four@example.test'], $this->find('four@'));
    }

    #[Test]
    public function a_name_that_is_part_of_a_question_key_or_of_something_stored_beside_the_answers_finds_only_its_own_row(): void
    {
        $this->threeFamilies();

        // "reem" is in the declared key `waiverAgreement`, which every row has.
        $this->assertSame(['one@example.test'], $this->find('Reem'));
        // "ada" is in both digests every row carries: the one under a key the form does not
        // declare, and the one in a text question it does.
        $this->assertSame(['two@example.test'], $this->find('Ada'));
        // In a declared key (`registrantEmail`), in the payment id every row carries in a
        // declared text question, and in no answer.
        $this->assertSame([], $this->find('Mai'));
        $this->assertSame([], $this->find('pi_3QbKiW'));
        // In an undeclared key (`parent1SpeaksArabic`) and in an undeclared value.
        $this->assertSame([], $this->find('Sara'));
        // The rest of the digest, and the tick itself.
        $this->assertSame([], $this->find('c0ffee'));
        $this->assertSame([], $this->find('true'));
    }

    #[Test]
    public function a_name_in_arabic_or_with_an_accent_is_found_as_typed(): void
    {
        $this->threeFamilies();
        $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [['firstName' => 'طارق', 'lastName' => 'الرحماني']]);
        $this->enrol($this->form, 'Nadia Farouk', 'five@example.test', [['firstName' => 'Émile', 'lastName' => 'Benoît']]);

        $this->assertSame(['four@example.test'], $this->find('طارق'));
        $this->assertSame(['four@example.test'], $this->find('الرحماني طارق'));
        // From the start of the word, as in any script: the article is written joined, so
        // the name without it is the inside of a word.
        $this->assertSame(['four@example.test'], $this->find('طار'));
        $this->assertSame(['four@example.test'], $this->find('الرح'));
        $this->assertSame([], $this->find('رحماني'));

        $this->assertSame(['five@example.test'], $this->find('Émile'));
        $this->assertSame(['five@example.test'], $this->find('émile'));
        $this->assertSame(['five@example.test'], $this->find('ÉMILE BENOÎT'));
    }

    #[Test]
    public function a_name_with_a_turkish_dotted_capital_i_is_found_however_the_i_is_typed(): void
    {
        $this->threeFamilies();
        $this->enrol($this->form, 'Zehra Demir', 'six@example.test', [['firstName' => 'İbrahim', 'lastName' => 'Yılmaz']]);

        // The full lower-case mapping stores "i" and a combining dot, which the plain
        // "ibrahim" is no part of, and gives the all-capitals word two of them.
        $this->assertSame(['six@example.test'], $this->find('ibrahim'));
        $this->assertSame(['six@example.test'], $this->find('Ibrahim'));
        $this->assertSame(['six@example.test'], $this->find('İbrahim'));
        $this->assertSame(['six@example.test'], $this->find('İBRAHİM'));
        $this->assertSame(['six@example.test'], $this->find('ibrahim yılmaz'));
    }

    #[Test]
    public function punctuation_typed_as_a_word_of_its_own_is_a_separator_not_a_word_to_find(): void
    {
        // The answers are stored as words, with no punctuation left. A typed "&", "-" or
        // "/" standing alone used to be required as a word and found in no row, so an
        // answer typed back exactly as it was given returned nothing.
        $this->enrol($this->form, 'Dalal Mansour', 'seven@example.test', [
            ['firstName' => 'Tariq & Layla', 'lastName' => 'Al - Rahman', 'age' => 6],
        ]);
        $this->enrol($this->form, 'Widad Nasser', 'eight@example.test', [
            ['firstName' => 'Samir', 'lastName' => 'Nasser', 'age' => 8],
        ]);

        $this->assertSame(['seven@example.test'], $this->find('Tariq & Layla'));
        $this->assertSame(['seven@example.test'], $this->find('Al - Rahman'));
        $this->assertSame(['seven@example.test'], $this->find('Rahman , Tariq'));
        // The same words without the separators find the same row.
        $this->assertSame(['seven@example.test'], $this->find('Tariq Layla'));
        // Every real word is still required.
        $this->assertSame([], $this->find('Tariq & Samir'));
        // Punctuation and nothing else beside a letterless term stays what it was: no row.
        $this->assertSame([], $this->find('& -'));
    }

    #[Test]
    public function a_word_is_matched_at_the_start_of_a_word_in_the_answers_and_never_inside_one(): void
    {
        // Decided 2026-10-04 (DECISIONS.md). Matched anywhere, a short name returned the
        // whole form at a registration desk: every row here that answered "email",
        // "Somali" or "guardian".
        $this->threeFamilies();
        $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Ali', 'lastName' => 'Hamdan']]);
        $this->enrol($this->form, 'Nadia Farouk', 'five@example.test', [
            ['firstName' => 'Layla', 'lastName' => 'Abdulrahman', 'age' => 8, 'dob' => '2019-04-02', 'grade' => 'Somali reading group'],
        ], ['relationship' => 'guardian', 'preferredContact' => 'email']);
        $this->enrol($this->form, 'Huda Mansour', 'six@example.test', [
            ['firstName' => 'Maimuna', 'lastName' => 'Abdul-Rahman'],
            ['firstName' => 'Kareem', 'lastName' => 'al-Rahman'],
        ], ['otherParentEmail' => 'Kareem.Haddad@family.example']);
        $this->enrol($this->form, 'Noor Aziz', 'seven@example.test', [
            ['firstName' => 'Ian', 'lastName' => "O'Neil"],
            ['firstName' => 'طارق', 'lastName' => 'الرحماني'],
        ]);

        // "mai" is inside "email" (the answer and its label), "ali" inside "Somali", "ian"
        // inside "guardian" (the answer and "Legal guardian"): none of them finds that row.
        $this->assertSame(['six@example.test'], $this->find('Mai'));
        $this->assertSame(['four@example.test'], $this->find('Ali'));
        $this->assertSame(['seven@example.test'], $this->find('Ian'));
        // Whole, each of those words still finds it.
        $this->assertSame(['five@example.test'], $this->find('email'));
        $this->assertSame(['five@example.test'], $this->find('Somali'));
        $this->assertSame(['five@example.test'], $this->find('guardian'));

        // The first letters of a name are enough, in any row that has such a word.
        $this->assertSame(['six@example.test', 'three@example.test'], $this->find('kar'));
        $this->assertSame(['six@example.test'], $this->find('Kare'));
        // The end or the middle of one is not.
        $this->assertSame([], $this->find('areem'));
        $this->assertSame([], $this->find('amdan'));

        // A HYPHENATED name is its parts, so each part begins a word; the same name written
        // as one word is found from its start only.
        $this->assertSame(['six@example.test'], $this->find('rahman'));
        $this->assertSame(['six@example.test'], $this->find('Abdul-Rahman'));
        $this->assertSame(['six@example.test'], $this->find('al-rahman'));
        $this->assertSame(['six@example.test'], $this->find('AL RAHMAN'));
        $this->assertSame(['five@example.test'], $this->find('abdulrahman'));
        $this->assertSame(['five@example.test', 'six@example.test'], $this->find('abdul'));
        // Every piece of what was typed must be there: no row has a word that begins "el".
        $this->assertSame([], $this->find('el-rahman'));

        // An APOSTROPHE divides a name the same way, typed straight or curled.
        $this->assertSame(['seven@example.test'], $this->find("o'neil"));
        $this->assertSame(['seven@example.test'], $this->find('O’Neil'));
        $this->assertSame(['seven@example.test'], $this->find('neil'));
        $this->assertSame([], $this->find('oneil'));

        // ARABIC: from the start of the word. The article is written joined, so the name
        // without it is the inside of a word.
        $this->assertSame(['seven@example.test'], $this->find('طارق'));
        $this->assertSame(['seven@example.test'], $this->find('الرح'));
        $this->assertSame([], $this->find('رحماني'));

        // An EMAIL ADDRESS typed whole is looked for piece by piece, as it was stored: this
        // one is an answer, not the address of the person who filled the form in.
        $this->assertSame(['six@example.test'], $this->find('kareem.haddad@family.example'));
        $this->assertSame(['six@example.test'], $this->find('KAREEM.HADDAD@FAMILY.EXAMPLE'));
        $this->assertSame([], $this->find('kareem.haddad@elsewhere.example'));

        // A NUMBER beside a name begins a word too: the age, or the year of "2019-04-02".
        $this->assertSame(['five@example.test'], $this->find('Layla 8'));
        $this->assertSame(['five@example.test'], $this->find('Layla 2019'));
        $this->assertSame(['five@example.test'], $this->find('Layla 201'));
        $this->assertSame([], $this->find('Layla 19'));

        // A second word narrows it, because every word must match.
        $this->assertSame(['six@example.test'], $this->find('kar rahman'));
        $this->assertSame([], $this->find('Ali Rahman'));
    }

    #[Test]
    public function the_first_word_of_the_answers_is_found_and_the_name_email_and_phone_are_matched_in_any_part(): void
    {
        $this->threeFamilies();
        // Filled in at the desk for a family: the respondent the row is filed under is not
        // the name in the answers, so only the answers can find "Zaynab", and it is their
        // very first word.
        $row = $this->enrol($this->form, 'Zaynab Qureshi', 'four@example.test', [['firstName' => 'Bilal', 'lastName' => 'Qureshi']]);
        $row->update(['respondent_name' => 'Front Desk', 'respondent_phone' => '+1 555 010 0199']);

        $this->assertStringStartsWith(' zaynab qureshi ', $row->fresh()->answers_text);
        $this->assertSame(['four@example.test'], $this->find('Zaynab'));
        $this->assertSame(['four@example.test'], $this->find('zay'));
        $this->assertSame([], $this->find('aynab'));

        // The three identity columns are not words: any part of one is found, as before.
        $this->assertSame(['four@example.test'], $this->find('ont Des'));
        $this->assertSame(['four@example.test'], $this->find('our@exam'));
        $this->assertSame(['four@example.test'], $this->find('0199'));
        $this->assertSame(['one@example.test'], $this->find('aryam'));
    }

    #[Test]
    public function an_answer_with_a_slash_in_it_is_found(): void
    {
        $this->threeFamilies();
        $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [
            ['firstName' => 'Layla', 'lastName' => 'Rahmani', 'dob' => '2019/05/01', 'grade' => 'KG/1'],
        ]);

        $this->assertSame(['four@example.test'], $this->find('KG/1'));
        $this->assertSame(['four@example.test'], $this->find('Layla 2019/05/01'));
    }

    #[Test]
    public function a_number_is_looked_for_in_the_answers_beside_a_name_and_never_on_its_own(): void
    {
        $this->threeFamilies();
        $row = $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [
            ['firstName' => 'Layla', 'lastName' => 'Rahmani', 'age' => 8, 'dob' => '2019-04-02', 'grade' => 'Grade 3'],
        ]);

        // Beside a name the number narrows: it is this child's age, grade or year of birth.
        $this->assertSame(['four@example.test'], $this->find('Layla 8'));
        $this->assertSame(['four@example.test'], $this->find('Layla Grade 3'));
        $this->assertSame(['four@example.test'], $this->find('Rahmani 2019'));
        // And it must be there: no answer of this row has a 5 in it.
        $this->assertSame([], $this->find('Layla 5'));
        // At the start of a word, like any other word: "2019-04-02" is 2019, 04 and 02.
        $this->assertSame(['four@example.test'], $this->find('Layla 20'));
        $this->assertSame(['four@example.test'], $this->find('Layla 2019-04-02'));
        $this->assertSame([], $this->find('Layla 19'));
        $this->assertSame([], $this->find('Layla 4'));

        // On its own a number is a registration number or part of a phone, never "anyone
        // aged 8": only the row whose own number is 8 could match, and this one's is not.
        $this->assertNotSame(8, (int) $row->id);
        $this->assertSame([], $this->find('8'));
        $this->assertSame([], $this->find('2019'));
        // The registration number still finds its row, with or without the hash.
        $this->assertSame(['four@example.test'], $this->find('#' . $row->id));
        $this->assertSame(['four@example.test'], $this->find((string) $row->id));
    }

    #[Test]
    public function a_choice_is_found_by_the_words_the_family_read_and_a_file_name_or_a_tick_is_not(): void
    {
        $this->threeFamilies();
        $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Layla', 'lastName' => 'Rahmani']], [
            'relationship' => 'guardian',
            'helpWith' => ['carpool', 'cleanup'],
            'preferredContact' => 'phone',
            'immunisationRecord' => 'vaccines-scan.pdf',
        ]);

        // A choose-one answer: the stored value, and the label of that option.
        $this->assertSame(['four@example.test'], $this->find('guardian'));
        $this->assertSame(['four@example.test'], $this->find('Legal'));
        // The label of an option nobody picked is not in any row.
        $this->assertSame([], $this->find('Father'));
        // A choose-any answer: each value and its label.
        $this->assertSame(['four@example.test'], $this->find('carpool'));
        $this->assertSame(['four@example.test'], $this->find('Driving Tidying'));
        $this->assertSame(['four@example.test'], $this->find('Phone call'));
        $this->assertSame(['four@example.test'], $this->find('cleanup carpool'));
        // The name of an uploaded file is not an answer to look for.
        $this->assertSame([], $this->find('vaccines'));

        $this->assertSame(
            ' samira nasser four example test guardian legal guardian carpool driving cleanup tidying up phone phone call layla rahmani',
            $this->form->responses()->where('respondent_email', 'four@example.test')->firstOrFail()->answers_text
        );
    }

    #[Test]
    public function a_percent_sign_or_an_underscore_is_never_a_wildcard(): void
    {
        $this->threeFamilies();
        $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Layla', 'lastName' => 'Rahmani', 'grade' => '100% attendance_award!']]);

        // Each would be "anything at all" to LIKE, and so every row. In the name, email and
        // phone it is looked for as typed.
        $this->assertSame([], $this->find('%'));
        $this->assertSame([], $this->find('_'));
        // As wildcards these would be "Samira" and "four@". Typed, they are no part of the
        // name or the address, and no word of the answers begins "mira" or "u".
        $this->assertSame([], $this->find('S_mira'));
        $this->assertSame([], $this->find('f%u'));

        // In the answers it is one more character between words, there and in what is typed.
        $this->assertSame(['four@example.test'], $this->find('Layla 100%'));
        $this->assertSame(['four@example.test'], $this->find('attendance_award!'));
        $this->assertSame(['four@example.test'], $this->find('award'));
        $this->assertSame([], $this->find('Layla 1_9'));
    }

    // ------------------------------------------------------- what is never found

    #[Test]
    public function a_term_that_is_not_valid_text_finds_nothing_and_never_every_row(): void
    {
        $this->threeFamilies();

        // A multi-byte character cut in half, alone and after a real name.
        $this->assertCount(0, $this->getJson($this->url('?q=%FF'))->assertOk()->json('data.data'));
        $this->assertCount(0, $this->getJson($this->url('?q=Maryam%FF'))->assertOk()->json('data.data'));
        $this->assertCount(0, $this->getJson($this->url('?q=Maryam+%E2%82'))->assertOk()->json('data.data'));

        // The export shares the query: it must not stream the whole form under a filter.
        $csv = $this->get($this->url('/export?q=%FF'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Maryam Idris', $csv);

        // A term with nothing in it to look for (here a wide space, which trim() keeps).
        $nothing = $this->form->responses()->search("\u{3000}");
        $this->assertSame(0, $nothing->count());
        $this->assertSame(3, $this->form->responses()->search('   ')->count(), 'An empty search is no search.');
    }

    #[Test]
    public function another_organisations_answers_are_never_reached(): void
    {
        $this->threeFamilies();
        $this->enrol($this->otherForm, 'Other Parent', 'other@example.test', [['firstName' => 'Reem', 'lastName' => 'Elsewhere']]);

        $this->assertSame(['one@example.test'], $this->find('Reem'));
        $this->assertSame([], $this->find('Elsewhere'));

        // And from the other side, only its own.
        $theirs = collect($this->getJson($this->url('?q=Reem', $this->otherForm))->assertOk()->json('data.data'));
        $this->assertSame(['other@example.test'], $theirs->pluck('respondent_email')->all());
    }

    // ------------------------------------------------- everything on the same query

    #[Test]
    public function the_export_the_roster_the_cash_totals_and_the_insights_are_narrowed_the_same_way(): void
    {
        $this->threeFamilies();

        // Each family paid 30.00 in cash at the table, so the totals have something to add up.
        $this->form->responses()->get()->each(fn (FormResponse $row) => $row->settleCashBy($this->admin));

        $csv = $this->get($this->url('/export?q=Reem'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Maryam Idris', $csv);
        $this->assertStringNotContainsString('Hana Odeh', $csv);
        $this->assertStringNotContainsString('Yusuf Karimi', $csv);

        // One child in the one family found, not the four on the form.
        $this->assertSame(1, $this->getJson($this->url('/roster?q=Reem'))->assertOk()->json('data.total'));
        $this->assertSame(4, $this->getJson($this->url('/roster'))->assertOk()->json('data.total'));

        $this->assertSame(3000, $this->getJson($this->url('/cash-totals?q=Reem'))->assertOk()->json('data.totals.cash_minor'));
        $this->assertSame(9000, $this->getJson($this->url('/cash-totals'))->assertOk()->json('data.totals.cash_minor'));

        // The insights panel is behind the assistant entitlement.
        $this->masjid->update(['assistant_enabled' => true]);
        $insights = "/api/admin/masjids/{$this->masjid->id}/forms/{$this->form->id}/insights";
        $this->assertSame(1, $this->getJson($insights . '?q=Reem')->assertOk()->json('data.totals.responses'));
        $this->assertSame(3, $this->getJson($insights)->assertOk()->json('data.totals.responses'));
    }

    // ------------------------------------------------------------ how it is kept

    #[Test]
    public function changing_a_responses_answers_rewrites_the_text_and_a_save_that_leaves_them_alone_does_not(): void
    {
        $row = $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Tariq', 'lastName' => 'Rahmani']]);

        $this->assertSame(' samira nasser four example test tariq rahmani', $row->fresh()->answers_text);

        // What the website import does when a family corrects a name: `data` is replaced.
        $row->data = $this->answers('Samira Nasser', 'four@example.test', [['firstName' => 'Idris', 'lastName' => 'Rahmani']]);
        $row->save();

        $this->assertSame(' samira nasser four example test idris rahmani', $row->fresh()->answers_text);
        $this->assertSame(['four@example.test'], $this->find('Idris'));
        $this->assertSame([], $this->find('Tariq'));

        // Triage does not touch the answers, so the form is not even read.
        $fresh = $row->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh->update(['status' => 'confirmed']);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('answers_text', $queries[0]);

        // A model loaded without its answers knows nothing about them and writes nothing.
        $partial = FormResponse::query()->select('id', 'status')->findOrFail($row->id);
        $partial->update(['status' => 'new']);

        $this->assertSame(' samira nasser four example test idris rahmani', $row->fresh()->answers_text);
    }

    #[Test]
    public function the_text_is_a_real_text_column_that_no_payload_carries_and_the_staging_scrub_empties(): void
    {
        // SQLite reports MEDIUMTEXT as text; tests/Mysql pins the real type. What matters
        // here is that it is not a varchar, whose width MySQL would enforce.
        $this->assertSame('text', Schema::getColumnType('form_responses', 'answers_text'));

        $row = $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Tariq', 'lastName' => 'Rahmani']]);

        $this->assertArrayNotHasKey('answers_text', $row->fresh()->toArray());

        $detail = $this->getJson($this->url("/{$row->id}"))->assertOk();
        $this->assertStringNotContainsString('answers_text', $detail->getContent());
        $this->assertStringNotContainsString('answers_text', $this->getJson($this->url())->assertOk()->getContent());

        // `staging:scrub` rewrites `data` without the model, so the copy has to go with it.
        $this->assertContains('answers_text', config('staging_scrub.null_columns.form_responses'));
    }

    #[Test]
    public function a_new_public_submission_is_searchable_by_every_choice_label(): void
    {
        $this->form->update(['settings' => []]);
        $this->post('/api/v1/forms/'.$this->form->id.'/responses', ['data' => [
            'registrantName' => 'Test Parent', 'registrantEmail' => 'visitor@example.test',
            'relationship' => 'guardian', 'preferredContact' => 'phone', 'helpWith' => ['carpool'],
            'children' => [['firstName' => 'Test', 'lastName' => 'Child']],
        ]], ['masjid-id' => (string) $this->masjid->id])->assertOk();
        foreach (['Legal guardian', 'Phone call', 'Driving', 'carpool'] as $query) {
            $rows = $this->getJson($this->url('?q='.rawurlencode($query)))->assertOk();
            $this->assertSame(1, $rows->json('data.total'));
        }
        $this->assertSame([], $this->find('Tidying'));
    }

    #[Test]
    public function rebuild_rejects_invalid_bounds_without_writing(): void
    {
        foreach ([['--form' => '1.5'], ['--masjid' => '0'], ['--from-id' => '-1'],
            ['--to-id' => 'abc'], ['--from-id' => '2', '--to-id' => '1'],
            ['--chunk' => '0'], ['--chunk' => '1001']] as $options) {
            $this->artisan('forms:rebuild-answers-text', $options)->assertExitCode(2);
        }
    }

    #[Test]
    public function rebuild_skips_an_answer_changed_in_the_same_timestamp_second(): void
    {
        $row = $this->enrol($this->form, 'Test Parent', 'one@example.test', [], ['helpWith' => ['carpool']]);
        DB::table('form_responses')->where('id', $row->id)->update(['answers_text' => ' carpool']);
        $changed = false;
        DB::connection()->beforeExecuting(function (string $query) use ($row, &$changed): void {
            if (! $changed && str_starts_with($query, 'update "form_responses" set "answers_text"')) {
                $changed = true;
                DB::table('form_responses')->where('id', $row->id)->update([
                    'data' => json_encode(['helpWith' => ['cleanup']]), 'answers_text' => ' cleanup tidying up',
                ]);
            }
        });
        $this->artisan('forms:rebuild-answers-text', ['--form' => $this->form->id, '--all' => true])
            ->expectsOutputToContain('Concurrent changes skipped')->assertExitCode(1);
        $this->assertTrue($changed);
        $this->assertSame(' cleanup tidying up', $row->fresh()->answers_text);
        $this->assertSame([], $this->find('Driving'));
        $this->assertSame(['one@example.test'], $this->find('Tidying'));
    }

    #[Test]
    public function interrupted_rebuild_reports_a_safe_resume_point_and_redacts_exception_text(): void
    {
        $one = $this->enrol($this->form, 'Test Parent', 'one@example.test', [], ['helpWith' => ['carpool']]);
        $two = $this->enrol($this->form, 'Test Parent', 'two@example.test', [], ['helpWith' => ['carpool']]);
        DB::table('form_responses')->whereIn('id', [$one->id, $two->id])->update(['answers_text' => ' carpool']);
        $interrupted = false;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($two, &$interrupted): void {
            if (! $interrupted && str_starts_with($query, 'update "form_responses" set "answers_text"')
                && $bindings[1] === $two->id) {
                $interrupted = true;
                throw new \RuntimeException('unprintable answer');
            }
        });
        $options = ['--form' => $this->form->id, '--all' => true, '--chunk' => 1];
        $this->assertSame(1, Artisan::call('forms:rebuild-answers-text', $options));
        $output = Artisan::output();
        $this->assertStringContainsString('--from-id='.$two->id.' --to-id='.$two->id, $output);
        $this->assertStringNotContainsString('unprintable answer', $output);
        $this->assertSame(['one@example.test'], $this->find('Driving'));
        $this->assertSame(0, Artisan::call('forms:rebuild-answers-text', $options + ['--from-id' => $two->id]));
        $this->assertSame(['one@example.test', 'two@example.test'], $this->find('Driving'));
    }

    #[Test]
    public function bounded_rebuild_finds_old_choice_labels_and_only_changes_answers_text(): void
    {
        $one = $this->enrol($this->form, 'Test Parent', 'one@example.test', [], ['helpWith' => ['carpool', 'removedOption']]);
        $two = $this->enrol($this->form, 'Test Parent', 'two@example.test', [], ['helpWith' => ['carpool']]);
        $other = $this->enrol($this->otherForm, 'Other Parent', 'other@example.test', [], ['helpWith' => ['carpool']]);
        DB::table('form_responses')->whereIn('id', [$one->id, $two->id, $other->id])->update(['answers_text' => ' carpool removedoption']);
        $before = DB::table('form_responses')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertSame([], $this->find('Driving'));
        $options = ['--all' => true, '--masjid' => $this->masjid->id,
            '--from-id' => $one->id, '--to-id' => $one->id, '--chunk' => 1];
        $this->artisan('forms:rebuild-answers-text', $options)->expectsOutputToContain('1 form response(s)')->assertSuccessful();
        $this->assertSame(['one@example.test'], $this->find('Driving'));
        $this->assertSame(['one@example.test', 'two@example.test'], $this->find('removedOption'));
        $this->artisan('forms:rebuild-answers-text', $options)->expectsOutputToContain('0 form response(s)')->assertSuccessful();
        $after = DB::table('form_responses')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        foreach ($after as $index => $row) {
            unset($row['answers_text'], $before[$index]['answers_text']);
            $this->assertSame($before[$index], $row);
        }
        $this->assertSame(' carpool removedoption', $two->fresh()->answers_text);
        $this->assertSame(' carpool removedoption', $other->fresh()->answers_text);
        $this->artisan('forms:rebuild-answers-text', ['--all' => true, '--masjid' => $this->masjid->id,
            '--from-id' => $two->id, '--to-id' => $two->id, '--chunk' => 1])->assertSuccessful();
        $this->assertSame(['one@example.test', 'two@example.test'], $this->find('Driving'));
        $this->assertSame(['one@example.test'], $this->find('removedOption'));
    }

    #[Test]
    public function rows_stored_before_the_column_are_filled_once_and_a_rebuild_follows_the_forms_questions(): void
    {
        // As the migration finds them: written without the model, with no text.
        $this->storeWithoutTheModel($this->form, 'Samira Nasser', 'four@example.test', 'Tariq', ['nickname' => 'Zuzu']);
        $this->storeWithoutTheModel($this->form, 'Nadia Farouk', 'five@example.test', 'Layla', ['nickname' => 'Zuzu']);

        $this->assertSame([], $this->find('Tariq'));

        // One row per chunk, so the walk by primary key is exercised.
        $this->assertSame(2, FormAnswersText::fill(chunk: 1));
        $this->assertSame(['four@example.test'], $this->find('Tariq'));
        $this->assertSame(['five@example.test'], $this->find('Layla'));
        $this->assertSame(0, FormAnswersText::fill(chunk: 1), 'A second run has nothing left to write.');

        // The form now declares a question those rows had already answered.
        $schema = $this->schema();
        $schema['sections'][0]['fields'][] = ['name' => 'nickname', 'label' => 'Nickname', 'type' => 'text'];
        $this->form->update(['schema' => $schema]);

        $this->assertSame([], $this->find('Zuzu'));

        // Without --all only empty rows are written, and there are none.
        $this->artisan('forms:rebuild-answers-text', ['--form' => $this->form->id])
            ->expectsOutputToContain('0 form response(s)')
            ->assertSuccessful();
        $this->assertSame([], $this->find('Zuzu'));

        // Another form's rebuild leaves these alone.
        $this->artisan('forms:rebuild-answers-text', ['--form' => $this->otherForm->id, '--all' => true])
            ->expectsOutputToContain('0 form response(s)')
            ->assertSuccessful();

        $this->artisan('forms:rebuild-answers-text', ['--form' => $this->form->id, '--all' => true])
            ->expectsOutputToContain('2 form response(s)')
            ->assertSuccessful();
        $this->assertSame(['five@example.test', 'four@example.test'], $this->find('Zuzu'));

        // Nothing about the responses themselves changed, so their timestamps did not move.
        $this->assertSame(
            ['2026-07-09 12:00:00'],
            DB::table('form_responses')->where('form_id', $this->form->id)->distinct()->pluck('updated_at')->all()
        );

        $this->artisan('forms:rebuild-answers-text', ['--form' => 'enrolment'])->assertExitCode(2);
    }

    #[Test]
    public function the_migration_fills_every_row_stored_before_it_and_a_second_run_writes_nothing(): void
    {
        $migration = $this->answersTextMigration();

        // The database as the deploy finds it: no column yet.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('form_responses', 'answers_text'), 'could not reach the schema before the migration');

        // A row from before the code, and one on a form that has since been deleted.
        $this->storeWithoutTheModel($this->form, 'Nadia Farouk', 'five@example.test', 'Layla');
        $closed = $this->makeForm($this->masjid, 'closed-enrolment');
        $this->storeWithoutTheModel($closed, 'Yusuf Karimi', 'three@example.test', 'Yunus');
        $closed->delete();
        // And a family that submits in the window: the code is live, `migrate` has not run.
        $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Tariq', 'lastName' => 'Rahmani']]);

        $migration->up();

        $this->assertSame(
            [
                'five@example.test' => ' nadia farouk five example test layla rahmani',
                'four@example.test' => ' samira nasser four example test tariq rahmani',
                'three@example.test' => ' yusuf karimi three example test yunus rahmani',
            ],
            DB::table('form_responses')->orderBy('respondent_email')->pluck('answers_text', 'respondent_email')->all()
        );
        $this->assertSame(['four@example.test'], $this->find('Tariq'));
        $this->assertSame(['five@example.test'], $this->find('Layla'));

        // An interrupted `migrate` comes back to up() with the column already there: it
        // must neither fail on it nor write over a row that has its text.
        DB::table('form_responses')->where('respondent_email', 'five@example.test')->update(['answers_text' => 'written since']);
        $this->storeWithoutTheModel($this->form, 'Hana Odeh', 'two@example.test', 'Bushra');

        $migration->up();

        $this->assertSame('written since', DB::table('form_responses')->where('respondent_email', 'five@example.test')->value('answers_text'));
        $this->assertSame(['two@example.test'], $this->find('Bushra'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $migration->up();
        $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => preg_match('/^\s*(update|insert|delete|alter)\b/i', $sql) === 1);
        DB::disableQueryLog();

        $this->assertSame([], $writes->values()->all(), 'With every row filled, a run has nothing to write.');

        // down() is safe to repeat too.
        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('form_responses', 'answers_text'));
    }

    #[Test]
    public function before_migrate_has_added_the_column_a_response_is_still_saved_and_the_search_is_the_old_one(): void
    {
        // bin/deploy makes the code live before it migrates.
        Schema::table('form_responses', fn ($table) => $table->dropColumn('answers_text'));
        FormAnswersText::forget();

        $row = $this->enrol($this->form, 'Samira Nasser', 'four@example.test', [['firstName' => 'Tariq', 'lastName' => 'Rahmani']]);

        $this->assertTrue($row->exists);
        $this->assertSame(['four@example.test'], $this->find('Samira'));
        $this->assertSame(['four@example.test'], $this->find('#' . $row->id));
        $this->assertSame([], $this->find('Tariq'));

        $this->artisan('forms:rebuild-answers-text')->assertExitCode(1);
    }
}
