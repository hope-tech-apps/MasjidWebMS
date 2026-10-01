<?php

namespace App\Http\Requests\Admin\Shop;

use App\Http\Requests\BaseFormRequest;
use App\Models\ProductSale;
use Illuminate\Validation\Rule;

/**
 * What the office did about a sale (shop slice B2, the critic's fix round): `refunded` (the money
 * went back, so there is nothing to hand out) or `substituted` (a replacement is handed over).
 * Naming the word is required, so a client cannot clear a resolution by sending nothing: the
 * DELETE is the way back.
 */
class ResolveShopSaleRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', Rule::in(ProductSale::RESOLUTIONS)],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution.required' => 'Say what was done about this sale: ' . implode(' or ', ProductSale::RESOLUTIONS) . '.',
            'resolution.in' => 'A sale is resolved as ' . implode(' or ', ProductSale::RESOLUTIONS) . '.',
        ];
    }

    public function resolution(): string
    {
        return (string) $this->validated('resolution');
    }
}
