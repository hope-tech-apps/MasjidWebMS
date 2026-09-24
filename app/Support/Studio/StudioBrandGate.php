<?php

namespace App\Support\Studio;

use App\Http\Requests\Admin\Onboarding\ProvisionMasjidRequest;
use App\Models\Masjid;
use App\Models\StudioDraft;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * What Step 3 refuses to provision, beyond the request's own rules
 * (docs/manara-studio-w1.md S8, R16, R23, R27).
 *
 *  - The four brand colours are exactly `#RRGGBB`. The wizard's endpoint still
 *    accepts #RGB and an alpha channel, and keeps doing so; Studio does not,
 *    because the contrast gate and the device previews grade opaque colours,
 *    and an alpha colour would ship something other than what was graded.
 *  - Every BLOCKING pair of the PaletteReport passes (text on the
 *    background, and the inks on primary, secondary and accent), judged after
 *    auto-ink, which is what the new org's theme will carry.
 *  - With web selected: the client's logo, a slug and an APPROVED layout
 *    preset. Without a preset no home page is written, and the renderer's home
 *    route spins forever on a page it cannot find (R27); without a logo the
 *    site has no mark (R23); without a slug there is no host to serve it on.
 *  - The name is not another organisation's. `masjids.name` is unique in the
 *    database but the wizard's rules never check it, so a duplicate reaches
 *    the insert and fails as a 500 that names nothing; Studio says which
 *    field instead. (The wizard's own behaviour is left as it is.)
 *
 * Every failure is a 422 in the legacy envelope, keyed like the request
 * (`logo`, `slug`, `layout_preset`) or as `brand.<field>`, and nothing has been
 * written when it is thrown.
 */
final class StudioBrandGate
{
    private const COLOURS = ['primary_color', 'secondary_color', 'accent_color', 'background_color'];

    /** Blocking pair => what it is, in the operator's words. */
    private const PAIR_NAMES = [
        'text_on_background' => 'Body text on the background colour',
        'on_primary' => 'Text on the primary colour',
        'on_secondary' => 'Text on the secondary colour',
        'on_accent' => 'Text on the accent colour',
    ];

    /**
     * @return array<string, mixed> the PaletteReport the gate passed, whose `tokens.color` the new org's theme carries
     *
     * @throws HttpResponseException 422 when anything above fails
     */
    public static function assert(StudioDraft $draft, ProvisionMasjidRequest $request): array
    {
        $errors = [];
        $brand = $draft->section('brand');

        foreach (self::COLOURS as $key) {
            $value = $brand[$key] ?? null;

            if (! is_string($value) || ! preg_match('/^#[0-9a-fA-F]{6}\z/', $value)) {
                $errors["brand.{$key}"][] = 'Choose this colour as #RRGGBB: six hex digits, no transparency.';
            }
        }

        $report = null;

        if ($errors === []) {
            $inks = $brand['ink_overrides'] ?? [];
            $report = PaletteContrast::report(
                $brand,
                is_array($inks) ? $inks : [],
                $draft->hasLogo() ? ['width' => $draft->logo_width, 'height' => $draft->logo_height] : null,
            );

            foreach ($report['pairs'] as $pair) {
                if ($pair['blocking'] && ! $pair['passes']) {
                    $errors["brand.{$pair['key']}"][] = sprintf(
                        '%s is %s:1; it needs %s:1 to be readable.',
                        self::PAIR_NAMES[$pair['key']] ?? $pair['key'],
                        $pair['ratio'] === null ? '?' : number_format((float) $pair['ratio'], 2),
                        number_format((float) $pair['required'], 1),
                    );
                }
            }
        }

        if (in_array('web', (array) $request->input('platforms', []), true)) {
            if (! $draft->logoExists()) {
                $errors['logo'][] = 'A website needs the client\'s logo. Upload it in Foundation.';
            }

            if (! $request->filled('slug')) {
                $errors['slug'][] = 'A website needs a subdomain. Choose one in Foundation.';
            }

            $layout = $draft->section('layout');
            $preset = $layout['preset'] ?? null;

            if (! is_string($preset) || $preset === '' || ! filled($layout['approved_at'] ?? null)) {
                $errors['layout_preset'][] = 'A website needs an approved layout. Approve one in Layout.';
            }
        }

        // Trashed rows too: the unique index still holds their names.
        if (Masjid::withTrashed()->where('name', (string) $request->input('name'))->exists()) {
            $errors['name'][] = 'Another organisation already has this name.';
        }

        if ($errors !== []) {
            throw new HttpResponseException(response()->json([
                'status' => 'failed',
                'data' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $report;
    }
}
