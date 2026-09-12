{{--
    The public unsubscribe landing (T-042c).

    One page, five states, for the same reason connect/onboarding-status.blade.php
    is one page: the wording of the five outcomes has to stay coherent, and it
    only does that while it sits in one file. $state is one of
    confirm|already|done|resubscribed|invalid.

    Self-contained on purpose — no SPA bundle and no external assets (SecurityHeaders'
    CSP allows inline styles but no unexpected origins), because this page is opened
    from a mail client on a phone by somebody who may have no account here at all.

    Two rules this file must keep:

     - The INVALID state names no organisation and no address. Everything that can
       go wrong funnels into it, so a probe cannot use this page to learn whether an
       address or an organisation exists.
     - Nothing on this page is a link that acts. Every action is a POST form, because
       mail scanners follow links and a GET that unsubscribed would opt people out
       who never clicked.
--}}
@php
    $copy = [
        'confirm' => [
            'tone' => '#f4b400',
            'title' => 'Stop announcement emails?',
        ],
        'already' => [
            'tone' => '#5f6368',
            'title' => 'You are already unsubscribed',
        ],
        'done' => [
            'tone' => '#0f9d58',
            'title' => 'You have been unsubscribed',
        ],
        'resubscribed' => [
            'tone' => '#0f9d58',
            'title' => 'You are back on the list',
        ],
        'invalid' => [
            'tone' => '#9aa0a6',
            'title' => 'This link is no longer valid',
        ],
    ][$state] ?? [
        'tone' => '#9aa0a6',
        'title' => 'This link is no longer valid',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $copy['title'] }}</title>
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
        .rule { height: 4px; width: 48px; margin: 0 auto 24px; border-radius: 2px; background: {{ $copy['tone'] }}; }
        h1 { font-size: 20px; margin: 0 0 12px; font-weight: 600; }
        p { font-size: 15px; line-height: 1.6; margin: 0 0 12px; color: #5f6368; }
        .org { font-weight: 600; color: #202124; }
        .address { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 14px; color: #202124; word-break: break-all; }
        form { margin: 24px 0 0; }
        button {
            font: inherit;
            cursor: pointer;
            padding: 12px 24px;
            border-radius: 8px;
            border: 1px solid transparent;
            font-size: 15px;
            font-weight: 600;
        }
        .primary { background: #202124; color: #fff; }
        .quiet { background: transparent; color: #5f6368; border-color: #dadce0; font-weight: 500; font-size: 14px; padding: 10px 18px; }
        .fineprint { margin-top: 24px; padding-top: 20px; border-top: 1px solid #e8eaed; font-size: 12.5px; line-height: 1.6; color: #80868b; }
        @media (prefers-color-scheme: dark) {
            body { background: #17181a; color: #e8eaed; }
            .card { background: #202124; box-shadow: none; }
            h1, .org, .address { color: #e8eaed; }
            p { color: #9aa0a6; }
            .fineprint { color: #9aa0a6; border-top-color: #3c4043; }
            .primary { background: #8ab4f8; color: #17181a; }
            .quiet { color: #9aa0a6; border-color: #3c4043; }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="rule" aria-hidden="true"></div>

        <h1>{{ $copy['title'] }}</h1>

        @if ($state === 'invalid')
            {{-- Names nothing. See the note at the top of this file. --}}
            <p>
                This unsubscribe link cannot be used. It may have been changed on its way
                here, or it may be from an email that is no longer supported.
            </p>
            <p>
                To stop receiving emails, reply to the message you received and ask to be
                removed &mdash; the organization that sent it can do that for you.
            </p>
        @else
            @if ($state === 'confirm')
                <p>
                    You are about to stop receiving announcement emails from
                    <span class="org">{{ $orgName }}</span> at:
                </p>
                <p class="address">{{ $address }}</p>

                <form method="POST" action="{{ route('unsubscribe.store', ['masjid_id' => $masjidId, 'token' => $token]) }}">
                    <button type="submit" class="primary">Unsubscribe me</button>
                </form>

            @elseif ($state === 'already')
                <p>
                    <span class="address">{{ $address }}</span> already receives no
                    announcement emails from <span class="org">{{ $orgName }}</span>.
                    Nothing changed just now.
                </p>

                <form method="POST" action="{{ route('unsubscribe.resubscribe', ['masjid_id' => $masjidId, 'token' => $resubscribeToken]) }}">
                    <button type="submit" class="quiet">Start receiving them again</button>
                </form>

            @elseif ($state === 'done')
                <p>
                    <span class="org">{{ $orgName }}</span> will no longer send announcement
                    emails to <span class="address">{{ $address }}</span>. This took effect
                    immediately &mdash; there is nothing else you need to do.
                </p>

                <form method="POST" action="{{ route('unsubscribe.resubscribe', ['masjid_id' => $masjidId, 'token' => $resubscribeToken]) }}">
                    <button type="submit" class="quiet">Changed your mind? Start them again</button>
                </form>

            @elseif ($state === 'resubscribed')
                <p>
                    <span class="address">{{ $address }}</span> will receive announcement
                    emails from <span class="org">{{ $orgName }}</span> again. You can
                    unsubscribe any time from the link at the bottom of any of them.
                </p>
            @endif

            {{--
                This paragraph is why a donor does not report a missing tax receipt as a
                bug three weeks from now. It is shown on every valid state, including
                the confirmation, because the moment to say it is BEFORE the click.
            --}}
            <p class="fineprint">
                This covers announcements and general emails only. Receipts, registration
                confirmations, replies to messages you send, and sign-in codes are not
                affected &mdash; you will still receive anything you ask for. This also
                applies only to {{ $orgName }}; other organizations you hear from are
                unchanged.
            </p>
        @endif
    </main>
</body>
</html>
