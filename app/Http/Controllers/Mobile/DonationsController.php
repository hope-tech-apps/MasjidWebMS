<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\Donations\CreateDonationCheckoutRequest;
use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Stripe\DonationService;
use App\Support\Errors;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public (unauthenticated) donation entry point for the mobile apps / web.
 *
 * This runs UNBOUND (the mobile routes never hit the tenant middleware), so the
 * masjid and fund are resolved by EXPLICIT masjid_id filtering — we cannot lean
 * on the BelongsToMasjid global scope here. DonationService persists a pending
 * donation and returns a hosted Stripe Checkout URL (PCI SAQ A: the card is
 * entered on Stripe's page, never here).
 */
class DonationsController extends Controller
{
    public function __construct(private DonationService $donations)
    {
    }

    public function createCheckoutSession(CreateDonationCheckoutRequest $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        // The org must have completed Stripe onboarding before it can be paid.
        if (! $masjid->canAcceptDonations()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This masjid is not able to accept online donations yet.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Explicit tenant filter (unbound context): the fund must belong to this
        // masjid and be active. A fund from another masjid resolves to 404.
        $fund = Fund::where('masjid_id', $masjid->id)
            ->where('id', $request->integer('fund_id'))
            ->where('is_active', true)
            ->first();

        if (! $fund) {
            return response()->json([
                'status' => 'error',
                'message' => 'Fund not found for this masjid.',
            ], Response::HTTP_NOT_FOUND);
        }

        $options = array_filter([
            'success_url' => $request->input('success_url'),
            'cancel_url' => $request->input('cancel_url'),
        ], fn ($v) => $v !== null);

        try {
            // Recurring path: subscription-mode checkout. The commitment is the
            // handle we return; the individual charges are booked by webhook.
            if ($request->boolean('recurring')) {
                $result = $this->donations->createSubscriptionCheckout(
                    $masjid,
                    $fund,
                    $request->integer('amount'),
                    $request->boolean('donor_covers_fees'),
                    $options + ['interval' => $request->input('interval', 'month')],
                );

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'checkout_url' => $result['checkout_url'],
                        'subscription_uuid' => $result['subscription']->uuid,
                        'charged_amount' => $result['subscription']->charged_amount,
                        'currency' => $result['subscription']->currency,
                        'interval' => $result['subscription']->interval,
                        'recurring' => true,
                    ],
                ], Response::HTTP_CREATED);
            }

            $result = $this->donations->createDonationCheckout(
                $masjid,
                $fund,
                $request->integer('amount'),
                $request->boolean('donor_covers_fees'),
                $options,
            );

            return response()->json([
                'status' => 'success',
                'data' => [
                    'checkout_url' => $result['checkout_url'],
                    'donation_uuid' => $result['donation']->uuid,
                    'charged_amount' => $result['donation']->charged_amount,
                    'currency' => $result['donation']->currency,
                    'recurring' => false,
                ],
            ], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
