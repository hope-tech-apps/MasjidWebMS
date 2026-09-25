<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentMethods\UpdatePaymentMethodsRequest;
use App\Models\Masjid;
use App\Models\OrganisationPaymentMethod;
use App\Support\Errors;
use App\Support\PaymentMethods;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: the organisation's accepted payment methods and how to pay with each.
 *
 * Tenant isolation is the guardrail's, not this controller's (FundsController is
 * the template): the `tenant` middleware binds the organisation and
 * BelongsToMasjid scopes every query and stamps every insert, so nothing here
 * filters or sets masjid_id. It sits OUTSIDE `crm` and carries no capability:
 * every organisation that takes any money has payment methods, whether or not it
 * runs the member directory, and `admin` already means a SuperAdmin or this
 * organisation's own MasjidAdmin.
 */
class PaymentMethodsController extends Controller
{
    public function index($masjid_id)
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->payload(),
        ], Response::HTTP_OK);
    }

    /**
     * Replace the set. A method left out is no longer accepted and its row is
     * deleted with its instructions; the order of the list is the order the
     * public sees. One transaction, so a failure half way leaves the old set.
     */
    public function update(UpdatePaymentMethodsRequest $request, $masjid_id)
    {
        $wanted = collect($request->validated('methods'))->values();

        try {
            DB::transaction(function () use ($wanted): void {
                $keep = $wanted->pluck('method')->all();

                OrganisationPaymentMethod::query()->whereNotIn('method', $keep)->delete();

                foreach ($wanted as $position => $row) {
                    $method = OrganisationPaymentMethod::query()->firstOrNew(['method' => $row['method']]);
                    $method->label = filled($row['label'] ?? null) ? trim((string) $row['label']) : null;
                    $method->instructions = filled($row['instructions'] ?? null) ? trim((string) $row['instructions']) : null;
                    $method->sort_order = $position;
                    $method->save();
                }
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->payload(),
        ], Response::HTTP_OK);
    }

    /**
     * The saved set, the vocabulary to choose from, and whether card can be
     * offered today — so the screen can say "saved, but not shown until Stripe
     * onboarding is finished" instead of letting an admin believe it is live.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $masjid = Masjid::find(app(TenantContext::class)->get());

        return [
            'methods' => OrganisationPaymentMethod::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'method', 'label', 'instructions', 'sort_order'])
                ->values(),
            'catalogue' => collect(PaymentMethods::KEYS)->map(fn (string $key) => [
                'method' => $key,
                'label' => PaymentMethods::LABELS[$key],
                'online' => $key === PaymentMethods::CARD,
            ])->values(),
            'card_ready' => (bool) $masjid?->canAcceptDonations(),
        ];
    }
}
