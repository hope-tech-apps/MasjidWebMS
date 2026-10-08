<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/** New feature fields are unknown inputs while OFF, including direct pivot writers. */
trait IgnoresOffClassSubjectFields
{
    private bool $classSubjectWriteOn = false;
    protected function performInsert(Builder $query)
    {
        $this->ignoreOffSubjectFields();
        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        $this->ignoreOffSubjectFields();
        return parent::performUpdate($query);
    }

    private function ignoreOffSubjectFields(): void
    {
        $columns = $this instanceof \App\Models\GroupStaff
            ? ['class_subject_ids', 'class_subjects_mapped_at', 'class_subject_legacy_snapshot', 'class_subjects_translated_from', 'class_subject_ids_edited_at']
            : ['class_subject_id', 'class_subject_link_checked_at'];
        $changed = array_intersect($columns, array_keys($this->getDirty()));
        if ($changed === [] || $this->classSubjectWriteOn) return;
        $attributes = $this->getAttributes(); $original = $this->getRawOriginal();
        foreach ($changed as $column) {
            if (array_key_exists($column, $original)) $attributes[$column] = $original[$column];
            else unset($attributes[$column]);
        }
        $this->setRawAttributes($attributes);
    }
}
