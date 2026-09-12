<?php

namespace App\Services\Broadcast;

use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * Every read and write of the broadcast-email opt-out goes through here
 * (T-042c), and so does the minting of the link that produces one.
 *
 * The sibling of App\Services\Sms\SmsConsentService. Same four rules, same
 * vocabulary, one channel over.
 *
 *  1. **The timestamp is server time.** `suppressed_at` is never taken from a
 *     request. An opt-out date a client can set is one a client can backdate,
 *     and the date is half of what makes the record evidence.
 *
 *  2. **An opt-out is written to the durable list, not just to the contact.**
 *     `email_suppressions` has no foreign key to `contacts` and therefore
 *     survives the merge that force-deletes the row, the re-import that
 *     recreates it, and the admin who deletes and re-adds a person next month.
 *     `contacts.email_opted_out_at` is mirrored for DISPLAY and is never read to
 *     decide whether to send. It is kept honest from two directions — this
 *     service writes it whenever the OPT-OUT changes, and App\Models\Contact's
 *     own hooks rewrite it whenever the ADDRESS changes — because a display copy
 *     that can silently disagree with the table that decides is a defect waiting
 *     to be trusted, and the contacts screen does trust it.
 *
 *  3. **Only the subscriber can undo it.** `release()` has exactly one caller:
 *     App\Http\Controllers\UnsubscribeController, reached from a link sent to
 *     the mailbox in question. There is no admin endpoint, and there must never
 *     be one — a staff button that re-enables mail to somebody who unsubscribed
 *     is the button that turns an obligation into a complaint. Pinned by
 *     `an_admin_cannot_re_subscribe_somebody_who_unsubscribed`.
 *
 *  4. **The suppression belongs to an ADDRESS, not to a person.** That is what
 *     makes the merge rule below the only defensible one, and it is why the
 *     unsubscribe link carries the address it was sent to rather than a contact
 *     id — see the token section.
 *
 * ## The link: what is in the URL, and why there is no id in it
 *
 * The URL shape is:
 *
 *     GET  /unsubscribe/{masjid_id}/{token}
 *     POST /unsubscribe/{masjid_id}/{token}                 (the actual opt-out)
 *     POST /unsubscribe/{masjid_id}/{token}/resubscribe
 *
 * `{token}` is `Crypt::encryptString()` over `1|{purpose}|{masjidId}|{broadcastId}|{address}`,
 * re-alphabetised into URL-safe base64. Laravel's encrypter is AUTHENTICATED
 * (AES + HMAC over the payload), so a token that has been edited by so much as
 * one character throws `DecryptException` and the landing renders "this link is
 * no longer valid" — naming no organisation and no address.
 *
 * Three properties fall out of that, and each was a requirement:
 *
 *  - **Unguessable.** The token is ciphertext under `APP_KEY`. There is no
 *    sequence to walk.
 *  - **Not an enumeration oracle.** The URL contains no contact id and no
 *    address. There is nothing to increment, so no version of this page can
 *    answer "does contact 412 of masjid 7 exist?". Editing the token does not
 *    unsubscribe somebody else; it produces an invalid link.
 *  - **It outlives the contact row.** The address travels IN the link, so an
 *    unsubscribe still works after the contact has been merged away,
 *    force-deleted or re-imported — which is exactly the case the suppression
 *    table exists for. A link keyed on `contact_id` would 404 in precisely the
 *    situation where the opt-out matters most, and would suppress the WRONG
 *    address whenever a contact's email had been edited since the send.
 *
 * ### Why not `URL::signedRoute()`
 *
 * It was the obvious choice and it is the wrong one HERE, for a reason this
 * deployment has already been bitten by once. `hasValidSignature()` compares the
 * full ABSOLUTE url including the host, and this application serves several
 * hostnames from one deploy (`manara.*`, `masjid.hopetechapps.com`, the portal
 * hosts). A proxy that rewrites Host before PHP sees it makes every signature
 * check fail — the same class of trap as `TWILIO_WEBHOOK_URL` in
 * .claude/rules/broadcasts.md. The failure mode there is that EVERY unsubscribe
 * link on the platform returns 403 while looking perfectly healthy in tests,
 * i.e. the compliance obligation this whole feature exists to meet is silently
 * unmet. The encrypted token is host-independent and gives the same
 * authenticity guarantee from the same key, so nothing is traded away.
 *
 * Both mechanisms share one weakness worth writing down: **rotating `APP_KEY`
 * invalidates every unsubscribe link already in somebody's inbox.** A rotation
 * therefore needs a re-send or an operator-side suppression, not a shrug.
 *
 * ### The link never expires
 *
 * No expiry is set, deliberately. An unsubscribe link that stops working is an
 * unhonoured opt-out, and a person who kept an email from last year is exactly
 * the person most likely to want out.
 *
 * ### The re-subscribe token is a DIFFERENT token
 *
 * The link in an email body carries `purpose = u` and can ONLY unsubscribe. The
 * re-subscribe form is minted fresh on the landing page with `purpose = r`.
 *
 * Be precise about what that buys, because it is narrower than it looks. It
 * means the URL sitting in `List-Unsubscribe` can never resume mail — which
 * matters, because mailbox providers and scanners POST that URL by themselves,
 * and a single-purpose token that both stopped and started would make an
 * automated retry a coin flip. It does NOT stop a determined human who holds the
 * link from walking unsubscribe → landing page → re-subscribe; nothing short of
 * an account could. Whoever holds a link sent to a mailbox is treated as that
 * mailbox's owner, exactly as every unsubscribe link on the internet is. The
 * asymmetry that does hold is the one that matters: an accidental or automated
 * request can only ever move in the safe direction.
 *
 * ## Merge: the survivor is RE-CHECKED, nothing is transplanted
 *
 * `ContactsController::merge` force-deletes the absorbed contact. Unlike SMS
 * consent there is nothing to carry across, because there is no email CONSENT
 * record — email is opt-OUT only — and the opt-out itself already lives in a
 * table with no foreign key to either row. So `reconcileOnMerge()` does the one
 * thing that is left: it re-checks the SURVIVOR's own address against the
 * durable list and mirrors the answer, so a merge can never produce a directory
 * row that looks mailable when the address is suppressed.
 */
class EmailSuppressionService
{
    /** Token minted into a broadcast email; may only stop mail. */
    public const PURPOSE_UNSUBSCRIBE = 'u';

    /** Token minted onto the landing page after an opt-out; may only resume it. */
    public const PURPOSE_RESUBSCRIBE = 'r';

    /** Payload version, so the format can change without breaking old links. */
    private const TOKEN_VERSION = '1';

    /**
     * The one shape an address is ever stored or compared in.
     *
     * Lower-cased and trimmed. Nothing cleverer: stripping Gmail dots or `+tags`
     * would silence addresses the person never asked to silence, and would make
     * the stored key something they cannot recognise on a support call.
     *
     * Returns null for anything that is not an address at all, so a blank or
     * malformed value can never become a key that matches other rows.
     */
    public static function normalize(?string $raw): ?string
    {
        $trimmed = strtolower(trim((string) $raw));

        if ($trimmed === '' || ! str_contains($trimmed, '@')) {
            return null;
        }

        return $trimmed;
    }

    /**
     * The two URLs one broadcast email needs for one recipient.
     *
     * `page` is the GET landing that goes in the footer; `one_click` is the POST
     * that goes in the `List-Unsubscribe` header (RFC 8058). They are two VERBS
     * on the same path carrying the same token, so the two strings are byte-
     * identical today — kept apart anyway because they mean different things and
     * a future change to either must not silently move the other. See
     * UnsubscribeController for why a GET must never perform the opt-out.
     *
     * That the header URL also answers GET is the useful failure mode: a client
     * that ignores `List-Unsubscribe-Post` and follows the link lands on the
     * confirmation page rather than on a 405.
     *
     * Built at SEND time by EmailChannel, never inside the queued Mailable: a
     * serialized job that mints its own URL mints it from whatever host the
     * worker happens to think it is on.
     *
     * @return array{page: string, one_click: string}
     */
    public function urls(int $masjidId, ?string $email, ?int $broadcastId = null): array
    {
        $token = $this->token($masjidId, $email, $broadcastId, self::PURPOSE_UNSUBSCRIBE);

        return [
            'page' => URL::route('unsubscribe.show', ['masjid_id' => $masjidId, 'token' => $token]),
            'one_click' => URL::route('unsubscribe.store', ['masjid_id' => $masjidId, 'token' => $token]),
        ];
    }

    /**
     * Mint one token. See the class docblock for the format and why it is
     * encryption rather than a signature.
     */
    public function token(int $masjidId, ?string $email, ?int $broadcastId, string $purpose): string
    {
        $payload = implode('|', [
            self::TOKEN_VERSION,
            $purpose,
            $masjidId,
            $broadcastId ?: 0,
            (string) (self::normalize($email) ?? ''),
        ]);

        // base64 from the encrypter carries `+`, `/` and `=`, none of which
        // survive a route segment intact. Re-alphabetised, padding dropped.
        return rtrim(strtr(Crypt::encryptString($payload), '+/', '-_'), '=');
    }

    /**
     * Read a token back, or null when it is absent, edited, or not the purpose
     * the caller asked for.
     *
     * Every failure returns null rather than throwing: the caller renders one
     * indistinguishable "no longer valid" page for all of them, so a probe
     * cannot tell a tampered token from an unknown organisation from a
     * well-formed token for the wrong verb.
     *
     * @return array{masjid_id: int, broadcast_id: ?int, email: string}|null
     */
    public function parse(?string $token, string $expectedPurpose): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $base64 = strtr($token, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);

        try {
            $payload = Crypt::decryptString($base64);
        } catch (DecryptException) {
            return null;
        }

        $parts = explode('|', $payload, 5);

        if (count($parts) !== 5) {
            return null;
        }

        [$version, $purpose, $masjidId, $broadcastId, $email] = $parts;

        if ($version !== self::TOKEN_VERSION || $purpose !== $expectedPurpose) {
            return null;
        }

        $normalized = self::normalize($email);

        if ($normalized === null || (int) $masjidId <= 0) {
            return null;
        }

        return [
            'masjid_id' => (int) $masjidId,
            'broadcast_id' => ((int) $broadcastId) ?: null,
            'email' => $normalized,
        ];
    }

    /**
     * Suppress an address for a tenant — the durable opt-out.
     *
     * Idempotent by design: a person clicks twice, a mail scanner replays the
     * one-click POST, and a second opt-out must update the one row rather than
     * fight the unique index. A previously RELEASED row is re-suppressed rather
     * than duplicated, so the history stays on a single row.
     *
     * ## A replay does not move the date the opt-out began
     *
     * `suppressed_at` and `broadcast_id` are written when the opt-out is
     * genuinely NEW — the row does not exist, or it exists and has been
     * released — and never on a repeat of one already in force. That is not a
     * micro-optimisation, it is the whole evidentiary value of the row.
     *
     * A congregant unsubscribes on 1 September. On the 20th a corporate mail
     * scanner replays the one-click POST from that same message, or the person
     * clicks the footer link in an older broadcast again. If this method
     * re-stamped the timestamp, the only record of when the organisation was
     * asked to stop would now read 20 September — nineteen days of mail moved
     * onto the right side of the line by a request the person never made, in
     * the organisation's own favour, exactly when a CAN-SPAM complaint needs
     * the opposite. The same overwrite churns provenance: a token minted with
     * no broadcast id parses back to null and would null out the id of the
     * message that actually prompted the opt-out.
     *
     * So a repeat on an ACTIVE row writes nothing at all. `reason` travels with
     * the date rather than separately — a row reading "opted out 1 September,
     * reason: bounce" because a later bounce landed on an address that had
     * already unsubscribed is a record that contradicts itself. The mirror is
     * still refreshed on every call, so a contact row recreated by an import
     * after the opt-out still picks the badge up.
     *
     * Pinned by `replaying_the_one_click_post_does_not_move_the_date_the_opt_out_began`.
     */
    public function suppress(
        int $masjidId,
        ?string $email,
        string $reason = EmailSuppression::REASON_UNSUBSCRIBE_LINK,
        ?int $broadcastId = null,
    ): ?EmailSuppression {
        $address = self::normalize($email);

        if ($address === null) {
            return null;
        }

        $suppression = EmailSuppression::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('email_normalized', $address)
            ->first();

        $attributes = [
            'reason' => $reason,
            'broadcast_id' => $broadcastId,
            'suppressed_at' => Carbon::now(),
            // Re-suppressing after a release clears that release; the row keeps
            // carrying the whole story rather than being deleted and rewritten.
            'released_at' => null,
        ];

        if ($suppression?->isActive()) {
            // Already in force: nothing is rewritten. The opt-out is honoured
            // exactly as it stands and the record of when it began is left
            // alone — see the docblock above for why that matters.
        } elseif ($suppression) {
            // Released, and now re-suppressed. This IS a new opt-out, so it gets
            // a new date and new provenance on the same row.
            $suppression->forceFill($attributes)->save();
        } else {
            // `withoutMasjidScope()` lifts the read filter but NOT the creating
            // hook, which would stamp whatever tenant happens to be bound over
            // the one this opt-out is for. The landing page runs unbound so the
            // hook is inert there — but `suppress()` is also reachable from
            // bound code, and a suppression written into the wrong organisation
            // is an unhonoured opt-out in one place and a silenced congregant in
            // another. The documented bypass makes the explicit id always win.
            $suppression = app(TenantContext::class)->runWithout(
                fn () => EmailSuppression::withoutMasjidScope()->create(array_merge($attributes, [
                    'masjid_id' => $masjidId,
                    'email_normalized' => $address,
                ])),
            );
        }

        // The mirror carries the row's OWN date, not "now": a badge that says
        // the person unsubscribed today when the row says 1 September is a
        // second version of the same fact, and the one staff read.
        $this->mirrorOntoContacts($masjidId, $address, $suppression->suppressed_at);

        return $suppression;
    }

    /**
     * Release a suppression because the SUBSCRIBER asked to resume.
     *
     * Only ever called from App\Http\Controllers\UnsubscribeController, with a
     * `purpose = r` token minted onto the page the person is looking at. No
     * admin path reaches it — see rule 3 on this class.
     *
     * The row is kept and stamped rather than deleted: the record that an
     * opt-out existed and was withdrawn by the person themselves is the
     * evidence, and deleting it would also let a later re-unsubscribe write a
     * second contradictory row.
     */
    public function release(int $masjidId, ?string $email): ?EmailSuppression
    {
        $address = self::normalize($email);

        if ($address === null) {
            return null;
        }

        $suppression = EmailSuppression::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('email_normalized', $address)
            ->first();

        $suppression?->forceFill(['released_at' => Carbon::now()])->save();

        $this->mirrorOntoContacts($masjidId, $address, null);

        return $suppression;
    }

    /** Is this address suppressed for this tenant right now? */
    public function isSuppressed(int $masjidId, ?string $email): bool
    {
        return $this->suppressedAt($masjidId, $email) !== null;
    }

    /**
     * WHEN this address opted out, or null if it is mailable.
     *
     * The same question `isSuppressed()` asks, answered with the date, because
     * the two readers that want the date — the contact directory's badge and
     * Contact's own recompute hook — must not get it from a second query with a
     * second definition of "in force". One predicate, two callers.
     *
     * Returns the date the opt-out BEGAN, which after the change above is
     * stable across replays: it is the answer to "since when has this
     * organisation been asked to stop", not "when was this row last touched".
     */
    public function suppressedAt(int $masjidId, ?string $email): ?Carbon
    {
        $address = self::normalize($email);

        if ($address === null) {
            return null;
        }

        $suppression = EmailSuppression::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('email_normalized', $address)
            ->whereNull('released_at')
            ->first();

        if ($suppression === null) {
            return null;
        }

        // `suppressed_at` is NOT NULL in the schema, so the fallbacks should be
        // unreachable — they are here because `isSuppressed()` is now DERIVED
        // from this method, and a missing timestamp must degrade to "suppressed
        // on some unknown date" rather than to "mailable". The dangerous
        // direction is the one that resumes mail to somebody who opted out.
        return $suppression->suppressed_at ?? $suppression->created_at ?? Carbon::now();
    }

    /**
     * Which of these addresses are suppressed for this tenant.
     *
     * One query for a whole audience rather than one per recipient — the
     * audience resolver calls this with every candidate address at once.
     *
     * Deliberately NOT wrapped in a try/catch. A database hiccup here has two
     * silent outcomes and both are worse than the loud one: failing OPEN emails
     * people who unsubscribed, and failing CLOSED stops every announcement with
     * no explanation. Letting it throw makes the EMAIL channel `failed` and
     * visible on the delivery row, and .claude/rules/broadcasts.md guarantees
     * that costs no other channel its send.
     *
     * @param  array<int, string|null>  $addresses  raw, as stored on contacts.
     * @return array<int, string>  the suppressed subset, NORMALISED.
     */
    public function suppressedAmong(int $masjidId, array $addresses): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(fn ($address) => self::normalize($address), $addresses),
        )));

        if ($normalized === []) {
            return [];
        }

        return EmailSuppression::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->whereIn('email_normalized', $normalized)
            ->whereNull('released_at')
            ->pluck('email_normalized')
            ->all();
    }

    /**
     * Reconcile the email opt-out when one contact is merged into another,
     * BEFORE the source row is force-deleted.
     *
     * Nothing transplants, and that is not an omission. The opt-out lives in
     * `email_suppressions`, keyed on the address with no foreign key to either
     * contact, so the force-delete cannot reach it and there is nothing to
     * rescue. The survivor is simply RE-CHECKED against the durable list, so a
     * merge can never produce a directory row that looks mailable while the
     * address it carries is suppressed.
     *
     * If the survivor carries a DIFFERENT address from the source, the source's
     * suppression correctly does not follow: it suppressed an address, not a
     * name, and the survivor's address never asked to be left alone.
     */
    public function reconcileOnMerge(Contact $source, Contact $target): void
    {
        $at = $this->suppressedAt((int) $target->masjid_id, $target->email);

        if ($at === null) {
            return;
        }

        // The row's own date, not `now()`: the survivor is being told what the
        // durable list already says, and re-dating it here would make the
        // directory disagree with the evidence for no reason.
        $target->forceFill(['email_opted_out_at' => $at])->save();
    }

    /**
     * Copy the verdict onto every contact of this tenant carrying that address,
     * so the directory shows it without a join.
     *
     * The suppression row is the authority; this is the copy, and nothing reads
     * it to decide whether to send.
     *
     * This half of the mirror only ever fires for the address being suppressed
     * or released. The OTHER half — a contact whose address is edited to (or
     * away from) a suppressed one, which would otherwise leave the copy
     * describing an address the row no longer carries — is done by the
     * `saved` hook on App\Models\Contact, so that every writer is covered
     * (admin edit, importer, merge, console command) rather than only the ones
     * that remember to ask. Between the two, the copy cannot outlive the
     * address it described; see the decision recorded on that hook.
     *
     * `contacts.email` is whatever a human typed, so the comparison cannot be a
     * plain `=`: SQLite compares TEXT case-sensitively and MySQL's collation does
     * not, and a mirror that lands on one driver and not the other is worse than
     * none. `like` narrows the scan on both without dialect-specific SQL
     * (.claude/rules/migrations.md), and the exact comparison then happens in
     * PHP through the same normaliser that wrote the suppression key — the same
     * two-step SmsConsentService::contactsWithNumber uses.
     *
     * @return Collection<int, Contact>
     */
    private function mirrorOntoContacts(int $masjidId, string $address, ?Carbon $at): Collection
    {
        $contacts = Contact::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->whereNotNull('email')
            ->where('email', 'like', $address)
            ->get()
            ->filter(fn (Contact $contact) => self::normalize($contact->email) === $address)
            ->values();

        $contacts->each(function (Contact $contact) use ($at) {
            $contact->forceFill(['email_opted_out_at' => $at])->save();
        });

        return $contacts;
    }
}
