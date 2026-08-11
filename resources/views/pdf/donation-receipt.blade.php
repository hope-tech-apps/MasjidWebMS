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
        .title { font-size: 15px; font-weight: bold; letter-spacing: .04em; text-transform: uppercase; margin: 22px 0 2px; }
        .serial { font-size: 12.5px; color: #444; margin-bottom: 18px; }
        .detail { width: 100%; border-collapse: collapse; font-size: 12.5px; margin: 0 0 18px; }
        .detail td { padding: 6px 0; border-bottom: 1px solid #eee; }
        .detail td.k { color: #666; width: 55%; }
        .detail td.v { text-align: right; }
        .detail tr.total td { border-top: 2px solid #999; border-bottom: none; padding-top: 9px; font-weight: bold; }
        .ref { font-size: 10.5px; color: #888; }
        .sig { margin-top: 36px; }
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
                <span>{{ $issueDate }}</span>
            </td>
        </tr>
    </table>

    <div class="title">Official Donation Receipt</div>
    <div class="serial">Receipt No. {{ $serial }}</div>

    <p>Dear {{ $donorName }},</p>

    <p>Thank you for your generous support of {{ $masjidName }}. This receipt acknowledges the
        contribution recorded below and is issued for your tax records.</p>

    <table class="detail" cellpadding="0" cellspacing="0">
        <tr>
            <td class="k">Receipt no.</td>
            <td class="v">{{ $serial }}</td>
        </tr>
        <tr>
            <td class="k">Date issued</td>
            <td class="v">{{ $issueDate }}</td>
        </tr>
        @if ($giftDate)
            <tr>
                <td class="k">Date of gift</td>
                <td class="v">{{ $giftDate }}</td>
            </tr>
        @endif
        <tr>
            <td class="k">Fund</td>
            <td class="v">{{ $fundName }}</td>
        </tr>
        <tr>
            <td class="k">Amount donated</td>
            <td class="v">{{ $currency }} {{ $grossAmount }}</td>
        </tr>
        @if ($hasAdvantage)
            <tr>
                <td class="k">Value of advantage received</td>
                <td class="v">{{ $currency }} {{ $advantageAmount }}</td>
            </tr>
        @endif
        <tr class="total">
            <td class="k" style="color:#1a1a1a;">Eligible amount of this gift for tax purposes</td>
            <td class="v">{{ $currency }} {{ $eligibleAmount }}</td>
        </tr>
    </table>

    <p>{{ $masjidName }} is a tax-exempt organization under section 501(c)(3) of the Internal Revenue Code.
        As such, all contributions are tax deductible for federal and state income tax purposes.@if ($taxId)
        Our {{ $jurisdiction }} Federal tax ID number is {{ $taxId }}.@endif</p>

    <p>No goods or services were provided in exchange for or in connection with this contribution, other
        than intangible religious benefits. You can keep this receipt as written proof of your donation for
        your tax records.</p>

    @if ($reference !== '')
        <p class="ref">Reference: {{ $reference }}</p>
    @endif

    <div class="sig">
        <p style="margin-bottom:26px;">Respectfully,</p>
        {{ $signatory }}
    </div>
</body>
</html>
