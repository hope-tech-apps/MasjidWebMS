<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;
use App\Models\GroupResource;
use Illuminate\Validation\Rule;

/**
 * Uploading one file for a class.
 *
 * `mimetypes:` matches the SNIFFED type, never the client's extension and never
 * the Content-Type header the browser sent — both are attacker-controlled. The
 * allowlist and the size ceiling come from `groups.resources.*`, which is its
 * own config block on purpose: `groups.media.*` is the children's photo feed and
 * widening that to carry documents would widen the photo feed too.
 *
 * `visibility` is validated but the DEFAULT is the model's, which is `staff`. A
 * payload that omits it produces a private file, never a published one.
 *
 * `recipient_membership_ids` names the STUDENTS a targeted file is addressed to,
 * and the rules here are SHAPE ONLY — integers, and the invariant that the field
 * and the `students` visibility imply each other in both directions. WHICH ids
 * are acceptable is not a question this class may answer: an id is only valid if
 * it is a current participant row of THIS class, and only the controller holds
 * the group. `ResourcesController::resolveRecipients()` is that check and it is
 * the guarantee — the same division `GroupMembershipsController` makes for
 * duplicate participants. Do not move it here with a scoped `exists` rule that
 * reads `masjid_id` off the route: the route parameter is the caller's.
 */
class StoreGroupResourceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $mimeTypes = (array) config('groups.resources.mime_types', []);
        $maxKb = (int) config('groups.resources.max_size_kb', 8192);

        $file = ['required', 'file'];

        // An empty allowlist would make `mimetypes:` match nothing and reject
        // every upload — the safe direction, but an unhelpful one to debug — so
        // it is only applied when something is actually configured.
        if ($mimeTypes !== []) {
            $file[] = 'mimetypes:' . implode(',', $mimeTypes);
        }

        if ($maxKb > 0) {
            $file[] = 'max:' . $maxKb;
        }

        return [
            'file' => $file,
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['nullable', Rule::in(GroupResource::VISIBILITIES)],

            // BOTH directions, so neither half can arrive alone. A `students`
            // file with no names would be an empty audience dressed up as a
            // share, and a list of names on a `families` file would be an
            // audience the row does not record and nothing reads — either one
            // is a payload whose stated audience is not the audience it gets.
            'recipient_membership_ids' => [
                'array',
                'max:500',
                Rule::requiredIf(fn (): bool => $this->input('visibility') === GroupResource::VISIBILITY_STUDENTS),
                Rule::prohibitedIf(fn (): bool => $this->input('visibility') !== GroupResource::VISIBILITY_STUDENTS),
            ],
            'recipient_membership_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimetypes' => 'That file type is not accepted. Use a PDF, a Word document, or a JPEG or PNG image.',
            'file.max' => 'That file is too large.',
            'recipient_membership_ids.required' => 'Choose at least one student for this file.',
            'recipient_membership_ids.prohibited' => 'Students can only be named when the file is for specific students.',
        ];
    }
}
