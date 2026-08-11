<?php

namespace App\Http\Controllers\Family;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Who am I?" — the only endpoint in the family realm as of T-015c.
 *
 * The slice exists to prove that a parent can authenticate, is scoped to their
 * own organisation, and can be revoked. Nothing here reads a group, a roster, a
 * feed, a photograph or a record: every one of those disclosures runs through
 * `GroupAudience`, whose Contact branch does not exist yet (T-015e), and a
 * screen that displayed them would have to invent an authorization rule to do
 * it. So this returns the contact's own identity and stops.
 */
class MeController extends Controller
{
    public function show()
    {
        $contact = Auth::user();

        // Unreachable: `family.active` refuses every non-Contact principal
        // before this runs. Written anyway because the failure it prevents is
        // an unauthenticated null reaching the field reads below — a 500 and a
        // stack trace rather than a refusal. A controller in this realm fails
        // closed on its own, without trusting its middleware stack to be
        // mounted the way the route file says it is.
        if (! $contact instanceof Contact) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        // An EXPLICIT projection, never `$contact` itself and never
        // `->toArray()`. The model carries fields a parent must not be handed
        // back: `notes` is a staff-authored free-text field on the CRM record —
        // the natural home for exactly the safeguarding remark this realm must
        // not disclose — and `email` / `phone` are the office's contact data,
        // not the parent's credential. Serializing the model would publish
        // every column added to `contacts` from now on, silently, on the day it
        // is added. Least disclosure is a property of the payload
        // (.claude/rules/groups.md), so the payload is built by hand.
        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => (int) $contact->id,
                // The organisation this token is bound to — the same value
                // `family.tenant` bound the request to, so a client can tell
                // which masjid it is looking at without asking a second
                // endpoint or trusting the URL it happened to call.
                'masjid_id' => (int) $contact->masjid_id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                // The address this login answers to, so the app can show the
                // parent which of their mailboxes the school has on file.
                'login_email' => $contact->login_email,
            ],
        ], Response::HTTP_OK);
    }
}
