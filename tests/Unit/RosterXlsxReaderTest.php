<?php

namespace Tests\Unit;

use App\Services\Schools\RosterXlsxReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\RosterWorkbook;

class RosterXlsxReaderTest extends TestCase
{
    use RosterWorkbook;

    public function test_first_sheet_strings_sparse_cells_cached_formulas_booleans_and_dates(): void
    {
        $path = $this->workbook('<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>Name</t></is></c></row><row r="2"><c r="A2" t="s"><v>1</v></c><c r="C2" t="b"><v>1</v></c><c r="D2"><f>2+2</f><v>4</v></c><c r="E2" s="1"><v>45292</v></c></row>', [
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Class</t></si><si><r><t>Grade </t></r><r><t>2</t></r><rPh><t>phonetic</t></rPh></si></sst>',
            'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet2.xml' => 'Not XML; second sheets are not read.',
        ]);
        try {
            $this->assertSame([['Class', 'Name'], ['Grade 2', '', '1', '4', '2024-01-01']], (new RosterXlsxReader)->read($path));
        } finally { unlink($path); }
    }

    public function test_1904_and_custom_date_formats(): void
    {
        $path = $this->workbook('<row><c r="A1" s="0"><v>0</v></c></row>', ['xl/styles.xml' => '<styleSheet><numFmts><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts><cellXfs><xf numFmtId="164"/></cellXfs></styleSheet>'], true);
        try { $this->assertSame([['1904-01-01']], (new RosterXlsxReader)->read($path)); } finally { unlink($path); }
    }

    public function test_guards_reject_bombs_dtd_rows_columns_and_encrypted_entries(): void
    {
        $cases = [
            ['<row r="20002"><c r="A20002"><v>1</v></c></row>', []],
            ['<row><c r="DY1"><v>1</v></c></row>', []],
            ['', ['xl/sharedStrings.xml' => str_repeat('x', RosterXlsxReader::MAX_ENTRY_BYTES + 1)]],
            ['', ['xl/workbook.xml' => '<!DOCTYPE workbook [<!ENTITY x SYSTEM "file:///nonexistent">]><workbook>&x;</workbook>']],
            ['', ['EncryptedPackage' => 'encrypted']],
            ['', ['xl/workbook.xml' => '<broken>']],
            ['', ['xl/workbook.xml' => mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE workbook [<!ENTITY x SYSTEM "file:///nonexistent">]><workbook>&x;</workbook>', 'UTF-16', 'UTF-8')]],
            ['', ['xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId7" Target="https://example.invalid/roster.xml" TargetMode="External"/></Relationships>']],
            ['', ['xl/vbaProject.bin' => 'macro']],
            ['<row><c r="A1" t="s"><v>999</v></c></row>', []],
            ['<row><c r="A1" t="e"><v>#VALUE!</v></c></row>', []],
        ];
        foreach ($cases as [$sheet, $extra]) {
            $path = $this->workbook($sheet, $extra);
            try {
                (new RosterXlsxReader)->read($path);
                $this->fail('Unsafe workbook was accepted.');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            } finally { unlink($path); }
        }
    }

    public function test_total_uncompressed_size_and_entry_count_caps(): void
    {
        foreach ([array_fill_keys(['a', 'b', 'c', 'd', 'e'], str_repeat('x', 7 * 1024 * 1024)), array_fill_keys(array_map(fn ($i) => "extra-{$i}", range(1, 129)), '')] as $extra) {
            $path = $this->workbook('', $extra);
            try {
                (new RosterXlsxReader)->read($path);
                $this->fail('Archive cap was not enforced.');
            } catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); } finally { unlink($path); }
        }
    }

    public function test_encrypted_zip_entry_is_refused(): void
    {
        $path = $this->workbook('');
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->setPassword('made-up-password');
        $this->assertTrue($zip->setEncryptionName('xl/worksheets/roster.xml', \ZipArchive::EM_AES_256));
        $zip->close();
        try {
            (new RosterXlsxReader)->read($path);
            $this->fail('Encrypted ZIP was accepted.');
        } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Encrypted', $e->getMessage()); } finally { unlink($path); }
    }

    public function test_file_size_cap_and_non_zip_encryption_refusal(): void
    {
        foreach ([str_repeat('x', RosterXlsxReader::MAX_FILE_BYTES + 1), "\xd0\xcf\x11\xe0encrypted"] as $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'roster-');
            file_put_contents($path, $bytes);
            try {
                (new RosterXlsxReader)->read($path);
                $this->fail('Invalid file accepted.');
            } catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); } finally { unlink($path); }
        }
    }
}
