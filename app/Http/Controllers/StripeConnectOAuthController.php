<?php

namespace App\Http\Controllers;

use App\Models\Masjid;
use App\Models\User;
use App\Services\Stripe\StripeConnectOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StripeConnectOAuthController extends Controller
{
    public function __construct(private StripeConnectOAuthService $oauth) {}

    public function start(Request $request, string $masjid_id)
    {
        if (! StripeConnectOAuthService::enabled()) return response()->api(404, 'Not found.');
        try {
            return response()->api(200, '', ['authorize_url' => $this->oauth->authorize(Masjid::findOrFail($masjid_id), $request->user())]);
        } catch (\DomainException $e) {
            return response()->api(422, $e->getMessage());
        }
    }

    public function unlink(Request $request, string $masjid_id)
    {
        if (! StripeConnectOAuthService::enabled()) return response()->api(404, 'Not found.');
        try {
            $this->oauth->unlink(Masjid::findOrFail($masjid_id), $request->user());
            return response()->api(200, '', ['unlinked' => true]);
        } catch (\DomainException $e) {
            return response()->api(422, $e->getMessage());
        }
    }

    public function complete(Request $request)
    {
        if (! StripeConnectOAuthService::enabled()) return response('Not found.', 404);
        $message = 'Stripe account linked. Return to Online payments and refresh the status.';
        $status = 200;
        try {
            $sessionUser = Auth::guard('web')->user();
            if ($sessionUser !== null && ! $sessionUser instanceof User) {
                throw new \DomainException('Sign in with your staff account and start again from Online payments.');
            }
            $value = $request->query('state');
            $state = $this->oauth->consume(is_string($value) ? $value : '', $sessionUser);
            if ($request->query('error')) throw new \DomainException('Stripe access was not granted. Nothing was linked. You can start again from Online payments.');
            $code = $request->query('code');
            if (! is_string($code) || $code === '') throw new \DomainException('Stripe did not return an authorisation code. Start again from Online payments.');
            $this->oauth->link($state, $code);
        } catch (\DomainException $e) {
            $message = $e->getMessage();
            $status = 422;
        } catch (\Throwable) {
            \Illuminate\Support\Facades\Log::warning('Stripe OAuth callback could not complete.');
            $message = 'The Stripe change could not be completed. Return to Online payments and refresh the status before trying again.';
            $status = 422;
        }
        return response()->view('connect.oauth-status', ['message' => $message, 'portalUrl' => config('app.url')], $status)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
