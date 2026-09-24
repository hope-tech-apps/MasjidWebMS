{{--
    The office's "Send portal invite" (2026-09-24).

    Written for a parent, not for an administrator. No jargon: no "credential",
    no "token", no "sign-in enabled", no "guardian record". It says who it is
    from, what the portal is for, how long the link lasts and what to do when it
    stops working.

    It carries NOTHING about a child — no name, no class, no marks, no message.
    An inbox forwards, previews on a lock screen and sits in a shared household
    account; the school's disclosure rules live inside the portal, where consent
    and identity are checked. See App\Mail\FamilyPortalInviteMail.

    English only. There is no Arabic family email template anywhere in this
    application yet (resources/views/emails/ carries none), and inventing a
    second language here — with no way for the office to say which one a family
    reads — would be a guess printed in somebody's inbox. The portal itself has
    a language picker on its first screen, which is where the choice belongs.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your {{ $orgName }} parent portal</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                <tr>
                    <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                        <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">{{ $orgName }}</div>
                        <div style="font-size:22px; font-weight:700; margin-top:4px;">Your parent portal is ready</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 32px 8px;">
                        <p style="margin:0 0 16px; font-size:16px; line-height:1.5;">{{ $greeting }}</p>

                        <p style="margin:0 0 16px; font-size:16px; line-height:1.55;">
                            {{ $orgName }} has set up a parent portal for you. It is where you can
                            see updates from your child's class, read what the teacher has shared,
                            and message the teacher back.
                        </p>

                        <p style="margin:0 0 24px; font-size:16px; line-height:1.55;">
                            Use the button below to open it. You will not need a password.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                            <tr>
                                <td style="background:#286c56; border-radius:8px;">
                                    <a href="{{ $url }}"
                                       style="display:inline-block; padding:13px 26px; font-size:16px; font-weight:600; color:#ffffff; text-decoration:none;">
                                        Open my parent portal
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px; font-size:15px; line-height:1.55;">
                            This link works for <strong>{{ $expiresIn }}</strong>, and it can be used once.
                            If it has already run out, open the portal and ask for a sign-in code to be
                            emailed to you — or reply to this email and {{ $orgName }} will send a new link.
                        </p>

                        <p style="margin:0 0 20px; font-size:14px; line-height:1.55; color:#52606d;">
                            If the button does not work, copy this address into your browser:<br>
                            <span style="word-break:break-all;">{{ $url }}</span>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:0 32px 28px; font-size:13px; line-height:1.5; color:#7b8794;">
                        {{-- No "this wasn't me" one-click link, on purpose and for the reason
                             family-login-code.blade.php gives: an action reachable from an inbox
                             is a way for whoever holds the inbox to change an account. Reporting
                             goes through the office, who can end the access. --}}
                        This email was sent to you because {{ $orgName }} has you on file as a
                        parent or guardian. If you were not expecting it, you can ignore it —
                        nobody else can use the link. If it keeps happening, contact {{ $orgName }}.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
