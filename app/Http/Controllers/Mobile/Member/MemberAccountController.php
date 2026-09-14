<?php

namespace App\Http\Controllers\Mobile\Member;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Member\MemberAccountDeletion;
use Illuminate\Http\Request;

/**
 * "Delete account" from inside the app.
 *
 * The member is the TOKEN's contact, never the URL: `family.tenant` has already
 * refused a path naming another organisation, so there is nothing here to
 * branch on. What deleting means lives in MemberAccountDeletion, which the
 * public /account-deletion page runs too.
 *
 * ---------------------------------------------------------------------------
 * NOT BEHIND `crm`
 * ---------------------------------------------------------------------------
 * App Store guideline 5.1.1(v) and Google Play's account-deletion policy both
 * require that an app offering sign-up also offers deletion. An organisation can
 * switch its CRM off after members signed up, and a `crm`-gated route would then
 * 403 the one request a member most needs to make. Sign-in stays behind `crm`;
 * leaving never does. See routes/api.php.
 */
class MemberAccountController extends Controller
{
    public function __construct(private MemberAccountDeletion $deletion)
    {
    }

    /** DELETE /api/mobile/masjids/{masjid_id}/me */
    public function destroy(Request $request)
    {
        /** @var Contact $contact */
        $contact = $request->user();

        $this->deletion->delete($contact, MemberAccountDeletion::VIA_APP, $request->ip());

        // An empty `data` object, not an omitted key: the iPhone app decodes
        // every mobile response through one `Response<T>` envelope whose `data`
        // is non-optional. Empty because the outcome (erased or kept) is the
        // office's business, and the app's next step is the same either way:
        // forget the token.
        return response()->json(['status' => 'success', 'data' => new \stdClass()]);
    }
}
