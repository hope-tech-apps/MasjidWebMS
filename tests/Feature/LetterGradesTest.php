<?php

namespace Tests\Feature;

use App\Models\{Masjid, Contact, Group, GroupMembership, ClassSubject, ClassAssignment, AssignmentScore, ReportCard, SchoolYear, SchoolTerm, TermGrade};
use App\Support\{LetterGrades, GradeLevel, GradeRecord, TenantContext};
use App\Services\Schools\ReportCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LetterGradesTest extends TestCase
{
    use RefreshDatabase;

    private function makeMasjid(): Masjid
    {
        return Masjid::create(['name'=>'Example School '.uniqid(), 'email'=>uniqid().'@example.test', 'phone'=>uniqid(), 'country_id'=>1, 'city_id'=>1, 'address'=>'Example', 'latitude'=>0, 'longitude'=>0, 'org_type'=>'school', 'crm_enabled'=>true]);
    }

    private function world(): array
    {
        $school = $this->makeMasjid();
        $school->forceFill(['capability_overrides'=>['letter_grades'=>true, 'class_subjects'=>true, 'school_calendar'=>true, 'school_calendar_terms'=>true], 'letter_grade_settings'=>['enabled'=>true, 'from_grade'=>'Grade 3', 'scale'=>LetterGrades::DEFAULT_SCALE]])->save();
        $group = Group::factory()->create(['masjid_id'=>$school->id, 'kind'=>'class']);
        $contact = Contact::factory()->create(['masjid_id'=>$school->id, 'email'=>null]);
        $member = GroupMembership::create(['masjid_id'=>$school->id, 'group_id'=>$group->id, 'contact_id'=>$contact->id, 'role'=>'member', 'grade_label'=>'3rd']);
        $subject = ClassSubject::create(['masjid_id'=>$school->id, 'group_id'=>$group->id, 'name'=>'Mathematics', 'position'=>0]);
        $year = SchoolYear::create(['masjid_id'=>$school->id, 'label'=>'2026-2027', 'first_day'=>'2026-08-01', 'last_day'=>'2027-06-30', 'meeting_weekdays'=>[1], 'term_system'=>'quarters']);
        $term = SchoolTerm::create(['masjid_id'=>$school->id, 'school_year_id'=>$year->id, 'name'=>'First term', 'position'=>1, 'starts_on'=>'2026-08-01', 'ends_on'=>'2026-10-31']);
        return [$school, $member, $subject, $term];
    }

    private function piece($member, $subject, string $date, string $status, ?float $earned, string $scale='points'): void
    {
        $assignment = ClassAssignment::create(['masjid_id'=>$member->masjid_id, 'group_id'=>$member->group_id, 'class_subject_id'=>$subject->id, 'title'=>'Example work', 'subject'=>$subject->name, 'assigned_on'=>$date, 'scale'=>$scale, 'points_possible'=>$scale === 'points' ? 100 : 4, 'type'=>'test']);
        AssignmentScore::create(['masjid_id'=>$member->masjid_id, 'group_id'=>$member->group_id, 'class_assignment_id'=>$assignment->id, 'group_membership_id'=>$member->id, 'status'=>$status, 'points_earned'=>$earned]);
    }

    #[Test]
    public function scale_is_atomic_descending_unique_and_covers_zero(): void
    {
        $this->assertSame(LetterGrades::DEFAULT_SCALE, LetterGrades::validateScale(LetterGrades::DEFAULT_SCALE));
        foreach ([[['letter'=>'A','minimum'=>90]], [['letter'=>'A','minimum'=>0], ['letter'=>'F','minimum'=>0]], [['letter'=>'A','minimum'=>101], ['letter'=>'F','minimum'=>0]], [['letter'=>'A','minimum'=>90], ['letter'=>'A','minimum'=>0]], [['letter'=>'F','minimum'=>0], ['letter'=>'A','minimum'=>90]]] as $bad) {
            try { LetterGrades::validateScale($bad); $this->fail('Invalid scale accepted'); } catch (ValidationException) { $this->assertTrue(true); }
        }
        $this->assertSame('B+', LetterGrades::letter(89.99, LetterGrades::DEFAULT_SCALE));
        $this->assertSame('A−', LetterGrades::letter(90, LetterGrades::DEFAULT_SCALE));
        $this->assertNull(LetterGrades::letter(null, LetterGrades::DEFAULT_SCALE));
    }

    #[Test]
    public function grades_use_the_existing_order_and_unknown_grades_keep_levels(): void
    {
        foreach (['Pre-K'=>0, 'K'=>1, 'KG'=>1, '2nd'=>3, 'Grade 3'=>4, '12th'=>13] as $label=>$position) $this->assertSame($position, GradeLevel::position($label));
        [$school,$member] = $this->world();
        $this->assertTrue(LetterGrades::eligible($school, $member->grade_label));
        $this->assertFalse(LetterGrades::eligible($school, '2nd'));
        $this->assertFalse(LetterGrades::eligible($school, 'unplaced'));
    }

    #[Test]
    public function term_percentage_reuses_gradebook_weights_missing_excused_and_scale_rules(): void
    {
        [$school,$member,$subject,$term] = $this->world();
        \App\Models\ClassGradeWeight::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'assignment_type'=>'test','weight'=>40]);
        $this->piece($member,$subject,'2026-08-01','scored',90);
        $this->piece($member,$subject,'2026-10-31','missing',null);
        $this->piece($member,$subject,'2026-09-01','excused',100);
        $this->piece($member,$subject,'2026-09-02','scored',4,'levels');
        $this->piece($member,$subject,'2026-11-01','scored',100);
        $summary = GradeRecord::forTerm($member->id, [$subject->id], $term->starts_on, $term->ends_on);
        $this->assertSame(45.0, $summary['by_subject'][0]['weighted_percent']);
        $card = app(ReportCardService::class)->prepare($member,'report_card','2026-2027',1);
        $line = LetterGrades::staff($card)[0];
        $this->assertSame($summary['by_subject'][0]['weighted_percent'], $line['calculated_percentage']);
        $this->assertSame('F', $line['calculated_letter']);
        $this->assertCount(1, $line['excluded_work']);
    }

    #[Test]
    public function no_work_never_becomes_an_f_and_progress_reports_keep_levels(): void
    {
        [, $member] = $this->world();
        $service = app(ReportCardService::class);
        $card = $service->prepare($member,'report_card','2026-2027',1);
        $this->assertNull(LetterGrades::staff($card)[0]['calculated_letter']);
        $this->assertSame([], LetterGrades::staff($service->prepare($member,'progress','2026-2027',1)));
    }

    #[Test]
    public function publication_freezes_final_and_resend_updates_one_term_record(): void
    {
        [$school,$member,$subject] = $this->world();
        $this->piece($member,$subject,'2026-09-01','scored',88.4);
        $service = app(ReportCardService::class);
        $card = $service->prepare($member,'report_card','2026-2027',1);
        $line = LetterGrades::staff($card)[0];
        try { LetterGrades::save($card, [['id'=>$line['id'],'override_letter'=>'A−','override_reason'=>' ']]); $this->fail('Missing reason accepted'); } catch (ValidationException) { $this->assertTrue(true); }
        $teacher = \App\Models\User::factory()->create(['type'=>'Teacher','phone'=>uniqid()]);
        \App\Models\GroupStaff::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'user_id'=>$teacher->id,'role'=>'teacher','class_subject_ids'=>[$subject->id],'assigned_at'=>now()]);
        $this->actingAs($teacher);
        LetterGrades::save($card, [['id'=>$line['id'],'override_letter'=>'A−','override_reason'=>'Additional evidence','comment'=>'Family comment']]);
        $service->publish($card);
        $family = LetterGrades::family($card);
        $this->assertSame('A−', $family[0]['final_letter']);
        $this->assertNull($family[0]['percentage']);
        foreach (['calculated_letter','calculated_percentage','override_letter','override_reason','override_by_user_id','override_at','excluded_work'] as $key) $this->assertArrayNotHasKey($key, $family[0]);
        $staff = LetterGrades::staff($card)[0];
        $this->assertSame($teacher->id, $staff['override_by_user_id']);
        $this->assertNotNull($staff['override_at']);
        $this->assertSame('B+', $staff['calculated_letter']);
        $school->forceFill(['letter_grade_settings'=>['enabled'=>true,'from_grade'=>'3rd','scale'=>[['letter'=>'Pass','minimum'=>0]]]])->save();
        AssignmentScore::query()->update(['points_earned'=>40]);
        LetterGrades::snapshot($card->fresh());
        $this->assertSame($family, LetterGrades::family($card->fresh()));
        $this->assertSame('A−', TermGrade::first()->final_letter);
        $service->unpublish($card);
        $service->prepare($member,'report_card','2026-2027',1);
        LetterGrades::save($card, [['id'=>$line['id'],'override_letter'=>null,'override_reason'=>null,'comment'=>'Revised']]);
        $service->publish($card->fresh());
        $this->assertSame(1, TermGrade::count());
        $this->assertSame('Pass', TermGrade::first()->final_letter);
        $this->assertSame(40.0, TermGrade::first()->percentage);
    }

    #[Test]
    public function term_grade_and_settings_are_tenant_isolated(): void
    {
        [$school,$member] = $this->world();
        $service = app(ReportCardService::class);
        $service->publish($service->prepare($member,'report_card','2026-2027',1));
        $other = $this->makeMasjid();
        app(TenantContext::class)->set($other->id);
        $this->assertNull(TermGrade::find(TermGrade::withoutMasjidScope()->first()->id));
        $this->assertSame(0, TermGrade::count());
        $this->assertSame(1, TermGrade::withoutMasjidScope()->count());
    }
    private function teacher($school, $member, $subject): \App\Models\User
    {
        $teacher = \App\Models\User::factory()->create(['name'=>'Example Teacher','email'=>uniqid().'@example.test','phone'=>uniqid(),'type'=>'Teacher']);
        \App\Models\MasjidUser::create(['masjid_id'=>$school->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
        \App\Models\GroupStaff::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'user_id'=>$teacher->id,'role'=>'teacher','class_subject_ids'=>[$subject->id],'assigned_at'=>now()]);
        \Laravel\Sanctum\Sanctum::actingAs($teacher, ['staff']);
        return $teacher;
    }

    #[Test]
    public function settings_save_whole_scale_accept_browser_boolean_and_refuse_foreign_school(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        [$school] = $this->world();
        $admin = \App\Models\User::factory()->create(['name'=>'Example Office','phone'=>uniqid(),'type'=>'MasjidAdmin']);
        \App\Models\MasjidUser::create(['masjid_id'=>$school->id,'user_id'=>$admin->id,'role'=>'admin','is_default'=>true]);
        \Laravel\Sanctum\Sanctum::actingAs($admin, ['staff']);
        $url = '/api/admin/masjids/'.$school->id.'/letter-grade-settings';
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_enable',true);
        $this->put($url,['enabled'=>'false','from_grade'=>'2nd','scale'=>LetterGrades::DEFAULT_SCALE])->assertOk();
        $this->assertFalse($school->fresh()->letter_grade_settings['enabled']);
        $this->putJson($url,['enabled'=>true,'from_grade'=>'2nd','scale'=>[['letter'=>'A','minimum'=>80]]])->assertUnprocessable();
        $this->assertFalse($school->fresh()->letter_grade_settings['enabled']);
        $other = $this->makeMasjid();
        $this->getJson('/api/admin/masjids/'.$other->id.'/letter-grade-settings')->assertForbidden();
        $this->putJson('/api/admin/masjids/'.$other->id.'/letter-grade-settings',['enabled'=>true,'from_grade'=>'2nd','scale'=>LetterGrades::DEFAULT_SCALE])->assertForbidden();
    }

    #[Test]
    public function settings_tell_the_office_to_add_terms_before_letters_can_be_enabled(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $school = $this->makeMasjid();
        $school->forceFill(['capability_overrides'=>['letter_grades'=>true,'class_subjects'=>true,'school_calendar_terms'=>true]])->save();
        $admin = \App\Models\User::factory()->create(['phone'=>uniqid(),'type'=>'MasjidAdmin']);
        \App\Models\MasjidUser::create(['masjid_id'=>$school->id,'user_id'=>$admin->id,'role'=>'admin','is_default'=>true]);
        \Laravel\Sanctum\Sanctum::actingAs($admin, ['staff']);
        $url = '/api/admin/masjids/'.$school->id.'/letter-grade-settings';
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_enable',false);
        $this->putJson($url,['enabled'=>true,'from_grade'=>'3rd','scale'=>LetterGrades::DEFAULT_SCALE])->assertUnprocessable();
    }

    #[Test]
    public function staff_endpoint_gradebook_and_family_json_and_pdf_obey_the_boundary(): void
    {
        [$school,$member,$subject,$term] = $this->world();
        $teacher = $this->teacher($school,$member,$subject);
        $this->piece($member,$subject,'2026-09-01','scored',88.4);
        $base = '/api/teacher/masjids/'.$school->id.'/groups/'.$member->group_id.'/members/'.$member->id;
        $url = $base.'/report-card?term=1&school_year=2026-2027';
        $data = $this->getJson($url)->assertOk()->json('data');
        $gradebook = $this->getJson($base.'/grades?school_term_id='.$term->id)->assertOk()->json('data.summary.by_subject.0.percent');
        $this->assertSame($gradebook, $data['letter_grades'][0]['calculated_percentage']);
        $id = $data['letter_grades'][0]['id'];
        $this->putJson($url,['letter_grades'=>[['id'=>$id,'override_letter'=>'A−','override_reason'=>'','comment'=>'Family words']]])->assertUnprocessable();
        $this->putJson($url,['letter_grades'=>[['id'=>$id,'override_letter'=>'A−','override_reason'=>'Staff evidence only','comment'=>'Family words']]])->assertOk()->assertJsonPath('data.letter_grades.0.override_by_user_id',$teacher->id);
        $this->postJson($base.'/report-card/publish?term=1&school_year=2026-2027')->assertOk();
        $card = ReportCard::firstOrFail();
        $pdf = app(\App\Services\Schools\ReportCardPdfService::class);
        $method = new \ReflectionMethod($pdf, 'data');
        $pdfData = $method->invoke($pdf, $card, $member);
        $this->assertArrayNotHasKey('override_reason',$pdfData['letterGrades'][0]);
        $this->assertArrayNotHasKey('calculated_letter',$pdfData['letterGrades'][0]);
        $html = view('pdf.report-card',$pdfData)->render();
        $this->assertStringContainsString('A−',$html);
        $this->assertStringContainsString('Family words',$html);
        $this->assertStringContainsString('First term (Aug 1, 2026',$html);
        $this->assertStringNotContainsString('Staff evidence only',$html);
        $this->assertStringNotContainsString('88.4%',$html);
        $this->assertStringStartsWith('%PDF-', $pdf->render($card,$member));
        $guardian = Contact::factory()->create(['masjid_id'=>$school->id,'email'=>null,'login_email'=>uniqid().'@example.test','login_enabled_at'=>now()]);
        GroupMembership::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'contact_id'=>$guardian->id,'role'=>'guardian','guardian_of_contact_id'=>$member->contact_id,'confirmed_at'=>now(),'consent_granted_at'=>now(),'consent_scope'=>'media']);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        $response = $this->withHeader('Authorization','Bearer '.$guardian->createFamilyToken()->plainTextToken)->getJson('/api/family/masjids/'.$school->id.'/groups/'.$member->group_id.'/members/'.$member->id.'/report-cards/'.$card->id)->assertOk();
        $this->assertStringContainsString('First term (Aug 1, 2026',$response->json('data.period_label'));
        $family = $response->json('data.letter_grades.0');
        $this->assertSame('A−',$family['final_letter']);
        foreach (['calculated_letter','calculated_percentage','override_letter','override_reason','override_by_user_id','override_at','override_by_name'] as $key) $this->assertArrayNotHasKey($key,$family);
        $this->assertStringNotContainsString('Staff evidence only',$response->getContent());
    }

    #[Test]
    public function combined_class_decides_by_each_students_grade_and_off_output_is_identical(): void
    {
        [$school,$member,$subject] = $this->world();
        $member->update(['grade_label'=>'2nd']);
        $this->teacher($school,$member,$subject);
        $url = '/api/teacher/masjids/'.$school->id.'/groups/'.$member->group_id.'/members/'.$member->id.'/report-card?term=1&school_year=2026-2027';
        $on = $this->getJson($url)->assertOk()->getContent();
        $listUrl = '/api/teacher/masjids/'.$school->id.'/groups/'.$member->group_id.'/report-cards?term=1&school_year=2026-2027';
        $onList = $this->getJson($listUrl)->assertOk()->getContent();
        $school->forceFill(['capability_overrides'=>['class_subjects'=>true,'school_calendar'=>true,'school_calendar_terms'=>true,'letter_grades'=>false]])->save();
        $off = $this->getJson($url)->assertOk()->getContent();
        $this->assertSame($off,$on);
        $this->assertSame($onList,$this->getJson($listUrl)->assertOk()->getContent());
        $this->assertStringNotContainsString('letter_grades',$off);
        $school->forceFill(['capability_overrides'=>['class_subjects'=>true,'school_calendar'=>true,'school_calendar_terms'=>true,'letter_grades'=>true]])->save();
        $contact = Contact::factory()->create(['masjid_id'=>$school->id,'email'=>null]);
        $older = GroupMembership::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'contact_id'=>$contact->id,'role'=>'member','grade_label'=>'Grade 3']);
        $olderUrl = str_replace('/members/'.$member->id.'/', '/members/'.$older->id.'/', $url);
        $this->getJson($olderUrl)->assertOk()->assertJsonPath('data.letter_grades.0.subject','Mathematics');
    }

    #[Test]
    public function subject_piece_levels_are_listed_outside_the_letter_average(): void
    {
        [$school,$member,$subject] = $this->world();
        $this->piece($member,$subject,'2026-09-01','scored',90);
        $piece = \App\Models\SubjectPiece::create(['masjid_id'=>$school->id,'class_subject_id'=>$subject->id,'source'=>'own','title'=>'Level evidence','created_at'=>'2026-09-01']);
        $piece->forceFill(['created_at'=>'2026-09-01'])->save();
        \App\Models\SubjectPieceMark::create(['masjid_id'=>$school->id,'subject_piece_id'=>$piece->id,'group_membership_id'=>$member->id,'level'=>4]);
        $card = app(ReportCardService::class)->prepare($member,'report_card','2026-2027',1);
        $line = LetterGrades::staff($card)[0];
        $this->assertSame(90.0,$line['calculated_percentage']);
        $this->assertSame('Level evidence',$line['excluded_work'][0]['title']);
    }

    #[Test]
    public function new_columns_are_portable_and_raw_mark_serialization_hides_provenance(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::getIndexes('term_grades');
        $this->assertNotEmpty(collect($schema)->firstWhere('primary', true));
        $unique = collect($schema)->firstWhere('name','term_grades_student_subject_term_unique');
        $this->assertTrue($unique['unique']);
        $this->assertSame(['contact_id','class_subject_id','school_term_id'],$unique['columns']);
        foreach (['override_reason','final_comment'] as $column) $this->assertSame('text', \Illuminate\Support\Facades\Schema::getColumnType('report_card_marks',$column));
        $this->assertSame('text', \Illuminate\Support\Facades\Schema::getColumnType('term_grades','comment'));
        [$school,$member] = $this->world();
        $card = app(ReportCardService::class)->prepare($member,'report_card','2026-2027',1);
        $mark = $card->marks()->where('kind','term_grade')->first();
        $this->assertArrayNotHasKey('override_reason',$mark->toArray());
        $this->assertArrayNotHasKey('letter_grade_settings',$school->toArray());
    }

    #[Test]
    public function default_off_grant_has_no_catalogue_override_or_settings_output(): void
    {
        $school = $this->makeMasjid();
        $this->assertFalse($school->hasCapability('letter_grades'));
        $this->assertArrayNotHasKey('letter_grades',$school->getCapabilitiesAttribute());
        $school->forceFill(['capability_overrides'=>['letter_grades'=>false]])->save();
        $this->assertArrayNotHasKey('letter_grades',$school->toArray()['capability_overrides']);
        $this->assertSame('hidden', \App\Support\CapabilityCatalogue::visibility('letter_grades',config('capabilities.letter_grades'),'school'));
        $school->forceFill(['org_type'=>'masjid','capability_overrides'=>['letter_grades'=>true]])->save();
        $this->assertFalse(LetterGrades::offered($school));
        $this->assertArrayNotHasKey('letter_grades',$school->getCapabilitiesAttribute());
        $this->assertArrayNotHasKey('letter_grades',$school->toArray()['capability_overrides']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        \App\Support\CapabilityWriter::apply($school,['letter_grades'=>true],null);
    }

    #[Test]
    public function unknown_student_grade_has_levels_and_explanation_and_semesters_use_dates(): void
    {
        [$school,$member,$subject,$term] = $this->world();
        $this->teacher($school,$member,$subject);
        $member->update(['grade_label'=>'Unplaced']);
        $url = '/api/teacher/masjids/'.$school->id.'/groups/'.$member->group_id.'/members/'.$member->id.'/report-card?term=1&school_year=2026-2027';
        $response = $this->getJson($url)->assertOk();
        $this->assertArrayNotHasKey('letter_grades',$response->json('data'));
        $this->assertStringContainsString('cannot be placed',$response->json('data.letter_grade_notice'));
        $member->update(['grade_label'=>'Grade 3']);
        SchoolYear::whereKey($term->school_year_id)->update(['term_system'=>'semesters']);
        $this->piece($member,$subject,'2026-09-01','scored',93);
        $this->getJson($url)->assertOk()->assertJsonPath('data.letter_grades.0.calculated_letter','A');
    }

    #[Test]
    public function failed_override_save_rolls_back_skill_edits_and_a_sent_card_is_locked(): void
    {
        [$school,$member,$subject] = $this->world();
        $this->teacher($school,$member,$subject);
        $url = '/api/teacher/masjids/'.$school->id.'/groups/'.$member->group_id.'/members/'.$member->id.'/report-card?term=1&school_year=2026-2027';
        $data = $this->getJson($url)->assertOk()->json('data');
        $skill = $data['subjects'][0]['criteria'][0];
        $letter = $data['letter_grades'][0];
        $this->putJson($url,['marks'=>[['id'=>$skill['id'],'level'=>4]],'letter_grades'=>[['id'=>$letter['id'],'override_letter'=>'A','override_reason'=>'']]])->assertUnprocessable();
        $this->assertNull(\App\Models\ReportCardMark::find($skill['id'])->level);
        $service = app(ReportCardService::class); $card = ReportCard::first(); $service->publish($card);
        $this->putJson($url,['letter_grades'=>[['id'=>$letter['id'],'override_letter'=>'A','override_reason'=>'Evidence']]])->assertUnprocessable();
        $this->assertNull(\App\Models\ReportCardMark::find($letter['id'])->override_letter);
    }

    #[Test]
    public function schema_rollback_refuses_to_erase_a_draft_or_issued_term_grade(): void
    {
        [, $member] = $this->world();
        app(ReportCardService::class)->prepare($member,'report_card','2026-2027',1);
        $migration = require database_path('migrations/2026_10_14_200000_add_letter_grades.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Keep this schema');
        $migration->down();
    }

    #[Test]
    public function dated_semester_heading_is_frozen_for_family_and_pdf(): void
    {
        [$school,$member,$subject,$term] = $this->world();
        SchoolYear::whereKey($term->school_year_id)->update(['term_system'=>'semesters']);
        $term->update(['name'=>'Autumn semester']);
        $this->piece($member,$subject,'2026-09-01','scored',93);
        $service = app(ReportCardService::class);
        $card = $service->prepare($member,'report_card','2026-2027',1);
        $service->publish($card);
        $heading = LetterGrades::periodLabel($card);
        $this->assertStringStartsWith('Autumn semester (Aug 1, 2026', $heading);
        $term->update(['name'=>'Changed term name','starts_on'=>'2026-08-15']);
        $this->assertSame($heading, LetterGrades::periodLabel($card->fresh()));
        $method = new \ReflectionMethod(app(\App\Services\Schools\ReportCardPdfService::class),'data');
        $pdf = $method->invoke(app(\App\Services\Schools\ReportCardPdfService::class),$card->fresh(),$member);
        $this->assertSame($heading,$pdf['periodLabel']);
        $this->assertStringContainsString('autumn-semester', app(\App\Services\Schools\ReportCardPdfService::class)->filename($card->fresh(),$member));
    }

    /** Walk finding: switching letters off stripped grades and the dated heading from cards already sent. */
    #[Test]
    public function a_sent_card_keeps_its_letters_and_heading_after_letters_are_switched_off(): void
    {
        [$school,$member,$subject,$term] = $this->world();
        $this->piece($member,$subject,'2026-09-01','scored',93);
        $service = app(ReportCardService::class);
        $card = $service->prepare($member,'report_card','2026-2027',1);
        $service->publish($card);
        $family = LetterGrades::family($card->fresh());
        $heading = LetterGrades::periodLabel($card->fresh());
        $this->assertNotSame([], $family);
        foreach ([['letter_grades'=>false], []] as $overrides) {
            // Off by the office's setting, and off by the switch itself.
            $school->forceFill(['letter_grade_settings'=>['enabled'=>false] + $school->letter_grade_settings, 'capability_overrides'=>$overrides + $school->capability_overrides])->save();
            \Illuminate\Support\Facades\Cache::flush();
            $fresh = $card->fresh();
            $this->assertSame($family, LetterGrades::family($fresh));
            $this->assertSame($heading, LetterGrades::periodLabel($fresh));
            $pdf = (new \ReflectionMethod(app(\App\Services\Schools\ReportCardPdfService::class),'data'))->invoke(app(\App\Services\Schools\ReportCardPdfService::class),$fresh,$member);
            $this->assertSame($family, $pdf['letterGrades']);
            $this->assertSame($heading, $pdf['periodLabel']);
        }
    }

    #[Test]
    public function current_term_uses_the_schools_local_day_at_a_utc_boundary(): void
    {
        [$school,$member,$subject,$term] = $this->world();
        $school->update(['timezone'=>'America/New_York']);
        SchoolTerm::create(['masjid_id'=>$school->id,'school_year_id'=>$term->school_year_id,'name'=>'Second term','position'=>2,'starts_on'=>'2026-11-01','ends_on'=>'2027-01-31']);
        $this->teacher($school,$member,$subject);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-01 00:30:00','UTC'));
        $this->getJson('/api/teacher/masjids/'.$school->id.'/groups/'.$member->group_id.'/report-cards')->assertOk()->assertJsonPath('data.period.term',1);
    }

}
