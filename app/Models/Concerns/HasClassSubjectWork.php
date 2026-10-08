<?php

namespace App\Models\Concerns;

use App\Models\{ClassSubject, Group, GroupStaff, LessonPlan, Masjid};
use App\Support\{ClassSubjectInitializer, ClassSubjectMode, SchoolSettings, SubjectFence, SubjectKey, TenantContext};
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Validation\ValidationException;

/** Atomically checks current ownership and resolves a subject on every Eloquent writer. */
trait HasClassSubjectWork
{
    use IgnoresOffClassSubjectFields;

    public function save(array $options = [])
    {
        $tenant = app(TenantContext::class)->get();
        $orgId = ! $this->exists && $tenant !== null ? $tenant : ($this->masjid_id ?? $tenant);
        if (! ($this->classSubjectWriteOn = ClassSubjectMode::enabled($orgId))) return parent::save($options);
        return DB::transaction(function () use ($orgId, $options) {
            $org = Masjid::withTrashed()->whereKey($orgId)->lockForUpdate()->firstOrFail();
            if (! ($this->classSubjectWriteOn = SchoolSettings::classSubjects($org))) return parent::save($options);
            $group = Group::withTrashed()->where('masjid_id', $orgId)->whereKey($this->group_id)->lockForUpdate()->firstOrFail();
            $user = Auth::user();
            $limits = null;
            if ($user?->type === 'Teacher') {
                // Current PK read: an outer REPEATABLE READ snapshot must not retain revoked IDs.
                $staffId = GroupStaff::where('masjid_id', $orgId)->where('group_id', $group->id)->where('user_id', $user->id)->value('id');
                $staff = GroupStaff::whereKey($staffId)->lockForUpdate()->first();
                $untranslated = $staff === null || ($staff->class_subjects_mapped_at === null && $staff->class_subject_ids_edited_at === null);
                $limits = $untranslated ? ['class_subject_ids' => []] : SubjectFence::limitsForIds(SubjectFence::validStoredIds($staff->class_subject_ids) ? $staff->class_subject_ids : [], $group);
            }
            $dirty = $this->getDirty();
            if ($this->exists) {
                $current = static::where('masjid_id', $orgId)->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
                if ((int) $current->group_id !== (int) $group->id) throw ValidationException::withMessages(['subject' => ['Saved work cannot move to another class.']]);
                $general = $this instanceof LessonPlan && SubjectKey::clean($current->subject) === null;
                abort_unless(SubjectFence::allowsWork($limits, $current->class_subject_id, $general), 404);
                $this->setRawAttributes($current->getAttributes(), true);
                $this->setRawAttributes(array_replace($this->getAttributes(), $dirty));
            }
            if (! $this->exists || $this->isDirty(['subject', 'class_subject_id'])) {
                $name = SubjectKey::clean($this->subject);
                if ($name === null && (! array_key_exists('class_subject_id', $dirty) || $this->class_subject_id === null)) {
                    if (! ($this instanceof LessonPlan) && $limits !== null) SubjectFence::refuse(null);
                    $this->class_subject_id = null;
                    $this->subject = null;
                } else {
                    $chosen = SubjectFence::resolveChoice($group, $name, $this->class_subject_id, array_key_exists('class_subject_id', $dirty), $limits);
                    $this->class_subject_id = $chosen->id;
                    $this->subject = $chosen->name;
                }
                $this->class_subject_link_checked_at = now();
            }
            return parent::save($options);
        });
    }
}
