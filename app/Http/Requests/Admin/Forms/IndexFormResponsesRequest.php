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
 *
 * The door's filters (DECISIONS.md 2026-09-11) narrow the list, the CSV, the roster and
 * the cash totals alike, because all four read FormResponsesController::query():
 * `payment` (paid | unpaid | settled, or one method), `collected` (yes | no) and
 * `staff_code_id` (one holder's cash).
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

    /**
     * paid / unpaid / settled read the way FormResponse::isSettled() does, so a row with
     * no money leg on a form that charges is unpaid, never free; the rest are a method.
     */
    public const PAYMENT_FILTERS = [
        'paid',
        'unpaid',
        'settled',
        FormResponse::METHOD_CASH,
        FormResponse::METHOD_ONLINE,
        FormResponse::METHOD_EXTERNAL,
    ];

    public const COLLECTED_FILTERS = ['yes', 'no'];

    public function rules(): array
    {
        return [
            'q' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(FormResponse::STATUSES)],
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'payment' => ['nullable', Rule::in(self::PAYMENT_FILTERS)],
            'collected' => ['nullable', Rule::in(self::COLLECTED_FILTERS)],
            'staff_code_id' => 'nullable|integer|min:1',
            // The roster sorts a flattened PHP collection, so it may order by any
            // attendee column — but those keys never reach SQL. The LIST route does reach
            // ORDER BY, so there it stays strictly allowlisted. A safe-identifier regex
            // still rejects things like "id;drop table forms" on both.
            'sort' => $this->isRoster()
                ? ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/']
                : ['nullable', Rule::in(self::SORTABLE)],
            'direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ];
    }

    /** Whether this request is for the attendee roster rather than the submission list. */
    public function isRoster(): bool
    {
        return str_contains((string) $this->path(), '/roster');
    }

    /**
     * The column for the SQL ORDER BY. Interpolated into a query, so it is re-checked
     * against the allowlist here even though validation already ran — a roster request
     * may legitimately carry an attendee column name, and that must never reach SQL.
     */
    public function sortColumn(): string
    {
        $sort = (string) $this->input('sort', 'submitted_at');

        return in_array($sort, self::SORTABLE, true) ? $sort : 'submitted_at';
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
