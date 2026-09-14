{{--
    The public account-deletion page (/account-deletion). See
    App\Http\Controllers\AccountDeletionController.

    One page, five states (start | code | removed | nothing | throttled), in one
    file for the same reason unsubscribe/status.blade.php is one file: the
    wording has to stay coherent across the outcomes.

    Rules this file keeps:

     - TENANT-NEUTRAL. No organisation's name, logo or colour frames the page. An
       organisation appears only as an option in the picker (the public app
       directory) or as the one the visitor chose. The one exception is the
       publisher line under the heading: Google Play asks the page to name the
       apps and developer exactly as the store listings do (config/member.php).
     - The `code` state depends ONLY on what the visitor typed. It must render
       byte-for-byte the same for an address with an account and one without;
       AccountDeletionPageTest compares the two.
     - Accessible without scripts: every control has a visible label, hints and
       errors are tied to their control with aria-describedby, an error summary
       leads the page and links to each field, and focus is always visible.
     - Self-contained. No SPA bundle and no external assets (SecurityHeaders' CSP
       allows inline styles), because this is opened from a store listing on a
       phone by somebody who may no longer have the app.
--}}
@php
    $state = $state ?? 'start';
    $organisations = $organisations ?? collect();
    $problems = $problems ?? new \Illuminate\Support\MessageBag();
    $old = $old ?? ['masjid_id' => '', 'email' => ''];
    $codeTtlMinutes = $codeTtlMinutes ?? 10;
    $erased = $erased ?? false;
    $publisher = (string) config('member.account_deletion.publisher', '');
    $apps = collect(config('member.account_deletion.apps', []))->filter()->values();
    $logRetentionDays = config('member.account_deletion.log_retention_days');

    // Play asks how long anything kept after deletion is retained. No number is
    // promised until config/member.php states one.
    $retention = 'Records the organisation keeps are held for as long as its own record-keeping requires; '
        . 'ask the organisation how long that is. A note that a deletion happened (the date, the '
        . 'organisation and an internal account number, never your email address) is kept in our server logs '
        . ($logRetentionDays !== null
            ? 'for up to ' . (int) $logRetentionDays . ' days.'
            : 'only as long as we need them to run and secure the service.')
        . ' If the organisation had also given you family-portal access, its access history keeps a note '
        . 'that the access ended, with the organisation\'s record.';

    $title = [
        'start' => 'Delete your app account',
        'code' => 'Enter the code we emailed you',
        'removed' => 'Your account has been deleted',
        'nothing' => 'There was no account to delete',
        'throttled' => 'Too many attempts',
    ][$state] ?? 'Delete your app account';

    // aria-describedby for a control: its hint, then its error when it has one.
    $describedBy = function (string $field, ?string $hint = null) use ($problems): string {
        return trim(($hint ? $field . '-hint ' : '') . ($problems->has($field) ? $field . '-error' : ''));
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $problems->isNotEmpty() ? 'Error: ' : '' }}{{ $title }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 24px 16px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 16px;
            line-height: 1.55;
            background: #f6f7f9;
            color: #202124;
        }
        .card {
            background: #fff;
            max-width: 560px;
            width: 100%;
            border-radius: 14px;
            padding: 32px 28px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08), 0 8px 24px rgba(0,0,0,.06);
        }
        h1 { font-size: 24px; line-height: 1.3; margin: 0 0 16px; font-weight: 650; }
        h2 { font-size: 18px; margin: 24px 0 8px; font-weight: 650; }
        p { margin: 0 0 12px; color: #3c4043; }
        ul { margin: 0 0 12px; padding-left: 22px; color: #3c4043; }
        li { margin-bottom: 6px; }
        a { color: #1a5fb4; text-underline-offset: 2px; }
        .org, .address { font-weight: 600; color: #202124; }
        .address { word-break: break-all; }
        .problems {
            border: 2px solid #b3261e;
            border-radius: 10px;
            padding: 12px 16px;
            margin: 0 0 20px;
        }
        .problems h2 { margin: 0 0 6px; font-size: 17px; color: #b3261e; }
        .problems ul { margin: 0; }
        .problems a { color: #b3261e; font-weight: 600; }
        form { margin: 20px 0 0; }
        .field { margin: 0 0 20px; }
        label { display: block; font-weight: 600; margin: 0 0 4px; color: #202124; }
        .hint { font-size: 14px; color: #5f6368; margin: 0 0 6px; }
        .error { font-size: 15px; color: #b3261e; font-weight: 600; margin: 0 0 6px; }
        input[type="email"], input[type="text"], select {
            display: block;
            width: 100%;
            font: inherit;
            padding: 12px;
            border: 2px solid #80868b;
            border-radius: 8px;
            background: #fff;
            color: #202124;
            min-height: 48px;
        }
        [aria-invalid="true"] { border-color: #b3261e; }
        .check { display: flex; gap: 12px; align-items: flex-start; }
        .check input { width: 24px; height: 24px; margin: 2px 0 0; flex: none; }
        .check label { font-weight: 500; }
        button {
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            min-height: 48px;
            padding: 12px 24px;
            border-radius: 8px;
            border: 2px solid transparent;
        }
        .primary { background: #202124; color: #fff; }
        .danger { background: #b3261e; color: #fff; }
        :focus-visible { outline: 3px solid #1a5fb4; outline-offset: 2px; }
        .fineprint { margin-top: 24px; padding-top: 16px; border-top: 1px solid #e8eaed; font-size: 14px; color: #5f6368; }
        @media (prefers-color-scheme: dark) {
            body { background: #17181a; color: #e8eaed; }
            .card { background: #202124; box-shadow: none; }
            p, ul, .hint, .fineprint { color: #bdc1c6; }
            h1, h2, label, .org, .address { color: #e8eaed; }
            a { color: #8ab4f8; }
            .problems { border-color: #f2b8b5; }
            .problems h2, .problems a, .error { color: #f2b8b5; }
            input[type="email"], input[type="text"], select { background: #17181a; color: #e8eaed; border-color: #9aa0a6; }
            [aria-invalid="true"] { border-color: #f2b8b5; }
            .primary { background: #e8eaed; color: #17181a; }
            .danger { background: #f2b8b5; color: #370b1e; }
            :focus-visible { outline-color: #8ab4f8; }
            .fineprint { border-top-color: #3c4043; }
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>{{ $title }}</h1>

        @if ($publisher !== '' || $apps->isNotEmpty())
            <p class="hint publisher">
                For accounts in the
                @if ($apps->isNotEmpty())
                    {{ $apps->count() > 1 ? $apps->slice(0, -1)->implode(', ') . ' and ' . $apps->last() : $apps->first() }}
                    apps
                @else
                    apps
                @endif
                @if ($publisher !== '')
                    published by {{ $publisher }}
                @endif
                on Google Play and the App Store.
            </p>
        @endif

        @if ($problems->isNotEmpty())
            <div class="problems" role="alert" aria-labelledby="problems-title">
                <h2 id="problems-title">There is a problem</h2>
                <ul>
                    @foreach ($problems->keys() as $field)
                        <li><a href="#{{ $field }}">{{ $problems->first($field) }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($state === 'start')
            <p>
                Use this page to delete the account you sign in to your masjid's, school's or
                organisation's app with. You can also do it in the app: open the menu while you are
                signed in and choose Delete account.
            </p>

            <h2>What deleting your account does</h2>
            <ul>
                <li>Your sign-in is removed, and you are signed out on every phone.</li>
                <li>
                    Your phone stops receiving notifications meant for your account, and your
                    notification choices are deleted. Announcements sent to everyone who has the app
                    still arrive while the app is installed.
                </li>
                <li>
                    If you created your account in the app and the organisation has no other records
                    about you, your contact details are deleted too.
                </li>
                <li>
                    Records the organisation keeps for its own work, such as gifts and receipts,
                    registrations, form responses, or class and family records, are not deleted
                    here. Contact the organisation to ask about those.
                </li>
                <li>You can create a new account later with the same email address.</li>
            </ul>

            <h2>What is kept, and for how long</h2>
            <p>{{ $retention }}</p>

            <form method="POST" action="{{ route('account-deletion.request') }}" novalidate>
                @csrf

                <div class="field">
                    <label for="masjid_id">Organisation</label>
                    <p class="hint" id="masjid_id-hint">The masjid, school or organisation whose app you signed in to.</p>
                    @if ($problems->has('masjid_id'))
                        <p class="error" id="masjid_id-error">{{ $problems->first('masjid_id') }}</p>
                    @endif
                    <select id="masjid_id" name="masjid_id" required
                            aria-describedby="{{ $describedBy('masjid_id', 'hint') }}"
                            @if ($problems->has('masjid_id')) aria-invalid="true" @endif>
                        <option value="">Choose an organisation</option>
                        @foreach ($organisations as $organisation)
                            <option value="{{ $organisation->id }}" @selected((string) $old['masjid_id'] === (string) $organisation->id)>{{ $organisation->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <p class="hint" id="email-hint">The address you sign in to the app with. We will email a code to it.</p>
                    @if ($problems->has('email'))
                        <p class="error" id="email-error">{{ $problems->first('email') }}</p>
                    @endif
                    <input id="email" name="email" type="email" autocomplete="email" inputmode="email"
                           spellcheck="false" required value="{{ $old['email'] }}"
                           aria-describedby="{{ $describedBy('email', 'hint') }}"
                           @if ($problems->has('email')) aria-invalid="true" @endif>
                </div>

                <button type="submit" class="primary">Email me a code</button>
            </form>

        @elseif ($state === 'code')
            <p>
                We have emailed a code to <span class="address">{{ $email }}</span> for
                <span class="org">{{ $org->name }}</span>. It expires in {{ $codeTtlMinutes }} minutes
                and can be used once.
            </p>
            <p>
                If nothing arrives within a few minutes, check your spam folder, or
                <a href="{{ route('account-deletion.show') }}">start again</a> with a different address.
            </p>

            <form method="POST" action="{{ route('account-deletion.confirm') }}" novalidate>
                @csrf
                <input type="hidden" name="masjid_id" value="{{ $org->id }}">
                <input type="hidden" name="email" value="{{ $email }}">

                <div class="field">
                    <label for="code">Code from the email</label>
                    @if ($problems->has('code'))
                        <p class="error" id="code-error">{{ $problems->first('code') }}</p>
                    @endif
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                           maxlength="20" required
                           @if ($problems->has('code')) aria-invalid="true" aria-describedby="code-error" @endif>
                </div>

                <div class="field">
                    @if ($problems->has('confirm'))
                        <p class="error" id="confirm-error">{{ $problems->first('confirm') }}</p>
                    @endif
                    <div class="check">
                        <input id="confirm" name="confirm" type="checkbox" value="1" required
                               @if ($problems->has('confirm')) aria-invalid="true" aria-describedby="confirm-error" @endif>
                        <label for="confirm">
                            I understand that my account for {{ $org->name }} will be deleted and I will be
                            signed out on every phone.
                        </label>
                    </div>
                </div>

                <button type="submit" class="danger">Delete my account</button>
            </form>

        @elseif ($state === 'removed')
            <p>
                The account for <span class="address">{{ $email }}</span> in the
                <span class="org">{{ $org->name }}</span> app has been deleted, and you have been signed
                out on every phone.
            </p>
            @if ($erased)
                <p>Your contact details have been deleted as well.</p>
            @else
                <p>
                    {{ $org->name }} keeps its own records about you, such as gifts, registrations or
                    class records, so those were not deleted. Contact {{ $org->name }} if you would like
                    to ask about them.
                </p>
            @endif
            <p>{{ $retention }}</p>
            <p>You can create a new account in the app later if you want to.</p>

        @elseif ($state === 'nothing')
            <p>
                There is no app account for <span class="address">{{ $email }}</span> at
                <span class="org">{{ $org->name }}</span>, so nothing was deleted.
            </p>
            <p>
                If you sign in to the app with a different address, or to another organisation's app,
                <a href="{{ route('account-deletion.show') }}">start again</a>.
            </p>

        @else
            <p>
                There have been too many attempts for this address or from this connection.
                Nothing was deleted.
                Please wait an hour, then <a href="{{ route('account-deletion.show') }}">start again</a>.
            </p>
        @endif

        <p class="fineprint">
            Deleting your account does not stop emails you asked for, such as receipts. To stop
            announcement emails, use the unsubscribe link at the bottom of any of them.
        </p>
    </main>
</body>
</html>
