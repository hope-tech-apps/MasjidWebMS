<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 60px 64px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; font-size: 12.5px; line-height: 1.55; }
        .logo { height: 96px; margin-bottom: 6px; }
        .bars { width: 100%; margin: 6px 0 22px; }
        .bars td { height: 12px; }
        .bar-black { background: #000; }
        .bar-green { background: #6cbf4b; }
        .head td { vertical-align: top; }
        .addr { font-size: 12.5px; }
        .date { text-align: right; }
        .date span { border-bottom: 1px solid #000; padding: 0 8px 2px; }
        p { margin: 0 0 16px; }
        .sig { margin-top: 40px; }
    </style>
</head>
<body>
    @if ($logo)
        <img src="{{ $logo }}" class="logo" alt="logo">
    @else
        <div style="font-size:20px; font-weight:bold; color:#2f7d32; margin-bottom:6px;">{{ $masjidName }}</div>
    @endif

    <table class="bars" cellpadding="0" cellspacing="0">
        <tr>
            <td class="bar-black" style="width:33%"></td>
            <td style="width:2%"></td>
            <td class="bar-green" style="width:30%"></td>
            <td style="width:2%"></td>
            <td class="bar-black" style="width:33%"></td>
        </tr>
    </table>

    <table class="head" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td class="addr">
                {{ $address }}<br>
                @if ($locale){{ $locale }}<br>@endif
                @if ($phone)Phone: {{ $phone }}<br>@endif
                @if ($website){{ $website }}@endif
            </td>
            <td class="date">
                <span>{{ $date }}</span>
            </td>
        </tr>
    </table>

    <p style="margin-top:26px;">Dear {{ $donorName }},</p>

    <p>On behalf of the board and volunteers at {{ $masjidName }} we would like to personally extend our
        deepest gratitude to you for your support. We are very grateful for the unrelenting encouragement
        that supporters like you have shown to charity.</p>

    <p>This is to acknowledge your total contributions of <strong>{{ $currency }} {{ $totalEligible }}</strong>
        ({{ $giftCount }} {{ $giftCount === 1 ? 'donation' : 'donations' }}) to {{ $masjidName }} during {{ $year }}.
        {{ $masjidName }} is a tax-exempt organization under section 501(c)(3) of the Internal Revenue Code.
        As such, all contributions are tax deductible for federal and state income tax purposes.@if ($taxId)
        Our US Federal tax ID number is {{ $taxId }}.@endif</p>

    <p>No goods or services were provided in exchange for or in connection with these contributions, other
        than intangible religious benefits. You can keep this letter as written proof of your donations for
        your tax records.</p>

    @if (count($gifts))
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:11.5px; margin:6px 0 16px;">
            <tr style="border-bottom:1px solid #999;">
                <td style="padding:5px 0; color:#666;">Date</td>
                <td style="padding:5px 0; color:#666;">Fund</td>
                <td style="padding:5px 0; color:#666; text-align:right;">Amount</td>
            </tr>
            @foreach ($gifts as $g)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:4px 0;">{{ $g['date'] }}</td>
                    <td style="padding:4px 0;">{{ $g['fund'] }}</td>
                    <td style="padding:4px 0; text-align:right;">{{ $currency }} {{ $g['amount'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p>Thanks again for your generous support.</p>

    <div class="sig">
        <p style="margin-bottom:26px;">Respectfully,</p>
        {{ $signatory }}
    </div>
</body>
</html>
