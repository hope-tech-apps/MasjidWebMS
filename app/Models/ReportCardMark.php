<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\PerformanceLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a report card.
 *
 * `subject` and `criterion` are stored TEXT copied from
 * App\Support\ReportCardTemplate, never a reference into it — see the migration
 * for why a document a family keeps must not re-render against a template that
 * has since been reworded.
 */
class ReportCardMark extends Model
{
    use HasFactory, BelongsToMasjid;

    /** A subject criterion — Reading Fluency, Tajweed, Problem Solving. */
    public const KIND_ACADEMIC = 'academic';

    /**
     * A learning behaviour — "works well with others".
     *
     * Kept apart from the academic marks everywhere, including in every average.
     * Folding effort into achievement is how a quiet child ends up with a lower
     * mark in Mathematics than their maths deserves.
     */
    public const KIND_BEHAVIOUR = 'behaviour';

    public const KINDS = [
        self::KIND_ACADEMIC,
        self::KIND_BEHAVIOUR,
    ];

    protected $fillable = [
        'masjid_id',
        'report_card_id',
        'kind',
        'subject',
        'criterion',
        'level',
        'comment',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'position' => 'integer',
        ];
    }

    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(ReportCard::class);
    }

    /** Was a judgement actually made here? NULL is "not assessed", never zero. */
    public function isAssessed(): bool
    {
        return $this->level !== null;
    }

    public function levelLabel(): ?string
    {
        return $this->level === null ? null : PerformanceLevel::label($this->level);
    }
}
