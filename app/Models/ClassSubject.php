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

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->name = (string) SubjectKey::clean($row->name);
            $key = SubjectKey::for($row->name);
            if ($row->exists && $row->getOriginal('name_key') !== $key) {
                $row->previous_name_keys = array_values(array_unique([
                    ...($row->previous_name_keys ?? []), (string) $row->getOriginal('name_key'),
                ]));
            }
            $row->name_key = $key;
        });
    }

    /** Snapshot matches survive renames and use the school's own guide spelling. */
    public function matchingKeys(): array
    {
        $keys = [$this->name_key, ...($this->previous_name_keys ?? [])];
        if ($this->guide_subject !== null) $keys[] = SubjectKey::for($this->guide_subject);
        foreach ($keys as $key) {
            $keys = [...$keys, ...\App\Support\ClassSubjectInitializer::aliases($key)];
        }
        return array_values(array_unique($keys));
    }
}
