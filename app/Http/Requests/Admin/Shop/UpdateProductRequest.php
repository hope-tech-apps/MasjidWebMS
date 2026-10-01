<?php

namespace App\Http\Requests\Admin\Shop;

/**
 * Edit a product and, when `variants` is sent, its sizes as a list (shop slice B2). Every product
 * field is optional (a key left out is left alone); `variants` is the product's WHOLE set of
 * sizes, keyed by `id` for the ones that exist: see ProductWriter.
 *
 * `lock_version` is REQUIRED: the version of the product the editor read (every product answer carries
 * it). A save that names another is refused under the product's lock with a 409 (ProductWriter), so
 * an editor opened before somebody else's save cannot put back what they changed. A missing or
 * non-integer one is a 422, so a client that forgot to send it fails loudly instead of overwriting.
 */
class UpdateProductRequest extends ProductFormRequest
{
    /** `products.lock_version` is an unsigned int. */
    private const VERSION_MAX = 4294967295;

    public function rules(): array
    {
        return $this->productRules(false) + [
            'lock_version' => ['required', 'integer:strict', 'min:0', 'max:' . self::VERSION_MAX],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'lock_version.required' => 'Say which version of the product you are changing (lock_version, from the product you loaded).',
            'lock_version.integer' => 'The lock_version must be the whole number the product carried when you loaded it.',
        ];
    }

    /** The version of the product the editor read. */
    public function lockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }
}
