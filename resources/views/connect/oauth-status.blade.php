{{-- Self-contained like connect.onboarding-status: no SPA bundle or external assets. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Stripe connection</title>
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
            background: {{ $linked ? '#0f9d58' : '#f4b400' }};
        }
        h1 { font-size: 20px; margin: 0 0 12px; font-weight: 600; }
        p { font-size: 15px; line-height: 1.6; margin: 0 0 12px; color: #5f6368; }
        .btn {
            display: inline-block; margin-top: 24px;
            padding: 11px 22px; border-radius: 8px;
            background: #202124; color: #fff;
            text-decoration: none; font-size: 14px; font-weight: 500;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #17181a; color: #e8eaed; }
            .card { background: #202124; box-shadow: none; }
            h1 { color: #e8eaed; }
            p { color: #9aa0a6; }
            .btn { background: #8ab4f8; color: #17181a; }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="badge" aria-hidden="true">{{ $linked ? '✓' : '!' }}</div>
        <h1>Stripe connection</h1>
        <p>{{ $message }}</p>
        <a class="btn" href="{{ $portalUrl }}">Return to Online payments</a>
    </main>
</body>
</html>
