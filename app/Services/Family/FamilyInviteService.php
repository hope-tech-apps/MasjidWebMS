<?php

namespace App\Services\Family;

use App\Mail\FamilyPortalInviteMail;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\ContactPortalInvite;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;
use Throwable;

/**
 * "Send portal invite" — the half of the parent portal that was missing.
 *
 * ---------------------------------------------------------------------------
 * THE GAP THIS CLOSES
 * ---------------------------------------------------------------------------
 *
 * `FamilyAccessService::enable()` opens the door and then sends the family
 * nothing at all. A parent whose access has just been granted has to be told, by
 * a human, that a portal exists; find `/family/{masjid}/sign-in`; type the
 * address the OFFICE chose, which may not be the one they use; and then fetch a
 * six-digit code that dies in ten minutes. Every one of those steps is a place
 * to give up, and the measurement says they do: Al-Razi had ten family logins
 * enabled and five had never signed in once.
 *
 * So this mails a link that lands them inside. It is modelled on the staff
 * invite (`App\Services\Auth\AccountAccessService`) — same seven days, same
 * single use, same fragment-only URL — and shares no code with it, because that
 * one is a framework password broker over `users` keyed by a globally-unique
 * email, and a parent is a `contacts` row whose address is unique only within
 * one organisation. See the migration for that argument in full.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE TOKEN IS, AND WHAT MAKES IT SAFE TO PUT IN AN INBOX
 * ---------------------------------------------------------------------------
 *
 * It is 32 bytes of CSPRNG output, hex-encoded, stored only as an HMAC-SHA256
 * digest keyed on `APP_KEY`. It is a bearer credential to a specific child's
 * photographs, marks and safeguarding conversations, so every one of these holds
 * and each is asserted in tests/Feature/FamilyPortalInviteTest.php:
 *
 *  1. **Hashed at rest.** Nothing that can read the table can read a link.
 *  2. **Single use**, burned by a compare-and-swap inside the same transaction
 *     that mints the session — so a double-tapped button or a mail client
 *     prefetching the URL cannot produce two sessions.
 *  3. **Seven days** (`config('family.invite.ttl_days')`), and dead at the
 *     minute after.
 *  4. **Bound to the contact AND to the address it was mailed to.** Redemption
 *     re-reads `contacts.login_email` and refuses if it has moved. The office
 *     re-addresses a login precisely when the old mailbox was wrong, was a
 *     stranger's, or belonged to a parent who has separated from the family; the
 *     link in that mailbox must stop working at that moment and not seven days
 *     later.
 *  5. **Dead when access ends.** Two independent mechanisms, exactly as
 *     `FamilyAccessService` argues for revocation: `redeem()` re-reads
 *     `familyLoginIsActive()` from the database, AND `revoke()` /
 *     `releaseAddressFrom()` / a re-address actively stamp `invalidated_at` on
 *     every outstanding row. Neither is redundant — the read covers rows nobody
 *     remembered to stamp, and the stamp covers a future caller that reaches
 *     redemption by some other path.
 *  6. **One live link per contact.** Issuing invalidates whatever was
 *     outstanding, so "they never got it, send it again" cannot leave two
 *     working keys in two inboxes.
 *  7. **Throttled**, by counting rows rather than by a cache limiter — see
 *     `assertNotFlooding()`.
 *
 * ---------------------------------------------------------------------------
 * THE URL PUTS THE TOKEN IN THE FRAGMENT
 * ---------------------------------------------------------------------------
 *
 * A fragment is never transmitted to a server: not in the request line, so nginx
 * cannot log it; not in `Referer`, so the next site the reader visits cannot read
 * it; and not through any proxy, CDN or WAF in between. This is not a
 * theoretical preference — `AccountAccessService` records that as a QUERY STRING
 * the staff reset token WAS found in this production host's rotated nginx access
 * logs, beside the account's email address. The SPA reads `location.hash`,
 * scrubs it, and POSTs the token in a request body.
 */
class FamilyInviteService
{
    public function __construct(private readonly FamilyAccessService $access)
    {
    }

    /**
     * Mint a link and mail it to the address on file.
     *
     * @throws RuntimeException when this contact may not hold a family login,
     *                          has no live one, or has been sent too many
     *                          invites this hour. Every one of these is a
     *                          REFUSAL an operator can read and act on, not an
     *                          error — the controller answers 422.
     * @throws InviteDeliveryFailed when the email did not go. A subclass, caught
     *                          FIRST by the controller and answered 500, because
     *                          it is the one failure here that says nothing about
     *                          the member.
     */
    public function issue(Contact $contact, ?User $actor = null, ?string $ip = null): ContactPortalInvite
    {
        // THE SAME CHECK `enable()` MAKES, not a second copy of it. Guardian
        // edges only: a login on a contact who is nobody's guardian is a STUDENT
        // login, which `GroupAudience::standingIn()` would grant the whole class
        // feed — every classmate's photograph, with nobody's consent — plus the
        // participant threads where a teacher and a guardian discuss a
        // safeguarding concern. A preview or a second door computed by a second
        // implementation is one that agrees today; this one IS the write's check.
        //
        // It is checked again HERE rather than trusted to have been checked at
        // enable() because standing lapses: a ward is deleted, a guardian edge is
        // removed by ordinary roster work, and `FamilyAccessService` deliberately
        // does NOT revoke in that case (see its docblock — revoking there would
        // burn a family's sign-in every term). An office must not be able to mail
        // a fresh key to a login that has since stopped qualifying for one.
        $this->access->assertMayHoldAFamilyLogin($contact);

        if (! $contact->familyLoginIsActive()) {
            throw new RuntimeException(
                'This member has no parent portal sign-in to invite them to. '
                . 'Enable sign-in first, then send the invite.'
            );
        }

        $address = Str::lower(trim((string) $contact->login_email));

        if ($address === '') {
            // Unreachable while `familyLoginIsActive()` holds — `enable()` is the
            // only writer of `login_enabled_at` and it always writes an address —
            // but a link mailed to nowhere is the one failure that would look
            // like success on the screen, so it is refused rather than assumed.
            throw new RuntimeException(
                'This member has no sign-in email address on file, so there is nowhere to send an invite.'
            );
        }

        $this->assertNotFlooding($contact);

        // The plaintext's ENTIRE LIFE: generated here, put into a URL, handed to
        // the mailer, and gone when this method returns. It is never returned to
        // the caller, never logged and never stored — the only copy in the world
        // after this is in the parent's inbox.
        $token = bin2hex(random_bytes(32));

        return DB::transaction(function () use ($contact, $address, $token, $actor, $ip): ContactPortalInvite {
            // ONE LIVE LINK. Done first and in the same transaction, so there is
            // no instant at which two working keys exist.
            ContactPortalInvite::invalidateOutstandingFor($contact);

            $invite = ContactPortalInvite::create([
                'masjid_id' => $contact->masjid_id,
                'contact_id' => $contact->id,
                'login_email' => $address,
                'token_hash' => $this->hash($token),
                'expires_at' => Carbon::now()->addDays($this->ttlDays()),
                'issued_ip' => $ip,
            ]);

            // INSIDE the transaction on purpose, and this is the unusual half.
            // The two failure directions are not symmetrical:
            //
            //   - mail fails, we roll back: nothing changed, the previous invite
            //     is still live, and the office is told the send failed. Correct.
            //   - mail fails, we had committed: the office reads "sent", the
            //     parent has nothing, and the previously-working link has been
            //     invalidated by a send that never happened. That is the silent
            //     failure this codebase keeps finding (memory: "write fails, UI
            //     says success").
            //
            // The residual risk is the mirror case — the mail goes out and the
            // COMMIT then fails — which leaves a parent holding a link that
            // resolves to no row. That is a 410 and a re-send, which is the
            // cheap direction to be wrong in.
            //
            // Unlike `FamilyLoginService::deliver()`, the exception is NOT
            // swallowed: that one is on an unauthenticated endpoint where an
            // error path would be an existence oracle, and this one is an
            // authenticated admin request whose entire purpose is to report
            // whether a parent was written to.
            $this->deliver($contact, $token);

            // On the record, the way `enable` and `revoke` are: what is being
            // handed out is a stranger's view of a specific child's file, and
            // "a link was sent" is not an answer to "who sent it, and where?".
            ContactLoginEvent::create([
                // Explicit rather than left to the creating hook: this must be
                // the CONTACT's tenant even in an unbound context.
                'masjid_id' => $contact->masjid_id,
                'contact_id' => $contact->id,
                'action' => ContactLoginEvent::ACTION_INVITE_SENT,
                // The address the mail actually went to, snapshotted — the
                // contact's column may move afterwards.
                'login_email' => $address,
                'actor_user_id' => $actor?->id,
                // Snapshotted for the reason the migration records: a name read
                // back through the foreign key is the name that person has
                // today, and no name at all once they are deleted.
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'actor_ip' => $ip,
            ]);

            return $invite;
        });
    }

    /**
     * Exchange a link's token for a family session, or null.
     *
     * NULL FOR EVERY FAILURE, and the controller renders one body for all of
     * them — unknown token, expired, already used, superseded by a newer invite,
     * access revoked since, address moved since. The uniformity costs nothing
     * here that it costs elsewhere in this realm: the request carries no address
     * and no identifier a caller could enumerate, so there is nothing a more
     * specific message could disclose. It is uniform because a parent needs to
     * know exactly one thing — ask the school for a new link — and six wordings
     * of that are six chances to say something true about a family.
     *
     * @return array{contact: Contact, token: NewAccessToken}|null
     */
    public function redeem(string $submittedToken, ?string $ip = null): ?array
    {
        $submitted = trim($submittedToken);

        if ($submitted === '') {
            return null;
        }

        // Tenant-scoped by BelongsToMasjid — `ResolveFamilyGuestTenant` has bound
        // the context from the {masjid_id} in the URL before any caller reaches
        // here, so a token belonging to another organisation is simply not found.
        // Never hand-filtered on masjid_id (.claude/rules/tenant-scoping.md).
        $invite = ContactPortalInvite::query()
            ->where('token_hash', $this->hash($submitted))
            ->first();

        if ($invite === null || ! $invite->isLive()) {
            return null;
        }

        // The SoftDeletes scope is the point of the plain query: a contact
        // deleted from the directory takes their portal access with them, and a
        // link in an inbox must not outlive the record.
        $contact = Contact::query()->whereKey($invite->contact_id)->first();

        if ($contact === null || ! $contact->familyLoginIsActive()) {
            return null;
        }

        // BOUND TO THE ADDRESS IT WAS MAILED TO. Re-read from the contact rather
        // than trusted from the row: the office re-addresses a login exactly when
        // the old mailbox should stop opening the child's file, and the active
        // invalidation in `FamilyAccessService` is the other half of this, not a
        // substitute for it.
        if (Str::lower(trim((string) $contact->login_email)) !== Str::lower((string) $invite->login_email)) {
            return null;
        }

        return $this->consume($invite, $contact);
    }

    /**
     * The most recent invite for a contact, for the office panel. Null if none.
     *
     * Not scoped to live rows: "we sent one on Tuesday and it expired" is the
     * answer the screen most needs, and hiding a dead row would make the panel
     * read identically to one where nothing was ever sent — which is the exact
     * blindness this feature exists to fix.
     */
    public function lastInviteFor(Contact $contact): ?ContactPortalInvite
    {
        return ContactPortalInvite::withoutMasjidScope()
            ->where('masjid_id', $contact->masjid_id)
            ->where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->first();
    }

    // ------------------------------------------------------------- internals

    /**
     * Burn the link and mint the session, atomically.
     *
     * The UPDATE is a compare-and-swap rather than a read-then-write, the same
     * call `FamilyLoginService::consume()` makes and for the same measured
     * reason: two requests carrying one correct credential arrive together far
     * more often than intuition suggests (a double-tapped button, a mail client
     * or a corporate link-scanner prefetching the URL), and the loser must get
     * nothing rather than a second token. `affected === 0` IS the loser.
     *
     * `invalidated_at` is in the predicate as well as `consumed_at`, so a link
     * revoked in the same instant it is clicked loses rather than races.
     *
     * WHAT THIS DELIBERATELY DOES NOT WRITE is `contacts.verified_at`. Clicking
     * a link does demonstrate control of the mailbox, and that is exactly why it
     * is tempting — but `verified_at` is what `Contact::memberAccessIsActive()`
     * turns on, so writing it here would silently hand the reader of that inbox
     * the MEMBER APP as well as the portal, from an act nobody described that
     * way. `FamilyLoginService::consume()` does not write it either; the member
     * realm sets it through its own door (`MemberSignupService`). One realm, one
     * grant.
     *
     * @return array{contact: Contact, token: NewAccessToken}|null
     */
    private function consume(ContactPortalInvite $invite, Contact $contact): ?array
    {
        return DB::transaction(function () use ($invite, $contact): ?array {
            $affected = ContactPortalInvite::withoutMasjidScope()
                ->whereKey($invite->getKey())
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['consumed_at' => Carbon::now()]);

            if ($affected === 0) {
                return null;
            }

            // Operator visibility only — nothing reads it and nothing authorizes
            // on it. It is what makes the office panel able to say whether the
            // invite ever actually worked. forceFill because the four login_*
            // columns are deliberately not fillable.
            $contact->forceFill(['last_login_at' => Carbon::now()])->save();

            // THE ORDINARY FAMILY SESSION, minted by the same method the code
            // door and the password door use. Nothing about `config/family.php`
            // is loosened by this feature: the abilities are
            // `Contact::FAMILY_TOKEN_ABILITIES`, the lifetime is the `family`
            // guard's `session.expiration_minutes`, and `family.active` re-reads
            // liveness on every subsequent request. An invite is a way IN, not a
            // different kind of session.
            return ['contact' => $contact, 'token' => $contact->createFamilyToken()];
        });
    }

    /**
     * How many invites this contact has been sent in the last hour, and stop.
     *
     * COUNTED IN THE DATABASE, not in a rate limiter, and that is the same call
     * `contact_login_codes.attempts` makes: a limiter lives in the cache, a cache
     * flush is an ordinary deploy step, and what is being bounded here is a real
     * family's mailbox filling with working keys to their child's records —
     * because a client is stuck retrying, because an office is clicking in
     * frustration, or because a staff token has been stolen. A control that a
     * deploy silently re-arms is not the control for that.
     *
     * Keyed on the CONTACT rather than the actor or the IP: the harm is to the
     * mailbox, and two different administrators sending five each is the same
     * flood as one sending ten.
     */
    private function assertNotFlooding(Contact $contact): void
    {
        $ceiling = max(1, (int) config('family.invite.sends_per_hour_per_contact', 3));

        $recent = ContactPortalInvite::withoutMasjidScope()
            ->where('masjid_id', $contact->masjid_id)
            ->where('contact_id', $contact->id)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();

        if ($recent >= $ceiling) {
            throw new RuntimeException(sprintf(
                'This member has already been sent %d portal invites in the last hour. '
                . 'The most recent link is still the one that works — wait an hour before sending another, '
                . 'or check with them that the address on file is right.',
                $recent,
            ));
        }
    }

    /**
     * Put the link in the parent's inbox.
     *
     * TO `login_email` AND NOWHERE ELSE. Never `contacts.email`: that column is
     * imported in bulk from an admissions spreadsheet, is routinely a HOUSEHOLD
     * address shared by both parents, and was verified by nobody — the argument
     * `FamilyAccessService` makes at length about why the credential address is
     * typed by an administrator rather than derived. A link mailed to the
     * imported address would be this feature quietly undoing that decision.
     *
     * The failure is NOT swallowed the way `FamilyLoginService::deliver()`
     * swallows its own: that one is on an unauthenticated endpoint where an
     * error path would be an existence oracle, and this one is an admin pressing
     * a button whose entire purpose is to report whether a parent was written
     * to. A silent failure here is the "write fails, UI says success" shape this
     * codebase keeps finding. The throw rolls the transaction back, so no invite
     * row and no `invite_sent` audit row survive an email that never went, and
     * the link the parent may already be holding is not invalidated.
     *
     * IT IS RE-TYPED ON THE WAY OUT, and that is the whole reason for the
     * try/catch. `Symfony\Component\Mailer\Exception\TransportException` extends
     * `\RuntimeException`, and `ContactFamilyLoginController::invite()` answers a
     * `RuntimeException` with a 422 carrying the exception's own message —
     * because that is how this service's REFUSALS reach an operator. Unwrapped,
     * a relay outage would therefore have been reported as "there is something
     * wrong with this member", with the transport's message as the body. See
     * InviteDeliveryFailed.
     */
    private function deliver(Contact $contact, string $token): void
    {
        // Masjid is the tenant, not a tenant-scoped model, so it carries no
        // global scope to bypass — EnsureCrmEnabled reads it the same way.
        $masjid = Masjid::find($contact->masjid_id);

        try {
            Mail::to($contact->login_email)->send(new FamilyPortalInviteMail(
                orgName: $masjid?->name ?: (string) config('app.name'),
                url: $this->linkFor($contact, $token),
                expiresInDays: $this->ttlDays(),
                recipientName: $contact->first_name,
                orgEmail: $masjid?->email,
            ));
        } catch (Throwable $e) {
            // The message is deliberately NOT carried through. A mailer
            // exception routinely quotes the recipient address and the relay's
            // own response, and this one is on its way to a screen; the original
            // travels as `previous` so the log keeps every word of it.
            throw new InviteDeliveryFailed('The portal invite could not be emailed.', 0, $e);
        }
    }

    /**
     * The address in the email.
     *
     * The token is in the FRAGMENT — see the class docblock, and
     * `AccountAccessService`, which records the production nginx logs this
     * prevents. `http_build_query` so the SPA can parse it the same way the
     * staff reset screen does.
     *
     * Built from `config('app.url')`, matching `SendGroupNotificationJob`'s
     * sign-in link. A school that has pointed its OWN hostname at the portal
     * (config/portal.php) still receives this app's URL; making the two agree is
     * a change to both and belongs with whichever of them moves first.
     */
    private function linkFor(Contact $contact, string $token): string
    {
        return rtrim((string) config('app.url'), '/')
            . '/family/' . $contact->masjid_id . '/invite#'
            . http_build_query(['token' => $token]);
    }

    /**
     * The digest actually stored. HMAC-SHA256 keyed on the application key —
     * the same construction `FamilyLoginService::hash()` uses.
     *
     * The key is read through `config('app.key')` (already base64-prefixed) and
     * never handled beyond this line.
     */
    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function ttlDays(): int
    {
        return max(1, (int) config('family.invite.ttl_days', 7));
    }
}
