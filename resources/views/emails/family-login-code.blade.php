{{--
    The parent/guardian sign-in code (T-015d), and the app member's codes: the
    same code to sign in, or (`$forAccountDeletion`) to confirm deleting an app
    account from the public /account-deletion page.

    Deliberately plain. There is no link, no button and no tracking pixel: the
    only thing this mail asks the reader to do is type six digits back into an
    app or page they already opened, so a click target would be a phishing
    pattern to train families into rather than a convenience.

    The deletion wording must stay true whether or not the address has an
    account: the page mails a code to every address it is given.
--}}
@php
    $forAccountDeletion = $forAccountDeletion ?? false;
    $heading = $forAccountDeletion ? 'Your account deletion code' : 'Your sign-in code';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">{{ $orgName }}</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $heading }}</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 16px; font-size:16px; line-height:1.5;">{{ $greeting }}</p>
                            <p style="margin:0 0 20px; font-size:16px; line-height:1.55;">
                                @if ($forAccountDeletion)
                                    Someone asked to delete the {{ $orgName }} app account for this email
                                    address. If that was you, enter this code on the account deletion page.
                                @else
                                    Enter this code to sign in to the {{ $orgName }} family portal.
                                @endif
                            </p>

                            <div style="margin:0 0 20px; padding:16px 0; text-align:center; background:#f4f6f8; border-radius:10px; font-size:30px; font-weight:700; letter-spacing:.28em;">
                                {{ $code }}
                            </div>

                            <p style="margin:0 0 20px; font-size:15px; line-height:1.55;">
                                It expires in {{ $expiresInMinutes }} minutes and can be used once.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 32px 28px; font-size:13px; line-height:1.5; color:#7b8794;">
                            {{-- No "click here if this wasn't you" link, on purpose: a
                                 one-click action reachable from an inbox is a way for
                                 whoever holds the inbox to change an account. Reporting
                                 goes through the office, who can revoke the login. --}}
                            @if ($forAccountDeletion)
                                If you did not ask for this, ignore this message. Nothing is deleted
                                without the code. If it keeps happening, contact {{ $orgName }}.
                            @else
                                If you did not ask to sign in, you can ignore this message — nobody
                                can use the code without it. If it keeps happening, contact
                                {{ $orgName }}.
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
