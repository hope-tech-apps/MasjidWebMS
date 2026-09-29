<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\GradeLevel;
use App\Support\SubjectKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One subject on the school's own list, edited by the office and offered to a
 * teacher setting work.
 *
 * Work stores the subject as a SNAPSHOT string, never a foreign key to this row,
 * so renaming or deleting a subject changes no mark a parent has read.
 * `grade_labels` NULL means every grade.
 */
class SchoolSubject extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'name',
        'grade_labels',
        'position',
    ];

    /** `name_key` is derived on every save (App\Support\SubjectKey) and never sent. */
    protected $hidden = [
        'name_key',
    ];

    protected static function booted(): void
    {
        static::saving(function (SchoolSubject $subject): void {
            $subject->name = (string) SubjectKey::clean($subject->name);
            $subject->name_key = SubjectKey::for($subject->name);
        });
    }

    protected function casts(): array
    {
        return [
            'grade_labels' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * Whether a child in `$gradeLabel` is taught this. NULL grades mean every
     * grade, and a child with no grade label on the roster is never hidden from a
     * subject: an unknown grade must not lose a subject.
     */
    public function appliesToGrade(?string $gradeLabel): bool
    {
        $labels = $this->grade_labels;

        if ($labels === null || $labels === []) {
            return true;
        }

        return GradeLevel::key($gradeLabel) === null || GradeLevel::in($gradeLabel, $labels);
    }
}
