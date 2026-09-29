<?php

namespace Tests\Feature;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\SchoolSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-001.1 / T-001.2 / T-001.3 on the piece of work itself: its subject, its type,
 * its optional weight and its ONE standard.
 *
 * THE RULE THAT MATTERS: a standard on a child's record is only ever one the
 * school's own pacing guide names. The teacher picks it from the standards search
 * that already exists (no second matcher), and the server checks the pick is a
 * guide row before it is stored, so nothing typed, scripted or replayed from
 * another school can put a standard on a piece of work that the school never
 * wrote. Every standard is a SNAPSHOT: re-importing the guide changes no mark.
 *
 * Length limits are asserted at the request layer at the columns' own numbers,
 * because SQLite (the suite) stores any length and MySQL (production) refuses.
 */
class GradebookCurriculumFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $class;
    private GroupMembership $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeSchool();
        $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);

        $this->class = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 3', 'slug' => 'g3']);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $child = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Amina']);
        $this->student = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER, 'grade_label' => '3rd',
        ]);

        // The school's pacing guide: a coded maths standard and an uncoded Islamic
        // Studies weekly focus (the guide's "Qur’an & Islamic Studies" has no codes).
        CurriculumWeek::create([
            'masjid_id' => $this->school->id, 'grade_label' => 'Grade 3', 'subject' => 'Mathematics',
            'week_no' => 4, 'quarter' => 1, 'focus' => 'Understand fractions as numbers', 'standard_code' => 'NC.3.NF.1',
        ]);
        CurriculumWeek::create([
            'masjid_id' => $this->school->id, 'grade_label' => 'Grade 3', 'subject' => 'Qur’an & Islamic Studies',
            'week_no' => 2, 'quarter' => 1, 'focus' => 'Wudu and its conditions', 'standard_code' => null,
        ]);

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    private function makeSchool(): Masjid
    {
        return Masjid::create([
            'name' => 'Al-Razi Test '.uniqid(), 'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/assignments".$path;
    }

    /** @param array<string,mixed> $over */
    private function body(array $over = []): array
    {
        return $over + ['title' => 'Fractions quiz', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString()];
    }

    /** The standards search the form uses, so the pick is what the endpoint really returns. */
    private function pick(string $q): array
    {
        $matches = $this->getJson("/api/teacher/masjids/{$this->school->id}/curriculum/standards?q=".urlencode($q))
            ->assertOk()->json('data.matches');

        $this->assertNotEmpty($matches, "the search found nothing for {$q}");

        return $matches[0];
    }

    // -------------------------------------------------------------------- type

    #[Test]
    public function every_type_is_accepted_and_the_word_comes_back(): void
    {
        foreach (['quiz' => 'Quiz', 'homework' => 'Homework', 'test' => 'Test', 'classwork' => 'Classwork', 'other' => 'Other'] as $type => $label) {
            $this->postJson($this->url(), $this->body(['type' => $type]))
                ->assertCreated()
                ->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.type_label', $label);
        }
    }

    #[Test]
    public function an_unknown_type_is_refused_and_nothing_is_written(): void
    {
        $this->postJson($this->url(), $this->body(['type' => 'exam']))->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->postJson($this->url(), $this->body(['type' => ['quiz']]))->assertStatus(422);
        $this->assertSame(0, ClassAssignment::query()->count());
    }

    #[Test]
    public function a_put_without_the_new_keys_keeps_them_and_a_null_clears_them(): void
    {
        $id = $this->postJson($this->url(), $this->body(['type' => 'quiz', 'subject' => 'Mathematics']))->assertCreated()->json('data.id');

        // An older screen still open in a tab sends none of the new keys.
        $this->putJson($this->url("/{$id}"), $this->body(['title' => 'Renamed']))
            ->assertOk()->assertJsonPath('data.type', 'quiz')->assertJsonPath('data.subject', 'Mathematics');

        $this->putJson($this->url("/{$id}"), $this->body(['type' => null, 'subject' => null]))
            ->assertOk()->assertJsonPath('data.type', null)->assertJsonPath('data.subject', null);
        $this->assertSame('', ClassAssignment::find($id)->subject_key);
    }

    #[Test]
    public function the_clients_own_form_encoded_body_works_and_an_empty_string_clears(): void
    {
        $r = $this->post($this->url(), [
            'title' => 'Form work', 'scale' => 'points', 'points_possible' => '10',
            'assigned_on' => now()->toDateString(), 'type' => 'test', 'subject' => 'Mathematics', 'weight' => '',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('test', $r->json('data.type'));
        $this->assertNull($r->json('data.weight'), 'an empty form field is "inherit", not a zero');
    }

    // ----------------------------------------------------------- column limits

    #[Test]
    public function every_text_field_is_bounded_at_its_columns_own_length(): void
    {
        // A school with no guide has nothing to check a subject against, so the
        // subject's own length is the only rule left in play.
        CurriculumWeek::query()->delete();

        $this->postJson($this->url(), $this->body(['subject' => str_repeat('s', 65)]))->assertStatus(422);
        $this->postJson($this->url(), $this->body(['subject' => str_repeat('é', 64)]))->assertCreated();
        $this->postJson($this->url(), $this->body(['standard_code' => str_repeat('c', 33)]))->assertStatus(422);
        $this->postJson($this->url(), $this->body(['curriculum_focus' => str_repeat('f', 501)]))->assertStatus(422);
        $this->postJson($this->url(), $this->body(['weight' => 101]))->assertStatus(422);
        $this->postJson($this->url(), $this->body(['weight' => -1]))->assertStatus(422);
        $this->postJson($this->url(), $this->body(['curriculum_week_no' => 61]))->assertStatus(422);
        $this->postJson($this->url(), $this->body(['curriculum_week_no' => 'x']))->assertStatus(422);
    }

    #[Test]
    public function a_subject_that_folds_to_a_shorter_key_still_fits_its_column(): void
    {
        CurriculumWeek::query()->delete();

        // 64 characters of apostrophes and letters: the key only ever shrinks.
        $subject = str_repeat("Q'", 32);
        $id = $this->postJson($this->url(), $this->body(['subject' => $subject]))->assertCreated()->json('data.id');

        $this->assertLessThanOrEqual(64, mb_strlen(ClassAssignment::find($id)->subject_key));
    }

    #[Test]
    public function the_subject_key_never_reaches_a_payload(): void
    {
        $this->postJson($this->url(), $this->body(['subject' => 'Mathematics']))->assertCreated();

        $this->assertStringNotContainsString('subject_key', $this->getJson($this->url())->assertOk()->getContent());
        $this->assertStringNotContainsString('subject_key', json_encode(ClassAssignment::query()->first()->toArray()));
    }

    // --------------------------------------------------------------- standards

    #[Test]
    public function a_standard_picked_from_the_existing_search_is_stored_as_a_snapshot(): void
    {
        $m = $this->pick('NF.1');

        $this->postJson($this->url(), $this->body([
            'subject' => 'Mathematics',
            'standard_code' => $m['standard_code'], 'curriculum_focus' => $m['focus'], 'curriculum_week_no' => $m['week_no'],
        ]))->assertCreated()
            ->assertJsonPath('data.standard_code', 'NC.3.NF.1')
            ->assertJsonPath('data.curriculum_focus', 'Understand fractions as numbers')
            ->assertJsonPath('data.curriculum_week_no', 4);
    }

    #[Test]
    public function an_uncoded_weekly_focus_is_a_standard_by_its_words_alone(): void
    {
        $m = $this->pick('wudu');
        $this->assertNull($m['standard_code']);

        $this->postJson($this->url(), $this->body([
            'standard_code' => null, 'curriculum_focus' => $m['focus'], 'curriculum_week_no' => $m['week_no'],
        ]))->assertCreated()
            ->assertJsonPath('data.standard_code', null)
            ->assertJsonPath('data.curriculum_focus', 'Wudu and its conditions')
            ->assertJsonPath('data.curriculum_week_no', 2);
    }

    #[Test]
    public function a_standard_the_guide_does_not_name_is_refused_however_it_is_offered(): void
    {
        $cases = [
            'a typed code' => ['standard_code' => 'NC.3.MADE.UP', 'curriculum_focus' => 'Understand fractions as numbers'],
            'a real code with invented words' => ['standard_code' => 'NC.3.NF.1', 'curriculum_focus' => 'Something the school never wrote'],
            'a real focus under the wrong code' => ['standard_code' => 'NC.3.NF.2', 'curriculum_focus' => 'Understand fractions as numbers'],
            'a code with no focus' => ['standard_code' => 'NC.3.NF.1'],
            'a focus with no code that is coded in the guide' => ['curriculum_focus' => 'Understand fractions as numbers'],
            'a real pair in the wrong week' => ['standard_code' => 'NC.3.NF.1', 'curriculum_focus' => 'Understand fractions as numbers', 'curriculum_week_no' => 30],
        ];

        foreach ($cases as $why => $fields) {
            $this->postJson($this->url(), $this->body($fields))
                ->assertStatus(422, "{$why} must be refused")
                ->assertJsonPath('data.standard_code.0', "That standard is not in this school's pacing guide. Choose one from the list.");
        }

        $this->assertSame(0, ClassAssignment::query()->count());
    }

    #[Test]
    public function another_schools_guide_is_not_this_schools_standards(): void
    {
        $other = $this->makeSchool();
        CurriculumWeek::create([
            'masjid_id' => $other->id, 'grade_label' => 'Grade 3', 'subject' => 'Science',
            'week_no' => 1, 'focus' => 'Their private focus', 'standard_code' => 'OTHER.3.S.1',
        ]);
        // create() bound no tenant here, but the request below binds this school.

        $this->postJson($this->url(), $this->body([
            'standard_code' => 'OTHER.3.S.1', 'curriculum_focus' => 'Their private focus',
        ]))->assertStatus(422);
        $this->assertSame(0, ClassAssignment::query()->count());
    }

    #[Test]
    public function clearing_the_standard_clears_its_week_and_leaving_it_out_keeps_all_three(): void
    {
        $m = $this->pick('NF.1');
        $id = $this->postJson($this->url(), $this->body([
            'standard_code' => $m['standard_code'], 'curriculum_focus' => $m['focus'], 'curriculum_week_no' => $m['week_no'],
        ]))->assertCreated()->json('data.id');

        $this->putJson($this->url("/{$id}"), $this->body(['title' => 'Renamed']))
            ->assertOk()->assertJsonPath('data.standard_code', 'NC.3.NF.1')->assertJsonPath('data.curriculum_week_no', 4);

        $this->putJson($this->url("/{$id}"), $this->body(['standard_code' => null, 'curriculum_focus' => null]))
            ->assertOk()
            ->assertJsonPath('data.standard_code', null)
            ->assertJsonPath('data.curriculum_focus', null)
            ->assertJsonPath('data.curriculum_week_no', null);
    }

    #[Test]
    public function the_snapshot_survives_the_guide_being_reimported_and_an_unchanged_save_still_passes(): void
    {
        $m = $this->pick('NF.1');
        $fields = ['standard_code' => $m['standard_code'], 'curriculum_focus' => $m['focus'], 'curriculum_week_no' => $m['week_no']];
        $id = $this->postJson($this->url(), $this->body($fields))->assertCreated()->json('data.id');

        // The school re-imports its guide and the row is gone.
        CurriculumWeek::query()->where('standard_code', 'NC.3.NF.1')->delete();

        $this->getJson($this->url("/{$id}"))->assertOk()
            ->assertJsonPath('data.standard_code', 'NC.3.NF.1')
            ->assertJsonPath('data.curriculum_focus', 'Understand fractions as numbers');

        // Saving the work with the SAME snapshot is not a new pick and is not refused.
        $this->putJson($this->url("/{$id}"), $this->body(['title' => 'Renamed'] + $fields))->assertOk();
        // A NEW pick of what the guide no longer names is.
        $this->postJson($this->url(), $this->body($fields))->assertStatus(422);
    }

    #[Test]
    public function a_school_that_teaches_no_pacing_guide_neither_shows_nor_stores_standards(): void
    {
        // BISS: `short_lesson_plan` hides the plan's standard, and work follows.
        $this->school->capability_overrides = [SchoolSettings::SHORT_LESSON_PLAN => true];
        $this->school->save();

        $body = $this->getJson($this->url())->assertOk()->json();
        $this->assertFalse($body['standards_enabled']);

        $m = $this->pick('NF.1');
        $this->postJson($this->url(), $this->body([
            'standard_code' => $m['standard_code'], 'curriculum_focus' => $m['focus'], 'curriculum_week_no' => $m['week_no'],
        ]))->assertCreated()
            ->assertJsonPath('data.standard_code', null)
            ->assertJsonPath('data.curriculum_focus', null);
    }

    #[Test]
    public function a_school_with_a_guide_offers_standards(): void
    {
        $this->assertTrue($this->getJson($this->url())->assertOk()->json('standards_enabled'));
    }

    // -------------------------------------------------- what a parent is shown

    #[Test]
    public function a_parent_sees_the_subject_type_and_standard_of_a_mark_and_no_class_fact(): void
    {
        $m = $this->pick('NF.1');
        $work = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => 'Fractions quiz',
            'points_possible' => 10, 'scale' => 'points', 'subject' => 'Mathematics', 'type' => 'quiz',
            'standard_code' => $m['standard_code'], 'curriculum_focus' => $m['focus'], 'assigned_on' => now()->toDateString(),
        ]);
        AssignmentScore::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'class_assignment_id' => $work->id,
            'group_membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 7,
        ]);

        $parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'login_email' => 'p-'.uniqid().'@test.local', 'login_enabled_at' => now(),
        ]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $this->student->contact_id,
            'confirmed_at' => now(), 'consent_granted_at' => now(), 'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        app(\App\Support\TenantContext::class)->forgetTenant();

        $assignment = $this->withHeader('Authorization', 'Bearer '.$parent->refresh()->createFamilyToken()->plainTextToken)
            ->getJson("/api/family/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$this->student->id}/grades")
            ->assertOk()->json('data.scores.0.assignment');

        $this->assertSame('Mathematics', $assignment['subject']);
        $this->assertSame('quiz', $assignment['type']);
        $this->assertSame('NC.3.NF.1', $assignment['standard_code']);
        $this->assertSame('Understand fractions as numbers', $assignment['curriculum_focus']);
        // The family key set is pinned in FamilyGradesTest; here, no staff
        // provenance and no class count rides along.
        foreach (['created_by_user_id', 'scored', 'roster', 'subject_key', 'deleted_at'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $assignment);
        }
    }

    // ------------------------------------------------------------- the export

    #[Test]
    public function the_records_export_carries_the_new_columns_at_the_end(): void
    {
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->school->user_id = $admin->id;
        $this->school->save();
        ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => 'Fractions quiz',
            'points_possible' => 10, 'scale' => 'points', 'subject' => 'Mathematics', 'type' => 'quiz',
            'weight' => 30, 'standard_code' => 'NC.3.NF.1', 'curriculum_focus' => 'Understand fractions as numbers',
            'assigned_on' => now()->toDateString(),
        ]);

        \Illuminate\Support\Facades\Auth::forgetGuards();
        app(\App\Support\TenantContext::class)->forgetTenant();
        Sanctum::actingAs($admin);

        $res = $this->get("/api/admin/masjids/{$this->school->id}/records/export?dataset=assignments");
        $res->assertOk();
        ob_start();
        $res->sendContent();
        $csv = ob_get_clean();

        $lines = array_values(array_filter(preg_split('/\r?\n/', $csv)));
        $header = str_getcsv(ltrim($lines[0], "\u{FEFF}"));

        // The earlier seven stay where they were; the five new ones follow.
        $this->assertSame(['Assignment id', 'Class id', 'Title', 'Scale', 'Points possible', 'Assigned on', 'Withdrawn on'], array_slice($header, 0, 7));
        $this->assertSame(['Subject', 'Type', 'Weight', 'Standard code', 'Curriculum focus'], array_slice($header, 7));

        $row = str_getcsv($lines[1]);
        $this->assertSame(['Mathematics', 'quiz', '30', 'NC.3.NF.1', 'Understand fractions as numbers'], array_slice($row, 7));
    }
}
