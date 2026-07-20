<?php

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\Donation;

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

        $details = $session['customer_details'] ?? [];
        $email = isset($details['email']) ? trim((string) $details['email']) : '';
        if ($email === '') {
            return null;
        }

        [$first, $last] = $this->splitName((string) ($details['name'] ?? ''));

        $contact = Contact::withoutMasjidScope()
            ->where('masjid_id', $donation->masjid_id)
            ->where('email', $email)
            ->first();

        if (! $contact) {
            $contact = new Contact([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
            ]);
            // Unbound context: the BelongsToMasjid creating hook won't stamp, so set it.
            $contact->masjid_id = $donation->masjid_id;
            $contact->save();
        }

        $donation->forceFill(['contact_id' => $contact->id])->save();

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
