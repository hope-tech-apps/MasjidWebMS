<?php

namespace App\Support;

use App\Models\{Form, FormResponse, Masjid};
use App\Jobs\BuildDutyRotas;

/** Form hints use the form the existing request already read. No OFF feature query. */
final class DutyRotaMode
{
    public static function rememberForm(Form $form): void
    {
        if (app()->bound('request')) {
            request()->attributes->set('duty_form_'.$form->id, (bool) $form->getAttribute('duty_rota_enabled'));
            if ($form->getAttribute('has_duty_rota_records')) request()->attributes->set('duty_retained_form_'.$form->id,true);
        }
    }

    public static function responseChanged(FormResponse $response): void
    {
        $hint = app()->bound('request') ? request()->attributes->get('duty_form_'.$response->form_id) : null;
        if ($hint === null) $hint = (bool) Form::withTrashed()->find($response->form_id)?->getAttribute('duty_rota_enabled');
        if (! $hint) return;
        BuildDutyRotas::dispatch((int) $response->masjid_id, (int) $response->form_id)->afterCommit();
    }

    public static function enabled(?Masjid $school): bool
    {
        return $school !== null && ! $school->trashed() && $school->orgType() === 'school'
            && $school->hasCapability('school_calendar') && $school->hasCapability('family_duty_rota');
    }

    /** Only a requested rota switch touches these flags; unused OFF paths have no reference reads. */
    public static function switchChanged(Masjid $school,array $changes): void
    {
        if (! array_key_exists('family_duty_rota',$changes)) return;
        if ($changes['family_duty_rota'] === false && ! $school->hasCapability('family_duty_rota')) return;
        \Illuminate\Support\Facades\DB::table('forms')->where('masjid_id',$school->id)->where('has_duty_rota_records',true)->update(['duty_rota_enabled'=>$changes['family_duty_rota']]);
    }

    /** Retained source schemas change only while school then rota locks exclude builders and source saves. */
    public static function updateForm(Form $form, array $data): void
    {
        if (! isset($data['schema']) || ! $form->getAttribute('has_duty_rota_records')) { $form->update($data); return; }
        \Illuminate\Support\Facades\DB::transaction(function () use($form,$data) {
            Masjid::whereKey($form->masjid_id)->lockForUpdate()->firstOrFail();
            $rotas=\App\Models\DutyRota::withoutMasjidScope()->where('masjid_id',$form->masjid_id)->where('source_form_id',$form->id)->orderBy('id')->lockForUpdate()->get();
            $locked=Form::where('masjid_id',$form->masjid_id)->whereKey($form->id)->lockForUpdate()->firstOrFail();
            $candidate=new Form(['schema'=>$data['schema']]);
            $fields=array_column(DutyRotaService::sourceFields($candidate),'name');
            foreach ($rotas as $rota) if (! in_array($rota->source_field,$fields,true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['schema'=>['The '.$rota->name.' rota reads this question. Change the rota first.']]);
            }
            $locked->update($data);
        },3);
    }

    public static function refuseDeletion($model, string $column): void
    {
        $kind=$column==='school_year_id'?'year':'form';
        if (! $model->getAttribute('has_duty_rota_records') && ! (app()->bound('request') && request()->attributes->get('duty_retained_'.$kind.'_'.$model->id))) return;
        if (\App\Models\DutyRota::withoutMasjidScope()->where('masjid_id',$model->masjid_id)->where($column,$model->id)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['rota'=>['Keep this record because a duty rota uses it. Rota history must be retained.']]);
        }
    }
}
