<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One cell of a school's pacing guide: what a grade covers in one subject in one
 * week, and the standard it maps to.
 *
 * Tenant-scoped reference data. A lesson plan COPIES from this and never points
 * at it — see the migration for why.
 */
class CurriculumWeek extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'grade_label',
        'subject',
        'week_no',
        'quarter',
        'focus',
        'objective',
        'learning_outcome',
        'standard_code',
        'assessment_note',
        'source_label',
    ];

    protected function casts(): array
    {
        return [
            'week_no' => 'integer',
            'quarter' => 'integer',
        ];
    }

    /**
     * What prefill puts on a plan.
     *
     * `standard_description` is absent and must stay absent: the pacing guide
     * carries codes only, and the companion workbook that holds descriptions has
     * not been imported. Inventing one would put words in the state's mouth.
     * (The school's own Qur'an, Arabic and Islamic Studies codes are imported
     * verbatim from its Quarter 1 plan; they are the school's, never invented.)
     *
     * A row that has its own Objective (the school's separated plan) prefills it;
     * every other row prefills its focus as it always did. `learning_outcome` is
     * added only when the row has one, so an old row's payload is byte-identical.
     */
    public function toPrefillArray(): array
    {
        $cell = [
            'standard_code' => $this->standard_code,
            'objective' => $this->objective ?? $this->focus,
            'assessment_formative' => $this->assessment_note,
            'curriculum_week_no' => (int) $this->week_no,
            'subject' => $this->subject,
            'grade_label' => $this->grade_label,
            'prefill_source' => $this->source_label,
        ];

        if ($this->learning_outcome !== null) {
            $cell['learning_outcome'] = $this->learning_outcome;
        }

        return $cell;
    }
}
