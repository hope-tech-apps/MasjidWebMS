<?php

namespace App\Services\Broadcast;

use App\Enums\BroadcastAudience;
use Illuminate\Support\Facades\DB;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Sms\SmsConsentService;
use Illuminate\Support\Collection;

/**
 * Turns a broadcast's audience selection into actual recipients (T-008).
 *
 * ## Two audiences, two completely different sources
 *
 * This class exists mainly to keep an honest asymmetry visible:
 *
 *   - EMAIL resolves against `contacts` — real people, with names and addresses,
 *     that an admin curates. Both `everyone` and an explicit contact set are
 *     meaningful here.
 *   - PUSH resolves against `mobile_app_users` — DEVICES. That table has
 *     `device_id` and `onesignal_subscription_id` and no contact_id whatsoever,
 *     so there is no join from a person to their phone. Push is therefore
 *     all-devices-or-nothing, and the request boundary REJECTS a broadcast that
 *     pairs a contact-narrowed audience with the push channel rather than
 *     quietly widening it. An admin who thinks they messaged four families and
 *     actually messaged four thousand devices has been lied to by the software.
 *
 * ## Tenant scoping
 *
 * `Contact` carries BelongsToMasjid, so the queries below are scoped by the
 * bound TenantContext — which BroadcastDispatcher guarantees is bound to this
 * broadcast's masjid before any driver runs. Nothing here hand-filters by
 * masjid_id (.claude/rules/tenant-scoping.md), and the explicit contact ids from
 * the request are resolved THROUGH that scope, so ids belonging to another
 * masjid simply do not come back.
 */
class BroadcastAudienceResolver
{
    public function __construct(private readonly SmsConsentService $consent)
    {
    }

    /**
     * Email recipients for this broadcast.
     *
     * Placeholder contacts are excluded: they are stubs the donation importer
     * mints for an unmatched card, not people who agreed to hear from anyone.
     * Contacts without an email are excluded rather than counted — a target
     * count must mean "inboxes addressed".
     *
     * @return Collection<int, Contact>
     */
    public function emailRecipients(Broadcast $broadcast): Collection
    {
        $query = Contact::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(function ($q) {
                // is_placeholder is nullable on older rows; NULL means "a real
                // person" there, so it must not be filtered out.
                $q->where('is_placeholder', false)->orWhereNull('is_placeholder');
            });

        if ($broadcast->audienceType() === BroadcastAudience::CONTACTS) {
            $ids = array_values(array_filter(array_map('intval', (array) $broadcast->audience_contact_ids)));

            // An empty explicit set addresses nobody. Returning every contact
            // here would be the single most damaging default in this file.
            if ($ids === []) {
                return collect();
            }

            $query->whereIn('id', $ids);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * SMS recipients for this broadcast — filtered on CONSENT, never on
     * "has a phone number" (T-009).
     *
     * This method is the reason T-008 refused to ship an SMS channel. `contacts`
     * carried a `phone` column and nothing else, and a resolver that read
     * `whereNotNull('phone')` would have texted every number an admissions form
     * ever collected. A phone number is a fact about a person; consent is a
     * decision they made. Only the second one authorises a bulk message.
     *
     * Four filters, in order of authority:
     *
     *  1. `Contact::hasSmsConsent()`'s columns, in SQL: the opt-in flag AND a
     *     consent timestamp AND a source AND no opt-out. Every clause matters —
     *     a flag with no provenance is not a defensible record, so it does not
     *     count as consent here either.
     *  2. A number that normalises to E.164 (App\Services\Sms\PhoneNumber). One
     *     that does not is REFUSED rather than guessed at, because a wrong
     *     normalisation produces a plausible number that matches no suppression
     *     row and reaches a stranger.
     *  3. The suppression list, which outlives the contact row and is therefore
     *     the final say. A contact re-imported with a fresh consent record is
     *     still suppressed if that NUMBER ever said STOP.
     *  4. Placeholders, excluded exactly as the email resolver excludes them:
     *     stubs the donation importer mints for an unmatched card are not people
     *     who agreed to hear from anyone.
     *
     * Everyone excluded is COUNTED (SmsAudience), because "sent to 40 of 800" is
     * information the admin needs and a silent 40 looks like a bug.
     *
     * Tenant scoping is the bound TenantContext's, exactly as for email — the
     * dispatcher binds it before any driver runs, so both the contacts and the
     * suppressions seen here belong to this masjid alone.
     */
    public function smsRecipients(Broadcast $broadcast): SmsAudience
    {
        $query = Contact::query()
            ->where(function ($q) {
                $q->where('is_placeholder', false)->orWhereNull('is_placeholder');
            });

        if ($broadcast->audienceType() === BroadcastAudience::CONTACTS) {
            $ids = array_values(array_filter(array_map('intval', (array) $broadcast->audience_contact_ids)));

            // An empty explicit set addresses nobody — the same guard the email
            // resolver makes, for the same reason.
            if ($ids === []) {
                return new SmsAudience(recipients: []);
            }

            $query->whereIn('id', $ids);
        }

        $candidates = $query->orderBy('id')->get();

        $consenting = [];
        $withoutConsent = 0;
        $unusableNumber = 0;
        $withoutPhone = 0;

        foreach ($candidates as $contact) {
            if (blank($contact->phone)) {
                $withoutPhone++;

                continue;
            }

            if (! $contact->hasSmsConsent()) {
                $withoutConsent++;

                continue;
            }

            $number = $contact->smsNumber();

            if ($number === null) {
                $unusableNumber++;

                continue;
            }

            $consenting[] = ['contact' => $contact, 'number' => $number];
        }

        // One query for the whole audience rather than one per recipient.
        $suppressed = $this->consent->suppressedAmong(
            (int) $broadcast->masjid_id,
            array_map(fn (array $row) => $row['number'], $consenting),
        );

        $recipients = array_values(array_filter(
            $consenting,
            fn (array $row) => ! in_array($row['number'], $suppressed, true),
        ));

        return new SmsAudience(
            recipients: $recipients,
            withoutConsent: $withoutConsent,
            suppressed: count($consenting) - count($recipients),
            unusableNumber: $unusableNumber,
            withoutPhone: $withoutPhone,
        );
    }

    /**
     * OneSignal subscription ids for this masjid's registered devices.
     *
     * Copied in shape from AdminDashboard\NotificationsController::save, which
     * is still the authority: subscription IDs, never external_id aliases —
     * aliases do not resolve in OneSignal's notification API. Only devices that
     * reported a subscription id via the heartbeat are targetable.
     *
     * @return array<int, string>
     */
    public function pushSubscriptionIds(Masjid $masjid, ?Broadcast $broadcast = null): array
    {
        $query = $masjid->mobileAppUsers()->whereNotNull('onesignal_subscription_id');

        if ($broadcast?->audienceType() === BroadcastAudience::SERVICE) {
            $this->narrowToServiceInterest($query, $masjid, (int) $broadcast->audience_service_id);
        }

        return $query->pluck('onesignal_subscription_id')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Narrow a device query to handsets belonging to members who asked to hear
     * about one service.
     *
     * Resolved HERE, at send time, rather than from a list snapshotted when the
     * broadcast was composed — an interest is an opt-in, and a member who
     * withdrew theirs in the meantime must not be reached. Same principle as
     * `smsRecipients()` filtering on the consent record instead of on
     * `phone IS NOT NULL`.
     *
     * Four conditions, each of which is a way to be wrong:
     *
     *  - the device must be CLAIMED (`contact_id` not null). A guest handset has
     *    no person behind it and no interests; it hears `everyone` and nothing
     *    narrower.
     *  - the interest row is matched with an explicit `masjid_id`. It is written
     *    as a raw subquery, which does NOT carry the BelongsToMasjid global
     *    scope, so the tenant filter has to be stated rather than assumed.
     *  - the contact must not be soft-deleted. `contact_id` is `nullOnDelete`,
     *    but a SOFT delete leaves the column pointing at a hidden row, so the
     *    FK alone would keep pushing to a deleted person's phone.
     *  - the contact must not be revoked. Staff revoking somebody revoked the
     *    person, not one channel — the same rule `memberAccessIsActive()` and
     *    `MemberSignupService` enforce on the way in.
     */
    private function narrowToServiceInterest($query, Masjid $masjid, int $serviceId): void
    {
        $query
            ->whereNotNull('contact_id')
            ->whereIn('contact_id', function ($sub) use ($masjid, $serviceId) {
                $sub->select('contact_id')
                    ->from('contact_service_interests')
                    ->where('masjid_id', $masjid->id)
                    ->where('service_id', $serviceId);
            })
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('contacts')
                    ->whereColumn('contacts.id', 'mobile_app_users.contact_id')
                    ->whereNull('contacts.deleted_at')
                    ->whereNull('contacts.login_revoked_at');
            });
    }
}
