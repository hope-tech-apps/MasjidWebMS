<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The school's separated Qur'an, Arabic and Islamic Studies weeks, against the
 * school's own document.
 *
 * Islamic content is imported VERBATIM. This test trusts neither the extractor
 * that first read the .docx nor the generator that wrote the data file: it
 * re-reads the committed .txt by LINE NUMBER and compares every stored string
 * with assertSame, byte for byte, with no trimming. The .txt is itself pinned to
 * the Drive download by hash.
 */
class CurriculumSplitSourceFaithfulnessTest extends TestCase
{
    private const TXT_SHA256 = '40cc44ac38a4b8b2be01fd07ef7fdf081682f0ff17ca78968992024a82ea8a0f';

    private const SOURCE = 'database/curriculum/sources/al-razi-detailed-pacing-plan-2026-09-07.txt';

    private const DATA = 'database/curriculum/al-razi-qai-split-2026-27-q1.json';

    private const COMBINED = "Qur\u{2019}an & Islamic Studies";

    /** @var list<string> */
    private array $lines;

    /** @var array<string, mixed> */
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 2);
        $this->lines = explode("\n", (string) file_get_contents("{$root}/" . self::SOURCE));
        $this->data = json_decode((string) file_get_contents("{$root}/" . self::DATA), true, flags: JSON_THROW_ON_ERROR);
    }

    private function line(int $n): string
    {
        return $this->lines[$n - 1];
    }

    #[Test]
    public function the_committed_source_is_the_drive_download(): void
    {
        $file = dirname(__DIR__, 2) . '/' . self::SOURCE;

        $this->assertSame(self::TXT_SHA256, hash_file('sha256', $file));
        $this->assertSame(self::TXT_SHA256, $this->data['source']['txt_sha256']);
        $this->assertSame(self::SOURCE, $this->data['source']['txt_path']);
        $this->assertSame(171429, filesize($file));
    }

    #[Test]
    public function every_imported_string_is_its_source_line_byte_for_byte(): void
    {
        $this->assertCount(96, $this->data['rows']);

        foreach ($this->data['rows'] as $row) {
            $where = "{$row['grade_label']} / {$row['subject']} / week {$row['week_no']}";

            foreach (['week_no', 'standard_code', 'focus', 'objective', 'learning_outcome'] as $field) {
                $source = $this->line($row['source_lines'][$field]);
                $stored = (string) $row[$field];

                $this->assertSame(
                    $source,
                    $stored,
                    "{$where} {$field}: source line {$row['source_lines'][$field]} is " . bin2hex($source) . ', stored ' . bin2hex($stored)
                );
            }
        }
    }

    #[Test]
    public function every_block_is_the_quarter_table_it_claims(): void
    {
        $tables = $this->data['source']['tables'];
        $this->assertCount(12, $tables);

        $gradeWord = ['Pre-Kindergarten' => 'PRE-K', 'Kindergarten' => 'KINDERGARTEN', 'Grade 1' => 'GRADE 1', 'Grade 2' => 'GRADE 2'];
        $subjectWord = ["Qur'an" => 'QUR', 'Arabic Language' => 'ARABIC', 'Islamic Studies' => 'ISLAMIC STUDIES'];

        foreach ($tables as $t) {
            $where = "{$t['grade_label']} / {$t['subject']}";

            $this->assertSame($t['heading'], $this->line($t['heading_line']), "{$where}: heading");
            $this->assertStringStartsWith('QUARTER 1 ', $t['heading']);
            $this->assertStringContainsString($gradeWord[$t['grade_label']], $t['heading'], "{$where}: grade in heading");
            $this->assertStringContainsString($subjectWord[$t['subject']], $t['heading'], "{$where}: subject in heading");
            $this->assertSame('Quarter Standards Table', $this->line($t['first_line'] - 1), "{$where}: table title");

            foreach (['Week', 'Standard Code', 'Focus Skill', 'Objective', 'Learning Outcome'] as $i => $header) {
                $this->assertSame($header, $this->line($t['first_line'] + $i), "{$where}: header cell {$i}");
            }

            $this->assertSame($t['first_line'] + 44, $t['last_line'], "{$where}: 8 weeks of 5 cells after the header");

            foreach ($this->data['rows'] as $row) {
                if ($row['grade_label'] !== $t['grade_label'] || $row['subject'] !== $t['subject']) {
                    continue;
                }

                foreach ($row['source_lines'] as $field => $n) {
                    $this->assertGreaterThanOrEqual($t['first_line'] + 5, $n, "{$where} week {$row['week_no']} {$field}");
                    $this->assertLessThanOrEqual($t['last_line'], $n, "{$where} week {$row['week_no']} {$field}");
                }

                // Cell of week n: week at first+5n, code +1, focus +2, objective +3, outcome +4.
                $at = $t['first_line'] + 5 * $row['week_no'];
                $this->assertSame(
                    ['week_no' => $at, 'standard_code' => $at + 1, 'focus' => $at + 2, 'objective' => $at + 3, 'learning_outcome' => $at + 4],
                    $row['source_lines']
                );
            }
        }
    }

    #[Test]
    public function the_file_is_twelve_blocks_of_eight_weeks(): void
    {
        $byBlock = [];

        foreach ($this->data['rows'] as $row) {
            $byBlock["{$row['grade_label']}|{$row['subject']}"][] = $row['week_no'];

            $this->assertSame(1, $row['quarter']);
            $this->assertNull($row['assessment_note']);
            $this->assertSame((string) $row['week_no'], $this->line($row['source_lines']['week_no']));
        }

        $this->assertCount(12, $byBlock);

        foreach ($byBlock as $block => $weeks) {
            $this->assertSame(range(1, 8), $weeks, $block);
        }

        // The catalogue's exact bytes: U+0027 in Qur'an, and NOT the U+2019 the combined column uses.
        $subjects = array_values(array_unique(array_column($this->data['rows'], 'subject')));
        sort($subjects);
        $this->assertSame(['Arabic Language', 'Islamic Studies', "Qur'an"], $subjects);
        $this->assertSame('51757227616e', bin2hex("Qur'an"));
        $this->assertContains('51757227616e', array_map('bin2hex', $subjects));
        $this->assertNotContains(self::COMBINED, $subjects);

        $this->assertSame(
            ['Pre-Kindergarten', 'Kindergarten', 'Grade 1', 'Grade 2'],
            array_values(array_unique(array_column($this->data['rows'], 'grade_label')))
        );

        // (code, focus) repeats inside a subject with a different Objective each time.
        foreach ([
            ['Kindergarten', "Qur'an", 'K.QUR.MEM.1', 'Memorization', [4, 7]],
            ['Grade 1', "Qur'an", '1.QUR.MEM.1', 'Memorization', [4, 7]],
            ['Grade 2', 'Arabic Language', '2.AAL.ALPH.1', 'Alphabet', [2, 3]],
        ] as [$grade, $subject, $code, $focus, $weeks]) {
            $hits = array_values(array_filter(
                $this->data['rows'],
                fn (array $r): bool => $r['grade_label'] === $grade && $r['subject'] === $subject
                    && $r['standard_code'] === $code && $r['focus'] === $focus
            ));

            $this->assertSame($weeks, array_column($hits, 'week_no'), "{$code} repeats in weeks " . implode(',', $weeks));
            $this->assertCount(2, array_unique(array_column($hits, 'objective')), "{$code}: a different objective each time");
        }
    }

    #[Test]
    public function the_kindergarten_quran_week_four_cell_is_the_schools_own_words(): void
    {
        $row = collect($this->data['rows'])->first(
            fn (array $r): bool => $r['grade_label'] === 'Kindergarten' && $r['subject'] === "Qur'an" && $r['week_no'] === 4
        );

        $this->assertSame('K.QUR.MEM.1', $row['standard_code']);
        $this->assertSame('Memorization', $row['focus']);
        $this->assertSame('Memorize Surah Al-Ikhlāṣ', $row['objective']);
        $this->assertSame('Recite independently', $row['learning_outcome']);
        $this->assertSame(3948, $row['source_lines']['objective']);
    }

    #[Test]
    public function replaces_is_the_combined_column_for_those_weeks_only(): void
    {
        $replaces = $this->data['replaces'];
        $this->assertCount(32, $replaces);

        $base = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/database/curriculum/al-razi-pacing-2026-27.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $baseCells = [];
        foreach ($base['rows'] as $r) {
            $baseCells[$r['grade_label'] . '|' . $r['subject'] . '|' . $r['week_no']] = true;
        }

        $seen = [];
        foreach ($replaces as $r) {
            $this->assertSame('517572e28099616e20262049736c616d69632053747564696573', bin2hex($r['subject']));
            $this->assertSame(self::COMBINED, $r['subject']);
            $this->assertContains($r['grade_label'], ['Pre-Kindergarten', 'Kindergarten', 'Grade 1', 'Grade 2']);
            $this->assertGreaterThanOrEqual(1, $r['week_no']);
            $this->assertLessThanOrEqual(8, $r['week_no']);
            $this->assertArrayHasKey($r['grade_label'] . '|' . $r['subject'] . '|' . $r['week_no'], $baseCells);
            $seen[$r['grade_label'] . '|' . $r['week_no']] = true;
        }

        $this->assertCount(32, $seen, 'four grades times weeks 1 to 8, each once');

        // Nothing the file inserts is also something it deletes.
        foreach ($this->data['rows'] as $r) {
            $this->assertNotContains($r['subject'], [self::COMBINED]);
        }
    }

    #[Test]
    public function grade_1_islamic_studies_copies_agree(): void
    {
        $this->assertSame(
            array_slice($this->lines, 5878, 45),
            array_slice($this->lines, 6116, 45),
            'lines 5879-5923 and 6117-6161 are the same table, so the choice between them is immaterial'
        );
    }

    #[Test]
    public function the_provenance_names_the_schools_document(): void
    {
        $this->assertSame('1Y_-gek3OxcOlDKYUxK1wX1UXxf22_8Ys', $this->data['source']['drive_file_id']);
        $this->assertSame('2026-09-07', $this->data['source']['drive_modified']);
        $this->assertSame(
            '98332b22dd36298ed5d575f22e44745069fd94ddada25929fcfa1f6fe34119e1',
            $this->data['source']['docx_sha256']
        );
        $this->assertSame(['id' => 14, 'name_contains' => 'razi', 'org_type' => 'school'], $this->data['for_masjid']);
        $this->assertLessThanOrEqual(120, mb_strlen($this->data['source_label']));
        $this->assertSame('al-razi-pacing-2026-27.json', $this->data['applies_after']);
    }
}
