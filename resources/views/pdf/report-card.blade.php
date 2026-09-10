{{--
    A report card, for printing and keeping.

    LAID OUT IN TABLES ON PURPOSE. This is rendered by mpdf, not a browser, and
    table/block layout is the part of CSS a PDF engine renders predictably.
    Flexbox and grid are not worth the risk on a document nobody will re-check
    after it has been emailed to sixty families.

    NO COLOUR SCALE ON THE LEVELS, matching the parent screen and the teacher's.
    The 1-4 scale is a standards scale; App\Support\PerformanceLevel argues at
    length that showing one as a percentage or a pass/fail misreads it. A 2 is
    "Approaching Expectations" — a description of where a child is right now —
    and printing it in red says something the teacher did not say, permanently,
    on paper. The school's own colour is used for structure only.
--}}
@php
    $primary = $palette['color']['primary'] ?? '#286C56';
    $onPrimary = $palette['color']['onPrimary'] ?? '#FFFFFF';
    $muted = '#6B7280';
    $rule = '#E5E7EB';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #111827; font-size: 10pt; line-height: 1.45; }

        .masthead { width: 100%; border-collapse: collapse; margin-bottom: 4pt; }
        .masthead td { vertical-align: middle; }
        .logo { height: 52pt; }
        .school { font-size: 16pt; font-weight: bold; color: {{ $primary }}; }
        .doctype { text-align: right; font-size: 11pt; font-weight: bold; color: {{ $primary }}; }
        .period { text-align: right; font-size: 9.5pt; color: {{ $muted }}; }

        .band { height: 3pt; background: {{ $primary }}; margin: 6pt 0 12pt; }

        .who { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
        .who td { vertical-align: bottom; padding: 0; }
        /* Generous leading, because an Arabic name is TALLER than a Latin one:
           at 14pt, أحمد's descenders ran into the grade line below it. Latin
           names do not reveal this, and neither does any fixture in the repo. */
        .child { font-size: 14pt; font-weight: bold; line-height: 1.6; }
        .grade { font-size: 9.5pt; color: {{ $muted }}; padding-top: 2pt; }
        .issued { text-align: right; font-size: 9.5pt; color: {{ $muted }}; }

        .attendance { width: 100%; border-collapse: collapse; background: #F9FAFB;
                      border: 0.5pt solid {{ $rule }}; margin-bottom: 12pt; }
        .attendance td { padding: 6pt 8pt; font-size: 9.5pt; }

        .keybox { width: 100%; border-collapse: collapse; border: 0.5pt solid {{ $rule }};
                  margin-bottom: 14pt; }
        .keybox th { background: {{ $primary }}; color: {{ $onPrimary }}; text-align: left;
                     padding: 4pt 8pt; font-size: 9pt; font-weight: bold; }
        .keybox td { padding: 3pt 8pt; font-size: 8.5pt; border-top: 0.5pt solid {{ $rule }}; }
        .keybox td.n { width: 18pt; font-weight: bold; text-align: center; }
        .keybox td.l { width: 105pt; font-weight: bold; }
        .keybox td.d { color: {{ $muted }}; }

        .subject { width: 100%; border-collapse: collapse; margin-bottom: 9pt; }
        .subject th { background: {{ $primary }}; color: {{ $onPrimary }}; text-align: left;
                      padding: 4pt 8pt; font-size: 9.5pt; font-weight: bold; }
        .subject td { padding: 4pt 8pt; border-bottom: 0.5pt solid {{ $rule }}; font-size: 9.5pt;
                      vertical-align: top; }
        .subject td.mark { width: 120pt; text-align: right; white-space: nowrap; }
        .subject tr:last-child td { border-bottom: none; }
        .note { display: block; color: {{ $muted }}; font-size: 8.5pt; font-style: italic;
                margin-top: 1pt; }
        .unassessed { color: {{ $muted }}; }

        .comment { width: 100%; border-collapse: collapse; border: 0.5pt solid {{ $rule }};
                   margin-top: 4pt; }
        .comment th { background: #F9FAFB; text-align: left; padding: 5pt 8pt; font-size: 9pt;
                      color: {{ $muted }}; font-weight: bold; border-bottom: 0.5pt solid {{ $rule }}; }
        .comment td { padding: 8pt; font-size: 9.5pt; }

        .foot { margin-top: 14pt; font-size: 8pt; color: {{ $muted }}; text-align: center; }
    </style>
</head>
<body>

    <table class="masthead">
        <tr>
            <td>
                @if ($logo)
                    <img src="{{ $logo }}" class="logo" alt="">
                @else
                    <span class="school">{{ $schoolName }}</span>
                @endif
            </td>
            <td>
                <div class="doctype">{{ $typeLabel }}</div>
                <div class="period">{{ $periodLabel }}</div>
            </td>
        </tr>
    </table>

    @if ($logo && $schoolName)
        <div class="school">{{ $schoolName }}</div>
    @endif

    <div class="band"></div>

    <table class="who">
        <tr>
            <td>
                <div class="child">{{ $childName }}</div>
                @if ($gradeLabel)<div class="grade">{{ $gradeLabel }}</div>@endif
            </td>
            <td class="issued">
                @if ($publishedAt)Issued {{ $publishedAt }}@endif
            </td>
        </tr>
    </table>

    {{-- Omitted entirely when no figures were recorded: printing zeroes here
         would read as "never absent", which is a different claim. --}}
    @if ($attendance)
        <table class="attendance">
            <tr>
                <td>
                    <strong>Attendance</strong>&nbsp;&nbsp;
                    Present {{ $attendance['present'] }}
                    <span style="color: {{ $muted }}">(includes {{ $attendance['late'] }} late)</span>
                    &nbsp;&middot;&nbsp; Absent {{ $attendance['absent'] }}
                </td>
            </tr>
        </table>
    @endif

    {{-- The key comes FIRST on paper, unlike the screen where it is a
         disclosure the reader can open. On a printed page there is nothing to
         click, so a parent meeting the 1-4 scale for the first time has to be
         able to read the numbers below without turning anything over. --}}
    <table class="keybox">
        <tr><th colspan="3">What the marks mean</th></tr>
        @foreach ($levels as $l)
            <tr>
                <td class="n">{{ $l['level'] }}</td>
                <td class="l">{{ $l['label'] }}</td>
                <td class="d">{{ $l['description'] }}</td>
            </tr>
        @endforeach
    </table>

    @foreach ($subjects as $sub)
        <table class="subject">
            <tr><th colspan="2">{{ $sub['subject'] }}</th></tr>
            @foreach ($sub['criteria'] as $m)
                <tr>
                    <td>
                        {{ $m['criterion'] }}
                        @if ($m['comment'])<span class="note">{{ $m['comment'] }}</span>@endif
                    </td>
                    <td class="mark">
                        @if ($m['level'] === null)
                            <span class="unassessed">{{ $m['levelLabel'] }}</span>
                        @else
                            {{ $m['level'] }} &middot; {{ $m['levelLabel'] }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endforeach

    {{-- Learning behaviours last and separate, as they are everywhere else:
         they describe how a child works, not what a child knows, and they are
         excluded from every academic average for that reason. --}}
    @if (count($behaviours))
        <table class="subject">
            <tr><th colspan="2">Learning behaviours</th></tr>
            @foreach ($behaviours as $m)
                <tr>
                    <td>
                        {{ $m['criterion'] }}
                        @if ($m['comment'])<span class="note">{{ $m['comment'] }}</span>@endif
                    </td>
                    <td class="mark">
                        @if ($m['level'] === null)
                            <span class="unassessed">{{ $m['levelLabel'] }}</span>
                        @else
                            {{ $m['level'] }} &middot; {{ $m['levelLabel'] }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($teacherComment)
        <table class="comment">
            <tr><th>Comment from the teacher</th></tr>
            <tr><td>{!! nl2br(e($teacherComment)) !!}</td></tr>
        </table>
    @endif

    <div class="foot">{{ $schoolName }}@if ($publishedAt) &middot; issued {{ $publishedAt }}@endif</div>

</body>
</html>
