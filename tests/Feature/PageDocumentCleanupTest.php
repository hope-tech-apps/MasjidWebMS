<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Support\PageDocuments;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** A 1x1 PNG, for a save that also carries an image. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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
        // The ROW cannot be deleted (the database refuses, or something listening to the delete
        // throws). A disk that will not let the FILE go is another failure and raises nothing:
        // a_file_the_disk_will_not_let_go_is_never_called_deleted().
        Media::deleting(function (): void {
            throw new \RuntimeException('the delete was refused, with a path a log must not carry: /srv/storage/secret');
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

    /* ------------------------------------------ a save from an out-of-date copy */

    #[Test]
    public function a_save_from_an_out_of_date_editor_deletes_nothing_and_an_ordinary_replace_still_deletes(): void
    {
        $x = $this->uploadDocument('Calendar 2025.pdf');
        $section = $this->saveLinkList([$x['url']]);

        // Two tabs have the section open. This is what the second one is holding: the whole
        // content, which the page tool sends back on every save, whatever was edited.
        $heldByTheSecondTab = $this->getJson($this->pageSections())->assertStatus(200)->json('data.0.content');
        $this->assertSame($x['url'], $heldByTheSecondTab['links'][0]['url']);

        // The first tab replaces X with Y and saves. An ordinary replace: X is deleted.
        $y = $this->uploadDocument('Calendar 2026.pdf');
        $this->updateLinkList($section, [$y['url']]);
        $this->assertDocumentGone($x);
        $this->assertDocumentKept($y);

        // The second tab, still showing X, changes the title and saves.
        Log::spy();
        $saved = $this->post("{$this->pageSections()}/{$section}", [
            '_method' => 'PUT',
            'section_type' => 'link_list',
            'title' => 'Downloads',
            'content' => json_encode($heldByTheSecondTab),
            'is_active' => 1,
        ])->assertStatus(200)->json('data');

        // The save is stored as it was sent: the old copy's link is back, and it is dead...
        $this->assertSame($x['url'], $saved['content']['links'][0]['url']);
        $this->get(parse_url($x['url'], PHP_URL_PATH))->assertStatus(404);
        // ...but the document the office publishes now was not deleted with it.
        $this->assertDocumentKept($y);

        // One line, by ids alone, naming what was kept.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($y, $section): bool {
                if (! str_starts_with($message, 'Page documents NOT removed')) {
                    return false;
                }

                $this->assertStringContainsString('out-of-date editor', $message);
                $this->assertSame([
                    'masjid_id' => $this->masjid->id,
                    'section_id' => $section,
                    'media_ids' => [$y['id']],
                ], $context);

                return true;
            })
            ->once();
        Log::shouldNotHaveReceived('warning', fn (string $message) => $message === self::DELETED_LOG);

        // The office puts the right address back by hand: an ordinary save again, and Y is linked.
        $this->updateLinkList($section, [$y['url']]);
        $this->assertDocumentKept($y);
    }

    /*
     * The guard above used to call a save out of date whenever it brought in ANY address of the
     * page-document shape that was not one of this organisation's documents. That is far more than
     * an old copy can hold: an office that replaced its own document with another organisation's,
     * with its own PDF from another collection, or with another site's address was told "taken
     * offline when you save", the file was kept, and no later save could reach it. An old copy holds
     * an address this application gave out, for a document that has since been DELETED. Only that is
     * out of date now. Everything else is an ordinary replace, and what it drops is cleaned up.
     */

    #[Test]
    public function a_document_replaced_by_another_organisations_real_document_is_deleted_and_theirs_is_untouched(): void
    {
        $other = $this->organisation();
        Sanctum::actingAs($this->adminOf($other));
        $theirs = $this->uploadDocument('Their Calendar.pdf', $other);

        Sanctum::actingAs($this->admin);
        $mine = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$mine['url']]);

        Log::spy();
        $this->updateLinkList($section, [$theirs['url']]);

        // Their document exists: nothing about this save is out of date.
        $this->assertDocumentGone($mine);
        $this->assertDocumentKept($theirs, $other);
        Log::shouldNotHaveReceived('warning', fn (string $message) => str_starts_with($message, 'Page documents NOT removed'));

        // And theirs is still not this organisation's to delete, when its address goes again.
        $this->updateLinkList($section, ['']);
        $this->assertDocumentKept($theirs, $other);
    }

    #[Test]
    public function a_document_replaced_by_the_organisations_own_pdf_in_another_collection_is_deleted(): void
    {
        // A PDF the organisation holds somewhere else, under a name of the same shape. It has a media
        // row, so the address is not a deleted document's; it is not a page document either, so it
        // is never deleted here.
        $flyer = $this->masjid->addMedia($this->file('flyer.pdf'))
            ->usingFileName('ramadan-flyer.pdf')->toMediaCollection('flyers');
        $this->assertStringStartsWith(self::PUBLIC_DISK_URL . '/', $flyer->getUrl());

        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        $this->updateLinkList($section, [$flyer->getUrl()]);
        $this->assertDocumentGone($document);

        $this->updateLinkList($section, ['']);
        $this->assertSame(1, DB::table('media')->where('id', $flyer->id)->count(), 'the flyer was deleted');
        Storage::disk('public')->assertExists("{$flyer->id}/ramadan-flyer.pdf");
    }

    #[Test]
    public function a_document_replaced_by_another_sites_address_of_the_same_shape_is_deleted(): void
    {
        // Rewritten: this pinned the old rule, under which the document below was KEPT, for good.
        // Another site's address is nothing an out-of-date editor of this application could hold,
        // whether or not a document of that number and name exists here.
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);
        $this->updateLinkList($section, ['https://elsewhere.example.test/storage/999999/annual-report.pdf']);
        $this->assertDocumentGone($document);

        // The same when that site's address has the number and name of a document that WAS here and
        // is gone: the host is what says it is not ours.
        $gone = $this->uploadDocument('Fees.pdf');
        $kept = $this->uploadDocument('Handbook.pdf');
        $other = $this->saveLinkList([$gone['url']]);
        $this->updateLinkList($other, [$kept['url']]);
        $this->assertDocumentGone($gone);
        $this->updateLinkList($other, ["https://elsewhere.example.test/storage/{$gone['file']}"]);
        $this->assertDocumentGone($kept);
    }

    #[Test]
    public function a_document_replaced_by_an_address_of_this_application_whose_document_is_gone_is_kept_and_written_down(): void
    {
        // What an old copy holds, in each way it can be written: on the public disk's own address (as
        // every upload is answered), on that host by plain http, with no host at all, on the host
        // this request came in on (the deployment answers to more than one), and carried
        // percent-encoded inside a viewer's link (found in the percent-decoded reading alone).
        $request = 'admin-host.example.test';
        $spellings = [
            'the public disk\'s own address' => self::PUBLIC_DISK_URL . '/999999/annual-report.pdf',
            'the same host over http' => str_replace('https://', 'http://', self::PUBLIC_DISK_URL) . '/999998/annual-report.pdf',
            'no host at all' => '/storage/999997/annual-report.pdf',
            'the host the request came in on' => "https://{$request}/storage/999996/annual-report.pdf",
            'percent-encoded inside a viewer\'s link' => 'https://viewer.example.test/view?url='
                . rawurlencode(self::PUBLIC_DISK_URL . '/999995/annual-report.pdf') . '&embedded=true',
        ];

        foreach ($spellings as $what => $dead) {
            $document = $this->uploadDocument('Calendar.pdf');
            $section = $this->saveLinkList([$document['url']]);

            Log::spy();
            // By its full address: a `Host` header alone does not reach the application in a test,
            // where the host is taken from the address the request is made to.
            $this->post("https://{$request}{$this->pageSections()}/{$section}", [
                '_method' => 'PUT',
                'content' => json_encode($this->linkList([$dead])),
            ])->assertStatus(200);

            $this->assertDocumentKept($document);
            Log::shouldHaveReceived('warning')
                ->withArgs(function (string $message, array $context = []) use ($document, $section, $what): bool {
                    // The spy is one for the whole test: each save's own line is found by its section.
                    if (! str_starts_with($message, 'Page documents NOT removed') || ($context['section_id'] ?? null) !== $section) {
                        return false;
                    }

                    $this->assertStringContainsString('out-of-date editor', $message, $what);
                    $this->assertSame([
                        'masjid_id' => $this->masjid->id,
                        'section_id' => $section,
                        'media_ids' => [$document['id']],
                    ], $context, $what);

                    return true;
                })
                ->once();
        }
    }

    #[Test]
    public function a_stale_save_that_brings_back_a_viewers_link_to_a_deleted_document_keeps_the_current_document(): void
    {
        $viewer = fn (array $document) => 'https://viewer.example.test/view?url=' . rawurlencode($document['url']) . '&embedded=true';

        // X is opened through a viewer from one section, and linked plainly from another.
        $x = $this->uploadDocument('Calendar 2025.pdf');
        $viewed = $this->saveSection('cta', $this->cta($viewer($x)));
        $buttons = $this->saveLinkList([$x['url']]);

        // What a second tab holds of the first section: the viewer's link, with X's address inside
        // it percent-encoded and nowhere as it is written.
        $heldByTheSecondTab = Section::findOrFail($viewed)->content;
        $this->assertSame($viewer($x), $heldByTheSecondTab['button_link']);
        $this->assertStringNotContainsString('/storage/', $heldByTheSecondTab['button_link']);

        // The first tab puts a new document, Y, in the viewer's place. Then the buttons let go of
        // X, nothing links it any more, and it is deleted, as it should be.
        $y = $this->uploadDocument('Calendar 2026.pdf');
        $this->updateSection($viewed, $this->cta($y['url']));
        $this->assertDocumentKept($x);
        $this->updateLinkList($buttons, ['']);
        $this->assertDocumentGone($x);

        // The second tab, still showing the viewer's link to X, saves.
        Log::spy();
        $saved = $this->updateSection($viewed, $heldByTheSecondTab);
        $this->assertSame($viewer($x), $saved['content']['button_link']);

        // The address it brought back is one of ours whose document is gone, though it is only
        // there percent-encoded: the save is out of date, and Y is not deleted with it.
        $this->assertDocumentKept($y);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($y, $viewed): bool {
                if (! str_starts_with($message, 'Page documents NOT removed')) {
                    return false;
                }

                $this->assertStringContainsString('out-of-date editor', $message);
                $this->assertSame([
                    'masjid_id' => $this->masjid->id,
                    'section_id' => $viewed,
                    'media_ids' => [$y['id']],
                ], $context);

                return true;
            })
            ->once();
        Log::shouldNotHaveReceived('warning', fn (string $message) => $message === self::DELETED_LOG);
    }

    #[Test]
    public function an_address_of_ours_with_a_real_documents_number_and_another_name_names_no_document(): void
    {
        // "The document it named is gone" is asked of the number AND the file name together, as a
        // document is always found here. A number that some row has, under another name, is not that
        // document: no file answers at this address, so the save is treated as out of date.
        $fees = $this->uploadDocument('Fees.pdf');
        $noSuchFile = self::PUBLIC_DISK_URL . "/{$fees['id']}/calendar-2019.pdf";
        Storage::disk('public')->assertMissing("{$fees['id']}/calendar-2019.pdf");

        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);
        $this->updateLinkList($section, [$noSuchFile]);

        $this->assertDocumentKept($document);
        $this->assertDocumentKept($fees);
    }

    #[Test]
    public function a_dead_link_that_was_already_in_the_section_does_not_hold_up_a_later_deletion(): void
    {
        // Only an address a save BRINGS IN marks it as out of date. A section that already carries
        // a dead link of ours (an old copy saved it, above) is edited like any other afterwards.
        $dead = self::PUBLIC_DISK_URL . '/999999/annual-report.pdf';
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$dead, $document['url']]);

        $this->updateLinkList($section, [$dead]);

        $this->assertDocumentGone($document);
    }

    /* ------------------------------------------------ still linked, spelt differently */

    #[Test]
    public function a_viewer_link_that_carries_the_address_percent_encoded_keeps_the_file(): void
    {
        $viewer = fn (array $document) => 'https://viewer.example.test/view?url=' . rawurlencode($document['url']) . '&embedded=true';

        // In the SAME section: the office swaps the plain address for a viewer's link to it.
        $same = $this->uploadDocument('Calendar.pdf');
        $this->assertStringNotContainsString(parse_url($same['url'], PHP_URL_PATH), $viewer($same), 'the plain path is not in the link');
        $section = $this->saveLinkList([$same['url']]);
        $saved = $this->updateLinkList($section, [$viewer($same)]);
        $this->assertSame($viewer($same), $saved['content']['links'][0]['url']);
        $this->assertDocumentKept($same);

        // In ANOTHER section: a call to action opens the document through the viewer, and the
        // buttons that carried the plain address let go.
        $other = $this->uploadDocument('Schedule.pdf');
        $cta = $this->saveSection('cta', $this->cta($viewer($other)));
        $buttons = $this->saveLinkList([$other['url']]);
        $this->updateLinkList($buttons, ['']);
        $this->assertDocumentKept($other);

        // A link that only ever carried the address encoded keeps a file and never starts a
        // deletion: when it goes too, the file is left online. Kept, not wrongly deleted.
        $this->updateSection($cta, $this->cta(''));
        $this->assertDocumentKept($other);
    }

    /**
     * Other ways of writing a document's address that a browser resolves to the same file before it
     * asks for it. Nobody is handed these: the page tool and the upload answer always give the plain
     * address. They are typed, or copied out of something that escaped them.
     *
     * @return array<string, array{\Closure(string): string}>
     */
    public static function spellingsABrowserResolves(): array
    {
        $viewer = fn (string $address) => 'https://viewer.example.test/view?url=' . rawurlencode($address) . '&embedded=true';

        return [
            'a dot segment' => [fn (string $plain) => str_replace('/storage/', '/storage/./', $plain)],
            'a dot-dot segment' => [fn (string $plain) => str_replace('/storage/', '/storage/old/../', $plain)],
            'a doubled slash' => [fn (string $plain) => str_replace('/storage/', '/storage//', $plain)],
            'backslashes for slashes' => [fn (string $plain) => str_replace('/', '\\', $plain)],
            'JSON-escaped slashes' => [fn (string $plain) => str_replace('/', '\\/', $plain)],
            // More than one step back, each over its own segment.
            'two dot-dot segments' => [fn (string $plain) => str_replace('/storage/', '/storage/a/b/../../', $plain)],
            // Percent-encoded, which a browser reads as the same segments: found only because the
            // percent-decoded reading is resolved as well as the text as written.
            'a dot segment written %2e' => [fn (string $plain) => str_replace('/storage/', '/storage/%2e/', $plain)],
            'a dot-dot segment written %2E%2E' => [fn (string $plain) => str_replace('/storage/', '/storage/old/%2E%2E/', $plain)],
            'backslashes written %5C' => [fn (string $plain) => str_replace('/', '%5C', $plain)],
            'a dot segment, percent-encoded inside a viewer\'s link' => [fn (string $plain) => $viewer(str_replace('/storage/', '/storage/./', $plain))],
        ];
    }

    #[Test]
    #[DataProvider('spellingsABrowserResolves')]
    public function a_link_written_in_a_spelling_a_browser_resolves_to_the_file_keeps_it(\Closure $spelt): void
    {
        // ANOTHER section holds only the spelling, and the buttons that carried the plain address
        // let go.
        $other = $this->uploadDocument('Schedule.pdf');
        $this->assertStringNotContainsString(parse_url($other['url'], PHP_URL_PATH), $spelt($other['url']), 'the plain path is not in the spelling');
        $cta = $this->saveSection('cta', $this->cta($spelt($other['url'])));
        $this->assertSame($spelt($other['url']), Section::findOrFail($cta)->content['button_link'], 'the spelling was not stored as it was written');
        $buttons = $this->saveLinkList([$other['url']]);
        $this->updateLinkList($buttons, ['']);
        $this->assertDocumentKept($other);

        // The SAME section swaps the plain address for the spelling.
        $same = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$same['url']]);
        $saved = $this->updateLinkList($section, [$spelt($same['url'])]);
        $this->assertSame($spelt($same['url']), $saved['content']['links'][0]['url']);
        $this->assertDocumentKept($same);

        // A spelling keeps a file and never STARTS a deletion: when the link that only ever carried
        // it goes too, the file is left online. Kept, not wrongly deleted.
        $this->updateSection($cta, $this->cta(''));
        $this->assertDocumentKept($other);
        $this->updateLinkList($section, ['']);
        $this->assertDocumentKept($same);
    }

    /*
     * The spelling reader is run over every string of the section on every save, and over every
     * other section of the organisation whenever a save drops a document. A section's text has no
     * size limit, so the reader must not cost more than the text is long: it used to take one
     * dot-dot step per pass over the whole text, and a text of 40,000 steps (195 KB, which no page
     * has and any administrator can write) made each of those saves take eleven seconds.
     */

    /** The longest a save may take here. An ordinary one takes a few hundredths of a second. */
    private const PROMPTLY = 2.0;

    #[Test]
    public function a_link_with_forty_thousand_dot_dot_steps_is_read_in_well_under_a_second_and_still_keeps_its_file(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $dropped = $this->uploadDocument('Schedule.pdf');

        // A browser resolves this to the document's own address, however many steps it takes.
        $long = str_replace('/storage/', '/storage' . str_repeat('/a/..', 40000) . '/', $document['url']);
        $this->assertGreaterThan(190_000, strlen($long));
        $this->assertStringNotContainsString(parse_url($document['url'], PHP_URL_PATH), $long, 'the plain path is not in the spelling');
        $this->saveSection('cta', $this->cta($long));
        $buttons = $this->saveLinkList([$document['url'], $dropped['url']]);

        // The buttons let go of both, and the other section's long link is read.
        $started = microtime(true);
        $this->updateLinkList($buttons, ['']);
        $took = microtime(true) - $started;

        $this->assertLessThan(self::PROMPTLY, $took, sprintf('reading a link of 40,000 dot-dot steps took %.2f seconds', $took));
        // The RIGHT file is kept: the one the long link reaches, and not the one nothing links.
        $this->assertDocumentKept($document);
        $this->assertDocumentGone($dropped);
    }

    #[Test]
    public function saving_a_section_whose_text_holds_forty_thousand_dot_dot_steps_returns_promptly(): void
    {
        // Text and nothing else: it links no document. Its own save reads it, and so does any other
        // section's save that drops a document.
        $steps = str_repeat('/a/..', 40000);
        $text = $this->saveSection('text', ['content' => "<p>{$steps}</p>"]);
        $document = $this->uploadDocument('Calendar.pdf');
        $buttons = $this->saveLinkList([$document['url']]);

        $started = microtime(true);
        $saved = $this->updateSection($text, ['content' => "<p>{$steps}</p><p>Edited.</p>"]);
        $own = microtime(true) - $started;
        $this->assertStringEndsWith('<p>Edited.</p>', $saved['content']['content']);

        $started = microtime(true);
        $this->updateLinkList($buttons, ['']);
        $other = microtime(true) - $started;

        $this->assertLessThan(self::PROMPTLY, $own, sprintf('saving the section itself took %.2f seconds', $own));
        $this->assertLessThan(self::PROMPTLY, $other, sprintf('another section\'s save that drops a document took %.2f seconds', $other));
        // And that save did its work: nothing links the document, so it is gone.
        $this->assertDocumentGone($document);
    }

    #[Test]
    public function a_save_that_brings_in_five_thousand_addresses_of_another_site_returns_promptly_and_deletes_what_it_dropped(): void
    {
        // The out-of-date check asks of every address a save brings in whether it is written as one
        // of ours. It used to read the whole text in front of each one, so a text of many addresses
        // cost the square of their number: 5,000 of them (250 KB) held the save for fifteen seconds.
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveSection('text', ['content' => '<p><a href="' . $document['url'] . '">The calendar</a></p>']);

        $many = '';
        for ($number = 1; $number <= 5000; $number++) {
            $many .= "https://elsewhere.example.test/storage/{$number}/annual-report.pdf ";
        }

        $started = microtime(true);
        $this->updateSection($section, ['content' => $many]);
        $took = microtime(true) - $started;

        $this->assertLessThan(self::PROMPTLY, $took, sprintf('a save that brought in 5,000 addresses took %.2f seconds', $took));
        // None of them is ours, so this is an ordinary replace: the document it dropped is gone.
        $this->assertDocumentGone($document);
    }

    #[Test]
    public function an_address_after_more_unbroken_text_than_is_read_for_its_host_is_not_judged_and_nothing_is_deleted(): void
    {
        // The price of reading only what stands straight in front of each address: one that follows
        // more than 2,048 characters with no space, quote or tag among them is not judged at all.
        // Calling it "another site's" unread would let the deletion through, so the check gives up
        // out loud, and a check that gives up deletes nothing.
        $elsewhere = 'https://elsewhere.example.test/storage/999999/annual-report.pdf';
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        Log::spy();
        $this->updateLinkList($section, [str_repeat('a', 5000) . $elsewhere]);

        $this->assertDocumentKept($document);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'Page documents were not checked')
                && $context === ['masjid_id' => $this->masjid->id, 'section_id' => $section, 'exception' => \RuntimeException::class])
            ->once();
        Log::shouldNotHaveReceived('warning', fn (string $message) => $message === self::DELETED_LOG);

        // Within that length it is judged as it always was: another site's address is an ordinary
        // replace, and the document it drops is deleted.
        $other = $this->uploadDocument('Schedule.pdf');
        $buttons = $this->saveLinkList([$other['url']]);
        $this->updateLinkList($buttons, [str_repeat('a', 2000) . $elsewhere]);
        $this->assertDocumentGone($other);
    }

    #[Test]
    public function an_id_written_with_a_leading_zero_beside_the_real_address_never_deletes_the_file(): void
    {
        $zero = fn (array $document) => self::PUBLIC_DISK_URL . "/0{$document['file']}";

        // Two buttons of ONE section, one spelt each way; the mistyped one is removed.
        $first = $this->uploadDocument('Fees.pdf');
        $this->assertSame(self::PUBLIC_DISK_URL . "/0{$first['id']}/fees.pdf", $zero($first));
        $section = $this->saveLinkList([$first['url'], $zero($first)]);
        $saved = $this->updateLinkList($section, [$first['url']]);
        $this->assertSame($first['url'], $saved['content']['links'][0]['url']);
        $this->assertDocumentKept($first);

        // One button, corrected from the mistyped spelling to the real one in a single save.
        $second = $this->uploadDocument('Handbook.pdf');
        $corrected = $this->saveLinkList([$zero($second)]);
        $this->updateLinkList($corrected, [$second['url']]);
        $this->assertDocumentKept($second);

        // Across TWO sections: one holds the real address, the other the mistyped one, and the
        // other lets go, then is deleted from the library.
        $third = $this->uploadDocument('Calendar.pdf');
        $this->saveSection('cta', $this->cta($third['url']));
        $mistyped = $this->saveLinkList([$zero($third)]);
        $this->updateLinkList($mistyped, ['']);
        $this->assertDocumentKept($third);
        $again = $this->saveLinkList([$zero($third)]);
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/sections/{$again}")->assertStatus(200);
        $this->assertDocumentKept($third);
    }

    #[Test]
    public function an_address_whose_id_has_a_leading_zero_is_not_a_page_document_address_and_starts_no_deletion(): void
    {
        // Nothing else links this document, so nothing but the address pattern stands between the
        // mistyped spelling (which, read as a number, IS this document's id) and its deletion.
        $document = $this->uploadDocument('Calendar.pdf');
        $mistyped = self::PUBLIC_DISK_URL . "/00{$document['file']}";

        // Not a working address either: there is no file behind it.
        Storage::disk('public')->assertMissing("00{$document['file']}");
        $this->get(parse_url($mistyped, PHP_URL_PATH))->assertStatus(404);

        $section = $this->saveLinkList([$mistyped]);
        $this->updateLinkList($section, ['']);
        $this->assertDocumentKept($document);

        $again = $this->saveLinkList([$mistyped]);
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/sections/{$again}")->assertStatus(200);
        $this->assertDocumentKept($document);
    }

    /* ------------------------------------------- a save that fails after it wrote */

    #[Test]
    public function a_save_whose_image_step_fails_after_the_content_was_written_still_takes_the_document_offline(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveSection('cta', $this->cta($document['url']));

        // The section's row is written first and its image is stored second, in no transaction.
        // The image step is made to throw (as the media library does for a name it will not keep).
        Media::creating(function (): void {
            throw new \RuntimeException('the image could not be stored');
        });

        $this->post("{$this->pageSections()}/{$section}", [
            '_method' => 'PUT',
            'content' => json_encode($this->cta('')),
            'background_image_url' => $this->image('banner.png'),
        ])->assertStatus(500);

        // The office was told the save failed, and the content is stored all the same: the
        // address is gone from it, so no later save could find the document to remove.
        $this->assertSame('', Section::findOrFail($section)->content['button_link']);
        $this->assertDocumentGone($document);
    }

    #[Test]
    public function the_library_route_does_the_same_when_its_image_step_fails(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $kept = $this->uploadDocument('Schedule.pdf');
        $section = $this->postJson("/api/admin/masjids/{$this->masjid->id}/sections", [
            'section_type' => 'cta',
            'content' => $this->cta($document['url']),
        ])->assertStatus(201)->json('data.id');
        $this->saveLinkList([$kept['url']]);

        Media::creating(function (): void {
            throw new \RuntimeException('the image could not be stored');
        });

        $this->post("/api/admin/masjids/{$this->masjid->id}/sections/{$section}", [
            '_method' => 'PUT',
            'content' => json_encode($this->cta('')),
            'background_image_url' => $this->image('banner.png'),
        ])->assertStatus(500);

        $this->assertSame('', Section::findOrFail($section)->content['button_link']);
        $this->assertDocumentGone($document);
        // Only what that save unlinked: another section's document is untouched by the failure.
        $this->assertDocumentKept($kept);
    }

    #[Test]
    public function a_save_that_fails_before_the_content_is_written_deletes_nothing(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        // The write itself fails: the stored content still links the document.
        Section::updating(function (): void {
            throw new \RuntimeException('the section could not be written');
        });

        $this->post("{$this->pageSections()}/{$section}", [
            '_method' => 'PUT',
            'content' => json_encode($this->linkList([''])),
        ])->assertStatus(500);

        $this->assertSame($document['url'], Section::findOrFail($section)->content['links'][0]['url']);
        $this->assertDocumentKept($document);
    }

    #[Test]
    public function a_saved_section_that_cannot_be_read_back_deletes_nothing_and_is_written_down(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        Log::spy();

        // The section is gone by the time the cleanup looks (deleted from the library by someone
        // else in the same moment). "It links nothing now" would be a guess that deletes.
        Section::whereKey($section)->delete();
        PageDocuments::forgetUnlinkedBySave($this->masjid, $this->linkList([$document['url']]), $section);

        $this->assertDocumentKept($document);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'Page documents were not checked')
                && $context === ['masjid_id' => $this->masjid->id, 'section_id' => $section, 'exception' => \RuntimeException::class])
            ->once();
    }

    /* ------------------------------------------------ a file the disk keeps */

    #[Test]
    public function a_file_the_disk_will_not_let_go_is_never_called_deleted(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $other = $this->uploadDocument('Schedule.pdf');
        $section = $this->saveLinkList([$document['url'], $other['url']]);

        // A disk that refuses every removal the way the public disk really does (a tree owned by
        // another user, a read-only mount): it does not throw, it answers false. The media library
        // has deleted the row by then, and reports nothing.
        $real = Storage::disk('public');
        Storage::set('public', new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter
        {
            public function delete($paths)
            {
                return false;
            }

            public function deleteDirectory($directory)
            {
                return false;
            }
        });

        Log::spy();
        $this->updateLinkList($section, ['']);

        foreach ([$document, $other] as $kept) {
            // The row is gone and the file is still public.
            $this->assertSame(0, DB::table('media')->where('id', $kept['id'])->count());
            Storage::disk('public')->assertExists($kept['file']);
        }

        // Each file has its own line, by ids alone, and it does not say the file was deleted.
        foreach ([$document, $other] as $kept) {
            Log::shouldHaveReceived('warning')
                ->withArgs(function (string $message, array $context = []) use ($kept, $section): bool {
                    if (($context['media_id'] ?? null) !== $kept['id']) {
                        return false;
                    }

                    $this->assertStringStartsWith('Page document NOT removed', $message);
                    $this->assertStringContainsString('still online', $message);
                    $this->assertStringNotContainsString('deleted', $message);
                    $this->assertSame([
                        'masjid_id' => $this->masjid->id,
                        'section_id' => $section,
                        'media_id' => $kept['id'],
                    ], $context);

                    return true;
                })
                ->once();
        }
        Log::shouldNotHaveReceived('warning', fn (string $message) => $message === self::DELETED_LOG);
    }

    #[Test]
    public function a_disk_that_cannot_be_asked_is_not_said_to_have_kept_the_file_nor_to_have_let_it_go(): void
    {
        $document = $this->uploadDocument('Calendar.pdf');
        $section = $this->saveLinkList([$document['url']]);

        // A disk that removes what it is told to and then cannot say what it holds (a network disk
        // that stops answering). Nothing is known about the file after that, either way.
        $real = Storage::disk('public');
        Storage::set('public', new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter
        {
            public function exists($path)
            {
                throw new \RuntimeException('The disk did not answer.');
            }
        });

        Log::spy();
        $this->updateLinkList($section, ['']);
        Storage::set('public', $real);

        // The record is gone, so this line is all there will be: it must not say the file was
        // deleted, and it must not say the disk kept it. It says the file could not be checked.
        $this->assertSame(0, DB::table('media')->where('id', $document['id'])->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($document, $section): bool {
                if (($context['media_id'] ?? null) !== $document['id']) {
                    return false;
                }

                $this->assertStringStartsWith('Page document could NOT be checked', $message);
                $this->assertStringContainsString('may still be online', $message);
                $this->assertStringNotContainsString('deleted', $message);
                $this->assertStringNotContainsString('kept the file', $message);
                $this->assertStringNotContainsString('NOT removed', $message);
                // By ids alone, as every other line here.
                $this->assertSame([
                    'masjid_id' => $this->masjid->id,
                    'section_id' => $section,
                    'media_id' => $document['id'],
                ], $context);

                return true;
            })
            ->once();
        Log::shouldNotHaveReceived('warning', fn (string $message) => $message === self::DELETED_LOG);
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

    /** A real picture, as a section's own image upload. */
    private function image(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, base64_decode(self::PNG_BASE64));
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
