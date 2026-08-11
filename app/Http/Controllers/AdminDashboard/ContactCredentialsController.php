<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Credentials\IndexContactCredentialsRequest;
use App\Http\Requests\Admin\Credentials\StoreContactCredentialRequest;
use App\Http\Requests\Admin\Credentials\UpdateContactCredentialRequest;
use App\Models\Contact;
use App\Models\ContactCredential;
use App\Support\CredentialDocuments;
use App\Support\Errors;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for a contact's credentials — the licenses, background checks and
 * certifications a Community org (pilot: a free clinic) tracks on its volunteer
 * providers (T-023). Replaces the spreadsheet, so the expiring-soon filter is
 * the feature: ?expiring_within_days=N is the renewal chase list.
 *
 * Tenant isolation is NOT hand-rolled here. The route keeps the
 * /masjids/{masjid_id}/... prefix by convention, but the `tenant` middleware
 * binds TenantContext and BelongsToMasjid auto-scopes Contact and
 * ContactCredential alike — so every lookup below walks the OWNERSHIP CHAIN
 * (scoped contact, then the credential THROUGH that contact) and a foreign id
 * anywhere in it is a 404 miss, never a filtered row. We never touch
 * $masjid_id. See .claude/rules/tenant-scoping.md.
 *
 * The scanned document follows .claude/rules/private-uploads.md: private disk,
 * random name, and the ONLY way to the bytes is downloadDocument() below, which
 * runs behind auth:sanctum + admin + tenant + crm + `permission:view contacts`.
 */
class ContactCredentialsController extends Controller
{
    /**
     * This contact's credentials, soonest expiry first (non-expiring last),
     * optionally narrowed to ?expiring_within_days=N.
     *
     * Not paginated on purpose: one person holds a handful of credentials, and
     * a coordinator reads them as a single card — mirrors the group roster.
     */
    public function index(IndexContactCredentialsRequest $request, $masjid_id, $contact_id)
    {
        $contact = Contact::findOrFail($contact_id);

        $days = $request->expiringWithinDays();

        $credentials = $contact->credentials()
            ->when($days !== null, fn ($q) => $q->expiringWithin($days))
            // `expires_at IS NULL` is portable ordering (MySQL + SQLite): the
            // deadlines an admin can still act on come first.
            ->orderByRaw('(expires_at IS NULL) ASC')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ContactCredential $credential) => $this->serialize($credential));

        return response()->json([
            'status' => 'success',
            'data' => $credentials,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * Store a credential on this contact. masjid_id and contact_id are both
     * server-derived — the scoped $contact supplies the parent and the
     * BelongsToMasjid creating hook stamps the tenant — so a payload can never
     * plant a credential on someone else's record.
     */
    public function store(StoreContactCredentialRequest $request, $masjid_id, $contact_id)
    {
        // Outside the try: a foreign/missing contact must surface as 404.
        $contact = Contact::findOrFail($contact_id);

        try {
            // Row + bytes are all-or-nothing: if the document write fails the
            // transaction takes the row with it, and CredentialDocuments has
            // already removed anything it put on disk.
            $credential = DB::transaction(function () use ($request, $contact) {
                $credential = $contact->credentials()->create(
                    $request->safe()->except(['document'])
                );

                if ($request->hasFile('document')) {
                    CredentialDocuments::attach($credential, $request->file('document'));
                }

                return $credential;
            });

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($credential),
                'meta' => $this->meta(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show one credential, resolved through its contact so a foreign id in
     * either position is a 404.
     */
    public function show($masjid_id, $contact_id, $credential_id)
    {
        $contact = Contact::findOrFail($contact_id);
        $credential = $contact->credentials()->findOrFail($credential_id);

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($credential),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * Update a credential. A `document` in the payload REPLACES the stored
     * scan; the old bytes are removed only after the row durably points at the
     * new ones (CredentialDocuments::attach owns that ordering).
     *
     * The scoped findOrFail chain runs OUTSIDE the try so a cross-tenant or
     * missing id surfaces as a clean 404 instead of being swallowed into a 500.
     */
    public function update(UpdateContactCredentialRequest $request, $masjid_id, $contact_id, $credential_id)
    {
        $contact = Contact::findOrFail($contact_id);
        $credential = $contact->credentials()->findOrFail($credential_id);

        try {
            $credential->update($request->safe()->except(['document']));

            if ($request->hasFile('document')) {
                CredentialDocuments::attach($credential, $request->file('document'));
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($credential),
                'meta' => $this->meta(),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a credential. The model's `deleting` hook removes the document
     * bytes from the private disk — deliberately the model layer, not a DB
     * cascade, which fires no events and would orphan the file forever
     * (.claude/rules/private-uploads.md).
     */
    public function destroy($masjid_id, $contact_id, $credential_id)
    {
        $contact = Contact::findOrFail($contact_id);
        $credential = $contact->credentials()->findOrFail($credential_id);

        $credential->delete();

        return response()->json([
            'status' => 'success',
            'data' => $credential,
        ], Response::HTTP_OK);
    }

    /**
     * Stream the scanned document.
     *
     * No try/catch here at all: the whole point is that each link of
     * masjid -> contact -> credential is re-resolved through its parent, so a
     * foreign id anywhere in the chain is a scoped MISS the JSON renderer turns
     * into a clean 404 — not a filter, and never a 500. (The masjid link is the
     * `tenant` middleware itself: a foreign {masjid_id} in the route is already
     * a 403 before this method runs.)
     */
    public function downloadDocument($masjid_id, $contact_id, $credential_id)
    {
        $contact = Contact::findOrFail($contact_id);
        $credential = $contact->credentials()->findOrFail($credential_id);

        if (! $credential->documentExists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This credential has no stored document.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $credential->documentStorage()->download(
            $credential->document_path,
            $credential->document_original_name,
            [
                // Sniffed from the bytes at upload and constrained to the
                // configured allowlist, so it is ours to state. Attachment
                // disposition (set by download()) plus the global nosniff
                // header keeps a document from ever being rendered inline.
                'Content-Type' => $credential->document_mime_type,
                // Private and uncached: an intermediate proxy must never hold
                // one org's license scan and hand it to the next admin who
                // asks for the URL.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * One credential as the admin surface sees it. The raw document_* columns
     * are hidden on the model; what ships is a single `document` object whose
     * only link points back at the authenticated download endpoint — never a
     * public URL (.claude/rules/private-uploads.md).
     *
     * @return array<string,mixed>
     */
    private function serialize(ContactCredential $credential): array
    {
        $data = $credential->toArray();

        $document = $credential->documentAdminArray();

        $data['document'] = $document === null ? null : array_merge($document, [
            'download_url' => "/api/admin/masjids/{$credential->masjid_id}"
                . "/contacts/{$credential->contact_id}"
                . "/credentials/{$credential->id}/document",
        ]);

        return $data;
    }

    /**
     * The vocabulary the SPA needs to render a credential form without
     * hardcoding it: the kind constants, the derived statuses, and the default
     * expiring window the status accessor uses.
     */
    private function meta(): array
    {
        return [
            'kinds' => ContactCredential::KINDS,
            'statuses' => ContactCredential::STATUSES,
            'expiring_within_days' => ContactCredential::expiringThresholdDays(),
        ];
    }
}
