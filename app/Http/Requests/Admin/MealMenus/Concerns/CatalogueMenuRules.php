<?php

namespace App\Http\Requests\Admin\MealMenus\Concerns;

use App\Models\MealMenu;
use Closure;

/**
 * The fields only a standing catalogue (the kitchen) uses, validated the same way
 * on create and on update.
 *
 *  - `pickup_lead_hours`: whole hours, at most 30 days — a lead time longer than
 *    the whole booking window (MealMenu::MAX_PICKUP_DAYS_AHEAD) would admit no
 *    pickup at all.
 *  - `notify_emails`: extra addresses an ADMIN types for new orders, each one
 *    checked here, so a typo is refused on save rather than discovered when the
 *    first order reaches nobody extra. The organisation's own address is always
 *    told as well (KitchenOrderNotifier::officeRecipients), and a lunch
 *    volunteer's value is dropped (MealMenusController::withoutAdminOnly).
 */
trait CatalogueMenuRules
{
    /** @return array<string, mixed> */
    protected function catalogueRules(): array
    {
        return [
            'pickup_lead_hours' => 'nullable|integer|min:0|max:720',
            'notify_emails' => [
                'nullable', 'string', 'max:1000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $typed = array_filter(preg_split('/[,;\s]+/', (string) $value) ?: [], fn ($e) => $e !== '');

                    foreach ($typed as $email) {
                        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                            $fail("\"{$email}\" is not an email address.");

                            return;
                        }
                    }
                },
            ],
        ];
    }
}
