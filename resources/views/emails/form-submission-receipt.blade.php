<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $formName }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">{{ $masjidName }}</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $title }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 16px; font-size:16px; line-height:1.5;">{{ $greeting }}</p>

                            @if ($body)
                                <p style="margin:0 0 20px; font-size:16px; line-height:1.55;">{{ $body }}</p>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; font-size:14px;">
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Registration no.</td>
                                    <td style="padding:12px 16px; text-align:right; font-weight:600;">#{{ $responseId }}</td>
                                </tr>
                                @if ($entryCount > 0)
                                    <tr style="background:#fafbfc;">
                                        <td style="padding:12px 16px; color:#7b8794;">People registered</td>
                                        <td style="padding:12px 16px; text-align:right; font-weight:600;">{{ $entryCount }}</td>
                                    </tr>
                                @endif
                                @if ($amountLine)
                                    <tr>
                                        <td style="padding:12px 16px; color:#7b8794;">Total due</td>
                                        <td style="padding:12px 16px; text-align:right; font-weight:700; font-size:18px;">
                                            {{ $amountLine }}@if ($tierLabel)<span style="display:block; font-weight:400; font-size:12px; color:#7b8794;">{{ $tierLabel }} rate</span>@endif
                                        </td>
                                    </tr>
                                @endif
                            </table>

                            @if (count($people) > 0)
                                <div style="margin:24px 0 8px; font-size:13px; letter-spacing:.04em; text-transform:uppercase; color:#7b8794;">
                                    You registered
                                </div>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; font-size:14px;">
                                    @foreach ($people as $person)
                                        <tr @if ($loop->even) style="background:#fafbfc;" @endif>
                                            <td style="padding:10px 16px; font-weight:600;">{{ $person['name'] }}</td>
                                            <td style="padding:10px 16px; text-align:right; color:#52606d;">{{ $person['detail'] }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if ($paymentNote)
                                <div style="border-left:3px solid #2f9e57; padding:4px 0 4px 16px; margin:24px 0 0; font-size:14px; line-height:1.55; color:#52606d;">
                                    {{ $paymentNote }}
                                </div>
                            @endif

                            @if (count($nextSteps) > 0)
                                <div style="margin:24px 0 8px; font-size:13px; letter-spacing:.04em; text-transform:uppercase; color:#7b8794;">
                                    What happens next
                                </div>
                                <ul style="margin:0 0 24px; padding-left:20px; font-size:15px; line-height:1.6;">
                                    @foreach ($nextSteps as $step)
                                        <li style="margin-bottom:6px;">{{ $step }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 28px; font-size:12px; color:#9aa5b1; line-height:1.5;">
                            Keep this email — it confirms your registration for {{ $formName }}.
                            Reply to it if anything above looks wrong.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
