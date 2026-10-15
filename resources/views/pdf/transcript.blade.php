<!DOCTYPE html><html><head><meta charset="utf-8"><style>
body { font-family: dejavusans; font-size: 10pt; color: #17293d; } h1 { font-size: 20pt; } h2 { font-size: 13pt; margin-top: 20px; } table { width: 100%; border-collapse: collapse; } th, td { padding: 6px; border-bottom: 1px solid #ddd; text-align: left; } .small { font-size: 8pt; } .logo { height: 55px; }
</style></head><body>
@if($document['school']['logo'])<img class="logo" src="{{ $document['school']['logo'] }}">@endif
<h1>{{ $document['school']['name'] }}</h1><p>{{ $document['school']['address'] }}</p>
<h2>{{ $document['designation'] }} Transcript</h2>
<p>{{ $document['student']['name'] }} · Grade {{ $document['student']['grade'] ?? 'Not recorded' }}<br>
@if($document['student']['date_of_birth'])Date of birth: {{ $document['student']['date_of_birth'] }}<br>@endif
Dates attended: {{ $document['student']['attended_from'] ?? 'Not recorded' }} to {{ $document['student']['attended_to'] ?? 'Current or not recorded' }}</p>
@foreach($document['years'] as $year)
<h2>{{ $year['year'] }} · {{ $year['grade'] }} · {{ $year['origin'] }}</h2>
@if($year['excluded_from_gpa'])<p>Prior transcript file only. No courses entered; this year contributes no credits or GPA.</p>@endif
<table><thead><tr><th>Course</th><th>Level</th><th>Final letter</th><th>Attempted</th><th>Earned</th></tr></thead><tbody>
@foreach($year['courses'] as $course)<tr><td>{{ $course['name'] }}</td><td>{{ ['regular'=>'Regular','honors'=>'Honors','ap'=>'AP'][$course['level']] }}</td><td>{{ $course['letter'] ?? 'Not graded' }}</td><td>{{ $course['credit'] ?? 'Not set' }}</td><td>{{ $course['passing'] ? ($course['credit'] ?? 'Not set') : '0.00' }}</td></tr>@endforeach
</tbody></table>
<p>Year GPA: plain {{ $year['totals']['plain'] ?? 'Not available' }}, weighted {{ $year['totals']['weighted'] ?? 'Not available' }}. Credits attempted {{ $year['totals']['attempted'] }}, earned {{ $year['totals']['earned'] }}.</p>
@endforeach
<h2>Cumulative</h2><p>Plain GPA {{ $document['cumulative']['plain'] ?? 'Not available' }} · Weighted GPA {{ $document['cumulative']['weighted'] ?? 'Not available' }}<br>Total credits attempted {{ $document['cumulative']['attempted'] }} · Total credits earned {{ $document['cumulative']['earned'] }}</p>
<p class="small">{{ $document['method'] }}</p>
@if($document['designation']==='Official')<p>Issued {{ $document['issued_at'] }} by {{ $document['issued_by'] }}<br>Serial: {{ $document['serial'] }}</p><p>Signature: _____________________________________</p>@else<p>Unofficial copy · Generated {{ $document['generated_at'] }}</p>@endif
</body></html>
