<?php

namespace App\Services\Cart;

use App\Models\Donation;
use App\Models\DonationReceipt;

/**
 * What one call to CartSettlementService::settle() did, for the webhook that made it.
 *
 * `settled` is true only when THIS call moved the order from unpaid to paid. A replay, a
 * paid order seen again, a refusal and a mismatch are all false, and none of them is an
 * error: the webhook answers 200 either way.
 *
 * `receipts` are the donation receipts issued AFTER the transaction committed, each with
 * its donation. Issuing is settlement's; e-mailing the receipt is the webhook
 * controller's existing delivery (StripeWebhookController::deliverReceipt), which is
 * once-only on `receipt_delivered_at` and stays where it is rather than being copied here.
 */
final readonly class CartSettlementResult
{
    /**
     * @param  list<array{0: Donation, 1: DonationReceipt}>  $receipts
     */
    public function __construct(
        public bool $settled = false,
        public array $receipts = [],
    ) {}

    /** Nothing was recorded by this call. */
    public static function none(): self
    {
        return new self;
    }
}
