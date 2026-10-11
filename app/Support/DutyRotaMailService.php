<?php

namespace App\Support;

use App\Models\{DutyRota, DutyRotaFamily, DutyRotaAssignment, DutyRotaMail, Masjid};
use App\Jobs\SendDutyRotaMail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class DutyRotaMailService
{
    /** Called under the rota mutex: log + unique key + assignment stamp in one transaction. */
    public function queue(DutyRota $r, DutyRotaFamily $f, string $kind, array $held, ?string $url=null, ?DutyRotaAssignment $assignment=null): bool
    {
        if (! $f->email || ! filter_var($f->email,FILTER_VALIDATE_EMAIL)) return false;
        $key=$kind==='reminder' ? 'reminder:'.$assignment->id : $kind.':'.Str::uuid();
        if (DutyRotaMail::withoutMasjidScope()->where('dedupe_key',$key)->exists()) return false;
        $school=Masjid::findOrFail($r->masjid_id);
        $owed=max(0,$r->turns_owed-count($held));
        $safeName=MailGreeting::safeName($f->name);
        $greeting=$safeName ? 'Hello '.$safeName.',' : 'Hello,';
        $heldLine='Days you hold: '.($held ? implode('; ',array_map(SchoolCalendar::label(...),$held)) : 'None').'.';
        if ($kind==='pick') {
            $subject='Pick your '.mb_strtolower($r->name).' day';
            $body="{$greeting}\n\n{$school->name}: please pick ".$owed.' more '.($owed===1?'day':'days')." for {$r->name}.\n{$heldLine}\n";
            if ($f->unplaced_picks) $body.='Your picks that were full: '.implode('; ',array_map(SchoolCalendar::label(...),$f->unplaced_picks)).".\n";
            $body.="Pick your days: {$url}\nYou can choose fewer days and come back. This private link works through the last day of the school year.\n\nReply to this email if you need help.";
        } elseif ($kind==='reminder') {
            $subject='Reminder: '.$r->name.' on '.SchoolCalendar::label($assignment->duty_on);
            $body="{$greeting}\n\nYour {$r->name} day at {$school->name} is ".SchoolCalendar::label($assignment->duty_on).".\n";
            if ($r->reminder_line) $body.=$r->reminder_line."\n";
            $body.="Give the day back or choose another: {$url}\nYou can give it back until the day before.\n\nReply to this email if you need help.";
        } else {
            $subject='Your '.$r->name.' days';
            $body="{$greeting}\n\nYour {$r->name} days at {$school->name} have been updated.\n{$heldLine}\n\nReply to this email if you need help.";
        }
        $mail=DutyRotaMail::create(['masjid_id'=>$r->masjid_id,'rota_id'=>$r->id,'family_id'=>$f->id,'assignment_id'=>$assignment?->id,'kind'=>$kind,'dedupe_key'=>$key,'status'=>'queued','queued_at'=>now()]);
        // A queue or failed_jobs payload must not contain a reusable private token or copied PII.
        $encrypted=Crypt::encryptString(json_encode(['address'=>$f->email,'orgName'=>$school->name,'orgEmail'=>$school->email,'subject'=>$subject,'body'=>$body],JSON_THROW_ON_ERROR));
        SendDutyRotaMail::dispatch((int)$mail->id,(int)$r->masjid_id,$encrypted)->afterCommit();
        if ($assignment) $assignment->forceFill(['reminder_queued_at'=>now()])->save();

        return true;
    }

    public function emailNeeds(DutyRota $rota, ?int $familyId=null): array
    {
        return app(DutyRotaService::class)->locked($rota,function($r) use($familyId) {
            if (! $r->active) throw \Illuminate\Validation\ValidationException::withMessages(['rota'=>['Activate this rota before emailing claim links.']]);
            $data=app(DutyRotaService::class)->office($r); $result=['queued'=>0,'skipped_no_email'=>0];
            foreach ($data['families'] as $row) {
                if ($row['still_owed']<=0 || ($familyId !== null && $row['id']!==$familyId)) continue;
                $f=DutyRotaFamily::withoutMasjidScope()->where('masjid_id',$r->masjid_id)->where('rota_id',$r->id)->findOrFail($row['id']);
                if (! $f->email || ! filter_var($f->email,FILTER_VALIDATE_EMAIL)) { $result['skipped_no_email']++; continue; }
                $link=app(DutyRotaService::class)->newLink($r,$f);
                if ($this->queue($r,$f,'pick',$row['held'],$link['url'])) $result['queued']++;
            }
            return $result;
        });
    }
}
