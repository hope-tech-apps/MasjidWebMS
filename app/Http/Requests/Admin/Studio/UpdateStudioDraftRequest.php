<?php

namespace App\Http\Requests\Admin\Studio;

use App\Enums\HighLatitudeRule;
use App\Enums\IqamaType;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use App\Http\Requests\BaseFormRequest;
use App\Models\Masjid;
use App\Models\StudioDraft;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/admin/studio/drafts/{draft_id}: an autosave.
 *
 * A draft is work in progress, so nothing here is `required` except the
 * lock_version and nothing checks the database (a unique email, a real city).
 * Those are provisioning's rules, and Step 3 runs ProvisionMasjidRequest on the
 * whole draft before anything is created. What IS checked here is shape: every
 * key a section may hold has a rule, so a key this server does not know is
 * refused rather than stored where nothing will ever read it, and the values
 * the server itself acts on (org type, colours, platforms, account modes,
 * switches) are checked against their allowed sets.
 *
 * Three refusals the plan names (docs/manara-studio-w1.md S2):
 *   - a section outside StudioDraft::ANSWER_SECTIONS;
 *   - a StudioDraft::SECRET_KEYS key at any depth, so a store credential can
 *     never reach the row (R7);
 *   - raw answers over config('studio.drafts.max_answers_bytes').
 *
 * `answers` may arrive as an object or as that object JSON-encoded. The SPA's
 * axios default is form-encoded (.claude/rules/shipping.md), which turns a
 * nested object's booleans into "true"/"false" and its numbers into strings and
 * drops empty arrays entirely; a JSON string survives all of it. Either way, the
 * switches and numbers are coerced here, on the server, where a cached bundle
 * cannot miss the fix.
 */
class UpdateStudioDraftRequest extends BaseFormRequest
{
    private const HEX6 = 'regex:/^#[0-9a-fA-F]{6}$/';

    /** Leaves coerced before validation; `*` is one path segment. */
    private const BOOLEAN_PATHS = ['prayer.iqama_given', 'features.capabilities.*'];

    private const INTEGER_PATHS = ['identity.country_id', 'identity.city_id', 'identity.user_id', 'prayer.iqama.*'];

    private const FLOAT_PATHS = ['identity.latitude', 'identity.longitude'];

    /** Size of the answers as the client sent them, before any decoding. */
    private ?int $rawAnswersBytes = null;

    /**
     * Every key each section may hold, with its rules, relative to the section.
     * The shape is the plan's §S2 answers table.
     *
     * @return array<string, array<string, array<int, mixed>>>
     */
    public static function sectionRules(): array
    {
        $text = fn (int $max) => ['nullable', 'string', "max:{$max}"];
        $colour = ['nullable', 'string', self::HEX6];
        $iqamaOffset = ['nullable', 'integer', 'min:0', 'max:180'];
        $accountMode = ['nullable', 'string', Rule::in(['managed', 'byo'])];

        return [
            'identity' => [
                'org_type' => ['nullable', 'string', Rule::in(Masjid::ORG_TYPES)],
                'name' => $text(255),
                'email' => $text(255),
                'phone' => $text(40),
                'address' => $text(1000),
                'country_id' => ['nullable', 'integer', 'min:1'],
                'city_id' => ['nullable', 'integer', 'min:1'],
                'latitude' => ['nullable', 'numeric', 'min:-90', 'max:90'],
                'longitude' => ['nullable', 'numeric', 'min:-180', 'max:180'],
                'timezone' => ['nullable', 'string', 'timezone'],
                'user_id' => ['nullable', 'integer', 'min:1'],
                'admin' => ['nullable', 'array'],
                'admin.name' => $text(255),
                'admin.email' => $text(255),
                'admin.phone' => $text(40),
                'slug' => $text(63),
                // Public copy, published verbatim (R12); the length S8 provisions.
                'description' => $text(300),
                // Internal only, never published.
                'vibe' => $text(2000),
                'donation_link' => $text(2048),
                'donation_title' => $text(255),
                'donation_message' => $text(255),
                'facebook_url' => $text(255),
                'youtube_url' => $text(255),
                'instagram_url' => $text(255),
                'whatsapp_url' => $text(255),
                'whatsapp_number' => $text(255),
            ],
            'prayer' => [
                'method' => ['nullable', 'string', Rule::in(array_column(PrayerCalculationMethod::cases(), 'value'))],
                'madhab' => ['nullable', 'string', Rule::in(array_column(Madhab::cases(), 'value'))],
                'high_latitude_rule' => ['nullable', 'string', Rule::in(array_column(HighLatitudeRule::cases(), 'value'))],
                'iqama_type' => ['nullable', 'string', Rule::in(array_column(IqamaType::cases(), 'value'))],
                'iqama' => ['nullable', 'array'],
                'iqama.fajr' => $iqamaOffset,
                'iqama.dhuhr' => $iqamaOffset,
                'iqama.asr' => $iqamaOffset,
                'iqama.maghrib' => $iqamaOffset,
                'iqama.isha' => $iqamaOffset,
                'jumaa_iqama' => ['nullable', 'date_format:H:i'],
                // False is "the client has not given iqama times" — S8 then
                // provisions with them hidden instead of showing invented ones.
                'iqama_given' => ['nullable', 'boolean'],
            ],
            'brand' => [
                'primary_color' => $colour,
                'secondary_color' => $colour,
                'accent_color' => $colour,
                'background_color' => $colour,
                'extracted' => ['nullable', 'array', 'max:16'],
                'extracted.*' => ['string', self::HEX6],
                'ink_overrides' => ['nullable', 'array'],
                'ink_overrides.onPrimary' => $colour,
                'ink_overrides.onSecondary' => $colour,
                'ink_overrides.onAccent' => $colour,
            ],
            'content' => [
                'about' => $text(5000),
                'mission' => $text(5000),
                'vision' => $text(5000),
            ],
            'features' => [
                // Keys are checked against the catalogue in withValidator.
                'capabilities' => ['nullable', 'array'],
                'capabilities.*' => ['boolean'],
            ],
            'layout' => [
                'preset' => $text(64),
                'approved_at' => ['nullable', 'date'],
            ],
            'platforms' => [
                'platforms' => ['nullable', 'array'],
                'platforms.*' => ['string', 'distinct', Rule::in(['ios', 'android', 'tvos', 'web'])],
                'apps' => ['nullable', 'array'],
                'apps.ios' => ['nullable', 'array'],
                'apps.ios.account_mode' => $accountMode,
                'apps.android' => ['nullable', 'array'],
                'apps.android.account_mode' => $accountMode,
                'apps.web' => ['nullable', 'array'],
                'apps.web.account_mode' => $accountMode,
            ],
            'domain' => [
                // The managed host is always {identity.slug}.<managed suffix>, so
                // only a client's own domain is stored.
                'custom' => ['nullable', 'array'],
                'custom.host' => $text(253),
                'custom.zone_apex' => $text(253),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $answers = $this->input('answers');

        if (is_string($answers)) {
            $this->rawAnswersBytes = strlen($answers);
            $decoded = json_decode($answers, true);

            // Left as the string when it is not a JSON object, so the `array`
            // rule refuses it by name.
            if (! is_array($decoded)) {
                return;
            }

            $answers = $decoded;
        } elseif (is_array($answers)) {
            $this->rawAnswersBytes = strlen((string) json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            return;
        }

        $this->merge(['answers' => $this->coerce($answers)]);
    }

    public function rules(): array
    {
        $rules = [
            'lock_version' => ['required', 'integer', 'min:0'],
            'current_step' => ['sometimes', 'string', Rule::in(StudioDraft::STEPS)],
            'answers' => ['sometimes', 'nullable', 'array'],
        ];

        foreach (self::sectionRules() as $section => $keys) {
            $rules["answers.{$section}"] = ['sometimes', 'nullable', 'array'];

            foreach ($keys as $key => $keyRules) {
                $rules["answers.{$section}.{$key}"] = $keyRules;
            }
        }

        return $rules;
    }

    /**
     * The sections this save replaces, keyed by name, each as validated or null
     * when it was sent as null to clear it.
     *
     * Not validated('answers') alone: Laravel leaves out of validated() an array
     * that has child rules but none of its children present, so a section sent
     * as {} (or as {"custom": {}}) would vanish from it and the save would record
     * nothing while answering 200. Which sections were sent is read from the
     * input instead, which withValidator has already held to ANSWER_SECTIONS.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function sections(): array
    {
        $sent = $this->input('answers');

        if (! is_array($sent)) {
            return [];
        }

        $sections = [];

        foreach ($sent as $section => $value) {
            $sections[$section] = $value === null ? null : ($this->validated("answers.{$section}") ?? []);
        }

        return $sections;
    }

    public function messages(): array
    {
        return [
            'answers.array' => 'The answers must be an object of sections, or that object as a JSON string.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $max = (int) config('studio.drafts.max_answers_bytes', 262144);

            if ($this->rawAnswersBytes !== null && $this->rawAnswersBytes > $max) {
                $validator->errors()->add('answers', "The answers are too large to save ({$this->rawAnswersBytes} bytes; the limit is {$max}).");
            }

            $answers = $this->input('answers');
            if (! is_array($answers)) {
                return;
            }

            $patterns = $this->allowedPathPatterns();
            $catalogue = config('capabilities', []);

            foreach (Arr::dot($answers) as $path => $value) {
                $path = (string) $path;
                $segments = explode('.', $path);

                if (array_intersect($segments, StudioDraft::SECRET_KEYS) !== []) {
                    $validator->errors()->add("answers.{$path}", 'Store credentials are never saved in a draft. Enter them at Step 3, when the organisation is provisioned.');

                    continue;
                }

                if (! in_array($segments[0], StudioDraft::ANSWER_SECTIONS, true)) {
                    $validator->errors()->add("answers.{$segments[0]}", "\"{$segments[0]}\" is not a section of a Studio draft.");

                    continue;
                }

                if (! $this->matchesAny($path, $patterns)) {
                    $validator->errors()->add("answers.{$path}", "\"{$path}\" is not an answer a Studio draft holds.");

                    continue;
                }

                if ($segments[0] === 'features' && ($segments[1] ?? null) === 'capabilities' && isset($segments[2])
                    && ! array_key_exists($segments[2], $catalogue)) {
                    $validator->errors()->add("answers.{$path}", "\"{$segments[2]}\" is not in the capability catalogue.");
                }
            }
        });
    }

    /** @return list<string> regexes for every dotted path a section may hold */
    private function allowedPathPatterns(): array
    {
        $patterns = [];

        foreach (self::sectionRules() as $section => $keys) {
            foreach (array_merge([$section], array_map(fn ($key) => "{$section}.{$key}", array_keys($keys))) as $path) {
                $patterns[] = '/^' . str_replace('\*', '[^.]+', preg_quote($path, '/')) . '$/';
            }
        }

        return $patterns;
    }

    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Switches and numbers as the types they are. A value that does not coerce
     * is left exactly as sent, so its rule still refuses it by name rather than
     * it being read as false or zero.
     */
    private function coerce(array $answers): array
    {
        foreach (Arr::dot($answers) as $path => $value) {
            $path = (string) $path;

            if (Str::is(self::BOOLEAN_PATHS, $path) && ! is_bool($value) && $value !== null) {
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool !== null) {
                    Arr::set($answers, $path, $bool);
                }
            } elseif (Str::is(self::INTEGER_PATHS, $path) && is_string($value) && preg_match('/^-?\d+$/', $value)) {
                Arr::set($answers, $path, (int) $value);
            } elseif (Str::is(self::FLOAT_PATHS, $path) && is_string($value) && is_numeric($value)) {
                Arr::set($answers, $path, (float) $value);
            }
        }

        return $answers;
    }
}
