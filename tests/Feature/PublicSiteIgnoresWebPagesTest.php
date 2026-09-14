<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The switches decide what an organisation's ADMINS can edit, never what its
 * public website serves (DECISIONS.md 2026-09-16).
 *
 * BISS is the shape: a school with no website builder for its admins
 * (web_pages off), the Web Pages Management screen switched off (website), and
 * every other module off too — while sundayschool.burlingtonmasjid.com keeps
 * serving its registration page and taking registrations through it. If a
 * public read ever started following a switch, the one page BISS has would go
 * dark the moment the owner tidied its sidebar.
 */
class PublicSiteIgnoresWebPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    #[Test]
    public function a_school_with_every_switch_off_still_serves_its_form_page_and_takes_a_response(): void
    {
        $school = Masjid::create([
            'name' => 'Sunday School ' . uniqid(),
            'email' => 'school' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
            'timezone' => 'America/New_York',
        ]);
        $school->forceFill([
            'capability_overrides' => array_fill_keys(Masjid::MODULE_KEYS, false) + ['web_pages' => false, 'form_editing' => false],
        ])->save();

        $this->assertSame(Masjid::MODULE_KEYS, $school->fresh()->modules_off);

        $form = Form::create([
            'masjid_id' => $school->id,
            'slug' => 'enrollment-' . uniqid(),
            'name' => 'Enrollment',
            'schema' => ['sections' => [[
                'id' => 'family', 'title' => 'Family',
                'fields' => [['name' => 'parentName', 'label' => 'Parent', 'type' => 'text', 'required' => true]],
            ]]],
            'settings' => ['identity' => ['name' => 'parentName']],
            'is_active' => true,
        ]);

        $page = Page::create([
            'masjid_id' => $school->id,
            'slug' => 'register',
            'title' => 'Register',
            'is_active' => true,
            'order' => 1,
        ]);
        $section = Section::create([
            'masjid_id' => $school->id,
            'section_type' => 'form',
            'title' => 'Registration',
            'content' => ['form_id' => $form->id, 'title' => '', 'intro' => ''],
            'is_active' => true,
        ]);
        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        $headers = ['masjid-id' => (string) $school->id, 'Accept' => 'application/json'];

        $content = $this->getJson('/api/v1/pages/register', $headers)
            ->assertOk()
            ->json('data.sections.0.content.form');

        $this->assertNotNull($content, 'the page stopped inlining its form');
        $this->assertTrue($content['accepting']);

        // Form-encoded, as the renderer's shared axios client sends it.
        $this->post("/api/v1/forms/{$form->id}/responses", [
            'data' => ['parentName' => 'Amal Yusuf'],
        ], $headers)->assertOk();

        $this->assertSame(1, FormResponse::where('form_id', $form->id)->count());
    }
}
