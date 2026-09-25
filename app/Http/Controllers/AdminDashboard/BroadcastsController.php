<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Broadcasts\PreviewNewsletterRequest;
use App\Http\Requests\Admin\Broadcasts\StoreBroadcastRequest;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Services\Broadcast\BroadcastComposer;
use App\Services\Broadcast\Newsletter\NewsletterBlocks;
use App\Support\Errors;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The unified publish composer endpoint (T-008).
 *
 * One POST reaches the announcements feed, push, the signage board and email —
 * whichever of them the admin ticked. It ORCHESTRATES; every one of those
 * channels keeps its own endpoint, working exactly as before, and this
 * controller adds nothing to them.
 *
 * ## Where it sits in the middleware stack, and why
 *
 * `auth:sanctum` + `admin` + `tenant` — the same stack the announcements and
 * notifications endpoints it fans out to already run under, and deliberately
 * OUTSIDE the `crm` group with no `permission:` gate. Same reasoning routes
 * /admin.php gives for the Flyer Studio: broadcasting a Jumu'ah notice is
 * content authoring, not the CRM money path, so gating it on
 * `masjids.crm_enabled` would take announcements and push away from every masjid
 * that has not bought the CRM. Nothing is minted either — Permission::count()
 * stays at 8 (StaffAuthGuardPinTest pins it).
 *
 * ## The ONE exception: the email channel reads the contact directory
 *
 * Email is the only channel whose recipients come from `contacts`, so selecting
 * it requires what reading contacts requires anywhere else in this app: the
 * masjid's CRM enabled AND the caller holding `view contacts`. That is checked
 * UP FRONT and answers 403 for the whole request, before a single channel runs.
 *
 * That is not a contradiction of the per-channel failure isolation elsewhere —
 * it is the distinction between an authorization decision and a delivery
 * outcome. Authorization must be all-or-nothing and knowable in advance;
 * discovering "you were not allowed to email these people" from a delivery row
 * AFTER the push has already gone out would be the worst of both designs.
 */
class BroadcastsController extends Controller
{
    /** Reserved by RFC 2606: never resolves, so a preview image can only be the SPA's own copy. */
    public const PREVIEW_ORIGIN = 'https://preview.invalid';

    public function __construct(private readonly BroadcastComposer $composer)
    {
    }

    /**
     * This tenant's sends, newest first.
     *
     * Queries the model directly — the bound TenantContext scopes it. No
     * hand-filtering by masjid_id (.claude/rules/tenant-scoping.md).
     */
    public function index($masjid_id)
    {
        $broadcasts = Broadcast::query()
            ->with('deliveries')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $broadcasts,
        ], Response::HTTP_OK);
    }

    /**
     * One send with its per-channel outcomes.
     *
     * The scoped findOrFail is OUTSIDE any try/catch on purpose, so another
     * masjid's id surfaces as a 404 rather than a 500.
     */
    public function show($masjid_id, $broadcast_id)
    {
        $broadcast = Broadcast::with('deliveries')->findOrFail($broadcast_id);

        return response()->json([
            'status' => 'success',
            'data' => $this->present($broadcast),
        ], Response::HTTP_OK);
    }

    /**
     * Compose and fan out.
     *
     * 202 rather than 201: for push the outcome is genuinely asynchronous (the
     * existing SendMasjidNotificationJob owns the OneSignal round trip), and a
     * scheduled broadcast has not been attempted at all yet. The delivery rows
     * in the response say precisely where each channel got to.
     */
    public function store(StoreBroadcastRequest $request, $masjid_id)
    {
        // Outside the try/catch: an unknown masjid must be a 404.
        $masjid = Masjid::findOrFail($masjid_id);

        $channels = $request->selectedChannels();

        $this->authorizeChannels($request, $masjid, $channels);

        try {
            $broadcast = $this->composer->send(
                masjid: $masjid,
                attributes: array_merge($request->safe()->only([
                    'title', 'body', 'link', 'starts_on', 'ends_on',
                    'audience', 'contact_ids', 'service_id', 'scheduled_at',
                ]), ['blocks' => $request->newsletterBlocks()]),
                channels: $channels,
                image: $request->file('image'),
                authorId: $request->user()?->id,
                blockImages: $request->newsletterImages(),
            );

            return response()->json([
                'status' => 'success',
                'data' => $this->present($broadcast->load('deliveries')),
            ], Response::HTTP_ACCEPTED);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The email exactly as it will be sent, for the composer's live preview.
     *
     * Rendered by BroadcastMail itself — the class that sends it — rather than
     * by a copy of its layout in the SPA, so the preview cannot drift from the
     * inbox. Nothing is stored and nothing is sent. Pictures are not uploaded
     * until the admin sends, so each one is addressed at a reserved `.invalid`
     * host the SPA swaps for its local copy of that file; a `.invalid` name
     * never resolves, so nothing leaves the admin's browser to fetch it.
     *
     * With no blocks this returns the ORIGINAL single-image email, because that
     * is what a send without blocks produces: the preview shows the truth rather
     * than a newsletter frame the recipients would not get.
     *
     * The masjid lookup is the tenant guardrail's: a MasjidAdmin naming another
     * organisation is refused by ResolveMasjidTenant before this runs.
     */
    public function preview(PreviewNewsletterRequest $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        $blocks = $request->newsletterBlocks();
        $placeholders = [];
        foreach (NewsletterBlocks::imageKeys($blocks) as $key) {
            $placeholders[$key] = self::PREVIEW_ORIGIN . '/newsletter-image/' . $key;
        }

        $mail = new BroadcastMail(
            orgName: (string) $masjid->name,
            title: (string) ($request->input('title') ?: 'Your title'),
            body: (string) ($request->input('body') ?: 'Your message.'),
            link: $request->input('link') ?: null,
            imageUrl: $request->input('with_image') === '1' ? self::PREVIEW_ORIGIN . '/composer-image' : null,
            recipientName: null,
            orgEmail: null,
            unsubscribeUrl: self::PREVIEW_ORIGIN . '/unsubscribe',
            unsubscribeOneClickUrl: null,
            blocks: NewsletterBlocks::withImageUrls($blocks, $placeholders),
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'html' => $mail->render(),
                'text' => $mail->textAlternative(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Refuse up front if a selected channel needs access the caller lacks.
     *
     * First the organisation's modules: a channel whose
     * BroadcastChannel::requiresModule() names a switched-off module is refused
     * outright (the channel drivers repeat the check at delivery, for sends
     * scheduled before the switch). Then the CRM checks below.
     *
     * Only the email channel does today (it reads `contacts`). Written as a loop
     * over `readsContacts()` rather than an `if ($channel === EMAIL)` so a future
     * contact-reading channel — SMS is the obvious one — inherits the check
     * instead of quietly bypassing it.
     *
     * @param  array<int, BroadcastChannel>  $channels
     */
    private function authorizeChannels(StoreBroadcastRequest $request, Masjid $masjid, array $channels): void
    {
        // A channel that writes into a module the organisation has switched off
        // is refused FIRST, for everyone — a SuperAdmin included. The composer
        // must agree with the organisation's own menu: announcements switched
        // off means no announcements, whichever screen tried to write one.
        // moduleIsOff is fail-open on a key the loaded config does not know as
        // a module, so a stale config cache mid-deploy refuses nothing.
        foreach ($channels as $channel) {
            $module = $channel->requiresModule();

            if ($module !== null && $masjid->moduleIsOff($module)) {
                // Thrown as a RESPONSE, not abort(): the JSON exception renderer
                // replaces an HttpException's message with "Request failed." when
                // app.debug is off, so this sentence would never reach production.
                // authorizeChannels runs outside store()'s try/catch.
                throw new HttpResponseException(response()->json([
                    'status' => 'error',
                    'message' => '"' . config("capabilities.{$module}.label", $module) . '" is switched off for this organisation, so the '
                        . $channel->label() . ' channel is unavailable.',
                ], Response::HTTP_FORBIDDEN));
            }
        }

        $needsContacts = array_filter($channels, fn (BroadcastChannel $c) => $c->readsContacts());

        // The AUDIENCE can need the directory even when no channel does. A
        // service audience resolves through `contact_service_interests` to pick
        // which devices to push to, so push — a channel that reads no contacts
        // on its own — inherits the gate here rather than bypassing it.
        $audience = BroadcastAudience::tryFrom((string) $request->input('audience'));
        $audienceNeedsContacts = $audience?->readsContacts() ?? false;

        if ($needsContacts === [] && ! $audienceNeedsContacts) {
            return;
        }

        $reason = $needsContacts !== []
            ? 'The email channel reads the contact directory, which is part of the CRM.'
            : 'That audience is built from the contact directory, which is part of the CRM.';

        if (! $masjid->crm_enabled) {
            abort(
                Response::HTTP_FORBIDDEN,
                $reason . ' Enable the CRM for this organization, or send to everyone instead.'
            );
        }

        if (! $request->user()?->can('view contacts')) {
            abort(
                Response::HTTP_FORBIDDEN,
                'Sending to a contact-derived audience requires the "view contacts" permission.'
            );
        }
    }

    /**
     * Attach the composer image URL so the SPA does not have to re-derive it
     * from the media library.
     */
    private function present(Broadcast $broadcast): array
    {
        return array_merge($broadcast->toArray(), [
            'image_url' => $broadcast->imageUrl(),
        ]);
    }
}
