<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Forms\IndexFormResponsesRequest;
use App\Http\Requests\Admin\Forms\UpdateFormResponseRequest;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Support\Errors;
use App\Support\FormSchema;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "Form Responses" screen's API: who filled out a given form.
 *
 * Search, filtering and sorting are all applied in SQL rather than in the client,
 * because every list in this app is server-paginated — sorting a page in the browser
 * would silently sort 25 of 300 rows and look like it worked.
 *
 * These rows are PII. Nothing here is reachable without auth + tenant scoping, and the
 * form is always resolved through `$masjid->forms()` so one masjid cannot read another's
 * registrations by guessing a form id.
 */
class FormResponsesController extends Controller
{
    /**
     * GET /api/admin/masjids/{masjid_id}/forms/{form_id}/responses
     *
     * Supports ?q= (name/email/phone), ?status=, ?from=&to=, ?sort=&direction=, ?per_page=.
     */
    public function index(IndexFormResponsesRequest $request, $masjid_id, $form_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);

            $responses = $this->query($request, $masjid, $form)
                ->paginate($request->perPage())
                ->withQueryString()
                ->through(fn (FormResponse $response) => $this->serialize($response));

            return response()->json([
                'status' => 'success',
                'data' => $responses,
                'meta' => [
                    'form' => [
                        'id' => $form->id,
                        'name' => $form->name,
                        'response_count' => $form->response_count,
                        'capacity' => $form->capacity,
                    ],
                    // The builder's column definitions, so the table can render a column
                    // per question without the SPA having to re-parse the schema.
                    'columns' => $this->columns($form),
                    'statuses' => FormResponse::STATUSES,
                    'sortable' => IndexFormResponsesRequest::SORTABLE,
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** GET /api/admin/masjids/{masjid_id}/forms/{form_id}/responses/{response_id} */
    public function show($masjid_id, $form_id, $response_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);
            $response = $form->responses()->findOrFail($response_id);

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($response, withData: true),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** PUT /api/admin/masjids/{masjid_id}/forms/{form_id}/responses/{response_id} */
    public function update(UpdateFormResponseRequest $request, $masjid_id, $form_id, $response_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);
            $response = $form->responses()->findOrFail($response_id);

            $response->update($request->safe()->all());

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($response->fresh(), withData: true),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** DELETE /api/admin/masjids/{masjid_id}/forms/{form_id}/responses/{response_id} */
    public function destroy($masjid_id, $form_id, $response_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);
            $response = $form->responses()->findOrFail($response_id);

            $response->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Response deleted successfully',
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/forms/{form_id}/responses/export
     *
     * Streams a CSV of the CURRENT filter selection — what the admin sees is what they
     * get. Declared before /{response_id} so "export" is not captured as an id.
     */
    public function export(IndexFormResponsesRequest $request, $masjid_id, $form_id): StreamedResponse
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $form = $masjid->forms()->findOrFail($form_id);

        $columns = $this->columns($form);
        $filename = $form->slug . '-responses-' . now()->format('Y-m-d') . '.csv';

        $query = $this->query($request, $masjid, $form);

        return response()->stream(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');

            $header = ['Submitted', 'Status', 'Entries', 'Amount due'];
            foreach ($columns as $column) {
                $header[] = $column['label'];
            }
            fputcsv($out, $header);

            // chunkById keeps memory flat on a large registration list.
            $query->chunkById(200, function ($chunk) use ($out, $columns) {
                foreach ($chunk as $response) {
                    $row = [
                        optional($response->submitted_at)->format('Y-m-d H:i'),
                        $response->status,
                        $response->entry_count,
                        $response->amount_due,
                    ];

                    foreach ($columns as $column) {
                        $row[] = $this->csvCell($this->valueFor($response, $column));
                    }

                    fputcsv($out, $row);
                }
            });

            fclose($out);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ------------------------------------------------------------------ internals

    /** The shared query behind both the list and the export, so they can never disagree. */
    private function query(IndexFormResponsesRequest $request, Masjid $masjid, Form $form)
    {
        return $form->responses()
            // Redundant with the relation, but keeps the tenant predicate on every row
            // read and lets the (masjid_id, form_id, submitted_at) index do the work.
            ->where('masjid_id', $masjid->id)
            ->search($request->input('q'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('submitted_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('submitted_at', '<=', $request->date('to')))
            // Column comes from an allowlist in the request class; never interpolated raw.
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', 'desc');
    }

    /**
     * One column per question in the schema, flattening a repeatable section to a single
     * summarised column (the admin table cannot grow a column per attendee).
     *
     * @return array<int,array{key:string,label:string,section:?string,repeatable:bool,field:string}>
     */
    private function columns(Form $form): array
    {
        $columns = [];

        foreach ($form->sections() as $section) {
            $sectionId = $section['id'] ?? null;
            $repeatable = ! empty($section['repeatable']);

            foreach ($section['fields'] ?? [] as $field) {
                if (! is_array($field) || ! isset($field['name'])) {
                    continue;
                }

                $columns[] = [
                    'key' => $repeatable ? $sectionId . '.' . $field['name'] : $field['name'],
                    'label' => $field['label'] ?? $field['name'],
                    'section' => $repeatable ? ($section['title'] ?? $sectionId) : null,
                    'repeatable' => $repeatable,
                    'field' => $field['name'],
                ];
            }
        }

        return $columns;
    }

    /** Read one column's value out of a response, joining repeatable rows. */
    private function valueFor(FormResponse $response, array $column): string
    {
        $data = $response->data ?? [];

        if ($column['repeatable']) {
            $sectionId = explode('.', $column['key'])[0];
            $rows = $data[$sectionId] ?? [];

            if (! is_array($rows)) {
                return '';
            }

            return collect($rows)
                ->map(fn ($row) => is_array($row) ? ($row[$column['field']] ?? '') : '')
                ->filter(fn ($v) => $v !== '' && $v !== null)
                ->map(fn ($v) => $this->stringify($v))
                ->implode(' | ');
        }

        return $this->stringify($data[$column['field']] ?? '');
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Neutralise spreadsheet formula injection.
     *
     * Excel and Sheets evaluate a cell beginning with =, +, - or @ as a formula, so a
     * registrant could put `=HYPERLINK(...)` in a name field and have it execute when a
     * volunteer opens the export. Prefixing with an apostrophe makes it literal text.
     */
    private function csvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * List rows stay light: the full submission is only sent for a single response.
     * `data` on every row of a 300-row page would be megabytes of PII in one payload.
     */
    private function serialize(FormResponse $response, bool $withData = false): array
    {
        $row = [
            'id' => $response->id,
            'form_id' => $response->form_id,
            'respondent_name' => $response->respondent_name,
            'respondent_email' => $response->respondent_email,
            'respondent_phone' => $response->respondent_phone,
            'entry_count' => $response->entry_count,
            'amount_due' => $response->amount_due,
            'status' => $response->status,
            'admin_notes' => $response->admin_notes,
            'submitted_at' => optional($response->submitted_at)->toIso8601String(),
        ];

        if ($withData) {
            $row['data'] = $response->data;
        }

        return $row;
    }
}
