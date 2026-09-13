<?php

namespace App\Services\Registrations;

use App\Models\User;

/**
 * "An authenticated administrator is entering this registration by hand, and is
 * asserting that their own act carries the authority to do so."
 *
 * ONE PARAMETER RATHER THAN THREE, and an object rather than a flag, because
 * this is not configuration — it is the claim itself. `RegistrationService`'s
 * class docblock spells out why the claim has to be made explicitly:
 * `writeRosterMemberships()` writes a GUARDIAN EDGE from the payer over every
 * registrant, and a guardian edge is the single fact the parent portal reads to
 * decide whose child's behaviour, ḥifẓ and safeguarding records a credential
 * opens. The service cannot see who asked — `confirm()` runs from a webhook
 * minutes or days later with no request and no principal — so it takes the
 * registrant list AS the claim, and the door that assembled that list owns
 * proving it. The public endpoint proves it the only way an unauthenticated
 * caller can: by resolving every registrant to a contact it creates in that
 * same request. A caller that hands over one of these is proving it the other
 * way, and had better be authenticated when it does.
 *
 * Passing `null` for `$user` is allowed and is NOT a loophole: a console
 * command or a seeder has no `users` row to name, and a staff entry with no
 * recorded actor is still better evidence than a row with no provenance at all
 * — the same call `GroupMembership::confirmedByStaff()` makes. What is NOT
 * allowed is constructing this from anything a request body carries; every HTTP
 * caller must build it from `$request->user()`, behind a permission gate.
 *
 * The absence of this object is what the public path looks like. There is no
 * `StaffEntry::none()` and no boolean, so a caller cannot pass "false" by
 * accident and cannot half-assert.
 */
final class StaffEntry
{
    /**
     * @param  User|null  $user  the administrator, taken from the request and
     *         never from input; null only on a console/seeder path
     * @param  string|null  $note  why this was entered by hand, in the words the
     *         administrator typed ("paid cash at the desk", "phoned in"). Free
     *         text for the office to read later — NEVER a money claim, and
     *         nothing reads it to decide what anybody owes.
     */
    public function __construct(
        public readonly ?User $user = null,
        public readonly ?string $note = null,
    ) {
    }
}
