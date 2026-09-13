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
 *  - POST   grants consent, ONCE. Refuses (422) when the contact has no usable
 *           number; when the number is on the suppression list — an admin cannot
 *           opt somebody back in after they texted STOP, only the subscriber can,
 *           by texting START to the number they heard from; and when a consent
 *           record already stands, because re-recording it can only destroy the
 *           date, source and evidence that make the first one evidence
 *           (SmsConsentService::grant carries the full argument).
 *  - DELETE withdraws it, AND writes the durable suppression row so the
 *           withdrawal survives the contact being merged away, re-imported or
 *           deleted and re-added — reporting in `meta.durable` whether that
 *           second half was actually possible.
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
     *
     * ## The response says WHICH of the two halves happened
     *
     * The durable half can fail on its own: the suppression list is keyed on
     * E.164 and `PhoneNumber` refuses a number it cannot resolve (a seven-digit
     * local number, "…ext 4", a bare international number), so there is nothing
     * to key a row on. This still answers 200 — the withdrawal IS recorded, and
     * the person asked for it — but `meta.durable` says whether it reached the
     * list that outlives this contact row, and `meta.message` carries the
     * server's own remedy sentence when it did not.
     *
     * It is reported rather than thrown because the two audiences differ: the
     * member's request has been honoured, so this is not a refusal; the
     * OPERATOR, though, was being told the number was on a permanent
     * do-not-text list that had never heard of it, and they are the only person
     * who can fix the number. The SPA quotes `meta.message` verbatim; a screen
     * that promised permanence it did not get is the defect this closes.
     */
    public function destroy($masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        $withdrawal = $this->consent->withdraw($contact);

        return response()->json([
            'status' => 'success',
            'data' => $withdrawal->contact->fresh(),
            'meta' => [
                'durable' => $withdrawal->isDurable(),
                'message' => $withdrawal->remedy(),
            ],
        ], Response::HTTP_OK);
    }
}
