<?php

namespace Tests\Feature;

use App\Models\{AppBottomTab, Masjid, MobileAppFeature, User};
use App\Support\{CapabilityWriter, MobileCache, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Storage};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\SeedsAppFeatureCatalogue;
use Tests\TestCase;

class AppBottomTabsTest extends TestCase
{
    use RefreshDatabase, SeedsAppFeatureCatalogue;

    private Masjid $org;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-13 12:00:00 UTC'));
        Storage::fake('public');
        Cache::flush();
        $this->seedAppFeatureCatalogue();
        $this->admin = User::factory()->create(['phone' => '+15555550101', 'type' => 'MasjidAdmin', 'name' => 'Practice Admin', 'email' => 'admin@example.invalid']);
        $this->org = $this->makeMasjid('practice', $this->admin->id);
        foreach (MobileAppFeature::all() as $feature) $this->org->features()->attach($feature->id, ['is_available' => 1]);
    }

    private function makeMasjid(string $suffix, ?int $owner = null): Masjid
    {
        return Masjid::create(['name' => 'Practice '.$suffix, 'email' => $suffix.'@example.invalid', 'phone' => $suffix === 'practice' ? '+15555550100' : '+155555501'.(strlen($suffix) + 10), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'user_id' => $owner]);
    }

    private function url(): string { return '/api/admin/masjids/'.$this->org->id.'/app-bottom-tabs'; }
    private function on(bool $enabled = true): void
    {
        $this->org->forceFill(['capability_overrides' => ['app_bottom_tabs' => $enabled]])->save();
        MobileCache::flushMasjidAll($this->org->id);
    }
    private function office(): void { auth()->forgetGuards(); Sanctum::actingAs($this->admin); }
    private function payloads(): array
    {
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant();
        $out = [];
        foreach (['features' => "/api/mobile/masjids/{$this->org->id}/features", 'show' => "/api/mobile/masjids/{$this->org->id}", 'settings' => '/api/v1/settings', 'menu' => "/api/mobile/masjids/{$this->org->id}/menu", 'orgs' => "/api/mobile/masjids/{$this->org->id}/orgs"] as $name => $url) {
            DB::enableQueryLog(); DB::flushQueryLog();
            $r = $this->getJson($url, ['masjid-id' => (string) $this->org->id])->assertOk();
            $out[$name] = ['body' => $r->getContent(), 'sql' => array_column(DB::getQueryLog(), 'query')];
            DB::disableQueryLog();
        }
        return $out;
    }

    #[Test]
    public function off_is_literal_base_and_new_routes_404_for_both_admin_types(): void
    {
        $expected = json_decode(file_get_contents(base_path('tests/fixtures/app-bottom-tabs-off-b531a07d.json')), true);
        $this->assertSame($expected['cold'], $this->payloads());
        $this->assertSame($expected['warm'], $this->payloads());
        foreach ([$this->admin, User::factory()->create(['phone' => '+15555550101', 'type' => 'SuperAdmin'])] as $user) {
            auth()->forgetGuards(); Sanctum::actingAs($user);
            $this->getJson($this->url())->assertNotFound();
            $this->putJson($this->url(), ['feature_ids' => []])->assertNotFound();
        }
        foreach (Masjid::ORG_TYPES as $type) {
            $this->org->forceFill(['org_type' => $type])->save();
            $this->assertFalse($this->org->hasCapability('app_bottom_tabs'));
            $this->assertArrayNotHasKey('app_bottom_tabs', $this->org->capabilities);
        }
    }

    #[Test]
    public function chosen_tabs_are_identical_additions_on_every_reader_with_one_extra_query(): void
    {
        $off = $this->payloads(); $offWarm = $this->payloads();
        $this->on(); $this->office();
        $this->putJson($this->url(), ['feature_ids' => [3, 1]])->assertOk();
        Cache::flush();
        foreach ([$off, $offWarm] as $base) {
            $on = $this->payloads();
            foreach (['show', 'settings'] as $name) {
                $body = json_decode($on[$name]['body'], true);
                $tabs = $name === 'features' ? $body['bottom_tabs'] : $body['data']['bottom_tabs'];
                $this->assertSame([['id' => 3, 'key' => 'adhkar', 'name' => 'Adhkar'], ['id' => 1, 'key' => "qur\u{2019}an", 'name' => "Qur\u{2019}an"]], $tabs);
                if ($name === 'features') unset($body['bottom_tabs']); else unset($body['data']['bottom_tabs']);
                $this->assertSame($base[$name]['body'], json_encode($body));
                $newSql = $on[$name]['sql'];
                $added = array_values(array_filter($newSql, fn ($q) => str_contains($q, 'app_bottom_tabs')));
                $this->assertCount(1, $added);
                $this->assertSame($base[$name]['sql'], array_values(array_filter($newSql, fn ($q) => ! str_contains($q, 'app_bottom_tabs'))));
            }
            foreach (['features', 'menu', 'orgs'] as $name) $this->assertSame($base[$name], $on[$name]);
        }
    }

    #[Test]
    public function enabled_empty_tabs_add_no_sql_and_features_never_carries_tabs(): void
    {
        $off = $this->payloads(); $warm = $this->payloads();
        $this->on(); Cache::flush();
        $this->assertSame($off, $this->payloads());
        $this->assertSame($warm, $this->payloads());
        $this->office(); $this->putJson($this->url(), ['feature_ids'=>[1]])->assertOk();
        $this->assertSame($warm['features'], $this->payloads()['features']);
    }

    #[Test]
    public function delete_then_insert_failure_restores_choices_and_presence(): void
    {
        $this->on(); $this->office(); $this->putJson($this->url(), ['feature_ids'=>[2,1]])->assertOk();
        $dispatcher = AppBottomTab::getEventDispatcher();
        AppBottomTab::setEventDispatcher(clone $dispatcher);
        AppBottomTab::creating(function () { throw new \RuntimeException('Injected after delete'); });
        try {
            \App\Support\AppBottomTabs::save($this->org->id, [3]);
            $this->fail('Injected mutation failure was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected after delete', $e->getMessage());
        } finally { AppBottomTab::setEventDispatcher($dispatcher); }
        $this->assertSame([2,1], AppBottomTab::orderBy('position')->pluck('feature_id')->all());
        $this->assertTrue((bool) ($this->org->fresh()->capability_overrides['_app_bottom_tabs_present'] ?? false));
    }

    #[Test]
    public function a_fill_started_before_save_cannot_republish_under_the_current_show_key(): void
    {
        $oldKey = MobileCache::masjidKey($this->org->id, MobileCache::SHOW);
        $old = $this->org->fresh();
        $this->on(); $this->office(); $this->putJson($this->url(), ['feature_ids'=>[1]])->assertOk();
        Cache::put($oldKey, $old, MobileCache::TTL_MEDIUM); // paused fill resumes after commit
        $this->assertNotSame($oldKey, MobileCache::masjidKey($this->org->id, MobileCache::SHOW));
        $this->getJson('/api/mobile/masjids/'.$this->org->id)->assertOk()->assertJsonPath('data.bottom_tabs.0.id', 1);
        MobileAppFeature::findOrFail(1)->update(['key'=>'renamed', 'name'=>'Renamed']);
        $this->getJson('/api/mobile/masjids/'.$this->org->id)->assertOk()->assertJsonPath('data.bottom_tabs.0.name', 'Renamed');
    }

    #[Test]
    public function renaming_a_recently_created_catalogue_instance_still_invalidates_the_detail_cache(): void
    {
        $feature=MobileAppFeature::create(['key'=>'practice','name'=>'Practice']);$this->org->features()->attach($feature->id,['is_available'=>true]);
        $this->on();$this->office();$this->putJson($this->url(),['feature_ids'=>[$feature->id]])->assertOk();$this->payloads();
        $before=MobileCache::masjidKey($this->org->id,MobileCache::SHOW);$feature->update(['key'=>'renamed-practice','name'=>'Renamed practice']);
        $this->assertNotSame($before,MobileCache::masjidKey($this->org->id,MobileCache::SHOW));
    }

    #[Test]
    public function old_opted_in_feature_cache_is_unwrapped_to_the_main_body(): void
    {
        $cold=$this->payloads();
        $key=MobileCache::masjidKey($this->org->id,MobileCache::FEATURES);
        $features=Cache::get($key);
        Cache::put($key,['features'=>$features,'tabs_enabled'=>true],600);
        $this->assertSame($cold['features']['body'],$this->getJson('/api/mobile/masjids/'.$this->org->id.'/features')->assertOk()->getContent());
    }

    #[Test]
    public function database_cache_off_and_on_empty_have_identical_query_lists_and_no_extra_lookup(): void
    {
        Cache::setDefaultDriver('database');Cache::flush();
        $read=function(){auth()->forgetGuards();app(TenantContext::class)->forgetTenant();DB::enableQueryLog();DB::flushQueryLog();$r=$this->getJson('/api/mobile/masjids/'.$this->org->id)->assertOk();$sql=array_column(DB::getQueryLog(),'query');DB::disableQueryLog();return ['body'=>$r->getContent(),'sql'=>$sql];};
        $off=$read();$warm=$read();$this->assertCount(9,$off['sql']);$this->assertCount(1,$warm['sql']);
        $this->on();Cache::flush();$this->assertSame($off,$read());$this->assertSame($warm,$read());
        $this->office();$this->putJson($this->url(),['feature_ids'=>[1]])->assertOk();
        Cache::flush();$on=$read();$this->assertCount(1,array_filter($on['sql'],fn($q)=>str_contains($q,'app_bottom_tabs')));
        $this->assertSame($off['sql'],array_values(array_filter($on['sql'],fn($q)=>!str_contains($q,'app_bottom_tabs'))));
        $this->assertCount(2,$read()['sql']);
        file_put_contents(base_path('artifacts/app101-database-cache-sql.json'),json_encode(['off'=>$off['sql'],'warm'=>$warm['sql'],'chosen'=>$on['sql']],JSON_PRETTY_PRINT));
    }

    #[Test]
    public function database_cache_fill_racing_save_cannot_overwrite_the_invalidation_revision(): void
    {
        Cache::setDefaultDriver('database');Cache::flush();$old=$this->org->fresh();
        \App\Support\ShowCache::remember($this->org->id,function()use($old){
            $this->on();$this->office();$this->putJson($this->url(),['feature_ids'=>[1]])->assertOk();return $old;
        });
        $this->getJson('/api/mobile/masjids/'.$this->org->id)->assertOk()->assertJsonPath('data.bottom_tabs.0.id',1);
        $this->getJson('/api/mobile/masjids/'.$this->org->id)->assertOk()->assertJsonPath('data.bottom_tabs.0.id',1);
    }

    #[Test]
    public function empty_or_all_unavailable_is_absent_and_never_an_empty_array(): void
    {
        $this->on(); $this->office(); $this->putJson($this->url(), ['feature_ids' => []])->assertOk();
        foreach (['features', 'show', 'settings'] as $name) $this->assertStringNotContainsString('bottom_tabs', $this->payloads()[$name]['body']);
        $this->office(); $this->putJson($this->url(), ['feature_ids' => [1]])->assertOk();
        $this->org->features()->updateExistingPivot(1, ['is_available' => 0]);
        foreach (['features', 'show', 'settings'] as $name) $this->assertStringNotContainsString('bottom_tabs', $this->payloads()[$name]['body']);
    }

    #[Test]
    public function writes_refuse_five_duplicates_disabled_foreign_and_home_and_keep_saved_order(): void
    {
        $this->on(); $this->office();
        $this->putJson($this->url(), ['feature_ids' => [4, 2, 1]])->assertOk();
        $this->org->features()->updateExistingPivot(6, ['is_available' => 0]);
        $foreign = MobileAppFeature::create(['key' => 'foreign', 'name' => 'Foreign']);
        $other = $this->makeMasjid('other'); $other->features()->attach($foreign->id, ['is_available' => 1]);
        $homeId = DB::table('mobile_app_features')->insertGetId(['key'=>'home','name'=>'Home']); $home = MobileAppFeature::findOrFail($homeId); $this->org->features()->attach($home->id, ['is_available' => 1]);
        foreach ([[1,2,3,4,5], [1,1], [6], [$foreign->id], [$home->id], [999999], ['bad']] as $ids) {
            $this->putJson($this->url(), ['feature_ids' => $ids])->assertUnprocessable();
            $this->assertSame([4,2,1], AppBottomTab::orderBy('position')->pluck('feature_id')->all());
        }
        $this->putJson($this->url(), ['feature_ids' => [1,4,2]])->assertOk();
        $this->assertSame([1,4,2], AppBottomTab::orderBy('position')->pluck('feature_id')->all());
        // The SPA sends an explicit JSON header; form encodings are still accepted.
        $this->put($this->url(), ['feature_ids' => ['2','1']], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame([2,1], AppBottomTab::orderBy('position')->pluck('feature_id')->all());
    }

    #[Test]
    public function platform_disable_and_feature_deletion_retain_unavailable_choices_until_save(): void
    {
        $this->on(); $this->office(); $this->putJson($this->url(), ['feature_ids' => [3,1,2]])->assertOk();
        $this->payloads();
        auth()->forgetGuards(); Sanctum::actingAs(User::factory()->create(['phone' => '+15555550101', 'type' => 'SuperAdmin']));
        $this->putJson("/api/admin/masjids/{$this->org->id}/features/1", ['is_available' => false])->assertOk();
        // Delete the pivot first, as the platform's catalogue delete does; tabs must not block it.
        DB::table('masjid_mobile_app_features')->where('feature_id', 2)->delete();
        MobileAppFeature::findOrFail(2)->delete();
        $this->office(); $r = $this->getJson($this->url())->assertOk();
        $this->assertSame([true,false,false], array_column($r->json('data.chosen'), 'available'));
        $this->assertSame('Hadith', $r->json('data.chosen.2.name'));
        $this->assertCount(3, AppBottomTab::all());
        foreach (['show', 'settings'] as $name) {
            $body = json_decode($this->payloads()[$name]['body'], true);
            $this->assertSame([['id'=>3,'key'=>'adhkar','name'=>'Adhkar']], $name === 'features' ? $body['bottom_tabs'] : $body['data']['bottom_tabs']);
        }
        $this->office(); $this->putJson($this->url(), ['feature_ids' => [3]])->assertOk();
        $this->assertCount(1, AppBottomTab::all());
    }

    #[Test]
    public function child_uses_only_its_own_choices_and_grant(): void
    {
        $this->on(); $this->office(); $this->putJson($this->url(), ['feature_ids'=>[1]])->assertOk();
        app(TenantContext::class)->forgetTenant();
        $parent = $this->org; $child = $this->makeMasjid('child');
        $child->forceFill(['parent_id'=>$parent->id])->save();
        $child->features()->attach(2, ['is_available'=>1]);
        $this->org = $child;
        $this->assertStringNotContainsString('bottom_tabs', $this->payloads()['show']['body']);
        $this->on(); auth()->forgetGuards(); Sanctum::actingAs(User::factory()->create(['phone' => '+15555550101', 'type'=>'SuperAdmin']));
        $this->putJson($this->url(), ['feature_ids'=>[2]])->assertOk();
        $this->assertSame([['id'=>2,'key'=>'hadith','name'=>'Hadith']], json_decode($this->payloads()['show']['body'],true)['data']['bottom_tabs']);
        $this->org = $parent;
        $this->assertSame(1, json_decode($this->payloads()['show']['body'],true)['data']['bottom_tabs'][0]['id']);
    }

    #[Test]
    public function editor_reuses_the_request_organisation_when_volunteer_is_off(): void
    {
        $this->on();$this->office();$this->getJson($this->url())->assertOk();
        DB::enableQueryLog();DB::flushQueryLog();$this->getJson($this->url())->assertOk();$sql=array_column(DB::getQueryLog(),'query');DB::disableQueryLog();
        $this->assertCount(1,array_filter($sql,fn($q)=>str_contains($q,'from "masjids"')));
    }
    #[Test]
    public function admin_role_and_tenant_fences_match_general_settings(): void
    {
        $this->on();
        $other = $this->makeMasjid('other');
        $this->office(); $this->getJson($this->url())->assertOk();
        $this->getJson("/api/admin/masjids/{$other->id}/app-bottom-tabs")->assertForbidden();
        $this->putJson("/api/admin/masjids/{$other->id}/app-bottom-tabs", ['feature_ids'=>[1]])->assertForbidden();
        foreach (['Teacher','LunchStaff','AppUser'] as $type) {
            auth()->forgetGuards(); Sanctum::actingAs(User::factory()->create(['phone' => '+15555550101', 'type'=>$type]));
            $this->getJson($this->url())->assertUnauthorized();
            $this->putJson($this->url(), ['feature_ids'=>[1]])->assertUnauthorized();
        }
    }

    #[Test]
    public function soft_delete_retains_and_force_delete_cascades_even_with_switch_off(): void
    {
        $this->on(); $this->office(); $this->putJson($this->url(), ['feature_ids'=>[1]])->assertOk();
        app(TenantContext::class)->forgetTenant(); $this->on(false);
        $this->org->delete(); $this->assertSame(1, AppBottomTab::count());
        $this->org->restore(); $this->assertSame(1, AppBottomTab::count());
        $this->org->forceDelete(); $this->assertSame(0, AppBottomTab::count());
    }

    #[Test]
    public function writer_transaction_starts_with_one_organisation_mutex_and_failure_rolls_back(): void
    {
        $this->on(); $this->office();
        $start = DB::transactionLevel(); $statements = [];
        DB::listen(function ($query) use (&$statements, $start) {
            if (DB::transactionLevel() > $start) $statements[] = $query->sql;
        });
        $this->putJson($this->url(), ['feature_ids'=>[4,3,2,1]])->assertOk();
        $this->assertStringContainsString('from "masjids" where "masjids"."id"', $statements[0]);
        $this->assertCount(1, array_filter($statements, fn ($q) => str_contains($q, 'from "masjids"')));
        $this->assertSame([1,2,3,4], AppBottomTab::orderBy('position')->pluck('position')->all());
        $statements = [];
        $this->putJson("/api/admin/masjids/{$this->org->id}/features/4", ['is_available'=>false])->assertUnauthorized();
        auth()->forgetGuards(); Sanctum::actingAs(User::factory()->create(['phone'=>'+15555550130', 'type'=>'SuperAdmin']));
        $this->putJson("/api/admin/masjids/{$this->org->id}/features/4", ['is_available'=>false])->assertOk();
        $this->assertStringContainsString('from "masjids" where "masjids"."id"', $statements[0]);
        $this->assertCount(1, array_filter($statements, fn ($q) => str_contains($q, 'from "masjids"')));
        $this->office(); $this->putJson($this->url(), ['feature_ids'=>[4]])->assertUnprocessable();
        $this->assertSame([4,3,2,1], AppBottomTab::orderBy('position')->pluck('feature_id')->all());
    }

    #[Test]
    public function feature_and_pivot_delete_paths_cannot_block_and_keep_unavailable_order(): void
    {
        $this->on(); $this->office(); $this->putJson($this->url(), ['feature_ids'=>[1,2,3]])->assertOk();
        $this->payloads();
        // Direct catalogue deletion cascades its pivot and nulls only the feature reference.
        MobileAppFeature::findOrFail(1)->delete();
        // A bulk catalogue deletion also bypasses model events and must have the same retention.
        DB::table('mobile_app_features')->where('id', 2)->delete();
        $this->office(); $this->getJson($this->url())->assertOk()->assertJsonPath('data.chosen.0.available', false)
            ->assertJsonPath('data.chosen.1.available', false)->assertJsonPath('data.chosen.2.feature_id', 3);
        $this->assertSame([null,null,3], AppBottomTab::orderBy('position')->pluck('feature_id')->all());
        $this->assertSame('text', \Illuminate\Support\Facades\Schema::getColumnType('app_bottom_tabs','saved_key'));
        $this->assertSame('text', \Illuminate\Support\Facades\Schema::getColumnType('app_bottom_tabs','saved_title'));
        $indexes = \Illuminate\Support\Facades\Schema::getIndexes('app_bottom_tabs');
        $this->assertTrue(collect($indexes)->contains(fn ($i) => $i['primary']));
        foreach ($indexes as $index) $this->assertLessThanOrEqual(64, strlen($index['name']));
    }

    #[Test]
    public function disabled_grant_is_hidden_in_admin_panel_raw_payload_team_and_studio(): void
    {
        auth()->forgetGuards(); Sanctum::actingAs(User::factory()->create(['phone'=>'+15555550131', 'type'=>'SuperAdmin']));
        $panel = $this->getJson("/api/admin/masjids/{$this->org->id}/capabilities")->assertOk();
        $this->assertStringNotContainsString('app_bottom_tabs', $panel->getContent());
        $this->on();
        $panel = $this->getJson("/api/admin/masjids/{$this->org->id}/capabilities")->assertOk();
        $this->assertStringContainsString('app_bottom_tabs', $panel->getContent());
        $this->assertContains('app_bottom_tabs', array_keys(array_filter($this->org->capabilities)));
        $this->on(false);
        $this->assertStringNotContainsString('app_bottom_tabs', $this->org->toJson());
        $this->assertNotContains('app_bottom_tabs', array_keys(array_filter($this->org->capabilities)));
        $before = DB::table('masjid_capability_changes')->count();
        CapabilityWriter::apply($this->org, ['app_bottom_tabs'=>false], $this->admin->id);
        $this->assertSame($before, DB::table('masjid_capability_changes')->count());
    }

    #[Test]
    public function grant_writer_supports_every_vertical_but_keeps_off_noop_hidden(): void
    {
        foreach (Masjid::ORG_TYPES as $type) {
            $this->org->forceFill(['org_type'=>$type])->save();
            CapabilityWriter::apply($this->org, ['app_bottom_tabs'=>true], $this->admin->id);
            $this->assertTrue($this->org->fresh()->hasCapability('app_bottom_tabs'));
            CapabilityWriter::apply($this->org, ['app_bottom_tabs'=>false], $this->admin->id);
            $this->assertArrayNotHasKey('app_bottom_tabs', $this->org->fresh()->capabilities);
        }
    }
}
