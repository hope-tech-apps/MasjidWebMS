<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\QuranIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HifzEntry — one recitation heard from ONE student (PLAN T-014).
 *
 * The ḥifẓ tracker rides on the Groups primitive: a ḥalaqa is a Group, and the
 * student is their `group_memberships` row. WHO may read an entry is decided per
 * request by App\Support\GroupAudience — never here, never in a controller — and
 * is applied as a QUERY CONSTRAINT so a forbidden row cannot surface in a
 * listing or inside an aggregate. A CHILD'S RECORD IS PRIVATE, the same rule
 * T-013 established for behaviour points. See .claude/rules/groups.md.
 *
 * ## THE CYCLE
 *
 * `sabak` is the new lesson, `sabqi` recent memorisation under active revision,
 * `manzil` the long rotation over consolidated memorisation. ONLY SABAK ADVANCES
 * THE STUDENT — a manzil entry over al-Baqarah does not mean a child who is
 * currently memorising juz 30 has gone backwards, it means they revised
 * something they already hold.
 *
 * ## POSITION IS DERIVED
 *
 * Nothing stores "where this student is". The latest sabak entry says it, and
 * the union of every sabak range says how much they hold — see
 * App\Support\HifzProgress. A denormalised column would need every writer
 * guarded (the `registrations.registration_count` lesson) and would still be
 * wrong the moment a mis-recorded sabak is struck, because a struck entry has to
 * move the position BACK.
 *
 * CORRECTION IS THE SOFT DELETE. `deleted_at` is the correction clock, so a
 * struck entry leaves every listing, every total and every derivation at once
 * through the ordinary SoftDeletes scope. `corrected_by_user_id` records who
 * made the correction. There is deliberately NO retention window and no purge
 * sweep on this table — a memorisation history is an academic record, and a
 * clock that quietly removed the newest sabak would move a child backwards in
 * the muṣḥaf. Its lifetime is bounded by the roster instead: the DB cascade off
 * `group_memberships` takes it with the student.
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope and the
 * server-derived creating hook. masjid_id stays fillable so system/super code
 * (seeders, importers) can set it while UNBOUND; a bound tenant always overrides
 * it. See .claude/rules/tenant-scoping.md.
 */
class HifzEntry extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    /**
     * The three parts of the daily ḥifẓ cycle.
     *
     * PHP constants, NOT a DB enum — the same reasoning as
     * GroupMembership::ROLES and BehaviorSkill::POLARITIES: adding a band must
     * never mean `ALTER TABLE … MODIFY` on a live table
     * (.claude/rules/migrations.md). Validated at the request boundary with
     * Rule::in(...).
     *
     * These are the CLASSICAL names, kept as-is rather than translated to
     * "new/recent/old". They are what every ḥifẓ teacher this module serves
     * already says, and a tracker that renamed them would be asking a ḥalaqa to
     * learn a vocabulary to describe its own practice. What a UI LABELS them is
     * presentation, exactly as with GroupMembership::ROLES.
     */
    public const KIND_SABAK = 'sabak';
    public const KIND_SABQI = 'sabqi';
    public const KIND_MANZIL = 'manzil';

    public const KINDS = [
        self::KIND_SABAK,
        self::KIND_SABQI,
        self::KIND_MANZIL,
    ];

    /**
     * The kinds that are REVISION of memorisation already held. Their defining
     * property is what they are not: they never advance the student's position.
     */
    public const REVISION_KINDS = [
        self::KIND_SABQI,
        self::KIND_MANZIL,
    ];

    /**
     * How the recitation went.
     *
     * Four bands, not a percentage and not a letter grade: a teacher listening
     * to a child recite is making a judgement of the kind "mumtāz / jayyid /
     * maqbūl / repeat it", and forcing that into a number would invent a
     * precision nobody measured. `repeat` is the one that is not a pass — the
     * portion is to be heard again — and it is deliberately a QUALITY rather
     * than a separate boolean, so there is exactly one field to read when asking
     * how a recitation went.
     */
    public const QUALITY_EXCELLENT = 'excellent';
    public const QUALITY_GOOD = 'good';
    public const QUALITY_FAIR = 'fair';
    public const QUALITY_REPEAT = 'repeat';

    public const QUALITIES = [
        self::QUALITY_EXCELLENT,
        self::QUALITY_GOOD,
        self::QUALITY_FAIR,
        self::QUALITY_REPEAT,
    ];

    protected $fillable = [
        'masjid_id',
        'group_id',
        'group_membership_id',
        'heard_by_user_id',
        'kind',
        'from_surah',
        'from_ayah',
        'to_surah',
        'to_ayah',
        'quality',
        'major_mistakes',
        'minor_mistakes',
        'note',
        'recited_at',
        'corrected_by_user_id',
    ];

    protected $attributes = [
        'major_mistakes' => 0,
        'minor_mistakes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'from_surah' => 'integer',
            'from_ayah' => 'integer',
            'to_surah' => 'integer',
            'to_ayah' => 'integer',
            'major_mistakes' => 'integer',
            'minor_mistakes' => 'integer',
            'recited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            // An entry records WHEN the recitation was heard, which is not
            // always when it was typed in. Defaulted in the model rather than
            // the controller so every caller — importers, seeders — gets a real
            // timestamp instead of a null that the position derivation would
            // have to sort around.
            if ($entry->recited_at === null) {
                $entry->recited_at = now();
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * THE STUDENT: their own participant membership in the ḥalaqa.
     *
     * Named `membership` to match BehaviorAward, because App\Support\GroupAudience
     * constrains BOTH through the same relation name — one implementation of
     * "records about a student this caller may read", so the two surfaces cannot
     * drift apart.
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }

    /** The teacher who heard it; null once that account is erased for good. */
    public function heardBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'heard_by_user_id');
    }

    /** The admin account that struck the entry; null on a live record. */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_user_id');
    }

    /**
     * The stored kind, degraded to the SAFEST reading when unrecognized.
     *
     * `manzil` rather than `sabak`, and the direction is deliberate: an
     * unreadable value must never be counted as new memorisation, because that
     * would advance a child's recorded position on the strength of a corrupt
     * string. Under-reporting progress is recoverable; claiming a student has
     * memorised something they have not is not.
     */
    public function kind(): string
    {
        $kind = $this->attributes['kind'] ?? null;

        return in_array($kind, self::KINDS, true) ? $kind : self::KIND_MANZIL;
    }

    /**
     * The stored quality, degraded to `fair` when unrecognized.
     *
     * The middle of the passing bands on purpose: degrading UP would hand a
     * child a distinction nobody awarded, and degrading to `repeat` would record
     * a failure no teacher gave. `fair` is the reading that makes the smallest
     * claim about the student in either direction.
     */
    public function quality(): string
    {
        $quality = $this->attributes['quality'] ?? null;

        return in_array($quality, self::QUALITIES, true) ? $quality : self::QUALITY_FAIR;
    }

    /** Is this the NEW lesson — the only kind that moves the student forward? */
    public function isSabak(): bool
    {
        return $this->kind() === self::KIND_SABAK;
    }

    public function isCorrected(): bool
    {
        return $this->trashed();
    }

    /**
     * This entry's range as an absolute [start, end] ayah span, or null when the
     * stored coordinates do not describe real ayahs.
     *
     * Null rather than a guess: a corrupt row must drop out of the arithmetic
     * entirely instead of contributing a nonsense span to a child's coverage.
     *
     * @return array{0:int,1:int}|null
     */
    public function span(): ?array
    {
        $start = QuranIndex::ordinal((int) $this->from_surah, (int) $this->from_ayah);
        $end = QuranIndex::ordinal((int) $this->to_surah, (int) $this->to_ayah);

        if ($start === null || $end === null || $start > $end) {
            return null;
        }

        return [$start, $end];
    }

    /** How many ayahs this entry covers; 0 for an unreadable range. */
    public function ayahCount(): int
    {
        $span = $this->span();

        return $span === null ? 0 : $span[1] - $span[0] + 1;
    }

    /** Entries of one kind. Unknown kinds match nothing rather than everything. */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return in_array($kind, self::KINDS, true)
            ? $query->where('kind', $kind)
            : $query->whereRaw('1 = 0');
    }

    /** The new-lesson entries — the only ones a position is derived from. */
    public function scopeSabak(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_SABAK);
    }

    /**
     * Narrow a listing to a closed date range over `recited_at` — the column
     * that says when the recitation HAPPENED, never created_at. Either end may
     * be omitted; whereDate keeps a bare `2026-03-01` inclusive of that whole
     * day instead of cutting it off at midnight.
     */
    public function scopeRecitedBetween(Builder $query, $from = null, $to = null): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('recited_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('recited_at', '<=', $to));
    }
}
