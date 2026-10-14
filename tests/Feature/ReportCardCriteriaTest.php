<?php

namespace Tests\Feature;

use App\Models\{Masjid, Contact, Group, GroupMembership, GroupStaff, ClassSubject, User, SchoolYear, SchoolTerm, ReportCardBand, ReportCardCriterion, ReportCardCriteriaDraft, ReportCardCriteriaHistory, ReportCardBandFreeze};
use App\Support\{ReportCardCriteria, ReportCardTemplate, TenantContext, ClassSubjectMode};
use App\Services\Schools\ReportCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportCardCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private function makeMasjid(bool $enabled = true): Masjid
    {
        $school = Masjid::create(['name'=>'Example School '.uniqid(), 'email'=>uniqid().'@example.invalid', 'phone'=>uniqid(), 'country_id'=>1, 'city_id'=>1, 'address'=>'Example', 'latitude'=>0, 'longitude'=>0, 'org_type'=>'school', 'crm_enabled'=>true]);
        $school->forceFill(['capability_overrides'=>['report_card_criteria'=>$enabled]])->save();
        return $school;
    }

    private function member(Masjid $school, ?string $grade = '1st', ?Group $group = null): GroupMembership
    {
        $group ??= Group::factory()->create(['masjid_id'=>$school->id, 'kind'=>'class']);
        $contact = Contact::factory()->create(['masjid_id'=>$school->id, 'email'=>null]);
        return GroupMembership::create(['masjid_id'=>$school->id,'group_id'=>$group->id,'contact_id'=>$contact->id,'role'=>'member','grade_label'=>$grade]);
    }

    private function admin(): User { return User::factory()->create(['type'=>'MasjidAdmin', 'phone'=>uniqid()]); }
    private function edit(Masjid $school, array $editor, User $user, bool $next = false): array
    {
        return ReportCardCriteria::save($school, $editor['bands'], $user, $next, $editor['revision']);
    }

    #[Test]
    public function off_is_hidden_and_uses_the_exact_legacy_rows_without_seeding(): void
    {
        $school = $this->makeMasjid(false);
        $this->assertArrayNotHasKey('report_card_criteria', $school->capabilities);
        $this->assertArrayNotHasKey('report_card_criteria', $school->toArray()['capability_overrides']);
        foreach (['Pre-K','KG','1st','12th',null,'unplaced'] as $grade) {
            $data = ReportCardCriteria::template($school, $grade);
            $this->assertSame(ReportCardTemplate::forGrade($grade), $data['subjects']);
            $this->assertSame(ReportCardTemplate::LEARNING_BEHAVIOURS, $data['behaviours']);
        }
        $card = app(ReportCardService::class)->prepare($this->member($school), 'report_card', '2026-2027', 1);
        $this->assertSame(ReportCardTemplate::rowsForGrade('1st'), $card->marks->where('kind','academic')->map->only(['subject','criterion'])->values()->all());
        $this->assertSame(0, ReportCardBand::count());
        $this->assertArrayNotHasKey('criteria_band_id', $card->toArray());
    }

    #[Test]
    public function editor_seeds_every_grade_exactly_once_and_criteria_are_curriculum_text(): void
    {
        $school = $this->makeMasjid(); $admin = $this->admin();
        $editor = ReportCardCriteria::editor($school, $admin);
        $this->assertCount(3, $editor['bands']);
        ReportCardCriteria::editor($school, $admin);
        $this->assertSame(3, ReportCardBand::count());
        foreach (['Pre-K','KG','1st','2nd','12th'] as $grade) {
            $data = ReportCardCriteria::template($school, $grade);
            $this->assertSame(ReportCardTemplate::forGrade($grade), $data['subjects']);
            $this->assertSame(ReportCardTemplate::LEARNING_BEHAVIOURS, $data['behaviours']);
        }
        $this->assertSame('text', \Illuminate\Support\Facades\Schema::getColumnType('report_card_criteria','text'));
    }

    #[Test]
    public function combined_class_uses_each_students_band_and_unknown_grade_explains_default(): void
    {
        $school = $this->makeMasjid(); ReportCardCriteria::editor($school, $this->admin());
        $first = $this->member($school, 'Pre-K'); $group = $first->group;
        foreach ([$first, $this->member($school,'KG',$group), $this->member($school,'1st',$group)] as $member) {
            $card = app(ReportCardService::class)->prepare($member, 'report_card','2026-2027',1);
            $this->assertSame(ReportCardTemplate::rowsForGrade($member->grade_label), ReportCardCriteria::marks($card)->where('kind','academic')->map->only(['subject','criterion'])->values()->all());
        }
        $data = ReportCardCriteria::template($school, 'unplaced');
        $this->assertSame(ReportCardTemplate::forGrade('1st'), $data['subjects']);
        $this->assertStringContainsString('default', $data['notice']);
    }

    #[Test]
    public function admin_edits_ordered_criteria_behaviours_and_default_without_rewriting_existing_cards(): void
    {
        $school = $this->makeMasjid(); $admin = $this->admin();
        $editor = ReportCardCriteria::editor($school,$admin);
        $member = $this->member($school);
        $card = app(ReportCardService::class)->prepare($member,'report_card','2026-2027',1);
        $before = $card->marks->toArray();
        $editor['bands'][2]['subjects'][0]['criteria'] = ['New curriculum text','Second criterion'];
        $editor['bands'][2]['behaviours'] = ['Works independently'];
        $this->edit($school,$editor,$admin);
        $this->assertSame(['New curriculum text','Second criterion'], ReportCardCriteria::template($school,'1st')['subjects']["Qur'an"]);
        $this->assertSame($before, app(ReportCardService::class)->prepare($member,'report_card','2026-2027',1)->marks->toArray());
        $this->assertSame($admin->id, ReportCardCriteriaHistory::latest('id')->first()->actor_user_id);
        $this->assertNotNull(ReportCardCriteriaHistory::latest('id')->first()->created_at);
    }

    #[Test]
    public function ranges_reject_gaps_overlaps_unknown_grades_and_missing_default(): void
    {
        $school=$this->makeMasjid(); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        foreach (['gap','overlap','unknown','default'] as $case) {
            $bad=$editor;
            if ($case==='gap') $bad['bands'][2]['from_grade']='2nd';
            if ($case==='overlap') $bad['bands'][2]['from_grade']='KG';
            if ($case==='unknown') $bad['bands'][2]['to_grade']='University';
            if ($case==='default') $bad['bands'][2]['is_default']=false;
            try { $this->edit($school,$bad,$admin); $this->fail('Invalid bands accepted: '.$case); }
            catch (ValidationException) { $this->assertTrue(true); }
        }
    }

    #[Test]
    public function admin_can_split_rename_and_merge_bands(): void
    {
        $school=$this->makeMasjid(); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $upper=$editor['bands'][2]; $editor['bands'][2]['to_grade']='2nd';
        $upper['id']=null; $upper['name']='Older grades'; $upper['from_grade']='3rd'; $upper['is_default']=false;
        $editor['bands'][]=$upper;
        $saved=$this->edit($school,$editor,$admin); $this->assertCount(4,$saved['bands']);
        $saved['bands'][2]['to_grade']='12th'; array_pop($saved['bands']);
        $saved['bands'][2]['name']='Grade 1 and up';
        $this->assertCount(3,$this->edit($school,$saved,$admin)['bands']);
    }

    #[Test]
    public function core_only_still_filters_academic_subjects_after_editing(): void
    {
        $school=$this->makeMasjid();
        $school->forceFill(['capability_overrides'=>['report_card_criteria'=>true,'report_card_core_subjects'=>true]])->save();
        ReportCardCriteria::editor($school,$this->admin());
        foreach (['Pre-K','KG','1st','12th'] as $grade) $this->assertSame(ReportCardTemplate::forGrade($grade,true), ReportCardCriteria::template($school,$grade)['subjects']);
    }

    #[Test]
    public function teacher_can_only_edit_assigned_subjects_in_bands_they_teach(): void
    {
        $school=$this->makeMasjid(); $school->forceFill(['capability_overrides'=>['report_card_criteria'=>true,'class_subjects'=>true]])->save();
        $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $member=$this->member($school);
        $subject=ClassSubject::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'name'=>'Mathematics','position'=>0]);
        $teacher=User::factory()->create(['type'=>'Teacher','phone'=>uniqid()]);
        GroupStaff::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'user_id'=>$teacher->id,'role'=>'teacher','class_subject_ids'=>[$subject->id],'class_subjects_mapped_at'=>now(),'assigned_at'=>now()]);
        ClassSubjectMode::forget((int)$school->id);
        $math=array_search('Mathematics',array_column($editor['bands'][2]['subjects'],'name'));
        $editor['bands'][2]['subjects'][$math]['criteria']=['New maths criterion'];
        $saved=$this->edit($school,$editor,$teacher);
        $this->assertSame(['New maths criterion'],ReportCardCriteria::template($school,'1st')['subjects']['Mathematics']);
        foreach (['subject','band','behaviour','metadata'] as $case) {
            $bad=$saved;
            if ($case==='subject') $bad['bands'][2]['subjects'][0]['criteria']=['Not assigned'];
            if ($case==='band') $bad['bands'][0]['subjects'][$math=0]['criteria']=['Wrong grade band'];
            if ($case==='behaviour') $bad['bands'][2]['behaviours']=['Not unrestricted'];
            if ($case==='metadata') $bad['bands'][2]['name']='Renamed';
            try { $this->edit($school,$bad,$teacher); $this->fail('Teacher escaped fence'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        }
    }

    /** A band's learning behaviours cover every class in it: a teacher may not edit them, even an unrestricted class teacher. */
    #[Test]
    public function an_unrestricted_class_teacher_cannot_edit_learning_behaviours(): void
    {
        $school=$this->makeMasjid(); $school->forceFill(['capability_overrides'=>['report_card_criteria'=>true]])->save();
        $editor=ReportCardCriteria::editor($school,$this->admin());
        $member=$this->member($school);
        $teacher=User::factory()->create(['type'=>'Teacher','phone'=>uniqid()]);
        GroupStaff::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'user_id'=>$teacher->id,'role'=>'teacher','assigned_at'=>now()]);
        $editor['bands'][2]['behaviours']=['Changed by a teacher'];
        try { $this->edit($school,$editor,$teacher); $this->fail('A teacher edited learning behaviours'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $this->assertSame(ReportCardTemplate::LEARNING_BEHAVIOURS, ReportCardCriteria::template($school->fresh(),'1st')['behaviours']);
    }

    private function dates(Masjid $school): void
    {
        $school->forceFill(['capability_overrides'=>['report_card_criteria'=>true,'school_calendar'=>true,'school_calendar_terms'=>true]])->save();
        $year=SchoolYear::create(['masjid_id'=>$school->id,'label'=>'Example year','first_day'=>'2026-08-01','last_day'=>'2027-06-30','meeting_weekdays'=>[1],'term_system'=>'quarters']);
        foreach ([1=>['2026-08-01','2026-10-31'],2=>['2026-11-01','2027-01-31']] as $position=>$dates) SchoolTerm::create(['masjid_id'=>$school->id,'school_year_id'=>$year->id,'name'=>'Term '.$position,'position'=>$position,'starts_on'=>$dates[0],'ends_on'=>$dates[1]]);
    }

    #[Test]
    public function sending_freezes_band_even_after_takeback_and_draft_applies_on_next_term_start(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin();
        ReportCardCriteria::editor($school,$admin); $member=$this->member($school);
        $service=app(ReportCardService::class); $card=$service->prepare($member,'report_card','2026-2027',1);
        $service->publish($card); $before=ReportCardCriteria::marks($card)->toArray();
        $editor=ReportCardCriteria::editor($school,$admin); $this->assertTrue($editor['bands'][2]['frozen']);
        $service->unpublish($card); $this->assertTrue(ReportCardCriteria::editor($school,$admin)['bands'][2]['frozen']);
        $editor['bands'][2]['subjects'][0]['criteria']=['Next term curriculum'];
        try { $this->edit($school,$editor,$admin); $this->fail('Frozen change accepted'); } catch (ValidationException) { $this->assertTrue(true); }
        $draft=$this->edit($school,$editor,$admin,true);
        $this->assertSame('2026-11-01',$draft['draft_effective_on']);
        $this->assertNotSame(['Next term curriculum'],ReportCardCriteria::template($school,'1st')['subjects']["Qur'an"]);
        $this->travelTo(now()->setDate(2026,11,1));
        $this->assertSame(['Next term curriculum'],ReportCardCriteria::template($school->fresh(),'1st')['subjects']["Qur'an"]);
        $this->assertFalse(ReportCardCriteria::editor($school,$admin)['bands'][2]['frozen']);
        $this->assertSame($before,ReportCardCriteria::marks($card)->toArray());
        $this->travelBack();
    }

    #[Test]
    public function without_dated_terms_freeze_is_for_the_school_year(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $admin=$this->admin(); ReportCardCriteria::editor($school,$admin);
        SchoolYear::create(['masjid_id'=>$school->id,'label'=>'2026-2027','first_day'=>'2026-08-15','last_day'=>'2027-06-15']);
        $service=app(ReportCardService::class); $service->publish($service->prepare($this->member($school),'progress','2026-2027',1));
        $editor=ReportCardCriteria::editor($school,$admin); $this->assertTrue($editor['bands'][2]['frozen']);
        $editor['bands'][2]['behaviours']=['Next year behaviour'];
        $this->edit($school,$editor,$admin,true);
        $this->travelTo(now()->setDate(2027,8,15));
        $this->assertSame(['Next year behaviour'],ReportCardCriteria::template($school->fresh(),'1st')['behaviours']);
        $this->travelBack();
    }

    #[Test]
    public function tenant_owned_storage_and_foreign_band_ids_are_isolated(): void
    {
        $one=$this->makeMasjid(); $two=$this->makeMasjid(); $admin=$this->admin();
        $a=ReportCardCriteria::editor($one,$admin); $b=ReportCardCriteria::editor($two,$admin);
        $a['bands'][0]['id']=$b['bands'][0]['id'];
        try { $this->edit($one,$a,$admin); $this->fail('Foreign band accepted'); } catch (ValidationException) { $this->assertTrue(true); }
        app(TenantContext::class)->set((int)$two->id);
        $this->assertNull(ReportCardBand::find($a['bands'][2]['id']));
        $this->assertSame(3,ReportCardBand::count());
        $this->assertSame(0,ReportCardCriterion::where('band_id',$a['bands'][2]['id'])->count());
        foreach ([ReportCardCriteriaDraft::class,ReportCardCriteriaHistory::class,ReportCardBandFreeze::class] as $model) {
            $this->assertSame(0,$model::where('masjid_id',$one->id)->count());
            $this->assertGreaterThanOrEqual(0,$model::withoutMasjidScope()->where('masjid_id',$one->id)->count());
        }
        app(TenantContext::class)->forgetTenant();
    }

    #[Test]
    public function http_admin_editor_roundtrips_browser_payload_and_rejects_another_school(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $school=$this->makeMasjid(); $other=$this->makeMasjid(); $admin=$this->admin();
        \App\Models\MasjidUser::create(['masjid_id'=>$school->id,'user_id'=>$admin->id,'role'=>'admin','is_default'=>true]);
        \Laravel\Sanctum\Sanctum::actingAs($admin,['staff']);
        $url='/api/admin/masjids/'.$school->id.'/report-card-criteria';
        $editor=$this->getJson($url)->assertOk()->json('data');
        $editor['bands'][2]['subjects'][0]['criteria']=['Edited through the office'];
        foreach ($editor['bands'] as &$band) $band['is_default']=$band['is_default'] ? 'true' : 'false';
        unset($band);
        $this->put($url,['bands'=>$editor['bands'],'revision'=>$editor['revision'],'next_term'=>'false'],['Accept'=>'application/json'])->assertOk();
        $this->assertSame(['Edited through the office'],ReportCardCriteria::template($school,'1st')['subjects']["Qur'an"]);
        $this->getJson('/api/admin/masjids/'.$other->id.'/report-card-criteria')->assertForbidden();
        $this->putJson('/api/admin/masjids/'.$other->id.'/report-card-criteria',['bands'=>$editor['bands'],'revision'=>1])->assertForbidden();
    }

    #[Test]
    public function http_teacher_editor_uses_the_subject_and_class_fences_and_off_is_inaccessible(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $school=$this->makeMasjid(); $member=$this->member($school); $other=$this->member($school);
        $teacher=User::factory()->create(['type'=>'Teacher','phone'=>uniqid()]);
        \App\Models\MasjidUser::create(['masjid_id'=>$school->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
        GroupStaff::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'user_id'=>$teacher->id,'role'=>'teacher','subjects'=>['quran'],'assigned_at'=>now()]);
        \Laravel\Sanctum\Sanctum::actingAs($teacher,['staff']);
        $base='/api/teacher/masjids/'.$school->id.'/groups/'; $url=$base.$member->group_id.'/report-card-criteria';
        $editor=$this->getJson($url)->assertOk()->json('data');
        $this->assertFalse($editor['can_manage_bands']);
        $this->getJson($base.$member->group_id)->assertOk()->assertJsonPath('data.report_card_criteria_enabled',true);
        $editor['bands'][2]['subjects'][0]['criteria']=['Teacher curriculum edit'];
        $this->putJson($url,['bands'=>$editor['bands'],'revision'=>$editor['revision']])->assertOk();
        $editor=$this->getJson($url)->json('data');
        $editor['bands'][2]['subjects'][1]['criteria']=['Outside subject fence'];
        $this->putJson($url,['bands'=>$editor['bands'],'revision'=>$editor['revision']])->assertForbidden();
        $this->getJson($base.$other->group_id.'/report-card-criteria')->assertForbidden();
        $school->forceFill(['capability_overrides'=>['report_card_criteria'=>false]])->save();
        $this->getJson($url)->assertForbidden();
        $this->getJson($base.$member->group_id)->assertOk()->assertJsonMissingPath('data.report_card_criteria_enabled');
        $this->assertSame(0,ReportCardCriteriaDraft::count());
    }

    #[Test]
    public function persistent_drafts_history_and_freezes_reject_cross_tenant_reads_updates_and_deletes(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $one=$this->makeMasjid(); $two=$this->makeMasjid(); $this->dates($one); $admin=$this->admin();
        $editor=ReportCardCriteria::editor($one,$admin);
        $service=app(ReportCardService::class); $service->publish($service->prepare($this->member($one),'report_card','2026-2027',1));
        $editor['bands'][2]['behaviours']=['Deferred curriculum']; $this->edit($one,$editor,$admin,true);
        $draft=ReportCardCriteriaDraft::where('masjid_id',$one->id)->firstOrFail();
        $history=ReportCardCriteriaHistory::where('masjid_id',$one->id)->firstOrFail();
        $freeze=ReportCardBandFreeze::where('masjid_id',$one->id)->firstOrFail();
        $criterion=ReportCardCriterion::where('masjid_id',$one->id)->firstOrFail();
        app(TenantContext::class)->set((int)$two->id);
        $this->assertNull(ReportCardCriteriaDraft::find($draft->id));
        $this->assertNull(ReportCardCriteriaHistory::find($history->id));
        $this->assertNull(ReportCardBandFreeze::find($freeze->id));
        $this->assertNull(ReportCardCriterion::find($criterion->id));
        $this->assertSame(0,ReportCardCriteriaDraft::whereKey($draft->id)->update(['actor_user_id'=>null]));
        $this->assertSame(0,ReportCardCriteriaHistory::whereKey($history->id)->delete());
        $this->assertSame(0,ReportCardBandFreeze::whereKey($freeze->id)->delete());
        $this->assertSame(0,ReportCardCriterion::whereKey($criterion->id)->delete());
        app(TenantContext::class)->forgetTenant(); $this->travelBack();
    }

    #[Test]
    public function sent_cards_and_their_export_and_pdf_rows_stay_unchanged_after_deferred_edits(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin(); ReportCardCriteria::editor($school,$admin);
        $member=$this->member($school); $service=app(ReportCardService::class);
        $card=$service->publish($service->prepare($member,'report_card','2026-2027',1));
        $before=ReportCardCriteria::marks($card)->toArray();
        $editor=ReportCardCriteria::editor($school,$admin); $editor['bands'][2]['subjects'][0]['criteria']=['Changed after issue'];
        $this->edit($school,$editor,$admin,true); $this->travelTo(now()->setDate(2026,11,1));
        ReportCardCriteria::template($school,'1st');
        $service->prepare($member,'report_card','2026-2027',1);
        $this->assertSame($before,ReportCardCriteria::marks($card)->toArray());
        $method=new \ReflectionMethod(\App\Services\Schools\ReportCardPdfService::class,'data');
        $pdf=$method->invoke(app(\App\Services\Schools\ReportCardPdfService::class),$card,$member);
        $this->assertSame('Recitation',$pdf['subjects'][0]['criteria'][0]['criterion']);
        $this->assertSame('Recitation',ReportCardCriteria::markQuery($card)->orderBy('position')->first()->criterion);
        $this->travelBack();
    }

    #[Test]
    public function deleting_staff_nulls_curriculum_attribution_and_keeps_the_school_records(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $this->edit($school,$editor,$admin,true);
        $admin->forceDelete();
        $this->assertSame(3,ReportCardBand::count());
        $this->assertSame(1,ReportCardCriteriaDraft::count());
        $this->assertNull(ReportCardCriteriaDraft::first()->actor_user_id);
        $this->assertSame(0,ReportCardCriteriaHistory::whereNotNull('actor_user_id')->count());
        $this->travelBack();
    }

    #[Test]
    public function default_off_capability_writer_does_not_create_override_or_audit(): void
    {
        $school=$this->makeMasjid(false);
        $school->forceFill(['capability_overrides'=>[]])->save();
        \App\Support\CapabilityWriter::apply($school,['report_card_criteria'=>false],null);
        $this->assertSame([], $school->fresh()->capability_overrides);
        $this->assertSame(0,\App\Models\MasjidCapabilityChange::where('capability','report_card_criteria')->count());
        $this->assertSame(\App\Support\CapabilityCatalogue::HIDDEN,\App\Support\CapabilityCatalogue::visibility('report_card_criteria',config('capabilities.report_card_criteria'),'school'));
    }

    #[Test]
    public function only_the_band_used_by_a_sent_card_freezes_and_unrelated_current_edits_survive_a_draft(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin(); ReportCardCriteria::editor($school,$admin);
        $service=app(ReportCardService::class); $service->publish($service->prepare($this->member($school),'report_card','2026-2027',1));
        $editor=ReportCardCriteria::editor($school,$admin);
        $this->assertFalse($editor['bands'][0]['frozen']); $this->assertTrue($editor['bands'][2]['frozen']);
        $editor['bands'][2]['behaviours']=['Next term behaviour']; $this->edit($school,$editor,$admin,true);
        $current=ReportCardCriteria::editor($school,$admin); $current['bands'][0]['behaviours']=['Current early years behaviour'];
        $this->edit($school,$current,$admin);
        $this->assertSame(['Current early years behaviour'],ReportCardCriteria::template($school,'Pre-K')['behaviours']);
        $this->travelTo(now()->setDate(2026,11,1));
        $this->assertSame(['Current early years behaviour'],ReportCardCriteria::template($school,'Pre-K')['behaviours']);
        $this->assertSame(['Next term behaviour'],ReportCardCriteria::template($school,'1st')['behaviours']);
        $this->travelBack();
    }

    #[Test]
    public function deferred_criteria_follow_a_rescheduled_term_and_do_not_apply_on_the_old_start(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $editor['bands'][2]['behaviours']=['Deferred behaviour']; $this->edit($school,$editor,$admin,true);
        SchoolTerm::where('masjid_id',$school->id)->where('position',2)->first()->update(['starts_on'=>'2026-11-05']);
        $this->travelTo(now()->setDate(2026,11,1));
        $this->assertSame(ReportCardTemplate::LEARNING_BEHAVIOURS,ReportCardCriteria::template($school,'1st')['behaviours']);
        $this->assertSame('2026-11-05',ReportCardCriteria::editor($school,$admin)['draft_effective_on']);
        $this->travelTo(now()->setDate(2026,11,5));
        $this->assertSame(['Deferred behaviour'],ReportCardCriteria::template($school,'1st')['behaviours']);
        $this->travelBack();
    }

    #[Test]
    public function opening_the_editor_after_publication_already_freezes_the_seeded_band(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $service=app(ReportCardService::class);
        $service->publish($service->prepare($this->member($school),'report_card','2026-2027',1));
        $this->assertSame(0,ReportCardBand::count());
        $editor=ReportCardCriteria::editor($school,$this->admin());
        $this->assertTrue($editor['bands'][2]['frozen']); $this->assertFalse($editor['bands'][0]['frozen']);
        $this->travelBack();
    }

    #[Test]
    public function custom_class_subjects_and_existing_subject_aliases_use_the_assigned_class_ids(): void
    {
        $school=$this->makeMasjid(); $school->forceFill(['capability_overrides'=>['report_card_criteria'=>true,'class_subjects'=>true]])->save();
        $member=$this->member($school);
        $subject=ClassSubject::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'name'=>'Arabic','position'=>0]);
        $teacher=User::factory()->create(['type'=>'Teacher','phone'=>uniqid()]);
        GroupStaff::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'user_id'=>$teacher->id,'role'=>'teacher','class_subject_ids'=>[$subject->id],'class_subjects_mapped_at'=>now(),'assigned_at'=>now()]);
        $editor=ReportCardCriteria::editor($school,$teacher);
        $this->assertTrue($editor['bands'][2]['subjects'][2]['can_edit']);
        $editor['bands'][2]['subjects'][2]['name']='Arabic';
        $editor['bands'][2]['subjects'][2]['criteria']=['Reading curriculum'];
        $this->edit($school,$editor,$teacher);
        $this->assertSame(['Reading curriculum'],ReportCardCriteria::template($school,'1st')['subjects']['Arabic']);
    }

    #[Test]
    public function curriculum_schema_has_primary_keys_short_unique_indexes_and_refuses_populated_rollback(): void
    {
        $school=$this->makeMasjid(); ReportCardCriteria::editor($school,$this->admin());
        foreach (['report_card_bands','report_card_criteria','report_card_criteria_drafts','report_card_criteria_histories','report_card_band_freezes'] as $table) {
            $indexes=\Illuminate\Support\Facades\Schema::getIndexes($table);
            $this->assertNotEmpty(array_filter($indexes,fn($index)=>$index['primary']));
            foreach ($indexes as $index) $this->assertLessThanOrEqual(64,strlen($index['name']));
        }
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_14_210000_add_report_card_criteria.php'))->down();
    }

    #[Test]
    public function sending_while_the_switch_is_off_freezes_a_previously_selected_band_on_reentry(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $service=app(ReportCardService::class); $card=$service->prepare($this->member($school),'report_card','2026-2027',1);
        $school->forceFill(['capability_overrides'=>['report_card_criteria'=>false]])->save();
        $service->publish($card);
        $school->forceFill(['capability_overrides'=>['report_card_criteria'=>true]])->save();
        $editor['bands'][2]['behaviours']=['Must remain frozen'];
        try { $this->edit($school,$editor,$admin); $this->fail('Publication while off was ignored'); } catch (ValidationException) { $this->assertTrue(true); }
        $this->assertTrue(ReportCardCriteria::editor($school,$admin)['bands'][2]['frozen']);
        $this->travelBack();
    }

    #[Test]
    public function a_new_band_in_a_future_draft_has_its_own_teacher_permission_flags(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin();
        $editor=ReportCardCriteria::editor($school,$admin); $member=$this->member($school,'1st');
        $teacher=User::factory()->create(['type'=>'Teacher','phone'=>uniqid()]);
        GroupStaff::create(['masjid_id'=>$school->id,'group_id'=>$member->group_id,'user_id'=>$teacher->id,'role'=>'teacher','subjects'=>['quran'],'assigned_at'=>now()]);
        $upper=$editor['bands'][2]; $editor['bands'][2]['to_grade']='2nd';
        $upper['id']=null; $upper['from_grade']='3rd'; $upper['name']='Older students'; $upper['is_default']=false; $editor['bands'][]=$upper;
        $this->edit($school,$editor,$admin,true);
        $teacherEditor=ReportCardCriteria::editor($school,$teacher);
        $this->assertTrue($teacherEditor['draft_bands'][2]['subjects'][0]['can_edit']);
        $this->assertFalse($teacherEditor['draft_bands'][3]['subjects'][0]['can_edit']);
        $this->assertSame([],$teacherEditor['draft_bands'][3]['editable_subject_names']);
        $this->assertFalse($teacherEditor['draft_bands'][3]['can_edit_behaviours']);
        $this->travelBack();
    }

    #[Test]
    public function stale_editor_cannot_overwrite_another_staff_save(): void
    {
        $school=$this->makeMasjid(); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $editor['bands'][2]['behaviours']=['First save']; $this->edit($school,$editor,$admin);
        $this->expectException(ValidationException::class); $this->edit($school,$editor,$admin);
    }
}
