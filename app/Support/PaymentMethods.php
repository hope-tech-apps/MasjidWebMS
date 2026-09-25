<?php

namespace App\Support;

/**
 * The one vocabulary of ways an organisation can be paid, Manara-wide.
 *
 * The owner's decision (2026-09-21): every organisation says which payment
 * methods it accepts and how to use each one, and every module that takes an
 * offline payment records "Mark as paid" WITH how the money came. Before this,
 * each module spelled its own list (MealOrder::PAID_VIA had no cheque,
 * FormResponse::PAID_VIA had no bank transfer), so a method an organisation
 * advertised could not always be recorded when the money arrived.
 *
 * These keys are what `organisation_payment_methods.method` stores, what the
 * public payload names, and what a customer's `preferred_payment` holds. The
 * modules' own PAID_VIA lists include every OFFLINE key here (a test pins it),
 * so whatever the organisation advertises, staff can record.
 *
 * `card` is the only online method: a card taken on the organisation's OWN
 * Stripe Connect account through Manara. It is offered to the public only while
 * the account can take charges (AcceptedPaymentMethods), because an advertised
 * method that fails at checkout is worse than one not offered.
 */
final class PaymentMethods
{
    public const CARD = 'card';
    public const CASH = 'cash';
    public const CHECK = 'check';
    public const ZELLE = 'zelle';
    public const BANK_TRANSFER = 'bank_transfer';
    public const OTHER = 'other';

    /** Every key, in the order the admin screen and the public page list them. */
    public const KEYS = [
        self::CARD,
        self::CASH,
        self::CHECK,
        self::ZELLE,
        self::BANK_TRANSFER,
        self::OTHER,
    ];

    /** The methods settled by hand ("Mark as paid"), i.e. everything but card. */
    public const OFFLINE = [
        self::CASH,
        self::CHECK,
        self::ZELLE,
        self::BANK_TRANSFER,
        self::OTHER,
    ];

    /** The words used when the organisation has not given its own label. */
    public const LABELS = [
        self::CARD => 'Card (online)',
        self::CASH => 'Cash',
        self::CHECK => 'Check',
        self::ZELLE => 'Zelle',
        self::BANK_TRANSFER => 'Bank transfer',
        self::OTHER => 'Other',
    ];

    public static function isOffline(string $key): bool
    {
        return in_array($key, self::OFFLINE, true);
    }
}
