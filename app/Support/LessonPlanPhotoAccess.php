<?php

namespace App\Support;

use App\Models\Masjid;

final class LessonPlanPhotoAccess
{
    public static function allowed(?Masjid $school, int $teacherId): bool
    {
        $ids = $school?->capability_overrides['lesson_plan_photo_fill_teacher_ids'] ?? [];
        return config('lesson_plan_photo.enabled', false) && $school?->hasCapability('lesson_plan_photo_fill')
            && is_array($ids) && in_array($teacherId, $ids, true);
    }

    public static function imageRules(): array
    {
        $dimension = min(2000, (int) config('lesson_plan_photo.max_dimension', 2000));
        return ['required', 'file', 'image', 'extensions:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
            'max:'.min(3072, (int) config('lesson_plan_photo.max_image_kb', 3072)),
            'dimensions:max_width='.$dimension.',max_height='.$dimension];
    }
}
