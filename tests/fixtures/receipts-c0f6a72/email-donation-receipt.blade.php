<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donation Receipt</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#2f9e57; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">Official Donation Receipt</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $masjidName }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 16px; font-size:16px;">Assalamu alaikum {{ $donorName }},</p>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.55; color:#52606d;">
                                Jazāk Allāhu Khayran for your {{ $recurring ? 'monthly ' : '' }}donation. This receipt confirms your gift and is issued for your records.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; font-size:14px;">
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Receipt no.</td>
                                    <td style="padding:12px 16px; text-align:right; font-weight:600;">{{ $serial }}</td>
                                </tr>
                                <tr style="background:#fafbfc;">
                                    <td style="padding:12px 16px; color:#7b8794;">Date issued</td>
                                    <td style="padding:12px 16px; text-align:right;">{{ $issueDate }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Fund</td>
                                    <td style="padding:12px 16px; text-align:right;">{{ $fundName }}</td>
                                </tr>
                                <tr style="background:#fafbfc;">
                                    <td style="padding:12px 16px; color:#7b8794;">Amount donated</td>
                                    <td style="padding:12px 16px; text-align:right;">{{ $currency }} ${{ $grossAmount }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px; color:#1f2933; font-weight:700; border-top:2px solid #e4e7eb;">Tax-deductible amount</td>
                                    <td style="padding:14px 16px; text-align:right; font-weight:700; color:#2f9e57; border-top:2px solid #e4e7eb;">{{ $currency }} ${{ $eligibleAmount }}</td>
                                </tr>
                            </table>

                            <p style="margin:20px 0 0; font-size:12px; line-height:1.6; color:#7b8794;">
                                No goods or services were provided in exchange for this contribution; any benefit received was intangible religious benefit only.
                                {{ $masjidName }} is a tax-exempt religious organization. Please retain this receipt for your tax records.
                            </p>
                            <p style="margin:12px 0 0; font-size:12px; color:#9aa5b1;">Reference: {{ $reference }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 28px; border-top:1px solid #eef1f4;">
                            <p style="margin:0; font-size:13px; color:#9aa5b1;">May Allah accept it from you and multiply your reward.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
