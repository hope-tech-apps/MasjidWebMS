<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * One submission to a Form.
 *
 * `data` is the validated submission keyed by field name; the repeatable section's
 * entries live under that section's id as an array of rows. The respondent_* columns
 * are copies of three values out of `data` so the admin list can search and sort
 * across the whole result set — see the create_form_responses_table migration.
 *
 * A `file` question stores the respondent's ORIGINAL FILENAME in `data` under the
 * field's name, so the admin table and the CSV export have something to show; the
 * bytes live on a private disk and are reached only through `attachments()`.
 *
 * These rows are PII (names, emails, phones, for camp forms minors' medical details,
 * and for a careers form an entire résumé). Treat them accordingly: they are excluded
 * from any payload that is not behind admin auth, and are never sent to a third-party
 * API unaggregated.
 *
 * ## The money leg (DECISIONS.md 2026-09-11)
 *
 * A form whose settings turn payment on gives its responses a money leg. It is
 * kept apart from `status` on purpose: STATUSES is the admin's triage workflow
 * and feeds hard-coded lists across the admin, while "has this person paid, and
 * have they had their bracelets?" is a different question.
 *
 * `payment_method` NULL means no money leg — every row written before the
 * festival, and every row on a form that charges nothing. "Settled" (paid, or
 * nothing was ever owed) is the one definition the WhatsApp link, the door and
 * "collected" all read: isSettled().
 *
 * The money columns are NOT fillable. As on MealOrder they move only through
 * the methods below, and each re-reads the row under a lock, so two people
 * pressing two buttons at once cannot both win:
 *
 *   markPaid()          the signed Stripe webhook, online rows only
 *   settleCash()        a staff code at the gate: cash its holder owes
 *   settleCashBy()      an admin taking cash for an unpaid row
 *   markExternalPaid()  an admin recording a payment made elsewhere (Wix)
 *   markCollected() / uncollect()
 *
 * Pinned by tests/Feature/FormResponseSettlementTest.php and
 * tests/Feature/FormPaymentSchemaTest.php.
 */
class FormResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'masjid_id',
        'data',
        'respondent_name',
        'respondent_email',
        'respondent_phone',
        'entry_count',
        'amount_due',
        'status',
        'admin_notes',
        'device_id',
        'ip_address',
        'user_agent',
        'submitted_at',
    ];

    protected $casts = [
        'data' => 'array',
        'entry_count' => 'integer',
        'amount_due' => 'decimal:2',
        'submitted_at' => 'datetime',
        'amount_due_minor' => 'integer',
        'fee_covered_minor' => 'integer',
        'total_minor' => 'integer',
        'paid_at' => 'datetime',
        'collected_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'staff_code_id' => 'integer',
        'marked_paid_by_user_id' => 'integer',
        'collected_by_user_id' => 'integer',
        'status_changed_by_user_id' => 'integer',
    ];

    /**
     * The replay guard's digest is derived from personal data and has no use on
     * any screen, so it never serialises.
     */
    protected $hidden = [
        'client_payload_hash',
    ];

    /**
     * Workflow states an admin can move a response through. Kept here rather than in a
     * DB enum so states can be added without a migration.
     */
    public const STATUSES = ['new', 'confirmed', 'waitlisted', 'cancelled'];

    /** The triage state the door and "collected" have to warn on. */
    public const STATUS_CANCELLED = 'cancelled';

    /** How a money leg was (or is to be) paid. NULL: this row has none. */
    public const METHOD_ONLINE = 'online';     // hosted Stripe Checkout; only the webhook marks it paid
    public const METHOD_CASH = 'cash';         // a staff code at the gate, or an admin taking cash
    public const METHOD_EXTERNAL = 'external'; // paid elsewhere (the Wix fallback), marked by an admin

    public const METHODS = [
        self::METHOD_ONLINE,
        self::METHOD_CASH,
        self::METHOD_EXTERNAL,
    ];

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID,
        self::PAYMENT_PAID,
    ];

    /**
     * The unique index behind the replay guard. A double-tap that races past the
     * lookup lands on it as a duplicate-key error, which the submit path must
     * answer as a replay, never a 500. MySQL's error names this index; SQLite's
     * names the two columns instead.
     */
    public const CLIENT_KEY_UNIQUE_INDEX = 'form_resp_form_client_key_unique';

    /**
     * Keep forms.response_count honest without a COUNT(*) on every capacity check.
     *
     * Capacity is enforced against this counter, so it must move in the same
     * transaction as the row it counts. `increment`/`decrement` issue an atomic
     * UPDATE … SET response_count = response_count + 1, which is safe against two
     * simultaneous submissions in a way that read-modify-write would not be.
     */
    protected static function booted(): void
    {
        // The public bearer handle for the status read and the Stripe return.
        // Minted here, never taken from input: `uuid` is not fillable, so a client
        // cannot choose the token that later unlocks its own row.
        static::creating(function (FormResponse $response) {
            if (empty($response->uuid)) {
                $response->uuid = (string) Str::uuid();
            }
        });

        static::created(function (FormResponse $response) {
            Form::whereKey($response->form_id)->increment('response_count');
        });

        // BEFORE the row goes, not after: the FK on form_response_attachments
        // cascades, so by the time a `deleted` hook ran the attachment rows would
        // already be gone — silently, without firing a single model event — and
        // every résumé ever submitted would still be sitting on the volume. Deleting
        // them through the model here is what makes each one's `deleting` hook run
        // and remove its bytes from disk.
        static::deleting(function (FormResponse $response) {
            $response->attachments()->get()->each->delete();
        });

        static::deleted(function (FormResponse $response) {
            Form::whereKey($response->form_id)
                ->where('response_count', '>', 0)
                ->decrement('response_count');
        });
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Files uploaded with this submission. Empty for every form without a `file`
     * field, which is every form that existed before T-004.
     */
    public function attachments()
    {
        return $this->hasMany(FormResponseAttachment::class, 'form_response_id');
    }

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    /**
     * The staff code whose holder owes this row's cash; null for every other row.
     * Resolved through the code's tenant scope, so a bound admin never sees
     * another masjid's holder through a forged pointer.
     */
    public function staffCode(): BelongsTo
    {
        return $this->belongsTo(FormStaffCode::class, 'staff_code_id');
    }

    /**
     * The admins behind the moves made by hand. withTrashed: removing an admin
     * soft-deletes their login, and "who handed these bracelets over?" must still
     * have an answer afterwards.
     */
    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by_user_id')->withTrashed();
    }

    public function markedPaidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_paid_by_user_id')->withTrashed();
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id')->withTrashed();
    }

    /**
     * Free-text search across the three denormalised identity columns, and the
     * registration number.
     * Deliberately not a JSON search: JSON path predicates are not portable between
     * MySQL (production) and SQLite (tests).
     *
     * "#123" is the registration number a receipt prints ("Registration no. #123"),
     * which is what a person at the bracelet table holds up. A bare number is
     * matched as one too, beside whatever phone number it may also be part of.
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $like = '%' . $term . '%';
            $q->where('respondent_name', 'like', $like)
                ->orWhere('respondent_email', 'like', $like)
                ->orWhere('respondent_phone', 'like', $like);

            if (preg_match('/^#?\s*([0-9]{1,18})$/', $term, $number) === 1) {
                $q->orWhere($q->qualifyColumn('id'), (int) $number[1]);
            }
        });
    }

    // ---------------------------------------------------------------- money leg

    /**
     * Whether this row carries a money leg at all. A money row is never deleted,
     * only cancelled, and its re-triage is stamped (stampStatusChange()).
     */
    public function hasMoneyLeg(): bool
    {
        return $this->payment_method !== null;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    /**
     * Triage-cancelled by an admin. Never payable, and the public reads say so
     * (`cancelled: true`) and hold the group link back: a refunded card payer is
     * cancelled and still reads as paid.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCollected(): bool
    {
        return $this->collected_at !== null;
    }

    /**
     * What a screen or an export says about this row's payment: 'paid'; 'unpaid',
     * which includes a row with no money leg on a form that charges (every
     * Wix-fallback row until an admin marks it paid); or null when nothing was
     * ever owed. isSettled() in one word, so the badge, the CSV and the roster
     * cannot read a NULL payment column as "free" where the door would not.
     */
    public function paymentState(): ?string
    {
        if ($this->isPaid()) {
            return self::PAYMENT_PAID;
        }

        return $this->isSettled() ? null : self::PAYMENT_UNPAID;
    }

    /**
     * Paid — or nothing was ever owed.
     *
     * The one definition the WhatsApp link, the door and markCollected() read.
     * NULL payment columns do NOT mean settled on their own: under the Wix
     * fallback every row on the festival form has them, and reading NULL as
     * "free" would hand the group link and a bracelet to people who had not
     * paid. So a row with no money leg is settled only when BOTH halves agree it
     * owed nothing:
     *
     *  - its own snapshot (amount_due / amount_due_minor) says nothing was owed
     *    when it was written, so a price later removed from the form does not
     *    wave through a row that owed money; and
     *  - its form asks for nothing now, so a fee form whose total came to $0 (an
     *    empty attendee list sent past the renderer) is not free by accident.
     *
     * The price of the second half: rows written while a form was free stop
     * being settled if an admin later puts a price on that live form.
     */
    public function isSettled(): bool
    {
        if ($this->isPaid()) {
            return true;
        }

        // An unpaid money leg is unpaid whatever the form says today.
        if ($this->payment_method !== null || $this->payment_status !== null) {
            return false;
        }

        if ((int) $this->owedMinor() > 0) {
            return false;
        }

        $form = $this->form ?? Form::withTrashed()->find($this->form_id);

        return $form !== null && ! $form->chargesFee();
    }

    /**
     * What this row owes, in integer cents: the snapshot when there is one, else
     * the legacy decimal `amount_due` read as a STRING — a decimal:2 cast always
     * has exactly two places, so no float touches the money. Null when the form
     * charged nothing when the row was written.
     */
    public function owedMinor(): ?int
    {
        if ($this->amount_due_minor !== null) {
            return (int) $this->amount_due_minor;
        }

        if ($this->amount_due === null) {
            return null;
        }

        $amount = (string) $this->amount_due;
        $sign = str_starts_with($amount, '-') ? -1 : 1;
        [$whole, $cents] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');

        return $sign * ((int) $whole * 100 + (int) str_pad(substr($cents, 0, 2), 2, '0'));
    }

    /**
     * The signed webhook's one move: an ONLINE row from unpaid to paid.
     *
     * True only on that transition, under the row lock, so
     * checkout.session.completed and payment_intent.succeeded arriving together
     * give one paid_at and exactly one receipt. The payment intent id is recorded
     * whatever happens — including on a cash or externally-paid row, which a
     * stray card payment must NOT flip: the id is how the organisation finds
     * that charge to refund it.
     */
    public function markPaid(?string $paymentIntentId = null): bool
    {
        return $this->underLock(function (self $row) use ($paymentIntentId): bool {
            if ($paymentIntentId !== null && $row->stripe_payment_intent_id === null) {
                $row->stripe_payment_intent_id = $paymentIntentId;
            }

            $transition = $row->payment_method === self::METHOD_ONLINE
                && $row->payment_status === self::PAYMENT_UNPAID;

            if ($transition) {
                $row->payment_status = self::PAYMENT_PAID;
                $row->paid_at = now();
            }

            $row->save();

            return $transition;
        });
    }

    /**
     * A staff code at the gate: this registration is now cash its holder owes,
     * at the list price.
     *
     * Only a row with no money leg yet — the one the submit has just written in
     * the same transaction. It never converts an ONLINE row (that would mark paid
     * a row whose card page may still be open; an admin does that with
     * settleCashBy() after closing the page) and never re-settles a paid one, so
     * a replay cannot make a holder owe twice.
     *
     * The code is re-read under a lock, so a code revoked a moment ago records
     * nothing. Returns false when the code is no longer usable or the row already
     * has a money leg; the caller rolls back and refuses. On success the code's
     * use_count moves in the same transaction.
     *
     * Throws on what no request should be able to cause: a code from another
     * form or masjid, and nothing to collect. Cash for $0 is how a paid event
     * becomes free by accident, so it is loud, never a quiet no-op.
     */
    public function settleCash(FormStaffCode $code): bool
    {
        if ((int) $code->form_id !== (int) $this->form_id || (int) $code->masjid_id !== (int) $this->masjid_id) {
            throw new LogicException('A staff code settles registrations on its own form only.');
        }

        return $this->underLock(function (self $row) use ($code): bool {
            if ($row->payment_method !== null || $row->payment_status !== null) {
                return false;
            }

            $owed = $row->requireOwedMinor();

            $live = FormStaffCode::withoutMasjidScope()->whereKey($code->getKey())->lockForUpdate()->first();

            if ($live === null || ! $live->isUsable()) {
                return false;
            }

            $row->forceFill(self::paidAs(self::METHOD_CASH, $owed, $row->currency) + [
                'staff_code_id' => $live->getKey(),
            ])->save();

            $live->recordUse();
            $code->setRawAttributes($live->getAttributes(), true);

            return true;
        });
    }

    /**
     * An admin taking cash for an unpaid registration.
     *
     * This does not talk to Stripe. The caller holds the row lock, closes any
     * open Checkout Session first (MealOrderCheckoutService's close logic: expire
     * it, and ask Stripe again after a refused close), refuses if Stripe reports
     * the session complete, and only then calls this. Afterwards the row is
     * `cash`, so a card payment that still lands is recorded by markPaid() but
     * never flips it back.
     *
     * A card-fee cover chosen for the online attempt no longer applies: cash pays
     * the list price.
     */
    public function settleCashBy(User $operator): bool
    {
        return $this->settleByHand(self::METHOD_CASH, $operator);
    }

    /**
     * "Mark paid (external)": the person paid somewhere else — the Wix page, if
     * MEC's Stripe Connect is not live in time. Same lock and the same Stripe
     * caveat as settleCashBy(). Recording it is what puts a Wix payer in the
     * paid set the door filters on.
     */
    public function markExternalPaid(User $by): bool
    {
        return $this->settleByHand(self::METHOD_EXTERNAL, $by);
    }

    /**
     * Bracelets handed out. Stamped by the first press only, so a second press
     * (or a colleague's at the next table) never rewrites who handed them over.
     *
     * A row that is not settled is refused, loudly: collected is the last step
     * of a paid registration, never a way around paying. The caller is expected
     * to check isSettled() first and answer 'Not paid yet'; this is the backstop.
     */
    public function markCollected(User $by): bool
    {
        return $this->underLock(function (self $row) use ($by): bool {
            if ($row->collected_at !== null) {
                return false;
            }

            if (! $row->isSettled()) {
                throw new LogicException("Form response {$row->id} is not settled, so it cannot be marked collected.");
            }

            $row->forceFill([
                'collected_at' => now(),
                'collected_by_user_id' => $by->getKey(),
            ])->save();

            return true;
        });
    }

    /** "Undo" at the table. False when there was nothing to undo. */
    public function uncollect(): bool
    {
        return $this->underLock(function (self $row): bool {
            if ($row->collected_at === null) {
                return false;
            }

            $row->forceFill(['collected_at' => null, 'collected_by_user_id' => null])->save();

            return true;
        });
    }

    /**
     * Record who is re-triaging a MONEY row, before it is saved.
     *
     * Cancelling a cash row takes it out of its holder's total, so the change
     * must carry a name — the marked_paid_by precedent. Call it after filling and
     * before save():
     *
     *     $response->fill($validated);
     *     $response->stampStatusChange($request->user());
     *     $response->save();
     *
     * A row with no money leg is left as it always was. True when it stamped.
     */
    public function stampStatusChange(?User $by): bool
    {
        if (! $this->exists || ! $this->isDirty('status') || ! $this->hasMoneyLeg()) {
            return false;
        }

        $this->status_changed_by_user_id = $by?->getKey();
        $this->status_changed_at = now();

        return true;
    }

    /**
     * The replay guard's digest of a submission: keyed on APP_KEY (it is derived
     * from personal data) and independent of key order, so the same answers
     * always hash the same while a list — the attendee rows — keeps its order.
     * Hash everything that decides what the row owes, not only the answers.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function payloadHash(array $payload): string
    {
        $json = json_encode(
            self::canonical($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );

        return hash_hmac('sha256', $json, (string) config('app.key'));
    }

    /**
     * Whether a replayed submission carries the answers this row was written
     * with. A replay that does not is refused, never handed this row.
     *
     * @param  array<string,mixed>  $payload
     */
    public function matchesPayload(array $payload): bool
    {
        return $this->client_payload_hash !== null
            && hash_equals($this->client_payload_hash, self::payloadHash($payload));
    }

    /**
     * Resolve a response by its public uuid within one masjid. The status read,
     * the reopen and the webhook all run UNBOUND, so the masjid is filtered by
     * hand and a foreign uuid misses.
     */
    public static function findByUuidForMasjid(string $uuid, int $masjidId): ?self
    {
        return static::query()
            ->where('masjid_id', $masjidId)
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * Whether $uuid is a handle the status read and "Return to payment" answer for: a row
     * with a money leg at this masjid (FormResponsePaymentsController::resolve()'s test,
     * less its form lookup). Their route limiters ask it before the controller runs, so a
     * made-up uuid meets a per-connection flood guard and a real registration only its own
     * allowance (AppServiceProvider). One query, on the unique uuid index. Hand-filtered:
     * those routes run unbound.
     */
    public static function isPaymentHandle(string $uuid, int $masjidId): bool
    {
        return $masjidId > 0
            && static::query()
                ->where('masjid_id', $masjidId)
                ->where('uuid', $uuid)
                ->whereNotNull('payment_method')
                ->exists();
    }

    /** Admin cash and external payments: one shape, stamped by the first press only. */
    private function settleByHand(string $method, User $by): bool
    {
        return $this->underLock(function (self $row) use ($method, $by): bool {
            if ($row->isPaid()) {
                return false;
            }

            $row->forceFill(self::paidAs($method, $row->requireOwedMinor(), $row->currency) + [
                'marked_paid_by_user_id' => $by->getKey(),
            ])->save();

            return true;
        });
    }

    /**
     * The columns a settlement by hand or by code writes. No card was charged, so
     * no card fee was covered and the total is what was owed.
     *
     * @return array<string,mixed>
     */
    private static function paidAs(string $method, int $owed, ?string $currency): array
    {
        return [
            'payment_method' => $method,
            'payment_status' => self::PAYMENT_PAID,
            'currency' => $currency ?? 'usd',
            'amount_due_minor' => $owed,
            'fee_covered_minor' => 0,
            'total_minor' => $owed,
            'paid_at' => now(),
        ];
    }

    private function requireOwedMinor(): int
    {
        $owed = $this->owedMinor();

        if ($owed === null || $owed <= 0) {
            throw new LogicException("Form response {$this->id} owes nothing, so there is nothing to settle.");
        }

        return $owed;
    }

    /**
     * Re-read this row under a row lock, apply $change to the locked copy, and
     * bring the result back onto $this.
     *
     * Every money method goes through here, so each decision is made on the
     * stored row as it is at that instant — never on a copy loaded seconds ago —
     * and a caller already holding the lock in its own transaction (the
     * take-cash action) nests harmlessly. Unsaved changes on $this are dropped:
     * these methods act on the stored row only.
     */
    private function underLock(callable $change): bool
    {
        return DB::transaction(function () use ($change): bool {
            $row = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $result = $change($row);

            $this->setRawAttributes($row->getAttributes(), true);

            return $result;
        });
    }

    /** Recursively key-sorted; lists keep their order. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => self::canonical($item), $value);
    }
}
