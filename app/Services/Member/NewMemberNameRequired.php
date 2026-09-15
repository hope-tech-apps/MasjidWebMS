<?php

namespace App\Services\Member;

use RuntimeException;

/**
 * A CORRECT sign-in code for an address that has no account here, sent with a
 * blank first or last name.
 *
 * Every other verify failure is a silent null from MemberSignupService::redeem()
 * and one 410 at the door. This one is different because it is reachable only
 * after the code has matched and won the consume gate: the caller has proven the
 * mailbox is theirs, so "this address has no account here yet" is news only to
 * the person who owns it. Refusing it with a 410, as the service did until
 * 2026-09-15, also burned the code, and a new member who typed one name was told
 * to ask for a fresh code.
 *
 * Thrown from INSIDE the consume transaction, so the `consumed_at` the gate wrote
 * is rolled back and the same code works once the name is sent.
 */
final class NewMemberNameRequired extends RuntimeException
{
    /** The sentence the apps show above the name fields. */
    public const MESSAGE = 'Enter your first and last name to create your account.';

    /** Per field, in the BaseFormRequest `data` shape both apps already read. */
    public const FIELD_MESSAGES = [
        'first_name' => 'Enter your first name.',
        'last_name' => 'Enter your last name.',
    ];

    /**
     * @param  list<'first_name'|'last_name'>  $missing
     */
    private function __construct(public readonly array $missing)
    {
        parent::__construct(self::MESSAGE);
    }

    public static function fromBlank(bool $firstBlank, bool $lastBlank): self
    {
        $missing = [];

        if ($firstBlank) {
            $missing[] = 'first_name';
        }

        if ($lastBlank) {
            $missing[] = 'last_name';
        }

        return new self($missing);
    }

    /**
     * Only the fields that were blank, each with its one message.
     *
     * @return array<string, list<string>>
     */
    public function fieldMessages(): array
    {
        $messages = [];

        foreach ($this->missing as $field) {
            $messages[$field] = [self::FIELD_MESSAGES[$field]];
        }

        return $messages;
    }
}
