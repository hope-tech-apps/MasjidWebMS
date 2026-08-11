<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Forms\SubmitFormResponseRequest;
use App\Models\Form;
use App\Models\FormResponse;
use App\Support\Errors;
use App\Support\FormAttachments;
use App\Support\FormNotifier;
use App\Support\FormSchema;
use Illuminate\Support\Facades\DB;

/**
 * The public form-submission endpoint.
 *
 * This is the only unauthenticated write in the forms feature, so it is written
 * defensively:
 *
 *  - The masjid comes from the `masjid-id` header AND the form must belong to it. It
 *    does NOT use Section::scopeFilterByMasjid, which no-ops when the header is absent
 *    and would let a caller submit against any tenant's form.
 *  - Validation is derived from the stored schema (App\Support\FormSchema), never from
 *    the payload. The renderer validating client-side is a convenience; this decides.
 *  - Anything the schema does not declare is dropped before storage, so a caller cannot
 *    inject content that later renders in the admin table or reaches the assistant.
 *  - The window and capacity are re-checked here, inside the same transaction that
 *    writes the row, because a page can sit open in a tab long after a form closed.
 *  - A honeypot field catches the naive bots; `throttle:form-submit` catches the rest.
 *  - Uploads (a `file` question — the Schools careers form's résumé) arrive in their
 *    own `files` bag and are type/size-checked at the boundary by
 *    SubmitFormResponseRequest before anything here runs. They are written to a
 *    PRIVATE disk inside the same transaction as the response, so a submission that
 *    loses the race for the last place leaves no bytes behind.
 *
 * Once a submission is accepted it also notifies the masjid's coordinators and sends the
 * submitter a receipt (App\Support\FormNotifier) — a registration nobody is told about is
 * only half-accepted.
 */
class FormSubmissionsController extends Controller
{
    /**
     * POST /api/v1/forms/{form_id}/responses
     */
    public function store(SubmitFormResponseRequest $request, $form_id)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');

            if ($masjidId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            $form = Form::query()
                ->where('masjid_id', $masjidId)
                ->whereKey($form_id)
                ->first();

            // Same response whether the form does not exist, belongs to another masjid,
            // or is soft-deleted — no probing for which forms exist where.
            if (! $form) {
                return response()->api(404, 'This form is not available.', null);
            }

            if (! $form->is_active) {
                return response()->api(422, 'This form is not currently accepting responses.', null);
            }

            // A bot filling every input trips this; a human never sees the field.
            if (filled($request->input('website'))) {
                // Report success so a scripted submitter gets no signal to adapt to,
                // while nothing is written.
                return response()->api(200, 'Thank you — your response has been received.', [
                    'id' => null,
                ]);
            }

            $submitted = $request->input('data');

            if (! is_array($submitted)) {
                return response()->api(422, 'No form data was submitted.', null);
            }

            $schema = FormSchema::for($form);

            // Only the uploads this form actually asked for; anything else in the
            // bag is dropped, exactly as undeclared answers are. Empty for every
            // form without a `file` question, which leaves the rest untouched.
            $uploads = $schema->uploads($request->file(FormSchema::UPLOAD_KEY));

            // Validated together with the answers so a missing REQUIRED résumé
            // reports under its own field name, alongside every other error, in one
            // field bag — rather than as a second, differently shaped rejection.
            $validator = $schema->validator(array_merge($submitted, $uploads));

            if ($validator->fails()) {
                // Field bag under `data`, matching how the SPA and the Nuxt site already
                // read 422s from BaseFormRequest.
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors(),
                ], 422);
            }

            $clean = $schema->only($submitted);

            $response = DB::transaction(function () use ($form, $clean, $schema, $request, $masjidId, $uploads) {
                // Re-read inside the transaction and lock, so two submissions racing for
                // the last place cannot both pass the capacity check. The counter is the
                // thing capacity is enforced against, so it must be read under the lock
                // that the insert will update.
                $locked = Form::whereKey($form->id)->lockForUpdate()->first();

                if (! $locked || ! $locked->acceptsSubmissions()) {
                    return null;
                }

                $created = FormResponse::create(array_merge(
                    [
                        'form_id' => $locked->id,
                        'masjid_id' => $masjidId,
                        'data' => $clean,
                        'entry_count' => $schema->entryCount($clean),
                        'amount_due' => $schema->amountDue($clean),
                        'status' => 'new',
                        'device_id' => $request->input('device_id'),
                        'ip_address' => $request->ip(),
                        'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                        'submitted_at' => now(),
                    ],
                    $schema->identity($clean)
                ));

                // Inside the transaction, and only once the row exists: a submission
                // that arrives after the last place is taken returns above without
                // ever touching the disk, and a write that fails here rolls the
                // response back (FormAttachments removes what it had written).
                $names = FormAttachments::store($created, $uploads);

                if ($names !== []) {
                    // The respondent's filenames go back into `data` under their
                    // field names, so the admin table and the CSV export have a cell
                    // to render — the bytes stay on the private disk, reachable only
                    // through the authenticated download endpoint.
                    $created->update(['data' => array_merge($clean, $names)]);
                }

                return $created;
            });

            if ($response === null) {
                // Closed or full between the page loading and the submit landing.
                return response()->api(422, $form->fresh()->closedReason() ?? 'This form is no longer accepting responses.', null);
            }

            // Deliberately after the transaction: the registration is already committed, so
            // a mail failure can neither roll it back nor reach the submitter. FormNotifier
            // swallows its own errors for the same reason.
            FormNotifier::submitted($form, $response);

            $settings = $form->settings ?? [];

            return response()->api(200, 'Thank you — your response has been received.', [
                'id' => $response->id,
                'amount_due' => $response->amount_due,
                'entry_count' => $response->entry_count,
                'success_title' => $settings['successTitle'] ?? null,
                'success_body' => $settings['successBody'] ?? null,
                'success_next_steps' => $settings['successNextSteps'] ?? [],
            ]);
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }
}
