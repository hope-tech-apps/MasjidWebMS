<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $masjidName }}</title>
</head>

<body style="margin:0; padding:0; background-color:#f4f4f7; font-family: Arial, Helvetica, sans-serif; color:#333;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0"
                    style="background-color:#ffffff; border-radius:8px; overflow:hidden; max-width:600px; width:100%;">
                    <tr>
                        <td style="background-color:#01B151; padding:20px 32px; color:#ffffff; font-size:18px; font-weight:bold;">
                            {{ $masjidName }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px;">Assalamu alaikum {{ $contacterName }},</p>
                            <p style="margin:0 0 16px;">Thank you for reaching out to us. Here is our reply to your message:</p>
                            <div style="margin:0 0 24px; padding:16px; background-color:#f4f4f7; border-radius:6px; white-space:pre-wrap;">{{ $replyBody }}</div>
                            <hr style="border:none; border-top:1px solid #e5e5e5; margin:24px 0;">
                            <p style="margin:0 0 8px; font-size:13px; color:#888;">Your original message:</p>
                            <div style="margin:0; padding:12px 16px; background-color:#fafafa; border-left:3px solid #01B151; font-size:13px; color:#555; white-space:pre-wrap;">{{ $originalMessage }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px; background-color:#fafafa; font-size:12px; color:#999;">
                            This message was sent by {{ $masjidName }} in response to your contact request.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
