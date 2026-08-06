<?php

namespace Tests\Feature;

use App\Models\Flyer;
use App\Models\FlyerTemplate;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Can a masjid actually make a flyer through the API?
 *
 * The tenant-isolation suite proves the models keep masjids apart, and the
 * templates render correctly in a browser — but neither exercises the HTTP path
 * an admin actually takes. This walks it: list the designs, create a draft,
 * read it back, update it, delete it.
 *
 * The update assertion is the point of the suite. `$request->safe()->only()`
 * silently dropped `palette` (Laravel excludes array keys that carry child
 * rules), so every save wrote a flyer with its colours wiped and reported
 * success. That is invisible to a unit test of the request object and obvious
 * here, so it is pinned at this layer.
 */
class FlyerApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->masjid = Masjid::create([
            'name' => 'Flyer Masjid ' . uniqid(),
            'email' => 'flyer-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => 'America/New_York',
        ]);

        $this->seed(\Database\Seeders\FlyerTemplateSeeder::class);
    }

    private function actAsSuperAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]));
    }

    private function foodTemplate(): FlyerTemplate
    {
        return FlyerTemplate::withoutMasjidScope()->where('kind', 'food')->firstOrFail();
    }

    /** The eight slots the food design declares, filled the way the corpus does. */
    private function foodContent(): array
    {
        return [
            'title' => 'Fresh Homemade Chicken Karahi',
            'ingredients' => 'Chicken, Onion, Tomato, Yogurt, Garlic, Ginger, Curry, Cilantro, and Spices',
            'when' => "Friday (May 15th), After Juma'a Prayer",
            'price' => '$8 Each',
            'disclaimer' => 'All money goes to support masjid operations and community projects at the masjid.',
            'deadline' => 'All Orders must be placed by 11 AM May 15th',
            'cta' => 'Text 336-350-1642 to reserve yours today!',
        ];
    }

    private function palette(): array
    {
        return [
            'grad' => ['#A18CCF', '#C5A7CB', '#E0BCC8', '#EDD2D1'],
            'ink' => '#000000',
            'pillBg' => '#FFFFFF',
            'pillInk' => '#000000',
            'barBg' => '#000000',
            'barInk' => '#FFFFFF',
        ];
    }

    #[Test]
    public function the_seeded_designs_are_listed_for_a_masjid(): void
    {
        $this->actAsSuperAdmin();

        $res = $this->getJson("/api/admin/masjids/{$this->masjid->id}/flyer-templates");

        $res->assertOk()->assertJsonPath('status', 'success');
        $keys = collect($res->json('data'))->pluck('key')->all();

        $this->assertContains('food', $keys);
        $this->assertContains('janazah', $keys);
    }

    #[Test]
    public function a_draft_can_be_created_and_read_back(): void
    {
        $this->actAsSuperAdmin();

        $create = $this->postJson("/api/admin/masjids/{$this->masjid->id}/flyers", [
            'flyer_template_id' => $this->foodTemplate()->id,
            'title' => 'Chicken Karahi — May 15',
            'content' => $this->foodContent(),
            'palette' => $this->palette(),
        ]);

        $create->assertStatus(201)->assertJsonPath('status', 'success');
        $id = $create->json('data.id');
        $this->assertNotNull($id);

        // Stamped with the tenant, not with anything the client sent.
        $this->assertSame($this->masjid->id, Flyer::withoutMasjidScope()->find($id)->masjid_id);

        $show = $this->getJson("/api/admin/masjids/{$this->masjid->id}/flyers/{$id}");
        $show->assertOk()
            ->assertJsonPath('data.content.title', 'Fresh Homemade Chicken Karahi')
            ->assertJsonPath('data.content.cta', 'Text 336-350-1642 to reserve yours today!');
    }

    #[Test]
    public function updating_a_flyer_does_not_wipe_its_palette(): void
    {
        $this->actAsSuperAdmin();

        $id = $this->postJson("/api/admin/masjids/{$this->masjid->id}/flyers", [
            'flyer_template_id' => $this->foodTemplate()->id,
            'title' => 'Before',
            'content' => $this->foodContent(),
            'palette' => $this->palette(),
        ])->json('data.id');

        $this->putJson("/api/admin/masjids/{$this->masjid->id}/flyers/{$id}", [
            'title' => 'After',
            'content' => array_merge($this->foodContent(), ['price' => '$7 Each']),
            'palette' => $this->palette(),
        ])->assertOk();

        $stored = Flyer::withoutMasjidScope()->find($id);

        $this->assertSame('After', $stored->title);
        $this->assertSame('$7 Each', $stored->content['price']);
        // The regression this suite exists for: the palette must survive a save.
        $this->assertSame(
            ['#A18CCF', '#C5A7CB', '#E0BCC8', '#EDD2D1'],
            $stored->palette['grad'] ?? null,
            'the palette snapshot was wiped by the update'
        );
    }

    #[Test]
    public function content_that_does_not_match_the_template_is_rejected(): void
    {
        $this->actAsSuperAdmin();

        $res = $this->postJson("/api/admin/masjids/{$this->masjid->id}/flyers", [
            'flyer_template_id' => $this->foodTemplate()->id,
            'title' => 'Bogus',
            'content' => ['not_a_real_slot' => 'x'],
            'palette' => $this->palette(),
        ]);

        $res->assertStatus(422);
    }

    #[Test]
    public function a_flyer_can_be_deleted(): void
    {
        $this->actAsSuperAdmin();

        $id = $this->postJson("/api/admin/masjids/{$this->masjid->id}/flyers", [
            'flyer_template_id' => $this->foodTemplate()->id,
            'title' => 'Temp',
            'content' => $this->foodContent(),
            'palette' => $this->palette(),
        ])->json('data.id');

        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/flyers/{$id}")->assertOk();
        $this->assertNull(Flyer::withoutMasjidScope()->find($id));
    }
}
