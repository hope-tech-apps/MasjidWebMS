<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\SubjectKey;
use Illuminate\Database\Eloquent\Model;

class ClassSubject extends Model
{
    use BelongsToMasjid;

    public const TOOLS = ['hifdh', 'arabic_letters', 'english_letters'];

    protected $fillable = ['masjid_id', 'group_id', 'name', 'guide_subject', 'tool', 'position', 'hidden_at'];
    protected $hidden = ['name_key', 'previous_name_keys'];

    protected function casts(): array
    {
        return ['previous_name_keys' => 'array', 'position' => 'integer', 'hidden_at' => 'datetime'];
    }

    private bool $attachOrphanedSavedWork = false;
    private array $attachedSavedWork = [];

    /** Explicit office addition or reported setup seed, never an existing subject's rename. */
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

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->name = (string) SubjectKey::clean($row->name);
            $key = SubjectKey::for($row->name);
            $previous = $row->previous_name_keys ?? [];
            if ($key === '' || ! is_array($previous) || ! array_is_list($previous)
                || array_filter($previous, fn ($name) => ! is_string($name) || SubjectKey::for($name) === '') !== []) {
                throw \Illuminate\Validation\ValidationException::withMessages(['name' => ['Choose a subject name and non-empty previous names.']]);
            }
            $row->previous_name_keys = array_values(array_unique(array_map(fn ($name) => SubjectKey::for($name), $previous)));
            if ($row->exists && $row->getOriginal('name_key') !== $key && $row->getOriginal('name_key') !== '') {
                $row->previous_name_keys = array_values(array_unique([
                    ...($row->previous_name_keys ?? []), (string) $row->getOriginal('name_key'),
                ]));
            }
            $row->name_key = $key;
            $row->attachedSavedWork = \App\Support\ClassSubjectSavedWork::check($row, $row->attachOrphanedSavedWork);
        });
    }

    /** Saved work belongs to current/previous names and fixed aliases, never a guide link. */
    public function matchingKeys(): array
    {
        $keys = array_values(array_filter([$this->name_key, ...($this->previous_name_keys ?? [])], fn ($key) => is_string($key) && $key !== ''));
        foreach ($keys as $key) {
            $keys = [...$keys, ...\App\Support\ClassSubjectInitializer::aliases($key)];
        }
        return array_values(array_unique($keys));
    }

    public function curriculumKeys(): array
    {
        return $this->guide_subject === null ? [] : [SubjectKey::for($this->guide_subject)];
    }
}
