<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    /**
     * local_id is an email_suppressions.id the run INSERTED (external_id is the
     * same id as a string, so no address is copied into this table). Only a row
     * that did not exist before the run is linked: that is what lets an undo
     * remove the import's own precautions and never a row somebody else wrote.
     */
    public const KIND_EMAIL_SUPPRESSION = 'email_suppression';

    /** local_id is an sms_suppressions.id the run INSERTED; as KIND_EMAIL_SUPPRESSION. */
    public const KIND_SMS_SUPPRESSION = 'sms_suppression';

    /**
     * local_id is a contacts.id an EARLIER run created and this run updated from
     * a fresher pull (external_id is "{contact id}@{batch}"). The values it
     * replaced are not kept, so this row is what makes the undo of the updating
     * run refuse rather than pretend to be exact.
     */
    public const KIND_CONTACT_UPDATE = 'contact_update';

    /**
     * Has this batch name already been used in the organisation, by any staged
     * importer? A reused name would let one `--undo` remove two runs, so the
     * commands refuse it before writing. Checks every place a batch name is
     * written: these links, `contacts.import_batch` (the Wix and roster
     * importers) and `contact_tag_links.import_batch`, which carries no
     * masjid_id and is reached through its contact.
     */
    public static function batchUsed(int $masjidId, string $batch): bool
    {
        return self::withoutMasjidScope()->where('masjid_id', $masjidId)->where('import_batch', $batch)->exists()
            || Contact::withoutMasjidScope()->withTrashed()->where('masjid_id', $masjidId)->where('import_batch', $batch)->exists()
            || DB::table('contact_tag_links')
                ->join('contacts', 'contacts.id', '=', 'contact_tag_links.contact_id')
                ->where('contacts.masjid_id', $masjidId)
                ->where('contact_tag_links.import_batch', $batch)
                ->exists();
    }

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
