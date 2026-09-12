<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One cached translation of one piece of staff-written text, for one school.
 *
 * The row is (source hash, target language) -> Arabic. It holds no English: see
 * the migration for why a second copy of a teacher's words about a child must
 * not exist outside the tables that already govern them.
 *
 * TENANT-SCOPED, AND THE SCOPE IS THE WHOLE ISOLATION STORY. Nothing about a
 * cache lookup names an organisation — the caller has a hash and a language and
 * asks "have we seen this?" — so if this model lost BelongsToMasjid the query
 * would answer across every school on the platform and no reviewer reading the
 * lookup would see anything wrong with it. That is the failure the trait exists
 * to make impossible (.claude/rules/tenant-scoping.md): a bound tenant filters
 * the read and stamps the write, and neither is re-implemented by hand anywhere
 * in App\Services\Translation. The mandatory cross-tenant Feature test is
 * `tests/Feature/FamilyTranslationTest.php`.
 *
 * `masjid_id` stays fillable so the purge command, which runs UNBOUND, can still
 * work with rows across organisations; a bound tenant always overrides whatever
 * a caller supplies.
 *
 * No soft deletes. A cache row deleted is a cache row gone — there is nothing to
 * restore and nothing to audit, because the source of truth is the English text
 * in the record the parent was reading.
 */
class ContentTranslation extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'source_hash',
        'target_lang',
        'model',
        'translated_text',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The digest a lookup is keyed on.
     *
     * One definition, because the WRITE and the READ must agree byte for byte or
     * the cache silently never hits and every parent pays for a fresh call — a
     * failure that looks exactly like a working feature. sha256 of the raw text,
     * with no normalisation: trimming or case-folding here would make two
     * genuinely different paragraphs share a row, and the caller has already
     * validated what it is sending.
     */
    public static function hashFor(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * Rows that have gone quiet — nothing has been served from them since
     * `$before`.
     *
     * TWO INDEXED ARMS RATHER THAN ONE COALESCE, and the difference is what the
     * nightly sweep costs. `COALESCE(last_used_at, updated_at) < ?` wraps the
     * indexed column in a function call, so MySQL cannot range-scan
     * `content_translations_last_used_at_idx` (the index the migration exists to
     * provide) and full-scans instead — and under InnoDB's default REPEATABLE
     * READ a DELETE takes next-key locks on every row it EXAMINES, not only on
     * the ones it removes. On a school with a term of cached paragraphs that is
     * the whole table locked for the length of the sweep, which blocks
     * AnthropicTranslator::remember()'s write and the hit-stamping UPDATE for any
     * parent translating at 03:15. Written as a plain comparison OR an explicit
     * NULL branch, each arm can use an index (`updated_at` has one for the second
     * arm) and the same rows come back.
     *
     * The NULL arm is kept even though the writer no longer produces one:
     * `remember()` stamps `last_used_at` on create as well as on a hit, so every
     * row this application writes has a value there. Rows written by hand, by a
     * fixture or by a future importer may not, and a sweep that skipped those
     * would keep forever exactly the rows nobody ever came back for — `NULL < ?`
     * is false in both MySQL and SQLite, so without the branch they are
     * unreachable rather than merely unswept.
     */
    public function scopeUnusedSince(Builder $query, string $before): Builder
    {
        return $query->where(function (Builder $q) use ($before): void {
            $q->where('last_used_at', '<', $before)
                ->orWhere(function (Builder $inner) use ($before): void {
                    $inner->whereNull('last_used_at')->where('updated_at', '<', $before);
                });
        });
    }
}
