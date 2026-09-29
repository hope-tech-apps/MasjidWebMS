<?php

namespace Tests\Unit;

use App\Support\GradeLevel;
use App\Support\SubjectKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two spellings of one subject are one subject (T-001.3), and a grade is a grade
 * however the roster, the weekly guide and the Drive curriculum each spell it.
 */
class SubjectKeyTest extends TestCase
{
    #[Test]
    public function every_apostrophe_a_person_can_type_folds_to_the_same_key(): void
    {
        $expected = 'quran';

        foreach (["Qur'an", "Qur\u{2019}an", "Qur\u{2018}an", "Qur\u{02BC}an", "Qur\u{02BB}an", 'QUR\'AN', '  Quran  '] as $spelling) {
            $this->assertSame($expected, SubjectKey::for($spelling), json_encode($spelling));
        }
    }

    #[Test]
    public function whitespace_collapses_and_a_blank_name_is_no_subject(): void
    {
        $this->assertSame('islamic studies', SubjectKey::for("Islamic \t  Studies"));
        $this->assertSame('', SubjectKey::for(null));
        $this->assertSame('', SubjectKey::for("   \n"));
        $this->assertNull(SubjectKey::clean('   '));
        $this->assertSame('Islamic Studies', SubjectKey::clean("  Islamic   Studies "));
    }

    #[Test]
    public function a_key_is_never_longer_than_its_name_so_it_fits_the_same_column(): void
    {
        // The reason for MB_CASE_LOWER_SIMPLE: the full mapping lengthens 'İ'.
        $name = str_repeat("İ", 64);
        $this->assertLessThanOrEqual(64, mb_strlen(SubjectKey::for($name)));
    }

    #[Test]
    public function the_combined_weekly_column_belongs_to_both_staff_subjects_and_maths_to_none(): void
    {
        $this->assertSame(['quran'], SubjectKey::staffKeys(SubjectKey::for("Qur'an")));
        $this->assertSame(['islamic_studies'], SubjectKey::staffKeys(SubjectKey::for('Islamic Studies')));
        $this->assertSame(['arabic'], SubjectKey::staffKeys(SubjectKey::for('Arabic Language')));
        $this->assertSame(['arabic'], SubjectKey::staffKeys(SubjectKey::for('Arabic')));
        $this->assertSame(
            ['quran', 'islamic_studies'],
            SubjectKey::staffKeys(SubjectKey::for("Qur\u{2019}an & Islamic Studies"))
        );
        $this->assertSame([], SubjectKey::staffKeys(SubjectKey::for('Mathematics')));
        $this->assertSame([], SubjectKey::staffKeys(''), 'no subject belongs to no staff subject');
    }

    #[Test]
    #[DataProvider('gradeSpellings')]
    public function a_grade_has_one_key_however_it_is_spelled(string $a, string $b): void
    {
        $this->assertSame(GradeLevel::key($a), GradeLevel::key($b));
        $this->assertNotNull(GradeLevel::key($a));
    }

    /** @return array<string, array{string, string}> */
    public static function gradeSpellings(): array
    {
        return [
            'pre-k' => ['Pre-K', 'Pre-Kindergarten'],
            'prek' => ['PreK', 'pre k'],
            'kg' => ['KG', 'Kindergarten'],
            'first' => ['1st', 'Grade 1'],
            'first grade' => ['1st Grade', 'first'],
            'ninth' => ['9th', 'Grade 9'],
            'twelfth' => ['12th', 'twelfth grade'],
        ];
    }

    #[Test]
    public function different_grades_never_share_a_key_and_a_blank_label_has_none(): void
    {
        $this->assertNotSame(GradeLevel::key('1st'), GradeLevel::key('11th'));
        $this->assertNotSame(GradeLevel::key('KG'), GradeLevel::key('1st'));
        $this->assertNotSame(GradeLevel::key('Pre-K'), GradeLevel::key('KG'));
        $this->assertNull(GradeLevel::key(null));
        $this->assertNull(GradeLevel::key('  '));
        // An unrecognised label still equals itself.
        $this->assertSame(GradeLevel::key('Level A'), GradeLevel::key('level  a'));
        $this->assertTrue(GradeLevel::in('Grade 3', ['1st', '3rd']));
        $this->assertFalse(GradeLevel::in('Grade 4', ['1st', '3rd']));
        $this->assertFalse(GradeLevel::in(null, ['1st']));
    }
}
