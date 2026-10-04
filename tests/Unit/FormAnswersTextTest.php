<?php

namespace Tests\Unit;

use App\Support\FormAnswersText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FormAnswersText::build(), the one function that decides what the Form Responses search
 * can find in the answers. No database: the schema's sections and the answers in, the
 * text out. The text is WORDS, each after one space, because the search finds a typed word
 * at the START of a word (`LIKE '% word%'`). The search itself is
 * tests/Feature/FormResponseSearchTest.php.
 */
class FormAnswersTextTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function sections(): array
    {
        return [
            ['id' => 'parent', 'fields' => [
                ['name' => 'parentName', 'type' => 'text'],
                ['name' => 'parentEmail', 'type' => 'email'],
                ['name' => 'householdSize', 'type' => 'number'],
                ['name' => 'relationship', 'type' => 'radio', 'options' => [
                    ['value' => 'mother', 'label' => 'Mother'],
                    ['value' => 'guardian', 'label' => 'Legal guardian'],
                ]],
                ['name' => 'days', 'type' => 'checkboxGroup', 'options' => [
                    ['value' => 'sat', 'label' => 'Saturday'],
                    ['value' => 'sun', 'label' => 'Sunday'],
                ]],
                ['name' => 'photoConsent', 'type' => 'checkbox'],
                ['name' => 'passportScan', 'type' => 'file'],
                ['name' => 'passportScanRef', 'type' => 'text'],
            ]],
            ['id' => 'children', 'repeatable' => true, 'fields' => [
                ['name' => 'firstName', 'type' => 'text'],
                ['name' => 'age', 'type' => 'number'],
            ]],
        ];
    }

    #[Test]
    public function it_writes_the_words_of_the_declared_answers_in_the_forms_order_in_lower_case(): void
    {
        $text = FormAnswersText::build($this->sections(), [
            'children' => [['firstName' => 'Tariq', 'age' => 5], ['firstName' => ' ÉMILE ', 'age' => 8.5]],
            'days' => ['sat', 'sun'],
            'relationship' => 'guardian',
            'householdSize' => 4,
            'parentEmail' => 'Samira@Example.Test',
            'parentName' => 'Samira Nasser',
        ]);

        // One space before every word, the first too; the address and the decimal are the
        // words between their punctuation.
        $this->assertSame(
            ' samira nasser samira example test 4 guardian legal guardian sat sun tariq 5 émile 8 5',
            $text
        );
    }

    #[Test]
    public function it_leaves_out_what_the_form_does_not_declare_and_what_is_not_an_answer_to_look_for(): void
    {
        $text = FormAnswersText::build($this->sections(), [
            'parentName' => 'Samira Nasser',
            // Declared, and never searched: a tick and a file name.
            'photoConsent' => true,
            'passportScan' => 'samira-passport.pdf',
            // Declared as a text question, as the website import declares it, and still a
            // digest: 64 hex digits that happen to spell "ada" and "deb".
            'passportScanRef' => '5E1C0FFEE7ADA90DEB42F00DBABE1ADA77C0DE5E1C0FFEE7ADA90DEB42F00D00',
            // Not declared: an import's status, and a key of a question since removed.
            'websiteStatus' => 'approved',
            'nickname' => 'Zuzu',
            // A row of the repeating section keeps only its declared questions too.
            'children' => [['firstName' => 'Tariq', 'medicalRef' => 'deadbeef'], 'not a row'],
            // Not strings or numbers.
            'householdSize' => null,
            'relationship' => ['guardian'],
            'parentEmail' => false,
        ]);

        $this->assertSame(' samira nasser tariq', $text);
    }

    #[Test]
    public function a_choice_that_is_not_one_of_the_options_is_kept_without_a_label(): void
    {
        $this->assertSame(' aunt', FormAnswersText::build($this->sections(), ['relationship' => 'Aunt']));
        $this->assertSame(' sat', FormAnswersText::build($this->sections(), ['days' => 'sat']));
    }

    #[Test]
    public function only_a_whole_value_of_hex_digits_as_long_as_a_digest_is_left_out(): void
    {
        $build = fn (string $value): string => FormAnswersText::build($this->sections(), ['passportScanRef' => $value]);

        $this->assertSame('', $build(str_repeat('ab12', 8)));
        $this->assertSame('', $build(' ' . hash('sha256', 'a path') . ' '));
        // One digit short of the shortest digest, a word in it, or a word beside it: an answer.
        $this->assertSame(' ' . str_repeat('ab12', 7) . 'ab1', $build(str_repeat('ab12', 7) . 'ab1'));
        $this->assertSame(' ref ' . str_repeat('ab12', 8), $build('Ref ' . str_repeat('ab12', 8)));
        $this->assertSame(' deadbeef', $build('deadbeef'));
        $this->assertSame(' ada', $build('Ada'));
    }

    #[Test]
    public function only_a_whole_value_shaped_like_a_payment_providers_object_id_is_left_out(): void
    {
        $build = fn (string $value): string => FormAnswersText::build($this->sections(), ['passportScanRef' => $value]);

        // The website import keeps the Stripe payment id in a text question the form
        // declares. As words it would be "pi" and 24 random letters and digits.
        $this->assertSame('', $build('pi_3QaLiW2eZvKYlo2C1mAiR7uV'));
        $this->assertSame('', $build(' cs_test_a1B2c3D4e5F6g7H8i9J0 '));
        $this->assertSame('', $build('ch_3QaLiW2eZvKYlo2C1mAiR7uV'));
        // A chosen option that begins the same way, a short reference, an id inside a
        // sentence, and a run with no digit in it: answers.
        $this->assertSame(' in person attendance', $build('in_person_attendance'));
        $this->assertSame(' in personattendanceonly', $build('in_personAttendanceOnly'));
        $this->assertSame(' pi test 0000', $build('pi_test_0000'));
        $this->assertSame(' paid pi 3qaliw2ezvkylo2c1mair7uv', $build('Paid, pi_3QaLiW2eZvKYlo2C1mAiR7uV'));
        $this->assertSame(' pi', $build('Pi'));
    }

    #[Test]
    public function lower_case_is_one_character_for_one_character_whatever_is_around_it(): void
    {
        // The full mapping makes a Turkish capital İ an "i" and a combining dot, which
        // "ibrahim" is no part of.
        $this->assertSame('ibrahim', FormAnswersText::lower('İbrahim'));
        $this->assertSame('ibrahim', FormAnswersText::lower('İBRAHİM'));
        $this->assertSame('ibrahim', FormAnswersText::lower('Ibrahim'));
        // And a Greek capital sigma a different letter at the end of a word than inside
        // one, so a word alone and the same letters inside the text would differ.
        $this->assertSame('οδοσ', FormAnswersText::lower('ΟΔΟΣ'));
        $this->assertStringContainsString(FormAnswersText::lower('ΟΔΟΣ'), FormAnswersText::lower('ΟΔΟΣΤΡΩΜΑ'));
        // What it leaves as it was.
        $this->assertSame('émile benoît', FormAnswersText::lower('ÉMILE Benoît'));
        $this->assertSame('طارق', FormAnswersText::lower('طارق'));

        $this->assertSame(' ibrahim yılmaz', FormAnswersText::build($this->sections(), ['parentName' => 'İbrahim Yılmaz']));
    }

    #[Test]
    public function a_word_is_a_run_of_letters_marks_and_numbers_and_everything_else_is_one_space(): void
    {
        // A hyphen, an apostrophe (typed or typographic), the punctuation of an address, a
        // slash, a line break and a run of several of them: one boundary each.
        $this->assertSame(['abdul', 'rahman'], FormAnswersText::words('Abdul-Rahman'));
        $this->assertSame(['o', 'neil'], FormAnswersText::words("O'Neil"));
        $this->assertSame(['o', 'neil'], FormAnswersText::words('O’Neil'));
        $this->assertSame(['layla', 'haddad', 'example', 'test'], FormAnswersText::words('Layla.Haddad@Example.test'));
        $this->assertSame(['kg', '1'], FormAnswersText::words('KG/1'));
        $this->assertSame(['2019', '04', '02'], FormAnswersText::words('2019-04-02'));
        $this->assertSame(['in', 'person', 'attendance'], FormAnswersText::words('in_person_attendance'));
        $this->assertSame(['grade', '3', 'room', 'b'], FormAnswersText::words("  Grade 3 --\n\t(room: B)!  "));
        // Written as one word, it is one word: found from its start only.
        $this->assertSame(['abdulrahman'], FormAnswersText::words('Abdulrahman'));
        $this->assertSame(['grade3'], FormAnswersText::words('Grade3'));

        // A letter keeps its marks: an accent typed as its own character, and the vowel
        // signs of an Arabic name. Arabic words are divided where they are written apart,
        // and at the Arabic comma.
        $this->assertSame(["e\u{301}mile"], FormAnswersText::words("E\u{301}mile"));
        $this->assertSame(['مُحَمَّد'], FormAnswersText::words('مُحَمَّد'));
        $this->assertSame(['عبد', 'الرحمن', 'طارق'], FormAnswersText::words('عبد الرحمن، طارق'));
        // Digits of another script are numbers too.
        $this->assertSame(['٢٠١٩'], FormAnswersText::words('٢٠١٩'));

        // Nothing to look for.
        $this->assertSame([], FormAnswersText::words(''));
        $this->assertSame([], FormAnswersText::words(' & - _ % ! '));
        // Bytes that are not text do not empty the rest.
        $this->assertSame(['maryam'], FormAnswersText::words("Maryam\xFF"));

        $this->assertSame('abdul rahman o neil', FormAnswersText::normalise(" Abdul-Rahman,\nO'Neil. "));
        $this->assertSame(
            ' abdul rahman o neil layla example test',
            FormAnswersText::build($this->sections(), ['parentName' => "Abdul-Rahman O'Neil", 'parentEmail' => 'layla@example.test'])
        );
    }

    #[Test]
    public function answers_or_a_schema_of_the_wrong_shape_have_no_text(): void
    {
        $this->assertSame('', FormAnswersText::build($this->sections(), null));
        $this->assertSame('', FormAnswersText::build($this->sections(), 'Tariq'));
        $this->assertSame('', FormAnswersText::build($this->sections(), []));
        $this->assertSame('', FormAnswersText::build([], ['parentName' => 'Samira Nasser']));
        $this->assertSame('', FormAnswersText::build(['parent', ['id' => 'x', 'fields' => 'parentName'], ['fields' => [['type' => 'text'], 'parentName']]], ['parentName' => 'Samira Nasser']));
        // A repeating section whose answer is not a list of rows.
        $this->assertSame('', FormAnswersText::build($this->sections(), ['children' => 'Tariq']));
    }

    #[Test]
    public function the_text_is_cut_by_bytes_on_a_character_boundary(): void
    {
        // Two bytes a letter after the one-byte leading space, so a cut at an even number
        // of bytes would split one.
        $long = str_repeat('م', intdiv(FormAnswersText::MAX_BYTES, 2) + 10);

        $text = FormAnswersText::build($this->sections(), ['parentName' => $long]);

        $this->assertSame(FormAnswersText::MAX_BYTES - 1, strlen($text));
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        // Under a MEDIUMTEXT's 16,777,215 bytes with room to spare.
        $this->assertLessThan(16_777_215 / 2, FormAnswersText::MAX_BYTES);
    }

    #[Test]
    public function a_mark_belongs_to_a_word_only_after_a_letter_or_a_number(): void
    {
        // An emoji typed from a phone carries a variation selector (a combining mark) and
        // is often followed by a word with no space. Kept as a word character, the mark
        // would sit at the FRONT of that word, which would then never be found.
        $this->assertSame(['allergy', 'peanuts'], FormAnswersText::words("\u{26A0}\u{FE0F}Allergy: peanuts"));
        $this->assertSame(['layla', 'haddad'], FormAnswersText::words("Layla\u{2764}\u{FE0F}Haddad"));
        // A mark after a letter stays with its letter: an accent typed as two characters,
        // and Arabic with its vowel marks.
        $this->assertSame(["e\u{301}mile"], FormAnswersText::words("E\u{301}mile"));
        $this->assertSame(["\u{645}\u{64F}\u{62D}\u{64E}\u{645}\u{651}\u{64E}\u{62F}"], FormAnswersText::words("\u{645}\u{64F}\u{62D}\u{64E}\u{645}\u{651}\u{64E}\u{62F}"));
        // A mark at the very start of the text is dropped, not kept as a word.
        $this->assertSame(['tariq'], FormAnswersText::words("\u{FE0F}Tariq"));
    }

    #[Test]
    public function a_like_pattern_keeps_what_was_typed_literal(): void
    {
        // Anywhere in a value: the respondent's name, email and phone.
        $this->assertSame('%tariq%', FormAnswersText::like('tariq'));
        $this->assertSame('%100!% !_x!_ wow!! a\\b%', FormAnswersText::like('100% _x_ wow! a\\b'));

        // At the start of a word of the answers: the space is the boundary.
        $this->assertSame('% tariq%', FormAnswersText::likeWordStart('tariq'));
        $this->assertSame('% 100!%%', FormAnswersText::likeWordStart('100%'));
    }
}
