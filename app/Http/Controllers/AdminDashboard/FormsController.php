<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Forms\StoreFormRequest;
use App\Http\Requests\Admin\Forms\UpdateFormRequest;
use App\Models\Masjid;
use App\Models\Offering;
use App\Support\Errors;
use App\Support\FormSchema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-up form management for one masjid.
 *
 * Tenancy is hand-scoped through `Masjid::findOrFail($masjid_id)` → `$masjid->forms()`,
 * exactly as PagesController and SectionsController do — Form deliberately does not use
 * the BelongsToMasjid trait so it stays consistent with the rest of this family. Every
 * lookup goes through the relation, so a masjid admin cannot reach another tenant's form
 * by guessing an id.
 */
class FormsController extends Controller
{
    /** GET /api/admin/masjids/{masjid_id}/forms */
    public function index($masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $forms = $masjid->forms()
                ->orderBy('created_at', 'desc')
                ->paginate(15)
                ->through(fn ($form) => $this->serialize($form));

            return response()->json([
                'status' => 'success',
                'data' => $forms,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/forms/options
     *
     * The lightweight list the Form Responses screen uses to populate its form picker.
     * Declared before /{form_id} so "options" is not captured as an id.
     */
    public function options($masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $forms = $masjid->forms()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'is_active', 'response_count'])
                ->map(fn ($form) => [
                    'id' => $form->id,
                    'name' => $form->name,
                    'slug' => $form->slug,
                    'is_active' => $form->is_active,
                    'response_count' => $form->response_count,
                ]);

            return response()->json([
                'status' => 'success',
                'data' => $forms,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/forms/field-types
     *
     * The builder's palette. Served from the same constant the submission validator
     * uses, so the editor can never offer a type the server would reject.
     */
    public function fieldTypes($masjid_id)
    {
        try {
            Masjid::findOrFail($masjid_id);

            $labels = [
                'text' => 'Short text',
                'email' => 'Email address',
                'tel' => 'Phone number',
                'number' => 'Number',
                'date' => 'Date',
                'textarea' => 'Long text',
                'select' => 'Dropdown',
                'radio' => 'Choose one',
                'checkbox' => 'Single checkbox',
                'checkboxGroup' => 'Choose any',
                'file' => 'File upload',
            ];

            $types = collect(FormSchema::FIELD_TYPES)->map(fn ($type) => [
                'value' => $type,
                'label' => $labels[$type] ?? $type,
                'has_options' => in_array($type, ['select', 'radio', 'checkboxGroup'], true),
                // The builder has to know what an upload field may accept, and the
                // server is the only authority on it — these come straight from
                // config('forms.attachments'), the same values the submit endpoint
                // enforces, so the two cannot drift apart.
                'upload' => $type === 'file' ? [
                    'mime_types' => array_values((array) config('forms.attachments.mime_types', [])),
                    'max_size_kb' => (int) config('forms.attachments.max_size_kb', 0),
                    // A file question would need one upload per row inside a
                    // repeatable section, which the submit payload has no shape for.
                    'allowed_in_repeatable' => false,
                ] : null,
            ])->values();

            return response()->json([
                'status' => 'success',
                'data' => $types,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** POST /api/admin/masjids/{masjid_id}/forms */
    public function store(StoreFormRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $form = $masjid->forms()->create($request->safe()->all());

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($form),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** GET /api/admin/masjids/{masjid_id}/forms/{form_id} */
    public function show($masjid_id, $form_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($form),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** PUT /api/admin/masjids/{masjid_id}/forms/{form_id} */
    public function update(UpdateFormRequest $request, $masjid_id, $form_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);

            $form->update($request->safe()->all());

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($form->fresh()),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /api/admin/masjids/{masjid_id}/forms/{form_id}
     *
     * Soft delete. Responses are left intact and reachable by restoring the form — a
     * mis-click must not destroy a registration list. The form_responses FK cascades on
     * a hard delete only, which nothing in the admin surface performs.
     *
     * REFUSED while this form is the intake form of a live offering.
     *
     * `offerings.intake_form_id` is NOT NULL, and RegistrationService::register throws
     * offeringClosed() the moment it cannot load the form — so soft-deleting it stops
     * every registration for that program instantly. Measured end to end before this
     * guard:
     *
     *     DELETE .../forms/{intake_form_id}   -> 200 "Form deleted successfully"
     *     PUBLIC  registration_state = closed, intake_form = null
     *     ADMIN   /offerings -> is_open=true, closed_reason=null  (a green "Open")
     *
     * An administrator tidying up an old form silently closed a program while every
     * admin screen went on saying it was open — this codebase's recurring shape, a
     * write that fails while the UI reports success.
     *
     * REFUSING rather than allowing-and-warning, for three reasons:
     *
     *  1. There is no legal end state. The column is NOT NULL and there is no "this
     *     offering has no intake form" the product can represent, so the delete leaves
     *     a required reference dangling — a state the schema says cannot exist, with no
     *     admin surface that can restore a soft-deleted form to repair it.
     *  2. The precedent is one file away and it is the same act one level down.
     *     OfferingsController::destroy refuses to delete an offering that holds live
     *     registrations and points at the non-destructive switch instead; deleting the
     *     form underneath it strands exactly the same people.
     *  3. There is a non-destructive path that does what the admin actually wanted:
     *     set `is_active = false`, which stops standalone submissions to the form and
     *     does NOT break offering registration (register() loads the form to validate
     *     against, and never consults its `is_active`). Or re-point the offering at a
     *     different form first, and then delete this one.
     *
     * The loud admin state is built anyway and is NOT redundant: rows broken before
     * this guard existed are still out there, and an offering can be pointed at an
     * already-deleted form id at creation time. See App\Support\OfferingRegistrationState
     * and OfferingsController's `registration_state`.
     *
     * The scoped findOrFail runs OUTSIDE the try, so a cross-tenant / missing id
     * surfaces as a clean 404 instead of being swallowed into a 500 by the catch.
     */
    public function destroy($masjid_id, $form_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $form = $masjid->forms()->findOrFail($form_id);

        // Explicit masjid filter and an explicit scope bypass: this must not depend on
        // whether TenantContext happens to be bound on this request, because a query
        // that quietly returns nothing here would let the delete through
        // (.claude/rules/tenant-scoping.md — an unbound scope adds NO filter).
        // Soft-deleted offerings do not block: nothing can register for one.
        $blocking = Offering::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->where('intake_form_id', $form->id)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($blocking !== []) {
            $names = implode(', ', array_slice($blocking, 0, 5))
                . (count($blocking) > 5 ? ', and ' . (count($blocking) - 5) . ' more' : '');

            $message = 'This form is the sign-up form for ' . $names . '. Deleting it would stop '
                . (count($blocking) === 1 ? 'that program' : 'those programs')
                . ' accepting registrations immediately, with no way to sign anyone up. '
                . 'Switch the form off instead (that leaves registrations working), or point '
                . (count($blocking) === 1 ? 'it' : 'them') . ' at another form first.';

            // `status: failed` + `data` mirrors OfferingsController::destroy, which is
            // the same refusal for the same reason; `message` is this controller
            // family's own error key, so a client reading either convention sees it.
            return response()->json([
                'status' => 'failed',
                'message' => $message,
                'data' => $message,
                'offerings' => $blocking,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $form->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Form deleted successfully',
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The admin-facing shape of a form. Mirrors PageSectionsController::serializeSection
     * — a hand-built array rather than a Resource, matching this controller family.
     */
    private function serialize($form): array
    {
        return [
            'id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'name' => $form->name,
            'slug' => $form->slug,
            'description' => $form->description,
            'schema' => $form->schema,
            'settings' => $form->settings,
            'is_active' => $form->is_active,
            'opens_at' => optional($form->opens_at)->toIso8601String(),
            'closes_at' => optional($form->closes_at)->toIso8601String(),
            'capacity' => $form->capacity,
            'response_count' => $form->response_count,
            'accepting' => $form->acceptsSubmissions(),
            'closed_reason' => $form->closedReason(),
            'created_at' => optional($form->created_at)->toIso8601String(),
            'updated_at' => optional($form->updated_at)->toIso8601String(),
        ];
    }
}
