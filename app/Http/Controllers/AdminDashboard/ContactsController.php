<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contacts\StoreContactRequest;
use App\Http\Requests\Admin\Contacts\UpdateContactRequest;
use App\Models\Contact;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Member directory — admin CRUD for CRM Contact (congregant) records.
 *
 * Tenant isolation is NOT enforced here by hand. The route keeps the
 * /masjids/{masjid_id}/... prefix by convention, but the `tenant`
 * middleware (ResolveMasjidTenant) binds TenantContext and the
 * App\Models\Concerns\BelongsToMasjid trait auto-scopes every Contact query
 * to the bound masjid — so we deliberately never filter by $masjid_id and
 * never set masjid_id from client input. See .claude/rules/tenant-scoping.md.
 */
class ContactsController extends Controller
{
    /**
     * Paginated list of the current masjid's contacts, optionally filtered by a
     * free-text ?search= over first name / last name / email / phone.
     */
    public function index(Request $request, $masjid_id)
    {
        $search = $request->query('search');

        $contacts = Contact::query()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        // Full name, so "Ahmad Fais" (first + last together) matches.
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                        ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$search}%"]);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $contacts
        ], Response::HTTP_OK);
    }

    /**
     * Store a new contact. masjid_id is intentionally omitted: the
     * BelongsToMasjid creating hook stamps it from the bound tenant, so a
     * client-supplied masjid_id can never plant a row in another masjid.
     */
    public function store(StoreContactRequest $request, $masjid_id)
    {
        try {
            $contact = Contact::create($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $contact
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show a single contact. findOrFail is tenant-scoped, so another masjid's id
     * resolves to a 404 rather than leaking the row.
     */
    public function show($masjid_id, $contact_id)
    {
        // Card last-4 + giving history, newest gift first (by real gift date).
        $contact = Contact::with([
            'cards',
            'donations' => fn ($q) => $q->with('fund')->orderByRaw('COALESCE(donated_at, created_at) DESC'),
        ])->findOrFail($contact_id);

        $data = $contact->toArray();
        $data['giving_total'] = (int) $contact->donations
            ->where('status', 'succeeded')->sum('charged_amount');

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ], Response::HTTP_OK);
    }

    /**
     * Merge a contact (typically a placeholder "Unidentified Card ####") into
     * another member — existing (target_contact_id) or newly created. Moves the
     * source's donations and card last-4 onto the target, then removes the source.
     *
     * All queries run in the bound-tenant context, so BelongsToMasjid scopes every
     * move to this masjid — a merge can't reach across tenants.
     */
    public function merge(\App\Http\Requests\Admin\Contacts\MergeContactRequest $request, $masjid_id, $contact_id)
    {
        $source = Contact::findOrFail($contact_id);   // tenant-scoped

        $target = $request->filled('target_contact_id')
            ? Contact::findOrFail($request->integer('target_contact_id'))
            : Contact::create($request->only(['first_name', 'last_name', 'email', 'phone']));

        if ($target->id === $source->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'A contact cannot be merged into itself.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($source, $target) {
            \App\Models\Donation::where('contact_id', $source->id)
                ->update(['contact_id' => $target->id]);

            foreach ($source->cards as $card) {
                \App\Models\ContactCard::firstOrCreate(
                    ['contact_id' => $target->id, 'last4' => $card->last4],
                    ['masjid_id' => $target->masjid_id],
                );
            }
            $source->cards()->delete();
            $source->forceDelete();   // the placeholder is fully absorbed
        });

        return response()->json([
            'status' => 'success',
            'data' => $target->fresh(['cards']),
        ], Response::HTTP_OK);
    }

    /**
     * Update a contact. The scoped findOrFail runs OUTSIDE the try so a
     * cross-tenant / missing id surfaces as a clean 404 instead of being
     * swallowed into a 500 by the catch below.
     */
    public function update(UpdateContactRequest $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        try {
            $contact->update($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $contact
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Soft-delete a contact (the model uses SoftDeletes) so a mis-click on a
     * congregant record is recoverable. Scoped findOrFail → 404 cross-tenant.
     */
    public function destroy($masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);
        $contact->delete();

        return response()->json([
            'status' => 'success',
            'data' => $contact
        ], Response::HTTP_OK);
    }
}
