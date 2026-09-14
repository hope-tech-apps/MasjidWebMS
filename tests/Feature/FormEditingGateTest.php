<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Form WRITES take `web_pages` OR `form_editing` (DECISIONS.md 2026-09-16).
 *
 * Before the standalone form editor, an organisation's admins reached the
 * builder only inside Web Pages Management, so `web_pages` reproduces who could
 * write a form, and `form_editing` lets a coordinator edit the enrollment form
 * without the website builder. Everything else about forms stays open to every
 * admin: the list, the definition, Form Responses, staff codes, and the public
 * submit that families use.
 */
class FormEditingGateTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSAL = 'Website pages or Edit sign-up forms is not switched on for this organisation.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function org(string $orgType = 'school', array $overrides = []): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Forms Org ' . uniqid(),
            'email' => 'forms' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
            'timezone' => 'America/New_York',
        ]);

        if ($overrides !== []) {
            $masjid->forceFill(['capability_overrides' => $overrides])->save();
        }

        return $masjid;
    }

    private function actingAsAdminOf(Masjid $masjid): void
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        Sanctum::actingAs($user->fresh());
    }

    private function document(): array
    {
        return [
            'slug' => 'registration-' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Registration',
            'schema' => ['sections' => [[
                'id' => 'family', 'title' => 'Family',
                'fields' => [['name' => 'parentName', 'label' => 'Parent', 'type' => 'text', 'required' => true]],
            ]]],
            'settings' => ['identity' => ['name' => 'parentName']],
        ];
    }

    private function storedForm(Masjid $masjid): Form
    {
        return Form::create(['masjid_id' => $masjid->id] + $this->document());
    }

    private function formsUrl(Masjid $masjid, string $path = ''): string
    {
        return "/api/admin/masjids/{$masjid->id}/forms" . $path;
    }

    #[Test]
    public function without_either_grant_an_admin_reads_everything_and_writes_nothing(): void
    {
        $org = $this->org();
        $form = $this->storedForm($org);
        $this->actingAsAdminOf($org);

        $this->assertStringContainsString(self::REFUSAL,
            $this->postJson($this->formsUrl($org), $this->document())->assertForbidden()->getContent());
        $this->assertStringContainsString(self::REFUSAL,
            $this->putJson($this->formsUrl($org, "/{$form->id}"), ['name' => 'Renamed'])->assertForbidden()->getContent());
        $this->assertStringContainsString(self::REFUSAL,
            $this->deleteJson($this->formsUrl($org, "/{$form->id}"))->assertForbidden()->getContent());

        $this->assertSame(1, Form::where('masjid_id', $org->id)->count());
        $this->assertSame('Registration', $form->fresh()->name);

        // Reads, responses and staff codes: Form Responses is always on.
        $this->getJson($this->formsUrl($org))->assertOk();
        $this->getJson($this->formsUrl($org, '/options'))->assertOk();
        $this->getJson($this->formsUrl($org, '/field-types'))->assertOk();
        $this->getJson($this->formsUrl($org, "/{$form->id}"))->assertOk();
        $this->getJson($this->formsUrl($org, "/{$form->id}/responses"))->assertOk();
        $this->getJson($this->formsUrl($org, "/{$form->id}/staff-codes"))->assertOk();

        // And families still submit.
        $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => ['parentName' => 'Amal Yusuf'],
        ], ['masjid-id' => (string) $org->id])->assertOk();
    }

    #[Test]
    public function web_pages_or_form_editing_each_opens_the_writes(): void
    {
        foreach (['web_pages', 'form_editing'] as $grant) {
            $org = $this->org('school', [$grant => true]);
            $this->actingAsAdminOf($org);

            $id = $this->postJson($this->formsUrl($org), $this->document())->assertCreated()->json('data.id');
            $this->putJson($this->formsUrl($org, "/{$id}"), ['name' => 'Renamed'])->assertOk();
            $this->deleteJson($this->formsUrl($org, "/{$id}"))->assertOk();

            $this->assertSoftDeleted('forms', ['id' => $id]);
        }
    }

    #[Test]
    public function a_super_admin_writes_without_either_grant(): void
    {
        $org = $this->org();
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550009876'])->fresh());

        $id = $this->postJson($this->formsUrl($org), $this->document())->assertCreated()->json('data.id');
        $this->putJson($this->formsUrl($org, "/{$id}"), ['name' => 'Renamed'])->assertOk();
    }

    #[Test]
    public function the_biss_shape_edits_forms_with_the_website_switched_off(): void
    {
        // No website, no builder, just the enrollment form's editor.
        $org = $this->org('school', ['website' => false, 'web_pages' => false, 'form_editing' => true]);
        $form = $this->storedForm($org);
        $this->actingAsAdminOf($org);

        $this->putJson($this->formsUrl($org, "/{$form->id}"), ['name' => 'Enrollment 2026–27'])->assertOk();
        $this->getJson("/api/admin/masjids/{$org->id}/pages")->assertForbidden();
    }

    #[Test]
    public function only_the_three_write_routes_carry_the_gate(): void
    {
        $gate = 'capability:web_pages,form_editing';
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin/masjids/{masjid_id}/forms')) {
                continue;
            }

            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $key = $method . ' ' . $route->uri();
            $gated = in_array($gate, $route->gatherMiddleware(), true);

            if ($gated) {
                $found[] = $key;
            }
        }

        $this->assertEqualsCanonicalizing([
            'POST api/admin/masjids/{masjid_id}/forms',
            'PUT api/admin/masjids/{masjid_id}/forms/{form_id}',
            'DELETE api/admin/masjids/{masjid_id}/forms/{form_id}',
        ], $found);
    }
}
