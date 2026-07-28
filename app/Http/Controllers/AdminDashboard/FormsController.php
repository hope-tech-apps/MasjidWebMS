<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Forms\StoreFormRequest;
use App\Http\Requests\Admin\Forms\UpdateFormRequest;
use App\Models\Masjid;
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
            ];

            $types = collect(FormSchema::FIELD_TYPES)->map(fn ($type) => [
                'value' => $type,
                'label' => $labels[$type] ?? $type,
                'has_options' => in_array($type, ['select', 'radio', 'checkboxGroup'], true),
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
     */
    public function destroy($masjid_id, $form_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);

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
