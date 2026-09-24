<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMessageAttachment;
use App\Models\GroupPostAttachment;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\GroupAudience;
use App\Support\GroupMedia;
use App\Support\PrivateMediaStream;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Playback for group VIDEO — the one place group media is reachable without an
 * Authorization header, and every line of this class is about why that is safe.
 *
 * ---------------------------------------------------------------------------
 * The problem
 * ---------------------------------------------------------------------------
 * Group photos are served as BYTES behind a bearer token: the SPA fetches the
 * whole file with `Authorization:` and shows it through an object URL, so no
 * URL for one exists that would work on its own. That arrangement cannot carry
 * video. A <video> element issues its own requests and cannot be given a
 * header, and a 100MB blob fetched in full before the first frame is not
 * playback. Seeking needs `Range`, which `Storage::download()` does not answer.
 *
 * ---------------------------------------------------------------------------
 * The answer, and what it preserves
 * ---------------------------------------------------------------------------
 * A SHORT-LIVED, VIEWER-BOUND, RELATIVE SIGNED URL, minted by an authenticated
 * "ticket" endpoint on each realm's own controller and redeemed here.
 *
 *   - **No permanent public URL.** The ticket expires (config
 *     `groups.media.video.playback_ttl_minutes`, 10 by default) and there is no
 *     accessor anywhere that produces a durable link. The bytes still live on a
 *     disk with no `url` (.claude/rules/private-uploads.md rule 1).
 *   - **The ownership chain is re-resolved, link by link, through its parent** —
 *     masjid → group → post → attachment, or masjid → group → thread → message →
 *     attachment. A foreign id anywhere is a MISS, not a filter (rule 4).
 *   - **Consent is re-asked AT ACCESS TIME.** The URL names its viewer, the
 *     signature covers that name so it cannot be edited, and this handler
 *     re-resolves that principal and puts the same question to
 *     App\Support\GroupAudience that the listing and the download endpoints ask.
 *     A guardian whose media consent is withdrawn mid-playback is refused on the
 *     NEXT range request, seconds later — the property the whole design exists
 *     for.
 *   - **The tenant is bound from the URL**, explicitly, before anything is
 *     looked up. This route carries none of the realms' middleware, and an
 *     UNBOUND context means BelongsToMasjid applies NO filter — which for this
 *     of all tables would mean every organisation's children. TenantContext's
 *     docblock lists this as case 3 (ResolveFamilyGuestTenant, the family
 *     sign-in surface, binds from the URL for the same reason).
 *
 * ---------------------------------------------------------------------------
 * What it costs, stated plainly
 * ---------------------------------------------------------------------------
 * Within the ticket's lifetime the URL is a bearer credential: anyone holding
 * the string can watch, not only the viewer it names. That is the residual risk
 * of every signed-media design, it is why the window is minutes rather than
 * hours, and it is why IMAGES WERE NOT MOVED ONTO THIS ROUTE — a photograph
 * loads fine as a blob, so it keeps the stronger arrangement and gains nothing
 * from this one. `Referrer-Policy: strict-origin-when-cross-origin` (global) is
 * what keeps the ticket out of an outbound Referer.
 */
class GroupMediaPlaybackController extends Controller
{
    public function __construct(private GroupAudience $audience)
    {
    }

    /**
     * GET /api/group-media/masjids/{masjid_id}/groups/{group_id}/posts/{post_id}/attachments/{attachment_id}/stream
     *
     * Behind `signed:relative`, so an edited path, an edited viewer or an
     * expired ticket never reaches this method.
     */
    public function post(Request $request, $masjid_id, $group_id, $post_id, $attachment_id): Response
    {
        $masjid = $this->bindTenant($masjid_id);

        $group = Group::findOrFail($group_id);
        // No withTrashed(), matching downloadAttachment exactly: a post an
        // admin hid this morning stops playing this morning.
        $post = $group->posts()->findOrFail($post_id);
        $attachment = $post->attachments()->findOrFail($attachment_id);

        $viewer = $this->viewer($request, $masjid);

        // The SAME question GroupPostsController::downloadAttachment asks, put
        // again here rather than trusted from when the ticket was minted.
        if (! $this->audience->mayReceive($viewer, $group, GroupAudience::DISCLOSURE_MEDIA)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this video.');
        }

        return $this->stream($request, $attachment);
    }

    /**
     * GET /api/group-media/masjids/{id}/groups/{id}/threads/{id}/messages/{id}/attachments/{id}/stream
     */
    public function message(Request $request, $masjid_id, $group_id, $thread_id, $message_id, $attachment_id): Response
    {
        $masjid = $this->bindTenant($masjid_id);

        $group = Group::findOrFail($group_id);
        $thread = $group->threads()->findOrFail($thread_id);
        $message = $thread->messages()->findOrFail($message_id);
        $attachment = $message->attachments()->findOrFail($attachment_id);

        $viewer = $this->viewer($request, $masjid);

        if (! $this->audience->mayReceiveThreadMedia($viewer, $group, $thread)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this video.');
        }

        return $this->stream($request, $attachment);
    }

    // ------------------------------------------------------------- internals

    /**
     * Bind the tenant from the {masjid_id} in the URL.
     *
     * NOT the isolation mechanism by itself — BelongsToMasjid's global scope is,
     * and this is what turns it on. Without it every lookup below would run
     * UNBOUND, which means no filter at all, and the id in the URL would select
     * any organisation's group. The masjid is loaded first so an id naming no
     * organisation is a 404 before anything else happens.
     *
     * The CRM gate is re-applied here too: `crm` guards every route that reaches
     * a group, and an organisation whose CRM was switched off this morning must
     * not keep serving video through a ticket minted yesterday.
     */
    private function bindTenant($masjidId): Masjid
    {
        $masjid = Masjid::findOrFail($masjidId);

        if (! $masjid->crm_enabled) {
            abort(Response::HTTP_FORBIDDEN, 'This organisation is not using the classroom features.');
        }

        app(TenantContext::class)->set((int) $masjid->id);

        return $masjid;
    }

    /**
     * The principal this ticket was minted for, re-resolved from the database.
     *
     * The signature covers `viewer_type` and `viewer_id`, so neither can be
     * edited into someone else's. What it cannot do is notice that the account
     * was disabled, revoked or removed from the organisation in the meantime —
     * so each branch re-checks exactly what its realm's middleware checks on
     * every ordinary request, and an unresolvable viewer is a 403 rather than a
     * null that GroupAudience would quietly answer "no standing" to (the same
     * answer, by accident, which is not the same thing as by decision).
     */
    private function viewer(Request $request, Masjid $masjid): Authenticatable
    {
        $type = (string) $request->query('viewer_type');
        $id = (int) $request->query('viewer_id');

        if ($type === GroupMedia::VIEWER_FAMILY) {
            // Contact is BelongsToMasjid, so the bound tenant already makes a
            // contact from another organisation a miss. familyLoginIsActive() is
            // what EnsureFamilyLoginActive asks on every family request:
            // enabled, not revoked, not deleted.
            $contact = Contact::find($id);

            if (! $contact instanceof Contact || ! $contact->familyLoginIsActive()) {
                abort(Response::HTTP_FORBIDDEN, 'This video link is no longer valid.');
            }

            return $contact;
        }

        if ($type === GroupMedia::VIEWER_STAFF) {
            $user = User::find($id);

            // `users` is NOT tenant-scoped (staff are global, joined to an
            // organisation through masjid_user), so the standing is checked by
            // hand — the one place in this file where the global scope cannot do
            // it for us. A teacher removed from the school this morning loses
            // playback now, not in ten minutes.
            //
            // TWO WAYS IN, because App\Support\TenantResolver has two. A
            // membership row is the ordinary one. The OWNER of an organisation
            // is the other, and it is not a nicety: the resolver's own docblock
            // records that `masjids.user_id` is set by factories, seeders and
            // two provisioning controllers that write NO `masjid_user` row, so
            // "every organisation provisioned since" has an owner with no
            // membership at all. Checking only the pivot here would have let an
            // office admin list a video, open the download endpoint, mint a
            // ticket — and then be refused the bytes, for being the owner.
            $belongs = $user !== null && (
                (int) $masjid->user_id === (int) $user->id
                || MasjidUser::where('masjid_id', (int) $masjid->id)
                    ->where('user_id', $user->id)
                    ->exists()
            );

            if (! $belongs) {
                abort(Response::HTTP_FORBIDDEN, 'This video link is no longer valid.');
            }

            return $user;
        }

        abort(Response::HTTP_FORBIDDEN, 'This video link is no longer valid.');
    }

    /**
     * Stream the bytes, honouring Range.
     *
     * VIDEO ONLY. An image reached through a ticket is refused rather than
     * served: photos keep the bearer-token blob path, and letting this route
     * answer for them would quietly create the durable-ish URL for a
     * photograph that the whole arrangement is built to avoid.
     */
    private function stream(Request $request, GroupPostAttachment|GroupMessageAttachment $attachment): Response
    {
        if (! GroupMedia::isPlayable($attachment)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if (! $attachment->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This video is no longer stored on the server.',
            ], Response::HTTP_NOT_FOUND);
        }

        return PrivateMediaStream::respond(
            $attachment->storage(),
            $attachment->path,
            // Sniffed from the bytes at upload and constrained to the configured
            // allowlist, so it is ours to state rather than the uploader's.
            (string) $attachment->mime_type,
            (string) $attachment->original_name,
            $request,
        );
    }
}
