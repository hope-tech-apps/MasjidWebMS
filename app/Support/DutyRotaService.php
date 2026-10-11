<?php

namespace App\Support;

use App\Models\{DutyRota, DutyRotaFamily, DutyRotaAssignment, DutyRotaMail, Form, FormResponse, Masjid, SchoolYear};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Every assignment writer shares the rota row mutex. Dates come only from the calendar. */
final class DutyRotaService
{
    public const UNAVAILABLE = 'This private link is unavailable. Ask the school office for a new link.';

    public function save(Masjid $school, ?DutyRota $rota, array $data): DutyRota
    {
        abort_unless(DutyRotaMode::enabled($school),404);
        return DB::transaction(function () use ($school,$rota,$data) {
            // Calendar writers also hold the school mutex before a year; creation cannot race deletion.
            $lockedSchool=Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
            abort_unless(DutyRotaMode::enabled($lockedSchool),404);
            if ($rota) $rota=DutyRota::where('masjid_id',$school->id)->whereKey($rota->id)->lockForUpdate()->firstOrFail();
            $year=SchoolYear::where('masjid_id',$school->id)->whereKey($data['school_year_id'])->lockForUpdate()->firstOrFail();
            if ($rota && DutyRotaFamily::where('rota_id',$rota->id)->exists() && ((int)$rota->school_year_id !== (int)$year->id || $rota->source_form_id != ($data['source_form_id'] ?? null) || $rota->source_field !== ($data['source_field'] ?? null))) {
                throw ValidationException::withMessages(['source'=>['Keep this rota’s year and source to preserve its history. Create a new rota for a different year or source.']]);
            }
            if (! empty($data['source_form_id'])) {
                $form=Form::where('masjid_id',$school->id)->whereKey($data['source_form_id'])->lockForUpdate()->firstOrFail();
                $fields=self::sourceFields($form);
                if (! in_array($data['source_field'] ?? null,array_column($fields,'name'),true)) throw ValidationException::withMessages(['source_field'=>['Choose a school meeting days checkbox field from this form.']]);
                $form->forceFill(['has_duty_rota_records'=>true,'duty_rota_enabled'=>true])->save(); DutyRotaMode::rememberForm($form);
            } elseif (! empty($data['source_field'])) throw ValidationException::withMessages(['source_field'=>['Choose a source form first.']]);
            $year->forceFill(['has_duty_rota_records'=>true])->save();
            if (app()->bound('request')) request()->attributes->set('duty_retained_year_'.$year->id,true);
            $data['masjid_id']=$school->id;
            if ($rota) $rota->fill($data)->save(); else $rota=DutyRota::create($data);
            return $rota->fresh();
        });
    }

    public static function sourceFields(Form $form): array
    {
        $out=[];
        foreach ($form->schema['sections'] ?? [] as $section) {
            if (! empty($section['repeatable'])) continue;
            foreach ($section['fields'] ?? [] as $f) if (($f['type'] ?? null)==='checkboxGroup' && ($f['optionsSource'] ?? null)==='school_meeting_days') $out[]=['name'=>$f['name'],'label'=>$f['label'] ?? $f['name']];
        }
        return $out;
    }

    /** First transaction statement is the mutex; locking row reads stay fresh even in nested callers. */
    public function locked(DutyRota $rota, callable $work): mixed
    {
        return DB::transaction(function () use ($rota,$work) {
            $locked=DutyRota::withoutMasjidScope()->where('masjid_id',$rota->masjid_id)->whereKey($rota->id)->lockForUpdate()->firstOrFail();
            $school=Masjid::whereKey($locked->masjid_id)->sharedLock()->first();
            abort_unless(DutyRotaMode::enabled($school),404);
            return $work($locked,$school);
        },3);
    }

    private function calendar(DutyRota $rota): array
    {
        // Fresh authority after the mutex, including closures committed while this caller waited.
        SchoolCalendarReaders::forget((int)$rota->masjid_id);
        $calendar=SchoolDateAuthority::for((int)$rota->masjid_id);
        $year=$calendar->years()->firstWhere('id',$rota->school_year_id);
        abort_unless($year,404);
        return [$calendar,$year,$calendar->openDaysBetween($year->first_day->toDateString(),$year->last_day->toDateString())];
    }

    private function rows(DutyRota $rota): array
    {
        return [DutyRotaFamily::withoutMasjidScope()->where('masjid_id',$rota->masjid_id)->where('rota_id',$rota->id)->orderBy('id')->lockForUpdate()->get(),
            DutyRotaAssignment::withoutMasjidScope()->where('masjid_id',$rota->masjid_id)->where('rota_id',$rota->id)->orderBy('id')->lockForUpdate()->get()];
    }

    private function reconcile(DutyRota $rota, array $open, $families, $assignments): void
    {
        foreach ($assignments as $a) if ($a->released_at === null && ! in_array($a->duty_on,$open,true)) {
            $reason=SchoolCalendar::label($a->duty_on).' was closed';
            $this->release($a,$reason);
            $family=$families->firstWhere('id',$a->family_id);
            if ($family) { $family->notices=array_values(array_unique([...($family->notices ?? []),$reason])); $family->save(); }
        }
    }

    public function build(DutyRota $rota, bool $preview=false): array
    {
        try {
            return $this->locked($rota,function ($r) use ($preview) {
                [$calendar,$year,$open]=$this->calendar($r); [$families,$assignments]=$this->rows($r); $this->reconcile($r,$open,$families,$assignments);
                $responses=$r->source_form_id ? FormResponse::where('masjid_id',$r->masjid_id)->where('form_id',$r->source_form_id)->orderBy('submitted_at')->orderBy('id')->lockForUpdate()->get() : collect();
                $live=$responses->filter(fn($row)=>$row->status !== 'cancelled');
                $grouped=$live->groupBy(fn($row)=>self::identity($row->respondent_email,$row->respondent_phone,'response:'.$row->id));
                $result=['registrations'=>$live->count(),'picks'=>0,'placed'=>0,'unplaced'=>0];
                foreach ($grouped as $identity=>$registrations) {
                    $first=$registrations->first(); $family=$families->firstWhere('identity_hash',$identity);
                    if (! $family) { $family=DutyRotaFamily::create(['masjid_id'=>$r->masjid_id,'rota_id'=>$r->id,'identity_hash'=>$identity,'name'=>$first->respondent_name ?: 'Family','email'=>self::email($first->respondent_email),'phone'=>$first->respondent_phone,'submitted_at'=>$first->submitted_at]); $families->push($family); }
                    if ($family->submitted_at === null || ($first->submitted_at && $first->submitted_at->lt($family->submitted_at))) $family->submitted_at=$first->submitted_at;
                    $family->registration_count=$registrations->count(); $family->withdrawn=false; $family->unplaced_picks=[]; $family->save();
                }
                foreach ($families as $family) if ($family->registration_count > 0 && ! $grouped->has($family->identity_hash)) {
                    $family->registration_count=0; $family->withdrawn=true; $family->unplaced_picks=[]; $family->save();
                    foreach ($assignments as $a) if ($a->family_id===$family->id && $a->source==='form' && $a->released_at===null && $a->duty_on>$calendar->today()) $this->release($a,'All registrations cancelled; past turns kept.');
                }
                // Global response order, then the family's picks in stored order. No grouped pass may jump the queue.
                foreach ($live as $row) {
                    $family=$families->firstWhere('identity_hash',self::identity($row->respondent_email,$row->respondent_phone,'response:'.$row->id));
                    $picks=$row->data[$r->source_field] ?? []; if (! is_array($picks)) continue;
                    foreach ($picks as $day) {
                        $result['picks']++;
                        if (! is_string($day) || ! in_array($day,$open,true)) { $result['unplaced']++; continue; }
                        // A release is intentional history, never an invitation for the form to undo it.
                        $existing=$assignments->first(fn($a)=>$a->family_id===$family->id && $a->duty_on===$day);
                        if ($existing) { $result[$existing->released_at===null?'placed':'unplaced']++; continue; }
                        $held=$assignments->filter(fn($a)=>$a->family_id===$family->id && $a->released_at===null)->count();
                        if ($day>$calendar->today() && $held >= $r->turns_owed) { $result['unplaced']++; continue; }
                        $taken=$assignments->filter(fn($a)=>$a->duty_on===$day && $a->released_at===null)->count();
                        if ($day>$calendar->today() && $taken >= $r->capacity) { $family->unplaced_picks=array_values(array_unique([...$family->unplaced_picks,$day])); $family->save(); $result['unplaced']++; continue; }
                        $assignments->push($this->assign($r,$family,$day,'form',$assignments)); $result['placed']++;
                    }
                }
                if ($preview) throw new DutyRotaPreview($result);
                return $result;
            });
        } catch (DutyRotaPreview $e) { return $e->result; }
    }

    public static function email(?string $email): ?string { $value=mb_strtolower(trim((string)$email)); return $value === '' ? null : $value; }
    public static function identity(?string $email, ?string $phone, string $fallback=''): string
    {
        $email=self::email($email); $digits=preg_replace('/\D/','',(string)$phone);
        return hash('sha256',$email ? 'email:'.$email : ($digits !== '' ? 'phone:'.$digits : $fallback));
    }

    private function assign(DutyRota $r, DutyRotaFamily $family, string $day, string $source, $assignments): DutyRotaAssignment
    {
        $old=$assignments->first(fn($a)=>$a->family_id===$family->id && $a->duty_on===$day);
        if ($old) { $old->forceFill(['source'=>$source,'released_at'=>null,'release_reason'=>null])->save(); return $old; }
        return DutyRotaAssignment::create(['masjid_id'=>$r->masjid_id,'rota_id'=>$r->id,'family_id'=>$family->id,'duty_on'=>$day,'source'=>$source]);
    }
    private function release(DutyRotaAssignment $a,string $reason): void { $a->forceFill(['released_at'=>now(),'release_reason'=>$reason])->save(); }
    private function owed(DutyRota $r,DutyRotaFamily $f,$assignments): int { return $f->withdrawn ? 0 : max(0,$r->turns_owed-$assignments->where('family_id',$f->id)->whereNull('released_at')->count()); }

    public function office(DutyRota $rota): array
    {
        return $this->locked($rota,function($r) {
            [$calendar,$year,$open]=$this->calendar($r); [$families,$assignments]=$this->rows($r); $this->reconcile($r,$open,$families,$assignments);
            $held=$assignments->whereNull('released_at'); $map=$families->keyBy('id');
            $familyRows=$families->map(fn($f)=>[...$f->toArray(),'still_owed'=>$this->owed($r,$f,$assignments),'held'=>$held->where('family_id',$f->id)->pluck('duty_on')->sort()->values()->all()])->values()->all();
            $days=array_map(fn($day)=>['date'=>$day,'taken'=>$held->where('duty_on',$day)->count(),'capacity'=>$r->capacity,'assignments'=>$held->where('duty_on',$day)->map(fn($a)=>[...$a->toArray(),'name'=>$map[$a->family_id]->name,'email'=>$map[$a->family_id]->email,'phone'=>$map[$a->family_id]->phone,'registration_count'=>$map[$a->family_id]->registration_count])->values()->all()],$open);
            $mails=DutyRotaMail::withoutMasjidScope()->where('masjid_id',$r->masjid_id)->where('rota_id',$r->id)->orderByDesc('id')->get()->map(fn($m)=>[...$m->toArray(),'name'=>$map[$m->family_id]?->name]);
            foreach ($familyRows as &$familyRow) {
                $mail=$mails->first(fn($m)=>$m['family_id']===$familyRow['id'] && $m['kind']==='pick');
                $familyRow['link_mail_status']=$mail ? ($mail['status']==='sending'?'queued':$mail['status']) : null;
                $familyRow['link_mail_at']=$mail ? ($mail['sent_at'] ?? $mail['failed_at'] ?? $mail['queued_at']) : null;
            }
            unset($familyRow);
            $future=array_filter($days,fn($d)=>$d['date']>$calendar->today());
            return ['rota'=>$r->toArray(),'today'=>$calendar->today(),'timezone'=>$calendar->timezone(),'days'=>$days,'families'=>$familyRows,'mails'=>$mails->all(),'history'=>$assignments->whereNotNull('released_at')->values()->toArray(),'totals'=>['families'=>$families->count(),'taken'=>array_sum(array_column($future,'taken')),'available'=>count($future)*$r->capacity,'needs'=>count(array_filter($familyRows,fn($f)=>$f['still_owed']>0))]];
        });
    }

    public function issue(DutyRota $rota,DutyRotaFamily $family): array
    {
        return $this->locked($rota,function($r) use($family) {
            $f=DutyRotaFamily::withoutMasjidScope()->where('masjid_id',$r->masjid_id)->where('rota_id',$r->id)->whereKey($family->id)->lockForUpdate()->firstOrFail();
            return $this->newLink($r,$f);
        });
    }
    public function newLink(DutyRota $r,DutyRotaFamily $f): array
    {
        [$calendar,$year]=$this->calendar($r);
        if ($year->last_day->toDateString() < $calendar->today()) throw ValidationException::withMessages(['year'=>['This school year has ended. Create a new year and rota before issuing a link.']]);
        $nonce=bin2hex(random_bytes(32)); $token=self::tokenForNonce($nonce);
        $f->forceFill(['token_hash'=>self::hashToken($token),'issuance_nonce'=>$nonce,'token_expires_at'=>\Carbon\CarbonImmutable::parse($year->last_day->toDateString(),$calendar->timezone())->endOfDay()->utc()])->save();
        return ['token'=>$token,'url'=>rtrim(config('app.url'),'/').'/duty/'.$r->masjid_id.'/'.$r->id.'#token='.$token];
    }
    /** Nonce is not a bearer credential; derivation requires APP_KEY, with a separate domain from hashing. */
    public static function tokenForNonce(string $nonce): string { return hash_hmac('sha256','duty-rota-link:'.$nonce,(string)config('app.key')); }
    public function currentLink(DutyRota $r,DutyRotaFamily $f): string
    {
        if (! $f->issuance_nonce || ! $f->token_expires_at?->gte(now())) return $this->newLink($r,$f)['url'];
        return rtrim(config('app.url'),'/').'/duty/'.$r->masjid_id.'/'.$r->id.'#token='.self::tokenForNonce($f->issuance_nonce);
    }
    public static function hashToken(string $token): string { return hash_hmac('sha256',$token,(string)config('app.key')); }

    public function publicAction(DutyRota $rota,string $token,string $action,array $dates=[],?string $giveBack=null): array
    {
        return $this->locked($rota,function($r,$school) use($token,$action,$dates,$giveBack) {
            abort_unless($r->active,410,self::UNAVAILABLE);
            [$calendar,$year,$open]=$this->calendar($r); [$families,$assignments]=$this->rows($r);
            $family=$families->first(fn($f)=>$f->token_hash !== null && hash_equals($f->token_hash,self::hashToken($token)) && $f->token_expires_at?->gte(now()));
            abort_unless($family,410,self::UNAVAILABLE); $this->reconcile($r,$open,$families,$assignments);
            $saved=[]; $refused=[];
            if ($action==='claim') {
                if (count($dates)>$this->owed($r,$family,$assignments)) throw ValidationException::withMessages(['dates'=>['Pick no more days than you still owe.']]);
                foreach ($dates as $day) {
                    if (! in_array($day,$open,true) || $day <= $calendar->today() || $assignments->where('duty_on',$day)->whereNull('released_at')->count()>=$r->capacity || $assignments->where('family_id',$family->id)->where('duty_on',$day)->whereNull('released_at')->isNotEmpty()) { $refused[]=$day; continue; }
                    $a=$this->assign($r,$family,$day,'claim',$assignments); if (! $assignments->contains('id',$a->id)) $assignments->push($a); $saved[]=$day;
                }
            }
            if ($action==='give-back') {
                if ($giveBack <= $calendar->today()) throw ValidationException::withMessages(['date'=>['A day can be given back only before that day.']]);
                $a=$assignments->first(fn($a)=>$a->family_id===$family->id && $a->duty_on===$giveBack && $a->released_at===null);
                if (! $a) throw ValidationException::withMessages(['date'=>['You do not hold that day.']]);
                $this->release($a,'Given back by the family.'); $saved[]=$giveBack;
            }
            $held=$assignments->where('family_id',$family->id)->whereNull('released_at')->pluck('duty_on')->sort()->values()->all();
            if ($saved !== []) app(DutyRotaMailService::class)->queue($r,$family,'confirmation',$held);
            $available=[];
            foreach ($open as $day) {
                $left=$r->capacity-$assignments->where('duty_on',$day)->whereNull('released_at')->count();
                if ($day>$calendar->today() && $left>0 && ! in_array($day,$held,true)) $available[]=['date'=>$day,'left'=>$left];
            }
            return ['school'=>['name'=>$school->name,'email'=>$school->email,'logo_url'=>$school->header_logo?->getFullUrl() ?? $school->logo?->getFullUrl()],'rota'=>['name'=>$r->name,'capacity'=>$r->capacity],'family'=>['name'=>$family->name],'held'=>$held,'still_owed'=>$this->owed($r,$family,$assignments),'today'=>$calendar->today(),'available'=>$available,'saved'=>$saved,'refused'=>$refused,'message'=>implode(' ',array_map(fn($d)=>SchoolCalendar::label($d).' was just taken or is no longer available. Pick another.',$refused))];
        });
    }

    public function officeAssignment(DutyRota $rota,array $data): void
    {
        $this->locked($rota,function($r) use($data) {
            [$calendar,$year,$open]=$this->calendar($r); [$families,$assignments]=$this->rows($r); $this->reconcile($r,$open,$families,$assignments);
            $a=isset($data['assignment_id']) ? $assignments->firstWhere('id',(int)$data['assignment_id']) : null;
            if (isset($data['assignment_id'])) abort_unless($a && $a->released_at===null,404);
            if (($data['action'] ?? 'add')==='remove') { $this->release($a,'Removed by the office.'); return; }
            $day=$data['date'];
            if (! in_array($day,$open,true)) throw ValidationException::withMessages(['date'=>['Choose an open school day in this year.']]);
            $family=$a ? $families->firstWhere('id',$a->family_id) : (isset($data['family_id']) ? $families->firstWhere('id',(int)$data['family_id']) : null);
            if (isset($data['family_id'])) abort_unless($family,404);
            if (! $family) {
                $identity=self::identity($data['email'] ?? null,$data['phone'] ?? null);
                $family=$families->firstWhere('identity_hash',$identity) ?? DutyRotaFamily::create(['masjid_id'=>$r->masjid_id,'rota_id'=>$r->id,'identity_hash'=>$identity,'name'=>$data['name'],'email'=>self::email($data['email'] ?? null),'phone'=>$data['phone'] ?? null,'registration_count'=>0]);
            }
            if ($assignments->where('family_id',$family->id)->where('duty_on',$day)->whereNull('released_at')->isNotEmpty()) throw ValidationException::withMessages(['date'=>['This family already holds that day.']]);
            if (empty($data['over_capacity']) && $assignments->where('duty_on',$day)->whereNull('released_at')->count()>=$r->capacity) throw ValidationException::withMessages(['date'=>['That day is full. Choose another day or deliberately allow over capacity.']]);
            if ($a) $this->release($a,'Moved by the office to '.SchoolCalendar::label($day).'.');
            $this->assign($r,$family,$day,'office',$assignments);
        });
    }
}

final class DutyRotaPreview extends \RuntimeException
{
    public function __construct(public array $result) { parent::__construct('Rollback preview'); }
}
