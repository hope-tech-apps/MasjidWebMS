<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Private school health record. Never load as a Contact relation or append. */
class StudentAllergy extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'contact_id', 'allergies', 'source', 'form_response_id', 'changed_by_user_id'];

    // A database error's SQL bindings must never contain a child's health note.
    protected $casts = ['allergies' => 'encrypted'];

    public function officePayload(): array
    {
        return [
            'allergies' => $this->allergies,
            'source' => $this->source,
            'form_response_id' => $this->form_response_id,
            'changed_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
