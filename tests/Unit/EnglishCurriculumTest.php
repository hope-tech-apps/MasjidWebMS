<?php

namespace Tests\Unit;

use App\Support\Arabic\ArabicCurriculum;
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
    public function the_syllabus_is_fifty_two_drills_a_capital_and_a_lower_case_per_letter(): void
    {
        // The syllabus IS the progress denominator, so this is the number a
        // parent's percentage divides by: 26 capitals + 26 lower case (T-004.2).
        // Nothing borrowed from the qāʿidah.
        $syllabus = E::syllabus(null);

        $this->assertCount(52, $syllabus);
        $this->assertSame($syllabus, E::syllabus('madd'));
        $this->assertSame(['a.upper', 'a.lower', 'b.upper'], array_slice($syllabus, 0, 3));
        $this->assertSame(['q.upper', 'q.lower'], E::drillsForLetter('q', null));
        $this->assertSame([], E::drillsForLetter('ba', null));

        // The stage owns the whole syllabus, so "this stage" and "everything" agree.
        $this->assertSame($syllabus, E::stageDrills(null));
    }

    #[Test]
    public function no_drill_id_is_a_bare_letter_because_production_compares_ids_case_insensitively(): void
    {
        // THE REASON THE IDS HAVE A SUFFIX. Production's drill_id column is
        // utf8mb4_unicode_ci, so `A` and `a` are ONE key under the unique index
        // (student, alphabet, drill) and the second write would collide. SQLite,
        // which runs this suite, compares case-sensitively and would never tell
        // us — so the property is asserted on the ids themselves: no two drills
        // may be equal once case is ignored, and none may be a single character.
        $lowered = array_map('strtolower', E::syllabus(null));

        $this->assertSame(count($lowered), count(array_unique($lowered)));

        foreach (E::syllabus(null) as $id) {
            $this->assertGreaterThan(1, strlen($id), "{$id} must not be a bare letter");
            $this->assertMatchesRegularExpression('/^[a-z]\.(upper|lower)$/', $id);
        }
    }

    #[Test]
    public function the_two_sets_are_capitals_then_lower_case_and_each_drill_knows_its_set(): void
    {
        $this->assertSame(
            [['id' => 'upper', 'label' => 'Capitals'], ['id' => 'lower', 'label' => 'Lower case']],
            E::sets()
        );
        $this->assertSame('upper', E::set('m.upper'));
        $this->assertSame('lower', E::set('m.lower'));
        $this->assertNull(E::set('m'));
        $this->assertNull(E::set('M.upper'));
        $this->assertNull(E::set('ba.upper'));

        // Arabic has no sets, and its four letter forms must not become any.
        $this->assertSame([], ArabicCurriculum::sets());
        $this->assertNull(ArabicCurriculum::set('ba.fatha'));
    }

    #[Test]
    public function a_bare_letter_is_recognised_as_legacy_so_a_stale_tab_can_be_told_to_reload(): void
    {
        $this->assertTrue(E::isLegacyDrillId('a'));
        $this->assertTrue(E::isLegacyDrillId('A'));
        $this->assertFalse(E::isLegacyDrillId('a.upper'));
        $this->assertFalse(E::isLegacyDrillId('ba'));
        $this->assertFalse(E::isLegacyDrillId('aa'));
        // ...and it is NOT a valid drill any more.
        $this->assertFalse(E::isValidDrill('a', null));
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
    public function a_described_drill_carries_the_keys_the_card_component_reads(): void
    {
        // The card component draws both tracks, so a key that appears on one and
        // not the other would make it two components. `set` is English's.
        $z = E::describeDrill('z.upper');

        $this->assertSame(
            ['id', 'letter', 'text', 'label', 'arabic_name', 'sound', 'stage', 'set'],
            array_keys($z)
        );
        $this->assertSame('z', $z['letter']);
        // Each case is its own drill now, so `text` is the ONE form it is about.
        $this->assertSame('Z', $z['text']);
        $this->assertSame('Capital Z', $z['label']);
        $this->assertSame('upper', $z['set']);
        $this->assertNull($z['arabic_name']);
        $this->assertSame('z as in zip', $z['sound']);
        $this->assertSame(E::STAGE_LETTERS, $z['stage']);

        $lower = E::describeDrill('z.lower');
        $this->assertSame('z', $lower['text']);
        $this->assertSame('Lower case z', $lower['label']);
        $this->assertSame('lower', $lower['set']);

        // x is taught from the ending, which is what every classroom chart does
        // and is the one cue that looks like a mistake.
        $this->assertSame('x as in fox', E::describeDrill('x.lower')['sound']);

        $this->assertNull(E::describeDrill('z'));
        $this->assertNull(E::describeDrill('ba'));
        $this->assertNull(E::describeDrill('a.fatha'));
        $this->assertNull(E::describeDrill('Z.upper'));
    }

    #[Test]
    public function a_drill_from_the_other_alphabet_or_a_malformed_id_is_not_valid_here(): void
    {
        $this->assertTrue(E::isValidDrill('a.upper', null));
        $this->assertTrue(E::isValidDrill('z.lower', null));
        $this->assertFalse(E::isValidDrill('ba', null));
        $this->assertFalse(E::isValidDrill('ba.upper', null));
        $this->assertFalse(E::isValidDrill('a.fatha', null));
        $this->assertFalse(E::isValidDrill('A.upper', null));
        $this->assertFalse(E::isValidDrill('a.Upper', null));
        $this->assertFalse(E::isValidDrill('a.upper ', null));
        $this->assertFalse(E::isValidDrill('a', null));
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
