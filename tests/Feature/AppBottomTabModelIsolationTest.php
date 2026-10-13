<?php

namespace Tests\Feature;

use App\Models\{AppBottomTab, Masjid};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppBottomTabModelIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function app_bottom_tab_hides_foreign_rows_and_stamps_the_bound_tenant(): void
    {
        $a = $this->makeMasjid('a'); $b = $this->makeMasjid('b');
        $tenant = app(TenantContext::class); $tenant->forgetTenant();
        $foreign = AppBottomTab::create(['masjid_id'=>$b->id, 'feature_id'=>null, 'position'=>1, 'saved_key'=>'removed', 'saved_title'=>'Removed']);
        $tenant->set($a->id);
        $this->assertNull(AppBottomTab::find($foreign->id));
        $this->assertSame(0, AppBottomTab::whereKey($foreign->id)->update(['saved_title'=>'Wrong']));
        $this->assertSame(0, AppBottomTab::whereKey($foreign->id)->delete());
        $own = AppBottomTab::create(['masjid_id'=>$b->id, 'feature_id'=>null, 'position'=>1, 'saved_key'=>'removed', 'saved_title'=>'Removed']);
        $this->assertSame($a->id, $own->masjid_id);
        $tenant->forgetTenant();
    }

    #[Test]
    public function preference_helper_refuses_unbound_or_mismatched_tenants_without_reading_or_deleting_rows(): void
    {
        $a = $this->makeMasjid('a'); $b = $this->makeMasjid('b');
        $b->forceFill(['capability_overrides'=>['app_bottom_tabs'=>true]])->save();
        $tenant = app(TenantContext::class); $tenant->forgetTenant();
        $foreign = AppBottomTab::create(['masjid_id'=>$b->id, 'feature_id'=>null, 'position'=>1, 'saved_key'=>'retired', 'saved_title'=>'Retired']);
        try {
            \App\Support\AppBottomTabs::editor();
            $this->fail('An unbound editor could read every organisation’s choices.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        foreach ([null, $a->id] as $id) {
            $id === null ? $tenant->forgetTenant() : $tenant->set($id);
            try {
                \App\Support\AppBottomTabs::save($b->id, []);
                $this->fail('An unbound or mismatched writer replaced choices.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $tenant->forgetTenant();
        $this->assertNotNull(AppBottomTab::find($foreign->id));
    }

    private function makeMasjid(string $suffix): Masjid
    {
        return Masjid::create(['name'=>'Practice '.$suffix, 'email'=>$suffix.'@example.invalid', 'phone'=>'+1555555011'.($suffix === 'a' ? '0' : '1'), 'country_id'=>'1', 'city_id'=>'1', 'address'=>'Practice', 'latitude'=>0, 'longitude'=>0]);
    }
}
