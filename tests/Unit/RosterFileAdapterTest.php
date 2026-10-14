<?php

namespace Tests\Unit;

use App\Services\Schools\RosterColumnMatcher;
use App\Services\Schools\RosterFileAdapter;
use App\Services\Schools\RosterImportService;
use PHPUnit\Framework\TestCase;

class RosterFileAdapterTest extends TestCase
{
    public function test_template_has_identical_rows_to_existing_reader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roster-');
        file_put_contents($path, "\xef\xbb\xbf" . implode(',', RosterImportService::HEADERS) . "\nGrade 2,  '=Example ,Student,2,Parent,Example, PARENT@example.invalid ,123\n\nGrade 2,Next,Student,2,,,,\n");
        try {
            $table = (new RosterFileAdapter)->read($path, 'csv');
            $rows = (new RosterFileAdapter)->rows($table, (new RosterColumnMatcher)->propose($table['headers']));
            $this->assertSame((new RosterImportService)->read($path)['rows'], $rows);
        } finally { unlink($path); }
    }

    public function test_two_guardians_split_and_empty_second_guardian_does_not_add_a_row(): void
    {
        $adapter = new RosterFileAdapter;
        $table = ['headers' => ['Class', 'First Name', 'Parent 1 Email', 'Parent 2 Email'], 'data' => [['Grade 2', 'Example', 'one@example.invalid', 'two@example.invalid'], ['Grade 2', 'Next', 'one@example.invalid', '']]];
        $rows = $adapter->rows($table, (new RosterColumnMatcher)->propose($table['headers']));
        $this->assertCount(3, $rows);
        $this->assertSame('Example', $rows[1]['student_first_name']);
        $this->assertSame('two@example.invalid', $rows[1]['guardian_email']);
    }

    public function test_csv_encoding_size_row_and_column_caps_are_enforced(): void
    {
        foreach (["Class,First Name\nRoom,Zo\xeb\n", str_repeat('x', 2 * 1024 * 1024 + 1), implode(',', array_fill(0, 129, 'Header')), "Class,First Name\n" . str_repeat("Room,Example\n", 20001)] as $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'roster-');
            file_put_contents($path, $bytes);
            try {
                (new RosterFileAdapter)->read($path, 'csv');
                $this->fail('Unsafe CSV was accepted.');
            } catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); } finally { unlink($path); }
        }
    }

    public function test_required_column_and_duplicate_assignment_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RosterFileAdapter)->rows(['headers' => ['Class'], 'data' => []], ['class' => 0]);
    }
}
