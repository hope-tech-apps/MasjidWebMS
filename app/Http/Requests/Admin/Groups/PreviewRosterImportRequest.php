<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * The file envelope only: CSV/XLSX structure and encoding are checked by the
 * bounded reader. MIME sniffing varies between spreadsheet exporters. The
 * optional mapping is JSON text because the SPA sends multipart FormData.
 */
class PreviewRosterImportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048'],
            'mapping' => ['sometimes', 'string', 'max:8192'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose the roster file you want to preview.',
            'file.max' => 'That file is larger than 2 MB. Upload a smaller CSV or .xlsx file.',
        ];
    }
}
