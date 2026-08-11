<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\BroadcastChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Broadcasts\StoreBroadcastRequest;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Services\Broadcast\BroadcastComposer;
use App\Support\Errors;
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
                attributes: $request->safe()->only([
                    'title', 'body', 'link', 'starts_on', 'ends_on',
                    'audience', 'contact_ids', 'scheduled_at',
                ]),
                channels: $channels,
                image: $request->file('image'),
                authorId: $request->user()?->id,
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
     * Refuse up front if a selected channel needs access the caller lacks.
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
        $needsContacts = array_filter($channels, fn (BroadcastChannel $c) => $c->readsContacts());

        if ($needsContacts === []) {
            return;
        }

        if (! $masjid->crm_enabled) {
            abort(
                Response::HTTP_FORBIDDEN,
                'The email channel reads the contact directory, which is part of the CRM. '
                . 'Enable the CRM for this organization, or send without the email channel.'
            );
        }

        if (! $request->user()?->can('view contacts')) {
            abort(
                Response::HTTP_FORBIDDEN,
                'Sending by email requires the "view contacts" permission.'
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
