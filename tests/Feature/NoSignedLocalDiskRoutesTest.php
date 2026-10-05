<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * No disk of this application answers to an address the framework signs.
 *
 * `'serve' => true` on a local-driver disk makes the framework register two
 * routes with no login, `GET storage/{path}` and `PUT storage/{path}`, and teach
 * the disk to mint addresses for them (`temporaryUrl`, `temporaryUploadUrl`).
 * Each honours only an address signed with APP_KEY (or a key still listed in
 * APP_PREVIOUS_KEYS), and the PUT writes the request body to the named path of
 * THAT DISK. On `local`, which is storage/app/private and holds every private
 * upload this application takes, the signature was the only thing between a
 * request and a write there. Nothing in the application ever made such an
 * address, so nothing used either route; the GET was never even reachable,
 * because the admin screen's catch-all is registered before it. The PUT was.
 *
 * Private files leave only through the application's own routes, which
 * re-resolve the ownership chain on every request: the authenticated downloads
 * (.claude/rules/private-uploads.md) and the viewer-bound playback ticket for
 * video (App\Support\GroupMedia). An address the FRAMEWORK signs would skip all
 * of that and survive consent being withdrawn, so the door is shut, not just
 * left unused. Three things keep it shut: the flag, the routes, and the real
 * disk refusing to make an address (so a future caller fails loudly instead of
 * quietly re-opening it; a test that fakes the disk does not see that refusal,
 * because the fake makes addresses of its own). See DECISIONS.md, 2026-10-05.
 *
 * Every request below runs against a scratch root, never storage/app/private.
 */
class NoSignedLocalDiskRoutesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // Only the root moves. The rest of the `local` disk's configuration is
        // what production reads, and the disk is rebuilt from it at first use.
        $this->root = storage_path('framework/testing/disks/no-signed-routes-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root);

        config(['filesystems.disks.local.root' => $this->root]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function no_disk_has_serve_switched_on(): void
    {
        foreach (config('filesystems.disks') as $name => $disk) {
            $this->assertFalse(
                (bool) ($disk['serve'] ?? false),
                "filesystems.disks.{$name} has 'serve' on: the framework would register signed GET and PUT routes over it."
            );
        }
    }

    #[Test]
    public function the_framework_registers_no_storage_route_for_any_disk(): void
    {
        $names = [];

        foreach (config('filesystems.disks') as $disk => $unused) {
            $names[] = 'storage.'.$disk;
            $names[] = 'storage.'.$disk.'.upload';
        }

        foreach ($names as $name) {
            $this->assertFalse(Route::getRoutes()->hasNamedRoute($name), "A route named {$name} is registered.");
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! preg_match('~^storage(/|$)~', $route->uri())) {
                continue;
            }

            $this->assertSame(
                [],
                array_values(array_intersect(['PUT', 'PATCH', 'POST', 'DELETE'], $route->methods())),
                'A route under storage/ takes a write verb: '.implode('|', $route->methods()).' '.$route->uri()
            );
        }
    }

    #[Test]
    public function no_route_accepts_a_write_on_a_storage_path(): void
    {
        foreach (['/storage/proof.txt', '/storage/12/nested/proof.txt'] as $path) {
            foreach (['PUT', 'PATCH', 'POST', 'DELETE'] as $verb) {
                try {
                    $route = Route::getRoutes()->match(Request::create($path, $verb));
                } catch (HttpException) {
                    // 404 or 405: no route takes this verb on this path.
                    continue;
                }

                $this->fail("{$verb} {$path} is taken by a route: ".$route->uri().' ('.($route->getName() ?? 'unnamed').')');
            }
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function every_local_disk_refuses_to_make_a_temporary_address(): void
    {
        foreach (config('filesystems.disks') as $name => $config) {
            if (($config['driver'] ?? null) !== 'local') {
                continue;
            }

            $disk = Storage::disk($name);

            $this->assertFalse($disk->providesTemporaryUrls(), "{$name} says it can make temporary addresses.");
            $this->assertFalse($disk->providesTemporaryUploadUrls(), "{$name} says it can make temporary upload addresses.");

            try {
                $disk->temporaryUrl('proof.txt', now()->addMinutes(5));
                $this->fail("{$name} made a temporary address.");
            } catch (RuntimeException $e) {
                $this->assertSame('This driver does not support creating temporary URLs.', $e->getMessage());
            }

            try {
                $disk->temporaryUploadUrl('proof.txt', now()->addMinutes(5));
                $this->fail("{$name} made a temporary upload address.");
            } catch (RuntimeException $e) {
                $this->assertSame('This driver does not support creating temporary upload URLs.', $e->getMessage());
            }
        }
    }

    #[Test]
    public function an_unsigned_put_to_a_storage_path_writes_nothing(): void
    {
        $response = $this->call('PUT', 'http://localhost/storage/proof.txt', [], [], [], [], 'unsigned-body');

        $this->assertNothingWritten($response->getStatusCode());
    }

    #[Test]
    public function a_badly_signed_put_to_a_storage_path_writes_nothing(): void
    {
        $expires = now()->addMinutes(5)->getTimestamp();
        $query = 'expires='.$expires.'&upload=1';

        $attempts = [
            // A signature that was never an HMAC of anything.
            'garbage signature' => 'http://localhost/storage/proof.txt?'.$query.'&signature='.str_repeat('0', 64),
            // A genuine signature, for a different path.
            'signed for another path' => 'http://localhost/storage/proof.txt?'.$query
                .'&signature='.$this->signature('/storage/other.txt?'.$query),
            // A genuine signature that has run out.
            'expired' => $this->signedUploadAddress('proof.txt', now()->subMinute()->getTimestamp()),
        ];

        foreach ($attempts as $label => $address) {
            $response = $this->call('PUT', $address, [], [], [], [], 'badly-signed-body');

            $this->assertNothingWritten($response->getStatusCode(), $label);
        }
    }

    #[Test]
    public function a_put_signed_the_way_the_framework_signs_writes_nothing(): void
    {
        $address = $this->signedUploadAddress('proof.txt', now()->addMinutes(5)->getTimestamp());

        // The address is only worth sending if the framework itself would accept
        // it. Without this a changed signing scheme would turn the test into one
        // that proves nothing, because any request with a wrong signature fails.
        $this->assertTrue(
            URL::hasValidRelativeSignature(Request::create($address, 'PUT')),
            'The address this test signs is not one the framework accepts, so the assertions below prove nothing.'
        );

        $response = $this->call('PUT', $address, [], [], [], [], 'signed-body');

        $this->assertNothingWritten($response->getStatusCode());
    }

    /**
     * An upload address in the shape `LocalFilesystemAdapter::temporaryUploadUrl()`
     * makes: the relative URL of `storage/{path}` with `expires` and `upload`
     * (ksorted by `UrlGenerator::signedRoute()`), signed with HMAC-SHA256 and
     * APP_KEY. Built by hand because the disk no longer makes one.
     */
    private function signedUploadAddress(string $path, int $expires): string
    {
        $query = 'expires='.$expires.'&upload=1';

        return 'http://localhost/storage/'.$path.'?'.$query.'&signature='.$this->signature('/storage/'.$path.'?'.$query);
    }

    private function signature(string $relativeUrl): string
    {
        return hash_hmac('sha256', $relativeUrl, (string) config('app.key'));
    }

    private function assertNothingWritten(int $status, string $label = ''): void
    {
        $suffix = $label === '' ? '' : " ({$label})";

        $this->assertGreaterThanOrEqual(400, $status, 'The PUT was answered '.$status.$suffix.'.');
        $this->assertFalse(Storage::disk('local')->exists('proof.txt'), 'The PUT wrote proof.txt on the private disk'.$suffix.'.');
        $this->assertSame([], File::allFiles($this->root), 'The PUT left a file under the private disk'.$suffix.'.');
    }
}
