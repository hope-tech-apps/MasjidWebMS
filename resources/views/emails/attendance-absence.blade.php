<!doctype html>
<html lang="en"><body>
<p>{{ $orgName }}</p>
@foreach (explode("\n", $bodyText) as $line)
@if ($line !== '')<p>{{ $line }}</p>@endif
@endforeach
</body></html>
