<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\PaymentMethods;
use Illuminate\Database\Eloquent\Model;

/**
 * One payment method an organisation ACCEPTS, with its own "how to pay" text.
 *
 * A row means accepted; no row means not. Tenant-scoped like every CRM model
 * (BelongsToMasjid): the admin screen reads and writes it through the bound
 * tenant, and the public read (AcceptedPaymentMethods) filters masjid_id by hand
 * because /api/v1 runs unbound. Cross-tenant proof:
 * tests/Feature/OrganisationPaymentMethodsTest.php.
 */
class OrganisationPaymentMethod extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'method',
        'label',
        'instructions',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** The organisation's own label, else the vocabulary's. */
    public function displayLabel(): string
    {
        $label = trim((string) $this->label);

        return $label !== '' ? $label : (PaymentMethods::LABELS[$this->method] ?? (string) $this->method);
    }
}
