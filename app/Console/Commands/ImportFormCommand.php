<?php

namespace App\Console\Commands;

use App\Models\Form;
use App\Models\Masjid;
use App\Rules\ValidFormSchema;
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
 * The schema is validated through the SAME rule the admin API uses, so a file cannot
 * introduce a form the builder would have rejected.
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

        $validator = Validator::make($definition, [
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'schema' => ['required', 'array', new ValidFormSchema()],
            'settings' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:opens_at'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            $this->error('That form definition is not valid:');
            foreach ($validator->errors()->all() as $message) {
                $this->line('  • ' . $message);
            }

            return self::FAILURE;
        }

        // Cross-check the identity map and fee rule against the schema, mirroring
        // StoreFormRequest — a typo here silently produces an unsearchable response list
        // or a total of zero, neither of which announces itself.
        $problems = $this->crossCheck($definition);

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

    /**
     * @return array<int,string>
     */
    private function crossCheck(array $definition): array
    {
        $problems = [];

        $flat = [];
        $repeatable = [];

        foreach ($definition['schema']['sections'] ?? [] as $section) {
            if (! empty($section['repeatable'])) {
                if (isset($section['id'])) {
                    $repeatable[] = $section['id'];
                }

                continue;
            }

            foreach ($section['fields'] ?? [] as $field) {
                if (isset($field['name'])) {
                    $flat[] = $field['name'];
                }
            }
        }

        foreach (['name', 'email', 'phone'] as $slot) {
            $declared = $definition['settings']['identity'][$slot] ?? null;

            if ($declared === null || $declared === '') {
                continue;
            }

            // A slot may name one question or several (first + last name), so check each.
            foreach ((array) $declared as $field) {
                if (! is_string($field) || ! in_array($field, $flat, true)) {
                    $label = is_string($field) ? $field : json_encode($field);
                    $problems[] = "settings.identity.{$slot} points at \"{$label}\", which is not a question in this form.";
                }
            }
        }

        $perEntry = $definition['settings']['fee']['perEntryOfSection'] ?? null;

        if ($perEntry && ! in_array($perEntry, $repeatable, true)) {
            $problems[] = "settings.fee.perEntryOfSection points at \"{$perEntry}\", which is not a repeatable section.";
        }

        return $problems;
    }
}
