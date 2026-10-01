<?php

namespace App\Http\Requests\Admin\Shop;

use App\Http\Requests\BaseFormRequest;
use App\Services\Shop\PickupList;
use Illuminate\Validation\Rule;

/**
 * The filters of the pickup list and of its CSV (shop slice B2): one contract for both, so what the
 * office downloads is what it was looking at.
 *
 * Validated, not coerced: a mistyped `state` must be a 422 rather than quietly become "everything",
 * which would hand out the list of a refunded sale. An empty value (`?search=`, a cleared select) is
 * "no filter". `product_id` and `variant_id` name sales by the ids their snapshot carries, so a
 * deleted product or size is still filterable; an id that is not this organisation's simply matches
 * nothing, as the tenant scope sees to.
 */
class IndexShopSalesRequest extends BaseFormRequest
{
    public const PER_PAGE = 25;

    public const PER_PAGE_MAX = 100;

    public function rules(): array
    {
        return [
            'state' => ['nullable', Rule::in(PickupList::STATES)],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'variant_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::PER_PAGE_MAX],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'state.in' => 'The state is one of ' . implode(', ', PickupList::STATES) . '.',
        ];
    }

    /**
     * What PickupList::query() takes. The default state is the one the office works from: what is
     * still to hand out.
     *
     * @return array{state: string, product_id: ?int, variant_id: ?int, search: ?string}
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';

        return [
            'state' => $validated['state'] ?? PickupList::STATE_TO_HAND_OUT,
            'product_id' => isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            'variant_id' => isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
            'search' => $search === '' ? null : $search,
        ];
    }

    public function perPage(): int
    {
        $validated = $this->validated();

        return isset($validated['per_page']) ? (int) $validated['per_page'] : self::PER_PAGE;
    }
}
