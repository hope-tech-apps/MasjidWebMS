<?php

use App\Models\{Masjid, TuitionAccount};
use App\Services\FamilyLedger\FamilyLedger;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(TenantContext::class)->forgetTenant();
    $this->school = Masjid::create(['name'=>'Practice Lock School', 'email'=>'locks@example.invalid', 'phone'=>'+15555550109', 'country_id'=>1, 'city_id'=>1, 'address'=>'Practice', 'latitude'=>0, 'longitude'=>0, 'org_type'=>'school']);
    $this->account = TuitionAccount::create(['masjid_id'=>$this->school->id, 'currency'=>'usd']);
    config(['database.connections.tuition_other' => config('database.connections.'.config('database.default'))]);
    $this->other = DB::connection('tuition_other');
    $this->other->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

afterEach(function () {
    while (DB::transactionLevel() > 0) DB::rollBack();
    DB::purge('tuition_other');
    if (! isset($this->school)) return;
    DB::table('tuition_lines')->where('masjid_id', $this->school->id)->delete();
    DB::table('tuition_accounts')->where('masjid_id', $this->school->id)->delete();
    DB::table('masjids')->where('id', $this->school->id)->delete();
});

it('serializes overlapping ledger writers on the account and refuses the duplicate period key across connections', function () {
    DB::beginTransaction();
    $data = ['kind'=>'charge', 'amount_minor'=>12345, 'effective_on'=>'2026-10-09', 'reason'=>'Practice tuition', 'source'=>'schedule', 'recorded_by_label'=>'Scheduled fee plan', 'idempotency_key'=>'charge:practice:child:period'];
    app(FamilyLedger::class)->append($this->account, $data);
    try {
        $this->other->transaction(fn () => $this->other->table('tuition_accounts')->where('id', $this->account->id)->lockForUpdate()->first());
        $this->fail('An overlapping writer must wait for the account mutex.');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1205);
    }
    DB::commit();
    expect((int) $this->other->table('tuition_lines')->where('account_id', $this->account->id)->sum('amount_minor'))->toBe(12345);
    try {
        $this->other->table('tuition_lines')->insert($data + ['masjid_id'=>$this->school->id, 'account_id'=>$this->account->id, 'currency'=>'usd']);
        $this->fail('A second connection cannot insert the same child/plan/period key.');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1062);
    }
});

it('clones every new table with production primary-key enforcement enabled', function () {
    $prior = (int) DB::selectOne('SELECT @@session.sql_require_primary_key AS value')->value;
    DB::statement('SET SESSION sql_require_primary_key = 1');
    try {
        foreach (['organisation_billing_settings','tuition_accounts','tuition_children','tuition_guardians','tuition_schedules','tuition_checkouts','tuition_lines','tuition_mails'] as $table) {
            DB::statement('CREATE TABLE `tuition_schema_probe` LIKE `'.$table.'`');
            expect(DB::selectOne('SHOW CREATE TABLE `tuition_schema_probe`')->{'Create Table'})->toContain('PRIMARY KEY');
            DB::statement('DROP TABLE `tuition_schema_probe`');
        }
    } finally {
        DB::statement('DROP TABLE IF EXISTS `tuition_schema_probe`');
        DB::statement('SET SESSION sql_require_primary_key = '.$prior);
    }
});
