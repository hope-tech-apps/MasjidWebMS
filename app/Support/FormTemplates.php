<?php

namespace App\Support;

use App\Models\Form;
use App\Models\Masjid;

/**
 * Applies a vertical's starter form templates (config/form_templates.php) to a
 * tenant — the forms counterpart of `Masjid::defaultFeatureKeys()`.
 *
 * Two callers:
 *
 *  - `OnboardingController@provision`, inside the provisioning transaction, so a
 *    school tenant is born with its Admissions / Careers / Withdrawal forms. A
 *    vertical with no templates (masjid, community) short-circuits before any
 *    query runs, which keeps provisioning them byte-identical to pre-T-011.
 *  - `php artisan form:apply-templates {masjid}`, for a tenant that already
 *    exists (Al-Razi's, once T-012 migrates it).
 *
 * Idempotent and NEVER destructive: a form is created only when NO form —
 * live or soft-deleted — already holds the template's slug for that tenant.
 * An admin's edits therefore survive any number of re-applies, and a template
 * an admin deleted stays deleted (resurrecting it would undo a deliberate
 * choice; `form:import` exists for a deliberate restore). Skips are reported,
 * not silent.
 */
class FormTemplates
{
    /**
     * The template definitions for one vertical. Unknown or template-less
     * org_types read as an empty list — the mechanism is data, so a future
     * vertical only needs a config entry.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forOrgType(string $orgType): array
    {
        $templates = config('form_templates.' . $orgType, []);

        return is_array($templates) ? array_values($templates) : [];
    }

    /**
     * Seed the tenant's vertical templates, skipping any slug the tenant
     * already has. With `$dryRun` nothing is written; the report says what
     * WOULD happen.
     *
     * `masjid_id` is taken from the tenant we were handed, mirroring
     * `form:import` — Form does not use BelongsToMasjid, and both call sites
     * (SuperAdmin provisioning, artisan) run without a bound TenantContext.
     *
     * @return array{created: array<int,string>, skipped: array<int,string>}
     */
    public static function applyTo(Masjid $masjid, bool $dryRun = false): array
    {
        $result = ['created' => [], 'skipped' => []];

        $templates = self::forOrgType($masjid->orgType());

        if ($templates === []) {
            return $result;
        }

        // withTrashed: a soft-deleted form still owns its (masjid_id, slug) —
        // creating over it would violate the unique index, and restoring it
        // would resurrect something an admin removed on purpose. Skip both.
        $taken = Form::withTrashed()
            ->where('masjid_id', $masjid->id)
            ->whereIn('slug', array_column($templates, 'slug'))
            ->pluck('slug')
            ->all();

        foreach ($templates as $template) {
            $slug = $template['slug'];

            if (in_array($slug, $taken, true)) {
                $result['skipped'][] = $slug;

                continue;
            }

            if (! $dryRun) {
                Form::create([
                    'masjid_id' => $masjid->id,
                    'slug' => $slug,
                    'name' => $template['name'],
                    'description' => $template['description'] ?? null,
                    'schema' => $template['schema'],
                    'settings' => $template['settings'] ?? null,
                    'is_active' => $template['is_active'] ?? true,
                ]);
            }

            $result['created'][] = $slug;
        }

        return $result;
    }
}
