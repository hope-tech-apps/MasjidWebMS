{{--
    "Your password was set" (2026-09-17), sent to a contact's login address
    after FamilyPasswordService::set() commits. See App\Mail\PasswordSetNoticeMail.

    NO LINK, NO BUTTON, NO TRACKING PIXEL, and nothing that opens anything: no
    password, no code, no token. PasswordSetNoticeTest renders this and fails if
    any of those appear. The plain-text part (password-set-notice-text) says the
    same thing; the one sentence that varies comes from the mailable.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your password was set</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">{{ $orgName }}</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">Your password was set</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 16px; font-size:16px; line-height:1.5;">{{ $greeting }}</p>
                            <p style="margin:0 0 16px; font-size:16px; line-height:1.55;">
                                The password for {{ $loginEmail }} at {{ $orgName }} was set on {{ $setAt }}.
                                If that was you, there is nothing else to do.
                            </p>
                            <p style="margin:0 0 20px; font-size:16px; line-height:1.55;">
                                {{ $ifItWasNotYou }}
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 32px 28px; font-size:13px; line-height:1.5; color:#7b8794;">
                            This email has no links on purpose. It is sent whenever a password is set
                            for this address at {{ $orgName }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
