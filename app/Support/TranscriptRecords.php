<?php

namespace App\Support;

use App\Models\{Contact, GroupResource, Masjid};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** Academic identity and prior PDFs survive login removal, switch-off and ordinary office cleanup. */
final class TranscriptRecords
{
    private static function everUsed(int $school): bool
    {
        $loaded=ClassSubjectMode::loadedOrganisation($school);
        $loaded ??= Masjid::withTrashed()->find($school);
        // The writer preserves an explicit false after disabling, and creates no entry for unused OFF schools.
        return array_key_exists('transcripts',$loaded?->capability_overrides ?? []);
    }
    public static function assertFileRemoval(GroupResource $file): void
    {
        if (!self::everUsed((int)$file->masjid_id) || !Schema::hasTable('transcript_prior_years')) return;
        if (DB::table('transcript_prior_years')->where('masjid_id',$file->masjid_id)->where('group_resource_id',$file->id)->exists()) throw ValidationException::withMessages(['file'=>['This file is held by a prior-school transcript. Keep it with the academic record.']]);
    }
    /** Called after the existing school mutex and before the source contact can be force-deleted. */
    public static function assertContactMerge(Contact $source): void
    {
        if (!self::everUsed((int)$source->masjid_id)) return;
        foreach (['transcript_finals'=>['contact_id'],'transcript_prior_years'=>['contact_id'],'transcript_issues'=>['contact_id'],'transcript_share_requests'=>['contact_id','consented_by_contact_id','withdrawn_by_contact_id'],'transcript_received'=>['contact_id']] as $table=>$columns) {
            if (!Schema::hasTable($table)) continue;
            if (DB::table($table)->where('masjid_id',$source->masjid_id)->where(function($query) use($columns,$source){foreach($columns as $column)$query->orWhere($column,$source->id);})->exists()) throw ValidationException::withMessages(['target_contact_id'=>['This contact holds transcript records or consent history. Keep both contacts so the academic identity and consent remain intact.']]);
        }
    }
}
