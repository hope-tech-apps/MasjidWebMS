<?php

namespace App\Services\FamilyLedger;

use App\Models\OrganisationBillingSetting;

/** Missing configuration is read-only; enabling a grant never creates billing data. */
final class TuitionSettings
{
    public static function for(int $school): OrganisationBillingSetting
    {
        return OrganisationBillingSetting::where('masjid_id', $school)->first() ?? new OrganisationBillingSetting(['masjid_id' => $school, 'currency' => strtolower(config('services.stripe.currency', 'usd')), 'minimum_payment_minor' => 0]);
    }
}
