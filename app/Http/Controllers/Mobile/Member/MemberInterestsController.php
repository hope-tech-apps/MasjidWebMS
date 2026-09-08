<?php

namespace App\Http\Controllers\Mobile\Member;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Member\MemberInterestService;
use Illuminate\Http\Request;

/**
 * The interest picker: the onboarding screen, and the same screen again later
 * from settings.
 *
 * The member is taken from the TOKEN, never from the URL. The route still
 * carries `{masjid_id}` to match every other mobile endpoint, but nothing here
 * reads it — `family.tenant` binds the tenant from the authenticated contact,
 * so a token cannot be pointed at another organisation by editing the path.
 */
class MemberInterestsController extends Controller
{
    public function __construct(private MemberInterestService $interests)
    {
    }

    /** GET — the whole catalogue, each flagged. */
    public function index(Request $request)
    {
        /** @var Contact $contact */
        $contact = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => $this->interests->list($contact),
        ]);
    }

    /**
     * PUT — replace this member's interests.
     *
     * `service_ids` may legitimately be an empty array: that is a member turning
     * everything off, which must be as easy as turning things on.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'service_ids' => ['present', 'array'],
            'service_ids.*' => ['integer'],
        ]);

        /** @var Contact $contact */
        $contact = $request->user();

        $this->interests->set($contact, $validated['service_ids']);

        // Echo the resulting state rather than the diff: the client just
        // replaced a screen's worth of toggles and should render what is now
        // true, not reconcile counts.
        return response()->json([
            'status' => 'success',
            'data' => $this->interests->list($contact),
        ]);
    }
}
