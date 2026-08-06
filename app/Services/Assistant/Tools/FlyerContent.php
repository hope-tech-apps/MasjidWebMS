<?php

namespace App\Services\Assistant\Tools;

use App\Models\FlyerTemplate;
use App\Models\Masjid;
use App\Models\ThemeSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * What the Assistant is allowed to put into a flyer, and what it has to ask for.
 *
 * The designs in resources/flyer-templates own every pixel — zone bands, type scale,
 * treatments, colour. All the Assistant contributes is words, and this is where those
 * words are checked against the design that will print them: a slot the design does
 * not define is refused, a line longer than its zone is refused (these layouts do not
 * reflow, they overflow), and anything the owner settled as ask-the-admin is refused
 * rather than quietly filled from a default.
 *
 * It sits beside the tools rather than in App\Support because it encodes the
 * ASSISTANT's rules, which are deliberately stricter than the Studio's. An admin
 * typing into the Studio may look at the manifest's placeholder title and keep it; a
 * model that shipped "Fresh Homemade Biryani" because nobody said otherwise would be
 * inventing content on a masjid's behalf.
 */
class FlyerContent
{
    /** The palette answer that means "derive from this masjid's own brand". */
    public const PALETTE_FROM_THEME = 'theme';

    /** Fallback when a design declares no palette of its own. Matches index.json. */
    private const NEUTRAL_PALETTE = 'cool-neutral';

    // ------------------------------------------------------------------ describing

    /**
     * One design as the model should see it: what it is for, and every slot it needs
     * filled. Nulls are stripped so the tool result stays readable — the model pays
     * for every key it reads.
     */
    public static function describe(FlyerTemplate $template): array
    {
        return array_filter([
            'key' => $template->key,
            'name' => $template->name,
            'kind' => $template->kind,
            'summary' => $template->schema['summary'] ?? null,
            'default_palette' => $template->schema['palette']['default'] ?? null,
            // janazah carries a list of things the design must NOT contain. Passing it
            // through verbatim is cheaper than restating it in a tool description that
            // would then drift from the manifest.
            'constraints' => $template->schema['constraints'] ?? null,
            'slots' => array_map(
                fn (array $slot) => self::describeSlot($slot),
                self::slotsOf($template)
            ),
        ], fn ($value) => $value !== null && $value !== []);
    }

    private static function describeSlot(array $slot): array
    {
        $described = array_filter([
            'name' => $slot['name'],
            'label' => $slot['label'] ?? $slot['name'],
            'type' => $slot['type'] ?? 'text',
            'required' => ! empty($slot['required']),
            'max_length' => $slot['maxLength'] ?? null,
            'max_items' => $slot['maxItems'] ?? null,
            'help' => $slot['help'] ?? null,
            // Named a suggestion, not a default, because that is what it is here: a
            // wording to read back for approval. Nothing in this class ever applies it.
            'suggested_value' => $slot['default'] ?? null,
            'item_slots' => isset($slot['item_slots']) && is_array($slot['item_slots'])
                ? array_map(
                    fn (array $field) => self::describeSlot($field),
                    array_values(array_filter($slot['item_slots'], fn ($f) => is_array($f) && ! empty($f['name'])))
                )
                : null,
        ], fn ($value) => $value !== null);

        if (! empty($slot['ask_always'])) {
            $described['ask_the_admin_every_time'] = true;
        }

        if (($slot['type'] ?? null) === 'image') {
            $described['filled_in_studio'] = true;
        }

        return $described;
    }

    // ------------------------------------------------------------------ validation

    /**
     * Check the model's content against the design.
     *
     * @return array ['ok' => true, 'content' => <clean>, 'studio_needs' => string[]]
     *               or ['ok' => false, 'error' => <one message the model can act on>]
     */
    public static function validate(FlyerTemplate $template, array $content): array
    {
        $slots = self::slotsOf($template);

        if ($slots === []) {
            return ['ok' => false, 'error' => "The \"{$template->name}\" design has no slots defined, so there is nothing I can fill in. Tell the admin and use request_feature."];
        }

        $names = array_column($slots, 'name');
        $unknown = array_diff(array_keys($content), $names);

        if ($unknown !== []) {
            return ['ok' => false, 'error' => sprintf(
                'This design has no slot called %s. It takes exactly these, and nothing else: %s.',
                self::quoted($unknown),
                implode(', ', $names)
            )];
        }

        $clean = [];
        $studioNeeds = [];
        $mustAsk = [];
        $missing = [];

        foreach ($slots as $slot) {
            $name = $slot['name'];
            $type = $slot['type'] ?? 'text';
            $label = $slot['label'] ?? $name;
            $given = $content[$name] ?? null;
            $supplied = array_key_exists($name, $content) && $given !== null;

            if ($type === 'image') {
                // Artwork is uploaded in the Studio, where a photo also goes through
                // background removal. A model that "set the photo" from here would be
                // describing something that never happened.
                if (is_string($given) && trim($given) !== '') {
                    return ['ok' => false, 'error' => "I cannot set \"{$label}\" — pictures are added in Flyer Studio, not here. Leave it out, and tell the admin to add it when they open the draft."];
                }

                $clean[$name] = null;
                $studioNeeds[] = $label . (empty($slot['required']) ? ' (optional)' : '');
                continue;
            }

            if ($type === 'list') {
                // An empty list is treated as an unanswered one. A bulletin whose
                // programme has no rows is not a flyer, it is a headline.
                if (! $supplied || $given === []) {
                    if (! empty($slot['required'])) {
                        $missing[] = $label;
                    }
                    $clean[$name] = null;
                    continue;
                }

                $rows = self::validateRows($slot, $given);

                if (isset($rows['error'])) {
                    return ['ok' => false, 'error' => $rows['error']];
                }

                $clean[$name] = $rows['items'];
                continue;
            }

            if ($supplied && ! is_scalar($given)) {
                return ['ok' => false, 'error' => "\"{$label}\" has to be a line of text."];
            }

            $value = $supplied ? trim((string) $given) : '';

            if ($value === '') {
                // An ask_always slot that was never sent means the question was never
                // put to the admin. Sending it EMPTY is different, and allowed where the
                // design makes it optional: that is "I asked, they don't want one".
                if (! empty($slot['ask_always']) && ! $supplied) {
                    $ask = trim($label . (isset($slot['help']) ? ' — ' . $slot['help'] : ''));

                    // Say how to record a "no". This is the only slot kind that can be
                    // declined, and a model that is told "no thanks" and then omits it
                    // gets this same refusal again, with nothing pointing at the way out.
                    if (empty($slot['required'])) {
                        $ask .= sprintf(' (optional — if they do not want one, send "%s" as an empty string to record that they were asked and said no)', $name);
                    }

                    $mustAsk[] = $ask;
                    continue;
                }

                if (! empty($slot['required'])) {
                    $missing[] = $label;
                    continue;
                }

                // Deliberately empty rather than the manifest's default: an unanswered
                // slot is a question for the admin, not a blank for us to fill.
                $clean[$name] = null;
                continue;
            }

            if ($problem = self::problemWith($value, $slot, $label)) {
                return ['ok' => false, 'error' => $problem];
            }

            $clean[$name] = $value;
        }

        if ($mustAsk !== [] || $missing !== []) {
            return ['ok' => false, 'error' => self::askFor($template, $mustAsk, $missing)];
        }

        return ['ok' => true, 'content' => $clean, 'studio_needs' => $studioNeeds];
    }

    /**
     * The rows of a list slot (only event-bulletin's `sessions` today).
     *
     * @return array ['items' => array] or ['error' => string]
     */
    private static function validateRows(array $slot, mixed $given): array
    {
        $label = $slot['label'] ?? $slot['name'];

        if (! is_array($given) || ! array_is_list($given)) {
            return ['error' => "\"{$label}\" has to be a list of rows."];
        }

        $maxItems = $slot['maxItems'] ?? null;

        if (is_int($maxItems) && count($given) > $maxItems) {
            return ['error' => sprintf(
                '"%s" holds %d rows at most and you sent %d. Past that the type has to shrink, so split the programme across two flyers instead.',
                $label,
                $maxItems,
                count($given)
            )];
        }

        $fields = array_values(array_filter(
            $slot['item_slots'] ?? [],
            fn ($field) => is_array($field) && ! empty($field['name'])
        ));

        if ($fields === []) {
            return ['error' => "\"{$label}\" has no row fields defined, so I cannot fill it in."];
        }

        $fieldNames = array_column($fields, 'name');
        $items = [];

        foreach ($given as $index => $row) {
            $line = $index + 1;

            if (! is_array($row)) {
                return ['error' => sprintf('Row %d of "%s" has to be an object with %s.', $line, $label, implode(', ', $fieldNames))];
            }

            $unknown = array_diff(array_keys($row), $fieldNames);

            if ($unknown !== []) {
                return ['error' => sprintf(
                    'Row %d of "%s" has no field called %s. Each row takes: %s.',
                    $line,
                    $label,
                    self::quoted($unknown),
                    implode(', ', $fieldNames)
                )];
            }

            $clean = [];

            foreach ($fields as $field) {
                $raw = $row[$field['name']] ?? null;
                $value = is_scalar($raw) ? trim((string) $raw) : '';
                $fieldLabel = $field['label'] ?? $field['name'];

                if ($value === '') {
                    if (! empty($field['required'])) {
                        return ['error' => sprintf('Row %d of "%s" is missing %s. Ask the admin for it.', $line, $label, $fieldLabel)];
                    }

                    $clean[$field['name']] = null;
                    continue;
                }

                if ($problem = self::problemWith($value, $field, sprintf('%s in row %d of "%s"', $fieldLabel, $line, $label))) {
                    return ['error' => $problem];
                }

                $clean[$field['name']] = $value;
            }

            $items[] = $clean;
        }

        return ['items' => $items];
    }

    /** Length and date-shape checks, shared by slots and by list rows. */
    private static function problemWith(string $value, array $slot, string $label): ?string
    {
        $max = $slot['maxLength'] ?? null;

        if (is_int($max) && $max > 0 && mb_strlen($value) > $max) {
            return sprintf(
                '%s is %d characters and that zone holds %d. Shorten it — the design does not reflow, it overflows.',
                $label,
                mb_strlen($value),
                $max
            );
        }

        // No flyer in the corpus prints a machine-formatted date, and a model reading
        // off a calendar reaches for one by reflex. Catching it here is cheaper than an
        // admin noticing "2026-05-15" after the image is exported and sent.
        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', $value)) {
            return sprintf(
                '%s has a date written as digits. These flyers read "Friday (May 15th), After Juma\'a Prayer" — write it that way.',
                $label
            );
        }

        return null;
    }

    /** One message that tells the model exactly what to go and ask. */
    private static function askFor(FlyerTemplate $template, array $mustAsk, array $missing): string
    {
        $parts = [];

        if ($mustAsk !== []) {
            $parts[] = "Ask the admin for these — they are settled per flyer and must never be carried forward from an earlier one:\n- "
                . implode("\n- ", $mustAsk);
        }

        if ($missing !== []) {
            $parts[] = 'Still missing: ' . implode(', ', $missing) . '.';
        }

        $parts[] = $template->kind === 'janazah'
            ? 'This is a janazah notice: every detail comes from the family, exactly as they gave it. Do not supply any of it yourself, and read the whole draft back before it is used. Nothing was saved.'
            : 'Ask the admin for them rather than filling them in yourself. Nothing was saved.';

        return implode(' ', $parts);
    }

    // --------------------------------------------------------------------- palette

    /**
     * The palettes this masjid may be offered, plus whether one of them is its own.
     *
     * A palette restricted to another tenant is not listed at all. Burlington's purple
     * is Burlington's brand, not the product's, and the surest way to keep it off
     * another masjid's flyer is for the model never to learn it exists.
     */
    public static function palettesFor(Masjid $masjid): array
    {
        $offered = [];

        foreach (self::catalogue() ?? [] as $key => $palette) {
            $restricted = $palette['restricted_to_masjid'] ?? null;

            if ($restricted !== null && (int) $restricted !== (int) $masjid->id) {
                continue;
            }

            $offered[] = array_filter([
                'key' => (string) $key,
                'name' => $palette['name'] ?? $key,
                'note' => $palette['note'] ?? null,
                'this_masjids_own_brand' => $restricted !== null ? true : null,
            ], fn ($value) => $value !== null);
        }

        return $offered;
    }

    /**
     * Turn the admin's answer into the palette stored on the flyer.
     *
     * flyers.palette is the RENDERER'S INPUT, not a record of the conversation. The
     * Studio saves what resolvePalette() in resources/flyer-templates/palette.js handed
     * back — one FLAT object (grad[4], field, ground, accent, ink, fieldInk, groundInk,
     * pillBg/pillInk, barBg/barInk) that paletteToCssVars() reads straight off — and
     * that is the only shape a template can render. So this returns exactly that shape
     * and nothing around it; where the choice came from is reported to the model in
     * `label` and `note` instead of being buried in the column.
     *
     * "theme" is the one answer this server cannot finish. Deriving a gradient from a
     * brand colour, and then repairing every ink that fails WCAG AA, is palette.js's
     * job and it runs in the BROWSER — the droplet has no Node. That answer is stored
     * as an EMPTY snapshot, which is a value the rest of the stack already defines:
     * FlyersController writes one for any flyer made outside the browser, and the
     * Studio resolves it from theme_settings the moment the draft is opened.
     *
     * Two "theme" answers never derive and are pinned here instead:
     *   - a janazah notice keeps the design's sombre ground whatever the brand is;
     *   - a masjid with a named palette of its own — that palette IS its brand, and
     *     palette.js only knows to do this for masjid 1.
     *
     * @return array ['ok' => true, 'palette' => array, 'label' => string, 'note' => ?string]
     *               or ['ok' => false, 'error' => string]
     */
    public static function resolvePalette(FlyerTemplate $template, string $choice, Masjid $masjid): array
    {
        $catalogue = self::catalogue();

        if ($catalogue === null) {
            return ['ok' => false, 'error' => 'I could not read the flyer palettes on this server, so I cannot save a flyer with the right colours. Tell the admin, and use request_feature.'];
        }

        $choice = trim($choice);

        if (strcasecmp($choice, self::PALETTE_FROM_THEME) === 0) {
            return self::themePalette($template, $masjid, $catalogue);
        }

        $palette = $catalogue[$choice] ?? null;

        if ($palette === null) {
            return ['ok' => false, 'error' => sprintf(
                'There is no palette called "%s". Offer the admin: %s, or "theme" for their own brand colours.',
                $choice,
                implode(', ', array_column(self::palettesFor($masjid), 'key'))
            )];
        }

        $restricted = $palette['restricted_to_masjid'] ?? null;

        if ($restricted !== null && (int) $restricted !== (int) $masjid->id) {
            return ['ok' => false, 'error' => 'That palette is another masjid\'s brand and cannot be used here. Ask this masjid which colours they want, or use "theme" for their own.'];
        }

        return [
            'ok' => true,
            'palette' => self::colourSnapshot($palette, $choice),
            'label' => $choice,
            'note' => null,
        ];
    }

    /**
     * The "just use our colours" answer, taken as far as a server with no Node can take
     * it: a pinned palette where one is settled, an empty snapshot where the derivation
     * belongs to the browser.
     *
     * @return array same contract as resolvePalette()
     */
    private static function themePalette(FlyerTemplate $template, Masjid $masjid, array $catalogue): array
    {
        // A janazah notice is not brand-coloured at all, so there is nothing to look up
        // for it and nothing to derive — it drops straight through to the design's own
        // ground below.
        if ($template->kind !== 'janazah') {
            $own = self::ownPaletteKey($catalogue, $masjid);

            if ($own !== null) {
                return [
                    'ok' => true,
                    'palette' => self::colourSnapshot($catalogue[$own], $own),
                    'label' => $own,
                    'note' => null,
                ];
            }

            if (self::brandColours($masjid) !== []) {
                // The only empty snapshot this class writes, and it is a value the
                // stack already defines rather than a hole: the Studio resolves it from
                // theme_settings on open, which is where the gradient is derived and
                // every ink contrast-checked.
                return [
                    'ok' => true,
                    'palette' => [],
                    'label' => 'their own brand colours',
                    'note' => 'The draft is set to this masjid\'s own colours. They are worked out from the Theme screen when the admin opens the draft in Flyer Studio, and each one is contrast-checked there.',
                ];
            }
        }

        $base = self::designPaletteKey($template, $masjid, $catalogue);

        if ($base === null) {
            return ['ok' => false, 'error' => sprintf(
                'This design starts from another masjid\'s brand palette, and one masjid\'s colours are never used on another\'s flyer. Ask the admin to pick one of these instead: %s.',
                implode(', ', array_column(self::palettesFor($masjid), 'key'))
            )];
        }

        $palette = $catalogue[$base] ?? null;

        // Refused rather than saved with no colours, the same as an unreadable palettes
        // file: a flyer whose palette is blank renders in the neutral default, which is
        // not the design anyone approved.
        if ($palette === null) {
            return ['ok' => false, 'error' => sprintf(
                'This design starts from a palette called "%s", and there is no such palette on this server. Tell the admin, use request_feature, and in the meantime ask them to pick one of these: %s.',
                $base,
                implode(', ', array_column(self::palettesFor($masjid), 'key'))
            )];
        }

        return [
            'ok' => true,
            'palette' => self::colourSnapshot($palette, $base),
            'label' => $base,
            'note' => $template->kind === 'janazah'
                ? 'A janazah notice keeps the design\'s sombre colours whatever the masjid\'s brand is. It is the one flyer their own colours are deliberately not used on — say so rather than telling them their colours were applied.'
                : "This masjid has no brand colours saved, so the draft uses the neutral \"{$base}\" palette. Say so — the admin can set their colours on the Theme screen and re-open the draft.",
        ];
    }

    /** This masjid's own named palette, when the catalogue holds one. */
    private static function ownPaletteKey(array $catalogue, Masjid $masjid): ?string
    {
        foreach ($catalogue as $key => $palette) {
            if (isset($palette['restricted_to_masjid']) && (int) $palette['restricted_to_masjid'] === (int) $masjid->id) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * The palette a design starts from, or null when that palette is another masjid's
     * brand.
     *
     * A design's default gets the same restricted_to_masjid check as the other two
     * palette paths. Templates are per-masjid as well as shared (FlyerTemplate scopes
     * to "shared OR mine"), so a schema can name any key it likes, and a design
     * defaulting to 'burlington-purple' would otherwise put Burlington's brand on
     * another masjid's flyer with nobody having chosen it.
     */
    private static function designPaletteKey(FlyerTemplate $template, Masjid $masjid, array $catalogue): ?string
    {
        $default = (string) ($template->schema['palette']['default'] ?? self::NEUTRAL_PALETTE);
        $restricted = $catalogue[$default]['restricted_to_masjid'] ?? null;

        if ($restricted !== null && (int) $restricted !== (int) $masjid->id) {
            return null;
        }

        return $default;
    }

    /** The colour values only — the catalogue's prose and its restriction are not part of a flyer. */
    private static function colourSnapshot(array $palette, string $key): array
    {
        return Arr::except($palette, ['note', 'restricted_to_masjid']) + ['key' => $key];
    }

    /** This masjid's own hexes, or [] when it has never set a theme. */
    private static function brandColours(Masjid $masjid): array
    {
        // ThemeSetting does not carry BelongsToMasjid, so it is filtered by hand here —
        // the same way update_theme reaches it.
        $theme = ThemeSetting::where('masjid_id', $masjid->id)->first();

        if (! $theme) {
            return [];
        }

        return array_filter([
            'primary' => $theme->primary_color,
            'secondary' => $theme->secondary_color,
            'accent' => $theme->accent_color,
            'background' => $theme->background_color,
        ], fn ($hex) => is_string($hex) && trim($hex) !== '');
    }

    /**
     * The named palettes, straight from the file the templates and palette.js share.
     * Null (not []) when the file is missing or unreadable, so a caller refuses rather
     * than saving a flyer with no colours at all.
     *
     * @return array<string,array<string,mixed>>|null
     */
    private static function catalogue(): ?array
    {
        $path = resource_path('flyer-templates/palettes.json');

        if (! is_readable($path)) {
            Log::error('Flyer palettes file missing', ['path' => $path]);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $palettes = $decoded['palettes'] ?? null;

        if (! is_array($palettes)) {
            Log::error('Flyer palettes file unreadable', ['path' => $path]);

            return null;
        }

        // Every caller treats an entry as a map of colours. A hand-edited file with a
        // string where a palette should be would otherwise reach Arr::except() and
        // fatal; dropped here, it comes out as "there is no palette called X", which is
        // a refusal the admin can act on.
        return array_filter($palettes, 'is_array');
    }

    // ----------------------------------------------------------------------- shared

    /** @return array<int,array<string,mixed>> The design's slots, every one with a name. */
    private static function slotsOf(FlyerTemplate $template): array
    {
        return array_values(array_filter(
            $template->slots(),
            fn ($slot) => is_array($slot) && ! empty($slot['name'])
        ));
    }

    private static function quoted(array $names): string
    {
        return implode(', ', array_map(fn ($name) => '"' . $name . '"', $names));
    }
}
