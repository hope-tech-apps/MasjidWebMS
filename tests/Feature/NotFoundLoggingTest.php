<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What reaches the production log when something is not found.
 *
 * This exists because of a measurement, not a preference. On 2026-09-12
 * production wrote 369 log lines in a day and the twelve most frequent were all
 * scanner traffic — `api/graphql`, `api/proxy`, `api/webhook`, junk masjid ids —
 * every one of them at ERROR. Two of this project's own rules tell whoever is
 * debugging a live problem to read that log first, and a log where the real
 * failures sit one-in-thirty amongst probes is one nobody can read.
 *
 * So the guarantee under test is a pair, and both halves matter:
 *
 *   - a stranger's 404 is DROPPED, because it is the internet, not a defect;
 *   - a signed-in caller's 404 is KEPT, because that one is usually our bug —
 *     a stale route, a tenant scope that stopped matching, a record the UI
 *     still lists — and losing it would be the expensive half of this change.
 *
 * The response is not under test here beyond staying a 404: this changes what
 * is WRITTEN DOWN, never what the caller is told.
 */
class NotFoundLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    #[Test]
    public function a_strangers_probe_for_a_route_that_does_not_exist_is_not_logged_as_an_error(): void
    {
        Log::spy();

        // The exact shape production sees all day: an unauthenticated scanner
        // walking a list of endpoint names that have never existed here.
        $this->getJson('/api/graphql')->assertNotFound();
        $this->getJson('/api/proxy')->assertNotFound();
        $this->getJson('/api/webhook')->assertNotFound();

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_strangers_request_for_a_record_that_does_not_exist_is_not_logged_as_an_error(): void
    {
        Log::spy();

        // A real route, a real handler, a masjid id that is not there — the
        // other half of what the scanners generate.
        $this->getJson('/api/mobile/masjids/98765432')->assertNotFound();

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_signed_in_callers_4xx_is_kept_because_that_one_is_usually_our_bug(): void
    {
        $masjid = $this->makeMasjid();
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $masjid->user_id = $admin->id;
        $masjid->save();

        Sanctum::actingAs($admin, ['staff']);

        Log::spy();

        // An AUTHENTICATED route the caller cannot complete. A public route
        // would prove nothing: no guard resolves on one, so its caller is
        // indistinguishable from a stranger no matter who they are.
        //
        // This admin holds no `view contacts` permission, so the refusal is a
        // 403 rather than the 404 the scanners generate — which is the better
        // test of the rule as written: the level follows the STATUS FAMILY and
        // who asked, not one particular code. Both are 4xx, both are answered
        // correctly, and neither is an ERROR.
        $response = $this->getJson("/api/admin/masjids/{$masjid->id}/contacts/98765432");

        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertLessThan(500, $response->status());

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'signed-in')
                    && ($context['status'] ?? 500) < 500
                    && str_contains((string) ($context['path'] ?? ''), 'contacts/98765432')
                    && ($context['guard'] ?? null) === 'staff';
            })
            ->atLeast()->once();

        // And never as an error: the request was answered correctly. Something
        // was missing; nothing failed.
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function the_message_the_caller_gets_is_unchanged(): void
    {
        // The whole change is about what is written down. A 404 must still look
        // exactly like a 404 to whoever asked, in the envelope the SPA and the
        // mobile clients parse.
        $this->getJson('/api/graphql')
            ->assertNotFound()
            ->assertJsonPath('status', 'error');
    }
}
