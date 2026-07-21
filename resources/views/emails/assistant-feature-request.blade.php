<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masjid Assistant Request</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">Masjid Assistant · {{ $categoryLabel }}</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $masjidName }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 20px; font-size:16px; line-height:1.5;">
                                {{ $requestedBy }} asked the Masjid Assistant for something it couldn't do.
                            </p>

                            <div style="border-left:3px solid #2f9e57; padding:4px 0 4px 16px; margin:0 0 20px; font-size:16px; font-weight:600;">
                                {{ $summary }}
                            </div>

                            @if ($details)
                                <div style="background:#fafbfc; border:1px solid #e4e7eb; border-radius:8px; padding:14px 16px; margin:0 0 20px; font-size:14px; line-height:1.55; color:#52606d; white-space:pre-wrap;">{{ $details }}</div>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; font-size:14px;">
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Request no.</td>
                                    <td style="padding:12px 16px; text-align:right; font-weight:600;">{{ $requestId }}</td>
                                </tr>
                                <tr style="background:#fafbfc;">
                                    <td style="padding:12px 16px; color:#7b8794;">Masjid</td>
                                    <td style="padding:12px 16px; text-align:right;">{{ $masjidName }} (#{{ $masjidId }})</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Requested by</td>
                                    <td style="padding:12px 16px; text-align:right;">{{ $requestedBy }}</td>
                                </tr>
                                <tr style="background:#fafbfc;">
                                    <td style="padding:12px 16px; color:#7b8794;">Reply to</td>
                                    <td style="padding:12px 16px; text-align:right;">{{ $requestedByEmail }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Type</td>
                                    <td style="padding:12px 16px; text-align:right;">{{ $categoryLabel }}</td>
                                </tr>
                            </table>

                            <p style="margin:20px 0 0; font-size:13px; line-height:1.55; color:#7b8794;">
                                The assistant told the admin this was sent to Hope Tech Inc. Replying to this
                                email reaches them directly.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 28px; font-size:12px; color:#9aa5b1;">
                            Sent automatically by the Masjid Assistant. Request #{{ $requestId }} is
                            recorded in the admin database whether or not this email arrived.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
