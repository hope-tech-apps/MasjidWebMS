<?php

namespace App\Services\Crm;

use App\Exceptions\AmbiguousDonorNameException;
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

    /**
     * Find-or-create a Contact from a NAME alone — the offline-gift path.
     *
     * ## Why a second key exists next to findOrCreateForMasjid()
     *
     * That method keys on EMAIL and returns null without one, which is right for
     * Stripe: a card donor always has an email, and the address is the stable
     * identity across gifts. An offline gift has neither. A treasurer recording
     * a cheque at the back of the hall has a name written on the cheque and
     * nothing else, and until this method existed the name had nowhere to go —
     * `store()` accepted only `contact_id`, so a donor who was not already in the
     * directory was silently booked as a general gift. MEASURED: donation 780,
     * $500 cheque #1084, recorded 2026-08-26 with the payer's name typed into the
     * form and discarded in the browser.
     *
     * ## Matching, and why it is exact rather than fuzzy
     *
     * A gift is money attributed to a person, so a wrong match is worse than a
     * duplicate: it puts one household's cheque on another household's year-end
     * statement. So the match is an exact, case- and whitespace-insensitive
     * comparison of first+last, and nothing looser. Anything fuzzy — initials,
     * nicknames, transposed names — is left to the admin, who has the typeahead
     * in front of them and can pick the real contact.
     *
     * Three outcomes, deliberately including one refusal:
     *   - exactly one match  -> that contact, no duplicate created
     *   - none               -> a new contact, which is the whole point
     *   - MORE THAN ONE      -> ValidationException (422). Two people genuinely
     *     share a name; picking either one silently would attribute the money by
     *     coin flip. The admin is told to choose from the list instead.
     *
     * The created contact is a real directory entry, not a placeholder: the
     * masjid knows who gave, they simply had not been entered yet. `is_placeholder`
     * stays false, which is what keeps them eligible for a year-end statement.
     */
    public function findOrCreateByName(int $masjidId, string $name): ?Contact
    {
        if (trim($name) === '') {
            return null;
        }

        [$first, $last] = $this->splitName($name);

        $matches = Contact::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower(trim($first))])
            ->whereRaw("LOWER(TRIM(COALESCE(last_name, ''))) = ?", [mb_strtolower(trim($last))])
            ->orderBy('id')
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            throw new AmbiguousDonorNameException(trim($name), $matches);
        }

        $contact = new Contact([
            'first_name' => $first,
            'last_name' => $last,
        ]);
        $contact->masjid_id = $masjidId;
        $contact->save();

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
