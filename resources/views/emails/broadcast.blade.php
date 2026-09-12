<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">{{ $orgName }}</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $title }}</div>
                        </td>
                    </tr>

                    @if ($imageUrl)
                        <tr>
                            <td style="padding:0;">
                                <img src="{{ $imageUrl }}" alt="" style="display:block; width:100%; max-width:560px; height:auto;">
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 16px; font-size:16px; line-height:1.5;">{{ $greeting }}</p>
                            <p style="margin:0 0 20px; font-size:16px; line-height:1.55; white-space:pre-line;">{{ $body }}</p>

                            @if ($link)
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;">
                                    <tr>
                                        <td style="background:#2f9e57; border-radius:8px;">
                                            <a href="{{ $link }}" style="display:inline-block; padding:12px 22px; color:#ffffff; font-size:15px; font-weight:600; text-decoration:none;">More details</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        {{--
                            The unsubscribe footer (T-042c).

                            The old footer was a statement — "you are receiving this
                            because…" — with no mechanism attached, which is not an
                            opt-out under CAN-SPAM and does not satisfy the Gmail/Yahoo
                            bulk-sender rules either.

                            The last sentence is not decoration. Without it a donor
                            unsubscribes from announcements, never receives their tax
                            receipt, and reports it as a bug — so the email says, at the
                            moment of the decision, exactly what this does and does not
                            stop. $unsubscribeUrl is nullable so an older caller renders
                            the original footer rather than an empty link.
                        --}}
                        <td style="padding:0 32px 28px; font-size:13px; line-height:1.5; color:#7b8794;">
                            You are receiving this because you are on {{ $orgName }}'s contact list.

                            @if (! empty($unsubscribeUrl))
                                <br>
                                <a href="{{ $unsubscribeUrl }}" style="color:#2f9e57; text-decoration:underline;">Unsubscribe from {{ $orgName }}'s emails</a>
                                <br>
                                <span style="color:#9aa5b1;">This does not affect receipts, registration confirmations, or replies to messages you send.</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
