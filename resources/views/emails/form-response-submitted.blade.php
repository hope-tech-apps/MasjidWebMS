<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New registration</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">New registration</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $formName }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 20px; font-size:16px; line-height:1.5;">
                                {{ $registrantName }} registered
                                @if ($entryCount === 1)
                                    1 person
                                @elseif ($entryCount > 1)
                                    {{ $entryCount }} people
                                @endif
                                for {{ $formName }}.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; font-size:14px;">
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Registered by</td>
                                    <td style="padding:12px 16px; text-align:right; font-weight:600;">{{ $registrantName }}</td>
                                </tr>
                                @if ($registrantEmail)
                                    <tr style="background:#fafbfc;">
                                        <td style="padding:12px 16px; color:#7b8794;">Email</td>
                                        <td style="padding:12px 16px; text-align:right;">
                                            <a href="mailto:{{ $registrantEmail }}" style="color:#2f9e57; text-decoration:none;">{{ $registrantEmail }}</a>
                                        </td>
                                    </tr>
                                @endif
                                @if ($registrantPhone)
                                    <tr>
                                        <td style="padding:12px 16px; color:#7b8794;">Phone</td>
                                        <td style="padding:12px 16px; text-align:right;">{{ $registrantPhone }}</td>
                                    </tr>
                                @endif
                                @if ($amountLine)
                                    <tr style="background:#fafbfc;">
                                        <td style="padding:12px 16px; color:#7b8794;">Amount due</td>
                                        <td style="padding:12px 16px; text-align:right; font-weight:700; font-size:16px;">
                                            {{ $amountLine }}@if ($tierLabel)<span style="display:block; font-weight:400; font-size:12px; color:#7b8794;">{{ $tierLabel }} rate</span>@endif
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Registration no.</td>
                                    <td style="padding:12px 16px; text-align:right;">#{{ $responseId }}</td>
                                </tr>
                            </table>

                            @if (count($people) > 0)
                                <div style="margin:24px 0 8px; font-size:13px; letter-spacing:.04em; text-transform:uppercase; color:#7b8794;">
                                    Attending ({{ count($people) }})
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

                            <p style="margin:24px 0 8px; font-size:13px; line-height:1.55; color:#7b8794;">
                                Health details, emergency contacts and the full answers are kept in the admin
                                panel rather than in this email.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0 28px;">
                                <tr>
                                    <td style="background:#2f9e57; border-radius:8px;">
                                        <a href="{{ $adminUrl }}" style="display:inline-block; padding:12px 22px; color:#ffffff; font-size:15px; font-weight:600; text-decoration:none;">
                                            Open Form Responses
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 28px; font-size:12px; color:#9aa5b1; line-height:1.5;">
                            Sent by Manara for {{ $masjidName }}. Reply to this email to reach
                            {{ $registrantName }} directly.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
