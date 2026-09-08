<?php

namespace App\Services\Family;

use App\Models\Contact;
use App\Models\ContactLoginEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * A parent chooses a password, and signs in with it (2026-09-08).
 *
 * The second door into the family realm. `FamilyLoginService` is the first and
 * remains the only one that can be opened from nothing but a mailbox; this one
 * can only ever be opened by a parent who walked through that one first.
 *
 * ---------------------------------------------------------------------------
 * THE OFFICE NEVER HOLDS A FAMILY'S PASSWORD
 * ---------------------------------------------------------------------------
 *
 * This is the constraint the original "no passwords for contacts" decision was
 * protecting, and it survives intact:
 *
 *   - `set()` takes the contact from the CALLER'S OWN TOKEN. There is no admin
 *     path, no staff override, and no `$contact` parameter an operator-facing
 *     controller could supply. A school secretary cannot set, read, or reset a
 *     parent's password, because no code path exists that would let them.
 *   - No password is ever mailed, logged, queued or returned. The plaintext
 *     lives for the duration of one request and is hashed before anything else
 *     happens to it.
 *   - There is no reset desk, which was the other half of the original
 *     objection. A parent who forgets their password requests a sign-in code —
 *     the mailbox is still the root credential, and the password is a
 *     convenience layered on top of it, never a replacement for it.
 *
 * ---------------------------------------------------------------------------
 * NEITHER DOOR IS AN ORACLE, and this one had to work harder for it
 * ---------------------------------------------------------------------------
 *
 * `attempt()` returns null for every failure — unknown address, revoked login,
 * no password chosen, wrong password — for the same reason `redeem()` does: a
 * school roster is a list of children, and confirming that a family attends a
 * particular organisation is itself a disclosure about a child.
 *
 * Status and body are not enough here. A password check is expensive by
 * construction (bcrypt is meant to be), so the honest implementation returns in
 * ~1ms for a stranger and ~60ms for a real address — a far cleaner timing
 * oracle than the ~15ms/~190ms one `FamilyLoginService::issue()` documents as a
 * known limitation. `hashOrBurn()` closes it: when there is no contact, or the
 * contact has no password, the check still runs against a fixed dummy hash of
 * the same cost. Both paths therefore pay for exactly one hash comparison.
 */
class FamilyPasswordService
{
    /**
     * A real digest, of a random value no caller can submit, computed ONCE per
     * PHP process and reused for every failed attempt that process handles.
     *
     * Deliberately not a hardcoded constant. The decoy only closes the timing
     * channel if comparing against it costs what comparing against a real
     * credential costs, and that cost is `config('hashing')` — algorithm and
     * rounds. A literal pasted in today silently stops matching the moment
     * anybody tunes bcrypt rounds or moves to argon2id, and `password_verify()`
     * against a digest of the wrong shape returns false doing almost no work at
     * all, so the defense would fail OPEN and completely silently. Deriving it
     * through the same `Hash::make()` the real credentials use means it tracks
     * the configuration by construction.
     *
     * Costed once per process rather than once per call because hashing on
     * every failure would DOUBLE the work on the failure path and reopen the
     * channel from the other side — strangers would become the slow ones. The
     * one process that pays twice is the first failure after a worker boots,
     * which is noise: it does not correlate with the address submitted.
     */
    private static ?string $decoy = null;

    public function __construct(private FamilyLoginService $logins)
    {
    }

    /**
     * Choose (or change) the password for a contact the caller has ALREADY
     * authenticated as.
     *
     * `$contact` comes from the request's token — see FamilyPasswordController.
     * Passing it in rather than resolving an address here is what makes it
     * structurally impossible for this method to change somebody else's
     * credential: there is no address to get wrong.
     *
     * OTHER SESSIONS ARE ENDED, the current one is not. Changing a password is
     * the one act that is also how a parent responds to "someone else has my
     * phone", so every other token this contact holds stops working — including
     * any live child-handoff token, which is the case that actually matters on
     * a shared family device. The caller's own token survives because signing a
     * parent out of the screen they just used to secure their account teaches
     * them that securing it broke something.
     */
    public function set(Contact $contact, string $plain, string $currentTokenId = '', ?string $ip = null): void
    {
        DB::transaction(function () use ($contact, $plain, $currentTokenId, $ip): void {
            // forceFill because `password` is deliberately not fillable — see
            // the $fillable docblock on Contact.
            $contact->forceFill([
                'password' => Hash::make($plain),
                'password_set_at' => now(),
            ])->save();

            $contact->tokens()
                ->when($currentTokenId !== '', fn ($q) => $q->whereKeyNot($currentTokenId))
                ->delete();

            $this->record($contact, ContactLoginEvent::ACTION_PASSWORD_SET, $ip);
        });
    }

    /**
     * Remove the password, putting this family back on codes only.
     *
     * The counterpart to `set()`, and reachable by the same person on the same
     * terms. It exists because "I chose a password and now I want it gone" must
     * not require an email to the office — the whole point is that the office is
     * not in this loop.
     */
    public function clear(Contact $contact, ?string $ip = null): void
    {
        if (! $contact->hasFamilyPassword()) {
            return;
        }

        DB::transaction(function () use ($contact, $ip): void {
            $contact->forceFill([
                'password' => null,
                'password_set_at' => null,
            ])->save();

            $this->record($contact, ContactLoginEvent::ACTION_PASSWORD_CLEARED, $ip);
        });
    }

    /**
     * Exchange an address + password for a family token, or null.
     *
     * @return array{contact: Contact, token: NewAccessToken}|null
     */
    public function attempt(string $submittedEmail, string $submittedPassword, ?string $ip = null): ?array
    {
        // The SHARED resolver, not a second implementation. It applies the
        // bound tenant, the case-insensitive match, the "two rows is no row"
        // ambiguity rule and `familyLoginIsActive()` — so a contact whose login
        // was revoked this morning cannot sign in with a password they set last
        // week, which is the failure a separate lookup here would have shipped.
        $contact = $this->logins->resolveContact($submittedEmail);

        if (! $this->hashOrBurn($contact, $submittedPassword)) {
            return null;
        }

        // Not a login_* column and not a credential; the same operator
        // visibility `consume()` writes, through the same forceFill, because
        // `last_login_at` is not fillable either.
        $contact->forceFill(['last_login_at' => now()])->save();

        return ['contact' => $contact, 'token' => $contact->createFamilyToken()];
    }

    // ------------------------------------------------------------- internals

    /**
     * Verify the password, ALWAYS paying for exactly one hash comparison.
     *
     * Three inputs reach this method and only one may succeed, but all three
     * must cost the same:
     *
     *   - no contact at all (unknown address, revoked login, ambiguous
     *     duplicate) — compared against the decoy
     *   - a contact who never chose a password — `getAuthPassword()` coalesces
     *     NULL to '', and every hasher refuses '' immediately WITHOUT doing the
     *     work, so this case would otherwise be fast; compared against the decoy
     *     instead, and refused on the flag rather than on the hash
     *   - a contact who did choose one — the real comparison
     *
     * The fail-closed '' behaviour is still what protects an un-enrolled row;
     * `hasFamilyPassword()` just means we never RELY on the timing of that
     * refusal.
     */
    private function hashOrBurn(?Contact $contact, string $submitted): bool
    {
        if ($contact === null || ! $contact->hasFamilyPassword()) {
            Hash::check($submitted, $this->decoy());

            return false;
        }

        return Hash::check($submitted, $contact->getAuthPassword());
    }

    /** @see self::$decoy */
    private function decoy(): string
    {
        return self::$decoy ??= Hash::make(Str::random(40));
    }

    /**
     * Append one immutable act to the same trail the admin on-switch writes to.
     *
     * `actor_user_id` / `actor_name` / `actor_email` are NULL on purpose and
     * that null is the record: no staff user was involved, because no staff user
     * CAN be. Every other verb on this trail names an operator; these two name
     * nobody, and a reader of the panel should be able to see that difference.
     */
    private function record(Contact $contact, string $action, ?string $ip): void
    {
        ContactLoginEvent::create([
            'masjid_id' => $contact->masjid_id,
            'contact_id' => $contact->id,
            'action' => $action,
            // The address the credential belongs to, snapshotted like every
            // other row on this trail. Never the password, obviously.
            'login_email' => $contact->login_email,
            'actor_user_id' => null,
            'actor_name' => null,
            'actor_email' => null,
            'actor_ip' => $ip,
        ]);
    }
}
