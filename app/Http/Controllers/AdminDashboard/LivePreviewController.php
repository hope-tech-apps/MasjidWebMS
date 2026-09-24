<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LivePreview\PreviewSessionRequest;
use App\Http\Requests\Admin\Theme\SaveThemeSettingsRequest;
use App\Http\Resources\Api\V1\ThemeSettingResource;
use App\Models\Masjid;
use App\Models\ThemeSetting;
use App\Support\Renderer\PreviewToken;
use App\Support\Renderer\RendererConfig;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live preview sessions for the site editors (docs/live-preview.md §4.3).
 *
 * WHO. Each surface's route sits inside the SAME route group as the save it previews,
 * so whoever may save it may preview it and nobody else: pages inside
 * `capability:web_pages` + `capability:website`, theme beside the theme save (admin +
 * tenant), splash inside `capability:splash`. The organisation is the one the tenant
 * middleware bound; the body never names it.
 *
 * WHAT. A five-minute token for the renderer and the URL to load it at. The URL is
 * built from configuration, never from this request's Host (.claude/rules/
 * generated-urls.md): it is handed to a browser and outlives the request.
 *
 * WHEN OFF. With the renderer integration unconfigured, or when the admin's own origin
 * is not one the renderer will let frame a preview, the answer is `enabled: false` with
 * a reason, so the editor simply shows no preview pane.
 */
class LivePreviewController extends Controller
{
    public function pages(PreviewSessionRequest $request, $masjid_id): JsonResponse
    {
        return $this->session($request, 'pages', (int) $masjid_id);
    }

    public function theme(PreviewSessionRequest $request, $masjid_id): JsonResponse
    {
        return $this->session($request, 'theme', (int) $masjid_id);
    }

    public function splash(PreviewSessionRequest $request, $masjid_id): JsonResponse
    {
        return $this->session($request, 'splash', (int) $masjid_id);
    }

    /**
     * The theme exactly as /api/v1/settings would serve it after saving these values —
     * the four colours and the resolved token tree the renderer prefers — without
     * saving anything. The renderer reads `tokens.color.*` ahead of the flat colours, so
     * a preview must carry the server's derivation (App\Support\DesignTokens), not a
     * client-side imitation of it.
     */
    public function themePreview(SaveThemeSettingsRequest $request, $masjid_id): JsonResponse
    {
        $masjid = Masjid::findOrFail($this->organisationId((int) $masjid_id));

        $draft = $masjid->themeSettings?->replicate() ?? new ThemeSetting();
        $draft->fill($request->safe()->only([
            'primary_color',
            'secondary_color',
            'accent_color',
            'background_color',
            'tokens',
        ]));

        return response()->json([
            'status' => 'success',
            'data' => ['theme' => (new ThemeSettingResource($draft))->resolve($request)],
        ]);
    }

    private function session(PreviewSessionRequest $request, string $surface, int $routeMasjidId): JsonResponse
    {
        $secret = RendererConfig::secret();
        $previewOrigin = RendererConfig::previewOrigin();
        if ($secret === null || $previewOrigin === null) {
            return $this->disabled('not_configured');
        }

        $adminOrigin = $this->adminOrigin($request);
        if ($adminOrigin === null) {
            return $this->disabled('origin_not_allowed');
        }

        $path = $request->previewPath();
        $expiresAt = now()->addSeconds(PreviewToken::TTL_SECONDS);
        $token = PreviewToken::mint(
            $secret,
            $this->organisationId($routeMasjidId),
            $surface,
            $path,
            $adminOrigin,
            $expiresAt->getTimestamp(),
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => true,
                // The token signs the decoded path; the URL carries it percent-encoded, which
                // h3 decodes back before the renderer compares them (an Arabic slug included).
                'url' => $previewOrigin.'/__manara/preview'.PreviewToken::encodePath($path).'?mp='.$token,
                'origin' => $previewOrigin,
                'surface' => $surface,
                'path' => $path,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    /** The bound tenant; for a SuperAdmin the route's masjid, which the middleware bound. */
    private function organisationId(int $routeMasjidId): int
    {
        return app(TenantContext::class)->get() ?? $routeMasjidId;
    }

    /**
     * The origin of the admin page asking, if the renderer will let it frame a preview.
     * A browser always sends Origin on a POST; Referer is the fallback for clients that
     * do not. Either way it only SELECTS one of the configured origins.
     */
    private function adminOrigin(Request $request): ?string
    {
        $candidate = $request->headers->get('Origin');
        if (! is_string($candidate) || $candidate === '' || $candidate === 'null') {
            $referer = (string) $request->headers->get('Referer', '');
            $scheme = parse_url($referer, PHP_URL_SCHEME);
            $host = parse_url($referer, PHP_URL_HOST);
            $port = parse_url($referer, PHP_URL_PORT);
            $candidate = is_string($scheme) && is_string($host)
                ? strtolower($scheme.'://'.$host.($port ? ':'.$port : ''))
                : null;
        }

        $origin = RendererConfig::origin(is_string($candidate) ? strtolower($candidate) : null);

        return $origin !== null && in_array($origin, RendererConfig::adminOrigins(), true) ? $origin : null;
    }

    private function disabled(string $reason): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['enabled' => false, 'reason' => $reason],
        ]);
    }
}
