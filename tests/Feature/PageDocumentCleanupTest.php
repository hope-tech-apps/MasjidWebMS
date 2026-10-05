<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Support\PageDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * What happens to a page document after it is uploaded (App\Support\PageDocuments::forgetUnlinked).
 *
 * The rule the office is told: a document stays online while a saved section links to it; clear or
 * replace its address and save, or delete the section from the library, and it is taken offline.
 *
 * This is code that DELETES PUBLIC FILES on an ordinary save, on a platform that has lost media to a
 * deletion by inference before. So each test asserts the media row AND the file, and the second half
 * of the file is the guards: what it must never delete, whatever a section's content says.
 */
class PageDocumentCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_DISK_URL = 'https://platform.example.test/storage';

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        . "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private const DELETED_LOG = 'Page document deleted: no saved section links it any more';

    private Masjid $masjid;

    private User $admin;

    private Page $page;

    /** Every temporary file this test wrote; a stored upload has already been moved off its path. */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // The fake drops the real disk's `url`; production's is absolute, and so is this one.
        Storage::fake('public', ['url' => self::PUBLIC_DISK_URL]);

        $this->masjid = $this->organisation();
        $this->admin = $this->adminOf($this->masjid);
        $this->page = $this->page($this->masjid, 'documents');

        Sanctum::actingAs($this->admin);
    }

    /* ------------------------------------------------------ taken offline */

    #[Test]
    public function replacing_a_documents_address_and_saving_deletes_the_old_file_and_keeps_the_new_one(): void
    {
        $old = $this->uploadDocument('Calendar 2025.pdf');
        $section = $this->saveLinkList([$old['url']]);

        $new = $this->uploadDocument('Calendar 2026.pdf');
        $this->assertNotSame($old['url'], $new['url'], 'a new upload is a new address, never new bytes behind the old one');

        $this->updateLinkList($section, [$new['url']]);

        $this->assertDocumentGone($old);
        $this->assertDocumentKept($new);
    }

    #[Test]
    public function clearing_a_documents_address_and_saving_deletes_the_file(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);
        $this->assertDocumentKept($document);

        $this->updateLinkList($section, ['']);

        $this->assertDocumentGone($document);
    }

    #[Test]
    public function removing_the_button_that_linked_a_document_deletes_the_file_and_leaves_the_other_buttons_documents(): void
    {
        $first = $this->uploadDocument('Curriculum.pdf');
        $second = $this->uploadDocument('Schedule.pdf');
        $section = $this->saveLinkList([$first['url'], $second['url']]);

        // The first button is removed; the second moves up into its place.
        $this->updateLinkList($section, [$second['url']]);

        $this->assertDocumentGone($first);
        $this->assertDocumentKept($second);
    }

    #[Test]
    public function a_document_two_sections_link_is_kept_until_the_last_of_them_lets_go(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $one = $this->saveLinkList([$document['url']]);
        $two = $this->saveSection('cta', $this->cta($document['url']));

        $this->updateLinkList($one, ['']);
        $this->assertDocumentKept($document);

        $this->updateSection($two, $this->cta(''));
        $this->assertDocumentGone($document);
    }

    #[Test]
    public function a_section_that_is_switched_off_or_only_in_the_library_still_keeps_its_document_online(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $onPage = $this->saveLinkList([$document['url']]);

        // In the library only, and inactive: still a saved section that links the document.
        $this->postJson("/api/admin/masjids/{$this->masjid->id}/sections", [
            'section_type' => 'cta',
            'content' => $this->cta($document['url']),
            'is_active' => false,
        ])->assertStatus(201);

        $this->updateLinkList($onPage, ['']);

        $this->assertDocumentKept($document);
    }

    #[Test]
    public function changing_a_sections_type_deletes_the_documents_its_old_content_linked(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        // A change of type starts the content afresh, so the address is gone with the buttons.
        $this->post("{$this->pageSections()}/{$section}", [
            '_method' => 'PUT',
            'section_type' => 'text',
            'content' => json_encode(['content' => '<p>Nothing to download.</p>']),
        ])->assertStatus(200);

        $this->assertDocumentGone($document);
    }

    #[Test]
    public function deleting_the_section_from_the_library_deletes_its_documents(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $elsewhere = $this->uploadDocument('Schedule.pdf');
        $section = $this->saveLinkList([$document['url'], $elsewhere['url']]);
        $this->saveSection('cta', $this->cta($elsewhere['url']));

        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/sections/{$section}")->assertStatus(200);

        $this->assertDocumentGone($document);
        // Another section still links this one.
        $this->assertDocumentKept($elsewhere);
    }

    /* ------------------------------------------------------ kept, on purpose */

    #[Test]
    public function taking_a_section_off_a_page_keeps_its_document(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        // The section stays in the library, and the dialog says so.
        $this->deleteJson("{$this->pageSections()}/{$section}")->assertStatus(200);

        $this->assertSame(1, Section::whereKey($section)->count());
        $this->assertDocumentKept($document);
    }

    #[Test]
    public function deleting_the_page_keeps_its_sections_documents(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $this->saveLinkList([$document['url']]);

        // A page delete is a soft delete and its sections stay in the library.
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/pages/{$this->page->id}")->assertStatus(200);

        $this->assertDocumentKept($document);
    }

    #[Test]
    public function a_document_that_was_uploaded_and_never_saved_is_kept(): void
    {
        $neverSaved = $this->uploadDocument('Wrong File.pdf');
        $saved = $this->uploadDocument('Calendar.pdf');

        // Every kind of write that runs the cleanup, none of which ever linked the first file.
        $section = $this->saveLinkList([$saved['url']]);
        $this->updateLinkList($section, ['']);
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/sections/{$section}")->assertStatus(200);

        $this->assertDocumentGone($saved);
        // No sweeper: nothing here deletes a public file because nothing links it.
        $this->assertDocumentKept($neverSaved);
    }

    #[Test]
    public function a_save_that_does_not_touch_the_content_or_keeps_the_address_deletes_nothing(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        // The title alone.
        $this->post("{$this->pageSections()}/{$section}", ['_method' => 'PUT', 'title' => 'Downloads'])->assertStatus(200);
        // The heading alone: the page-section route keeps the stored keys a request leaves out.
        $this->post("{$this->pageSections()}/{$section}", [
            '_method' => 'PUT',
            'content' => json_encode(['heading' => 'Downloads']),
        ])->assertStatus(200);
        // The same address again, with a query and a fragment a person added.
        $this->updateLinkList($section, [$document['url'] . '?v=2#page=3']);

        $this->assertDocumentKept($document);
    }

    /* ---------------------------------------------------------------- guards */

    #[Test]
    public function a_document_is_recognised_by_its_path_whatever_host_or_scheme_is_written_in_front(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $path = parse_url($document['url'], PHP_URL_PATH);

        // Kept alive by a section that wrote it under another host and plain http.
        $one = $this->saveLinkList([$document['url']]);
        $two = $this->saveSection('cta', $this->cta('http://another-host.example.test' . $path));
        $this->updateLinkList($one, ['']);
        $this->assertDocumentKept($document);

        // And taken offline when THAT section lets go: it is this organisation's file all the same.
        $this->updateSection($two, $this->cta(''));
        $this->assertDocumentGone($document);
    }

    #[Test]
    public function a_document_linked_from_text_inside_a_section_is_a_linked_document(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $text = $this->saveSection('text', ['content' => '<p>Read <a href="' . $document['url'] . '">the calendar</a>.</p>']);
        $buttons = $this->saveLinkList([$document['url']]);

        $this->updateLinkList($buttons, ['']);
        $this->assertDocumentKept($document);

        $this->updateSection($text, ['content' => '<p>The calendar is at the office.</p>']);
        $this->assertDocumentGone($document);
    }

    #[Test]
    public function another_organisations_document_pasted_here_and_removed_is_never_touched(): void
    {
        // The other organisation uploads, and links its own document from its own page.
        $other = $this->organisation();
        Sanctum::actingAs($this->adminOf($other));
        $theirs = $this->uploadDocument('Their Calendar.pdf', $other);
        $otherPage = $this->page($other, 'theirs');
        $theirSection = $this->post("/api/admin/masjids/{$other->id}/pages/{$otherPage->id}/sections", [
            'section_type' => 'link_list',
            'content' => json_encode($this->linkList([$theirs['url']])),
            'order' => 1,
        ])->assertStatus(201)->json('data.id');

        // This organisation pastes that address into its own section, then removes it, then deletes
        // the section. A public address is public; the file is not this organisation's to delete.
        Sanctum::actingAs($this->admin);
        $section = $this->saveLinkList([$theirs['url']]);
        $this->updateLinkList($section, ['']);
        $this->updateLinkList($section, [$theirs['url']]);
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/sections/{$section}")->assertStatus(200);

        $this->assertDocumentKept($theirs, $other);

        // Even when the other organisation itself no longer links it from anywhere.
        Section::whereKey($theirSection)->delete();
        $again = $this->saveLinkList([$theirs['url']]);
        $this->updateLinkList($again, ['']);

        $this->assertDocumentKept($theirs, $other);
    }

    #[Test]
    public function a_file_that_is_not_this_organisations_page_document_is_never_deleted_whatever_the_content_says(): void
    {
        $bystander = $this->saveSection('text', ['content' => '<p>Unrelated.</p>']);

        // Three files that are NOT page documents of this organisation, each given a `.pdf` name on
        // purpose so only the ownership test stands between them and the delete:
        //  - a section's own upload (collection section_images, owned by the Section),
        //  - a gallery photo (owned by the organisation, another collection),
        //  - a `page_documents` row owned by ANOTHER KIND of model whose id equals this
        //    organisation's (the half-key that once read across tenants: MasjidMediaModelTypeTest).
        $sectionImage = Section::findOrFail($bystander)->addMedia($this->file('brochure.pdf'))
            ->usingFileName('brochure.pdf')->toMediaCollection('section_images');
        $galleryPhoto = $this->masjid->addMedia($this->file('gallery.pdf'))
            ->usingFileName('gallery.pdf')->toMediaCollection('galleries');
        $sameId = (new Section())->forceFill([
            'id' => $this->masjid->id + 1000, 'masjid_id' => $this->masjid->id,
            'section_type' => 'text', 'content' => ['content' => ''], 'is_active' => true,
        ]);
        $sameId->save();
        $foreignOwner = $sameId->addMedia($this->file('lookalike.pdf'))
            ->usingFileName('lookalike.pdf')->toMediaCollection(PageDocuments::COLLECTION);
        // Its owner id is made the organisation's own id: only `model_type` now tells them apart.
        DB::table('media')->where('id', $foreignOwner->id)->update(['model_id' => $this->masjid->id]);

        // One real page document, whose id is borrowed for an address with ANOTHER name.
        $document = $this->uploadDocument('Calendar.pdf');
        $wrongName = self::PUBLIC_DISK_URL . "/{$document['id']}/some-other-name.pdf";
        // And a longer name that only starts like the document's.
        $longerName = $document['url'] . '.html';

        $addresses = [
            $sectionImage->getUrl(), $galleryPhoto->getUrl(), $foreignOwner->getUrl(), $wrongName, $longerName,
        ];
        $section = $this->saveLinkList($addresses);
        $this->updateLinkList($section, ['']);
        $again = $this->saveLinkList($addresses);
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/sections/{$again}")->assertStatus(200);

        foreach ([$sectionImage, $galleryPhoto, $foreignOwner] as $media) {
            $this->assertSame(1, DB::table('media')->where('id', $media->id)->count(), "{$media->collection_name}/{$media->file_name} was deleted");
            Storage::disk('public')->assertExists("{$media->id}/{$media->file_name}");
        }
        $this->assertDocumentKept($document);
    }

    #[Test]
    public function the_sections_library_route_takes_a_document_offline_as_the_page_route_does(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->postJson("/api/admin/masjids/{$this->masjid->id}/sections", [
            'section_type' => 'cta',
            'content' => $this->cta($document['url']),
        ])->assertStatus(201)->json('data.id');

        $this->putJson("/api/admin/masjids/{$this->masjid->id}/sections/{$section}", [
            'content' => $this->cta(''),
        ])->assertStatus(200);

        $this->assertDocumentGone($document);
    }

    #[Test]
    public function a_failure_inside_the_cleanup_never_fails_the_save_and_is_written_down(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        Log::spy();
        // The disk refuses: the row cannot be deleted, as when a file system is read-only.
        Media::deleting(function (): void {
            throw new \RuntimeException('the disk refused, with a path a log must not carry: /srv/storage/secret');
        });

        $saved = $this->updateLinkList($section, ['']);

        // The office's edit is stored, and answered as stored.
        $this->assertSame('', $saved['content']['links'][0]['url']);
        $this->assertSame('', Section::findOrFail($section)->content['links'][0]['url']);
        // The file is still online, and somebody can find out why.
        $this->assertDocumentKept($document);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($document, $section): bool {
                if (! str_contains($message, 'Page document NOT deleted')) {
                    return false;
                }

                $this->assertSame([
                    'masjid_id' => $this->masjid->id,
                    'section_id' => $section,
                    'media_id' => $document['id'],
                    'exception' => \RuntimeException::class,
                ], $context);

                return true;
            })
            ->once();
    }

    #[Test]
    public function a_cleanup_that_cannot_even_look_never_fails_the_save_and_is_written_down(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        Log::spy();

        // Called as the controllers call it, with an organisation whose relations cannot be read.
        $broken = new class extends Masjid
        {
            public function pageDocuments()
            {
                throw new \LogicException('no database');
            }
        };
        $broken->forceFill(['id' => $this->masjid->id]);

        PageDocuments::forgetUnlinked($broken, $this->linkList([$document['url']]), $this->linkList(['']), $section);

        $this->assertDocumentKept($document);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'Page documents were not checked')
                && $context === ['masjid_id' => $this->masjid->id, 'section_id' => $section, 'exception' => \LogicException::class])
            ->once();
    }

    #[Test]
    public function each_deletion_leaves_one_warning_line_by_ids_alone(): void
    {
        $document = $this->uploadDocument('Family Name Confidential.pdf');
        $section = $this->saveLinkList([$document['url']]);

        Log::spy();
        $this->updateLinkList($section, ['']);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($document, $section): bool {
                if ($message !== self::DELETED_LOG) {
                    return false;
                }

                // Ids and nothing else: no file name (an office's file name can name a person), no
                // address, no content.
                $this->assertSame([
                    'masjid_id' => $this->masjid->id,
                    'section_id' => $section,
                    'media_id' => $document['id'],
                ], $context);

                return true;
            })
            ->once();
    }

    #[Test]
    public function a_deleted_documents_address_is_a_404_from_the_application(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);
        $this->updateLinkList($section, ['']);
        $this->assertDocumentGone($document);

        // On the servers nginx finds no file and hands the path to the application.
        $this->get(parse_url($document['url'], PHP_URL_PATH))->assertStatus(404);
    }

    /* -------------------------------------------------------------- helpers */

    private function file(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, self::PDF);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Upload through the real route, as the signed-in administrator.
     *
     * @return array{url: string, id: int, file: string}
     */
    private function uploadDocument(string $name, ?Masjid $masjid = null): array
    {
        $url = $this->post('/api/admin/masjids/' . ($masjid ?? $this->masjid)->id . '/pages/documents', [
            'document' => $this->file($name),
        ])->assertStatus(201)->json('data.url');

        $this->assertSame(1, preg_match('#/storage/(\d+)/([a-z0-9-]+\.pdf)$#', $url, $match), $url);

        return ['url' => $url, 'id' => (int) $match[1], 'file' => "{$match[1]}/{$match[2]}"];
    }

    private function assertDocumentKept(array $document, ?Masjid $masjid = null): void
    {
        $this->assertSame(
            1,
            ($masjid ?? $this->masjid)->pageDocuments()->whereKey($document['id'])->count(),
            "the media row of {$document['file']} is gone"
        );
        Storage::disk('public')->assertExists($document['file']);
    }

    private function assertDocumentGone(array $document): void
    {
        $this->assertSame(0, DB::table('media')->where('id', $document['id'])->count(), "the media row of {$document['file']} is still there");
        Storage::disk('public')->assertMissing($document['file']);
    }

    private function pageSections(): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/pages/{$this->page->id}/sections";
    }

    /** Link Buttons content with one button per address. */
    private function linkList(array $addresses): array
    {
        return [
            'heading' => 'Downloads',
            'description' => '',
            'layout' => 'stack',
            'background_color' => '#ffffff',
            'links' => array_map(fn (string $address) => [
                'label' => 'Document', 'url' => $address, 'icon' => 'bi-file-earmark-arrow-down', 'style' => 'primary',
            ], $addresses),
        ];
    }

    private function cta(string $address): array
    {
        return [
            'heading' => 'Read it', 'description' => '', 'button_text' => 'Open', 'button_link' => $address,
            'button_style' => 'primary', 'background_image_url' => null, 'background_color' => '#f8f9fa',
        ];
    }

    /** Save a new section on the page the way SectionFormModal does; returns its id. */
    private function saveSection(string $type, array $content): int
    {
        return (int) $this->post($this->pageSections(), [
            'section_type' => $type,
            'content' => json_encode($content),
            'order' => 1,
            'is_active' => 1,
        ])->assertStatus(201)->json('data.id');
    }

    /** Save an edit of a section on the page; returns the answer's `data`. */
    private function updateSection(int $section, array $content): array
    {
        return $this->post("{$this->pageSections()}/{$section}", [
            '_method' => 'PUT',
            'content' => json_encode($content),
        ])->assertStatus(200)->json('data');
    }

    private function saveLinkList(array $addresses): int
    {
        return $this->saveSection('link_list', $this->linkList($addresses));
    }

    private function updateLinkList(int $section, array $addresses): array
    {
        return $this->updateSection($section, $this->linkList($addresses));
    }

    private function organisation(): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Test Organisation ' . uniqid(),
            'email' => 'org-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
        $masjid->forceFill(['capability_overrides' => ['web_pages' => true]])->save();

        return $masjid;
    }

    private function adminOf(Masjid $masjid): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);

        MasjidUser::create([
            'masjid_id' => $masjid->id, 'user_id' => $user->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        return $user->fresh();
    }

    private function page(Masjid $masjid, string $slug): Page
    {
        return Page::create([
            'masjid_id' => $masjid->id,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'is_active' => true,
            'order' => 1,
        ]);
    }
}
