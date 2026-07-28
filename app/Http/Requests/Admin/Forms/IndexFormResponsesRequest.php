<?php

namespace App\Http\Requests\Admin\Forms;

use App\Http\Requests\BaseFormRequest;
use App\Models\FormResponse;
use Illuminate\Validation\Rule;

/**
 * Query parameters for the Form Responses list.
 *
 * `sort` is validated against an allowlist rather than passed through: it is
 * interpolated into an ORDER BY, so anything not on this list must never reach the
 * query builder. The allowlist is exactly the set of real, indexed columns — sorting on
 * a JSON path inside `data` is deliberately not offered, because it would not be
 * portable between MySQL in production and SQLite in the test suite.
 */
class IndexFormResponsesRequest extends BaseFormRequest
{
    public const SORTABLE = [
        'submitted_at',
        'respondent_name',
        'respondent_email',
        'status',
        'entry_count',
        'amount_due',
    ];

    public function rules(): array
    {
        return [
            'q' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(FormResponse::STATUSES)],
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ];
    }

    public function sortColumn(): string
    {
        return $this->input('sort', 'submitted_at');
    }

    public function sortDirection(): string
    {
        return $this->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
