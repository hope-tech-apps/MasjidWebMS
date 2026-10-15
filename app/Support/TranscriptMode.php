<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TranscriptMode
{
    public static function enabled(?Masjid $school): bool
    {
        return $school!==null && !$school->trashed() && $school->isSchool() && $school->hasCapability('letter_grades') && $school->hasCapability('transcripts');
    }
    public static function assertEnabled(?Masjid $school): void
    {
        if (!self::enabled($school)) throw ValidationException::withMessages(['transcripts'=>['Switch on letter grades and transcripts at this school first.']]);
    }
    public static function assertChanges(Masjid $school,array $changes): void
    {
        $transcripts=$changes['transcripts'] ?? $school->hasCapability('transcripts');
        if ($transcripts && (!$school->isSchool() || !($changes['letter_grades'] ?? $school->hasCapability('letter_grades')))) throw ValidationException::withMessages(['capability'=>['Switch on letter grades at a school before switching on transcripts.']]);
    }
    /** New-key dispatch preserves the existing capability writer's frozen OFF body. */
    public static function apply(Masjid $school,array $changes,?int $actor): array
    {
        return DB::transaction(function () use($school,$changes,$actor) {
            $locked=Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail(); self::assertChanges($locked,$changes);
            $value=$changes['transcripts']; if (!is_bool($value)) throw new \InvalidArgumentException('Transcript switch must be boolean.');
            $before=$locked->hasCapability('transcripts');
            $overrides=$locked->capability_overrides ?? []; $previous=$overrides['transcripts'] ?? null;
            if ($before || $value) {
                // Apply our part first so a combined transcripts/letters OFF write satisfies the dependency.
                $overrides['transcripts']=$value;
                $locked->forceFill(['capability_overrides'=>$overrides,'updated_by'=>$actor])->save();
            }
            $other=array_diff_key($changes,['transcripts'=>true]);
            $result=$other?CapabilityWriter::apply($locked,$other,$actor):['changed'=>[],'unchanged'=>[]]; $locked->refresh();
            if ($before || $value) CapabilityLedger::record($locked,'transcripts',$before,$value,$previous,$actor);
            $result[$before===$value?'unchanged':'changed'][]='transcripts';
            ClassSubjectMode::forget((int)$school->id); DB::afterCommit(fn()=>MobileCache::flushFamily($locked));
            return $result;
        });
    }
}
