<?php

namespace Tests\Unit;

use App\Support\Arabic\ArabicCurriculum as C;
use App\Support\Letters\EnglishCurriculum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three sounding groups — throat, heavy, light.
 *
 * These are CONTENT assertions before they are code ones. A letter in the wrong
 * group teaches a child to pronounce the Qurʾān wrongly, and unlike a layout bug
 * nothing downstream will ever surface it. Each membership below was checked
 * against tajweed sources and each exclusion is deliberate, so a future edit
 * that "tidies" one of these lists has to come through this file first.
 */
class ArabicLetterGroupsTest extends TestCase
{
    #[Test]
    public function there_are_three_groups_and_they_are_not_stages(): void
    {
        $this->assertSame(['halq', 'tafkheem', 'tarqeeq'], C::groupIds());

        // The ladder must be untouched: a group on it would be cumulative, and
        // غ would have to sit in two stages at once.
        foreach (C::groupIds() as $group) {
            $this->assertNotContains($group, C::STAGES);
        }
    }

    #[Test]
    public function the_throat_letters_are_the_five_that_exist_in_this_alphabet(): void
    {
        // Classically SIX: ء ه ع ح غ خ. Hamza is not one of the 28, so five.
        $this->assertSame(['ha', 'ayn', 'haa', 'ghayn', 'kha'], C::groupLetters(C::GROUP_HALQ));
    }

    #[Test]
    public function alif_is_never_a_throat_letter(): void
    {
        // The single most likely wrong "fix": adding alif to make the list six.
        // Alif is sounded from al-jawf, and teaching it as a throat letter
        // breaks the madd lesson later.
        $this->assertNotContains('alif', C::groupLetters(C::GROUP_HALQ));
        $this->assertStringContainsString('Hamza', C::GROUPS[C::GROUP_HALQ]['note']);
    }

    #[Test]
    public function the_heavy_letters_are_the_seven_huruf_al_istila(): void
    {
        // خ ص ض غ ط ق ظ — خُصَّ ضَغْطٍ قِظْ, and no others.
        $this->assertSame(
            ['kha', 'sad', 'dad', 'ghayn', 'taa', 'qaf', 'zaa'],
            C::groupLetters(C::GROUP_TAFKHEEM)
        );
        $this->assertCount(7, C::HEAVY_LETTERS);
    }

    #[Test]
    public function the_conditional_letters_are_in_neither_group(): void
    {
        // ر is heavy on fatha/damma and light on kasra; ل is heavy only in الله;
        // ا copies whatever precedes it. None can be drawn as a tile a child is
        // marked right or wrong on.
        foreach (['ra', 'lam', 'alif'] as $letter) {
            $this->assertNotContains($letter, C::groupLetters(C::GROUP_TAFKHEEM), "{$letter} is not always heavy");
            $this->assertNotContains($letter, C::groupLetters(C::GROUP_TARQEEQ), "{$letter} is not always light");
        }
    }

    #[Test]
    public function the_light_letters_are_derived_and_never_drift_from_the_heavy_list(): void
    {
        $light = C::groupLetters(C::GROUP_TARQEEQ);

        $this->assertSame(
            array_values(array_diff(array_keys(C::LETTERS), C::HEAVY_LETTERS, C::CONDITIONAL_LETTERS)),
            $light
        );
        $this->assertCount(28 - 7 - 3, $light);
    }

    #[Test]
    public function both_haa_and_ha_are_light_and_are_not_confused_for_one_another(): void
    {
        // ح (id haa) and ه (id ha) are the classic transcription slip. Both are
        // light, both must be present, and they are different letters.
        $light = C::groupLetters(C::GROUP_TARQEEQ);

        $this->assertContains('haa', $light);
        $this->assertContains('ha', $light);
        $this->assertSame('ح', C::LETTERS['haa'][0]);
        $this->assertSame('ه', C::LETTERS['ha'][0]);
    }

    #[Test]
    public function a_letter_may_belong_to_two_groups(): void
    {
        // غ and خ are throat letters AND heavy letters. This is exactly why the
        // groups cannot be stages.
        foreach (['ghayn', 'kha'] as $letter) {
            $this->assertContains($letter, C::groupLetters(C::GROUP_HALQ));
            $this->assertContains($letter, C::groupLetters(C::GROUP_TAFKHEEM));
        }
    }

    #[Test]
    public function group_drills_are_one_per_letter_and_name_their_group(): void
    {
        $drills = C::groupDrills(C::GROUP_HALQ);

        $this->assertCount(5, $drills);
        $this->assertSame('ha.group_halq', $drills[0]);
        $this->assertSame(C::GROUP_HALQ, C::groupOfDrill('ha.group_halq'));
    }

    #[Test]
    public function a_letter_outside_a_group_cannot_be_drilled_as_part_of_it(): void
    {
        // `sad.group_halq` parses, but ṣād is not a throat letter. Accepting it
        // would let a client invent a drill the group's own total never counts.
        $this->assertNull(C::groupOfDrill('sad.group_halq'));
        $this->assertNull(C::describeDrill('sad.group_halq'));
        $this->assertFalse(C::isValidDrill('sad.group_halq', C::STAGE_MADD));
    }

    #[Test]
    public function group_drills_are_valid_at_the_very_first_stage(): void
    {
        // How a letter is sounded is not unlocked by the ladder: a class in its
        // first term practising throat letters must be able to record it.
        $this->assertTrue(C::isValidDrill('ayn.group_halq', C::STAGE_LETTERS));
        $this->assertTrue(C::isValidDrill('qaf.group_tafkheem', null));
    }

    #[Test]
    public function groups_are_absent_from_the_syllabus_so_no_class_progress_moved(): void
    {
        // The whole reason groups carry their own totals. If a group drill ever
        // enters the syllabus, every existing class's bar jumps backwards.
        foreach (C::STAGES as $stage) {
            foreach (C::syllabus($stage) as $drill) {
                $this->assertNull(C::groupOfDrill($drill), "{$drill} leaked into the {$stage} syllabus");
            }
        }

        $this->assertCount(28, C::syllabus(C::STAGE_LETTERS));
    }

    #[Test]
    public function a_described_group_drill_belongs_to_no_stage(): void
    {
        $described = C::describeDrill('kha.group_tafkheem');

        $this->assertSame(C::GROUP_TAFKHEEM, $described['group']);
        $this->assertNull($described['stage']);
        $this->assertSame('خ', $described['text']);
    }

    #[Test]
    public function every_described_drill_answers_the_group_question(): void
    {
        // A client switches on `group`; a missing key would read as undefined
        // rather than as "not a group drill".
        foreach (['ba', 'ba.fatha', 'ba.madd_alif'] as $drill) {
            $this->assertArrayHasKey('group', C::describeDrill($drill));
            $this->assertNull(C::describeDrill($drill)['group']);
        }
    }

    #[Test]
    public function the_english_track_has_no_groups_and_says_so_plainly(): void
    {
        $this->assertSame([], EnglishCurriculum::groups());
        $this->assertSame([], EnglishCurriculum::groupDrills('halq'));
    }

    #[Test]
    public function a_stage_owns_only_the_drills_it_introduces(): void
    {
        // The bug this was written for: "mark all the Long Vowels drills" used
        // to resolve to the whole cumulative syllabus.
        $this->assertCount(28, C::stageDrills(C::STAGE_LETTERS));
        $this->assertCount(28 * 3, C::stageDrills(C::STAGE_MADD));

        foreach (C::stageDrills(C::STAGE_MADD) as $drill) {
            $this->assertStringContainsString('.madd_', $drill);
        }

        $this->assertCount(28 * 3, C::stageDrills(C::STAGE_SHORT_VOWELS));
        $this->assertCount(28 * 2, C::stageDrills(C::STAGE_SUKUN_SHADDA));
        $this->assertCount(28 * 3, C::stageDrills(C::STAGE_TANWEEN));
    }

    #[Test]
    public function the_stages_own_drills_add_up_to_the_cumulative_syllabus(): void
    {
        // If these two ever disagree, a drill exists that no stage introduces
        // and "everything" and the sum of the parts stop matching.
        $summed = [];

        foreach (C::STAGES as $stage) {
            $summed = array_merge($summed, C::stageDrills($stage));
        }

        sort($summed);
        $full = C::syllabus(C::STAGE_MADD);
        sort($full);

        $this->assertSame($full, $summed);
    }
}
