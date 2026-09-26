<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ContactTag — an organisation's own label on its contacts ("Volunteer",
 * "MEC emaillist 1").
 *
 * A LABEL, not a consent record and not a group. Nothing reads a tag to decide
 * whether somebody may be emailed or texted: a broadcast addressed to a tag
 * still goes through the email opt-out list and the SMS consent record in
 * App\Services\Broadcast\BroadcastAudienceResolver, exactly as "everyone" does.
 * Groups (App\Models\Group) carry roles and guardian consent; a tag carries
 * neither and must not grow them.
 *
 * ## One definition of "the same name"
 *
 * `name_key` is the name lower-cased with whitespace collapsed, and the unique
 * index is on (masjid_id, name_key). It is computed HERE, in `saving`, so every
 * writer — the admin API, the Wix importer, a console session — lands on the
 * same key; `keyFor()` is the one function both the request rule and the
 * importer's lookup call. See the create_contact_tags_table migration for why
 * the column exists at all (production collates utf8mb4_bin).
 *
 * ## Tenant scoping
 *
 * BelongsToMasjid, with its cross-tenant suite in
 * tests/Feature/ContactTagTenantIsolationTest.php. The `contacts()` relation
 * applies Contact's own scopes (tenant and soft-delete), so a count or a filter
 * through it never includes another organisation's people or a deleted one.
 */
class ContactTag extends Model
{
    use BelongsToMasjid;

    /** Longest name the API or an import will store; the column is varchar(100). */
    public const NAME_MAX = 100;

    protected $fillable = [
        'masjid_id',
        'name',
    ];

    /**
     * `name_key` is plumbing for the unique index; `pivot` is the link row
     * Eloquent attaches when a tag is loaded through a contact. Neither is
     * something a screen should read.
     */
    protected $hidden = [
        'name_key',
        'pivot',
    ];

    protected static function booted(): void
    {
        static::saving(function (ContactTag $tag): void {
            $tag->name = self::cleanName($tag->name);
            $tag->name_key = self::keyFor($tag->name);
        });
    }

    /** Trimmed, with runs of whitespace (including a pasted tab or newline) collapsed to one space. */
    public static function cleanName(?string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    }

    /** The comparison key: two names with the same key are the same tag. */
    public static function keyFor(?string $name): string
    {
        return mb_strtolower(self::cleanName($name));
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tag_links')
            ->withPivot('import_batch')
            ->withTimestamps();
    }
}
