<?php

namespace App\Services\Schools;

use App\Models\ClassAssignment;
use App\Models\ClassGradeWeight;
use App\Models\Group;
use Illuminate\Support\Facades\DB;

/**
 * Setting or clearing a class's weights: the one write behind `PUT grade-weights`,
 * mounted twice.
 *
 * The teacher realm (Teacher\GradebookController::saveWeights) and the office
 * (AdminDashboard\GroupGradeWeightsController) each decide WHO may call it, and
 * that is the only thing they decide differently: a teacher passes
 * SubjectFence::mayWeighClass, the office passes `permission:manage contacts`. What
 * a set or a clear DOES lives here, so the two cannot drift.
 *
 * The caller has validated the request (SaveGradeWeightsRequest: all five types or
 * none, or a clear) and resolved `$group` through the tenant scope.
 */
class ClassGradeWeightsService
{
    /**
     * Set every type's weight, or clear them all, in one transaction.
     *
     * Clearing also removes every per-work override in the class: an override
     * typed against a weighted class must not lie dormant and come back to life
     * the day weights are turned on again.
     *
     * @param  array<string,int|string>  $weights  `{type: weight}`, ignored when `$clear`
     * @return array{weights: object, weighting_enabled: bool, cleared_overrides: int}  the response's `data`
     */
    public function save(Group $group, bool $clear, array $weights, ?int $userId): array
    {
        $cleared = 0;

        DB::transaction(function () use ($group, $clear, $weights, $userId, &$cleared): void {
            if ($clear) {
                ClassGradeWeight::query()->where('group_id', $group->id)->delete();
                $cleared = ClassAssignment::query()
                    ->where('group_id', $group->id)
                    ->whereNotNull('weight')
                    ->update(['weight' => null]);

                return;
            }

            foreach ($weights as $type => $weight) {
                ClassGradeWeight::query()->updateOrCreate(
                    ['group_id' => $group->id, 'assignment_type' => $type],
                    ['masjid_id' => $group->masjid_id, 'weight' => (int) $weight, 'updated_by_user_id' => $userId],
                );
            }
        });

        $stored = ClassGradeWeight::forGroup((int) $group->id);

        return [
            'weights' => (object) $stored,
            'weighting_enabled' => $stored !== [],
            'cleared_overrides' => $cleared,
        ];
    }
}
