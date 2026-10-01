<?php

namespace App\Http\Requests\Admin\Shop;

/**
 * Edit a product and, when `variants` is sent, its sizes as a list (shop slice B2). Every product
 * field is optional (a key left out is left alone); `variants` is the product's WHOLE set of
 * sizes, keyed by `id` for the ones that exist: see ProductWriter.
 */
class UpdateProductRequest extends ProductFormRequest
{
    public function rules(): array
    {
        return $this->productRules(false);
    }
}
