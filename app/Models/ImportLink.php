<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/**
 * ImportLink — which Manara row an external record became, in one organisation.
 *
 * Written only by the staged importers under App\Services\Imports. The reasons
 * each column exists (a re-run that updates rather than duplicates, an undo
 * that removes exactly what a run created, a re-run that keeps an office edit)
 * are on the create_import_links_table migration.
 *
 * `local_id` names a row in the table `kind` implies (see the KIND_ constants)
 * and is deliberately not a foreign key: a link whose row has since been
 * deleted is how a re-run knows not to recreate somebody the office removed.
 *
 * BelongsToMasjid, with its cross-tenant suite in
 * tests/Feature/ImportLinkTenantIsolationTest.php. The importers bind the
 * organisation before they read or write a single link, as every console
 * command must (.claude/rules/tenant-scoping.md).
 */
class ImportLink extends Model
{
    use BelongsToMasjid;

    public const SOURCE_WIX = 'wix';

    /** local_id is a contacts.id */
    public const KIND_CONTACT = 'contact';

    /** local_id is a contact_tags.id */
    public const KIND_TAG = 'tag';

    /** local_id is a contact_us_accounts.id */
    public const KIND_CONTACT_US_ACCOUNT = 'contact_us_account';

    /** local_id is a contact_us_messages.id */
    public const KIND_CONTACT_US_MESSAGE = 'contact_us_message';

    protected $fillable = [
        'masjid_id',
        'source',
        'kind',
        'external_id',
        'local_id',
        'created_local',
        'fingerprint',
        'import_batch',
    ];

    protected function casts(): array
    {
        return [
            'local_id' => 'integer',
            'created_local' => 'boolean',
        ];
    }
}
