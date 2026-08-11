<?php

namespace App\Services\Broadcast;

use App\Enums\BroadcastAudience;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Masjid;
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
     * OneSignal subscription ids for this masjid's registered devices.
     *
     * Copied in shape from AdminDashboard\NotificationsController::save, which
     * is still the authority: subscription IDs, never external_id aliases —
     * aliases do not resolve in OneSignal's notification API. Only devices that
     * reported a subscription id via the heartbeat are targetable.
     *
     * @return array<int, string>
     */
    public function pushSubscriptionIds(Masjid $masjid): array
    {
        return $masjid->mobileAppUsers()
            ->whereNotNull('onesignal_subscription_id')
            ->pluck('onesignal_subscription_id')
            ->filter()
            ->values()
            ->toArray();
    }
}
