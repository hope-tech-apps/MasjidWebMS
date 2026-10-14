<?php

namespace Tests\Unit;

use App\Services\Schools\RosterColumnMatcher;
use App\Services\Schools\RosterImportService;
use PHPUnit\Framework\TestCase;

class RosterColumnMatcherTest extends TestCase
{
    public function test_template_and_synonyms_are_unique_and_unknown_is_unmatched(): void
    {
        $matcher = new RosterColumnMatcher;
        $this->assertSame(array_combine(RosterImportService::HEADERS, range(0, 7)), array_intersect_key($matcher->propose(RosterImportService::HEADERS), array_flip(RosterImportService::HEADERS)));
        $map = $matcher->propose(['Homeroom', 'Given Name', 'Surname', 'Grade Level', 'Mother Given Name', 'Parent One Surname', 'Parent 1 E-mail', 'Guardian Mobile', 'Parent Two Email', 'Secret Code']);
        $this->assertSame(0, $map['class']);
        $this->assertSame(1, $map['student_first_name']);
        $this->assertSame(6, $map['guardian_email']);
        $this->assertSame(8, $map['guardian_2_email']);
        $this->assertSame(count(array_filter($map, fn ($v) => $v !== null)), count(array_unique(array_filter($map, fn ($v) => $v !== null))));
        $this->assertSame($matcher->signature(['Parent One E-mail']), $matcher->signature(['guardian 1 email']));
        $this->assertNotSame($matcher->signature(['Class', 'Name']), $matcher->signature(['Name', 'Class']));
        $this->assertNull($matcher->propose(['Student ID'])['student_first_name']);
        $parents = $matcher->propose(['Mother Email', 'Father Email']);
        $this->assertSame(0, $parents['guardian_email']);
        $this->assertSame(1, $parents['guardian_2_email']);
        $this->assertSame(0, $matcher->propose(['Parent2Email'])['guardian_2_email']);
        $this->assertSame([], RosterColumnMatcher::BUILT_IN_MAPPINGS);
    }

    public function test_ambiguous_columns_are_not_guessed(): void
    {
        $map = (new RosterColumnMatcher)->propose(['First Name', 'Given Name', 'Class']);
        $this->assertNull($map['student_first_name']);
    }
}
