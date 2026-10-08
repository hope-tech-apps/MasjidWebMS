<?php

use App\Models\{Masjid, SchoolYear, SchoolClosure, SchoolTerm};
use App\Support\{TenantContext, SchoolCalendar, SchoolCalendarSwitch};
use App\Http\Controllers\AdminDashboard\SchoolCalendarConfigurationController;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

require_once __DIR__.'/../Support/calendarMysqlDiagnostic.php';

function calendarLockFailure(QueryException $e, string $table, string $column): RuntimeException
{
    return new RuntimeException('SQLSTATE='.($e->errorInfo[0] ?? '?').' driver='.($e->errorInfo[1] ?? '?')." table=$table column=$column ".$e->getMessage(), 0, $e);
}

beforeEach(function () {
    return calendarMysqlDiagnostic(function () {
        app(TenantContext::class)->forgetTenant();
        $this->org = Masjid::create(['name' => 'Lock School '.uniqid(),'email' => uniqid().'@example.invalid','phone' => '+1'.random_int(1000000000,9999999999),'country_id' => '1','city_id' => '1','address' => '1 Test St','latitude' => 0,'longitude' => 0,'org_type' => 'school']);
        $this->org->forceFill(['capability_overrides' => ['school_calendar_terms' => true]])->save();
        $this->year = SchoolYear::create(['masjid_id' => $this->org->id,'label' => 'Test year','first_day' => '2026-10-12','last_day' => '2026-10-19','meeting_weekdays' => [1,2]]);
        config(['database.connections.calendar_other' => config('database.connections.'.config('database.default'))]);
        $this->other = DB::connection('calendar_other');
        $this->other->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }, 'masjids', 'name');
});

afterEach(function () {
    return calendarMysqlDiagnostic(function () {
        if (! isset($this->org)) return;
        while (DB::transactionLevel() > 0) DB::rollBack();
        DB::purge('calendar_other');
        DB::table('school_terms')->where('masjid_id',$this->org->id)->delete();
        DB::table('school_closures')->where('masjid_id',$this->org->id)->delete();
        DB::table('school_years')->where('masjid_id',$this->org->id)->delete();
        DB::table('masjids')->where('id',$this->org->id)->delete();
    }, 'school_years', 'masjid_id');
});

it('takes parent record locks before closure insert and blocks the register on the same year', function () {
    return calendarMysqlDiagnostic(function () {
        DB::beginTransaction();
        app(TenantContext::class)->set($this->org->id);
        app(SchoolCalendarConfigurationController::class)->writeClosure(['school_year_id' => $this->year->id,'closed_on' => '2026-10-13','reason' => 'Staff day'], $this->org->id);
        $locks = collect(DB::select("SELECT OBJECT_NAME AS t, INDEX_NAME AS i, LOCK_MODE AS m FROM performance_schema.data_locks WHERE LOCK_TYPE='RECORD' AND ENGINE_TRANSACTION_ID=(SELECT trx_id FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=CONNECTION_ID())"));
        foreach (['masjids','school_years'] as $table) {
            $row = $locks->where('t',$table)->first(fn ($r) => $r->i === 'PRIMARY' && $r->m === 'X,REC_NOT_GAP');
            expect($row)->not->toBeNull("Missing $table PRIMARY X,REC_NOT_GAP lock");
        }
        try {
            $this->other->transaction(fn () => $this->other->table('school_years')->where('id',$this->year->id)->lockForUpdate()->first());
            $this->fail('Register parent lock should wait');
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1205) throw calendarLockFailure($e,'school_years','id');
        }
        DB::commit();
        expect($this->other->table('school_closures')->where('school_year_id',$this->year->id)->value('closed_on'))->toBe('2026-10-13');
    }, 'school_years', 'id');
});

it('does not gap-lock another school child inserts while calendar parent locks are held', function () {
    return calendarMysqlDiagnostic(function () {
        DB::beginTransaction();
        Masjid::query()->whereKey($this->org->id)->lockForUpdate()->firstOrFail();
        SchoolYear::query()->whereKey($this->year->id)->lockForUpdate()->firstOrFail();
        try {
            // Different existing parent, resolved/committed before the first transaction in the other connection.
            $table = 'masjids'; $column = 'name';
            $foreign = $this->other->table('masjids')->insertGetId(['name'=>'Other Lock School '.uniqid(),'email'=>uniqid().'@example.invalid','phone'=>'+1'.random_int(1000000000,9999999999),'country_id'=>'1','city_id'=>'1','address'=>'1 Test St','latitude'=>0,'longitude'=>0,'org_type'=>'school','created_at'=>now(),'updated_at'=>now()]);
            $table = 'school_years'; $column = 'first_day';
            $fid = $this->other->table('school_years')->insertGetId(['masjid_id'=>$foreign,'label'=>'Other year','first_day'=>'2026-10-12','last_day'=>'2026-10-19','meeting_weekdays'=>'[1]','created_at'=>now(),'updated_at'=>now()]);
            $table = 'school_terms'; $column = 'school_year_id';
            $this->other->table('school_terms')->insert(['masjid_id'=>$foreign,'school_year_id'=>$fid,'name'=>'Term','starts_on'=>'2026-10-12','ends_on'=>'2026-10-19','position'=>1,'created_at'=>now(),'updated_at'=>now()]);
        } catch (QueryException $e) { throw calendarLockFailure($e,$table,$column); }
        finally {
            DB::rollBack();
            if (isset($foreign)) { $this->other->table('school_terms')->where('masjid_id',$foreign)->delete(); $this->other->table('school_years')->where('masjid_id',$foreign)->delete(); $this->other->table('masjids')->where('id',$foreign)->delete(); }
        }
    }, 'school_terms', 'school_year_id');
});

it('clones the actual terms definition with sql_require_primary_key enabled', function () {
    return calendarMysqlDiagnostic(function () {
        // Real migration against fresh temporary names. Always restore the session setting.
        $previous = (int) DB::selectOne('SELECT @@session.sql_require_primary_key AS value')->value;
        DB::statement('SET SESSION sql_require_primary_key = 1');
        try {
            DB::statement('CREATE TABLE `calendar_schema_probe` LIKE `school_terms`');
            expect(DB::selectOne('SHOW CREATE TABLE `calendar_schema_probe`')->{'Create Table'})->toContain('PRIMARY KEY');
        } catch (QueryException $e) {
            throw new RuntimeException('SQLSTATE='.($e->errorInfo[0] ?? '?').' driver='.($e->errorInfo[1] ?? '?').' table=calendar_schema_probe column=id '.$e->getMessage(), 0, $e);
        } finally {
            DB::statement('DROP TABLE IF EXISTS `calendar_schema_probe`');
            DB::statement('SET SESSION sql_require_primary_key = '.$previous);
        }
    }, 'calendar_schema_probe', 'id');
});

it('sees committed register marks after waiting for the year lock and refuses the closure', function () {
    return calendarMysqlDiagnostic(function () {
        $group = \App\Models\Group::factory()->create(['masjid_id'=>$this->org->id,'name'=>'Test class','slug'=>'lock-class-'.uniqid(),'kind'=>'class']);
        $child = \App\Models\Contact::factory()->create(['masjid_id'=>$this->org->id,'first_name'=>'Test','last_name'=>'Student']);
        $member = \App\Models\GroupMembership::create(['masjid_id'=>$this->org->id,'group_id'=>$group->id,'contact_id'=>$child->id,'role'=>'member']);
        $this->other->beginTransaction();
        $this->other->table('school_years')->where('id',$this->year->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            DB::transaction(fn () => app(SchoolCalendarConfigurationController::class)->writeClosure(['school_year_id'=>$this->year->id,'closed_on'=>'2026-10-13','reason'=>'Staff day'],$this->org->id));
            $this->fail('Closure should wait on register parent lock');
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1205) throw calendarLockFailure($e,'school_years','id');
        }
        try {
            $this->other->table('attendance_records')->insert(['masjid_id'=>$this->org->id,'group_id'=>$group->id,'group_membership_id'=>$member->id,'session_date'=>'2026-10-13','status'=>'present','created_at'=>now(),'updated_at'=>now()]);
            $this->other->commit();
            try {
                DB::transaction(fn () => app(SchoolCalendarConfigurationController::class)->writeClosure(['school_year_id'=>$this->year->id,'closed_on'=>'2026-10-13','reason'=>'Staff day'],$this->org->id));
                $this->fail('Committed attendance must block a closure');
            } catch (\Illuminate\Validation\ValidationException $e) {
                expect($e->errors()['closed_on'][0])->toContain('1 attendance mark');
            }
            expect(SchoolClosure::query()->where('school_year_id',$this->year->id)->count())->toBe(0);
        } catch (QueryException $e) { throw calendarLockFailure($e,'attendance_records','session_date'); }
        finally {
            if ($this->other->transactionLevel() > 0) $this->other->rollBack();
            DB::table('attendance_records')->where('masjid_id',$this->org->id)->delete();
            DB::table('group_memberships')->where('masjid_id',$this->org->id)->delete();
            DB::table('groups')->where('masjid_id',$this->org->id)->delete();
            DB::table('contacts')->where('masjid_id',$this->org->id)->delete();
        }
    }, 'school_years', 'id');
});
