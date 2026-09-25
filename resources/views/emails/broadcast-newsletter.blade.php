{{--
    The newsletter layout of a broadcast email (BroadcastMail with blocks).

    The frame around the blocks: header band, the composer image if one was
    attached, greeting, body, the blocks (NewsletterRenderer, already escaped),
    the "More details" link, and the unsubscribe footer — the footer's wording
    and link are the ones emails.broadcast sends, unchanged (T-042c).

    Built for mail clients rather than browsers: a 600px table card, inline
    styles on every element, colours declared on every cell. The <style> block
    below is an ENHANCEMENT only — dark mode for clients that honour
    prefers-color-scheme (Apple Mail, iOS) and Outlook.com's [data-ogsc] hooks,
    and stacking the two-image row on a phone. A client that strips it (Gmail in
    some contexts) still gets a complete, legible email from the inline styles.
    The MSO conditionals hold Outlook for Windows, which ignores max-width, to
    the card's width.

    Nothing here or in the blocks is admin-supplied markup: the blocks arrive as
    HTML the renderer wrote itself from escaped values and re-sanitised text.
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        @media (prefers-color-scheme: dark) {
            .nl-bg { background-color: #0f1215 !important; }
            .nl-card { background-color: #1b1f24 !important; }
            .nl-text { color: #e6e9ec !important; }
            .nl-muted { color: #a3acb5 !important; }
            .nl-link { color: #7bd9a3 !important; }
            .nl-rule { border-top-color: #3a424b !important; }
        }
        [data-ogsc] .nl-text { color: #e6e9ec !important; }
        [data-ogsc] .nl-muted { color: #a3acb5 !important; }
        [data-ogsc] .nl-link { color: #7bd9a3 !important; }
        [data-ogsb] .nl-bg { background-color: #0f1215 !important; }
        [data-ogsb] .nl-card { background-color: #1b1f24 !important; }
        @media only screen and (max-width: 620px) {
            .nl-pad { padding-left: 16px !important; padding-right: 16px !important; }
            .nl-col { display: block !important; width: 100% !important; max-width: 100% !important; }
            .nl-col img { width: 100% !important; max-width: 100% !important; }
            .nl-col-first { padding-bottom: 16px !important; }
            .nl-gap { display: none !important; }
        }
    </style>
</head>
<body class="nl-bg" style="margin:0; padding:0; background-color:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" class="nl-bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f8;">
        <tr>
            <td align="center" class="nl-bg" style="padding:24px 0; background-color:#f4f6f8;">
                <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
                <table role="presentation" class="nl-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td class="nl-pad" bgcolor="#1f7a41" style="background-color:#1f7a41; padding:24px; color:#ffffff;">
                            <div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; font-size:13px; letter-spacing:.06em; text-transform:uppercase; color:#ffffff;">{{ $orgName }}</div>
                            <h1 style="margin:4px 0 0; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; font-size:24px; line-height:1.3; font-weight:700; color:#ffffff;">{{ $title }}</h1>
                        </td>
                    </tr>

                    @if ($imageUrl)
                        <tr>
                            <td class="nl-card" style="padding:0; background-color:#ffffff;">
                                <img src="{{ $imageUrl }}" alt="{{ $title }}" width="600" style="display:block; width:100%; max-width:600px; height:auto; border:0;">
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td class="nl-card nl-pad" style="padding:24px 24px 4px; background-color:#ffffff;">
                            <p class="nl-text" style="margin:0 0 16px; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:1.6; color:#1f2933;">{{ $greeting }}</p>
                            <p class="nl-text" style="margin:0 0 16px; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:1.6; color:#1f2933; white-space:pre-line;">{{ $body }}</p>
                        </td>
                    </tr>

{!! $blocksHtml !!}

                    @if ($link)
                        <tr>
                            <td class="nl-card nl-pad" style="padding:4px 24px 24px; background-color:#ffffff;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">
                                    <tr>
                                        <td bgcolor="#1f7a41" style="background-color:#1f7a41; border-radius:8px;">
                                            <a href="{{ $link }}" target="_blank" style="display:inline-block; padding:12px 24px; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; font-size:16px; font-weight:600; line-height:1.2; color:#ffffff; text-decoration:none; border-radius:8px;">More details</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td class="nl-card nl-pad" style="padding:16px 24px 28px; background-color:#ffffff;">
                            <p class="nl-muted" style="margin:0; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:1.5; color:#52606d;">
                                You are receiving this because you are on {{ $orgName }}'s contact list.
                                @if (! empty($unsubscribeUrl))
                                    <br>
                                    <a href="{{ $unsubscribeUrl }}" class="nl-link" style="color:#1f7a41; text-decoration:underline;">Unsubscribe from {{ $orgName }}'s emails</a>
                                    <br>
                                    This does not affect receipts, registration confirmations, or replies to messages you send.
                                @endif
                            </p>
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
