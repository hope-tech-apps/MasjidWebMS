<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contacts\StoreEmailConsentRequest;
use App\Models\Contact;
use App\Services\Broadcast\EmailSuppressionService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff record a contact's consent to this organisation's email, which lifts
 * an import's `not_opted_in` precaution — and only that.
 *
 * Why it exists: the MEC Wix import writes `not_opted_in` for every address
 * Wix never had consent for (owner: "Everyone, most blocked"). That is the
 * import's inference, not the person's request, and the person it silences
 * never receives a broadcast, so the subscriber's own re-subscribe link can
 * never reach them. Without this, "never opted in on Wix" would mean "can never
 * opt in". The argument, and the refusal of every other reason, is on
 * EmailSuppressionService::liftPrecaution.
 *
 * Refuses (422) when the address has no suppression in force, or when it has
 * one for any other reason: an unsubscribe, an imported opt-out, a complaint or
 * a bounce is released only by the person, from the link in an email.
 *
 * Beside the SMS consent endpoints, inside the `crm` group, gated on
 * `permission:manage contacts` like them; no permission is minted. The scoped
 * findOrFail makes another organisation's contact a 404
 * (.claude/rules/tenant-scoping.md).
 */
class ContactEmailConsentController extends Controller
{
    public function __construct(private readonly EmailSuppressionService $suppressions)
    {
    }

    public function store(StoreEmailConsentRequest $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);
        $masjidId = (int) $contact->masjid_id;

        $lifted = $this->suppressions->liftPrecaution(
            $masjidId,
            $contact->email,
            $request->string('evidence')->toString(),
            Auth::id(),
        );

        if ($lifted === null) {
            $message = match (true) {
                EmailSuppressionService::normalize($contact->email) === null => 'This contact has no email address.',
                $this->suppressions->activeReason($masjidId, $contact->email) === null => 'This address is not suppressed, so there is nothing to record.',
                default => 'This address opted out or could not be delivered to. Only the person can resume email, from the link in an email they receive; staff cannot.',
            };

            return response()->json(['status' => 'error', 'message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $contact->fresh()->toArray();
        $data['email_opt_out_reason'] = null;

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ], Response::HTTP_OK);
    }
}
