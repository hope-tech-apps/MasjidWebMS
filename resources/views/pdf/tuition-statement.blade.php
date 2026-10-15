<!doctype html><html><head><meta charset="utf-8"><style>body { font-family: sans-serif; font-size: 11pt; } table { width:100%; border-collapse: collapse; } td, th { padding:7px; border-bottom:1px solid #ccc; text-align:left; }</style></head><body>
@if ($letterhead['logo'])<img src="{{ $letterhead['logo'] }}" style="max-height:64px" />@endif
<h1>{{ $letterhead['masjidName'] }}</h1><p>{{ $letterhead['address'] }} {{ $letterhead['locale'] }}</p><h2>Tuition statement</h2>
<p>{{ $statement['from'] }} to {{ $statement['to'] }}</p>
<p>Opening balance: {{ $money($statement['opening_minor']) }}</p>
<table><thead><tr><th>Date / Due</th><th>Child</th><th>Entry</th><th>Reason</th><th>Amount</th></tr></thead><tbody>
@foreach ($statement['lines'] as $line)
<tr><td>{{ $line['date'] }}@if ($line['due_on'])<br>{{ $line['due_on'] }}@endif</td><td>{{ $line['child'] }}</td><td>#{{ $line['id'] }} {{ str_replace('_', ' ', $line['kind']) }}@if ($line['method'])<br>{{ str_replace('_', ' ', $line['method']) }}@endif @if ($line['original_line_id']) (entry {{ $line['original_line_id'] }}) @endif</td><td>{{ $line['reason'] }}</td><td>{{ $money($line['amount_minor']) }}</td></tr>
@endforeach
</tbody></table><p>Closing balance: {{ $money($statement['closing_minor']) }}</p>
<p>Office payments are recorded by the school. Card payments are recorded from Stripe confirmation. This is a tuition statement.</p>
</body></html>
