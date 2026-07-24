<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Donations\StoreOfflineDonationRequest;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Fund;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: donations list (read-only for the Phase-0 spike).
 *
 * Tenant-scoped by the `tenant` middleware + BelongsToMasjid — no hand-filtering
 * by $masjid_id. See .claude/rules/tenant-scoping.md.
 */
class DonationsController extends Controller
{
    public function index(Request $request, $masjid_id)
    {
        $donations = Donation::query()
            ->with(['fund', 'receipt', 'contact'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('fund_id'), fn ($q, $fundId) => $q->where('fund_id', $fundId))
            // Optional donor search (name or email).
            ->when($request->query('search'), function ($q, $search) {
                $q->whereHas('contact', function ($c) use ($search) {
                    $c->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        // Full name, so "Ahmad Fais" (first + last together) matches.
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                        ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$search}%"]);
                });
            })
            // Newest gift first — by the real gift date for offline history, else entry.
            ->orderByRaw('COALESCE(donated_at, created_at) DESC')
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $donations,
        ], Response::HTTP_OK);
    }

    /**
     * Record a manual OFFLINE donation (cash/check/Zelle/…). Stripe donations are
     * still webhook-only; this path exists for gifts that never touch Stripe. The
     * row is booked succeeded, source=offline, dated to when it was given, with no
     * receipt (an offline gift isn't a Stripe-verified event). Tenant-scoped:
     * fund/contact are validated to belong to this masjid before the write.
     */
    public function store(StoreOfflineDonationRequest $request, $masjid_id)
    {
        // Validate fund + contact belong to THIS masjid (bound tenant scopes these).
        $fund = Fund::findOrFail($request->integer('fund_id'));
        $contactId = null;
        if ($request->filled('contact_id')) {
            $contactId = Contact::findOrFail($request->integer('contact_id'))->id;
        }

        $cents = (int) round(((float) $request->validated('amount')) * 100);

        $donation = Donation::create([
            'contact_id' => $contactId,
            'fund_id' => $fund->id,
            'type' => 'one_time',
            'source' => 'offline',
            'payment_method' => $request->validated('payment_method'),
            'check_number' => $request->validated('payment_method') === 'check' ? $request->input('check_number') : null,
            'donated_at' => $request->validated('donated_at'),
            'note' => $request->input('note'),
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'currency' => strtolower((string) config('services.stripe.currency', 'usd')),
            'donor_covers_fees' => false,
            'status' => 'succeeded',
            'idempotency_key' => 'offline_' . Str::uuid(),
        ]);   // masjid_id stamped by BelongsToMasjid

        return response()->json([
            'status' => 'success',
            'data' => $donation->load(['fund', 'contact']),
        ], Response::HTTP_CREATED);
    }

    /**
     * Show a single donation with its fund and issued receipt eager-loaded.
     * Read-only: donations are created and advanced ONLY by Stripe webhooks,
     * never through the admin API. findOrFail is tenant-scoped, so another
     * masjid's id resolves to a 404 rather than leaking the row.
     */
    public function show($masjid_id, $donation_id)
    {
        $donation = Donation::with(['fund', 'receipt', 'contact'])->findOrFail($donation_id);

        return response()->json([
            'status' => 'success',
            'data' => $donation,
        ], Response::HTTP_OK);
    }
}
