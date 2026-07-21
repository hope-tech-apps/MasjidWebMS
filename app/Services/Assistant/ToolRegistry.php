<?php

namespace App\Services\Assistant;

use App\Mail\AssistantFeatureRequestMail;
use App\Models\Announcement;
use App\Models\AssistantFeatureRequest;
use App\Models\Event;
use App\Models\Masjid;
use App\Models\ThemeSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

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
    public function execute(string $name, array $input, User $user, Masjid $masjid): array
    {
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
            $result = ($tool->handler)($input, $masjid, $user);

            Log::info('Assistant tool executed', [
                'tool' => $name, 'user_id' => $user->id, 'masjid_id' => $masjid->id,
                'writes' => $tool->writes, 'ok' => $result['ok'] ?? true,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('Assistant tool failed', [
                'tool' => $name, 'user_id' => $user->id, 'masjid_id' => $masjid->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'That action failed: ' . $e->getMessage()];
        }
    }

    /** @return AssistantTool[] keyed by name — the full surface before filtering. */
    private function all(): array
    {
        $tools = [
            $this->listAnnouncements(),
            $this->createAnnouncement(),
            $this->listEvents(),
            $this->createEvent(),
            $this->updateTheme(),
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
            description: 'Create a new announcement for this masjid. Use for news the congregation should see in the app.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Short headline.'],
                    'summary' => ['type' => 'string', 'description' => 'One or two sentence summary.'],
                    'details' => ['type' => 'string', 'description' => 'Full body text.'],
                    'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD when it should start showing.'],
                    'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD when it should stop showing.'],
                    'link' => ['type' => 'string', 'description' => 'Optional URL.'],
                ],
                'required' => ['title'],
            ],
            writes: true,
            handler: function (array $in, Masjid $masjid): array {
                // Model output is untrusted — validate exactly as the REST endpoint would.
                $v = Validator::make($in, [
                    'title' => ['required', 'string', 'max:255'],
                    'summary' => ['nullable', 'string', 'max:1000'],
                    'details' => ['nullable', 'string'],
                    'start_date' => ['nullable', 'date'],
                    'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
                    'link' => ['nullable', 'url', 'max:2048'],
                ]);

                if ($v->fails()) {
                    return ['ok' => false, 'error' => 'Invalid values: ' . $v->errors()->first()];
                }

                $data = $v->validated();
                $data['masjid_id'] = $masjid->id;   // server-derived, never from the model

                $a = Announcement::create($data);

                return ['ok' => true, 'created' => ['id' => $a->id, 'title' => $a->title]];
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
                    'details' => ['type' => 'string'],
                    'place' => ['type' => 'string', 'description' => 'Where it is held.'],
                    'start' => ['type' => 'string', 'description' => 'Start datetime, YYYY-MM-DD HH:MM:SS.'],
                    'end' => ['type' => 'string', 'description' => 'End datetime, YYYY-MM-DD HH:MM:SS.'],
                    'link' => ['type' => 'string'],
                ],
                'required' => ['title'],
            ],
            writes: true,
            handler: function (array $in, Masjid $masjid): array {
                $v = Validator::make($in, [
                    'title' => ['required', 'string', 'max:255'],
                    'details' => ['nullable', 'string'],
                    'place' => ['nullable', 'string', 'max:255'],
                    'start' => ['nullable', 'date'],
                    'end' => ['nullable', 'date', 'after_or_equal:start'],
                    'link' => ['nullable', 'url', 'max:2048'],
                ]);

                if ($v->fails()) {
                    return ['ok' => false, 'error' => 'Invalid values: ' . $v->errors()->first()];
                }

                $data = $v->validated();
                $data['masjid_id'] = $masjid->id;

                $e = Event::create($data);

                return ['ok' => true, 'created' => ['id' => $e->id, 'title' => $e->title]];
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
        $to = config('services.anthropic.escalation_email');

        if (! $to) {
            return;
        }

        try {
            Mail::to($to)->send(new AssistantFeatureRequestMail(
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
