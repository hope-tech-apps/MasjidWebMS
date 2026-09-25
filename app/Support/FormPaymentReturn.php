<?php

namespace App\Support;

use App\Models\FormResponse;
use App\Models\MasjidDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where Stripe sends a payer back to after a form's hosted Checkout
 * (DECISIONS.md 2026-09-11; festival brief, "Return URLs").
 *
 * Built from two things and nothing else:
 *
 *  - the request's Origin, only when it EXACTLY matches an entry in
 *    config('forms.payment_return_origins'), or is `https://<host>` of a
 *    `masjid_domains` row of THE FORM'S OWN organisation that is corsAdmitted()
 *    (Studio W1, S9; R3): active or manual, seen serving our own site, org not
 *    trashed. The organisation match is the point: Stripe sends a payer who has
 *    just paid to this origin, so one organisation's confirmed host must never
 *    become another's return address, and a host we have not seen serving our
 *    site (pending, reserved, failed, unconfirmed) never becomes anyone's. The
 *    env list fails closed: unset, it is empty, and with no confirmed row either
 *    no form can open a card payment. It is deliberately not
 *    config('cors.allowed_origins'), which defaults to ['*'], and there is no APP_URL
 *    fallback. JummahLunchOrdersController::returnUrlsFor() falls back to APP_URL,
 *    which suits a lunch page Manara serves itself and not a festival page on an
 *    organisation's own site.
 *  - a RELATIVE `return_path` (the renderer's route.path), matched against
 *    PATH_PATTERN: one leading slash, never two, then only unreserved characters and
 *    %XX triplets. A percent-encoded (Arabic) slug can take a card; a scheme, a host,
 *    a query, a fragment, a space or a backslash cannot get in.
 *
 * A client-supplied absolute URL is never used: the mobile donation path's habit of
 * passing the client's URLs to Stripe is not copied. The uuid rides in the query
 * string so the page can read the status back (GET /api/v1/form-responses/{uuid}).
 *
 * Pinned by tests/Feature/FormPaymentCheckoutTest.php.
 */
final class FormPaymentReturn
{
    /** The one refusal, whichever half was wrong; the log line says which. */
    public const REFUSED = "This page can't open a card payment. Reload it and try again.";

    /**
     * A path on the allowlisted site: "/", "/festival", "/ar/%D9%85…". At most 200
     * characters or triplets. `\z`, not `$`: a `$` also matches before a trailing
     * newline.
     */
    public const PATH_PATTERN = '#^/(?!/)(?:[A-Za-z0-9._~/-]|%[0-9A-Fa-f]{2}){0,200}\z#';

    /**
     * A bare origin: scheme, host, optional port, and nothing else. The shape
     * config/forms.php keeps when it reads FORMS_PAYMENT_RETURN_ORIGINS, asked again
     * here because config can be set other ways (a published override, a test), and a
     * wildcard or a path must never become the front of a Stripe return URL.
     */
    public const ORIGIN_PATTERN = '#^https?://[a-z0-9.-]+(:[0-9]{1,5})?\z#';

    /**
     * The origin and path a return URL is built on, or null.
     *
     * A refusal is logged at warning, the level production runs at: it is a payer who
     * cannot pay, and all the page can tell them is "reload".
     *
     * @param  array<string,mixed>  $context  ids for the log line
     */
    public static function base(Request $request, int $masjidId, array $context = []): ?string
    {
        $header = $request->headers->get('Origin');
        $origin = self::allowedOrigin($header, $masjidId);
        $path = $request->input('return_path');
        $pathOk = is_string($path) && preg_match(self::PATH_PATTERN, $path) === 1;

        if ($origin !== null && $pathOk) {
            return $origin . $path;
        }

        Log::warning('A card payment was refused a return address.', $context + [
            'reason' => $origin === null
                ? "origin not on forms.payment_return_origins or a confirmed domain of the form's organisation"
                : 'return_path is not a relative path',
            'origin' => is_string($header) ? mb_substr($header, 0, 200) : null,
            'allowlist_configured' => self::allowlist() !== [],
        ]);

        return null;
    }

    /**
     * The allowlist entry the header names exactly, else, given the form's
     * organisation, the header when it is `https://<host>` of one of that
     * organisation's corsAdmitted() rows; or null.
     *
     * The env list is asked first, so an origin it names never costs a query. The
     * table is matched on the stored (normalised) host, and only a bare lower-case
     * https origin is looked up at all: `http://`, a port, a path or upper case is
     * not how a browser on our site sends it, and must not match by normalising.
     * A database error refuses (logged at warning): this decides where a payer is
     * sent, so it fails closed, never open.
     */
    public static function allowedOrigin(mixed $header, ?int $masjidId = null): ?string
    {
        if (! is_string($header) || $header === '') {
            return null;
        }

        foreach (self::allowlist() as $allowed) {
            if ($header === $allowed) {
                return $allowed;
            }
        }

        if ($masjidId === null || preg_match('#^https://([a-z0-9.-]+)\z#', $header, $m) !== 1) {
            return null;
        }

        try {
            $admitted = MasjidDomain::query()
                ->corsAdmitted()
                ->where('masjid_id', $masjidId)
                ->where('host', $m[1])
                ->exists();
        } catch (Throwable $e) {
            Log::warning('A card payment return could not read masjid_domains; refusing.', [
                'masjid_id' => $masjidId,
                'exception' => $e::class,
            ]);

            return null;
        }

        return $admitted ? 'https://' . $m[1] : null;
    }

    /**
     * Stripe's two return URLs for $response, on a base base() has checked.
     *
     * @return array{success_url: string, cancel_url: string}
     */
    public static function urls(string $base, FormResponse $response): array
    {
        $query = http_build_query([
            'form' => (int) $response->form_id,
            'form_response' => (string) $response->uuid,
        ]);

        return [
            'success_url' => "{$base}?{$query}&paid=1",
            'cancel_url' => "{$base}?{$query}&cancelled=1",
        ];
    }

    /**
     * The configured origins that are bare origins. Anything else is dropped rather
     * than trusted: an exact match against '*' would otherwise let a request saying
     * `Origin: *` choose where Stripe sends the payer.
     *
     * @return array<int,string>
     */
    private static function allowlist(): array
    {
        return array_values(array_filter(
            (array) config('forms.payment_return_origins', []),
            static fn ($origin): bool => is_string($origin) && preg_match(self::ORIGIN_PATTERN, $origin) === 1
        ));
    }
}
