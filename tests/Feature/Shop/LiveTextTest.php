<?php

namespace Tests\Feature\Shop;

use App\Services\Shop\LiveText;
use Normalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shop slice B2 (the critic's fix round): LiveText::fold() is what the label validation and the slug
 * suffixing compare through, so it has to call two texts the same whenever production's
 * `utf8mb4_unicode_ci` unique index does, in every script, and keep different letters different.
 *
 * Most of the pairs need `intl` (NFKD): CI's setup-php enables it, and a PHP without it skips them and
 * runs the fallback's own cases instead.
 */
class LiveTextTest extends TestCase
{
    private function needsIntl(): void
    {
        if (! class_exists(Normalizer::class)) {
            $this->markTestSkipped('The intl extension is not loaded; the fallback cases below cover what remains.');
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function pairsTheIndexCallsTheSame(): array
    {
        return [
            'Cyrillic E and E with a diaeresis' => ["\u{0415}", "\u{0401}"],
            'Arabic alef and alef with hamza above (children)' => ["\u{0623}\u{0637}\u{0641}\u{0627}\u{0644}", "\u{0627}\u{0637}\u{0641}\u{0627}\u{0644}"],
            'Arabic alef with madda and alef' => ["\u{0622}", "\u{0627}"],
            'a soft hyphen inside XL' => ['XL', "X\u{00AD}L"],
            'a zero width space inside XL' => ['XL', "X\u{200B}L"],
            'a zero width joiner inside XL' => ['XL', "X\u{200D}L"],
            'a zero width non-joiner inside XL' => ['XL', "X\u{200C}L"],
            'a trailing space (PAD SPACE)' => ['M', 'M '],
            'a leading space' => ['M', ' M'],
            'a space at both ends' => ['M ', ' M'],
            'a tab and a no-break space inside a label' => ["Adult\u{00A0}L", "Adult\tL"],
            'two spaces and one' => ['Adult  L', 'Adult L'],
            'Medium and Medium with an accent' => ['Medium', "M\u{00E9}dium"],
            'a decomposed accent and a precomposed one' => ["Me\u{0301}dium", "M\u{00E9}dium"],
            'an eszett and ss' => ['Strasse', "Stra\u{00DF}e"],
            'case' => ['Polo', 'POLO'],
            'a full-width letter and the plain one' => ['A', "\u{FF21}"],
            'the fi ligature and fi' => ['fi', "\u{FB01}"],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function pairsThatStayDifferent(): array
    {
        return [
            'two different Arabic words' => ["\u{0643}\u{0628}\u{064A}\u{0631}", "\u{0635}\u{063A}\u{064A}\u{0631}"],
            'Cyrillic E and Latin E' => ["\u{0415}", 'E'],
            'M and N' => ['M', 'N'],
            'XL and XXL' => ['XL', 'XXL'],
            'two labels that differ in a word' => ['Adult L', 'Adult M'],
            'a letter and nothing' => ['M', ''],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('pairsTheIndexCallsTheSame')]
    public function the_fold_calls_the_same_what_the_collation_calls_the_same(string $a, string $b): void
    {
        $this->needsIntl();

        $this->assertSame(LiveText::fold($a), LiveText::fold($b), json_encode([$a, $b]));
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('pairsThatStayDifferent')]
    public function the_fold_keeps_different_letters_and_different_scripts_apart(string $a, string $b): void
    {
        $this->assertNotSame(LiveText::fold($a), LiveText::fold($b), json_encode([$a, $b]));
        $this->assertNotSame(LiveText::fold($a, false), LiveText::fold($b, false), 'and so does the fallback');
    }

    #[Test]
    public function the_fallback_without_intl_still_folds_case_space_format_characters_marks_and_latin_accents(): void
    {
        $pairs = [
            ['XL', "X\u{00AD}L"],
            ['XL', "X\u{200B}L"],
            ['M', 'M '],
            ['M', ' M'],
            ['Polo', 'POLO'],
            ['Medium', "M\u{00E9}dium"],
            ['Strasse', "Stra\u{00DF}e"],
            // A mark that arrives already decomposed is stripped without the Normalizer.
            ["\u{0415}", "\u{0415}\u{0308}"],
            ["\u{0627}", "\u{0627}\u{0653}"],
        ];

        foreach ($pairs as [$a, $b]) {
            $this->assertSame(LiveText::fold($a, false), LiveText::fold($b, false), json_encode([$a, $b]));
        }

        // What the fallback cannot know: a letter that exists only precomposed (Ё, أ) is told apart from its base.
        // That is why production needs intl (ASSUMPTIONS S-29), and why the backstop in ProductWriter exists.
        $this->assertNotSame(LiveText::fold("\u{0401}", false), LiveText::fold("\u{0415}", false));
    }

    #[Test]
    public function folding_is_stable(): void
    {
        foreach (['M', "M\u{00E9}dium", "\u{0623}\u{0637}\u{0641}\u{0627}\u{0644}", "X\u{00AD}L", '  Adult   L '] as $text) {
            $once = LiveText::fold($text);

            $this->assertSame($once, LiveText::fold($once), 'folding twice changes nothing: ' . json_encode($text));
        }
    }
}
