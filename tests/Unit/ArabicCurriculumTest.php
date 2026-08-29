<?php

namespace Tests\Unit;

use App\Support\Arabic\ArabicCurriculum as C;
use App\Support\Avatar;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The alphabet a child is taught from must be right, and the scope a class is
 * working on must be ONE answer.
 *
 * These are content assertions as much as code ones: a wrong letter shape or a
 * vowel unlocked two years early is not a cosmetic bug in a school product.
 */
class ArabicCurriculumTest extends TestCase
{
    #[Test]
    public function there_are_exactly_twenty_eight_letters_in_hijai_order(): void
    {
        $letters = array_keys(C::LETTERS);

        $this->assertCount(28, $letters);
        $this->assertSame('alif', $letters[0]);
        $this->assertSame('ya', $letters[27]);
        $this->assertSame(28, count(array_unique($letters)));
    }

    #[Test]
    public function the_six_non_connecting_letters_are_the_six_that_do_not_join_forward(): void
    {
        // ا د ذ ر ز و — and no others. A letter wrongly marked as joining would
        // show a child an initial form that does not exist.
        $nonConnecting = array_keys(array_filter(
            C::LETTERS,
            static fn (array $l): bool => $l[3] === false
        ));

        $this->assertSame(['alif', 'dal', 'dhal', 'ra', 'zay', 'waw'], $nonConnecting);
    }

    #[Test]
    public function a_non_connecting_letter_has_two_shapes_and_a_connecting_one_has_four(): void
    {
        $this->assertSame(['isolated', 'final'], C::positionsFor('dal'));
        $this->assertSame(['isolated', 'initial', 'medial', 'final'], C::positionsFor('ba'));

        // The shapes are built with the zero-width joiner rather than pasted
        // glyphs, so the font picks the contextual form.
        $this->assertSame('ب', C::shape('ba', 'isolated'));
        $this->assertSame('ب'.C::ZWJ, C::shape('ba', 'initial'));
        $this->assertSame(C::ZWJ.'ب'.C::ZWJ, C::shape('ba', 'medial'));
        $this->assertSame(C::ZWJ.'ب', C::shape('ba', 'final'));

        // dāl never joins forward, so even asked for an initial it stays bare.
        $this->assertSame('د', C::shape('dal', 'initial'));
        $this->assertSame(C::ZWJ.'د', C::shape('dal', 'final'));
    }

    #[Test]
    public function stages_are_cumulative_and_unlock_the_marks_in_teaching_order(): void
    {
        $this->assertSame([], C::marksUpTo(C::STAGE_LETTERS));
        $this->assertSame(['fatha', 'kasra', 'damma'], C::marksUpTo(C::STAGE_SHORT_VOWELS));
        $this->assertSame(
            ['fatha', 'kasra', 'damma', 'sukun', 'shadda'],
            C::marksUpTo(C::STAGE_SUKUN_SHADDA)
        );
        $this->assertSame(
            ['fatha', 'kasra', 'damma', 'sukun', 'shadda', 'fathatan', 'kasratan', 'dammatan'],
            C::marksUpTo(C::STAGE_TANWEEN)
        );

        // Long vowels arrive last and only last.
        $this->assertSame([], C::maddUpTo(C::STAGE_TANWEEN));
        $this->assertSame(['alif', 'waw', 'ya'], C::maddUpTo(C::STAGE_MADD));
    }

    #[Test]
    public function the_syllabus_is_the_progress_denominator(): void
    {
        // 28 letters alone.
        $this->assertCount(28, C::syllabus(C::STAGE_LETTERS));
        // + three short vowels on each.
        $this->assertCount(28 * 4, C::syllabus(C::STAGE_SHORT_VOWELS));
        // + sukun and shadda.
        $this->assertCount(28 * 6, C::syllabus(C::STAGE_SUKUN_SHADDA));
        // + three tanween.
        $this->assertCount(28 * 9, C::syllabus(C::STAGE_TANWEEN));
        // + three long vowels.
        $this->assertCount(28 * 12, C::syllabus(C::STAGE_MADD));
    }

    #[Test]
    public function one_letters_drills_are_a_slice_of_the_same_syllabus(): void
    {
        // The class grid, one child's card and the denominator must agree — if
        // they were computed separately a child could be shown a drill their
        // progress bar never counted.
        $stage = C::STAGE_SUKUN_SHADDA;
        $forBa = C::drillsForLetter('ba', $stage);
        $syllabus = C::syllabus($stage);

        $this->assertSame(['ba', 'ba.fatha', 'ba.kasra', 'ba.damma', 'ba.sukun', 'ba.shadda'], $forBa);

        foreach ($forBa as $drill) {
            $this->assertContains($drill, $syllabus);
        }
    }

    #[Test]
    public function a_drill_from_a_later_stage_is_not_valid_for_an_earlier_one(): void
    {
        $this->assertFalse(C::isValidDrill('ba.fatha', C::STAGE_LETTERS));
        $this->assertTrue(C::isValidDrill('ba.fatha', C::STAGE_SHORT_VOWELS));
        $this->assertFalse(C::isValidDrill('ba.dammatan', C::STAGE_SUKUN_SHADDA));
        $this->assertTrue(C::isValidDrill('ba.dammatan', C::STAGE_TANWEEN));
        // Nonsense never validates.
        $this->assertFalse(C::isValidDrill('ba.nonsense', C::STAGE_MADD));
        $this->assertFalse(C::isValidDrill('notaletter', C::STAGE_MADD));
    }

    #[Test]
    public function a_marked_drill_renders_the_letter_carrying_the_real_combining_mark(): void
    {
        $fatha = C::describeDrill('ba.fatha');

        $this->assertSame("ب\u{064E}", $fatha['text']);
        $this->assertSame('Fatha', $fatha['label']);
        $this->assertSame('ba', $fatha['sound']);

        // A long vowel is a LETTER after a short vowel, never a mark: بَا is two
        // letters, and describing it as one carrying a mark would be wrong.
        $madd = C::describeDrill('ba.madd_alif');
        $this->assertSame("ب\u{064E}ا", $madd['text']);
        $this->assertSame(C::STAGE_MADD, $madd['stage']);

        $this->assertNull(C::describeDrill('ba.not_a_mark'));
    }

    #[Test]
    public function an_unset_stage_is_the_first_stage_rather_than_an_error(): void
    {
        // Every group that existed before this feature has a null stage.
        $this->assertSame(C::STAGE_LETTERS, C::normaliseStage(null));
        $this->assertSame(C::STAGE_LETTERS, C::normaliseStage('nonsense'));
        $this->assertCount(28, C::syllabus(null));
    }

    // ------------------------------------------------------------- avatars

    #[Test]
    public function the_avatar_catalogue_is_the_forty_drawings_that_ship(): void
    {
        $catalogue = Avatar::catalogue();

        $this->assertCount(2 * 4 * 5, $catalogue['options']);
        $this->assertCount(2, $catalogue['characters']);
        $this->assertCount(4, $catalogue['tones']);
        $this->assertCount(5, $catalogue['colors']);
    }

    #[Test]
    public function an_incomplete_or_unknown_avatar_resolves_to_null_not_a_default(): void
    {
        // Null means "draw initials". Substituting a default would show a child
        // somebody else's face.
        $this->assertNull(Avatar::imageUrl(null, null, null));
        $this->assertNull(Avatar::imageUrl('ameera', 'tone2', null));
        $this->assertNull(Avatar::imageUrl('ameera', 'tone9', 'pink'));
        $this->assertNull(Avatar::imageUrl('somebody', 'tone1', 'pink'));

        $this->assertStringContainsString(
            'images/avatars/ameera_tone2_pink.webp',
            Avatar::imageUrl('ameera', 'tone2', 'pink')
        );
    }

    #[Test]
    public function every_catalogued_avatar_has_a_file_on_disk(): void
    {
        // The catalogue is a constant and the images are files; if they drift,
        // a picker renders broken images and nothing else complains.
        foreach (Avatar::catalogue()['options'] as $option) {
            $path = public_path("images/avatars/{$option['character']}_{$option['tone']}_{$option['color']}.webp");
            $this->assertFileExists($path);
        }
    }
}
