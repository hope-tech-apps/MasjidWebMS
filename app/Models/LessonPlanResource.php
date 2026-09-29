<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This lesson plan uses that file", in an order.
 *
 * A join row and nothing else: it holds no bytes and decides no access. The file
 * is a `GroupResource` (the class's Files, private disk, staff-only by default)
 * and every download still goes through the Files download route, which
 * re-resolves masjid -> group -> resource. A plan's attachments are served to
 * STAFF ONLY (the teacher's and the office's plan payloads); no family payload
 * carries them, and a file reaches families only if the teacher separately
 * shares it from Files.
 *
 * The "same class, same school" rule is enforced where a link is written
 * (`Teacher\LessonPlanController::resolveAttachments`), not here: this row has no
 * `group_id` of its own to check against.
 */
class LessonPlanResource extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'lesson_plan_id',
        'group_resource_id',
        'position',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function lessonPlan(): BelongsTo
    {
        return $this->belongsTo(LessonPlan::class);
    }

    public function groupResource(): BelongsTo
    {
        return $this->belongsTo(GroupResource::class);
    }
}
