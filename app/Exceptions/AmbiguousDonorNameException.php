<?php

namespace App\Exceptions;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Two or more contacts in one masjid genuinely share the typed name.
 *
 * Raised by DonorContactService::findOrCreateByName() instead of picking one.
 * A gift is money attributed to a person: attributing it by whichever row
 * sorted first would put one household's cheque on another household's
 * year-end statement, and nothing downstream would ever flag it. The admin has
 * the donor list on screen and can say which one they mean.
 *
 * Deliberately NOT an HTTP exception: the service does not know it is being
 * called from a controller. The caller renders it.
 */
class AmbiguousDonorNameException extends RuntimeException
{
    /** @param Collection<int,\App\Models\Contact> $candidates */
    public function __construct(
        public readonly string $name,
        public readonly Collection $candidates,
    ) {
        parent::__construct('More than one contact is named "'.$name.'".');
    }

    public function publicMessage(): string
    {
        $who = $this->candidates
            ->map(fn ($c) => trim(($c->first_name ?? '').' '.($c->last_name ?? '')).' (#'.$c->id.')')
            ->implode(', ');

        return 'More than one contact is named "'.$this->name.'" — '.$who.
            '. Pick the right one from the donor list so the gift is attributed to the correct person.';
    }
}
