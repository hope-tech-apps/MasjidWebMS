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

    private function mathCriteria(array $editor, array $criteria): array
    {
        $math=array_search('Mathematics',array_column($editor['bands'][2]['subjects'],'name'));
        $editor['bands'][2]['subjects'][$math]['criteria']=$criteria;
        return $editor;
    }

    #[Test]
    public function a_late_started_prior_term_card_uses_its_period_after_a_draft_applies(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin();
        $editor=ReportCardCriteria::editor($school,$admin);
        $earlier=['Quarter one maths','Quarter one reasoning','Quarter one fluency'];
        $editor=$this->edit($school,$this->mathCriteria($editor,$earlier),$admin);
        $service=app(ReportCardService::class);
        $issued=$service->publish($service->prepare($this->member($school),'report_card','2026-2027',1));
        $inProgress=$service->prepare($this->member($school),'report_card','2026-2027',1);
        $issuedRows=ReportCardCriteria::marks($issued)->toArray();
        $unfinishedRows=ReportCardCriteria::marks($inProgress)->toArray();
        $next=['Quarter two maths','Quarter two reasoning','Quarter two fluency','Quarter two geometry','Quarter two data'];
        $this->edit($school,$this->mathCriteria($editor,$next),$admin,true);
        $this->travelTo(now()->setDate(2026,11,1));
        $this->assertSame($next,ReportCardCriteria::template($school,'1st')['subjects']['Mathematics']);
        $member=$this->member($school);
        $late=$service->prepare($member,'report_card','2026-2027',1);
        $this->assertSame($earlier,$late->marks->where('subject','Mathematics')->pluck('criterion')->all());
        $new=$service->prepare($member,'report_card','2026-2027',2);
        $this->assertSame($next,$new->marks->where('subject','Mathematics')->pluck('criterion')->all());
        $this->assertSame($issuedRows,$service->prepare($issued->membership,'report_card','2026-2027',1)->marks->toArray());
        $this->assertSame($unfinishedRows,$service->prepare($inProgress->membership,'report_card','2026-2027',1)->marks->toArray());
        $this->travelBack();
    }

    #[Test]
    public function two_applied_drafts_keep_late_q1_and_q2_cards_and_full_band_versions_recoverable(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin();
        $year=SchoolYear::where('masjid_id',$school->id)->firstOrFail();
        $q3=SchoolTerm::create(['masjid_id'=>$school->id,'school_year_id'=>$year->id,'name'=>'Term 3','position'=>3,'starts_on'=>'2027-02-01','ends_on'=>'2027-04-30']);
        SchoolTerm::create(['masjid_id'=>$school->id,'school_year_id'=>$year->id,'name'=>'Term 4','position'=>4,'starts_on'=>'2027-05-01','ends_on'=>'2027-06-30']);
        $q1=SchoolTerm::where('masjid_id',$school->id)->where('position',1)->firstOrFail();
        $q2=SchoolTerm::where('masjid_id',$school->id)->where('position',2)->firstOrFail();
        $editor=ReportCardCriteria::editor($school,$admin);
        $editor=$this->edit($school,$this->mathCriteria($editor,['Q1 maths']),$admin);
        $q1Band=$editor['bands'][2]['id'];
        $editor['bands'][2]['behaviours']=['Q2 behaviour'];
        $editor['bands'][2]['name']='Q2 upper grades';
        $editor['bands'][2]['id']=null;
        $this->edit($school,$this->mathCriteria($editor,['Q2 maths']),$admin,true);
        $this->travelTo(now()->setDate(2026,11,1));
        $editor=ReportCardCriteria::editor($school,$admin);
        $q2Band=$editor['bands'][2]['id'];
        $this->assertNotSame($q1Band,$q2Band);
        $editor['bands'][2]['behaviours']=['Q3 behaviour'];
        $editor['bands'][2]['name']='Q3 upper grades';
        $this->edit($school,$this->mathCriteria($editor,['Q3 maths']),$admin,true);
        // The first activation read is late too, after Q3 has already started.
        $this->travelTo(now()->setDate(2027,2,4));
        $service=app(ReportCardService::class); $member=$this->member($school);
        foreach ([1=>['Q1 maths',$q1Band,ReportCardTemplate::LEARNING_BEHAVIOURS],2=>['Q2 maths',$q2Band,['Q2 behaviour']],3=>['Q3 maths',$q2Band,['Q3 behaviour']]] as $term=>$expected) {
            $card=$service->prepare($member,'progress','2026-2027',$term);
            $this->assertSame([$expected[0]],$card->marks->where('subject','Mathematics')->pluck('criterion')->all());
            $this->assertSame($expected[1],$card->criteria_band_id);
            $this->assertSame($expected[2],$card->marks->where('kind','behaviour')->pluck('criterion')->all());
        }
        $later=$service->prepare($member,'progress','2026-2027',4);
        $this->assertSame(['Q3 maths'],$later->marks->where('subject','Mathematics')->pluck('criterion')->all());
        foreach ([$q1,$q2,$q3] as $i=>$term) {
            $version=ReportCardCriteriaHistory::where('masjid_id',$school->id)->where('effective_period','term:'.$term->id)->whereNotNull('bands')->latest('id')->firstOrFail();
            $this->assertSame($term->starts_on->toDateString(),$version->effective_on->toDateString());
            $math=collect($version->bands[2]['subjects'])->firstWhere('name','Mathematics');
            $this->assertSame(['Q'.($i+1).' maths'],$math['criteria']);
        }
        $this->assertSame(['Q3 maths'],ReportCardCriteria::template($school,'1st')['subjects']['Mathematics']);
        $this->travelBack();
    }

    #[Test]
    public function without_dated_terms_late_cards_use_their_own_school_year_including_a_named_year(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $admin=$this->admin();
        SchoolYear::create(['masjid_id'=>$school->id,'label'=>'Earlier school year','first_day'=>'2026-08-15','last_day'=>'2027-06-15']);
        SchoolYear::create(['masjid_id'=>$school->id,'label'=>'Next school year','first_day'=>'2027-08-15','last_day'=>'2028-06-15']);
        $editor=ReportCardCriteria::editor($school,$admin);
        $editor=$this->edit($school,$this->mathCriteria($editor,['Earlier year maths']),$admin);
        $this->edit($school,$this->mathCriteria($editor,['Next year maths']),$admin,true);
        $this->travelTo(now()->setDate(2027,8,15));
        ReportCardCriteria::editor($school,$admin);
        $service=app(ReportCardService::class); $member=$this->member($school);
        foreach (['2026-2027','Earlier school year'] as $label) {
            $card=$service->prepare($member,'report_card',$label,4);
            $this->assertSame(['Earlier year maths'],$card->marks->where('subject','Mathematics')->pluck('criterion')->all());
            $service->publish($card);
            $this->assertTrue(ReportCardBandFreeze::where('band_id',$card->criteria_band_id)->where('period_key','year:2026-2027')->exists());
        }
        $new=$service->prepare($member,'report_card','2027-2028',1);
        $this->assertSame(['Next year maths'],$new->marks->where('subject','Mathematics')->pluck('criterion')->all());
        $this->travelBack();
    }

    #[Test]
    public function the_august_rollover_without_calendar_years_keeps_prior_year_criteria(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $editor=$this->edit($school,$this->mathCriteria($editor,['Earlier year']),$admin);
        $this->edit($school,$this->mathCriteria($editor,['Next year']),$admin,true);
        $this->travelTo(now()->setDate(2027,8,1));
        $service=app(ReportCardService::class); $member=$this->member($school);
        foreach (['2026-2027'=>'Earlier year','2027-2028'=>'Next year'] as $year=>$expected) {
            $card=$service->prepare($member,'report_card',$year,1);
            $this->assertSame([$expected],$card->marks->where('subject','Mathematics')->pluck('criterion')->all());
        }
        $this->travelBack();
    }

    #[Test]
    public function immediate_edits_in_a_later_term_do_not_replace_an_earlier_terms_configuration(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $this->edit($school,$this->mathCriteria($editor,['Earlier maths']),$admin);
        $this->travelTo(now()->setDate(2026,11,2));
        $editor=ReportCardCriteria::editor($school,$admin);
        $this->edit($school,$this->mathCriteria($editor,['Later maths']),$admin);
        $card=app(ReportCardService::class)->prepare($this->member($school),'report_card','2026-2027',1);
        $this->assertSame(['Earlier maths'],$card->marks->where('subject','Mathematics')->pluck('criterion')->all());
        $this->travelBack();
    }

    #[Test]
    public function history_times_and_draft_dates_are_formatted_in_the_school_timezone(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-15 01:17:00','UTC'));
        $school=$this->makeMasjid(); $this->dates($school); $school->update(['timezone'=>'America/Los_Angeles']);
        $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $draft=$this->edit($school,$editor,$admin,true);
        $this->assertSame('Oct 14, 2026, 6:17 PM',$draft['history'][0]['at']);
        $this->assertSame('2026-11-01',$draft['draft_effective_on']);
        $this->assertSame('Nov 1, 2026',$draft['draft_effective_on_label']);
        $this->travelBack();
    }

    #[Test]
    public function the_existing_unversioned_configuration_is_preserved_before_activating_a_pending_draft(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $editor=$this->edit($school,$this->mathCriteria($editor,['Earlier custom maths']),$admin);
        $this->edit($school,$this->mathCriteria($editor,['Future maths']),$admin,true);
        // Simulate the earlier build's action-only history, with a pending draft.
        ReportCardCriteriaHistory::where('masjid_id',$school->id)->update(['bands'=>null,'effective_period'=>null,'effective_on'=>null]);
        $this->travelTo(now()->setDate(2026,11,1));
        ReportCardCriteria::editor($school,$admin);
        $card=app(ReportCardService::class)->prepare($this->member($school),'report_card','2026-2027',1);
        $this->assertSame(['Earlier custom maths'],$card->marks->where('subject','Mathematics')->pluck('criterion')->all());
        $this->travelBack();
    }

    #[Test]
    public function period_versions_cannot_be_read_or_overwritten_through_another_tenant(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $one=$this->makeMasjid(); $two=$this->makeMasjid(); $this->dates($one); $this->dates($two); $admin=$this->admin();
        foreach ([$one,$two] as $school) {
            $editor=ReportCardCriteria::editor($school,$admin);
            $editor=$this->edit($school,$this->mathCriteria($editor,['School '.$school->id.' earlier']),$admin);
            $this->edit($school,$this->mathCriteria($editor,['School '.$school->id.' later']),$admin,true);
        }
        $version=ReportCardCriteriaHistory::where('masjid_id',$one->id)->whereNotNull('bands')->latest('id')->firstOrFail();
        app(TenantContext::class)->set((int)$two->id);
        $this->assertNull(ReportCardCriteriaHistory::find($version->id));
        $this->assertSame(0,ReportCardCriteriaHistory::whereKey($version->id)->update(['bands'=>[]]));
        $this->assertSame(0,ReportCardCriteriaHistory::whereKey($version->id)->delete());
        app(TenantContext::class)->forgetTenant();
        $this->travelTo(now()->setDate(2026,11,1));
        foreach ([$one,$two] as $school) {
            $member=$this->member($school);
            foreach ([1=>'earlier',2=>'later'] as $term=>$suffix) {
                $card=app(ReportCardService::class)->prepare($member,'report_card','2026-2027',$term);
                $this->assertSame(['School '.$school->id.' '.$suffix],$card->marks->where('subject','Mathematics')->pluck('criterion')->all());
            }
        }
        $this->travelBack();
    }

    #[Test]
    public function switching_off_ignores_versions_and_pending_drafts_for_new_cards(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin(); $editor=ReportCardCriteria::editor($school,$admin);
        $editor=$this->edit($school,$this->mathCriteria($editor,['Configured maths']),$admin);
        $this->edit($school,$this->mathCriteria($editor,['Next maths']),$admin,true);
        $school->forceFill(['capability_overrides'=>['report_card_criteria'=>false]])->save();
        $this->travelTo(now()->setDate(2026,11,1));
        $card=app(ReportCardService::class)->prepare($this->member($school),'report_card','2026-2027',1);
        $this->assertSame(ReportCardTemplate::rowsForGrade('1st'),$card->marks->where('kind','academic')->map->only(['subject','criterion'])->values()->all());
        $this->assertNull(ReportCardCriteriaDraft::firstOrFail()->applied_at);
        $this->assertNull($card->criteria_band_id);
        $this->travelBack();
    }

    #[Test]
    public function calendar_terms_without_dates_use_the_school_year_for_both_versions_and_freezes(): void
    {
        $this->travelTo(now()->setDate(2026,10,14));
        $school=$this->makeMasjid(); $this->dates($school); $admin=$this->admin();
        SchoolTerm::where('masjid_id',$school->id)->delete();
        $editor=ReportCardCriteria::editor($school,$admin);
        $editor=$this->edit($school,$this->mathCriteria($editor,['Earlier undated year']),$admin);
        $service=app(ReportCardService::class);
        $card=$service->publish($service->prepare($this->member($school),'report_card','2026-2027',1));
        $this->assertTrue(ReportCardBandFreeze::where('band_id',$card->criteria_band_id)->where('period_key','year:2026-2027')->exists());
        $this->assertTrue(ReportCardCriteria::editor($school,$admin)['bands'][2]['frozen']);
        $this->edit($school,$this->mathCriteria($editor,['Next undated year']),$admin,true);
        $this->travelTo(now()->setDate(2027,8,1));
        $late=$service->prepare($this->member($school),'report_card','2026-2027',4);
        $this->assertSame(['Earlier undated year'],$late->marks->where('subject','Mathematics')->pluck('criterion')->all());
        $this->travelBack();
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
    public function version_history_rollback_refuses_to_discard_recoverable_period_configurations(): void
    {
        $school=$this->makeMasjid(); ReportCardCriteria::editor($school,$this->admin());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Criteria versions exist');
        (require database_path('migrations/2026_10_14_220000_version_report_card_criteria_history.php'))->down();
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
