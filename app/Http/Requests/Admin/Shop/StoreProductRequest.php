<?php

namespace App\Http\Requests\Admin\Shop;

/**
 * Create a product, optionally with its sizes in the same request (shop slice B2). The name and
 * the price are required; the slug is generated from the name by ProductWriter, never sent.
 */
class StoreProductRequest extends ProductFormRequest
{
    public function rules(): array
    {
        return $this->productRules(true);
    }
}
