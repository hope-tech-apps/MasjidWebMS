<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\RosterImportMapping;
use App\Models\User;
use App\Services\Schools\RosterImportService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Support\RosterWorkbook;

class RosterImportMappingTest extends TestCase
{
    use RefreshDatabase, RosterWorkbook;

    private Masjid $school;
    private Masjid $otherSchool;
    private User $admin;
    private Group $class;
    private const HEADER = 'class,student_first_name,student_last_name,grade,guardian_first_name,guardian_last_name,guardian_email,guardian_phone' . "\n";

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        app(TenantContext::class)->forgetTenant();

        $this->school = $this->makeMasjid();
        $this->otherSchool = $this->makeMasjid();

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->school->user_id = $this->admin->id;
        $this->school->save();
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->admin->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 2', 'slug' => 'grade-2-' . uniqid(),
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->school->id}/records/roster-import{$suffix}";
    }

    private function csv(string $body, string $name = 'roster.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    /** @return array<string, mixed> the preview payload */
    private function previewOf(UploadedFile $file): array
    {
        $response = $this->post($this->url('/preview'), ['file' => $file], ['Accept' => 'application/json']);
        $response->assertOk();

        return $response->json('data');
    }

    // -------------------------------------------- 1. the preview is a preview


    #[Test]
    public function template_mapping_has_identical_preview_and_commit_results(): void
    {
        $csv = self::HEADER . "Grade 2,Example,Student,2,Parent,Example,PARENT@example.invalid,123\n";
        $legacy = $this->post($this->url('/preview'), ['file' => $this->csv($csv)])->assertOk()->json('data');
        $inspection = $this->post($this->url('/columns'), ['file' => $this->csv($csv)])->assertOk()->json('data');
        $mapped = $this->post($this->url('/preview'), ['file' => $this->csv($csv), 'mapping' => json_encode($inspection['mapping'])])->assertOk()->json('data');
        unset($legacy['receipt'], $mapped['receipt']);
        $this->assertSame($legacy, $mapped);
        $this->assertSame(0, Contact::count());
        $preview = $this->post($this->url('/preview'), ['file' => $this->csv($csv), 'mapping' => json_encode($inspection['mapping'])])->json('data');
        $first = $this->post($this->url(), ['file' => $this->csv($csv), 'receipt' => $preview['receipt']])->assertCreated()->json('data');
        $this->assertSame(['students' => 1, 'guardians' => 1, 'memberships' => 1, 'edges' => 1], $first['created']);
        $next = $this->post($this->url('/preview'), ['file' => $this->csv($csv)])->json('data');
        $second = $this->post($this->url(), ['file' => $this->csv($csv), 'receipt' => $next['receipt']])->assertCreated()->json('data');
        $this->assertSame(['students' => 0, 'guardians' => 0, 'memberships' => 0, 'edges' => 0], $second['created']);
        $this->assertSame(['students' => 1, 'guardians' => 1], $second['matched']);
    }

    #[Test]
    public function xlsx_two_guardians_and_receipt_bound_mapping_use_the_existing_commit(): void
    {
        $headers = ['Homeroom', 'First Name', 'Last Name', 'Parent 1 Given Name', 'Parent 1 Last Name', 'Parent 1 Email', 'Parent Two Given Name', 'Parent Two Last Name', 'Parent Two Email'];
        $values = ['Grade 2', 'Example', 'Student', 'First', 'Example', 'one@example.invalid', 'Second', 'Example', 'two@example.invalid'];
        $sheet = '';
        foreach ([$headers, $values] as $r => $row) {
            $sheet .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $v) $sheet .= '<c r="' . chr(65 + $c) . ($r + 1) . '" t="inlineStr"><is><t>' . $v . '</t></is></c>';
            $sheet .= '</row>';
        }
        $path = $this->workbook($sheet);
        try {
            $upload = fn () => new UploadedFile($path, 'sample.xlsx', null, null, true);
            $map = $this->post($this->url('/columns'), ['file' => $upload()])->assertOk()->json('data.mapping');
            $preview = $this->post($this->url('/preview'), ['file' => $upload(), 'mapping' => json_encode($map)])->assertOk()->json('data');
            $this->assertSame(2, $preview['totals']['guardians']['to_create']);
            $map['guardian_2_email'] = null; // commit must use what was previewed, not new client choices
            $result = $this->post($this->url(), ['file' => $upload(), 'mapping' => json_encode($map), 'receipt' => $preview['receipt']])->assertCreated()->json('data');
            $this->assertSame(2, $result['created']['edges']);
            $this->assertSame(2, $result['created']['guardians']);
        } finally { unlink($path); }
    }

    /** Parent 1 / Parent 2 rows used to count as two lines, so a refusal named the wrong spreadsheet row. */
    #[Test]
    public function refusals_and_lines_name_the_spreadsheet_row_not_the_split_guardian_rows(): void
    {
        $csv = "Homeroom,First Name,Last Name,Parent 1 First,Parent 1 Last,Parent 1 Email,Parent 2 First,Parent 2 Last,Parent 2 Email\n"
            . "Grade 2,Ilyas,Example,One,Example,one@example.invalid,Two,Example,two@example.invalid\n"
            . "Level 9,Noor,Example,Three,Example,three@example.invalid,,,\n"
            . ",,,,,,,,\n"
            . "Grade 2,Musa,Example,Four,Example,four@example.invalid,Five,Example,five@example.invalid\n"
            . "Grade 2,Zayd,Example,Six,Example,six@example.invalid,,,\n";
        $map = $this->post($this->url('/columns'), ['file' => $this->csv($csv)])->assertOk()->json('data.mapping');
        $preview = $this->post($this->url('/preview'), ['file' => $this->csv($csv), 'mapping' => json_encode($map)])->assertOk()->json('data');
        $this->assertSame([3], array_column($preview['refused'], 'line') ?: array_map(fn ($r) => $r[0] ?? $r['line'], $preview['refused']));
        $lines = [];
        foreach ($preview['students'] as $student) $lines[explode(' ', $student['name'])[0]] = $student['lines'];
        $this->assertSame([2], array_values(array_unique($lines['Ilyas'])));
        $this->assertSame([5], array_values(array_unique($lines['Musa'])), 'row 5: the blank row 4 still counts as a spreadsheet row');
        $this->assertSame([6], array_values(array_unique($lines['Zayd'])));
    }

    #[Test]
    public function missing_required_columns_stop_before_preview_and_mappings_are_remembered_only_for_that_signature(): void
    {
        $csv = "Class,Unknown,Parent Email\nGrade 2,Example,parent@example.invalid\n";
        $inspect = $this->post($this->url('/columns'), ['file' => $this->csv($csv)])->assertOk()->json('data');
        $this->assertNull($inspect['mapping']['student_first_name']);
        $this->post($this->url('/preview'), ['file' => $this->csv($csv), 'mapping' => json_encode($inspect['mapping'])])->assertUnprocessable()->assertJsonPath('data.mapping.0', 'Choose a column for student first name before previewing.');
        $this->assertSame(0, RosterImportMapping::count());
        $inspect['mapping']['student_first_name'] = 1;
        $this->post($this->url('/preview'), ['file' => $this->csv($csv), 'mapping' => json_encode($inspect['mapping'])])->assertOk();
        $this->post($this->url('/columns'), ['file' => $this->csv($csv)])->assertOk()->assertJsonPath('data.remembered', true)->assertJsonPath('data.mapping.student_first_name', 1);
        $this->post($this->url('/columns'), ['file' => $this->csv("Unknown,Class,Parent Email\nExample,Grade 2,parent@example.invalid\n")])->assertOk()->assertJsonPath('data.remembered', false)->assertJsonPath('data.mapping.student_first_name', null);
        $this->assertSame(0, Contact::count());
    }

    #[Test]
    public function a_school_cannot_read_update_or_delete_another_schools_mapping_and_create_is_stamped(): void
    {
        app(TenantContext::class)->set($this->otherSchool->id);
        $foreign = RosterImportMapping::create(['header_signature' => str_repeat('a', 64), 'mapping' => ['class' => 0]]);
        app(TenantContext::class)->set($this->school->id);
        $this->assertNull(RosterImportMapping::find($foreign->id));
        $this->assertSame(0, RosterImportMapping::whereKey($foreign->id)->update(['mapping' => '{}']));
        $this->assertSame(0, RosterImportMapping::whereKey($foreign->id)->delete());
        $local = RosterImportMapping::create(['masjid_id' => $this->otherSchool->id, 'header_signature' => str_repeat('b', 64), 'mapping' => []]);
        $this->assertSame($this->school->id, $local->masjid_id);
        $this->post('/api/admin/masjids/' . $this->otherSchool->id . '/records/roster-import/columns', ['file' => $this->csv(self::HEADER)])->assertForbidden();
    }

    #[Test]
    public function another_schools_same_header_signature_is_never_recalled_or_overwritten(): void
    {
        $csv = "Class,Unknown\nGrade 2,Example\n";
        $signature = (new \App\Services\Schools\RosterColumnMatcher)->signature(['Class', 'Unknown']);
        app(TenantContext::class)->set($this->otherSchool->id);
        $foreign = RosterImportMapping::create(['header_signature' => $signature, 'mapping' => ['class' => 0, 'student_first_name' => 1]]);
        app(TenantContext::class)->forgetTenant();
        $columns = $this->post($this->url('/columns'), ['file' => $this->csv($csv)])->assertOk()->assertJsonPath('data.remembered', false)->json('data');
        $columns['mapping']['student_first_name'] = 1;
        $this->post($this->url('/preview'), ['file' => $this->csv($csv), 'mapping' => json_encode($columns['mapping'])])->assertOk();
        $this->assertSame(2, RosterImportMapping::withoutMasjidScope()->where('header_signature', $signature)->count());
        $this->assertSame(['class' => 0, 'student_first_name' => 1], RosterImportMapping::withoutMasjidScope()->find($foreign->id)->mapping);
    }

    #[Test]
    public function second_guardian_with_no_email_is_refused_and_no_roster_is_written(): void
    {
        $csv = "Class,First Name,Last Name,Parent 1 First Name,Parent 1 Last Name,Parent 1 Email,Parent 2 First Name,Parent 2 Last Name\nGrade 2,Example,Student,First,Example,one@example.invalid,Second,Example\n";
        $mapping = $this->post($this->url('/columns'), ['file' => $this->csv($csv)])->json('data.mapping');
        $preview = $this->post($this->url('/preview'), ['file' => $this->csv($csv), 'mapping' => json_encode($mapping)])->assertOk()->json('data');
        $this->assertFalse($preview['can_commit']);
        $this->assertSame('a guardian needs an email — it is how the family signs in later', $preview['refused'][0]['why']);
        $this->post($this->url(), ['file' => $this->csv($csv), 'receipt' => $preview['receipt']])->assertUnprocessable();
        $this->assertSame(0, Contact::count());
    }

    #[Test]
    public function mapping_schema_has_a_primary_key_and_a_short_school_signature_unique_index(): void
    {
        $indexes = \Illuminate\Support\Facades\Schema::getIndexes('roster_import_mappings');
        $this->assertNotEmpty(array_filter($indexes, fn ($index) => $index['primary'] && $index['columns'] === ['id']));
        $this->assertNotEmpty(array_filter($indexes, fn ($index) => $index['unique'] && $index['columns'] === ['masjid_id', 'header_signature'] && strlen($index['name']) <= 64));
    }

    #[Test]
    public function unsupported_extensions_and_duplicate_assignments_are_plain_refusals(): void
    {
        $this->post($this->url('/columns'), ['file' => UploadedFile::fake()->createWithContent('sample.xls', 'encrypted')])->assertUnprocessable()->assertJsonPath('data.file.0', 'Upload a CSV or unencrypted .xlsx file. .xls and .xlsm files are not supported.');
        $csv = "Class,First Name\nGrade 2,Example\n";
        $this->post($this->url('/preview'), ['file' => $this->csv($csv), 'mapping' => '{"class":0,"student_first_name":0}'])->assertUnprocessable()->assertJsonPath('data.mapping.0', 'Each file column can fill only one field.');
    }
}
