<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Models\Event;
use App\Models\Masjid;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminGuideFindingsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15555550101']);
        $this->masjid = Masjid::create([
            'name' => 'Guide Test', 'email' => 'guide@example.test', 'phone' => '+15555550100',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0, 'longitude' => 0, 'user_id' => $admin->id,
        ]);
        $this->masjid->forceFill(['capability_overrides' => ['web_pages' => true, 'shop' => true]])->save();
        Sanctum::actingAs($admin);
    }

    #[Test]
    public function the_library_labels_every_type_exactly_as_the_add_section_palette_does(): void
    {
        foreach (SectionType::cases() as $type) {
            Section::create([
                'masjid_id' => $this->masjid->id, 'section_type' => $type,
                'title' => $type->value, 'content' => $type->defaultContent(), 'is_active' => true,
            ]);
        }

        $base = '/api/admin/masjids/'.$this->masjid->id;
        $palette = $this->getJson($base.'/section-types')->assertOk()->json('data');
        $labels = array_column($palette, 'label', 'value');
        $library = $this->getJson($base.'/sections')->assertOk()->json('data');
        $this->assertCount(count(SectionType::cases()), $library);
        foreach ($library as $section) {
            $this->assertSame($labels[$section['section_type']], $section['section_type_label'] ?? null);
        }
    }

    #[Test]
    public function creating_and_updating_events_accepts_an_empty_link_in_form_encoding(): void
    {
        $base = '/api/admin/masjids/'.$this->masjid->id.'/events';
        $body = ['title' => 'Circle', 'details' => 'Details', 'place' => 'Hall',
            'start' => '2099-01-05 19:00', 'end' => '2099-01-05 20:00', 'link' => ''];
        $id = $this->post($base, $body)->assertOk()->json('data.id');
        $this->assertNull(Event::findOrFail($id)->link);
        $body['title'] = 'Updated Circle';
        $this->put($base.'/'.$id, $body)->assertOk();
        $event = Event::findOrFail($id);
        $this->assertSame('Updated Circle', $event->title);
        $this->assertNull($event->link);

        $body['link'] = 'not a URL';
        $this->put($base.'/'.$id, $body)->assertStatus(422);
        $this->post($base, $body)->assertStatus(422);
    }
}
