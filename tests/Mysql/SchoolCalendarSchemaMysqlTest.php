<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

require_once __DIR__.'/../Support/calendarMysqlDiagnostic.php';

it('has production-compatible calendar types, primary key and short indexes', function () {
    return calendarMysqlDiagnostic(function () {
        $columns = collect(DB::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS type, IS_NULLABLE AS nullable, CHARACTER_MAXIMUM_LENGTH AS length, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('school_years','school_terms','report_cards')"))->keyBy(fn ($r) => $r->t.'.'.$r->c);
        expect($columns['school_years.meeting_weekdays']->type)->toBe('json')
            ->and($columns['school_years.meeting_weekdays']->nullable)->toBe('YES')
            ->and($columns['school_years.term_system']->length)->toBe(12)
            ->and($columns['school_terms.name']->length)->toBe(80)
            ->and($columns['school_terms.position']->type)->toBe('tinyint')
            ->and($columns['report_cards.school_term_id']->type)->toBe('bigint')
            ->and($columns['report_cards.school_term_id']->nullable)->toBe('YES')
            ->and($columns['school_terms.id']->extra)->toContain('auto_increment');
        $indexes = collect(DB::select("SELECT INDEX_NAME AS name, COLUMN_NAME AS col FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='school_terms'"));
        expect($indexes->where('name','PRIMARY')->pluck('col')->all())->toBe(['id'])
            ->and($indexes->where('name','school_term_year_pos_uq')->pluck('col')->all())->toBe(['school_year_id','position']);
        foreach ($indexes as $index) expect(strlen($index->name))->toBeLessThanOrEqual(64);
        $rules = collect(DB::select("SELECT TABLE_NAME AS t, REFERENCED_TABLE_NAME AS parent, DELETE_RULE AS rule FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('school_terms','report_cards')"));
        expect($rules->first(fn($r)=>$r->t==='school_terms' && $r->parent==='school_years')->rule)->toBe('CASCADE')
            ->and($rules->first(fn($r)=>$r->t==='report_cards' && $r->parent==='school_terms')->rule)->toBe('SET NULL');
        $cardIndex = DB::select("SELECT COLUMN_NAME AS col FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_cards' AND INDEX_NAME='report_card_school_term_idx'");
        expect(array_column($cardIndex,'col'))->toBe(['school_term_id']);
    }, 'school_terms', 'id');
});

