<?php

namespace App\Services\Member;

use App\Models\Contact;

/**
 * Who holds an address as a member's sign-in address, in this organisation: nobody, exactly one
 * contact, or several. Three answers, because "nobody" and "several" are opposite cases that a
 * nullable contact cannot tell apart: nobody may become a new member, and several must not, or
 * the person who proves the mailbox is given a NEW empty contact while their own record, with
 * its history, sits beside it (MemberSignupService::holdersOf()).
 */
final class AddressHolders
{
    /**
     * @param  list<int>  $contactIds  every live contact that holds the address the way the match was made
     */
    private function __construct(
        public readonly ?Contact $contact,
        public readonly array $contactIds,
    ) {
    }

    public static function none(): self
    {
        return new self(null, []);
    }

    public static function one(Contact $contact): self
    {
        return new self($contact, [(int) $contact->getKey()]);
    }

    /** @param  list<int>  $contactIds */
    public static function several(array $contactIds): self
    {
        return new self(null, array_values(array_map('intval', $contactIds)));
    }

    public function isNone(): bool
    {
        return $this->contactIds === [];
    }

    public function isOne(): bool
    {
        return $this->contact !== null;
    }

    public function isSeveral(): bool
    {
        return count($this->contactIds) > 1;
    }
}
