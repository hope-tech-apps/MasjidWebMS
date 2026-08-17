<?php

namespace App\Console\Commands;

use App\Http\Requests\Admin\Forms\StoreFormRequest;
use App\Models\Form;
use App\Models\Masjid;
use App\Rules\TierCutoff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Import (or update) a form definition from a JSON file.
 *
 * Built for the Camp 2026 registration, but deliberately generic: a committee can hand
 * over a form as a file and it lands without anyone hand-building sixty fields in the
 * browser. It is also how a form gets copied between masjids or re-created after a
 * mistake.
 *
 *   php artisan form:import 1 database/forms/camp-2026.json
 *
 * Idempotent by (masjid_id, slug): running it twice UPDATES rather than duplicating, so
 * it is safe to re-run after editing the file. Existing responses are untouched.
 *
 * THE WHOLE DOCUMENT is validated through the admin API's OWN rules —
 * `StoreFormRequest::documentRules()`, `::settingsRules()` and `::crossCheck()`, called
 * rather than transcribed — so a file cannot introduce a form the builder would have
 * rejected. The single deliberate exception is `slug`: this command is idempotent by
 * (masjid_id, slug) and updates (or restores) an existing row where the API refuses a
 * duplicate.
 *
 * THAT SENTENCE HAS BEEN WRONG TWICE, in the same shape both times — a promise written
 * about one part of the document and believed about all of it. Round six found it
 * covered only `schema`, not `settings`, which is where the FEE lives: `form:import`
 * accepted `tiers[0].until = '2026-8-14'` with exit 0 while the admin API answered 422
 * for the identical payload, and the imported form then charged the expired early-bird
 * rate for four months. This round found the rewritten promise still false in the
 * remaining half — the rules the command transcribed for itself:
 *
 *     capacity 2000000    form:import ACCEPT   POST 422   PUT 422
 *     is_active null      form:import ACCEPT and stores `true`
 *                         POST 201     and stores `false`
 *
 * So it is no longer a promise. FormDoorEquivalenceTest feeds one fixture table through
 * every door and asserts the verdicts and the stored rows are identical; when this
 * sentence next goes false, that test fails instead of a committee's camp.
 */
class ImportFormCommand extends Command
{
    protected $signature = 'form:import
                            {masjid : The masjid id to import into}
                            {path : Path to the form JSON, absolute or relative to the app root}
                            {--dry-run : Validate and report without writing anything}';

    protected $description = 'Import or update a sign-up form definition from a JSON file';

    public function handle(): int
    {
        $masjidId = (int) $this->argument('masjid');
        $path = $this->argument('path');

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            $this->error("No file at {$path}");

            return self::FAILURE;
        }

        $masjid = Masjid::find($masjidId);

        if (! $masjid) {
            $this->error("No masjid with id {$masjidId}.");

            return self::FAILURE;
        }

        try {
            $definition = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('That file is not valid JSON: ' . $e->getMessage());

            return self::FAILURE;
        }

        // Editorial comments are allowed in the file and dropped on import.
        unset($definition['$comment']);

        // A BLANK TIER CUT-OFF MEANS THE SAME THING HERE AS IT DOES THROUGH THE
        // BUILDER, because this is the only door that can see one.
        //
        // `TrimStrings` and `ConvertEmptyStringsToNull` are global middleware
        // (bootstrap/app.php), so on POST and PUT a blank `until` is already null
        // before validation — the API stores NULL and means "no cut-off, this is
        // the open-ended final price". A file does not pass through middleware,
        // so the same document used to store `''` here. Measured, reading the row
        // back:
        //
        //     until ''      POST -> stored NULL      form:import -> stored ''
        //     until '   '   POST -> stored NULL      form:import -> stored ''
        //
        // One document, two forms, and under `Form::resolveTier()` those two
        // forms priced differently. Normalised here rather than refused, because
        // refusing would invent a THIRD meaning for a spelling the builder has
        // always accepted — and the importer is the door that has to match, not
        // the one that gets to legislate. See App\Rules\TierCutoff.
        if (isset($definition['settings']['fee']['tiers']) && is_array($definition['settings']['fee']['tiers'])) {
            foreach ($definition['settings']['fee']['tiers'] as $i => $tier) {
                if (is_array($tier) && array_key_exists('until', $tier)) {
                    $definition['settings']['fee']['tiers'][$i]['until'] = TierCutoff::normalise($tier['until']);
                }
            }
        }

        // `is_active` is coerced EXACTLY as StoreFormRequest::prepareForValidation
        // coerces it, before the shared rules run. Measured before this: the same
        // file with `"is_active": null` imported as a LIVE form and POSTed as a
        // switched-off one — both doors answered success and the two rows
        // disagreed about whether the public could see the form at all. Absent
        // still means live (the documented default); anything present is read the
        // way the builder reads it.
        if (array_key_exists('is_active', $definition)) {
            $definition['is_active'] = filter_var($definition['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        // EVERY rule the admin doors apply, from the admin doors themselves — not
        // a paraphrase of them. `slug` is the one deliberate difference and it is
        // argued on StoreFormRequest::rules(): this command is idempotent by
        // (masjid_id, slug), so an existing slug is its update path rather than a
        // refusal.
        //
        // Until this round the command carried its own transcription, and the
        // transcription had drifted in two measurable places on top of the
        // `settings` gap round six closed: `capacity` had no upper bound (2000000
        // imported, 422 through the API) and `is_active` above. See
        // StoreFormRequest::documentRules().
        $validator = Validator::make($definition, [
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ] + StoreFormRequest::documentRules() + StoreFormRequest::settingsRules());

        if ($validator->fails()) {
            $this->error('That form definition is not valid:');
            foreach ($validator->errors()->all() as $message) {
                $this->line('  • ' . $message);
            }

            return self::FAILURE;
        }

        // Cross-check the identity map and fee rule against the schema — a typo
        // here silently produces an unsearchable response list or a total of
        // zero, neither of which announces itself.
        //
        // StoreFormRequest::crossCheck() IS the check, called rather than
        // mirrored. This command used to carry a near-identical private copy;
        // "mirroring StoreFormRequest" is the sentence that describes two
        // implementations, and two implementations of one paragraph is the shape
        // every round of this review has found somewhere else.
        $problems = StoreFormRequest::crossCheck(
            (array) ($definition['schema'] ?? []),
            (array) ($definition['settings'] ?? []),
        );

        if ($problems !== []) {
            $this->error('The settings reference questions that do not exist:');
            foreach ($problems as $problem) {
                $this->line('  • ' . $problem);
            }

            return self::FAILURE;
        }

        $existing = Form::withTrashed()
            ->where('masjid_id', $masjidId)
            ->where('slug', $definition['slug'])
            ->first();

        $sectionCount = count($definition['schema']['sections'] ?? []);
        $fieldCount = collect($definition['schema']['sections'] ?? [])
            ->sum(fn ($section) => count($section['fields'] ?? []));

        $this->info(($existing ? 'Updating' : 'Creating') . " \"{$definition['name']}\" for {$masjid->name}");
        $this->line("  slug:     {$definition['slug']}");
        $this->line("  sections: {$sectionCount}");
        $this->line("  fields:   {$fieldCount}");

        if ($existing) {
            $this->line("  responses already collected: {$existing->response_count} (left untouched)");
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $attributes = [
            'name' => $definition['name'],
            'description' => $definition['description'] ?? null,
            'schema' => $definition['schema'],
            'settings' => $definition['settings'] ?? null,
            'is_active' => $definition['is_active'] ?? true,
            'opens_at' => $definition['opens_at'] ?? null,
            'closes_at' => $definition['closes_at'] ?? null,
            'capacity' => $definition['capacity'] ?? null,
        ];

        if ($existing) {
            // Restore first: re-importing a form an admin deleted should bring it back
            // rather than fail on the unique (masjid_id, slug) index.
            if ($existing->trashed()) {
                $existing->restore();
                $this->comment('  (this form had been deleted — restored)');
            }
            $existing->update($attributes);
            $form = $existing;
        } else {
            $form = Form::create(array_merge($attributes, [
                'masjid_id' => $masjidId,
                'slug' => $definition['slug'],
            ]));
        }

        $this->newLine();
        $this->info("Done. Form id {$form->id}.");
        $this->line('Add it to a page by creating a section of type "form" with content.form_id = ' . $form->id . '.');

        return self::SUCCESS;
    }
}
