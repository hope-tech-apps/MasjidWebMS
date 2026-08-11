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
                        <td style="padding:0 32px 28px; font-size:13px; line-height:1.5; color:#7b8794;">
                            You are receiving this because you are on {{ $orgName }}'s contact list.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
