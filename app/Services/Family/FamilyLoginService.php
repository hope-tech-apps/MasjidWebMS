<?php

namespace App\Services\Family;

use App\Mail\FamilyLoginCodeMail;
use App\Models\Contact;
use App\Models\ContactLoginCode;
use App\Models\Masjid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

/**
 * Issuing and redeeming a parent/guardian sign-in code (T-015d).
 *
 * `docs/t015-parent-identity-design.md` §3. This is the ONLY way a family token
 * comes into existence; `routes/family.php` deliberately offered no way to
 * obtain one until this slice, and `Contact::createFamilyToken()` is called from
 * exactly one place below.
 *
 * ---------------------------------------------------------------------------
 * THE CALLER LEARNS NOTHING IT DID NOT ALREADY KNOW
 * ---------------------------------------------------------------------------
 *
 * Every method here is written so that its OBSERVABLE behaviour does not depend
 * on whether the submitted address belongs to a real, login-enabled contact.
 * `issue()` returns `void` — not a bool, not the contact — because a return
 * value is a value a controller can branch on, and a controller that branches
 * is one edit away from a 404 that answers "is this family at this school?".
 * That question is the one this product must never answer: a school roster is a
 * list of children, and confirming a parent's address against a specific
 * organisation is itself a disclosure about a child.
 *
 * The same reasoning is why `redeem()` returns `null` for every failure —
 * unknown address, no code, wrong code, expired code, replayed code, locked-out
 * code — rather than a reason the caller could render.
 *
 * ---------------------------------------------------------------------------
 * The plaintext code's entire life
 * ---------------------------------------------------------------------------
 *
 * It is generated in `issue()`, hashed, and handed to the mailer. It is never
 * returned, never logged, never put on a queue (see FamilyLoginCodeMail for why
 * that mailable is the one in this application that is NOT `ShouldQueue`), and
 * never stored. After `issue()` returns, the only copy in the world is in the
 * parent's inbox.
 */
class FamilyLoginService
{
    /**
     * Send a fresh sign-in code to `$submittedEmail`, if it names a contact of
     * the BOUND tenant that is allowed to log in.
     *
     * Silent in every direction. An unknown address, a contact whose login was
     * never enabled, a revoked one, a soft-deleted one and a mail relay outage
     * all produce exactly the same nothing.
     */
    public function issue(string $submittedEmail, ?string $ip = null): void
    {
        $contact = $this->resolveContact($submittedEmail);

        if ($contact === null) {
            return;
        }

        $code = $this->generateCode();

        ContactLoginCode::create([
            'contact_id' => $contact->id,
            'code_hash' => $this->hash($code),
            'channel' => ContactLoginCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
            'requested_ip' => $ip,
        ]);

        // Sent INLINE, synchronously. Two properties are in tension here and this
        // is the trade that was chosen deliberately:
        //
        //  - The plaintext code must never be persisted. QUEUE_CONNECTION is
        //    `database`, so a queued mailable writes its public properties —
        //    the code among them — into `jobs.payload`, and into `failed_jobs`
        //    on a relay outage. Hence FamilyLoginCodeMail is the one Mailable in
        //    this application that is not ShouldQueue.
        //
        //  - KNOWN LIMITATION, measured and unfixed: sending inline makes this
        //    endpoint a TIMING ORACLE. The unknown-address path returns after one
        //    indexed SELECT; this path additionally renders a template and makes a
        //    synchronous HTTPS call to the mail provider. On the deployed host
        //    (MAIL_MAILER=resend) a real login-enabled address answers in
        //    ~180-195ms and a stranger's in ~15ms — non-overlapping, so one probe
        //    per address reveals whether that family attends this organisation.
        //    Everything else in this realm works hard to keep status and body
        //    identical; the wall clock still answers the question.
        //
        // Deferring the send with dispatch()->afterResponse() closes the timing
        // channel without persisting anything, and was tried — but
        // Application::terminate() walks $terminatingCallbacks and never clears
        // them, so every callback re-runs on each later termination in the same
        // process. Invisible under php-fpm, wrong under Octane, and it re-sends a
        // parent's sign-in code. The correct fix is a queued job carrying an
        // encrypted payload (ShouldBeEncrypted) or a constant-time floor on the
        // controller, and it deserves its own change with its own tests rather
        // than being wedged into this one.
        $this->deliver($contact, $code);
    }


    /**
     * Exchange `$submittedEmail` + `$submittedCode` for a family token, or null.
     *
     * @return array{contact: Contact, token: NewAccessToken}|null
     */
    public function redeem(string $submittedEmail, string $submittedCode, ?string $ip = null): ?array
    {
        $contact = $this->resolveContact($submittedEmail);

        if ($contact === null) {
            return null;
        }

        $candidate = $this->hash($submittedCode);

        // Newest first: a parent who requested twice types the code from the
        // most recent mail. Requesting a second code deliberately does NOT kill
        // the first — a slow relay must not lock a family out of their own
        // retry — so more than one row can be live, and each is independently
        // single-use.
        $live = ContactLoginCode::query()
            ->where('contact_id', $contact->id)
            ->redeemable()
            ->orderByDesc('id')
            ->get();

        foreach ($live as $row) {
            // Constant-time, and against the stored digest rather than the
            // stored code, because there is no stored code.
            if (! hash_equals((string) $row->code_hash, $candidate)) {
                continue;
            }

            $token = $this->consume($row, $contact);

            // Null here means another request consumed this very row between
            // the SELECT above and the UPDATE inside consume(). That is the
            // replay case arriving concurrently, and it must lose exactly like
            // a replay arriving a second later.
            return $token === null ? null : ['contact' => $contact, 'token' => $token];
        }

        // A WRONG code. Every code this contact currently has live is charged
        // for the guess, not just "the newest one" — otherwise an attacker
        // requests twenty codes and buys a hundred guesses against whichever
        // one they are actually attacking. Legitimate use is unaffected: a
        // parent who types the right digits never reaches this line.
        //
        // increment() on the QUERY, not on the models: `attempts` is not
        // fillable (see the model) and a read-modify-write in PHP would lose
        // guesses arriving in parallel, which is precisely the traffic shape a
        // lockout exists to stop.
        if ($live->isNotEmpty()) {
            ContactLoginCode::query()
                ->whereIn('id', $live->pluck('id'))
                ->increment('attempts');
        }

        return null;
    }

    // ------------------------------------------------------------- internals

    /**
     * The contact this address names in the BOUND tenant, if it may log in.
     *
     * The tenant is never a parameter: `ContactLoginCode` and `Contact` are both
     * `BelongsToMasjid`, and `App\Http\Middleware\ResolveFamilyGuestTenant` has
     * bound the context before any caller reaches here. Passing a masjid id in
     * would create a second, hand-written filter that could disagree with the
     * global scope — the thing .claude/rules/tenant-scoping.md forbids.
     *
     * `LOWER()` on both sides rather than trusting the column collation, for the
     * reason `GroupAudience::identitiesFor()` records: production is utf8mb4_bin
     * (case-SENSITIVE) and the suite is SQLite, and whether a parent can sign in
     * must not depend on which database is answering.
     *
     * TWO ROWS IS NO ROW. The `(masjid_id, login_email)` unique index makes an
     * exact duplicate impossible, but it cannot stop `Parent@x.com` and
     * `parent@x.com` both existing — and an ambiguity about WHO is signing in
     * resolves to nobody, the same call the staff identity bridge makes.
     */
    private function resolveContact(string $submittedEmail): ?Contact
    {
        $email = Str::lower(trim($submittedEmail));

        if ($email === '') {
            return null;
        }

        $matches = Contact::query()
            ->whereNotNull('login_email')
            ->whereRaw('LOWER(login_email) = ?', [$email])
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $contact = $matches->first();

        // The same liveness the `family.active` middleware applies on every
        // authenticated request, applied here so a revoked or never-enabled
        // contact cannot even be sent a code. One definition, two doors.
        return $contact->familyLoginIsActive() ? $contact : null;
    }

    /**
     * Burn the code and mint the token, atomically.
     *
     * The UPDATE is a compare-and-swap (`whereNull('consumed_at')`) rather than
     * a read-then-write: two requests carrying the same correct code arrive at
     * the same instant far more often than intuition suggests (a double-tapped
     * button, a mail client prefetching a link), and the loser must get nothing
     * rather than a second token. `affected === 0` IS the loser.
     *
     * Design §3 requires `consumed_at` to be written inside the same transaction
     * that mints the token; that is what stops a failure between the two leaving
     * either a live code that already produced a token, or a consumed code that
     * produced none.
     */
    private function consume(ContactLoginCode $row, Contact $contact): ?NewAccessToken
    {
        return DB::transaction(function () use ($row, $contact): ?NewAccessToken {
            $affected = ContactLoginCode::query()
                ->whereKey($row->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            if ($affected === 0) {
                return null;
            }

            // Operator visibility only — nothing reads it and nothing
            // authorizes on it (see the migration that added the column).
            // forceFill because the four login_* columns are deliberately not
            // fillable.
            $contact->forceFill(['last_login_at' => now()])->save();

            return $contact->createFamilyToken();
        });
    }

    /**
     * A uniformly-random decimal code, zero-padded.
     *
     * `random_int()` is the CSPRNG; `rand()`/`mt_rand()`/`Str::random()` are
     * not, and a predictable sign-in code is the same failure as a stored one.
     */
    private function generateCode(): string
    {
        $length = max(4, (int) config('family.login.code_length', 6));

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * The digest actually stored. HMAC-SHA256 keyed on the application key —
     * see the migration for why a bare sha256 of six digits is not a hash in
     * any meaningful sense.
     *
     * The key is read through `config('app.key')` (already base64-prefixed) and
     * never handled beyond this line.
     */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('family.login.code_ttl_minutes', 10));
    }

    /**
     * Put the code in the parent's inbox.
     *
     * WRAPPED, and the exception is swallowed on purpose. An SMTP outage that
     * escaped here would 500 for a real address while an unknown address still
     * answered 202 — a perfect existence oracle built out of an error path, and
     * exactly the kind of leak that is invisible until someone goes looking for
     * it.
     *
     * What is logged is the contact id and the exception CLASS. Not the code,
     * obviously; and not the exception message either, because a mailer
     * exception routinely quotes the recipient address, and a log line naming a
     * family address against a school is the disclosure this realm is built to
     * avoid.
     */
    private function deliver(Contact $contact, string $code): void
    {
        try {
            // Masjid is the tenant, not a tenant-scoped model, so it carries no
            // global scope to bypass — EnsureCrmEnabled reads it the same way.
            $masjid = Masjid::find($contact->masjid_id);

            Mail::to($contact->login_email)->send(new FamilyLoginCodeMail(
                orgName: $masjid?->name ?? config('app.name'),
                code: $code,
                expiresInMinutes: $this->ttlMinutes(),
                recipientName: $contact->first_name,
                orgEmail: $masjid?->email,
            ));
        } catch (Throwable $e) {
            Log::warning('family login code delivery failed', [
                'contact_id' => $contact->id,
                'masjid_id' => $contact->masjid_id,
                'exception' => $e::class,
            ]);
        }
    }
}
