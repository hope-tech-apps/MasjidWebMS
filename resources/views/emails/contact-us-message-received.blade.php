<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New contact message</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">New contact message</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $reason }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 20px; font-size:16px; line-height:1.5;">
                                {{ $senderName }} sent {{ $masjidName }} a message through {{ $source }}.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; font-size:14px;">
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">From</td>
                                    <td style="padding:12px 16px; text-align:right; font-weight:600;">{{ $senderName }}</td>
                                </tr>
                                @if ($senderEmail)
                                    <tr style="background:#fafbfc;">
                                        <td style="padding:12px 16px; color:#7b8794;">Email</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <a href="mailto:{{ $senderEmail }}" style="color:#2f9e57; text-decoration:none;">{{ $senderEmail }}</a>
                                        </td>
                                    </tr>
                                @endif
                                @if ($senderPhone)
                                    <tr>
                                        <td style="padding:12px 16px; color:#7b8794;">Phone</td>
                                        <td style="padding:12px 16px; text-align:right;">{{ $senderPhone }}</td>
                                    </tr>
                                @endif
                                <tr style="background:#fafbfc;">
                                    <td style="padding:12px 16px; color:#7b8794;">Received</td>
                                    <td style="padding:12px 16px; text-align:right;">{{ $receivedAt }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Message no.</td>
                                    <td style="padding:12px 16px; text-align:right;">#{{ $messageId }}</td>
                                </tr>
                            </table>

                            <div style="margin:24px 0 8px; font-size:13px; letter-spacing:.04em; text-transform:uppercase; color:#7b8794;">
                                Message
                            </div>
                            <div style="padding:16px; background:#f7f9fa; border-left:3px solid #2f9e57; border-radius:6px; font-size:15px; line-height:1.55; white-space:pre-wrap;">{{ $body }}</div>

                            <p style="margin:24px 0 8px; font-size:13px; line-height:1.55; color:#7b8794;">
                                Reply from the dashboard rather than from your mail client, so the answer is
                                saved against the message and the rest of the office can see it has been dealt
                                with.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0 28px;">
                                <tr>
                                    <td style="background:#2f9e57; border-radius:8px;">
                                        <a href="{{ $adminUrl }}" style="display:inline-block; padding:12px 22px; color:#ffffff; font-size:15px; font-weight:600; text-decoration:none;">
                                            Open Contact Requests
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 28px; font-size:12px; color:#9aa5b1; line-height:1.5;">
                            Sent by Manara for {{ $masjidName }}. Replying to this email reaches
                            {{ $senderName }} directly, but the answer will not be recorded against the
                            message.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
