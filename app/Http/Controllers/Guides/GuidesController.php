<?php

namespace App\Http\Controllers\Guides;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\GuideUnansweredQuestion;
use App\Support\Guides\GuideAskService;
use App\Support\Guides\GuideAskLimits;
use Throwable;
use App\Models\User;
use App\Support\Guides\GuideReleases;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/** The realm gates authenticate first; entitlements are checked before disk reads. */
class GuidesController extends Controller
{
    public function __construct(private GuideReleases $releases) {}

    private function allowed(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User || ! app(TenantContext::class)->get()) return [];
        $organisation = Masjid::find(app(TenantContext::class)->get());
        if (! $organisation) return [];
        return match ($user->type) {
            'SuperAdmin', 'MasjidAdmin' => $organisation->crm_enabled ? ['admin', 'school'] : ['admin'],
            'Teacher' => ['teacher'],
            'LunchStaff' => ['lunch'],
            default => [],
        };
    }

    public function index(Request $request, string $masjid_id)
    {
        $allowed = $this->allowed($request);
        abort_unless($allowed, 404);
        $manifest = $this->releases->current();
        $books = [];
        foreach ($allowed as $book) {
            if (isset($manifest['books'][$book])) $books[] = ['book' => $book, 'title' => $manifest['books'][$book]['title'], 'version' => $manifest['version']];
        }
        return response()->json(['status' => 'success', 'data' => $books,
            'ask_available' => app(GuideAskService::class)->available($manifest, $allowed),
            'ask_failure' => app(GuideAskService::class)->failure(match ($request->user()->type) { 'Teacher' => 'teacher', 'LunchStaff' => 'lunch', default => 'office' }),
            'ask_min_chars' => (int) config('guide_ask.min_chars'), 'ask_max_chars' => (int) config('guide_ask.max_chars')])->header('Cache-Control', 'private, no-store');
    }

    public function ask(Request $request, string $masjid_id, GuideAskService $ask, GuideAskLimits $limits)
    {
        $books = $this->allowed($request);
        abort_unless($books, 404);
        $manifest = $this->releases->current();
        abort_unless($ask->available($manifest, $books), 404);
        $request->validate(['question' => ['required', 'string', 'min:'.config('guide_ask.min_chars'), 'max:'.config('guide_ask.max_chars')]]);
        $reader = match ($request->user()->type) { 'Teacher' => 'teacher', 'LunchStaff' => 'lunch', default => 'office' };
        try {
            $limit = $limits->reserve($request->user()->id, app(TenantContext::class)->get());
            if ($limit) return response()->json(['message' => $limit === 'person' ? 'Too many questions just now. Try again in a minute.' : $ask->resting($reader)], 429)->header('Cache-Control', 'private, no-store');
            $result = $ask->answer($manifest, $books, $reader, $request->input('question'));
            if ($result['unknown']) GuideUnansweredQuestion::query()->insert([
                'question' => $request->input('question'), 'created_at' => now(),
                'books' => implode('+', $books), 'release_version' => $manifest['version'],
            ]);
            unset($result['usage']);
            return response()->json($result)->header('Cache-Control', 'private, no-store');
        } catch (Throwable) {
            // SDK/SQL exceptions may quote the question or answer. Never report them.
            return response()->json(['message' => $ask->failure($reader)], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function show(Request $request, string $masjid_id, string $book)
    {
        abort_unless(in_array($book, $this->allowed($request), true), 404);
        $manifest = $this->releases->current();
        $meta = $manifest['books'][$book] ?? null;
        abort_unless($meta, 404);
        $page = $this->releases->file($manifest['version'], $meta['page']);
        $style = $this->releases->file($manifest['version'], $meta['style']);
        abort_unless($page && $style, 404);
        $html = @file_get_contents($page);
        $css = @file_get_contents($style);
        abort_if($html === false || $css === false, 404);
        return response()->json(['status' => 'success', 'data' => [
            'version' => $manifest['version'], 'title' => $meta['title'], 'html' => $html,
            'css' => $css, 'tasks' => $meta['tasks'],
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function picture(Request $request, string $masjid_id, string $book, string $version, string $path)
    {
        abort_unless(in_array($book, $this->allowed($request), true), 404);
        $manifest = $this->releases->manifest($version);
        $files = $manifest['books'][$book]['files'] ?? [];
        // Resolve the exact manifest KEY, never a filesystem path supplied by the caller.
        $key = array_search($path, array_keys($files), true);
        abort_if($key === false, 404);
        $relative = array_keys($files)[$key];
        $file = $this->releases->file($version, $book.'/'.$relative);
        abort_unless($file, 404);
        $mime = match (pathinfo($relative, PATHINFO_EXTENSION)) { 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => null };
        abort_unless($mime, 404);
        // Read before headers are sent: a concurrent prune is a 404, never a streaming error.
        $bytes = @file_get_contents($file);
        abort_if($bytes === false, 404);
        $response = response($bytes, 200, ['Content-Type' => $mime, 'Content-Length' => strlen($bytes), 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-cache']);
        $response->setPrivate();
        $response->setEtag($files[$relative]['sha256']);
        $response->isNotModified($request);
        return $response;
    }
}
