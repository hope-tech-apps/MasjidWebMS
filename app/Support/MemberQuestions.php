<?php

namespace App\Support;

use App\Models\{Contact, Group, Masjid, MemberQuestionAnswer, MemberQuestionHelp, MemberQuestionOption, MemberQuestionSetting};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

final class MemberQuestions
{
    public const KINDS = ['field','occupation','help'];
    public const PROMPTS = ['field'=>'What field do you work in?','occupation'=>'What is your profession?','help'=>'How could you help?'];
    private const DEFAULTS = [
        'field' => ['Education','Healthcare','Technology','Engineering','Business and finance','Legal','Trades and construction','Hospitality and food service','Public service','Nonprofit and community','Student','Homemaker','Retired','Other'],
        'occupation' => ['Teacher or professor','Physician','Nurse','Dentist','Pharmacist','Software or IT professional','Engineer','Accountant or finance professional','Attorney','Business owner','Manager or administrator','Skilled trade','Driver or logistics','Chef or food service','Student','Homemaker','Retired','Other'],
        'help' => ['Teaching and tutoring','Events and hospitality','Youth activities','Community relief','Maintenance and facilities','Technology and media','Professional advice','Other'],
    ];
    public static function enabled(?Masjid $org): bool
    {
        return $org !== null && ! $org->trashed() && $org->hasCapability('app_member_questions');
    }
    /** Caller holds the organisation lock; retained/deactivated lists are never reseeded. */
    public static function seed(Masjid $org): void
    {
        foreach (self::DEFAULTS as $question => $labels) {
            MemberQuestionSetting::withoutMasjidScope()->firstOrCreate(['masjid_id'=>$org->id,'kind'=>$question],['active'=>$question !== 'help','prompt'=>self::PROMPTS[$question]]);
            if (MemberQuestionOption::withoutMasjidScope()->where('masjid_id',$org->id)->where('question',$question)->exists()) continue;
            foreach ($labels as $position=>$label) MemberQuestionOption::withoutMasjidScope()->create([
                'masjid_id'=>$org->id,'question'=>$question,'label'=>$label,'position'=>$position+1,'active'=>true,'is_other'=>$label==='Other',
            ]);
        }
    }
    /** One joined query for all kind settings and ordered options. OFF callers never invoke it. */
    public static function options(int $orgId, bool $editor = false, bool $member = false): array
    {
        $out = ['field'=>[], 'occupation'=>[], 'help'=>[]]; $settings=[];
        $rows=DB::table('member_question_settings as setting')
            ->leftJoin('member_question_options as option',function($join)use($editor){
                $join->on('option.masjid_id','=','setting.masjid_id')->on('option.question','=','setting.kind');
                if (!$editor) $join->where('option.active',true);
            })->where('setting.masjid_id',$orgId)->orderBy('option.position')->orderBy('option.id')
            ->get(['setting.kind','setting.active as asked','setting.prompt','option.id','option.label','option.active','option.is_other','option.position']);
        foreach ($rows as $row) {
            if (!in_array($row->kind,self::KINDS,true)) continue;
            $settings[$row->kind]=['active'=>(bool)$row->asked,'prompt'=>$row->prompt];
            if ($row->id === null) continue;
            $out[$row->kind][] = $editor ? ['id'=>(int)$row->id,'label'=>$row->label,'active'=>(bool)$row->active,'is_other'=>(bool)$row->is_other,'position'=>(int)$row->position]
                : ['id'=>(int)$row->id,'label'=>$row->label] + ($member ? ['is_other'=>(bool)$row->is_other] : []);
        }
        $questions=[];
        foreach (self::KINDS as $kind) if ($settings[$kind]['active'] ?? false) $questions[]=[
            'kind'=>$kind,'prompt'=>$settings[$kind]['prompt'],'multiple'=>$kind==='help','options'=>$out[$kind],
        ];
        return $out + ['questions'=>$questions] + ($editor ? ['settings'=>$settings] : []);
    }
    public static function onboarding(int $orgId): ?array
    {
        $questions=self::options($orgId)['questions'];
        return $questions === [] ? null : ['questions'=>$questions];
    }
    public static function editOptions(int $orgId, array $lists): void
    {
        DB::transaction(function () use ($orgId,$lists) {
            $org=Masjid::whereKey($orgId)->lockForUpdate()->firstOrFail(); abort_unless(self::enabled($org),404);
            $existing=MemberQuestionOption::withoutMasjidScope()->where('masjid_id',$orgId)->get()->keyBy('id');
            $seen=[];
            foreach (self::KINDS as $question) {
                MemberQuestionSetting::withoutMasjidScope()->where('masjid_id',$orgId)->where('kind',$question)->update($lists['settings'][$question]);
                foreach ($lists[$question] as $position=>$input) {
                    $id=$input['id'] ?? null;
                    if ($id !== null && (!isset($existing[$id]) || $existing[$id]->question!==$question || isset($seen[$id]))) throw ValidationException::withMessages([$question=>'Choose different options from this organisation and question.']);
                    $row=$id === null ? new MemberQuestionOption(['masjid_id'=>$orgId,'question'=>$question,'is_other'=>false]) : $existing[$id];
                    $row->fill(['label'=>$input['label'],'active'=>$input['active'],'position'=>$position+1])->save();
                    $seen[$row->id]=true;
                }
            }
            // Omission deactivates rather than erasing an option members already chose.
            foreach ($existing as $id=>$row) if (!isset($seen[$id])) $row->update(['active'=>false]);
            DB::afterCommit(fn()=>MobileCache::flushMasjid($orgId,MobileCache::SHOW));
        });
    }
    public static function assertAdult(Contact $contact, ?Masjid $org = null): void
    {
        $org ??= ClassSubjectMode::loadedOrganisation((int)$contact->masjid_id);
        $age=StudentAge::shown($contact,now($org?->timezone ?? config('app.timezone'))->toDateString())['age'];
        $student=DB::table('group_memberships as member')->join('groups as classroom','classroom.id','=','member.group_id')
            ->where('member.masjid_id',$contact->masjid_id)->where('classroom.masjid_id',$contact->masjid_id)
            ->where(function($query)use($contact){
                $query->where(function($member)use($contact){$member->where('member.contact_id',$contact->id)->where('member.role','member')->where('classroom.kind',Group::KIND_CLASS);})
                    ->orWhere(function($guardian)use($contact){$guardian->where('member.role','guardian')->where('member.guardian_of_contact_id',$contact->id);});
            })->exists();
        abort_if($student || ($age !== null && $age < 18),403,'These questions are for an adult app member answering for themselves.');
    }
    public static function answers(Contact $contact): array
    {
        $answer=MemberQuestionAnswer::withoutMasjidScope()->where('masjid_id',$contact->masjid_id)->where('contact_id',$contact->id)->first();
        return ['field_id'=>$answer?->field_id,'field_text'=>$answer?->field_text,'occupation_id'=>$answer?->occupation_id,'occupation_text'=>$answer?->occupation_text,
            'help_ids'=>$answer === null ? [] : MemberQuestionHelp::withoutMasjidScope()->where('masjid_id',$contact->masjid_id)->where('answer_id',$answer->id)->orderBy('option_id')->pluck('option_id')->map(fn($id)=>(int)$id)->all(), 'help_text'=>$answer?->help_text];
    }
    public static function replace(Contact $member, array $data): void
    {
        abort_unless(app(TenantContext::class)->get() === (int)$member->masjid_id,403);
        DB::transaction(function () use ($member,$data) {
            $org=Masjid::whereKey($member->masjid_id)->lockForUpdate()->firstOrFail(); abort_unless(self::enabled($org),404);
            $contact=Contact::withoutMasjidScope()->where('masjid_id',$org->id)->whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_unless($contact->memberAccessIsActive(),403);
            self::assertAdult($contact,$org);
            $settings=MemberQuestionSetting::withoutMasjidScope()->where('masjid_id',$org->id)->get()->keyBy('kind');
            foreach (self::KINDS as $kind) {
                $value=$data[$kind === 'help' ? 'help_ids' : $kind.'_id'] ?? null;
                if ((!empty($value) || filled($data[$kind.'_text'] ?? null)) && !($settings->get($kind)?->active ?? false)) {
                    throw ValidationException::withMessages([$kind=>'This question is not asked by your organisation. Clear its answer before saving.']);
                }
            }
            $field=$data['field_id'] ?? null; $occupation=$data['occupation_id'] ?? null;
            $help=array_values(array_unique(array_map('intval',$data['help_ids'] ?? [])));
            $ids=array_values(array_filter(array_merge([$field,$occupation],$help),fn($id)=>$id !== null));
            $options=MemberQuestionOption::withoutMasjidScope()->where('masjid_id',$org->id)->where('active',true)->whereIn('id',$ids)->get()->keyBy('id');
            foreach (['field'=>$field,'occupation'=>$occupation] as $kind=>$id) {
                if ($id !== null && (!isset($options[$id]) || $options[$id]->question !== $kind)) throw ValidationException::withMessages([$kind.'_id'=>'That option is not available for this question in your organisation.']);
                if (($data[$kind.'_text'] ?? null) !== null && !($options->get($id)?->is_other ?? false)) throw ValidationException::withMessages([$kind.'_text'=>'Extra words are only accepted with Other.']);
            }
            foreach ($help as $id) if (!isset($options[$id]) || $options[$id]->question !== 'help') throw ValidationException::withMessages(['help_ids'=>'That option is not available for this question in your organisation.']);
            $helpText=$data['help_text'] ?? null;
            if ($helpText !== null && !$options->only($help)->contains(fn($row)=>$row->is_other)) throw ValidationException::withMessages(['help_text'=>'Extra words are only accepted with Other.']);
            $existing=MemberQuestionAnswer::withoutMasjidScope()->where('masjid_id',$org->id)->where('contact_id',$contact->id);
            if ($field === null && $occupation === null && $help === []) { $existing->delete(); return; }
            $answer=$existing->first() ?? new MemberQuestionAnswer(['masjid_id'=>$org->id,'contact_id'=>$contact->id]);
            $answer->fill(['field_id'=>$field,'field_text'=>$data['field_text'] ?? null,'occupation_id'=>$occupation,'occupation_text'=>$data['occupation_text'] ?? null,'help_text'=>$helpText])->save();
            MemberQuestionHelp::withoutMasjidScope()->where('masjid_id',$org->id)->where('answer_id',$answer->id)->delete();
            foreach ($help as $id) MemberQuestionHelp::create(['masjid_id'=>$org->id,'answer_id'=>$answer->id,'option_id'=>$id]);
        });
    }
    /** Called within the existing contact merge transaction, even when disclosure is off. */
    public static function merge(Contact $source, Contact $target): void
    {
        if (!Schema::hasTable('member_question_answers')) return;
        abort_unless($source->masjid_id === $target->masjid_id,403);
        Contact::withoutMasjidScope()->where('masjid_id',$source->masjid_id)->whereIn('id',[$source->id,$target->id])->orderBy('id')->lockForUpdate()->get();
        $rows=MemberQuestionAnswer::withoutMasjidScope()->where('masjid_id',$source->masjid_id)->whereIn('contact_id',[$source->id,$target->id])->lockForUpdate()->get()->keyBy('contact_id');
        $absorbed=$rows->get($source->id); if ($absorbed === null) return;
        if ($rows->has($target->id)) $absorbed->delete();
        else $absorbed->forceFill(['contact_id'=>$target->id])->save();
    }
}
