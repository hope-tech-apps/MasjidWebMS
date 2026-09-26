<?php

namespace App\Support;

use App\Models\Form;

/**
 * The prices a form priced by a quantity question or by answer (Ramadan giving,
 * 2026-09-25) shows on its public page, written into the wording of its own questions.
 *
 * Why here and not in the renderer: the public renderer draws a price only from a unit
 * x rows total (payment.unitMinor) or a flat `fee.amount`, and these forms publish
 * neither (SectionContentBinder::publicPayment()/publicFee(), so no wrong total is
 * drawn). Without this nothing on the page says what anything costs: the iftar levels
 * read "Quarter Iftar" and Zakat-ul-Fitr asks only "Number of people". Written from the
 * same fee rule the server charges by, so the page can never show one price and charge
 * another, and an organisation that changes a price changes it in one place.
 *
 *   by choice     each priced option of the choice question: "Quarter Iftar ($450.00)",
 *                 "Individual Iftar ($18.00 each)" for a level charged per unit
 *   per quantity  the quantity question's help line starts "$17.00 each."
 *
 * Only the published copy of the schema is changed. The stored schema, the answers and
 * the labels on receipts (Form::optionLabel()) are the organisation's own words.
 *
 * The live "$17.00 x 4 = $68.00" is the renderer's (burlington-masjid-site branch
 * feat/form-quantity-display), from choicePrices and unitMinorEach. It draws these labels
 * as they are and adds no price of its own, so each price appears once: change the
 * wording here and the page follows.
 *
 * Pinned by tests/Feature/FormQuantityPaymentTest.php and FormDateReservationTest.php.
 */
final class FormPriceLabels
{
    /**
     * @param  mixed  $schema  the schema about to be published (FormOptionSources::schema())
     * @return mixed the same schema, with the prices written in
     */
    public static function apply(Form $form, mixed $schema): mixed
    {
        if (! is_array($schema) || ! is_array($schema['sections'] ?? null) || ! $form->pricesByQuantityOrChoice()) {
            return $schema;
        }

        $fee = $form->feeRule();
        $currency = is_string($fee['currency'] ?? null) ? $fee['currency'] : 'USD';
        $byChoice = ($fee['pricing'] ?? null) === Form::PRICING_CHOICE;
        $money = fn (float|int $amount): string => FormNotifier::money(FormPayment::toMinor($amount), $currency);

        $prices = [];

        foreach ($byChoice ? $fee['choicePrices'] : [] as $price) {
            $prices[$price['value']] = $price;
        }

        foreach ($schema['sections'] as $s => $section) {
            if (! is_array($section) || ! empty($section['repeatable']) || ! is_array($section['fields'] ?? null)) {
                continue;
            }

            foreach ($section['fields'] as $f => $field) {
                if (! is_array($field) || ! is_string($field['name'] ?? null)) {
                    continue;
                }

                if ($byChoice && $field['name'] === $fee['choiceField'] && is_array($field['options'] ?? null)) {
                    foreach ($field['options'] as $o => $option) {
                        $price = is_array($option) && is_string($option['value'] ?? null) ? ($prices[$option['value']] ?? null) : null;

                        if ($price === null) {
                            continue;
                        }

                        $label = is_string($option['label'] ?? null) && trim($option['label']) !== '' ? trim($option['label']) : $price['value'];
                        $schema['sections'][$s]['fields'][$f]['options'][$o]['label'] =
                            $label . ' (' . $money($price['amount']) . ($price['perQuantity'] ? ' each' : '') . ')';
                    }
                }

                if (! $byChoice && $field['name'] === $fee['perQuantityOf']) {
                    $help = is_string($field['help'] ?? null) ? trim($field['help']) : '';
                    $schema['sections'][$s]['fields'][$f]['help'] = trim($money($fee['amount']) . ' each. ' . $help);
                }
            }
        }

        return $schema;
    }
}
