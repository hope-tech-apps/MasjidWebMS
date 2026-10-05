<?php

namespace App\Services\Member;

use App\Models\Contact;
use InvalidArgumentException;

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

    /**
     * Two or more, or it is not several. A list of one (or none) with no contact would be none of
     * isNone(), isOne() and isSeveral(), and a caller that then read `->contact` as null would take
     * its "nobody holds this" branch and create a member: the very thing this class exists to stop.
     *
     * @param  list<int>  $contactIds
     *
     * @throws InvalidArgumentException when fewer than two distinct contacts are named
     */
    public static function several(array $contactIds): self
    {
        $ids = array_values(array_unique(array_map('intval', $contactIds)));

        if (count($ids) < 2) {
            throw new InvalidArgumentException('AddressHolders::several() needs at least two distinct contacts; use one() or none().');
        }

        return new self(null, $ids);
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
