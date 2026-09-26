<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #{{ $orderNumber }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#0c3d2b; padding:24px 32px; color:#ffffff;">
                            <div style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; opacity:.85;">{{ $masjidName }}</div>
                            <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $headline }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 16px; font-size:16px; line-height:1.5;">{{ $greeting }}</p>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.55;">{{ $lead }}</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; font-size:14px;">
                                <tr>
                                    <td style="padding:12px 16px; color:#7b8794;">Order no.</td>
                                    <td style="padding:12px 16px; text-align:right; font-weight:600;">#{{ $orderNumber }}</td>
                                </tr>
                                @if ($pickupLabel)
                                    <tr style="background:#fafbfc;">
                                        <td style="padding:12px 16px; color:#7b8794;">Pickup</td>
                                        <td style="padding:12px 16px; text-align:right; font-weight:600;">{{ $pickupLabel }}</td>
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

                            @if ($howToPay)
                                <div style="border-left:3px solid #a05a1a; padding:4px 0 4px 16px; margin:12px 0 0; font-size:14px; line-height:1.55; color:#52606d; white-space:pre-line;">{{ $howToPay }}</div>
                            @endif

                            @if ($orderLink)
                                {{-- $orderLink, never $orderUrl: only the former is checked (KitchenOrderForCustomer::orderLink()). Escaped into the attribute. --}}
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0;">
                                    <tr>
                                        <td style="background:#0c3d2b; border-radius:8px;">
                                            <a href="{{ $orderLink }}" target="_blank" rel="noopener noreferrer" style="display:inline-block; padding:12px 22px; color:#ffffff; font-size:15px; font-weight:600; text-decoration:none;">
                                                View your order
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if ($pickupNote)
                                <div style="border-left:3px solid #0c3d2b; padding:4px 0 4px 16px; margin:24px 0 0; font-size:14px; line-height:1.55; color:#52606d;">
                                    {{ $pickupNote }}
                                </div>
                            @endif

                            <p style="margin:24px 0 24px; font-size:14px; line-height:1.55; color:#52606d;">
                                Show order <strong>#{{ $orderNumber }}</strong> when you pick up.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 28px; font-size:12px; color:#9aa5b1; line-height:1.5;">
                            To change or cancel this order, contact the office.
                            @if ($canReply)
                                You can reply to this email.
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
