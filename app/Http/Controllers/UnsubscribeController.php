<?php

namespace App\Http\Controllers;

use App\Models\EmailSuppression;
use App\Models\Masjid;
use App\Services\Broadcast\EmailSuppressionService;
use App\Support\PublicTenant;
use Illuminate\Http\Response;

/**
 * The public unsubscribe landing for broadcast email (T-042c).
 *
 * A person who cannot sign in must still be able to leave. So this controller
 * sits on `routes/web.php` with **no auth, no session and no tenant binding** —
 * the same posture as ConnectOnboardingLandingController and the SMS webhook —
 * and the encrypted token in the URL is the entire credential. See
 * App\Services\Broadcast\EmailSuppressionService for the token format and for
 * why it is authenticated encryption rather than `URL::signedRoute` (short
 * version: `hasValidSignature()` compares the absolute URL including the host,
 * this deploy answers on several hostnames, and a proxy rewriting Host would
 * 403 every unsubscribe on the platform while every test still passed).
 *
 * ## GET renders. POST acts. That split is load-bearing.
 *
 * Outlook SafeLinks, Gmail's link prefetcher and corporate mail scanners FOLLOW
 * links in email. A GET that suppressed would unsubscribe people who never
 * clicked, and the organisation would watch its audience evaporate with no
 * complaint to explain it. So the GET shows a page with a button and changes
 * nothing; the POST is the act. This is also what RFC 8058 one-click expects,
 * and the `List-Unsubscribe` header points at the POST for exactly that reason.
 *
 * ## Nothing here is an enumeration oracle
 *
 * The URL carries no contact id and no address — only the tenant id and the
 * ciphertext — so there is no id to increment and no way to unsubscribe somebody
 * else by editing one. Every failure (edited token, wrong verb's token, an
 * organisation that has been offboarded, a token minted under a rotated
 * `APP_KEY`) renders the SAME page with the SAME 403, naming no organisation and
 * no address. A probe cannot tell those cases apart, which is the contract
 * .claude/rules/auth-permissions.md holds the sign-in code endpoints to: neither
 * endpoint may become a directory.
 *
 * ## What it stops, and what it does not
 *
 * Broadcast email from ONE organisation, permanently, until the person
 * themselves resumes it. Not one message. Not the whole platform. And not
 * transactional mail: receipts, annual statements, registration confirmations,
 * replies to a message the person sent, and family sign-in codes keep arriving,
 * because the suppression is consulted only in
 * `BroadcastAudienceResolver::emailAudience()`. The confirmation page says so in
 * plain words, at the moment of the decision, because a donor who thinks they
 * have just cancelled their tax receipt will call.
 *
 * ## An admin cannot undo this
 *
 * There is no admin route to `release()`, and there must never be one. Staff can
 * see that somebody unsubscribed; they cannot undo it. The re-subscribe form on
 * the landing page carries a purpose-scoped token minted for that page alone, so
 * the URL in `List-Unsubscribe` — which mailbox providers POST automatically —
 * can only ever stop mail, never resume it. That scoping does not pretend to
 * stop a person holding the link from walking both steps by hand; see the note
 * on EmailSuppressionService for exactly how far it goes.
 *
 * ## APP_KEY rotation
 *
 * Rotating `APP_KEY` invalidates every unsubscribe link already sitting in
 * somebody's inbox — they will land on the "no longer valid" page. That is a
 * real operational consequence of the token being encrypted rather than stored,
 * and the alternative (a bearer `unsubscribe_token` column on `contacts`) trades
 * it for a permanent credential on a row that the merge path force-deletes.
 * Recorded here so a rotation is planned with a re-send rather than discovered.
 */
class UnsubscribeController extends Controller
{
    public function __construct(private readonly EmailSuppressionService $suppression)
    {
    }

    /**
     * The confirmation page. Renders; suppresses nobody.
     *
     * A person who is already unsubscribed sees that, plus the way back — rather
     * than a button that pretends there is something left to do.
     */
    public function show(string $masjid_id, string $token): Response
    {
        $link = $this->resolve($masjid_id, $token, EmailSuppressionService::PURPOSE_UNSUBSCRIBE);

        if ($link === null) {
            return $this->invalid();
        }

        $already = $this->suppression->isSuppressed($link['masjid_id'], $link['email']);

        return $this->page($already ? 'already' : 'confirm', $link, $token);
    }

    /**
     * The act. Idempotent — a second click, or a mail scanner replaying the
     * one-click POST, updates the one row rather than writing a second.
     */
    public function store(string $masjid_id, string $token): Response
    {
        $link = $this->resolve($masjid_id, $token, EmailSuppressionService::PURPOSE_UNSUBSCRIBE);

        if ($link === null) {
            return $this->invalid();
        }

        $this->suppression->suppress(
            $link['masjid_id'],
            $link['email'],
            EmailSuppression::REASON_UNSUBSCRIBE_LINK,
            $link['broadcast_id'],
        );

        return $this->page('done', $link, $token);
    }

    /**
     * The way back, for the subscriber and nobody else.
     *
     * Reached only from the form on the page above, whose token carries
     * `purpose = r`. A token lifted out of an email body carries `purpose = u`
     * and is refused here, so a forwarded message is not authority to resume
     * mail to a mailbox.
     */
    public function resubscribe(string $masjid_id, string $token): Response
    {
        $link = $this->resolve($masjid_id, $token, EmailSuppressionService::PURPOSE_RESUBSCRIBE);

        if ($link === null) {
            return $this->invalid();
        }

        $this->suppression->release($link['masjid_id'], $link['email']);

        return $this->page('resubscribed', $link, $token);
    }

    /**
     * Decode the link and check everything that has to be true, returning null
     * for every way it can be false.
     *
     * Three checks, each of which is a way to be wrong:
     *
     *  - the token decrypts, is this format's version, and is for THIS verb;
     *  - the tenant in the path is the tenant inside the token, so a token
     *    cannot be spliced onto another organisation's path;
     *  - the organisation still exists (`masjids` soft-deletes, so an offboarded
     *    id keeps matching its own rows forever — App\Support\PublicTenant).
     *
     * @return array{masjid_id: int, broadcast_id: ?int, email: string, masjid: Masjid}|null
     */
    private function resolve(string $masjidId, string $token, string $purpose): ?array
    {
        $link = $this->suppression->parse($token, $purpose);

        if ($link === null || $link['masjid_id'] !== (int) $masjidId) {
            return null;
        }

        if (! PublicTenant::exists($link['masjid_id'])) {
            return null;
        }

        $masjid = Masjid::find($link['masjid_id']);

        if ($masjid === null) {
            return null;
        }

        return $link + ['masjid' => $masjid];
    }

    /**
     * The one page every valid outcome renders.
     *
     * `$resubscribeToken` is minted HERE rather than reused from the URL: the
     * incoming token may only unsubscribe, and the form on this page may only
     * resume. Two purposes, two tokens.
     *
     * @param  array{masjid_id: int, broadcast_id: ?int, email: string, masjid: Masjid}  $link
     */
    private function page(string $state, array $link, string $token): Response
    {
        return response()->view('unsubscribe.status', [
            'state' => $state,
            'orgName' => (string) $link['masjid']->name,
            'address' => $link['email'],
            'masjidId' => $link['masjid_id'],
            'token' => $token,
            'resubscribeToken' => $this->suppression->token(
                $link['masjid_id'],
                $link['email'],
                $link['broadcast_id'],
                EmailSuppressionService::PURPOSE_RESUBSCRIBE,
            ),
        ]);
    }

    /**
     * The refusal. 403, and it names NOTHING — no organisation, no address, no
     * reason. Every failure funnels here so the page cannot be used to learn
     * whether an address or an organisation exists.
     */
    private function invalid(): Response
    {
        return response()->view('unsubscribe.status', [
            'state' => 'invalid',
            'orgName' => null,
            'address' => null,
            'masjidId' => null,
            'token' => null,
            'resubscribeToken' => null,
        ], 403);
    }
}
