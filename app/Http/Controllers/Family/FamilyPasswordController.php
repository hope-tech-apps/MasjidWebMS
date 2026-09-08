<?php

namespace App\Http\Controllers\Family;

use App\Http\Controllers\Controller;
use App\Http\Requests\Family\SetFamilyPasswordRequest;
use App\Models\Contact;
use App\Services\Family\FamilyPasswordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A parent chooses their own password, or removes it (2026-09-08).
 *
 * The counterpart to `FamilyAuthController::signInWithPassword`, and the reason
 * that door can exist safely. Everything here is AUTHENTICATED — the route sits
 * behind the realm's full stack (`auth:family`, `family.active`,
 * `family.parent`, `family.tenant`, `crm`, `throttle:family`) — so unlike the
 * sign-in endpoints there is nothing to disclose and errors may be specific.
 *
 * ---------------------------------------------------------------------------
 * THE CONTACT COMES FROM THE TOKEN. There is no other way to name one.
 * ---------------------------------------------------------------------------
 *
 * Neither method takes a contact id, an email, or any other identifier from the
 * request — not from the body, not from the URL. `Auth::user()` is the only
 * source, so "set the password of somebody else" is not a request that can be
 * expressed against this controller, rather than one that is checked and
 * refused. That is the same shape as `GroupThreadsController::store` forcing
 * scope in the controller instead of reading it from the payload, and it is
 * what keeps the promise in FamilyPasswordService's docblock — that the office
 * can never hold a family's password — true by construction rather than by
 * review.
 *
 * `{masjid_id}` is still in the URI because every family route is addressed
 * per-organisation, but it is an assertion `family.tenant` verifies against the
 * token, never a lookup key. See routes/family.php.
 */
class FamilyPasswordController extends Controller
{
    public function __construct(private FamilyPasswordService $passwords)
    {
    }

    /**
     * PUT /api/family/masjids/{masjid_id}/password
     *
     * Idempotent by nature: setting a password when one already exists is how a
     * parent CHANGES it, which is why this is a PUT and why there is no separate
     * change endpoint to keep in step with this one.
     */
    public function update(SetFamilyPasswordRequest $request)
    {
        $contact = $this->contact();

        $this->passwords->set(
            $contact,
            (string) $request->input('password'),
            // The caller's OWN token id, so `set()` can end every other session
            // this family holds without ending the one they are using. Read from
            // the authenticated token rather than the payload for the obvious
            // reason: a client-supplied id would let a caller choose which
            // session survives.
            (string) ($request->user()?->currentAccessToken()?->id ?? ''),
            $request->ip(),
        );

        return $this->state($contact->refresh(), 'Your password is set. You can sign in with it from now on.');
    }

    /**
     * DELETE /api/family/masjids/{masjid_id}/password
     *
     * Back to sign-in codes only. Deliberately does NOT end any session: a
     * parent removing a password is simplifying how they sign in, not
     * responding to a compromise, and signing them out of the app in the middle
     * of it would read as an error.
     */
    public function destroy(Request $request)
    {
        $contact = $this->contact();

        $this->passwords->clear($contact, $request->ip());

        return $this->state($contact->refresh(), 'Your password has been removed. Sign in with an emailed code from now on.');
    }

    /**
     * What a client may know about this credential: THAT it exists and when it
     * was chosen. Never the hash — `Contact::$hidden` stops that too, but a
     * hand-built projection means this endpoint does not depend on that list
     * staying correct.
     */
    private function state(Contact $contact, string $message)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'has_password' => $contact->hasFamilyPassword(),
                'password_set_at' => $contact->password_set_at?->toIso8601String(),
            ],
        ], Response::HTTP_OK);
    }

    /** The authenticated parent, and the only contact this controller can act on. */
    private function contact(): Contact
    {
        /** @var Contact $contact */
        $contact = Auth::guard('family')->user();

        return $contact;
    }
}
