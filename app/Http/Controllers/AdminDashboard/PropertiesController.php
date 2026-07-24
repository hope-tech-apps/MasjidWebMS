<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Properties\StorePropertyRequest;
use App\Http\Requests\Admin\Properties\StoreRentPaymentRequest;
use App\Http\Requests\Admin\Properties\UpdatePropertyRequest;
use App\Models\Property;
use App\Models\RentPayment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: rental properties + their rent payments.
 *
 * A SEPARATE component from the donor CRM — rent is not a charitable gift and
 * never appears in donor totals, receipts, or statements. Tenant-scoped by the
 * `tenant` middleware + BelongsToMasjid, so findOrFail on another masjid's
 * property is a 404, never a leak. Amounts are integer cents; the API speaks
 * dollars and converts here.
 */
class PropertiesController extends Controller
{
    public function index(Request $request)
    {
        $properties = Property::query()
            ->withCount('rentPayments')
            ->withSum('rentPayments', 'amount')   // rent_payments_sum_amount (cents)
            ->when($request->query('active') !== null, fn ($q) => $q->where('is_active', (bool) $request->query('active')))
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $properties,
        ], Response::HTTP_OK);
    }

    public function show($masjid_id, $property_id)
    {
        $property = Property::with(['rentPayments' => fn ($q) => $q->orderByDesc('paid_on')])
            ->findOrFail($property_id);

        return response()->json([
            'status' => 'success',
            'data' => $property,
        ], Response::HTTP_OK);
    }

    public function store(StorePropertyRequest $request, $masjid_id)
    {
        $data = $request->validated();
        $data['monthly_rent'] = $this->toCents($data['monthly_rent'] ?? null);

        // masjid_id is stamped by the BelongsToMasjid hook when a tenant is bound;
        // fall back to the route masjid when unbound so the NOT NULL insert holds.
        $data['masjid_id'] = $data['masjid_id'] ?? (int) $masjid_id;
        $property = Property::create($data);

        return response()->json([
            'status' => 'success',
            'data' => $property,
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePropertyRequest $request, $masjid_id, $property_id)
    {
        $property = Property::findOrFail($property_id);
        $data = $request->validated();
        if (array_key_exists('monthly_rent', $data)) {
            $data['monthly_rent'] = $this->toCents($data['monthly_rent']);
        }

        $property->fill($data)->save();

        return response()->json([
            'status' => 'success',
            'data' => $property,
        ], Response::HTTP_OK);
    }

    public function destroy($masjid_id, $property_id)
    {
        $property = Property::findOrFail($property_id);
        $property->delete();   // soft delete; rent history is preserved

        return response()->json([
            'status' => 'success',
            'data' => 'Property archived.',
        ], Response::HTTP_OK);
    }

    /** Record a rent payment against a property (or a negative vacancy adjustment). */
    public function storeRent(StoreRentPaymentRequest $request, $masjid_id, $property_id)
    {
        $property = Property::findOrFail($property_id);   // tenant-scoped

        $payment = new RentPayment($request->safe()->only(['paid_on', 'payment_method', 'note']));
        $payment->property_id = $property->id;
        // A rent payment always belongs to the same masjid as its property. The
        // BelongsToMasjid creating-hook stamps masjid_id when a tenant is BOUND,
        // but when the context is UNBOUND (a SuperAdmin, or an admin whose masjid
        // link did not resolve) the hook is a no-op, so the caller must set it —
        // otherwise the NOT NULL insert fails ("masjid_id doesn't have a default").
        $payment->masjid_id = $property->masjid_id;
        $payment->amount = $this->toCents($request->validated('amount'));
        $payment->save();

        return response()->json([
            'status' => 'success',
            'data' => $payment,
        ], Response::HTTP_CREATED);
    }

    public function destroyRent($masjid_id, $property_id, $rent_id)
    {
        // Scope through the property so the payment can't belong to another masjid.
        $property = Property::findOrFail($property_id);
        $payment = RentPayment::where('property_id', $property->id)->findOrFail($rent_id);
        $payment->delete();

        return response()->json([
            'status' => 'success',
            'data' => 'Rent payment removed.',
        ], Response::HTTP_OK);
    }

    /** Dollars (float/string) → integer cents, preserving sign; null passes through. */
    private function toCents($dollars): ?int
    {
        if ($dollars === null || $dollars === '') {
            return null;
        }

        return (int) round(((float) $dollars) * 100);
    }
}
