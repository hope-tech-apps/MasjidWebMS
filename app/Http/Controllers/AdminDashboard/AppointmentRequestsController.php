<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentRequests\StoreAppointmentRequestNoteRequest;
use App\Http\Requests\Admin\AppointmentRequests\UpdateAppointmentRequestStatusRequest;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestNote;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin triage for appointment requests (PLAN T-021, Community vertical): the
 * queue the free clinic's staff work instead of a Gmail inbox. List, read one
 * (with its internal notes), move it through triage, add a note.
 *
 * Tenant isolation is NOT hand-rolled here. The route keeps the
 * /masjids/{masjid_id}/... prefix by convention, but the `tenant` middleware
 * binds TenantContext and BelongsToMasjid auto-scopes every query — so we never
 * filter by $masjid_id and never set masjid_id from client input (the creating
 * hook stamps it). See .claude/rules/tenant-scoping.md.
 *
 * Authorization reuses the CONTACTS permissions (view/manage), the same
 * precedent as groups: these requests are people records in the member
 * directory's trust domain, and minting `view/manage appointments` would change
 * the seeded permission set that RolePermissionBridgeTest pins. See
 * .claude/rules/groups.md and routes/admin.php.
 *
 * NO PHI IN LOGS — date_of_birth, reason and note bodies are encrypted at rest
 * and must never reach Log::* on any path here. The catches below report via
 * Errors::publicMessage, which logs exception metadata only, never input.
 */
class AppointmentRequestsController extends Controller
{
    /**
     * Paginated triage queue, newest first, optionally narrowed by ?status=.
     * An unrecognized status filter yields an empty page rather than an error —
     * it is a filter, not a write, so Rule::in gating would be noise.
     */
    public function index(Request $request, $masjid_id)
    {
        $requests = AppointmentRequest::query()
            ->withCount('notes')
            ->when($request->filled('status'), fn ($q) => $q->ofStatus($request->query('status')))
            ->orderByDesc('created_at')
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $requests,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * One request with its internal notes. findOrFail is tenant-scoped, so
     * another organization's id resolves to a 404 rather than leaking the row.
     */
    public function show($masjid_id, $appointment_request_id)
    {
        $appointmentRequest = AppointmentRequest::with([
            // Only what the note list renders about its author — never the
            // whole user row alongside a patient record.
            'notes' => fn ($q) => $q->with('author:id,name,email')->latest(),
        ])->findOrFail($appointment_request_id);

        return response()->json([
            'status' => 'success',
            'data' => $appointmentRequest,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * Move a request through triage. The scoped findOrFail runs OUTSIDE the try
     * so a cross-tenant / missing id surfaces as a clean 404 instead of being
     * swallowed into a 500 by the catch below.
     */
    public function updateStatus(UpdateAppointmentRequestStatusRequest $request, $masjid_id, $appointment_request_id)
    {
        $appointmentRequest = AppointmentRequest::findOrFail($appointment_request_id);

        try {
            $appointmentRequest->update(['status' => $request->validated('status')]);

            return response()->json([
                'status' => 'success',
                'data' => $appointmentRequest,
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
     * Add an internal note. The author is the authenticated admin and the
     * tenant is stamped by the BelongsToMasjid creating hook — neither is
     * accepted from the client.
     */
    public function storeNote(StoreAppointmentRequestNoteRequest $request, $masjid_id, $appointment_request_id)
    {
        // Scoped parent lookup first (outside the try): the note may only ever
        // hang off a request this tenant can see.
        $appointmentRequest = AppointmentRequest::findOrFail($appointment_request_id);

        try {
            $note = AppointmentRequestNote::create([
                // Denormalised from the parent, never from the client. On this
                // route a tenant is always bound and the creating hook stamps
                // the same value; carrying it from the parent keeps any future
                // unbound (system) caller correct too.
                'masjid_id' => $appointmentRequest->masjid_id,
                'appointment_request_id' => $appointmentRequest->id,
                'user_id' => $request->user()->id,
                'body' => $request->validated('body'),
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $note,
                'meta' => $this->meta(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** The status vocabulary, so the SPA never hardcodes the triage set. */
    private function meta(): array
    {
        return [
            'statuses' => AppointmentRequest::STATUSES,
        ];
    }
}
