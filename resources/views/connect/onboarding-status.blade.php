{{--
    Public Stripe Connect onboarding landing.

    Rendered to an org admin's browser after Stripe's hosted onboarding, so it is
    intentionally self-contained: no SPA bundle, no external assets (the CSP in
    SecurityHeaders allows inline styles but no unexpected origins), and no
    account identifiers. $state is one of connected|pending|expired|unknown.
--}}
@php
    $copy = [
        'connected' => [
            'icon' => '&check;',
            'tone' => '#0f9d58',
            'title' => 'You are connected to Stripe',
            'body' => 'Your account is set up and can now receive donations. Payments go directly to your organization&rsquo;s own Stripe account.',
        ],
        'pending' => [
            'icon' => '&hellip;',
            'tone' => '#f4b400',
            'title' => 'Almost there &mdash; Stripe is still reviewing',
            'body' => 'Your details were received. Stripe sometimes needs a little longer, or may still be missing something such as a bank account or business details. You&rsquo;ll be able to accept donations as soon as Stripe finishes.',
        ],
        'expired' => [
            'icon' => '&#8635;',
            'tone' => '#f4b400',
            'title' => 'This onboarding link expired',
            'body' => 'Stripe onboarding links are single-use and short-lived. Ask your administrator to send you a new link from the admin portal, then pick up where you left off &mdash; nothing you already entered was lost.',
        ],
        'unknown' => [
            'icon' => '?',
            'tone' => '#9aa0a6',
            'title' => 'We couldn&rsquo;t find that organization',
            'body' => 'This link doesn&rsquo;t match an organization we have on file. Please ask your administrator for a new onboarding link.',
        ],
    ][$state] ?? [
        'icon' => '?',
        'tone' => '#9aa0a6',
        'title' => 'Onboarding status',
        'body' => 'Please ask your administrator for an up-to-date onboarding link.',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- $copy titles carry HTML entities for the body; decode them so the tab
         title reads "Almost there — …" rather than a literal "&mdash;". --}}
    <title>{{ html_entity_decode(strip_tags($copy['title']), ENT_QUOTES | ENT_HTML5) }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f6f7f9;
            color: #202124;
        }
        .card {
            background: #fff;
            max-width: 480px;
            width: 100%;
            border-radius: 14px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,.08), 0 8px 24px rgba(0,0,0,.06);
        }
        .badge {
            width: 56px; height: 56px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; line-height: 1;
            color: #fff;
            background: {{ $copy['tone'] }};
        }
        h1 { font-size: 20px; margin: 0 0 12px; font-weight: 600; }
        p { font-size: 15px; line-height: 1.6; margin: 0 0 12px; color: #5f6368; }
        .org { font-weight: 600; color: #202124; }
        .status-row {
            margin-top: 24px; padding-top: 20px;
            border-top: 1px solid #e8eaed;
            display: flex; justify-content: space-between;
            font-size: 14px; color: #5f6368;
        }
        .status-row span:last-child { font-weight: 600; color: #202124; }
        .btn {
            display: inline-block; margin-top: 24px;
            padding: 11px 22px; border-radius: 8px;
            background: #202124; color: #fff;
            text-decoration: none; font-size: 14px; font-weight: 500;
        }
        .fineprint { margin-top: 20px; font-size: 12.5px; color: #80868b; }
        @media (prefers-color-scheme: dark) {
            body { background: #17181a; color: #e8eaed; }
            .card { background: #202124; box-shadow: none; }
            h1, .org, .status-row span:last-child { color: #e8eaed; }
            p, .status-row { color: #9aa0a6; }
            .status-row { border-top-color: #3c4043; }
            .btn { background: #8ab4f8; color: #17181a; }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="badge" aria-hidden="true">{!! $copy['icon'] !!}</div>

        <h1>{!! $copy['title'] !!}</h1>

        @if ($masjidName)
            <p class="org">{{ $masjidName }}</p>
        @endif

        <p>{!! $copy['body'] !!}</p>

        @if (in_array($state, ['connected', 'pending'], true))
            <div class="status-row">
                <span>Accepting donations</span>
                <span>{{ $state === 'connected' ? 'Yes' : 'Not yet' }}</span>
            </div>
            <div class="status-row" style="border-top: 0; padding-top: 8px;">
                <span>Payouts to your bank</span>
                <span>{{ $payoutsEnabled ? 'Enabled' : 'Pending' }}</span>
            </div>
        @endif

        <a class="btn" href="{{ $portalUrl }}">Go to the admin portal</a>

        <p class="fineprint">
            You can close this window. Your organization&rsquo;s Stripe account is
            managed by you directly &mdash; refunds, payouts, and disputes all live
            in your own Stripe dashboard.
        </p>
    </main>
</body>
</html>
