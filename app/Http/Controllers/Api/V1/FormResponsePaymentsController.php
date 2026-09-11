<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Services\Stripe\FormCheckoutRefused;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\Errors;
use App\Support\FormPaymentReturn;
use App\Support\PublicTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A card registration after its submit (DECISIONS.md 2026-09-11). The page Stripe
 * sends the payer back to reads the payment state here, and "Return to payment"
 * reopens the page here.
 *
 * The uuid in the path is a BEARER handle: it travels in Stripe's return URL and the
 * browser's history. So:
 *
 *  - the status read says only what the return page draws: the payment state, whether
 *    an admin cancelled it, the amounts and the form's public success copy. No name,
 *    email, answer, holder or payment history. The WhatsApp group link is added only
 *    once the registration is settled (FormResponse::isSettled()), and never to a
 *    cancelled one: a refunded card payer is cancelled and still reads as paid.
 *  - both routes are unauthenticated and unbound, like the submit: the masjid comes
 *    from the header and must still exist (PublicTenant), and the row is found within
 *    it (FormResponse::findByUuidForMasjid()). An unknown uuid, another masjid's, and
 *    a row with no money leg are one 404, byte for byte.
 *  - each route has its own named limiter keyed by the uuid, not the connection:
 *    every phone at the venue shares one address (AppServiceProvider).
 *
 * Nothing here marks a row paid. Only the signed webhook does.
 *
 * Pinned by tests/Feature/FormPaymentCheckoutTest.php.
 */
class FormResponsePaymentsController extends Controller
{
    private const NOT_FOUND = 'This registration was not found.';

    /**
     * GET /api/v1/form-responses/{uuid}
     */
    public function show(Request $request, string $uuid)
    {
        try {
            $found = $this->resolve($request, $uuid);

            if ($found instanceof JsonResponse) {
                return $found;
            }

            [$row, $form] = $found;

            return response()->api(200, 'ok', $this->status($row, $form));
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * POST /api/v1/form-responses/{uuid}/checkout  {return_path}
     *
     * "Return to payment": the open page again, or a new one once it has expired
     * (FormResponseCheckoutService::reopen()). Nothing is repriced. A refusal answers with
     * the row as the lock found it, which reopen() copies back onto $row: cash taken or a
     * cancel committed while this request waited is what the page is then shown. A page
     * Stripe says was paid, before the webhook has recorded it, is answered
     * `confirming: true` with `can_pay: false` (FormCheckoutRefused::answer()).
     */
    public function checkout(Request $request, string $uuid)
    {
        try {
            $found = $this->resolve($request, $uuid);

            if ($found instanceof JsonResponse) {
                return $found;
            }

            [$row, $form] = $found;

            // The submit's rules exactly: an allowlisted origin and a relative path.
            $returnTo = FormPaymentReturn::base($request, [
                'masjid_id' => $row->masjid_id,
                'form_id' => $form->id,
                'form_response_id' => $row->id,
            ]);

            if ($returnTo === null) {
                return response()->api(422, FormPaymentReturn::REFUSED, null);
            }

            try {
                $page = app(FormResponseCheckoutService::class)->reopen($row, $returnTo);
            } catch (FormCheckoutRefused $e) {
                return response()->api(422, $e->getMessage(), $e->answer($this->status($row, $form)));
            } catch (\Stripe\Exception\ExceptionInterface $e) {
                FormResponseCheckoutService::reportFailure($e, $row);

                return response()->api(422, FormResponseCheckoutService::COULD_NOT_OPEN, $this->status($row, $form));
            }

            return response()->api(200, 'ok', $this->status($page['response'], $form) + [
                'checkout_url' => $page['checkout_url'],
            ]);
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * The row and its form for this uuid within the header's masjid, or the answer to
     * give instead. Every miss is the same 404.
     *
     * @return array{0: FormResponse, 1: Form}|JsonResponse
     */
    private function resolve(Request $request, string $uuid): array|JsonResponse
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            return response()->api(400, 'A masjid must be specified.', null);
        }

        $row = PublicTenant::exists($masjidId) ? FormResponse::findByUuidForMasjid($uuid, $masjidId) : null;

        // A row with no money leg (a free form's) has nothing to report here, and its
        // uuid is never handed out: it misses like an unknown one. Hand-filtered form,
        // because this runs unbound.
        $form = $row !== null && $row->hasMoneyLeg()
            ? Form::query()->where('masjid_id', $masjidId)->whereKey($row->form_id)->first()
            : null;

        if ($row === null || $form === null) {
            return response()->api(404, self::NOT_FOUND, null);
        }

        return [$row, $form];
    }

    /**
     * What the return page draws, and nothing it does not.
     *
     * @return array<string,mixed>
     */
    private function status(FormResponse $row, Form $form): array
    {
        $settings = $form->settings ?? [];

        $data = [
            'form_id' => (int) $form->id,
            'payment_status' => $row->payment_status,
            'payment_method' => $row->payment_method,
            'entry_count' => (int) $row->entry_count,
            'amount_due_minor' => $row->amount_due_minor,
            'fee_covered_minor' => (int) $row->fee_covered_minor,
            'total_minor' => $row->total_minor,
            'currency' => $row->currency,
            // Checked before any paid reading: a cancelled registration is not "paid",
            // whatever its payment_status says.
            'cancelled' => $row->isCancelled(),
            'can_pay' => $this->canPay($row, $form),
            'success_title' => $settings['successTitle'] ?? null,
            'success_body' => $settings['successBody'] ?? null,
            'success_next_steps' => $settings['successNextSteps'] ?? [],
        ];

        $whatsapp = $form->whatsappUrl();

        if ($whatsapp !== null && ! $row->isCancelled() && $row->setRelation('form', $form)->isSettled()) {
            $data['whatsapp_url'] = $whatsapp;
            $data['whatsapp_label'] = $settings['whatsappLabel'] ?? null;
        }

        return $data;
    }

    /**
     * Whether "Return to payment" can work: unpaid by card, not cancelled, and the card
     * leg is up. A cancelled registration is refused by the checkout itself; saying so
     * here keeps the page from offering a button that can only fail.
     */
    private function canPay(FormResponse $row, Form $form): bool
    {
        return $row->payment_method === FormResponse::METHOD_ONLINE
            && ! $row->isPaid()
            && ! $row->isCancelled()
            && $form->takesOnlinePayment()
            && FormResponseCheckoutService::refusal(Masjid::find($row->masjid_id), (int) $row->total_minor) === null;
    }
}
