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
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimetypes' => 'That file type is not accepted. Use a PDF, a Word document, or a JPEG or PNG image.',
            'file.max' => 'That file is too large.',
        ];
    }
}
