<?php

namespace App\Services\Assistant;

use Anthropic\Client;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Runs one assistant turn: builds the tool surface for this admin, sends the
 * conversation to Claude, executes any tools it asks for, and loops until it
 * produces a final answer.
 *
 * Model choice is config-driven (`services.anthropic.model`, default Sonnet 5).
 * This workload is short-request comprehension plus tool selection from a small
 * surface — not long-horizon reasoning — so the Sonnet tier is the right fit and
 * Opus is one env var away if a harder case ever justifies it.
 *
 * Cost shape: the system prompt + tool schemas are byte-identical for a given
 * (masjid, admin) and are marked with cacheControl, so every turn after the
 * first reads that prefix at ~0.1x instead of paying for it again.
 */
class MasjidAssistantService
{
    public function __construct(private ToolRegistry $registry)
    {
    }

    /**
     * @param  ?array $image  ['media_type' => 'image/png', 'data' => <base64>, 'path' => <tmp file>]
     * @param  array  $history Prior turns: [['role' => 'user'|'assistant', 'content' => ...], ...]
     * @return array  ['reply' => string, 'actions' => array, 'stopped_reason' => string]
     */
    public function handle(
        User $user,
        Masjid $masjid,
        string $message,
        ?array $image = null,
        array $history = [],
    ): array {
        $tools = $this->registry->availableFor($user, $masjid);

        $client = new Client(apiKey: (string) config('services.anthropic.key'));

        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $this->userContent($message, $image)];

        $actions = [];               // the visible "what I did" trail
        $maxIterations = (int) config('services.anthropic.max_tool_iterations', 5);

        for ($i = 0; $i < $maxIterations; $i++) {
            $response = $client->messages->create(
                model: (string) config('services.anthropic.model'),
                maxTokens: (int) config('services.anthropic.max_tokens', 4096),
                system: $this->system($masjid, $tools),
                messages: $messages,
                tools: array_values(array_map(
                    fn (AssistantTool $t) => $t->toApiSchema(),
                    $tools
                )),
                thinking: ['type' => 'adaptive'],
                outputConfig: ['effort' => (string) config('services.anthropic.effort', 'medium')],
            );

            // Not a tool request — this is the final answer.
            if ($response->stopReason !== 'tool_use') {
                return [
                    'reply' => $this->textOf($response->content),
                    'actions' => $actions,
                    'stopped_reason' => (string) $response->stopReason,
                ];
            }

            // Echo the assistant turn back, then answer EVERY tool_use block in a single
            // user message. The blocks have to be re-shaped first — see echoable().
            $messages[] = ['role' => 'assistant', 'content' => $this->echoable($response->content)];

            $toolResults = [];

            foreach ($response->content as $block) {
                if (($block->type ?? null) !== 'tool_use') {
                    continue;
                }

                $input = (array) ($block->input ?? []);
                // Tools that create content can also *use* the attachment, not just
                // read it — a flyer becomes the announcement's image.
                $result = $this->registry->execute(
                    $block->name, $input, $user, $masjid, $image['path'] ?? null
                );

                $actions[] = [
                    'tool' => $block->name,
                    'input' => $input,
                    'ok' => (bool) ($result['ok'] ?? false),
                    'error' => $result['error'] ?? null,
                ];

                $toolResults[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $block->id,
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'is_error' => ! ($result['ok'] ?? false),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        // Hit the loop cap — return what we have rather than spinning up cost.
        Log::warning('Assistant hit max tool iterations', [
            'user_id' => $user->id, 'masjid_id' => $masjid->id,
        ]);

        return [
            'reply' => "I wasn't able to finish that in a reasonable number of steps. Here's what I did complete — could you narrow the request a little?",
            'actions' => $actions,
            'stopped_reason' => 'max_iterations',
        ];
    }

    /**
     * Re-shape response content blocks into blocks the API will accept back as input.
     *
     * Response blocks are not input blocks. A TextBlock carries an output-only
     * `parsed` field, and sending it back verbatim fails the whole turn with
     * "messages.N.content.0.text.parsed: Extra inputs are not permitted". So we
     * copy across only the fields that belong on the way in.
     *
     * Thinking blocks are echoed complete with their signature: with adaptive
     * thinking the model's reasoning has to survive the tool round-trip, and the
     * signature is what proves it wasn't tampered with. Dropping them silently
     * degrades multi-step tool use.
     *
     * Unknown block types are passed through as-is rather than dropped — losing a
     * block the model emitted is worse than letting the API reject it loudly.
     */
    private function echoable(array $content): array
    {
        $blocks = [];

        foreach ($content as $block) {
            $blocks[] = match ($block->type ?? null) {
                'text' => ['type' => 'text', 'text' => $block->text],
                'thinking' => [
                    'type' => 'thinking',
                    'thinking' => $block->thinking,
                    'signature' => $block->signature,
                ],
                'redacted_thinking' => ['type' => 'redacted_thinking', 'data' => $block->data],
                'tool_use' => [
                    'type' => 'tool_use',
                    'id' => $block->id,
                    'name' => $block->name,
                    'input' => (object) $block->input,   // must serialize as {} when empty
                ],
                default => $block,
            };
        }

        return $blocks;
    }

    /** Image block goes BEFORE the text block, per the vision docs. */
    private function userContent(string $message, ?array $image): array
    {
        $content = [];

        if ($image !== null) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['media_type'],
                    'data' => $image['data'],
                ],
            ];
        }

        $content[] = ['type' => 'text', 'text' => $message];

        return $content;
    }

    /**
     * Stable per (masjid, admin) so it caches. The behavioural rules here are the
     * other half of the safety model: the code makes bad actions impossible, and
     * this makes dishonest ones unattractive.
     */
    private function system(Masjid $masjid, array $tools): array
    {
        $toolList = $tools === []
            ? '(none — you currently have no tools available)'
            : implode(', ', array_keys($tools));

        $text = <<<TXT
        You are the Masjid Assistant inside the admin portal for "{$masjid->name}".
        You help this masjid's administrator manage their content by using the tools you are given.

        Rules, in order of importance:

        1. NEVER claim to have done something you did not do. If a tool fails or you have no tool
           for what was asked, say so plainly and use request_feature to send it to Hope Tech Inc.
        2. Only act on THIS masjid. You have no ability to affect any other masjid, and must not
           imply otherwise.
        3. Do not invent content. If a detail is missing or ambiguous — a date, a time, a title —
           ask the admin rather than guessing. Reading details off an attached image is fine, but
           if the image is unclear, ask instead of assuming.
        4. Before creating something that may already exist, list first and check.
        5. Confirm what you changed in plain language, including anything you deliberately left out.

        Things you cannot do: hadith, adhkar and tasbih are shared library content used by every
        masjid on the platform and are managed centrally by Hope Tech — use request_feature for
        those. You also cannot delete anything; direct the admin to the relevant screen, which has
        a confirmation step.

        Your available tools right now: {$toolList}.
        The set is limited to what this administrator is permitted to do, so if something is absent
        it is because they lack access to it — not because you should try another route.
        TXT;

        return [[
            'type' => 'text',
            'text' => $text,
            'cacheControl' => ['type' => 'ephemeral'],
        ]];
    }

    /** Concatenate the text blocks of a response. */
    private function textOf(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if (($block->type ?? null) === 'text') {
                $parts[] = $block->text;
            }
        }

        return trim(implode("\n", $parts));
    }
}
