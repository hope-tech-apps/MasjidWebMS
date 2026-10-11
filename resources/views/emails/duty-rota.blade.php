<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body><h1>{{ $orgName }}</h1><div style="white-space:pre-wrap;overflow-wrap:anywhere">@php
    // Only HTTP(S) private links become anchors; both the address and visible text are escaped.
    foreach (preg_split('~(https?://[^\s]+\#token=[a-f0-9]{64})~', $bodyText, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        echo preg_match('~^https?://[^\s]+\#token=[a-f0-9]{64}$~', $part)
            ? '<a href="'.e($part).'">'.e($part).'</a>'
            : e($part);
    }
@endphp</div></body></html>
