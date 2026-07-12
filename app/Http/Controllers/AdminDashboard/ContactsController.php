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
                        ->orWhere('phone', 'like', "%{$search}%");
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
        $contact = Contact::findOrFail($contact_id);

        return response()->json([
            'status' => 'success',
            'data' => $contact
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
