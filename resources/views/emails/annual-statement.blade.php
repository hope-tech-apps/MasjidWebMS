<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $year }} Annual Giving Statement</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">Annual Giving Statement · {{ $year }}</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $masjidName }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 16px; font-size:16px;">Assalamu alaikum {{ $donorName }},</p>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.55; color:#52606d;">
                                Jazāk Allāhu Khayran for your generosity this year. Below is a summary of your
                                tax-deductible contributions to {{ $masjidName }} for {{ $year }}, for your records.
                            </p>

                            <!-- Total -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; margin-bottom:20px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <div style="font-size:13px; color:#7b8794; text-transform:uppercase; letter-spacing:.04em;">Total tax-eligible ({{ $giftCount }} {{ $giftCount === 1 ? 'gift' : 'gifts' }})</div>
                                        <div style="font-size:26px; font-weight:700; color:#1f2933; margin-top:4px;">{{ $currency }} {{ $totalEligible }}</div>
                                    </td>
                                </tr>
                            </table>

                            <!-- By fund -->
                            @if (count($byFund) > 1)
                                <h6 style="font-size:12px; color:#7b8794; text-transform:uppercase; letter-spacing:.04em; margin:0 0 8px;">By fund</h6>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; margin-bottom:20px;">
                                    @foreach ($byFund as $row)
                                        <tr>
                                            <td style="padding:6px 0; color:#52606d;">{{ $row['fund'] }}</td>
                                            <td style="padding:6px 0; text-align:right; font-weight:600;">{{ $currency }} {{ $row['amount'] }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            <!-- Gift detail -->
                            <h6 style="font-size:12px; color:#7b8794; text-transform:uppercase; letter-spacing:.04em; margin:0 0 8px;">Contributions</h6>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-collapse:collapse;">
                                <tr style="border-bottom:1px solid #e4e7eb;">
                                    <td style="padding:8px 0; color:#7b8794;">Date</td>
                                    <td style="padding:8px 0; color:#7b8794;">Fund</td>
                                    <td style="padding:8px 0; color:#7b8794; text-align:right;">Receipt</td>
                                    <td style="padding:8px 0; color:#7b8794; text-align:right;">Amount</td>
                                </tr>
                                @foreach ($gifts as $gift)
                                    <tr style="border-bottom:1px solid #f0f2f4;">
                                        <td style="padding:8px 0;">{{ $gift['date'] }}</td>
                                        <td style="padding:8px 0;">{{ $gift['fund'] }}</td>
                                        <td style="padding:8px 0; text-align:right; color:#7b8794;">#{{ $gift['serial'] }}</td>
                                        <td style="padding:8px 0; text-align:right; font-weight:600;">{{ $currency }} {{ $gift['amount'] }}</td>
                                    </tr>
                                @endforeach
                            </table>

                            <p style="margin:24px 0 0; font-size:12px; line-height:1.6; color:#9aa5b1;">
                                No goods or services were provided in exchange for these contributions, other than
                                intangible religious benefits. Please retain this statement for your tax records.
                                {{ $masjidName }} is a registered 501(c)(3) organization.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 28px; font-size:12px; color:#9aa5b1;">
                            Generated automatically. If any detail looks wrong, please contact {{ $masjidName }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
