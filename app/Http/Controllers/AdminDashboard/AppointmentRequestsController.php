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
     * The columns the QUEUE reads.
     *
     * date_of_birth and reason are deliberately absent: they are the
     * health-adjacent fields, and a triage list renders fifteen people at a time
     * on a front-desk screen. Leaving them out of the SELECT means the
     * ciphertext is never even fetched, let alone decrypted into a JSON payload
     * — least disclosure applies to what we ASK the database for, not only to
     * what the template renders (the same reasoning as the per-tab fetches in
     * GroupDetailView). They are served by show(), which is one deliberate
     * click on one person. ip_address / user_agent are abuse-investigation
     * metadata and are not part of triage either.
     */
    private const LIST_COLUMNS = [
        'id',
        'masjid_id',
        'applicant_name',
        'phone',
        'email',
        'preferred_window',
        'status',
        'source',
        'created_at',
        'updated_at',
    ];

    /** Hard ceiling on ?per_page= so a client cannot ask for the whole table. */
    private const MAX_PER_PAGE = 100;

    /**
     * Paginated triage queue, optionally narrowed by ?status= and ?search=, and
     * ordered by when it was submitted (?sort=newest|oldest, newest default).
     *
     * Every one of the three is a READ FILTER, so an unrecognized value degrades
     * to a sensible read rather than a 422: an unknown status yields an empty
     * page, an unknown sort falls back to newest-first. Rule::in gating belongs
     * on the write path (UpdateAppointmentRequestStatusRequest), not here.
     *
     * SEARCH IS RESTRICTED TO THE UNENCRYPTED COLUMNS ON PURPOSE. applicant_name
     * / phone / email are stored in plaintext precisely so staff can find and
     * call a person; date_of_birth and reason are ciphertext and a LIKE against
     * them can only ever match nothing (or, worse, invite someone to "fix" that
     * by decrypting the column). See .claude/rules/appointments.md.
     */
    public function index(Request $request, $masjid_id)
    {
        $search = $this->stringQuery($request, 'search');
        $status = $this->stringQuery($request, 'status');
        // Oldest-first is what a clinic working a backlog asks for: whoever has
        // been waiting longest. Anything else, including nothing, is newest.
        $direction = $this->stringQuery($request, 'sort') === 'oldest' ? 'asc' : 'desc';

        $requests = AppointmentRequest::query()
            ->select(self::LIST_COLUMNS)
            ->withCount('notes')
            ->when($status !== '', fn ($q) => $q->ofStatus($status))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                // Grouped so the OR set cannot escape an active status filter.
                // % and _ are escaped because the term is user input: an
                // unescaped '%' would silently return the tenant's ENTIRE queue
                // to someone who typed one character.
                $term = '%' . addcslashes($search, '%_\\') . '%';

                $q->where('applicant_name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term);
            }))
            ->orderBy('created_at', $direction)
            // Ties broken by id so a page boundary can never repeat or drop a
            // row when several requests land in the same second.
            ->orderBy('id', $direction)
            ->paginate($this->perPage($request));

        return response()->json([
            'status' => 'success',
            'data' => $requests,
            // Counts describe the WHOLE queue, not the filtered page: they are
            // what the filter tabs are labelled with, so they must not change
            // when a filter is applied.
            'meta' => $this->meta() + ['status_counts' => $this->statusCounts()],
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

    /**
     * How many requests sit in each status for this tenant.
     *
     * One grouped aggregate rather than four counts, tenant-scoped by the
     * BelongsToMasjid global scope like every other query here. Keyed over
     * STATUSES with a 0 default so the SPA can render a tab per status without
     * knowing whether any row uses it yet; a row carrying a status outside the
     * vocabulary (only possible if one is retired) is left out of the buckets
     * rather than inventing a label for it — the paginator's total stays the
     * authority on how many rows exist.
     */
    private function statusCounts(): array
    {
        $counts = AppointmentRequest::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(AppointmentRequest::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    /**
     * One query parameter, read as a trimmed STRING or not at all.
     *
     * `?search[]=x` arrives as an array: casting one to string is a PHP warning,
     * which Laravel's error handler raises as an ErrorException, which this
     * app's JSON renderer returns as a 500 — and binding one into `where` is a
     * database error with the same ending. A malformed FILTER must never be able
     * to take the triage queue down; it degrades to "no filter".
     */
    private function stringQuery(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Page size: the client's ?per_page= clamped to 1..MAX_PER_PAGE.
     *
     * Unclamped, `?per_page=100000` is one request that pulls the tenant's
     * entire intake history — a slow query and a far larger disclosure than a
     * queue page, from an endpoint whose whole point is that it holds patient
     * records. A non-numeric value falls back to the default rather than 0.
     */
    private function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', 15);

        return max(1, min($requested ?: 15, self::MAX_PER_PAGE));
    }
}
