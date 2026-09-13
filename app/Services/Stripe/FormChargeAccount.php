<?php

namespace App\Services\Stripe;

use App\Models\FormResponse;
use App\Models\Masjid;

/**
 * The ONE answer to "whose Connect account takes this organisation's FORM card
 * payments?" (DECISIONS.md 2026-09-15). Every Forms card gate asks it: the submit's
 * refusal, Form::canTakeCardNow(), the page payload's `available`, the checkout's
 * preflight and the account a new page is opened on.
 *
 * Forms only. Donations, lunch orders and offerings keep Masjid::canAcceptDonations(),
 * which this never changes, so a linked organisation still takes none of those.
 *
 *  - An organisation that is NOT linked charges on its own account: itself, only when
 *    its stripe_account_id is an acct_ id and Stripe says it can take charges.
 *  - A LINKED organisation (masjids.forms_card_via_masjid_id, set only by a SuperAdmin)
 *    charges on its holder's account, and only while every one of these holds, checked
 *    on every call: it has no account of its own; the link equals its parent_id; the
 *    holder is live (not trashed) and not itself linked; the holder's account is an
 *    acct_ id and can take charges. The holder is read LIVE each time, so a holder that
 *    disconnects, is offboarded or is re-parented away makes card unavailable at once:
 *    fail closed, never a different payee.
 *
 * A page already opened is never re-resolved through here: its account is pinned on the
 * row (FormResponse::charge_account_id) and every later read, close and webhook uses the
 * pin.
 */
final class FormChargeAccount
{
    /** Reasons a link cannot charge (linkProblem()); stable codes a screen words itself. */
    public const PROBLEM_ORGANISATION_MISSING = 'organisation_missing';

    public const PROBLEM_HAS_OWN_ACCOUNT = 'has_own_account';

    public const PROBLEM_SAME_ORGANISATION = 'same_organisation';

    public const PROBLEM_NOT_PARENT = 'not_parent';

    public const PROBLEM_HOLDER_MISSING = 'holder_missing';

    public const PROBLEM_HOLDER_LINKED = 'holder_linked';

    public const PROBLEM_HOLDER_NOT_ONBOARDED = 'holder_not_onboarded';

    public const PROBLEM_HOLDER_CHARGES_DISABLED = 'holder_charges_disabled';

    /** The organisation is itself the holder of another organisation's link. */
    public const PROBLEM_IS_HOLDER = 'is_holder';

    public const PROBLEMS = [
        self::PROBLEM_ORGANISATION_MISSING,
        self::PROBLEM_HAS_OWN_ACCOUNT,
        self::PROBLEM_SAME_ORGANISATION,
        self::PROBLEM_NOT_PARENT,
        self::PROBLEM_HOLDER_MISSING,
        self::PROBLEM_HOLDER_LINKED,
        self::PROBLEM_HOLDER_NOT_ONBOARDED,
        self::PROBLEM_HOLDER_CHARGES_DISABLED,
        self::PROBLEM_IS_HOLDER,
    ];

    /**
     * Where $org's form card payments land right now, or null when they cannot be taken.
     */
    public static function for(?Masjid $org): ?FormCharge
    {
        if ($org === null || $org->trashed()) {
            return null;
        }

        if (! self::isLinked($org)) {
            return self::isAccount($org->stripe_account_id) && $org->stripe_charges_enabled
                ? new FormCharge($org, $org, (string) $org->stripe_account_id, false)
                : null;
        }

        $holder = Masjid::find((int) $org->forms_card_via_masjid_id);

        if (self::chainProblem($org, $holder) !== null) {
            return null;
        }

        return new FormCharge($org, $holder, (string) $holder->stripe_account_id, true);
    }

    /**
     * Why $child's form card payments cannot be charged on $holder's account, or null
     * when they can. The admin endpoint asks it before writing a link (and a status
     * screen after), so it also refuses a child that is itself some other
     * organisation's holder.
     */
    public static function linkProblem(Masjid $child, ?Masjid $holder): ?string
    {
        $problem = self::chainProblem($child, $holder);

        if ($problem !== null) {
            return $problem;
        }

        $isHolder = Masjid::query()
            ->where('forms_card_via_masjid_id', $child->id)
            ->whereKeyNot($child->id)
            ->exists();

        return $isHolder ? self::PROBLEM_IS_HOLDER : null;
    }

    /** Whether a SuperAdmin has pointed $org's form card payments at another organisation. */
    public static function isLinked(?Masjid $org): bool
    {
        return $org !== null && $org->forms_card_via_masjid_id !== null;
    }

    /** A Stripe connected-account id, never '' or anything else a header could carry. */
    public static function isAccount(mixed $accountId): bool
    {
        return is_string($accountId) && strlen($accountId) > 5 && str_starts_with($accountId, 'acct_');
    }

    /**
     * The organisation a registration was charged THROUGH, when that is not its own
     * organisation: the holder its page was pinned to. withTrashed, so a holder
     * offboarded since still has a name. Null for every row of an organisation that
     * charges on its own account, and for every row written before the pin existed.
     */
    public static function chargedThrough(FormResponse $row): ?Masjid
    {
        if ($row->charge_masjid_id === null || (int) $row->charge_masjid_id === (int) $row->masjid_id) {
            return null;
        }

        return Masjid::withTrashed()->find((int) $row->charge_masjid_id);
    }

    /**
     * What an admin of the registration's organisation is told to do about a card
     * payment that should be refunded, when the charge sits on another organisation's
     * account: who holds the refund button, and how that organisation finds the charge.
     * Null for a row charged on its own organisation's account (its words stay as they
     * were).
     */
    public static function refundInstruction(FormResponse $row): ?string
    {
        $holder = self::chargedThrough($row);

        if ($holder === null) {
            return null;
        }

        $reference = match (true) {
            filled($row->stripe_payment_intent_id) => " (payment {$row->stripe_payment_intent_id})",
            filled($row->stripe_checkout_session_id) => " (Checkout session {$row->stripe_checkout_session_id})",
            default => '',
        };

        return "The card payment was taken through {$holder->name}'s Stripe account, so only {$holder->name} can refund it{$reference}.";
    }

    /**
     * Every rule of a usable link except "the child is not itself a holder", which
     * for() does not need: a grandchild linked through this child already fails at its
     * own "holder not linked" rule.
     */
    private static function chainProblem(Masjid $child, ?Masjid $holder): ?string
    {
        if ($child->trashed()) {
            return self::PROBLEM_ORGANISATION_MISSING;
        }

        if (filled($child->stripe_account_id)) {
            return self::PROBLEM_HAS_OWN_ACCOUNT;
        }

        if ($holder === null || ! $holder->exists || $holder->trashed()) {
            return self::PROBLEM_HOLDER_MISSING;
        }

        if ((int) $holder->id === (int) $child->id) {
            return self::PROBLEM_SAME_ORGANISATION;
        }

        if ($child->parent_id === null || (int) $child->parent_id !== (int) $holder->id) {
            return self::PROBLEM_NOT_PARENT;
        }

        if (self::isLinked($holder)) {
            return self::PROBLEM_HOLDER_LINKED;
        }

        if (! self::isAccount($holder->stripe_account_id)) {
            return self::PROBLEM_HOLDER_NOT_ONBOARDED;
        }

        if (! $holder->stripe_charges_enabled) {
            return self::PROBLEM_HOLDER_CHARGES_DISABLED;
        }

        return null;
    }
}
