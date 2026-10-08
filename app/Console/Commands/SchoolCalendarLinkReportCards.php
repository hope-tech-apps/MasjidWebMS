<?php

namespace App\Console\Commands;

use App\Models\{Masjid, ReportCard};
use App\Support\{SchoolSettings, SchoolReportCardTermMatcher, TenantContext};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SchoolCalendarLinkReportCards extends Command
{
    protected $signature = 'school-calendar:link-report-cards {--masjid= : Organisation ID} {--dry-run : Report without changing cards}';
    protected $description = 'Link report cards to exact matching dated quarters and report unmatched periods.';

    public function handle(): int
    {
        $id = $this->option('masjid');
        if ($id !== null && (! ctype_digit((string)$id) || (int)$id < 1)) {
            $this->error('--masjid must be a positive organisation ID.'); return self::FAILURE;
        }
        return app(TenantContext::class)->runWithout(function () use ($id) {
            $query = Masjid::query()->orderBy('id');
            if ($id !== null) $query->whereKey((int)$id);
            if ($id !== null && ! $query->exists()) { $this->error('Organisation ID was not found.'); return self::FAILURE; }
            foreach ($query->cursor() as $org) {
                if (! SchoolSettings::calendarTerms($org)) continue;
                $linked = []; $unmatched = []; $total = 0;
                // ON only. IDs outside transactions; mutable state is reread after the org mutex.
                ReportCard::query()->where('masjid_id',$org->id)->whereNull('school_term_id')->select('id')->chunkById(100, function ($cards) use ($org,&$linked,&$unmatched,&$total) {
                    foreach ($cards as $candidate) {
                        // A production dry run never opens a transaction or asks for locks.
                        $result = $this->matchCard($org->id, $candidate->id, false);
                        if (! $this->option('dry-run') && $result && $result['match']['term']) {
                            $result = DB::transaction(fn () => $this->matchCard($org->id, $candidate->id, true));
                        }
                        if (! $result) continue;
                        $match = $result['match'];
                        if ($match['term']) {
                            $key = $match['year']->id.':'.$result['position'].':'.$match['term']->id;
                            $linked[$key] = ($linked[$key] ?? 0) + 1; $total++;
                        } else {
                            $key = json_encode([$result['text'],$result['position'],$match['reason']],JSON_UNESCAPED_UNICODE);
                            $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
                        }
                    }
                });
                $this->line('organisation='.$org->id.' mode='.($this->option('dry-run')?'dry-run':'write').' linked='.$total.' unmatched='.array_sum($unmatched));
                ksort($linked); ksort($unmatched);
                foreach ($linked as $key=>$count) { [$year,$position,$term]=explode(':',$key); $this->line("year=$year term=$position school_term=$term count=$count"); }
                foreach ($unmatched as $key=>$count) {
                    [$text,$position,$reason]=json_decode($key,true);
                    $this->line('school_year='.json_encode($text,JSON_UNESCAPED_UNICODE).' term='.$position.' count='.$count.' reason='.$reason);
                }
            }
            return self::SUCCESS;
        });
    }
    /** Both modes share matching and reporting; only a matched real update takes locks. */
    private function matchCard(int $org, int $id, bool $write): ?array
    {
        if ($write) {
            // First statement: no stale consistent-read view before the mutex.
            $locked = Masjid::query()->whereKey($org)->lockForUpdate()->firstOrFail();
            if (! SchoolSettings::calendarTerms($locked)) return null;
        }
        $card = ReportCard::query()->where('masjid_id', $org)->whereKey($id)->first();
        if (! $card || $card->school_term_id !== null) return null;
        $match = SchoolReportCardTermMatcher::match($org, $card->school_year, $card->term, $write);
        if ($write && $match['term']) {
            // Organization -> year -> term -> card. Mutable state was rechecked.
            $card = ReportCard::query()->where('masjid_id', $org)->whereKey($id)->lockForUpdate()->first();
            if (! $card || $card->school_term_id !== null) return null;
            if ($match['term']) {
                DB::table('report_cards')->where('masjid_id', $org)->where('id', $card->id)->whereNull('school_term_id')->update(['school_term_id' => $match['term']->id]);
            }
        }
        return ['text' => $card->school_year, 'position' => $card->term, 'match' => $match];
    }

}
