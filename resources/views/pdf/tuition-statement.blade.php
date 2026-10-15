<!doctype html><html><head><meta charset="utf-8"><style>body { font-family: sans-serif; font-size: 11pt; } table { width:100%; border-collapse: collapse; } td, th { padding:7px; border-bottom:1px solid #ccc; text-align:left; }</style></head><body>
@if ($letterhead['logo'])<img src="{{ $letterhead['logo'] }}" style="max-height:64px" />@endif
<h1>{{ $letterhead['masjidName'] }}</h1><p>{{ $letterhead['address'] }} {{ $letterhead['locale'] }}<br>{{ $contact }}</p><h2>Tuition statement</h2>
<p>Family: {{ $statement['family_name'] }}</p>
<p>{{ $date($statement['from']) }} to {{ $date($statement['to']) }}</p>
<p>Opening balance: {{ $money($statement['opening_minor']) }}</p>
<table><thead><tr><th>Date / Due</th><th>Child</th><th>Entry</th><th>Description</th><th>Amount</th></tr></thead><tbody>
@foreach ($statement['lines'] as $line)
<tr><td>{{ $date($line['date']) }}@if ($line['due_on'])<br>Due {{ $date($line['due_on']) }}@endif</td><td>{{ $line['child'] }}</td><td>{{ $line['kind_label'] }}@if ($line['method_label']), {{ $line['method'] === 'cash' ? 'cash' : $line['method_label'] }}@endif</td><td>{{ $line['description'] }}</td><td>{{ $money($line['amount_minor']) }}</td></tr>
@endforeach
</tbody></table><p>Closing balance: {{ $money($statement['closing_minor']) }}</p>
<p>{{ $letterhead['masjidName'] }} · {{ $contact }}</p>
</body></html>
