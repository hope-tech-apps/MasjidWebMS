<?php

namespace Tests\Feature\Shop;

use App\Http\Requests\Admin\Shop\UploadProductImagesRequest;
use App\Models\Masjid;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * Shop slice B2: a product's pictures over HTTP at .../shop/products/{id}/images. Upload many (at most
 * eight in all), delete one, reorder: Spatie media in the `product_images` collection, read only
 * through `Product::images()` so `model_type` is always part of the key.
 *
 * The upload rule is the one every image upload in the admin has (bytes AND name AND size), so the
 * refusals here are the same refusals the section images have.
 */
class ShopProductImagesTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use BuildsShopAdmin;
    use RefreshDatabase;

    private Masjid $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootShopAdminTest();
        Storage::fake('public');

        $this->org = $this->shopOrg();
        $this->admin = $this->adminOf($this->org);

        Sanctum::actingAs($this->admin);
    }

    private function imagesUrl(Product $product, string $path = ''): string
    {
        return $this->shopUrl($this->org, '/products/' . $product->id . '/images' . $path);
    }

    private function jpeg(string $name = 'photo.jpg', int $kilobytes = 50): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/jpeg');
    }

    /** @param  list<UploadedFile>  $files */
    private function upload(Product $product, array $files): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])->post($this->imagesUrl($product), ['images' => $files]);
    }

    /**
     * `$count` pictures uploaded the way the SPA does it, so each has the disk and the file a real
     * upload leaves; the ids come back in upload order.
     *
     * @return list<int>
     */
    private function uploaded(Product $product, int $count): array
    {
        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $files[] = $this->jpeg("photo-{$i}.jpg");
        }

        $this->upload($product, $files)->assertCreated();

        return Media::query()
            ->where('model_type', Product::class)
            ->where('model_id', $product->id)
            ->orderBy('order_column')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function pictureCount(Product $product): int
    {
        return Media::query()->where('model_type', Product::class)->where('model_id', $product->id)->count();
    }

    // ------------------------------------------------------------ upload

    #[Test]
    public function pictures_are_stored_as_the_products_media_on_the_default_disk_and_answered_in_order(): void
    {
        $polo = $this->product($this->org);

        $response = $this->upload($polo, [
            $this->jpeg('front.jpg'),
            UploadedFile::fake()->create('back.png', 80, 'image/png'),
        ])->assertCreated();

        $images = $response->json('data.images');
        $this->assertSame(['front', 'back'], array_column($images, 'name'));
        $this->assertSame([1, 2], array_column($images, 'order'));
        $this->assertStringContainsString('front.jpg', $images[0]['url']);

        $rows = Media::query()->where('model_id', $polo->id)->orderBy('order_column')->get();
        $this->assertCount(2, $rows);

        foreach ($rows as $media) {
            $this->assertSame(Product::class, $media->model_type, 'filed under the product, with the whole key');
            $this->assertSame(Product::IMAGES, $media->collection_name);
            $this->assertSame('public', $media->disk, 'the library\'s default disk, as the gallery uses');
            Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        }

        // And the product's own read carries them.
        $this->getJson($this->shopUrl($this->org, '/products/' . $polo->id))->assertOk()->assertJsonCount(2, 'data.images');
    }

    #[Test]
    public function a_product_holds_at_most_eight_pictures_and_a_ninth_is_refused_as_a_whole(): void
    {
        $polo = $this->product($this->org);

        // Eight at once is the ceiling of one request and of one product.
        $this->uploaded($polo, 8);
        $this->assertSame(8, $this->pictureCount($polo));

        $response = $this->upload($polo, [$this->jpeg('ninth.jpg')])->assertStatus(422);
        $this->assertArrayHasKey('images', $response->json('data'));
        $this->assertSame(8, $this->pictureCount($polo), 'the ninth was not stored');

        // A request of nine files is refused outright.
        $other = $this->product($this->org, ['name' => 'Hoodie', 'slug' => 'hoodie']);
        $nine = [];
        for ($i = 1; $i <= 9; $i++) {
            $nine[] = $this->jpeg("p{$i}.jpg");
        }
        $this->assertArrayHasKey('images', $this->upload($other, $nine)->assertStatus(422)->json('data'));
        $this->assertSame(0, $this->pictureCount($other));

        // Two more on top of seven would be the ninth: refused as a whole, so not even the first is kept.
        $third = $this->product($this->org, ['name' => 'Cap', 'slug' => 'cap']);
        $this->uploaded($third, 7);
        $refused = $this->upload($third, [$this->jpeg('a.jpg'), $this->jpeg('b.jpg')])->assertStatus(422);
        $this->assertStringContainsString('at most 8', $refused->json('data.images.0'));
        $this->assertSame(7, $this->pictureCount($third));

        // One more is the eighth, and fits.
        $this->upload($third, [$this->jpeg('c.jpg')])->assertCreated();
        $this->assertSame(8, $this->pictureCount($third));
    }

    #[Test]
    public function only_images_are_accepted_by_their_bytes_by_their_name_and_by_their_size(): void
    {
        $polo = $this->product($this->org);

        $refused = [
            'a PDF' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            'an SVG' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
            'a video' => UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'),
            'image bytes named as a page' => UploadedFile::fake()->create('page.html', 10, 'image/jpeg'),
            'image bytes named as a script' => UploadedFile::fake()->create('shell.php', 10, 'image/jpeg'),
            'a page named as an image' => UploadedFile::fake()->create('photo.jpg', 10, 'text/html'),
            'one kilobyte over 10 MB' => UploadedFile::fake()->create('huge.jpg', UploadProductImagesRequest::MAX_KB + 1, 'image/jpeg'),
        ];

        foreach ($refused as $why => $file) {
            $response = $this->upload($polo, [$file])->assertStatus(422);

            $this->assertArrayHasKey('images.0', $response->json('data'), "{$why} was not refused on the file");
            $this->assertSame(0, $this->pictureCount($polo), "{$why} was stored anyway");
        }

        // The sentence says the limit, and eight of them fit the server's post_max_size (110M in production).
        $huge = $this->upload($polo, [UploadedFile::fake()->create('huge.jpg', UploadProductImagesRequest::MAX_KB + 1, 'image/jpeg')])->assertStatus(422);
        $this->assertSame('Each picture can be at most 10 MB.', $huge->json('data')['images.0'][0]);
        $this->assertSame(10240, UploadProductImagesRequest::MAX_KB);
        $this->assertLessThan(110 * 1024, Product::MAX_IMAGES * UploadProductImagesRequest::MAX_KB, 'a full request of eight must be able to arrive');

        // The four kinds, in either case of extension, and the size ceiling itself, are in.
        $this->upload($polo, [
            UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg'),
            UploadedFile::fake()->create('b.PNG', 10, 'image/png'),
            UploadedFile::fake()->create('c.gif', 10, 'image/gif'),
            UploadedFile::fake()->create('d.webp', 10, 'image/webp'),
            UploadedFile::fake()->create('e.jpeg', UploadProductImagesRequest::MAX_KB, 'image/jpeg'),
        ])->assertCreated();
        $this->assertSame(5, $this->pictureCount($polo));
    }

    #[Test]
    public function a_request_with_no_pictures_is_refused(): void
    {
        $polo = $this->product($this->org);

        $response = $this->withHeaders(['Accept' => 'application/json'])->post($this->imagesUrl($polo), [])->assertStatus(422);

        $this->assertArrayHasKey('images', $response->json('data'));
    }

    // ------------------------------------------------------------ delete

    #[Test]
    public function one_picture_is_deleted_with_its_file_and_the_others_stay(): void
    {
        $polo = $this->product($this->org);
        [$first, $second, $third] = $this->uploaded($polo, 3);

        $doomed = Media::query()->findOrFail($second);
        $path = $doomed->getPathRelativeToRoot();
        Storage::disk('public')->assertExists($path);

        $response = $this->deleteJson($this->imagesUrl($polo, '/' . $second))->assertOk();

        $this->assertSame([$first, $third], array_column($response->json('data.images'), 'id'));
        $this->assertNull(Media::query()->find($second));
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(2, $this->pictureCount($polo));
    }

    // ------------------------------------------------------------ reorder

    #[Test]
    public function pictures_are_reordered_by_spatie_order_column(): void
    {
        $polo = $this->product($this->org);
        [$a, $b, $c] = $this->uploaded($polo, 3);

        $response = $this->putJson($this->imagesUrl($polo, '/order'), ['order' => [$c, $a, $b]])->assertOk();

        $this->assertSame([$c, $a, $b], array_column($response->json('data.images'), 'id'));
        $this->assertSame([1, 2, 3], array_column($response->json('data.images'), 'order'));
        $this->assertSame(1, (int) Media::query()->findOrFail($c)->order_column);
        $this->assertSame(2, (int) Media::query()->findOrFail($a)->order_column);
        $this->assertSame(3, (int) Media::query()->findOrFail($b)->order_column);

        // A picture added afterwards goes to the end.
        $this->upload($polo, [$this->jpeg('late.jpg')])->assertCreated();
        $ids = array_column($this->getJson($this->shopUrl($this->org, '/products/' . $polo->id))->json('data.images'), 'id');
        $this->assertSame([$c, $a, $b], array_slice($ids, 0, 3));
        $this->assertCount(4, $ids);
    }

    #[Test]
    public function a_reorder_must_name_every_picture_of_the_product_once_and_nothing_else(): void
    {
        $polo = $this->product($this->org);
        $hoodie = $this->product($this->org, ['name' => 'Hoodie', 'slug' => 'hoodie']);
        [$a, $b] = $this->uploaded($polo, 2);
        [$theirs] = $this->uploaded($hoodie, 1);

        // A list that leaves one out: 422.
        $this->putJson($this->imagesUrl($polo, '/order'), ['order' => [$b]])->assertStatus(422)->assertJsonPath('status', 'failed');

        // The same picture twice: 422 on the repeat.
        $this->assertArrayHasKey('order.1', $this->putJson($this->imagesUrl($polo, '/order'), ['order' => [$a, $a]])->assertStatus(422)->json('data'));

        // Not this product's, or not there: a 404, and nothing moves.
        $this->putJson($this->imagesUrl($polo, '/order'), ['order' => [$b, $a, $theirs]])->assertNotFound();
        $this->putJson($this->imagesUrl($polo, '/order'), ['order' => [$b, 999999]])->assertNotFound();

        $this->assertSame(1, (int) Media::query()->findOrFail($a)->order_column);
        $this->assertSame(2, (int) Media::query()->findOrFail($b)->order_column);
        $this->assertSame(1, (int) Media::query()->findOrFail($theirs)->order_column, 'the other product\'s picture was not moved');
    }

    // ------------------------------------------------------------ the editor's version

    #[Test]
    public function a_picture_added_removed_or_moved_moves_the_lock_version_and_the_answer_carries_it(): void
    {
        $polo = $this->product($this->org);
        $version = fn (): int => (int) Product::query()->findOrFail($polo->id)->lock_version;

        $this->assertSame(0, $version());

        $upload = $this->upload($polo, [$this->jpeg('a.jpg'), $this->jpeg('b.jpg')])->assertCreated();
        $this->assertSame(1, $upload->json('data.lock_version'), 'one bump for the upload, however many files');
        [$a, $b] = array_column($upload->json('data.images'), 'id');

        $moved = $this->putJson($this->imagesUrl($polo, '/order'), ['order' => [$b, $a]])->assertOk();
        $this->assertSame(2, $moved->json('data.lock_version'));

        $removed = $this->deleteJson($this->imagesUrl($polo, '/' . $a))->assertOk();
        $this->assertSame(3, $removed->json('data.lock_version'));
        $this->assertSame(3, $version());

        // Refused requests move nothing.
        $this->putJson($this->imagesUrl($polo, '/order'), ['order' => [$b, 999999]])->assertNotFound();
        $this->deleteJson($this->imagesUrl($polo, '/999999'))->assertNotFound();
        $this->upload($polo, [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertStatus(422);
        $this->assertSame(3, $version());
    }

    #[Test]
    public function an_editor_that_read_the_product_before_a_picture_changed_is_refused_as_stale(): void
    {
        $polo = $this->product($this->org);
        $this->variant($polo, ['label' => 'M']);

        $opened = (int) $this->getJson($this->shopUrl($this->org, '/products/' . $polo->id))->assertOk()->json('data.lock_version');

        // Somebody else adds a picture.
        $this->upload($polo, [$this->jpeg('late.jpg')])->assertCreated();

        // The first editor saves what it showed: refused, nothing written.
        $this->putProduct($this->org, $polo->id, ['name' => 'Stale name'], $opened)->assertStatus(409);
        $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name);

        // At the version the picture upload left, it goes through.
        $this->putProduct($this->org, $polo->id, ['name' => 'Fresh name'])->assertOk();
    }

    // ------------------------------------------------------------ the whole key

    #[Test]
    public function a_picture_of_another_model_that_shares_the_products_id_is_never_listed_counted_or_touched(): void
    {
        $polo = $this->product($this->org);
        [$mine] = $this->uploaded($polo, 1);

        // A SERVICE's picture, filed in the same collection name under an id equal to the product's: half
        // a key (`model_id` alone) would take it for the product's.
        $collision = $this->pictureOf(Service::class, (int) $polo->id, Product::IMAGES, 1);
        // The product's own id in another collection is not a product picture either.
        $elsewhere = $this->pictureOf(Product::class, (int) $polo->id, 'something_else', 1);

        $listed = $this->getJson($this->shopUrl($this->org, '/products/' . $polo->id))->assertOk()->json('data.images');
        $this->assertSame([$mine], array_column($listed, 'id'));

        $this->deleteJson($this->imagesUrl($polo, '/' . $collision->id))->assertNotFound();
        $this->deleteJson($this->imagesUrl($polo, '/' . $elsewhere->id))->assertNotFound();
        $this->putJson($this->imagesUrl($polo, '/order'), ['order' => [$mine, $collision->id]])->assertNotFound();

        $this->assertNotNull(Media::query()->find($collision->id), 'the other model\'s picture is still there');
        $this->assertNotNull(Media::query()->find($elsewhere->id));

        // They do not count against the eight either: the product's own one and seven more make eight,
        // though two rows of other keys share its id.
        $seven = [];
        foreach (['b', 'c', 'd', 'e', 'f', 'g', 'h'] as $name) {
            $seven[] = $this->jpeg("{$name}.jpg");
        }
        $this->upload($polo, $seven)->assertCreated();
        $this->assertSame(
            8,
            Media::query()->where('model_type', Product::class)->where('model_id', $polo->id)->where('collection_name', Product::IMAGES)->count()
        );

        $this->upload($polo, [$this->jpeg('ninth.jpg')])->assertStatus(422);
    }
}
