<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\SubjectKey;
use Illuminate\Database\Eloquent\Model;

class ClassSubject extends Model
{
    use BelongsToMasjid;

    public const TOOLS = ['hifdh', 'arabic_letters', 'english_letters'];

    protected $fillable = ['masjid_id', 'group_id', 'name', 'guide_subject', 'guide_subjects', 'tool', 'position', 'hidden_at'];
    protected $hidden = ['name_key', 'previous_name_keys', 'guide_subjects'];

    protected function casts(): array
    {
        return ['guide_subjects' => 'array', 'previous_name_keys' => 'array', 'position' => 'integer', 'hidden_at' => 'datetime'];
    }

    /** Ordered office choices; NULL preserves the initializer's single-match fallback. */
    public function followedGuideSubjects(): array
    {
        return $this->guide_subjects ?? ($this->guide_subject === null ? [] : [$this->guide_subject]);
    }

    private bool $attachOrphanedSavedWork = false;
    private array $attachedSavedWork = [];

    /** Explicit office addition, never an automatic seed or rename attachment. */
    public function saveAttachingOrphanedWork(): bool
    {
        $this->attachOrphanedSavedWork = true;
        try {
            return $this->save();
        } finally {
            $this->attachOrphanedSavedWork = false;
        }
    }

    public function attachedSavedWork(): array
    {
        return $this->attachedSavedWork;
    }

    public function save(array $options = [])
    {
        $group = \App\Models\Group::withTrashed()->findOrFail($this->group_id);
        $orgId = ! $this->exists ? (app(\App\Support\TenantContext::class)->get() ?? $this->masjid_id ?? $group->masjid_id) : $this->masjid_id;
        return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $orgId, $group) {
            \App\Models\Masjid::withTrashed()->whereKey($orgId)->lockForUpdate()->firstOrFail();
            \App\Models\Group::withTrashed()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ((int) $orgId !== (int) $group->masjid_id) throw \Illuminate\Validation\ValidationException::withMessages(['name' => ['Choose a class in this school.']]);
            if ($this->exists) {
                $current = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
                if ((int) $current->group_id !== (int) $this->group_id || (int) $current->masjid_id !== (int) $orgId) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['name' => ['A class subject cannot be moved to another class or school.']]);
                }
                $dirty = $this->getDirty();
                $this->setRawAttributes($current->getAttributes(), true);
                $this->setRawAttributes(array_replace($this->getAttributes(), $dirty));
            }
            // Keep all legacy readers on the first choice. Automatic seeds leave the list NULL.
            if ($this->isDirty('guide_subjects') && $this->guide_subjects !== null) {
                $this->guide_subject = $this->guide_subjects[0] ?? null;
            } elseif ($this->exists && $this->isDirty('guide_subject') && $this->guide_subjects !== null) {
                $this->guide_subjects = $this->guide_subject === null ? [] : [$this->guide_subject];
            }
            $this->masjid_id = $orgId;
            $this->name = (string) SubjectKey::clean($this->name);
            $this->name_key = SubjectKey::for($this->name);
            if ($this->name_key === '') throw \Illuminate\Validation\ValidationException::withMessages(['name' => ['Choose a subject name.']]);
            $others = self::where('masjid_id', $orgId)->where('group_id', $group->id)->when($this->exists, fn ($q) => $q->where('id', '<>', $this->id))->get();
            if ($others->contains(fn ($s) => array_intersect($s->matchingKeys(), $this->matchingKeys()) !== [])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['name' => ['This class already has a subject using that name.']]);
            }
            if ($this->tool !== null && $others->contains('tool', $this->tool)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['tool' => ['Another subject in this class already holds that tool.']]);
            }
            $saved = parent::save($options);
            if ($saved && $this->attachOrphanedSavedWork) $this->attachedSavedWork = \App\Support\ClassSubjectSavedWork::attach($this);
            return $saved;
        });
    }

    /** Current name and the two fixed aliases are selection/activation keys only. */
    public function matchingKeys(): array
    {
        return \App\Support\ClassSubjectInitializer::aliases((string) $this->name_key);
    }

    public function curriculumKeys(): array
    {
        return $this->guide_subject === null ? [] : [SubjectKey::for($this->guide_subject)];
    }
}
