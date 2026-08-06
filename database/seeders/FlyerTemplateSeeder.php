<?php

namespace Database\Seeders;

use App\Models\FlyerTemplate;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Installs the system flyer designs from resources/flyer-templates/index.json.
 *
 * Idempotent and strictly additive — it upserts by `key` and truncates NOTHING. That
 * is not a stylistic preference: the repo's DatabaseSeeder truncates ~15 tenant tables
 * and has cost this project real data before, so a seeder that runs on every deploy
 * must be incapable of removing a row. Nothing here deletes, and nothing here touches a
 * masjid's own templates.
 *
 * Run it on its own:
 *
 *     php artisan db:seed --class=FlyerTemplateSeeder
 */
class FlyerTemplateSeeder extends Seeder
{
    private const DIRECTORY = 'flyer-templates';

    public function run(): void
    {
        $index = $this->readJson('index.json');

        if ($index === null) {
            $this->warn('resources/' . self::DIRECTORY . '/index.json is missing or unreadable — no flyer templates installed.');

            return;
        }

        // Unbound for the whole pass: system templates carry masjid_id = null, and the
        // BelongsToMasjid creating hook would otherwise stamp them with whatever tenant
        // happens to be bound if this is ever run from inside a request (tinker, a
        // deploy hook). Unbound also drops the read scope, so an existing shared row is
        // found rather than silently re-created.
        app(TenantContext::class)->runWithout(function () use ($index): void {
            foreach ($index['templates'] ?? [] as $entry) {
                $this->install(is_array($entry) ? $entry : []);
            }
        });
    }

    /**
     * One design: its index entry names the manifest, and the manifest IS the schema
     * the Studio and the flyer-content validator both read.
     *
     * @param  array<string,mixed>  $entry
     */
    private function install(array $entry): void
    {
        $key = $entry['key'] ?? null;
        $manifestFile = $entry['manifest'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($manifestFile)) {
            $this->warn('Skipped a flyer template with no key or manifest in index.json.');

            return;
        }

        $schema = $this->readJson($manifestFile);

        if ($schema === null) {
            $this->warn("Skipped flyer template \"{$key}\": its manifest ({$manifestFile}) could not be read.");

            return;
        }

        $kind = $schema['kind'] ?? $entry['kind'] ?? null;

        // `kind` is a DB enum and drives which renderer a design gets, so a manifest
        // naming a kind this app has never heard of is skipped rather than allowed to
        // abort the deploy on an SQL truncation error.
        if (! in_array($kind, FlyerTemplate::KINDS, true)) {
            $this->warn("Skipped flyer template \"{$key}\": unknown kind \"" . var_export($kind, true) . '".');

            return;
        }

        $name = $schema['name'] ?? $entry['name'] ?? $key;

        // shared() and not key alone: this seeder installs the SHARED designs, so a
        // masjid-authored row must never be what it finds. Matching on key alone would
        // forceFill a tenant's own template into a system one — the single most
        // destructive thing a "strictly additive" seeder could do.
        $existing = FlyerTemplate::withoutMasjidScope()->shared()->where('key', $key)->first();

        if (! $existing && FlyerTemplate::withoutMasjidScope()->where('key', $key)->exists()) {
            // Only reachable if a tenant row somehow holds a system key (the model
            // stamps an 'm<id>.' namespace to prevent exactly that). Skipped rather
            // than created, because the create below would hit the unique index and
            // abort the deploy's whole seed run over one design.
            $this->warn("Skipped flyer template \"{$key}\": a masjid already owns that key.");

            return;
        }

        if ($existing) {
            // is_active is deliberately NOT re-set: an admin who switched a system
            // design off must not have it switched back on by the next deploy.
            $existing->forceFill([
                'name' => $name,
                'kind' => $kind,
                'schema' => $schema,
                'is_system' => true,
            ])->save();

            return;
        }

        FlyerTemplate::create([
            'key' => $key,
            'name' => $name,
            'kind' => $kind,
            'schema' => $schema,
            'is_active' => true,
            'is_system' => true,
            // Shared with every tenant. See the migration for why this column is
            // nullable and why the model widens the tenant scope to match.
            'masjid_id' => null,
        ]);
    }

    /**
     * Read one JSON file out of resources/flyer-templates.
     *
     * basename() because the filename comes from index.json rather than from here —
     * these files are repo content today, but a seeder pointed at anything outside its
     * own directory is a bug worth making impossible.
     *
     * @return array<string,mixed>|null
     */
    private function readJson(string $file): ?array
    {
        $path = resource_path(self::DIRECTORY . '/' . basename($file));

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Seeders run headless in CI, where $this->command is null. */
    private function warn(string $message): void
    {
        $this->command?->warn($message);
    }
}
