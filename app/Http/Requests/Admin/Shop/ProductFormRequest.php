<?php

namespace App\Http\Requests\Admin\Shop;

use App\Http\Requests\BaseFormRequest;
use App\Services\Shop\LiveText;
use App\Services\Shop\ProductWriter;
use App\Support\FormPayment;
use Closure;
use Illuminate\Contracts\Validation\Validator;

/**
 * The boundary rules a product and its sizes share, for the create and the update (shop slice B2).
 *
 * Money is integer MINOR units, `integer:strict`: a JSON number, never a string, a float or a
 * boolean (a price typed as 25.5 or "2500" is refused rather than coerced). Bounds: a price is at
 * least 1 and at most FormPayment::MAX_CHARGE_MINOR, the most one card payment can take, which is
 * the same ceiling CartCheckoutService refuses a basket total at.
 *
 * The widths are the columns': NAME_MAX, CATEGORY_MAX and LABEL_MAX equal `products.name`,
 * `products.category` and `product_variants.label` in the shop migration, and
 * tests/Feature/Shop/ShopProductValidationTest reads the migration to prove it (SQLite ignores
 * a varchar's length, so only that test can see the two drift).
 *
 * What is never read from the body, whatever it says: `masjid_id`, `slug` (generated once, at
 * creation), `currency` beyond the refusal below, `sold_count` and a size's `product_id`.
 * ProductWriter builds each attribute list field by field, so an extra key is ignored, not saved.
 */
abstract class ProductFormRequest extends BaseFormRequest
{
    public const NAME_MAX = 120;

    public const CATEGORY_MAX = 60;

    public const DESCRIPTION_MAX = 5000;

    public const LABEL_MAX = 40;

    /** A uniform shop has a dozen sizes; this is a sanity ceiling on one request, not a business rule. */
    public const MAX_VARIANTS = 50;

    /** `product_variants.stock` is an unsigned int. */
    public const STOCK_MAX = 4294967295;

    /** `products.sort` and `product_variants.sort` are signed ints. */
    public const SORT_MIN = -2147483648;

    public const SORT_MAX = 2147483647;

    /**
     * @return array<string,mixed>
     */
    protected function productRules(bool $creating): array
    {
        $presence = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => array_merge($presence, ['string', 'max:' . self::NAME_MAX]),
            'category' => ['nullable', 'string', 'max:' . self::CATEGORY_MAX],
            'description' => ['nullable', 'string', 'max:' . self::DESCRIPTION_MAX],
            'base_price_minor' => array_merge($presence, $this->priceRules()),
            // Never stored from here: the product is sold in the platform's currency. A request that
            // NAMES another one is refused rather than quietly overridden, so a client that believes
            // it is selling in pounds finds out (ASSUMPTIONS S-9).
            'currency' => ['bail', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (strtolower(trim((string) $value)) !== ProductWriter::currency()) {
                    $fail('Products are sold in ' . strtoupper(ProductWriter::currency()) . ' only.');
                }
            }],
            'active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer:strict', 'between:' . self::SORT_MIN . ',' . self::SORT_MAX],

            'variants' => ['sometimes', 'array', 'max:' . self::MAX_VARIANTS],
            // A new product has no sizes to edit, so a row naming one is refused here; on an update the
            // writer decides whether the id is one of THIS product's live sizes (404 if not).
            'variants.*.id' => $creating
                ? ['prohibited']
                : ['bail', 'nullable', 'integer:strict', 'min:1', 'distinct'],
            // Two sizes may not share a label: checked below in withValidator(), the way the unique index
            // compares (case AND accent blind, LiveText::fold), because MySQL's collation is not byte-exact.
            'variants.*.label' => ['bail', 'required', 'string', 'max:' . self::LABEL_MAX],
            'variants.*.enabled' => ['sometimes', 'boolean'],
            'variants.*.price_minor' => array_merge(['nullable'], $this->priceRules()),
            'variants.*.stock' => ['nullable', 'integer:strict', 'min:0', 'max:' . self::STOCK_MAX],
            'variants.*.sort' => ['sometimes', 'integer:strict', 'between:' . self::SORT_MIN . ',' . self::SORT_MAX],
        ];
    }

    /**
     * "Two sizes cannot share a name": after every other rule has passed, so a label that is not a
     * string never reaches the comparison. Compared as production's `utf8mb4_unicode_ci` unique index
     * compares them (LiveText::fold: M and m, Medium and Médium, Strasse and Straße are one label),
     * because SQLite is byte-exact and a text that passes here and then meets that index is a 500, not
     * a sentence.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $seen = [];

            foreach ((array) $this->input('variants', []) as $index => $row) {
                $label = is_array($row) ? ($row['label'] ?? null) : null;

                if (! is_string($label) || $label === '') {
                    continue;
                }

                $key = LiveText::fold($label);

                if (isset($seen[$key])) {
                    $validator->errors()->add("variants.{$index}.label", 'Two sizes cannot share a name.');
                }

                $seen[$key] = true;
            }
        });
    }

    /** @return list<string> */
    private function priceRules(): array
    {
        return ['integer:strict', 'min:1', 'max:' . FormPayment::MAX_CHARGE_MINOR];
    }

    public function messages(): array
    {
        $ceiling = number_format(FormPayment::MAX_CHARGE_MINOR);

        return [
            'name.required' => 'A product needs a name.',
            'name.max' => 'A product name can be at most ' . self::NAME_MAX . ' characters.',
            'category.max' => 'A category can be at most ' . self::CATEGORY_MAX . ' characters.',
            'base_price_minor.required' => 'A product needs a price.',
            'base_price_minor.integer' => 'The price must be a whole number of cents.',
            'base_price_minor.min' => 'A product must cost at least 1 cent.',
            'base_price_minor.max' => "A price cannot be more than {$ceiling} cents, the most one card payment can take.",
            'variants.*.id.distinct' => 'A size is listed twice.',
            'variants.*.id.prohibited' => 'A new product has no sizes to edit yet; leave the id out.',
            'variants.*.label.required' => 'Every size needs a name.',
            'variants.*.label.max' => 'A size name can be at most ' . self::LABEL_MAX . ' characters.',
            'variants.*.price_minor.integer' => 'A size price must be a whole number of cents.',
            'variants.*.price_minor.min' => 'A size must cost at least 1 cent; leave the price out to use the product price.',
            'variants.*.price_minor.max' => "A price cannot be more than {$ceiling} cents, the most one card payment can take.",
            'variants.*.stock.integer' => 'Stock must be a whole number, or empty for unlimited.',
            'variants.*.stock.min' => 'Stock cannot be negative.',
            'variants.*.stock.max' => 'That is more stock than can be recorded.',
        ];
    }

    /**
     * The product's own fields, without the sizes: what ProductWriter takes as `$attributes`.
     *
     * @return array<string,mixed>
     */
    public function productAttributes(): array
    {
        $validated = $this->validated();
        unset($validated['variants'], $validated['currency'], $validated['lock_version']);

        return $validated;
    }

    /**
     * The list of sizes when the request carries one, null when it does not (null leaves the
     * product's sizes alone; an empty list takes them all away).
     *
     * @return list<array<string,mixed>>|null
     */
    public function variantRows(): ?array
    {
        $validated = $this->validated();

        if (! array_key_exists('variants', $validated)) {
            return null;
        }

        // validated() rebuilds the list one RULE at a time (every `id`, then every `label`, ...), so a row
        // with no `id` comes back after the rows that have one. The order the caller sent is the order
        // that decides a new size's place in the list, so it is restored from the raw input's keys.
        $rows = [];

        foreach (array_keys((array) $this->input('variants', [])) as $key) {
            if (isset($validated['variants'][$key])) {
                $rows[] = $validated['variants'][$key];
            }
        }

        return $rows;
    }
}
