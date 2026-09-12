<?php

namespace Tests\Unit;

use App\Support\Letters\CurriculumRegistry;
use App\Support\Letters\EnglishCurriculum as E;
use App\Support\Letters\LetterCurriculum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The English alphabet a child is taught from must be right, and it must stay
 * out of the qāʿidah's way.
 *
 * These are content assertions as much as code ones, for the same reason the
 * Arabic ones are: a missing letter or a phonics cue a class does not use is not
 * a cosmetic bug in a school product. The separation assertions matter just as
 * much — this track shares a table, a tab and a set of routes with Arabic, and
 * every one of those is a place the two could bleed into each other.
 */
class EnglishCurriculumTest extends TestCase
{
    #[Test]
    public function there_are_exactly_twenty_six_letters_in_alphabetical_order(): void
    {
        $letters = E::letters();

        $this->assertCount(26, $letters);
        $this->assertSame(range('a', 'z'), $letters);
    }

    #[Test]
    public function there_is_one_stage_and_everything_normalises_to_it(): void
    {
        // English has no ladder to climb. Anything handed in — including the
        // group's `arabic_stage`, which is what the tracker actually passes —
        // is the one stage, because there is nowhere else to be.
        $this->assertSame([E::STAGE_LETTERS], array_column(E::stages(), 'id'));
        $this->assertSame('Letters', E::stages()[0]['label']);

        $this->assertSame(E::STAGE_LETTERS, E::normaliseStage(null));
        $this->assertSame(E::STAGE_LETTERS, E::normaliseStage('short_vowels'));
        $this->assertSame(E::STAGE_LETTERS, E::normaliseStage('nonsense'));
    }

    #[Test]
    public function the_syllabus_is_the_twenty_six_letters_and_nothing_else(): void
    {
        // The syllabus IS the progress denominator, so this is the number a
        // parent's percentage divides by. One drill per letter: no marks, no
        // vowels, nothing borrowed from the qāʿidah.
        $this->assertSame(range('a', 'z'), E::syllabus(null));
        $this->assertSame(range('a', 'z'), E::syllabus('madd'));
        $this->assertSame(['q'], E::drillsForLetter('q', null));
        $this->assertSame([], E::drillsForLetter('ba', null));
    }

    #[Test]
    public function a_letter_is_shown_as_its_upper_and_lower_case(): void
    {
        $this->assertSame(['upper', 'lower'], E::positionsFor('a'));
        $this->assertSame('A', E::shape('a', 'upper'));
        $this->assertSame('a', E::shape('a', 'lower'));

        // Both cases are the thing being learned, so the letter itself is the
        // pair — showing one would be showing half the drill.
        $this->assertSame('Aa', E::letter('a')['glyph']);
        $this->assertNull(E::letter('a')['arabic_name']);
        $this->assertFalse(E::letter('a')['connects_forward']);
        $this->assertNull(E::letter('ba'));
        $this->assertSame([], E::positionsFor('ba'));
    }

    #[Test]
    public function a_described_drill_carries_the_same_keys_the_arabic_one_does(): void
    {
        // The card component draws both tracks, so a key that appears on one and
        // not the other would make it two components.
        $z = E::describeDrill('z');

        $this->assertSame(
            ['id', 'letter', 'text', 'label', 'arabic_name', 'sound', 'stage'],
            array_keys($z)
        );
        $this->assertSame('z', $z['letter']);
        $this->assertSame('Zz', $z['text']);
        $this->assertSame('Z', $z['label']);
        $this->assertNull($z['arabic_name']);
        $this->assertSame('z as in zip', $z['sound']);
        $this->assertSame(E::STAGE_LETTERS, $z['stage']);

        // x is taught from the ending, which is what every classroom chart does
        // and is the one cue that looks like a mistake.
        $this->assertSame('x as in fox', E::describeDrill('x')['sound']);

        $this->assertNull(E::describeDrill('ba'));
        $this->assertNull(E::describeDrill('a.fatha'));
    }

    #[Test]
    public function a_drill_from_the_other_alphabet_is_not_valid_here(): void
    {
        $this->assertTrue(E::isValidDrill('a', null));
        $this->assertFalse(E::isValidDrill('ba', null));
        $this->assertFalse(E::isValidDrill('a.fatha', null));
        $this->assertFalse(E::isValidDrill('A', null));
        $this->assertFalse(E::isValidDrill('aa', null));
    }

    #[Test]
    public function the_registry_hands_out_the_two_alphabets_and_refuses_a_third(): void
    {
        $this->assertSame(['arabic', 'english'], CurriculumRegistry::ALPHABETS);

        foreach (CurriculumRegistry::ALPHABETS as $alphabet) {
            $curriculum = CurriculumRegistry::for($alphabet);
            $this->assertInstanceOf(LetterCurriculum::class, $curriculum);
            $this->assertSame($alphabet, $curriculum->alphabetId());
        }

        $this->assertSame('ltr', CurriculumRegistry::for('english')->direction());
        $this->assertSame('rtl', CurriculumRegistry::for('arabic')->direction());

        // A plain varchar column stores this, so an unrecognised value must
        // never quietly resolve to Arabic and make the other track's rows look
        // as though they vanished.
        $this->expectException(\InvalidArgumentException::class);
        CurriculumRegistry::for('englsih');
    }
}
