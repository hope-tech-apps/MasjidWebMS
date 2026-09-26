<?php

namespace App\Support;

use RuntimeException;

/**
 * The unique index on form_date_reservations refused a hold: another payer holds the
 * date (FormReservations::hold()). Thrown inside the submit's transaction so the
 * response written beside it rolls back, and answered as the 422 the check under the
 * form lock gives, never as a 500.
 */
final class FormDateTaken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(FormReservations::TAKEN);
    }
}
