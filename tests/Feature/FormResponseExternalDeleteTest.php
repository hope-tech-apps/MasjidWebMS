<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormResponseAttachment;
use App\Models\Masjid;
use App\Models\User;
use App\Support\FormAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A response imported from the school website (`external_ref`, alrazi:sync-website)
 * cannot be deleted from the admin — the next five-minute run would write it
 * straight back, documents and all, and staff would believe it was gone. It is
 * cancelled instead, exactly as a paid registration is.
 *
 * And the website's row id never leaves the server: the site hands that id to the
 * family's browser and it unlocks the site's payment step, so it is a bearer value.
 * It is absent from the admin's show and list payloads and from toArray(), and it
 * cannot be mass-assigned.
 *
 * Every value is obviously fake.
 */
class FormResponseExternalDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'test-external-ref-0001';

    private Masjid $masjid;

    private Form $form;

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

        Storage::fake($this->disk());

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $this->form = Form::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'test-imported-' . uniqid(),
            'name' => 'Test Imported Applications',
            'is_active' => false,
            'schema' => [
                'sections' => [
                    [
                        'id' => 'applicant',
                        'title' => 'Applicant',
                        'fields' => [
                            ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text'],
                            ['name' => 'resume', 'label' => 'Résumé', 'type' => 'file'],
                        ],
                    ],
                ],
            ],
            'settings' => [
                'confirmationEmail' => false,
                'identity' => ['name' => 'fullName'],
            ],
        ]);

        $admin = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        Sanctum::actingAs($admin);
    }

    private function disk(): string
    {
        return (string) config('forms.attachments.disk', 'local');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/forms/{$this->form->id}/responses{$suffix}";
    }

    private function makeResponse(?string $ref, string $name): FormResponse
    {
        $response = new FormResponse([
            'form_id' => $this->form->id,
            'masjid_id' => $this->masjid->id,
            'data' => ['fullName' => $name],
            'respondent_name' => $name,
            'status' => 'new',
            'submitted_at' => '2026-09-01 10:00:00',
        ]);
        // Not fillable: set the way the import sets it.
        $response->external_ref = $ref;
        $response->save();

        return $response;
    }

    private function withResume(FormResponse $response): FormResponseAttachment
    {
        FormAttachments::store($response, [
            'resume' => UploadedFile::fake()->create('test-resume.pdf', 20, 'application/pdf'),
        ]);

        return $response->attachments()->sole();
    }

    #[Test]
    public function an_imported_response_is_refused_and_keeps_its_files(): void
    {
        $imported = $this->makeResponse(self::REF, 'Test Imported');
        $attachment = $this->withResume($imported);

        $this->deleteJson($this->url("/{$imported->id}"))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Imported from the school website. Cancel it instead.');

        $this->assertNotNull(FormResponse::find($imported->id), 'the row is still there');
        $this->assertNotNull(FormResponseAttachment::find($attachment->id), 'and its attachment row');
        Storage::disk($this->disk())->assertExists($attachment->path);
        $this->assertSame(1, $this->form->fresh()->response_count);
    }

    #[Test]
    public function a_response_submitted_here_still_deletes(): void
    {
        $plain = $this->makeResponse(null, 'Test Submitted');
        $attachment = $this->withResume($plain);

        $this->deleteJson($this->url("/{$plain->id}"))->assertOk();

        $this->assertNull(FormResponse::find($plain->id));
        Storage::disk($this->disk())->assertMissing($attachment->path);
        $this->assertSame(0, $this->form->fresh()->response_count);
    }

    #[Test]
    public function the_website_id_is_absent_from_the_admin_payloads(): void
    {
        $imported = $this->makeResponse(self::REF, 'Test Imported');

        $show = $this->getJson($this->url("/{$imported->id}"))->assertOk();
        $this->assertStringNotContainsString(self::REF, $show->getContent());
        $this->assertStringNotContainsString('external_ref', $show->getContent());

        $index = $this->getJson($this->url())->assertOk();
        $this->assertStringNotContainsString(self::REF, $index->getContent());
        $this->assertStringNotContainsString('external_ref', $index->getContent());

        $this->assertArrayNotHasKey('external_ref', $imported->fresh()->toArray());
        $this->assertStringNotContainsString(self::REF, $imported->fresh()->toJson());
    }

    #[Test]
    public function the_website_id_cannot_be_mass_assigned(): void
    {
        $response = new FormResponse(['external_ref' => 'test-chosen-ref', 'form_id' => $this->form->id]);

        $this->assertNull($response->external_ref);
        $this->assertNotContains('external_ref', $response->getFillable());
        $this->assertNotContains('external_synced_at', $response->getFillable());
        $this->assertTrue($this->makeResponse(self::REF, 'Test Imported')->isExternal());
        $this->assertFalse($this->makeResponse(null, 'Test Submitted')->isExternal());
    }
}
