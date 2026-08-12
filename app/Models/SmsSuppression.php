<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Services\Sms\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SmsSuppression — a number that must not be texted, held OUTSIDE the contact
 * row so it cannot be defeated by deleting one (T-009).
 *
 * The whole point is the absent foreign key. `sms_suppressions` references no
 * contact: it is keyed on `phone_e164`, the one identifier that survives
 * `ContactsController::merge`'s `forceDelete()`, a CSV re-import, and somebody
 * re-adding a person by hand next month. Consent lives on `contacts` and is
 * therefore mortal; the opt-out lives here and is not.
 *
 * ## Rows are released, never deleted
 *
 * A subscriber who texts START back gets `released_at` stamped and the row kept
 * (App\Services\Sms\SmsConsentService::release). Deleting it would destroy the
 * evidence that the STOP was honoured, which is the exact record an operator
 * needs when a complaint arrives, and would let a later re-STOP write a second
 * contradictory row instead of updating this one.
 *
 * ## Tenant scoping
 *
 * BelongsToMasjid, per .claude/rules/tenant-scoping.md, and a new model so it
 * gets the global scope rather than hand-filtering. Two consequences worth
 * stating:
 *
 *  - The inbound webhook runs UNBOUND (routes/api.php, like the Stripe webhook),
 *    where the scope adds no constraint — so it can resolve the tenant from the
 *    number the message was sent TO and then write an explicit masjid_id. The
 *    creating hook only overrides masjid_id when a tenant IS bound, so nothing
 *    is silently rewritten.
 *  - During a send the tenant IS bound, so the audience resolver sees exactly
 *    this masjid's suppressions and never another's. Pinned by
 *    SmsTenantIsolationTest.
 *
 * Suppression is per tenant on purpose: STOP is a reply to ONE registered
 * number, each masjid has its own (masjid_sms_senders), and agreeing to hear
 * from your masjid was never agreement to hear from the school across town.
 */
class SmsSuppression extends Model
{
    use HasFactory, BelongsToMasjid;

    /** The subscriber texted a stop keyword to our number. */
    public const REASON_STOP_KEYWORD = 'stop_keyword';

    /** An admin recorded a withdrawal made some other way (in person, by phone). */
    public const REASON_MANUAL = 'manual';

    /** The provider or carrier reported the number as undeliverable/blocked. */
    public const REASON_PROVIDER = 'provider';

    /**
     * Keywords that suppress, per CTIA guidance and the set every US carrier
     * honours. Matched case-insensitively on the whole trimmed body — "stop
     * texting me" is not in this list because the carriers do not treat it as
     * the control keyword either, and a substring match would suppress on
     * "don't stop believing".
     */
    public const STOP_KEYWORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];

    /** Keywords that release a suppression. */
    public const START_KEYWORDS = ['START', 'UNSTOP', 'YES'];

    /** Keywords that ask for help. Answered provider-side; we record nothing. */
    public const HELP_KEYWORDS = ['HELP', 'INFO'];

    protected $fillable = [
        'masjid_id',
        'phone_e164',
        'reason',
        'keyword',
        'provider_message_id',
        'suppressed_at',
        'released_at',
        'released_keyword',
    ];

    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** Suppressions currently in force — a released row no longer blocks. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /** Normalise before matching: the key is E.164 or it is nothing. */
    public function scopeForNumber(Builder $query, string $raw): Builder
    {
        return $query->where('phone_e164', PhoneNumber::e164($raw) ?? '');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }
}
