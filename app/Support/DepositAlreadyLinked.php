<?php

namespace App\Support;

use RuntimeException;

/** A re-enrolment deposit already exists for this child and campaign; answered as a plain 409. */
final class DepositAlreadyLinked extends RuntimeException
{
}
