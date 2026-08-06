<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\FlyerTemplate;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The designs the Flyer Studio can build from: the system templates that ship with
 * the product plus anything this masjid authored.
 *
 * Read-only. Flyers are rendered CLIENT-SIDE — the droplet has no Node, and no PHP
 * rasteriser here does flexbox or Arabic shaping — so `show` hands the SPA the design's
 * manifest AND its self-contained HTML, and the browser does the compositing. The
 * palette resolver (resources/flyer-templates/palette.js) and the named palettes are
 * imported by the SPA at build time; they are static assets, not tenant data, so they
 * are deliberately not served from here.
 *
 * Tenancy needs no hand-filtering: FlyerTemplate widens the BelongsToMasjid scope to
 * "shared OR mine", so a bound tenant sees the system rows plus its own and nothing
 * else. See the scope comment on the model for why that widening exists.
 */
class FlyerTemplatesController extends Controller
{
    /** Where every template's HTML lives. Nothing outside this directory is servable. */
    private const TEMPLATE_DIRECTORY = 'flyer-templates';

    /**
     * GET /api/admin/masjids/{masjid_id}/flyer-templates
     *
     * The picker. Carries each design's full manifest so the Studio can show the slot
     * list and canvas without a second round trip per template; the HTML is the heavy
     * part and is left to show().
     */
    public function index(Request $request, $masjid_id)
    {
        $templates = FlyerTemplate::query()
            ->when($this->kindFilter($request), fn ($query, $kind) => $query->ofKind($kind))
            // Inactive designs stay reachable for an admin managing their own, but the
            // picker only ever wants the usable ones.
            ->unless($request->boolean('include_inactive'), fn ($query) => $query->active())
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn (FlyerTemplate $template) => $this->serialize($template));

        return response()->json([
            'status' => 'success',
            'data' => $templates,
        ], Response::HTTP_OK);
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/flyer-templates/{template_id}
     *
     * Manifest + HTML, which is everything the client-side renderer needs.
     */
    public function show($masjid_id, $template_id)
    {
        $template = FlyerTemplate::findOrFail($template_id);

        $data = $this->serialize($template);
        $data['html'] = $this->html($template);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ], Response::HTTP_OK);
    }

    /**
     * The picker's one filter, validated instead of passed straight to a scope.
     *
     * `?kind[]=food` arrives as an ARRAY: when() reads it as truthy and
     * scopeOfKind(string) then raises a TypeError, i.e. a 500 for a malformed query
     * string. An unrecognised kind is a 422 for the reason DonationStatsController
     * validates its filters — a client bug should say so rather than quietly return
     * "no designs", which reads as "the Studio is empty".
     */
    private function kindFilter(Request $request): ?string
    {
        $kind = $request->query('kind');

        $validator = Validator::make(
            // An absent or blank filter means "every kind", the picker's default.
            ['kind' => $kind === '' ? null : $kind],
            ['kind' => ['nullable', 'string', Rule::in(FlyerTemplate::KINDS)]]
        );

        if ($validator->fails()) {
            // Same 422 envelope BaseFormRequest produces. Thrown rather than returned
            // because the JSON renderer in bootstrap/app.php hands an
            // HttpResponseException's response back verbatim.
            throw new HttpResponseException(response()->json([
                'status' => 'failed',
                'data' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $validator->validated()['kind'] ?? null;
    }

    /**
     * Load a design's HTML off disk.
     *
     * The filename comes out of a DB column, and a tenant-authored template's schema is
     * tenant input, so it is treated as hostile: reduced to a basename, required to be
     * a .html file, and then required to resolve INSIDE resources/flyer-templates. A
     * bare `basename()` already defeats `../../.env`, but the realpath check is what
     * makes that a guarantee rather than a property of one string function.
     */
    private function html(FlyerTemplate $template): ?string
    {
        $file = basename((string) ($template->schema['html'] ?? ''));

        if ($file === '' || ! Str::endsWith($file, '.html')) {
            return null;
        }

        $directory = realpath(resource_path(self::TEMPLATE_DIRECTORY));
        $path = realpath(resource_path(self::TEMPLATE_DIRECTORY . '/' . $file));

        if ($directory === false || $path === false || ! Str::startsWith($path, $directory . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $html = @file_get_contents($path);

        return $html === false ? null : $html;
    }

    /**
     * The admin-facing shape of a design. A hand-built array rather than a Resource,
     * matching this controller family.
     */
    private function serialize(FlyerTemplate $template): array
    {
        $schema = is_array($template->schema) ? $template->schema : [];

        return [
            'id' => $template->id,
            'key' => $template->key,
            'name' => $template->name,
            'kind' => $template->kind,
            'archetype' => $schema['archetype'] ?? null,
            'summary' => $schema['summary'] ?? null,
            'canvas' => $schema['canvas'] ?? null,
            'root_class' => $schema['root_class'] ?? null,
            'palette' => $schema['palette'] ?? null,
            'slots' => $template->slots(),
            'default_content' => $template->defaultContent(),
            'is_system' => $template->is_system,
            'is_active' => $template->is_active,
            // Null for the shared designs. The Studio uses it to mark "yours".
            'masjid_id' => $template->masjid_id,
            'updated_at' => optional($template->updated_at)->toIso8601String(),
        ];
    }
}
