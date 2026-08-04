<?php

namespace App\Services\Assistant;

use App\Mail\AssistantFeatureRequestMail;
use App\Models\Announcement;
use App\Models\AssistantFeatureRequest;
use App\Models\Event;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\ThemeSetting;
use App\Models\User;
use App\Support\MobileCache;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The capabilities the Masjid Assistant can actually perform, and the rules that
 * decide which of them a given admin is offered.
 *
 * Two invariants hold everywhere in this file:
 *
 *  1. `masjid_id` is NEVER a tool parameter. It is taken from the Masjid resolved
 *     server-side, so the model cannot address another tenant even if it tries.
 *  2. Every tool's authorization is re-checked in execute() immediately before the
 *     handler runs. The model choosing a tool is a request, never a grant.
 *
 * Deliberately absent: hadith, adhkar and tasbih. Those tables have no masjid_id
 * and the mobile API serves them globally, so they are platform-wide content
 * owned by Hope Tech (SuperAdmin-only since 4a0da46). A masjid admin asking for
 * them is routed to request_feature instead of being quietly given a tool that
 * would edit every masjid's app. Also absent: any delete. Destructive actions
 * stay in the UI behind its confirmation dialog.
 */
class ToolRegistry
{
    /**
     * Stand-in artwork for an announcement created without an attachment.
     * Returns null if the asset is missing from the deploy, so the caller can
     * refuse rather than write a row the mobile apps cannot decode.
     */
    private static function defaultAnnouncementImage(): ?string
    {
        $path = public_path('images/announcement-default.png');

        if (! is_readable($path)) {
            Log::error('Assistant default announcement image missing', ['path' => $path]);

            return null;
        }

        return $path;
    }

    /** @return AssistantTool[] keyed by tool name, filtered to this admin. */
    public function availableFor(User $user, Masjid $masjid): array
    {
        $available = [];

        foreach ($this->all() as $tool) {
            if ($tool->isAvailableTo($user, $masjid)) {
                $available[$tool->name] = $tool;
            }
        }

        return $available;
    }

    /**
     * Run a tool the model asked for. Returns a result array that is fed back as
     * the tool_result content. Never throws into the loop — an error is returned
     * as data so the model can explain it to the admin rather than the whole
     * turn collapsing.
     */
    public function execute(
        string $name,
        array $input,
        User $user,
        Masjid $masjid,
        ?string $attachmentPath = null,
    ): array {
        $tool = $this->all()[$name] ?? null;

        if ($tool === null) {
            return ['ok' => false, 'error' => "Unknown tool: {$name}."];
        }

        // Re-check authorization at execution — not just at offer time.
        if (! $tool->isAvailableTo($user, $masjid)) {
            Log::warning('Assistant tool blocked at execution', [
                'tool' => $name, 'user_id' => $user->id, 'masjid_id' => $masjid->id,
            ]);

            return ['ok' => false, 'error' => 'You do not have permission to do that.'];
        }

        try {
            // Handlers declare only the parameters they use; PHP ignores the extras.
            $result = ($tool->handler)($input, $masjid, $user, $attachmentPath);

            Log::info('Assistant tool executed', [
                'tool' => $name, 'user_id' => $user->id, 'masjid_id' => $masjid->id,
                'writes' => $tool->writes, 'ok' => $result['ok'] ?? true,
                // Recorded because "why didn't it use my flyer?" is otherwise
                // impossible to answer after the fact.
                'had_attachment' => $attachmentPath !== null,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('Assistant tool failed', [
                'tool' => $name, 'user_id' => $user->id, 'masjid_id' => $masjid->id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $this->friendlyError($e)];
        }
    }

    /**
     * Turn an exception into something an admin should actually read.
     *
     * This value goes two places, and both matter: the model sees it as the
     * tool_result and explains it to the admin, and the UI prints it verbatim in
     * the action trail. A raw driver message ("SQLSTATE[22001]: ... insert into
     * `events` (`title`, `details`, ...) values (...)") is useless to an admin,
     * dumps our schema and the whole row onto their screen, and misleads the
     * model — it once read a NOT NULL complaint about a dead column and filed a
     * feature request to "expose the text field".
     *
     * Full detail is already in the log above; this is the public face.
     */
    private function friendlyError(\Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return match ((string) ($e->errorInfo[0] ?? '')) {
                '22001' => "One of the values was too long to save. Try shortening it.",
                '23000' => "That conflicts with something already saved.",
                default => "I couldn't save that — the database rejected it.",
            };
        }

        // In local dev the real message is far more useful than a euphemism.
        return config('app.debug')
            ? 'That action failed: ' . $e->getMessage()
            : "That action didn't go through. Nothing was changed.";
    }

    /** @return AssistantTool[] keyed by name — the full surface before filtering. */
    private function all(): array
    {
        $tools = [
            $this->listAnnouncements(),
            $this->createAnnouncement(),
            $this->updateAnnouncement(),
            $this->listEvents(),
            $this->createEvent(),
            $this->updateEvent(),
            $this->updateTheme(),
            $this->listIqamaTimes(),
            $this->setIqamaSchedule(),
            $this->requestFeature(),
        ];

        return collect($tools)->keyBy(fn (AssistantTool $t) => $t->name)->all();
    }

    // ---------------------------------------------------------------- announcements

    private function listAnnouncements(): AssistantTool
    {
        return new AssistantTool(
            name: 'list_announcements',
            description: "List this masjid's recent announcements. Use before updating or to check whether something already exists.",
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'How many to return (max 20).'],
                ],
                'required' => [],
            ],
            handler: function (array $in, Masjid $masjid): array {
                $limit = min(20, max(1, (int) ($in['limit'] ?? 10)));

                $rows = Announcement::where('masjid_id', $masjid->id)
                    ->orderByDesc('id')->limit($limit)
                    ->get(['id', 'title', 'summary', 'start_date', 'end_date']);

                return ['ok' => true, 'announcements' => $rows->toArray()];
            },
        );
    }

    private function createAnnouncement(): AssistantTool
    {
        return new AssistantTool(
            name: 'create_announcement',
            description: 'Create a new announcement for this masjid. Use for news the congregation should see in the app. If the admin attached an image, it becomes the announcement image automatically — do not ask them to upload it again.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Short headline.'],
                    'details' => ['type' => 'string', 'description' => 'The announcement body. This is what the congregation reads.'],
                    'summary' => ['type' => 'string', 'description' => 'Optional one-line summary.'],
                    'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD — the day it starts showing. Ask if unknown; do not guess.'],
                    'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD — the day it stops showing. Must be after start_date.'],
                    'link' => ['type' => 'string', 'description' => 'Optional URL.'],
                ],
                // Mirrors the NOT NULL columns. The admin screen demands all of these too,
                // so anything less would fail at the database and confuse everyone.
                'required' => ['title', 'details', 'start_date', 'end_date'],
            ],
            writes: true,
            handler: function (array $in, Masjid $masjid, User $user, ?string $attachmentPath = null): array {
                // Model output is untrusted — validate as the REST endpoint would.
                $v = Validator::make($in, [
                    'title' => ['required', 'string', 'max:255'],
                    'details' => ['required', 'string'],   // TEXT column — no practical cap
                    'summary' => ['nullable', 'string', 'max:255'],
                    'start_date' => ['required', 'date'],
                    'end_date' => ['required', 'date', 'after:start_date'],
                    'link' => ['nullable', 'url', 'max:2048'],
                ]);

                if ($v->fails()) {
                    return ['ok' => false, 'error' => 'Invalid values: ' . $v->errors()->first()];
                }

                $data = $v->validated();
                $data['masjid_id'] = $masjid->id;   // server-derived, never from the model
                // Accept whatever date phrasing the model produced, store the column's format.
                $data['start_date'] = Carbon::parse($data['start_date'])->format('Y-m-d');
                $data['end_date'] = Carbon::parse($data['end_date'])->format('Y-m-d');
                // `text` is NOT NULL but unused — every live row carries ''. Matching that
                // rather than duplicating the body into a column nothing reads.
                $data['text'] = '';

                $a = Announcement::create($data);

                // An announcement MUST end up with an image. The iOS client decodes
                // `image` as a non-optional field, and because it decodes the whole
                // list at once, a single imageless row blanks the entire announcements
                // tab for every user of that masjid. The admin form has always
                // enforced this (`'image' => 'required|image'`); this tool has to
                // honour the same contract.
                //
                // The attached flyer is used when there is one — that is the point of
                // letting an admin drop an image into the chat — otherwise a neutral
                // masjid graphic stands in so the announcement is publishable now and
                // the admin can swap the artwork later.
                $hasAttachment = $attachmentPath && is_readable($attachmentPath);
                $source = $hasAttachment ? $attachmentPath : self::defaultAnnouncementImage();

                if ($source === null) {
                    $a->forceDelete();   // never leave a row the apps will choke on

                    return ['ok' => false, 'error' => 'I could not attach an image, and an announcement needs one. Please add it from the Announcements screen.'];
                }

                $a->addMedia($source)->preservingOriginal()->toMediaCollection('announcements');

                // Without this the apps keep serving the cached list and the admin
                // reasonably concludes the assistant lied to them.
                MobileCache::flushMasjid((int) $masjid->id, MobileCache::ANNOUNCEMENTS);

                return ['ok' => true, 'created' => [
                    'id' => $a->id,
                    'title' => $a->title,
                    // Surfaced so the model tells the admin a stand-in was used, rather
                    // than letting them discover a generic graphic in the app later.
                    'image' => $hasAttachment
                        ? 'the image you attached'
                        : 'a default masjid graphic (no image was attached — tell the admin they can replace it on the Announcements screen)',
                ]];
            },
        );
    }

    private function updateAnnouncement(): AssistantTool
    {
        return new AssistantTool(
            name: 'update_announcement',
            description: <<<'TXT'
            Change an existing announcement. Use this INSTEAD of create_announcement whenever the admin is
            correcting, rewording, or improving something that already exists — creating a second copy is
            almost never what they want. Get the id from list_announcements first.
            Only send the fields you are changing. If the admin attached an image, it REPLACES the current
            artwork, so this is how you add a flyer to an announcement that was made without one.
            TXT,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The announcement id from list_announcements.'],
                    'title' => ['type' => 'string'],
                    'details' => ['type' => 'string', 'description' => 'The announcement body.'],
                    'summary' => ['type' => 'string'],
                    'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, after start_date.'],
                    'link' => ['type' => 'string'],
                ],
                'required' => ['id'],
            ],
            writes: true,
            handler: function (array $in, Masjid $masjid, User $user, ?string $attachmentPath = null): array {
                $v = Validator::make($in, [
                    'id' => ['required', 'integer'],
                    'title' => ['sometimes', 'string', 'max:255'],
                    'details' => ['sometimes', 'string'],
                    'summary' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'start_date' => ['sometimes', 'date'],
                    'end_date' => ['sometimes', 'date'],
                    'link' => ['sometimes', 'nullable', 'url', 'max:2048'],
                ]);

                if ($v->fails()) {
                    return ['ok' => false, 'error' => 'Invalid values: ' . $v->errors()->first()];
                }

                $data = $v->validated();

                // Scoped by masjid_id, not just id — the model supplies the id, and it
                // must never be able to reach another tenant's announcement.
                $a = Announcement::where('masjid_id', $masjid->id)->find($data['id']);

                if (! $a) {
                    return ['ok' => false, 'error' => "There is no announcement with id {$data['id']} for this masjid."];
                }

                unset($data['id']);

                foreach (['start_date', 'end_date'] as $d) {
                    if (! empty($data[$d])) {
                        $data[$d] = Carbon::parse($data[$d])->format('Y-m-d');
                    }
                }

                // Re-check the ordering against whatever the row ends up with, since
                // the admin may be changing only one of the two dates.
                $start = $data['start_date'] ?? $a->start_date;
                $end = $data['end_date'] ?? $a->end_date;

                if ($start && $end && Carbon::parse($end)->lte(Carbon::parse($start))) {
                    return ['ok' => false, 'error' => 'The end date has to be after the start date.'];
                }

                if ($data !== []) {
                    $a->fill($data)->save();
                }

                $imageReplaced = false;
                if ($attachmentPath && is_readable($attachmentPath)) {
                    $a->clearMediaCollection('announcements');
                    $a->addMedia($attachmentPath)->preservingOriginal()->toMediaCollection('announcements');
                    $imageReplaced = true;
                }

                MobileCache::flushMasjid((int) $masjid->id, MobileCache::ANNOUNCEMENTS);

                return ['ok' => true, 'updated' => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'fields_changed' => array_keys($data),
                    'image_replaced' => $imageReplaced,
                ]];
            },
        );
    }

    // ---------------------------------------------------------------- events

    private function listEvents(): AssistantTool
    {
        return new AssistantTool(
            name: 'list_events',
            description: "List this masjid's upcoming events.",
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'How many to return (max 20).'],
                ],
                'required' => [],
            ],
            handler: function (array $in, Masjid $masjid): array {
                $limit = min(20, max(1, (int) ($in['limit'] ?? 10)));

                $rows = Event::where('masjid_id', $masjid->id)
                    ->orderByDesc('id')->limit($limit)
                    ->get(['id', 'title', 'place', 'start', 'end']);

                return ['ok' => true, 'events' => $rows->toArray()];
            },
        );
    }

    private function createEvent(): AssistantTool
    {
        return new AssistantTool(
            name: 'create_event',
            description: 'Create an event for this masjid. If the admin attached a flyer image, read the details off it — but ask before guessing anything ambiguous.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'details' => ['type' => 'string', 'description' => 'What the event is.'],
                    'place' => ['type' => 'string', 'description' => 'Where it is held.'],
                    'start' => ['type' => 'string', 'description' => 'Start datetime, YYYY-MM-DD HH:MM. Ask if unknown; do not guess.'],
                    'end' => ['type' => 'string', 'description' => 'Optional end datetime, YYYY-MM-DD HH:MM. Must be after start.'],
                    'link' => ['type' => 'string'],
                ],
                'required' => ['title', 'details', 'place', 'start'],
            ],
            writes: true,
            handler: function (array $in, Masjid $masjid): array {
                $v = Validator::make($in, [
                    'title' => ['required', 'string', 'max:255'],
                    'details' => ['required', 'string'],
                    'place' => ['required', 'string', 'max:255'],
                    'start' => ['required', 'date'],
                    'end' => ['nullable', 'date', 'after:start'],
                    'link' => ['nullable', 'url', 'max:2048'],
                ]);

                if ($v->fails()) {
                    return ['ok' => false, 'error' => 'Invalid values: ' . $v->errors()->first()];
                }

                $data = $v->validated();
                $data['masjid_id'] = $masjid->id;
                $data['start'] = Carbon::parse($data['start'])->format('Y-m-d H:i');
                if (! empty($data['end'])) {
                    $data['end'] = Carbon::parse($data['end'])->format('Y-m-d H:i');
                }

                $e = Event::create($data);

                MobileCache::flushMasjid((int) $masjid->id, MobileCache::EVENTS);

                return ['ok' => true, 'created' => ['id' => $e->id, 'title' => $e->title]];
            },
        );
    }

    private function updateEvent(): AssistantTool
    {
        return new AssistantTool(
            name: 'update_event',
            description: 'Change an existing event. Use this INSTEAD of create_event when the admin is correcting or improving one that already exists. Get the id from list_events. Only send the fields you are changing.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The event id from list_events.'],
                    'title' => ['type' => 'string'],
                    'details' => ['type' => 'string'],
                    'place' => ['type' => 'string'],
                    'start' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM'],
                    'end' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM, after start.'],
                    'link' => ['type' => 'string'],
                ],
                'required' => ['id'],
            ],
            writes: true,
            handler: function (array $in, Masjid $masjid): array {
                $v = Validator::make($in, [
                    'id' => ['required', 'integer'],
                    'title' => ['sometimes', 'string', 'max:255'],
                    'details' => ['sometimes', 'string'],
                    'place' => ['sometimes', 'string', 'max:255'],
                    'start' => ['sometimes', 'date'],
                    'end' => ['sometimes', 'nullable', 'date'],
                    'link' => ['sometimes', 'nullable', 'url', 'max:2048'],
                ]);

                if ($v->fails()) {
                    return ['ok' => false, 'error' => 'Invalid values: ' . $v->errors()->first()];
                }

                $data = $v->validated();

                $e = Event::where('masjid_id', $masjid->id)->find($data['id']);

                if (! $e) {
                    return ['ok' => false, 'error' => "There is no event with id {$data['id']} for this masjid."];
                }

                unset($data['id']);

                foreach (['start', 'end'] as $d) {
                    if (! empty($data[$d])) {
                        $data[$d] = Carbon::parse($data[$d])->format('Y-m-d H:i');
                    }
                }

                $start = $data['start'] ?? $e->start;
                $end = $data['end'] ?? $e->end;

                if ($start && $end && Carbon::parse($end)->lte(Carbon::parse($start))) {
                    return ['ok' => false, 'error' => 'The event has to end after it starts.'];
                }

                if ($data !== []) {
                    $e->fill($data)->save();
                }

                MobileCache::flushMasjid((int) $masjid->id, MobileCache::EVENTS);

                return ['ok' => true, 'updated' => [
                    'id' => $e->id,
                    'title' => $e->title,
                    'fields_changed' => array_keys($data),
                ]];
            },
        );
    }

    // ---------------------------------------------------------------- theme

    private function updateTheme(): AssistantTool
    {
        return new AssistantTool(
            name: 'update_theme',
            description: "Update this masjid's app and website colors. Colors are hex like #1B7F4C. If the admin attached an image, you may pick a palette from it. Only send the colors being changed.",
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'primary_color' => ['type' => 'string', 'description' => 'Hex, e.g. #1B7F4C'],
                    'secondary_color' => ['type' => 'string'],
                    'accent_color' => ['type' => 'string'],
                    'background_color' => ['type' => 'string'],
                ],
                'required' => [],
            ],
            writes: true,
            handler: function (array $in, Masjid $masjid): array {
                $hex = ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'];

                $v = Validator::make($in, [
                    'primary_color' => $hex,
                    'secondary_color' => $hex,
                    'accent_color' => $hex,
                    'background_color' => $hex,
                ]);

                if ($v->fails()) {
                    return ['ok' => false, 'error' => 'Colors must be hex like #1B7F4C. ' . $v->errors()->first()];
                }

                $data = array_filter($v->validated(), fn ($x) => $x !== null && $x !== '');

                if ($data === []) {
                    return ['ok' => false, 'error' => 'No colors were provided.'];
                }

                $theme = ThemeSetting::firstOrNew(['masjid_id' => $masjid->id]);
                $theme->masjid_id = $masjid->id;
                $theme->fill($data)->save();

                // Theme rides along on the masjid `show` payload.
                MobileCache::flushMasjid((int) $masjid->id, MobileCache::SHOW);

                return ['ok' => true, 'updated' => $data];
            },
        );
    }

    // ---------------------------------------------------------------- escalation

    private function requestFeature(): AssistantTool
    {
        return new AssistantTool(
            name: 'request_feature',
            description: <<<'TXT'
            Send a request to Hope Tech Inc when you CANNOT complete something yourself. Use this instead of
            apologising and stopping, and never claim you did something you could not do. Use it when:
              - the portal has no such capability yet (category: missing_capability);
              - the capability exists but is not enabled for this masjid (category: feature_not_enabled);
              - this admin lacks permission for it (category: insufficient_permission).
            Hadith, adhkar and tasbih are shared library content across ALL masjids and are managed centrally
            by Hope Tech — requests to add or change them belong here, as feature_not_enabled.
            TXT,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'One line: what the admin wanted.'],
                    'details' => ['type' => 'string', 'description' => 'Their request in their own words, plus any context.'],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['missing_capability', 'feature_not_enabled', 'insufficient_permission', 'other'],
                    ],
                ],
                'required' => ['summary', 'category'],
            ],
            writes: true,
            handler: function (array $in, Masjid $masjid, User $user): array {
                $v = Validator::make($in, [
                    'summary' => ['required', 'string', 'max:255'],
                    'details' => ['nullable', 'string', 'max:5000'],
                    'category' => ['required', 'in:missing_capability,feature_not_enabled,insufficient_permission,other'],
                ]);

                if ($v->fails()) {
                    return ['ok' => false, 'error' => $v->errors()->first()];
                }

                $req = AssistantFeatureRequest::create($v->validated() + [
                    'masjid_id' => $masjid->id,
                    'user_id' => $user->id,
                    'status' => 'open',
                ]);

                $this->notifyHopeTech($req, $masjid, $user);

                return [
                    'ok' => true,
                    'request_id' => $req->id,
                    'message' => 'Request sent to Hope Tech Inc.',
                ];
            },
        );
    }

    // ---------------------------------------------------------------- iqama times

    private function listIqamaTimes(): AssistantTool
    {
        return new AssistantTool(
            name: 'list_iqama_times',
            description: <<<'TXT'
            Show the masjid's current iqama (jama'ah) times — how they are calculated, and every
            fixed time already scheduled. ALWAYS call this before set_iqama_schedule, so you can
            tell the admin what is currently set and avoid re-entering something that is already
            there. Also the right tool when an admin simply asks "what time is Asr iqama".
            Adhan times are NOT set here — they are calculated from the masjid's location.
            TXT,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'month' => [
                        'type' => 'string',
                        'description' => 'Optional YYYY-MM to list only that month, e.g. "2026-08". Omit for everything.',
                    ],
                ],
            ],
            handler: function (array $in, Masjid $masjid, User $user): array {
                $setting = IqamaTimeSetting::where('masjid_id', $masjid->id)->first();

                if (! $setting) {
                    return ['ok' => false, 'error' => 'This masjid has no iqama settings yet. Set them up on the Iqama Times screen first.'];
                }

                $q = $setting->timeRanges()->orderByRaw(
                    "FIELD(salah,'fajr','dhuhr','asr','maghrib','isha'), start_date"
                );

                if (! empty($in['month']) && preg_match('/^\d{4}-\d{2}$/', $in['month'])) {
                    $start = $in['month'] . '-01';
                    $end = date('Y-m-t', strtotime($start));
                    // Any range that OVERLAPS the month, not just one starting inside it —
                    // a range spanning a month boundary still governs days in this month.
                    $q->where('start_date', '<=', $end)->where('end_date', '>=', $start);
                }

                $ranges = $q->get()->map(fn ($r) => [
                    'salah' => $r->salah,
                    'from' => substr((string) $r->start_date, 0, 10),
                    'to' => substr((string) $r->end_date, 0, 10),
                    'time' => substr((string) $r->specific_time, 0, 5),
                ])->all();

                return [
                    'ok' => true,
                    'mode' => $setting->iqama_type instanceof \BackedEnum
                        ? $setting->iqama_type->value
                        : (string) $setting->iqama_type,
                    'mode_explained' => ($setting->iqama_type instanceof \BackedEnum ? $setting->iqama_type->value : $setting->iqama_type) === 'specific_time_ranges'
                        ? 'Fixed clock times from the schedule below. Any prayer with no range for a date falls back to the minutes-after-adhan offsets.'
                        : 'Every iqama is calculated as N minutes after the adhan. Fixed times below are stored but NOT in use until the mode changes.',
                    'offsets_minutes_after_adhan' => [
                        'fajr' => (int) $setting->fajr,
                        'dhuhr' => (int) $setting->dhuhr,
                        'asr' => (int) $setting->asr,
                        'maghrib' => (int) $setting->maghrib,
                        'isha' => (int) $setting->isha,
                    ],
                    'shown_to_congregation' => (bool) $setting->show_iqama_times,
                    'fixed_times' => $ranges,
                ];
            },
        );
    }

    private function setIqamaSchedule(): AssistantTool
    {
        return new AssistantTool(
            name: 'set_iqama_schedule',
            description: <<<'TXT'
            Set fixed iqama (jama'ah) times for date ranges — this is how a masjid publishes its
            monthly prayer schedule. Send every line of the schedule in one call.

            Each entry is one prayer over one date range at one clock time, exactly as printed on
            the masjid's schedule, e.g. Fajr from 2026-08-01 to 2026-08-05 at 05:30.

            IMPORTANT — read these before calling:
            * This sets IQAMA (jama'ah) only. Adhan times are calculated from the masjid's
              location and cannot be set here. If the admin is reading a sheet with two columns,
              the one you want is the later time (often labelled Salat, Iqama or Jama'ah).
            * An entry REPLACES that prayer's time only on the days it covers. Correcting one
              week does not disturb the rest of the month, so a spot fix is safe.
            * Call list_iqama_times first, and read the resulting schedule back to the admin for
              confirmation before you call this. It changes what the whole congregation sees.
            * Jumu'ah is configured separately and is not handled by this tool.
            TXT,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'entries' => [
                        'type' => 'array',
                        'description' => 'Every prayer/date-range/time line to set.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'salah' => [
                                    'type' => 'string',
                                    'enum' => ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'],
                                ],
                                'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, first day this time applies.'],
                                'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, last day. Same as start_date for a single day.'],
                                'time' => [
                                    'type' => 'string',
                                    'description' => 'The iqama time. 24-hour "17:30" is safest; "5:30 PM" is also accepted.',
                                ],
                            ],
                            'required' => ['salah', 'start_date', 'end_date', 'time'],
                        ],
                    ],
                ],
                'required' => ['entries'],
            ],
            writes: true,
            handler: function (array $in, Masjid $masjid, User $user): array {
                $v = Validator::make($in, [
                    'entries' => ['required', 'array', 'min:1', 'max:200'],
                    'entries.*.salah' => ['required', 'string', Rule::in(['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'])],
                    'entries.*.start_date' => ['required', 'date_format:Y-m-d'],
                    'entries.*.end_date' => ['required', 'date_format:Y-m-d'],
                    'entries.*.time' => ['required', 'string', 'max:16'],
                ]);

                if ($v->fails()) {
                    return ['ok' => false, 'error' => 'Invalid values: ' . $v->errors()->first()];
                }

                $setting = IqamaTimeSetting::where('masjid_id', $masjid->id)->first();

                if (! $setting) {
                    return ['ok' => false, 'error' => 'This masjid has no iqama settings yet. Create them on the Iqama Times screen first, then I can keep them updated.'];
                }

                // Normalise and check each line BEFORE writing anything: a schedule that
                // half-applied would leave the congregation with a mix of two months.
                $clean = [];
                foreach ($v->validated()['entries'] as $i => $e) {
                    $line = $i + 1;

                    $time = $this->normaliseClockTime($e['time']);
                    if ($time === null) {
                        return ['ok' => false, 'error' => "I could not read the time \"{$e['time']}\" on line {$line}. Give it as 17:30 or 5:30 PM."];
                    }

                    if ($e['end_date'] < $e['start_date']) {
                        return ['ok' => false, 'error' => "On line {$line} the end date ({$e['end_date']}) is before the start date ({$e['start_date']})."];
                    }

                    $clean[] = [
                        'salah' => $e['salah'],
                        'start_date' => $e['start_date'],
                        'end_date' => $e['end_date'],
                        'specific_time' => $time . ':00',
                    ];
                }

                $wasMode = $setting->iqama_type instanceof \BackedEnum
                    ? $setting->iqama_type->value
                    : (string) $setting->iqama_type;

                DB::transaction(function () use ($setting, $clean, $wasMode) {
                    $now = now();

                    foreach ($clean as $row) {
                        // Two ranges covering one day for one prayer would make the resolved
                        // iqama depend on row order, so overlaps have to go. But DELETING an
                        // overlapping range takes its non-overlapping days with it: correcting
                        // Asr for the 5th–15th would silently blank the 1st–4th and 16th–30th,
                        // dropping those days back to the offset without saying so. So trim
                        // the neighbour instead, and only delete what the new range fully covers.
                        $overlapping = $setting->timeRanges()
                            ->where('salah', $row['salah'])
                            ->where('start_date', '<=', $row['end_date'])
                            ->where('end_date', '>=', $row['start_date'])
                            ->get();

                        foreach ($overlapping as $old) {
                            $oldStart = substr((string) $old->start_date, 0, 10);
                            $oldEnd = substr((string) $old->end_date, 0, 10);

                            $keepBefore = $oldStart < $row['start_date'];
                            $keepAfter = $oldEnd > $row['end_date'];

                            if ($keepBefore) {
                                DB::table('iqama_time_ranges')->insert([
                                    'iqama_time_setting_id' => $setting->id,
                                    'salah' => $old->salah,
                                    'start_date' => $oldStart,
                                    'end_date' => date('Y-m-d', strtotime($row['start_date'] . ' -1 day')),
                                    'specific_time' => $old->specific_time,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                            }

                            if ($keepAfter) {
                                DB::table('iqama_time_ranges')->insert([
                                    'iqama_time_setting_id' => $setting->id,
                                    'salah' => $old->salah,
                                    'start_date' => date('Y-m-d', strtotime($row['end_date'] . ' +1 day')),
                                    'end_date' => $oldEnd,
                                    'specific_time' => $old->specific_time,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                            }

                            DB::table('iqama_time_ranges')->where('id', $old->id)->delete();
                        }
                    }

                    DB::table('iqama_time_ranges')->insert(array_map(
                        fn ($r) => $r + [
                            'iqama_time_setting_id' => $setting->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $clean
                    ));

                    // Fixed times are INERT while the masjid is on minutes-after-adhan.
                    // Saving a schedule that then does nothing is the worst outcome here —
                    // it looks like it worked. Switch the mode so what was entered is what
                    // the congregation actually sees.
                    if ($wasMode !== 'specific_time_ranges') {
                        $setting->iqama_type = 'specific_time_ranges';
                        $setting->save();
                    }
                });

                MobileCache::flushMasjid((int) $masjid->id, MobileCache::PRAYERS_SETTINGS);

                $summary = collect($clean)
                    ->map(fn ($r) => sprintf(
                        '%s %s to %s at %s',
                        ucfirst($r['salah']),
                        $r['start_date'],
                        $r['end_date'],
                        substr($r['specific_time'], 0, 5)
                    ))
                    ->all();

                return [
                    'ok' => true,
                    'saved' => count($clean),
                    'schedule' => $summary,
                    'switched_to_fixed_times' => $wasMode !== 'specific_time_ranges',
                    'message' => $wasMode !== 'specific_time_ranges'
                        ? 'Saved. This masjid was calculating iqama as minutes after the adhan, so I also switched it to use fixed times — otherwise the schedule would have been stored but ignored.'
                        : 'Saved. The apps and website will show these within a few minutes.',
                ];
            },
        );
    }

    /**
     * Accept what an admin would actually say and return "HH:MM", or null.
     *
     * The model is transcribing a printed sheet, so it may pass "5:30 PM", "5:30PM",
     * "17:30" or "5:30" — the last being genuinely ambiguous. Rather than guess at
     * a bare "5:30" for Asr and publish a 5:30 AM jama'ah, anything without a
     * meridiem is read as 24-hour, which is what the schema asks for.
     */
    private function normaliseClockTime(string $raw): ?string
    {
        $s = strtoupper(trim($raw));
        $s = preg_replace('/\s+/', '', $s) ?? $s;

        if (preg_match('/^(\d{1,2}):(\d{2})(AM|PM)$/', $s, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h < 1 || $h > 12 || $min > 59) {
                return null;
            }
            if ($m[3] === 'PM' && $h !== 12) {
                $h += 12;
            }
            if ($m[3] === 'AM' && $h === 12) {
                $h = 0;
            }

            return sprintf('%02d:%02d', $h, $min);
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];

            return ($h > 23 || $min > 59) ? null : sprintf('%02d:%02d', $h, $min);
        }

        return null;
    }

    /**
     * Email Hope Tech about an escalation.
     *
     * Queued, so the admin isn't waiting on SMTP mid-conversation, and swallowed on
     * failure: the assistant already told them "sent to Hope Tech Inc", and the
     * assistant_feature_requests row is the record that makes that true. A bounced
     * notification must not turn into a failed tool call.
     */
    private function notifyHopeTech(AssistantFeatureRequest $req, Masjid $masjid, User $user): void
    {
        // The setting can name several recipients, comma-separated.
        $recipients = collect(explode(',', (string) config('services.anthropic.escalation_email')))
            ->map(fn ($e) => trim($e))
            ->filter()
            ->values()
            ->all();

        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send(new AssistantFeatureRequestMail(
                requestId: $req->id,
                masjidName: (string) $masjid->name,
                masjidId: (int) $masjid->id,
                requestedBy: (string) ($user->name ?: $user->email),
                requestedByEmail: (string) $user->email,
                category: (string) $req->category,
                summary: (string) $req->summary,
                details: $req->details,
            ));
        } catch (\Throwable $e) {
            Log::error('Assistant escalation email failed', [
                'request_id' => $req->id,
                'masjid_id' => $masjid->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
