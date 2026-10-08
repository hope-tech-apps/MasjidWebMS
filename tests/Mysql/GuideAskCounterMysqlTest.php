<?php

use App\Support\Guides\GuideAskLimits;
use Illuminate\Support\Facades\DB;

it('counts only actual conditional changes and creates both managed-MySQL primary keys', function () {
    $limits = app(GuideAskLimits::class);
    expect($limits->reserveBucket('org:42', '2099-01-01', 1))->toBeTrue();
    expect($limits->reserveBucket('org:42', '2099-01-01', 1))->toBeFalse();
    expect($limits->reserveBucket('org:42', '2099-01-01', 1))->toBeFalse();
    expect((int) DB::table('guide_ask_counters')->where('scope_key', 'org:42')->value('count'))->toBe(1);
    foreach (['guide_ask_counters', 'guide_unanswered_questions'] as $table) {
        $key = DB::selectOne("select COLUMN_KEY as k, EXTRA as e from information_schema.COLUMNS where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME = 'id'", [$table]);
        expect($key->k)->toBe('PRI')->and($key->e)->toContain('auto_increment');
    }
});
