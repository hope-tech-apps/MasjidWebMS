<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Services\Sms\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contact — a congregant record. First consumer of the CRM tenant-isolation
 * guardrail (Phase 0 of the donation/CRM build).
 *
 * The BelongsToMasjid trait supplies the masjid_id global scope, the
 * server-derived creating hook, and the masjid() relationship. masjid_id stays
 * in $fillable so system/super code can set it while UNBOUND; when a tenant is
 * bound the creating hook overrides it regardless. See BelongsToMasjid.
 *
 * ## SMS consent (T-009) is a RECORD on this row, and it is mortal
 *
 * `phone` alone has never been permission to send anything. The four consent
 * columns say whether, when, how and on what evidence this person agreed to
 * receive bulk text messages, and `hasSmsConsent()` requires ALL of them — a
 * flag set by a careless import with no timestamp and no source reads as NO
 * consent rather than as permission.
 *
 * The OPT-OUT here (`sms_opted_out_at`) is only a mirror. The authority is
 * `sms_suppressions`, keyed on the number rather than on this row, because this
 * row can be merged away, force-deleted or re-imported and an opt-out must
 * survive all three. See App\Models\SmsSuppression.
 */
class Contact extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    /**
     * HOW consent was obtained. A constant set rather than free text: free text
     * produces forty spellings of "website" and cannot answer "show me everyone
     * whose consent came from the admissions form" three years later, which is
     * the question a TCPA demand letter actually asks. The specific artifact
     * goes in `sms_consent_evidence` beside it.
     *
     * Stored as a plain string column — adding a source must never mean
     * `ALTER TABLE … MODIFY` on a live table (.claude/rules/migrations.md).
     *
     * `sms_reply_start` is the only value this application writes on its own:
     * it is recorded when the subscriber texts START to the organisation's own
     * registered number, which is express written consent given in the
     * subscriber's own hand.
     *
     * @var array<int, string>
     */
    public const SMS_CONSENT_SOURCES = [
        'web_form',
        'paper_form',
        'in_person',
        'phone_call',
        'sms_reply_start',
        'imported_with_proof',
    ];

    protected $fillable = [
        'masjid_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'notes',
        'is_placeholder',
        'import_batch',
        // SMS consent (T-009). Fillable so an importer carrying real proof can
        // seed it; every write path that an ADMIN can reach goes through
        // App\Services\Sms\SmsConsentService, which stamps the timestamp
        // server-side and refuses to consent a suppressed number.
        'sms_opt_in',
        'sms_consent_at',
        'sms_consent_source',
        'sms_consent_evidence',
        'sms_opted_out_at',
    ];

    protected function casts(): array
    {
        return [
            'is_placeholder' => 'boolean',
            'sms_opt_in' => 'boolean',
            'sms_consent_at' => 'datetime',
            'sms_opted_out_at' => 'datetime',
        ];
    }

    /**
     * Has this person agreed, provably, to receive bulk text messages?
     *
     * Every clause is load-bearing. The flag is the affirmative; the timestamp
     * and source are what make it defensible; and the opt-out overrides all of
     * them. A row missing any one of the first three is NOT consent — that is
     * the difference between a record and a checkbox.
     *
     * This is necessary but not sufficient: the audience resolver additionally
     * checks the number against `sms_suppressions`, which outlives this row.
     */
    public function hasSmsConsent(): bool
    {
        return $this->sms_opt_in === true
            && $this->sms_consent_at !== null
            && filled($this->sms_consent_source)
            && $this->sms_opted_out_at === null;
    }

    /** This contact's number in E.164, or null when it cannot be resolved. */
    public function smsNumber(): ?string
    {
        return PhoneNumber::e164($this->phone);
    }

    /**
     * A hard-deleted contact must take its credential DOCUMENTS with it.
     *
     * Soft deletes (the normal destroy path) keep credential rows and bytes on
     * purpose — a mis-click must not destroy a provider's credential history.
     * But the merge flow force-deletes absorbed placeholder contacts, and on a
     * hard delete the DB cascade would remove contact_credentials rows without
     * firing a single model event, orphaning the scans on the private disk
     * forever (.claude/rules/private-uploads.md). Deleting each credential
     * through the model here lets its own `deleting` hook reach the disk first.
     *
     * Note what is deliberately NOT cleaned up here: this contact's SMS
     * suppression. `sms_suppressions` has no foreign key to `contacts` and is
     * keyed on the number, precisely so that force-deleting the row cannot
     * un-say a STOP.
     */
    protected static function booted(): void
    {
        static::deleting(function (Contact $contact) {
            if (! $contact->isForceDeleting()) {
                return;
            }

            $contact->credentials()->get()->each->delete();
        });
    }

    /** Card last-4 records for this contact (historical lookup + placeholder merge). */
    public function cards()
    {
        return $this->hasMany(ContactCard::class);
    }

    /** Succeeded giving attributed to this contact (Stripe + offline alike). */
    public function donations()
    {
        return $this->hasMany(Donation::class);
    }

    /**
     * This person's places in groups — their own leader/member rows AND the
     * guardian edges they hold over someone else. Additive: groups reference a
     * contact, they never duplicate one. See .claude/rules/groups.md.
     */
    public function groupMemberships()
    {
        return $this->hasMany(GroupMembership::class);
    }

    /** The groups this contact appears in, in any role. */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_memberships')
            ->withPivot(['role', 'guardian_of_contact_id', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * This person's credentials — a Community org's volunteer licenses,
     * background checks and certifications (T-023). Additive: credentials
     * reference a contact, they never duplicate one. See
     * .claude/rules/credentials.md.
     */
    public function credentials()
    {
        return $this->hasMany(ContactCredential::class);
    }
}
