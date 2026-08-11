<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Sms\UpdateSmsSenderRequest;
use App\Models\Masjid;
use App\Models\MasjidSmsSender;
use App\Services\Sms\SmsProviderFactory;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-masjid SMS sender identity and A2P 10DLC registration state (T-009).
 * Super-Admin only, mirroring MasjidOneSignalController.
 *
 * ## Why this is an operator surface and not a masjid-admin one
 *
 * 10DLC registration is a commercial act performed by the platform on the
 * organisation's behalf: it involves the provider account, a legal business
 * identity and an EIN, and a mistake is charged to the whole platform's sending
 * reputation. A masjid admin cannot do it and must not be able to claim it was
 * done — which is precisely what a self-serve "our number is approved" toggle
 * would let them do. So the route sits behind `super`, and the masjid is always
 * resolved from the route id rather than the body.
 *
 * ## What it does NOT do
 *
 * It does not register anything. There is no API call here to any provider, no
 * brand creation, no campaign submission. Registration is a human step with
 * days of latency and a possible rejection; this endpoint records its OUTCOME so
 * the sending path can refuse until that outcome is `approved`. The steps an
 * operator must actually perform are written out in
 * .claude/rules/broadcasts.md.
 *
 * `approved_at` is stamped by the server on the transition into `approved` and
 * cleared on the way out, so "approved" always carries its date and a rejected
 * or suspended sender never keeps a stale one.
 */
class MasjidSmsSenderController extends Controller
{
    public function __construct(private readonly SmsProviderFactory $providers)
    {
    }

    /**
     * Read this masjid's sender, plus whether the PLATFORM is configured at all.
     *
     * Both halves matter to the operator: a perfectly approved sender still
     * cannot send on a deployment with no provider credentials, and being told
     * that here is better than discovering it from a failed delivery row.
     */
    public function show($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        $sender = MasjidSmsSender::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'masjid_id' => (int) $masjid->id,
                'sender' => $sender,
                'can_send' => (bool) $sender?->canSend(),
                'refusal_reason' => $sender ? $sender->refusalReason() : 'No sender identity has been recorded '
                    . 'for this organization, so it cannot send text messages.',
                // Platform-level, not tenant-level: no credentials means no
                // tenant can send, however well registered it is.
                'provider_configured' => $this->providers->make()->isConfigured(),
                'provider' => $this->providers->make()->name(),
            ],
        ], Response::HTTP_OK);
    }

    /** Create or update the sender identity and its registration state. */
    public function update(UpdateSmsSenderRequest $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        $sender = MasjidSmsSender::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->first() ?? new MasjidSmsSender(['masjid_id' => $masjid->id]);

        $attributes = $request->safe()->only([
            'provider',
            'phone_number',
            'messaging_service_sid',
            'sender_label',
            'registration_status',
            'brand_registration_id',
            'campaign_registration_id',
            'notes',
        ]);

        $status = $attributes['registration_status'];

        // Server-stamped, so "approved" always carries its date and a sender
        // that leaves approved never keeps a stale one.
        $attributes['approved_at'] = $status === MasjidSmsSender::STATUS_APPROVED
            ? ($sender->approved_at ?? Carbon::now())
            : null;

        // Server-derived tenant: the route id, never the body.
        $attributes['masjid_id'] = $masjid->id;

        $sender->forceFill($attributes)->save();

        return response()->json([
            'status' => 'success',
            'data' => [
                'masjid_id' => (int) $masjid->id,
                'sender' => $sender->fresh(),
                'can_send' => $sender->fresh()->canSend(),
                'refusal_reason' => $sender->fresh()->refusalReason(),
            ],
        ], Response::HTTP_OK);
    }
}
