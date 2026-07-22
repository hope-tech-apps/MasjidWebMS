<?php

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationSubscription;

/**
 * Turns an anonymous donor into a first-class Contact — the seed of the donor
 * CRM. Called from the (unbound) Stripe webhook after a donation succeeds, so
 * masjid_id is set explicitly and lookups skip the tenant scope.
 *
 * Idempotent: re-runs return the already-linked contact and never duplicate.
 */
class DonorContactService
{
    /**
     * Find-or-create a Contact for this masjid from a Checkout Session's
     * customer_details, and link it to the donation. Returns the contact, or
     * null when no email was collected (nothing to key on).
     */
    public function linkFromCheckoutSession(Donation $donation, array $session): ?Contact
    {
        if ($donation->contact_id) {
            return Contact::withoutMasjidScope()->find($donation->contact_id);
        }

        $contact = $this->findOrCreateForMasjid(
            (int) $donation->masjid_id,
            $session['customer_details'] ?? []
        );

        if ($contact) {
            $donation->forceFill(['contact_id' => $contact->id])->save();
        }

        return $contact;
    }

    /**
     * Same as above for a recurring commitment: seed the donor from the first
     * checkout session and pin them to the subscription, so every monthly charge
     * inherits the contact without re-reading customer details.
     */
    public function linkSubscriptionContact(DonationSubscription $subscription, array $session): ?Contact
    {
        if ($subscription->contact_id) {
            return Contact::withoutMasjidScope()->find($subscription->contact_id);
        }

        $contact = $this->findOrCreateForMasjid(
            (int) $subscription->masjid_id,
            $session['customer_details'] ?? []
        );

        if ($contact) {
            $subscription->forceFill(['contact_id' => $contact->id])->save();
        }

        return $contact;
    }

    /**
     * Find-or-create a Contact for a masjid from Stripe customer_details. Returns
     * null when no email was collected (nothing to key on). Idempotent per
     * (masjid, email). Runs unbound, so masjid_id is set/filtered explicitly.
     */
    public function findOrCreateForMasjid(int $masjidId, array $customerDetails): ?Contact
    {
        $email = isset($customerDetails['email']) ? trim((string) $customerDetails['email']) : '';
        if ($email === '') {
            return null;
        }

        [$first, $last] = $this->splitName((string) ($customerDetails['name'] ?? ''));

        $contact = Contact::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('email', $email)
            ->first();

        if (! $contact) {
            $contact = new Contact([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
            ]);
            $contact->masjid_id = $masjidId;
            $contact->save();
        }

        return $contact;
    }

    /** Split a full name into [first, last]; last may be empty. */
    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['Donor', ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }
}
