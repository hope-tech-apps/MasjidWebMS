<?php

namespace App\Support;

use App\Models\FormStaffCode;

/**
 * What checking a staff credential came to (App\Support\FormStaffCodes): accepted,
 * with the live code; refused; or locked out, with how many seconds to wait. Three
 * outcomes, which a nullable return could not tell apart — and a lockout must NOT
 * read as a refusal, or a staff member typing the right code would be told it is
 * wrong.
 */
final class StaffCodeCheck
{
    private function __construct(
        public readonly ?FormStaffCode $code,
        public readonly ?int $retryAfter,
    ) {
    }

    public static function accepted(FormStaffCode $code): self
    {
        return new self($code, null);
    }

    public static function refused(): self
    {
        return new self(null, null);
    }

    public static function lockedOut(int $retryAfterSeconds): self
    {
        return new self(null, max(1, $retryAfterSeconds));
    }

    public function isAccepted(): bool
    {
        return $this->code !== null;
    }

    public function isLockedOut(): bool
    {
        return $this->retryAfter !== null;
    }
}
