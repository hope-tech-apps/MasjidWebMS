<?php

namespace App\Services\Family;

use RuntimeException;

/**
 * The ONE refusal an operator can answer with a confirmation.
 *
 * A subclass rather than a flag on the message, because the SPA has to tell this
 * refusal apart from the other four `enable()` can throw (not a guardian, a
 * placeholder, a blank address, an address held by somebody who can sign in with
 * it right now) in order to offer the reassignment checkbox — and deciding that
 * by matching the prose would put the rule in the copy, where a reworded
 * sentence silently removes the only way through.
 *
 * It extends RuntimeException so every existing catch keeps working unchanged:
 * `ContactFamilyLoginController::store` still answers 422 with this message. The
 * type only adds one boolean to that body.
 *
 * NOT thrown for a live holder. That case is not reassignable at any confirmation
 * level — two live logins on one address resolve to NOBODY in
 * `FamilyLoginService::resolveContact()`, silently, and both parents are locked
 * out with nothing in any log. The operator must revoke first, which is a
 * different act with a different record.
 */
class AddressReassignmentRequired extends RuntimeException
{
}
