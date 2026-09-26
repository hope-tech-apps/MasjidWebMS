<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen order #{{ $orderNumber }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#0c3d2b; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">{{ $masjidName }}</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">New order to confirm</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.55;">
                                {{ $menuTitle ?: 'Kitchen' }} order <strong>#{{ $orderNumber }}</strong> is waiting for the office to confirm it.
                                The customer has been told it is not confirmed until they hear from you.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; font-size:14px;">
                                <tr>
                                    <td style="padding:10px 16px; color:#7b8794;">Customer</td>
                                    <td style="padding:10px 16px; text-align:right; font-weight:600;">{{ $customerName }}</td>
                                </tr>
                                @if ($customerPhone)
                                    <tr style="background:#fafbfc;">
                                        <td style="padding:10px 16px; color:#7b8794;">Phone</td>
                                        <td style="padding:10px 16px; text-align:right;">{{ $customerPhone }}</td>
                                    </tr>
                                @endif
                                @if ($customerEmail)
                                    <tr>
                                        <td style="padding:10px 16px; color:#7b8794;">Email</td>
                                        <td style="padding:10px 16px; text-align:right;">{{ $customerEmail }}</td>
                                    </tr>
                                @endif
                                @if ($pickupLabel)
                                    <tr style="background:#fafbfc;">
                                        <td style="padding:10px 16px; color:#7b8794;">Pickup</td>
                                        <td style="padding:10px 16px; text-align:right; font-weight:600;">{{ $pickupLabel }}</td>
                                    </tr>
                                @endif
                                @foreach ($items as $item)
                                    <tr>
                                        <td style="padding:10px 16px;">{{ $item['quantity'] }} &times; {{ $item['name'] }}</td>
                                        <td style="padding:10px 16px; text-align:right; color:#52606d;">{{ $item['line'] }}</td>
                                    </tr>
                                @endforeach
                                <tr style="background:#fafbfc;">
                                    <td style="padding:12px 16px; color:#7b8794;">Total</td>
                                    <td style="padding:12px 16px; text-align:right; font-weight:700; font-size:18px;">{{ $totalLine }}</td>
                                </tr>
                            </table>

                            <p style="margin:16px 0 0; font-size:15px; line-height:1.55;">{{ $paymentLine }}</p>

                            @if ($customerNotes)
                                <div style="border-left:3px solid #0c3d2b; padding:4px 0 4px 16px; margin:16px 0 0; font-size:14px; line-height:1.55; color:#52606d; white-space:pre-line;">{{ $customerNotes }}</div>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 24px;">
                                <tr>
                                    <td style="background:#0c3d2b; border-radius:8px;">
                                        <a href="{{ $adminUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block; padding:12px 22px; color:#ffffff; font-size:15px; font-weight:600; text-decoration:none;">
                                            Open the orders board
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
