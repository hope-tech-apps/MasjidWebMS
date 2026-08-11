<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contacts\StoreSmsConsentRequest;
use App\Models\Contact;
use App\Services\Sms\SmsConsentService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recording and withdrawing a contact's SMS consent (T-009).
 *
 * Two verbs on one resource, and they are deliberately NOT a single boolean
 * toggle:
 *
 *  - POST   grants consent. Refuses (422) when the contact has no usable number,
 *           and refuses when the number is on the suppression list — an admin
 *           cannot opt somebody back in after they texted STOP. Only the
 *           subscriber can, by texting START to the number they heard from.
 *  - DELETE withdraws it, AND writes the durable suppression row so the
 *           withdrawal survives the contact being merged away, re-imported or
 *           deleted and re-added.
 *
 * A toggle would make those two look like inverses of each other, which is
 * exactly the misunderstanding that produces an unhonoured opt-out.
 *
 * ## Where it sits
 *
 * Beside the contacts endpoints, inside the `crm` group, gated on
 * `permission:manage contacts` — writing consent is editing the contact record,
 * so it takes the permission editing a contact takes. No permission is minted
 * (Permission::count() stays at 8; StaffAuthGuardPinTest pins it).
 *
 * Tenant isolation is the guardrail's, not the controller's: the scoped
 * findOrFail sits OUTSIDE any try/catch so another masjid's contact id is a
 * clean 404 rather than a 500 (.claude/rules/tenant-scoping.md).
 */
class ContactSmsConsentController extends Controller
{
    public function __construct(private readonly SmsConsentService $consent)
    {
    }

    /** Record that this contact agreed to receive text messages. */
    public function store(StoreSmsConsentRequest $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        try {
            $contact = $this->consent->grant(
                $contact,
                $request->string('source')->toString(),
                $request->input('evidence'),
            );
        } catch (\RuntimeException $e) {
            // A refusal, not a crash: no usable number, or the number opted out.
            // The message is written for the admin reading it and names who CAN
            // undo an opt-out.
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'data' => $contact->fresh(),
        ], Response::HTTP_OK);
    }

    /**
     * Withdraw consent, and suppress the number durably.
     *
     * Used when somebody asks to stop in person or on the phone rather than by
     * texting STOP. The suppression row is the point: clearing the columns alone
     * would let the next CSV import undo the withdrawal.
     */
    public function destroy($masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        $contact = $this->consent->withdraw($contact);

        return response()->json([
            'status' => 'success',
            'data' => $contact->fresh(),
        ], Response::HTTP_OK);
    }
}
