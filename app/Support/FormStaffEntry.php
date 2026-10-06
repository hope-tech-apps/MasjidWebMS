<?php

namespace App\Support;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class FormStaffEntry
{
    // The owner may disable complimentary entries without changing settlement or routing.
    public static function allowsComplimentaryCash(): bool
    {
        return true;
    }

    public static function read(Request $request, ?FormStaffCode $code): array
    {
        $route = $request->input('staff_pay_with');
        $unit = $request->input('staff_unit_price_minor');
        $route = $route === '' ? null : $route;
        $unit = $unit === '' ? null : $unit;

        if ($code === null && ($route !== null || $unit !== null)) {
            throw ValidationException::withMessages(['staff_code' => 'A valid staff credential is required for staff payment controls.']);
        }

        if ($route !== null && ! in_array($route, ['cash', 'card'], true)) {
            throw ValidationException::withMessages(['staff_pay_with' => 'Choose cash or card.']);
        }

        if ($unit !== null) {
            $digits = is_int($unit) && $unit >= 0 ? (string) $unit : (is_string($unit) ? $unit : null);
            if ($digits === null || ! preg_match('/^[0-9]+\z/', $digits)
                || strlen(ltrim($digits, '0')) > 8 || (int) $digits > FormPayment::MAX_CHARGE_MINOR) {
                throw ValidationException::withMessages(['staff_unit_price_minor' => 'Enter a whole price in minor units.']);
            }
            $unit = (int) $digits;
        }

        if ($code !== null && $route !== null && $request->input('pay_with') !== null && $request->input('pay_with') !== $route) {
            throw ValidationException::withMessages(['staff_pay_with' => 'The payment choices disagree.']);
        }

        return ['route' => $route ?? 'cash', 'unit' => $unit, 'explicit_route' => $route !== null];
    }

    public static function fingerprint(array $fields, bool $coverFees): array
    {
        $fingerprint = [];
        if ($fields['explicit_route']) {
            $fingerprint['staff_pay_with'] = $fields['route'];
        }
        if ($fields['unit'] !== null) {
            $fingerprint['staff_unit_price_minor'] = $fields['unit'];
        }
        if ($fields['route'] === 'card') {
            // A retry is compared with the submitted choice, never today's fee settings.
            $fingerprint['fee_covered'] = $coverFees;
        }

        return $fingerprint;
    }

    public static function quote(Form $form, array $clean, array $fields, bool $coverFees): ?array
    {
        if (! $form->takesStaffCodes()) {
            throw ValidationException::withMessages(['staff_code' => FormStaffCodes::REFUSED]);
        }
        if ($fields['route'] === 'card' && ! $form->takesOnlinePayment()) {
            throw ValidationException::withMessages(['staff_pay_with' => 'This form does not take card payment.']);
        }
        $at = now();
        $list = FormPayment::quote($form, $clean, false, false, $at);
        if ($list === null) {
            return null;
        }
        $unit = $fields['unit'];
        if ($unit !== null && (! $form->allowsStaffPriceOverride() || $unit > $list['unit_minor'])) {
            throw ValidationException::withMessages(['staff_unit_price_minor' => 'The staff price must be enabled and cannot exceed the list price.']);
        }
        if ($unit === 0 && ($fields['route'] !== 'cash' || ! self::allowsComplimentaryCash() || $list['unit_minor'] <= 0 || $list['quantity'] <= 0)) {
            throw ValidationException::withMessages(['staff_unit_price_minor' => 'A complimentary entry can only be recorded as cash.']);
        }

        return FormPayment::quote($form, $clean, $coverFees, $fields['route'] === 'card', $at, $unit);
    }

    public static function isComplimentaryCash(FormResponse $row): bool
    {
        return self::allowsComplimentaryCash()
            && $row->staff_payment_method === 'cash'
            && $row->staff_unit_price_minor === 0
            && $row->unit_price_minor === 0
            && $row->list_unit_price_minor > 0
            && $row->price_quantity > 0
            && $row->amount_due_minor === 0;
    }
}
