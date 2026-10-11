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

    protected static function booted(): void
    {
        static::deleted(fn (self $record) => \App\Support\StudentAllergies::refreshRetention((int) $record->masjid_id));
        static::saved(function (self $record): void {
            \Illuminate\Support\Facades\DB::table('masjids')->where('id', $record->masjid_id)->where('has_student_allergy_records', false)->update(['has_student_allergy_records' => true]);
            \App\Support\ClassSubjectMode::forget((int) $record->masjid_id);
        });
    }

    public function payload(bool $teacher = false): array
    {
        try {
            $note = \App\Support\StudentAllergies::normalise($this->allergies);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            \Illuminate\Support\Facades\Log::warning('Student allergy note unavailable', ['masjid_id' => (int) $this->masjid_id, 'contact_id' => (int) $this->contact_id, 'student_allergy_id' => (int) $this->id]);
            return ['allergies' => null, 'unavailable' => true];
        }
        return $teacher ? ['allergies' => $note] : [
            'allergies' => $note,
            'source' => $this->source,
            'form_response_id' => $this->form_response_id,
            'changed_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public function officePayload(): array
    {
        return $this->payload();
    }
}
