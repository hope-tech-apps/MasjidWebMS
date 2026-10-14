<?php

namespace App\Support;

use App\Models\{Masjid, User, Group, GroupMembership, GroupStaff, ClassSubject, SchoolYear, SchoolTerm, ReportCard, ReportCardBand, ReportCardCriterion, ReportCardCriteriaDraft, ReportCardCriteriaHistory, ReportCardBandFreeze};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One reader for new curriculum and saved card snapshots. All writers lock the school first. */
final class ReportCardCriteria
{
    public const MAX_ROWS = 200;
    /** The rollout grant is available only to active school organisations. */
    public static function enabled(?Masjid $school): bool
    {
        return $school !== null && $school->deleted_at === null && $school->orgType() === 'school' && $school->hasCapability('report_card_criteria');
    }

    /** Keep exports and individual cards on saved rows, including tenant scoping. */
    public static function markQuery(?ReportCard $card = null)
    {
        return $card ? $card->marks() : \App\Models\ReportCardMark::query();
    }

    /** Read the document snapshot in its saved order, never against current curriculum. */
    public static function marks(ReportCard $card): Collection
    {
        return self::markQuery($card)->orderBy('position')->get();
    }

    /** Select current curriculum without seeding; OFF and unopened editors retain the legacy template. */
    public static function template(?Masjid $school, ?string $grade): array
    {
        $legacy = ['subjects'=>ReportCardTemplate::forGrade($grade, SchoolSettings::reportCardCoreOnly($school)), 'behaviours'=>ReportCardTemplate::LEARNING_BEHAVIOURS, 'band_id'=>null, 'notice'=>null];
        if (! self::enabled($school)) return $legacy;
        return DB::transaction(function () use ($school, $grade, $legacy) {
            $locked = Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
            if (! self::enabled($locked)) return $legacy;
            self::activate($locked);
            $bands = self::configuration($locked);
            $band = self::place($bands, $grade);
            if ($band === null) return $legacy;
            $subjects = [];
            $core = array_keys(ReportCardTemplate::forGrade('Pre-K', true));
            foreach ($band['subjects'] as $subject) {
                if (! SchoolSettings::reportCardCoreOnly($locked) || in_array($subject['name'], $core, true) || SubjectKey::staffKeys(SubjectKey::for($subject['name']))!==[]) $subjects[$subject['name']] = $subject['criteria'];
            }
            return ['subjects'=>$subjects, 'behaviours'=>$band['behaviours'], 'band_id'=>$band['id'],
                'notice'=>GradeLevel::position($grade) === null ? 'This student’s grade cannot be placed in the school grade order; the default band “'.$band['name'].'” is used.' : null];
        });
    }

    /** Explain an unplaced grade using the band saved on this card. */
    public static function notice(ReportCard $card, ?Masjid $school): ?string
    {
        if (! self::enabled($school) || GradeLevel::position($card->grade_label)!==null) return null;
        $band=$card->criteria_band_id ? ReportCardBand::where('masjid_id',$school->id)->find($card->criteria_band_id) : null;
        return $band ? 'This student’s grade could not be placed in the school grade order; this card uses the default band “'.$band->name.'”.' : self::template($school,$card->grade_label)['notice'];
    }

    /** Seed once, activate due drafts and describe staff authority under the school mutex. */
    public static function editor(Masjid $school, User $user): array
    {
        return DB::transaction(function () use ($school, $user) {
            $locked = Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::enabled($locked), 404);
            self::activate($locked);
            if (self::configuration($locked) === []) {
                self::apply($locked, self::seeds($locked));
                self::history($locked, $user, 'Seeded criteria');
                // Cards sent before the editor opened already freeze their grade bands.
                foreach (ReportCard::where('masjid_id',$locked->id)->published()->get() as $card) self::freeze($locked,$card);
            }
            self::synchronizePublications($locked);
            return self::payload($locked, $user);
        });
    }

    /** Save a whole validated revision, refusing stale edits, subject escapes and frozen current changes. */
    public static function save(Masjid $school, array $bands, User $user, bool $nextTerm, int $revision): array
    {
        return DB::transaction(function () use ($school, $bands, $user, $nextTerm, $revision) {
            $locked = Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::enabled($locked), 404);
            self::activate($locked);
            self::synchronizePublications($locked);
            if (self::revision($locked) !== $revision) self::invalid('Another staff member changed the criteria. Reload before saving.');
            $current = self::configuration($locked);
            $pending = self::pending($locked);
            $baseline = $nextTerm && $pending ? $pending->bands : $current;
            $clean = self::validate($locked, $bands, $baseline);
            self::authorize($locked, $user, $baseline, $clean);
            if ($nextTerm) {
                $period = self::nextPeriod($locked);
                $draft = $pending ?? new ReportCardCriteriaDraft(['masjid_id'=>$locked->id]);
                $draft->fill(['effective_period'=>$period['key'],'effective_on'=>$period['starts_on'],'bands'=>$clean,'actor_user_id'=>$user->id,'applied_at'=>null])->save();
                self::history($locked,$user,'Saved next-term draft');
            } else {
                foreach ($current as $old) {
                    $new = collect($clean)->firstWhere('id',$old['id']);
                    if ($new !== $old && self::frozen($locked,(int)$old['id'])) self::invalid('A card using this band has been sent; its criteria are read-only for this period. Choose “Changes apply from next term”.');
                }
                if ($pending) $pending->update(['bands'=>self::mergePending($current,$clean,$pending->bands)]);
                self::apply($locked,$clean);
                self::history($locked,$user,'Saved criteria');
            }
            return self::payload($locked,$user);
        });
    }

    /** Called inside the publication transaction, before published_at is written. */
    public static function freeze(Masjid $school, ReportCard $card): void
    {
        if (! self::enabled($school)) return;
        $bandId = $card->criteria_band_id;
        if ($bandId === null) $bandId = self::place(self::configuration($school),$card->grade_label)['id'] ?? null;
        if ($bandId === null) return;
        if ($card->criteria_band_id === null) $card->forceFill(['criteria_band_id'=>$bandId])->save();
        ReportCardBandFreeze::firstOrCreate(['masjid_id'=>$school->id,'band_id'=>$bandId,'period_key'=>self::cardPeriod($school,$card)]);
    }

    /** Sending while the rollout is temporarily off still freezes the band on re-entry. */
    private static function synchronizePublications(Masjid $school): void
    {
        $period=self::currentPeriod($school);
        if ($period['key']==='between-terms') return;
        $cards=ReportCard::where('masjid_id',$school->id)->published();
        if (str_starts_with($period['key'],'term:')) {
            $term=SchoolTerm::where('masjid_id',$school->id)->findOrFail((int)substr($period['key'],5));
            $year=SchoolYear::where('masjid_id',$school->id)->findOrFail($term->school_year_id);
            $labels=[$year->label,$year->first_day->format('Y').'-'.$year->last_day->format('Y')];
            $cards->where(fn($q)=>$q->where('school_term_id',$term->id)->orWhere(fn($legacy)=>$legacy->whereNull('school_term_id')->whereIn('school_year',$labels)->where('term',$term->position)));
        } else $cards->where('school_year',substr($period['key'],5));
        $cards->whereNotExists(fn($q)=>$q->selectRaw('1')->from('report_card_band_freezes')->where('report_card_band_freezes.masjid_id',$school->id)->whereColumn('report_card_band_freezes.band_id','report_cards.criteria_band_id')->where('period_key',$period['key']));
        foreach ($cards->get() as $card) self::freeze($school,$card);
    }

    private static function seeds(Masjid $school): array
    {
        $result = [];
        foreach ([['Pre-K','Pre-K','Pre-K'],['Kindergarten','KG','KG'],['Grade 1 and up','1st','12th']] as $i=>$row) {
            $subjects=[];
            foreach (ReportCardTemplate::forGrade($row[1], SchoolSettings::reportCardCoreOnly($school)) as $name=>$criteria) $subjects[]=['name'=>$name,'criteria'=>$criteria];
            $result[]=['id'=>null,'name'=>$row[0],'from_grade'=>$row[1],'to_grade'=>$row[2],'is_default'=>$i===2,'subjects'=>$subjects,'behaviours'=>ReportCardTemplate::LEARNING_BEHAVIOURS];
        }
        return $result;
    }

    private static function configuration(Masjid $school): array
    {
        $bands=ReportCardBand::where('masjid_id',$school->id)->where('archived',false)->orderBy('position')->get();
        $rows=ReportCardCriterion::where('masjid_id',$school->id)->whereIn('band_id',$bands->modelKeys())->orderBy('subject_position')->orderBy('position')->get()->groupBy('band_id');
        return $bands->map(function ($band) use ($rows) {
            $subjects=[]; $behaviours=[];
            foreach ($rows->get($band->id,collect())->groupBy('subject_position') as $lines) {
                if ($lines->first()->kind==='behaviour') $behaviours=$lines->pluck('text')->all();
                else $subjects[]=['name'=>$lines->first()->subject,'criteria'=>$lines->pluck('text')->all()];
            }
            return ['id'=>(int)$band->id,'name'=>$band->name,'from_grade'=>GradeLevel::LEVELS[$band->from_position],'to_grade'=>GradeLevel::LEVELS[$band->to_position],'is_default'=>$band->is_default,'subjects'=>$subjects,'behaviours'=>$behaviours];
        })->all();
    }

    private static function place(array $bands, ?string $grade): ?array
    {
        $position=GradeLevel::position($grade);
        foreach ($bands as $band) if ($position !== null && $position >= GradeLevel::position($band['from_grade']) && $position <= GradeLevel::position($band['to_grade'])) return $band;
        return collect($bands)->firstWhere('is_default',true);
    }

    private static function validate(Masjid $school, array $bands, array $baseline): array
    {
        $data=validator(['bands'=>$bands], [
            'bands'=>['required','array','min:1','max:14'], 'bands.*.id'=>['nullable','integer'],
            'bands.*.name'=>['required','string','max:120'], 'bands.*.from_grade'=>['required','string'], 'bands.*.to_grade'=>['required','string'],
            'bands.*.is_default'=>['required','boolean'], 'bands.*.subjects'=>['required','array','min:1','max:30'],
            'bands.*.subjects.*.name'=>['required','string','max:120'], 'bands.*.subjects.*.criteria'=>['required','array','min:1','max:100'],
            'bands.*.subjects.*.criteria.*'=>['required','string','max:200'], 'bands.*.behaviours'=>['present','array','max:100'], 'bands.*.behaviours.*'=>['required','string','max:200'],
        ])->validate()['bands'];
        if (! array_is_list($data)) self::invalid('Save bands in grade order.');
        $expected=0; $defaults=0; $seen=[]; $names=[]; $clean=[];
        $validNames=self::subjectNames($school);
        // Previously configured names survive retirement of a class subject.
        foreach ($baseline as $band) foreach ($band['subjects'] as $subject) $validNames[]=$subject['name'];
        foreach ($data as $band) {
            $id=isset($band['id']) ? (int)$band['id'] : null;
            if ($id !== null && (isset($seen[$id]) || ! collect($baseline)->contains('id',$id))) self::invalid('Choose a band belonging to this school.');
            if ($id !== null) $seen[$id]=true;
            $from=GradeLevel::position($band['from_grade']); $to=GradeLevel::position($band['to_grade']);
            if ($from === null || $to === null || $from !== $expected || $to < $from) self::invalid('Use recognised grades in order, with no gaps or overlaps.');
            $expected=$to+1; $defaults+=(int)$band['is_default'];
            $name=trim($band['name']);
            if ($name==='' || isset($names[mb_strtolower($name)])) self::invalid('Give every band a different name.');
            $names[mb_strtolower($name)]=true;
            $subjects=[]; $subjectSeen=[]; $rowCount=count($band['behaviours']);
            foreach ($band['subjects'] as $subject) {
                $subjectName=trim($subject['name']);
                if (! in_array($subjectName,$validNames,true) || isset($subjectSeen[mb_strtolower($subjectName)]) || $subjectName==='Learning Behaviours') self::invalid('Choose unique report-card or class subject names.');
                $subjectSeen[mb_strtolower($subjectName)]=true;
                $criteria=self::texts($subject['criteria']); $rowCount+=count($criteria);
                $subjects[]=['name'=>$subjectName,'criteria'=>$criteria];
            }
            // SaveReportCardRequest accepts at most 200 skill rows in one save.
            if ($rowCount > self::MAX_ROWS) self::invalid('Keep each band to at most 200 criteria including learning behaviours.');
            $clean[]=['id'=>$id,'name'=>$name,'from_grade'=>GradeLevel::LEVELS[$from],'to_grade'=>GradeLevel::LEVELS[$to],'is_default'=>(bool)$band['is_default'],'subjects'=>$subjects,'behaviours'=>self::texts($band['behaviours'])];
        }
        if ($expected !== count(GradeLevel::LEVELS) || $defaults !== 1) self::invalid('Cover every grade and mark exactly one band as default.');
        return $clean;
    }

    private static function texts(array $rows): array
    {
        if (! array_is_list($rows)) self::invalid('Save criteria in order.');
        $clean=array_map('trim',$rows);
        if (in_array('',$clean,true) || count(array_unique($clean)) !== count($clean)) self::invalid('Criteria must be non-empty and unique within their subject.');
        return $clean;
    }

    private static function subjectNames(Masjid $school): array
    {
        $names=array_keys(ReportCardTemplate::forGrade('1st'));
        if (SchoolSettings::classSubjects($school)) $names=array_merge($names,ClassSubject::where('masjid_id',$school->id)->whereNull('hidden_at')->pluck('name')->all());
        return array_values(array_unique($names));
    }

    private static function authorize(Masjid $school, User $user, array $old, array $new): void
    {
        if ($user->type !== 'Teacher') { abort_unless(in_array($user->type,['MasjidAdmin','SuperAdmin'],true),403); return; }
        if (count($old)!==count($new)) abort(403);
        foreach ($old as $i=>$band) {
            $other=$new[$i];
            foreach (['id','name','from_grade','to_grade','is_default'] as $field) abort_unless($band[$field]===$other[$field],403);
            $authority=self::authority($school,$user,$band);
            if ($band['behaviours']!==$other['behaviours']) abort_unless($authority['behaviours'],403);
            $before=collect($band['subjects'])->keyBy('name'); $after=collect($other['subjects'])->keyBy('name');
            foreach ($before->keys()->merge($after->keys())->unique() as $name) {
                if ($before->get($name)!==$after->get($name)) abort_unless(in_array($name,$authority['subjects'],true),403);
            }
            // Reordering also changes a subject, even when its own criteria did not change.
            foreach ($other['subjects'] as $position=>$subject) if (($band['subjects'][$position]['name'] ?? null)!==$subject['name']) abort_unless(in_array($subject['name'],$authority['subjects'],true),403);
        }
    }

    private static function authority(Masjid $school, User $user, array $band): array
    {
        if ($user->type !== 'Teacher') return ['subjects'=>self::subjectNames($school),'behaviours'=>true];
        $names=[];
        $staff=GroupStaff::where('masjid_id',$school->id)->where('user_id',$user->id)->get();
        foreach ($staff as $assignment) {
            $group=Group::where('masjid_id',$school->id)->find($assignment->group_id);
            if (! $group || ! $group->teachesStudents()) continue;
            $hasStudent=GroupMembership::where('masjid_id',$school->id)->where('group_id',$group->id)->participants()->current()->get()->contains(function ($member) use ($band) {
                $position=GradeLevel::position($member->grade_label);
                return $position===null ? $band['is_default'] : $position>=GradeLevel::position($band['from_grade']) && $position<=GradeLevel::position($band['to_grade']);
            });
            if (! $hasStudent) continue;
            if (SchoolSettings::classSubjects($school)) {
                $limits=SubjectFence::limitsForWithClassSubjects($user,(int)$group->id);
                $subjects=ClassSubject::where('masjid_id',$school->id)->where('group_id',$group->id)->whereNull('hidden_at')->get();
                foreach (array_unique(array_merge(self::subjectNames($school),array_column($band['subjects'],'name'))) as $name) {
                    $subject=ReportCardSubjectWork::match($name,$subjects);
                    if ($subject && SubjectFence::allowsWork($limits,(int)$subject->id)) $names[]=$name;
                }
            } else {
                $limits=SubjectFence::assigned((int)$group->id,(int)$user->id);
                foreach (self::subjectNames($school) as $name) if (SubjectFence::allows($limits,SubjectKey::for($name))) $names[]=$name;
            }
        }
        // A band's learning behaviours cover every class in it, so only the office edits them.
        return ['subjects'=>array_values(array_unique($names)),'behaviours'=>false];
    }

    /** Keep unrelated current edits when an earlier next-term draft is already waiting. */
    private static function mergePending(array $before, array $now, array $draft): array
    {
        if ($draft === $before) return $now;
        if ($now === $before || $draft === $now) return $draft;
        // Band/subject identity and order must agree before merging disjoint fields.
        foreach (['id','name'] as $identity) {
            if (isset($before[0]) && is_array($before[0]) && array_key_exists($identity,$before[0])) {
                if (array_column($before,$identity)!==array_column($now,$identity) || array_column($before,$identity)!==array_column($draft,$identity)) self::invalid('These changes conflict with the saved next-term draft. Edit that draft first.');
                break;
            }
        }
        if (array_keys($before)!==array_keys($now) || array_keys($before)!==array_keys($draft)) self::invalid('These changes conflict with the saved next-term draft. Edit that draft first.');
        $merged=[];
        foreach ($before as $key=>$value) {
            if (is_array($value) && is_array($now[$key]) && is_array($draft[$key])) $merged[$key]=self::mergePending($value,$now[$key],$draft[$key]);
            elseif ($draft[$key]===$value) $merged[$key]=$now[$key];
            elseif ($now[$key]===$value || $now[$key]===$draft[$key]) $merged[$key]=$draft[$key];
            else self::invalid('These changes conflict with the saved next-term draft. Edit that draft first.');
        }
        return $merged;
    }

    private static function apply(Masjid $school, array $bands): void
    {
        ReportCardBand::where('masjid_id',$school->id)->where('archived',false)->update(['archived'=>true]);
        foreach ($bands as $position=>$data) {
            $band=isset($data['id']) ? ReportCardBand::where('masjid_id',$school->id)->findOrFail($data['id']) : new ReportCardBand(['masjid_id'=>$school->id]);
            $band->fill(['name'=>$data['name'],'from_position'=>GradeLevel::position($data['from_grade']),'to_position'=>GradeLevel::position($data['to_grade']),'position'=>$position,'is_default'=>$data['is_default'],'archived'=>false])->save();
            ReportCardCriterion::where('masjid_id',$school->id)->where('band_id',$band->id)->delete();
            foreach ($data['subjects'] as $subjectPosition=>$subject) foreach ($subject['criteria'] as $i=>$text) self::criterion($school,$band,$subject['name'],'academic',$subjectPosition,$i,$text);
            foreach ($data['behaviours'] as $i=>$text) self::criterion($school,$band,'Learning Behaviours','behaviour',count($data['subjects']),$i,$text);
        }
    }

    private static function criterion(Masjid $school, ReportCardBand $band, string $subject, string $kind, int $subjectPosition, int $position, string $text): void
    {
        ReportCardCriterion::create(['masjid_id'=>$school->id,'band_id'=>$band->id,'subject'=>$subject,'kind'=>$kind,'subject_position'=>$subjectPosition,'position'=>$position,'text'=>$text]);
    }

    private static function revision(Masjid $school): int { return (int)ReportCardCriteriaHistory::where('masjid_id',$school->id)->max('id'); }
    private static function history(Masjid $school, ?User $user, string $action): void { ReportCardCriteriaHistory::create(['masjid_id'=>$school->id,'actor_user_id'=>$user?->id,'action'=>$action]); }
    private static function pending(Masjid $school): ?ReportCardCriteriaDraft { return ReportCardCriteriaDraft::where('masjid_id',$school->id)->whereNull('applied_at')->orderBy('effective_on')->first(); }

    private static function activate(Masjid $school): void
    {
        $today=SchoolDateAuthority::for((int)$school->id)->today();
        foreach (ReportCardCriteriaDraft::where('masjid_id',$school->id)->whereNull('applied_at')->orderBy('effective_on')->get() as $draft) {
            if (str_starts_with($draft->effective_period,'term:')) {
                $term=SchoolTerm::where('masjid_id',$school->id)->find((int)substr($draft->effective_period,5));
                // A removed or undated term must be replaced explicitly in the editor.
                if (! $term?->starts_on) continue;
                if ($term->starts_on->toDateString()!==$draft->effective_on->toDateString()) $draft->update(['effective_on'=>$term->starts_on->toDateString()]);
            }
            if ($draft->effective_on->toDateString()>$today) continue;
            self::apply($school,$draft->bands); $draft->update(['applied_at'=>now()]);
            ReportCardCriteriaHistory::create(['masjid_id'=>$school->id,'actor_user_id'=>$draft->actor_user_id,'action'=>'Applied next-term draft']);
        }
    }

    private static function payload(Masjid $school, User $user): array
    {
        $pending=self::pending($school); $bands=self::configuration($school);
        $bands=self::describeBands($school,$user,$bands);
        $history=ReportCardCriteriaHistory::where('masjid_id',$school->id)->latest('id')->limit(15)->get();
        $actors=User::whereIn('id',$history->pluck('actor_user_id')->filter())->pluck('name','id');
        return ['bands'=>$bands,'draft_bands'=>$pending ? self::describeBands($school,$user,$pending->bands) : null,'draft_effective_on'=>$pending?->effective_on->toDateString(),
            'grades'=>GradeLevel::LEVELS,'subject_names'=>self::subjectNames($school),'can_manage_bands'=>$user->type!=='Teacher','revision'=>self::revision($school),
            'history'=>$history->map(fn($row)=>['actor'=>$actors[$row->actor_user_id] ?? 'Former staff','at'=>$row->created_at->toIso8601String(),'action'=>$row->action])->all()];
    }

    private static function describeBands(Masjid $school, User $user, array $bands): array
    {
        foreach ($bands as &$band) {
            $authority=self::authority($school,$user,$band);
            $band['frozen']=$band['id']!==null && self::frozen($school,(int)$band['id']);
            $band['can_edit_behaviours']=$authority['behaviours'];
            foreach ($band['subjects'] as &$subject) $subject['can_edit']=$user->type!=='Teacher' || in_array($subject['name'],$authority['subjects'],true);
            unset($subject);
            $band['editable_subject_names']=$authority['subjects'];
        }
        unset($band);
        return $bands;
    }

    private static function periods(Masjid $school): array
    {
        $periods=[];
        if (SchoolSettings::calendarTerms($school)) foreach (SchoolTerm::where('masjid_id',$school->id)->whereNotNull('starts_on')->whereNotNull('ends_on')->get() as $term) $periods[]=['key'=>'term:'.$term->id,'starts_on'=>$term->starts_on->toDateString(),'ends_on'=>$term->ends_on->toDateString()];
        if ($periods!==[]) return collect($periods)->sortBy('starts_on')->values()->all();
        foreach (SchoolYear::where('masjid_id',$school->id)->get() as $year) $periods[]=['key'=>'year:'.$year->first_day->format('Y').'-'.$year->last_day->format('Y'),'starts_on'=>$year->first_day->toDateString(),'ends_on'=>$year->last_day->toDateString()];
        return collect($periods)->sortBy('starts_on')->values()->all();
    }

    private static function currentPeriod(Masjid $school): array
    {
        $today=SchoolDateAuthority::for((int)$school->id)->today();
        $periods=self::periods($school);
        $period=collect($periods)->first(fn($p)=>$p['starts_on']<=$today && $p['ends_on']>=$today);
        if ($period) return $period;
        if (collect($periods)->contains(fn($p)=>str_starts_with($p['key'],'term:'))) return ['key'=>'between-terms','starts_on'=>$today,'ends_on'=>$today];
        // Same August rollover as the existing teacher report-card selector.
        $year=(int)substr($today,0,4)-((int)substr($today,5,2)<8 ? 1 : 0);
        return ['key'=>'year:'.$year.'-'.($year+1),'starts_on'=>$year.'-08-01','ends_on'=>($year+1).'-07-31'];
    }

    private static function nextPeriod(Masjid $school): array
    {
        $today=SchoolDateAuthority::for((int)$school->id)->today();
        $next=collect(self::periods($school))->first(fn($p)=>$p['starts_on']>$today);
        if ($next) return $next;
        if (collect(self::periods($school))->contains(fn($p)=>str_starts_with($p['key'],'term:'))) self::invalid('Add the next dated term in the school calendar before saving a next-term draft.');
        $current=self::currentPeriod($school); $start=\Illuminate\Support\Carbon::parse($current['starts_on'])->addYear();
        while ($start->toDateString()<=$today) $start->addYear();
        return ['key'=>'year:'.$start->year.'-'.($start->year+1),'starts_on'=>$start->toDateString()];
    }

    private static function cardPeriod(Masjid $school, ReportCard $card): string
    {
        if (SchoolSettings::calendarTerms($school)) {
            $term=$card->school_term_id ? SchoolTerm::where('masjid_id',$school->id)->find($card->school_term_id) : null;
            if (! $term) {
                $year=SchoolYear::where('masjid_id',$school->id)->get()->first(fn($y)=>$y->label===$card->school_year || $y->first_day->format('Y').'-'.$y->last_day->format('Y')===$card->school_year);
                $term=$year ? SchoolTerm::where('masjid_id',$school->id)->where('school_year_id',$year->id)->where('position',$card->term)->first() : null;
            }
            if ($term) return 'term:'.$term->id;
        }
        return 'year:'.$card->school_year;
    }

    private static function frozen(Masjid $school, int $band): bool
    {
        $key=self::currentPeriod($school)['key'];
        return ReportCardBandFreeze::where('masjid_id',$school->id)->where('band_id',$band)->where('period_key',$key)->exists();
    }

    private static function invalid(string $message): never { throw ValidationException::withMessages(['bands'=>[$message]]); }
}
