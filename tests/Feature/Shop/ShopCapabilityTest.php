<?php

namespace Tests\Feature\Shop;

use App\Models\Masjid;
use App\Models\User;
use App\Support\CapabilityWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * The `shop` grant (shop slice B1): the switch the whole shop ships dark behind.
 *
 * It is a grant, OFF for every organisation of every type, so an organisation that is not given
 * it is byte for byte what it was. A SuperAdmin turns it on per organisation through the one
 * writer every grant uses, and the change is audited like every other grant. What the grant
 * GATES (a product line in a basket) is pinned beside the line source in ProductLineTest and
 * CartAddProductTest; this file pins only that the switch exists, is off, and is writable.
 */
class ShopCapabilityTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;

    #[Test]
    public function the_shop_is_off_for_every_organisation_type_until_a_superadmin_decides(): void
    {
        foreach (['masjid', 'school', 'community'] as $type) {
            $org = $this->org(['org_type' => $type]);

            $this->assertFalse($org->hasCapability('shop'), "a {$type} must not have the shop by default");
        }

        $this->assertSame('grant', config('capabilities.shop.kind'));
        $this->assertSame('registration_money', config('capabilities.shop.group'));
        $this->assertSame('Online shop', config('capabilities.shop.label'));
        $this->assertSame(['masjid' => false, 'school' => false, 'community' => false], config('capabilities.shop.defaults'));
        $this->assertTrue(config('capabilities.shop.listed_when_off'));
        $this->assertArrayHasKey(config('capabilities.shop.group'), config('capability_groups'));
    }

    #[Test]
    public function a_config_cache_from_before_the_grant_existed_reads_as_off(): void
    {
        // A deploy can serve new code from an old config:cache. A grant fails CLOSED.
        $org = $this->org();
        $org->forceFill(['capability_overrides' => ['shop' => true]])->save();
        $this->assertTrue($org->fresh()->hasCapability('shop'), 'premise: granted');

        config(['capabilities' => array_diff_key(config('capabilities'), ['shop' => true])]);

        $this->assertFalse($org->fresh()->hasCapability('shop'));
    }

    #[Test]
    public function it_is_writable_through_the_capability_writer_and_reads_back(): void
    {
        $org = $this->org();
        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);

        $this->assertFalse($org->fresh()->hasCapability('shop'));

        CapabilityWriter::apply($org, ['shop' => true], (int) $super->id);
        $this->assertTrue(Masjid::query()->find($org->id)->hasCapability('shop'));

        CapabilityWriter::apply($org->fresh(), ['shop' => false], (int) $super->id);
        $this->assertFalse(Masjid::query()->find($org->id)->hasCapability('shop'));

        $this->assertSame(
            2,
            DB::table('masjid_capability_changes')->where('masjid_id', $org->id)->where('capability', 'shop')->count(),
            'the switch is audited like every grant'
        );
    }
}
