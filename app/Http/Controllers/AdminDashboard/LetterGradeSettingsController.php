<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{Masjid, SchoolTerm};
use App\Support\{GradeLevel, LetterGrades, SchoolSettings};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

/** Office school policy, available only after the default-off rollout grant is given. */
class LetterGradeSettingsController extends Controller
{
    public function show(Request $request, $masjid_id)
    {
        $school = Masjid::findOrFail($masjid_id);
        $this->assertAvailable($school);
        return response()->json(['status'=>'success','data'=>$this->payload($school)]);
    }

    public function update(Request $request, $masjid_id)
    {
        $request->merge(['enabled'=>filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        $data = $request->validate(['enabled'=>['required','boolean'], 'from_grade'=>['required','string', Rule::in(GradeLevel::LEVELS)], 'scale'=>['required','array','max:40']]);
        $data['enabled'] = (bool) $data['enabled'];
        LetterGrades::validateScale($data['scale']);
        return DB::transaction(function () use ($masjid_id, $data) {
            $school = Masjid::whereKey($masjid_id)->lockForUpdate()->firstOrFail();
            $this->assertAvailable($school);
            if ($data['enabled'] && ! $this->ready($school)) throw ValidationException::withMessages(['enabled'=>['Add dated terms in the school calendar and switch on class subjects before enabling letters.']]);
            $school->forceFill(['letter_grade_settings'=>$data])->save();
            return response()->json(['status'=>'success','data'=>$this->payload($school)]);
        });
    }

    private function assertAvailable(Masjid $school): void
    {
        abort_unless(LetterGrades::offered($school), 404);
    }

    private function ready(Masjid $school): bool
    {
        return SchoolSettings::classSubjects($school) && SchoolSettings::calendarTerms($school)
            && SchoolTerm::where('masjid_id', $school->id)->whereNotNull('starts_on')->whereNotNull('ends_on')->exists();
    }

    private function payload(Masjid $school): array
    {
        return ['enabled'=>($school->letter_grade_settings['enabled'] ?? false) === true,
            'from_grade'=>$school->letter_grade_settings['from_grade'] ?? '3rd',
            'scale'=>$school->letter_grade_settings['scale'] ?? LetterGrades::DEFAULT_SCALE,
            'grades'=>GradeLevel::LEVELS, 'can_enable'=>$this->ready($school),
            'setup_message'=>$this->ready($school) ? null : 'Add dated terms in the school calendar and switch on class subjects before enabling letters.'];
    }
}
