<?php

namespace App\Http\Requests\Admin\Shop;

use App\Http\Requests\BaseFormRequest;
use App\Models\Product;

/**
 * Put a product's pictures in a new order (shop slice B2): `order` is the picture ids, first to
 * last. Whether they are THIS product's pictures, and all of them, is decided against the product's
 * own pictures in ShopProductImagesController (another product's or another organisation's id is a
 * 404, a missing one a 422); this only holds the shape.
 */
class ReorderProductImagesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1', 'max:' . Product::MAX_IMAGES],
            'order.*' => ['bail', 'required', 'integer:strict', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'order.required' => 'Say the order of the pictures.',
            'order.*.distinct' => 'A picture is listed twice.',
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map('intval', $this->validated('order')));
    }
}
