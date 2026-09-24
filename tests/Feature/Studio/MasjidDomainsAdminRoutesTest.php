<?php

namespace Tests\Feature\Studio;

use App\Jobs\AttachMasjidDomain;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Domains\DomainAttacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * /api/admin/masjids/{masjid_id}/domains (S7): an organisation's web addresses,
 * for SuperAdmins only. No test here reaches a network: the job is faked, the
 * probe's host resolves to nothing, and every request is refused unless faked.
 */
class MasjidDomainsAdminRoutesTest extends TestCase
{
    use FakesCloudflare;
    use MakesStudioDomains;
    use RefreshDatabase;

    private const PREFIX = 'api/admin/masjids/{masjid_id}/domains';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        config(['cloudflare.studio_token' => null]);
        $this->resolveTo([]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function actAsSuper(): User
    {
        $user = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    private function member(Masjid $org, string $type, string $role): User
    {
        $user = User::factory()->create(['type' => $type, 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'is_default' => true]);

        return $user->fresh();
    }

    private function url(Masjid $org, string $rest = ''): string
    {
        return "/api/admin/masjids/{$org->id}/domains{$rest}";
    }

    #[Test]
    public function a_masjid_admin_a_teacher_and_a_guest_are_refused_every_domain_route_with_401(): void
    {
        $org = $this->makeOrg();
        $row = $this->makeDomain($org, 'www.example.org');

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === self::PREFIX || str_starts_with($route->uri(), self::PREFIX . '/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $routes[] = [$method, '/' . str_replace(['{masjid_id}', '{domain_id}'], [$org->id, $row->id], $route->uri())];
                }
            }
        }
        $this->assertCount(4, $routes, 'the four domain routes');

        foreach ([
            'a guest' => null,
            'a MasjidAdmin' => $this->member($org, 'MasjidAdmin', 'masjid-admin'),
            'a Teacher' => $this->member($org, 'Teacher', 'teacher'),
        ] as $who => $user) {
            foreach ($routes as [$method, $url]) {
                $this->app['auth']->forgetGuards();
                if ($user !== null) {
                    Sanctum::actingAs($user);
                }

                $response = $this->json($method, $url, ['kind' => 'managed_subdomain', 'label' => 'refused']);
                $this->assertSame(401, $response->getStatusCode(), "{$method} {$url} answered {$who} with {$response->getStatusCode()}");
            }
        }

        $this->assertSame(1, MasjidDomain::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_list_says_whether_the_token_is_configured_and_carries_each_rows_steps(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $this->makeDomain($org, 'al-noor.manara.hopetechapps.com', MasjidDomain::STATUS_PENDING, [
            'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com', 'waiting_on' => 'token',
        ]);
        $this->makeDomain($this->makeOrg(), 'someone-else.example.org');

        $response = $this->getJson($this->url($org))->assertOk();

        $this->assertSame([
            'configured' => false,
            'pages_project' => 'manara-renderer',
            'pages_domains_used' => null,
            'pages_domains_ceiling' => 100,
        ], $response->json('data.cloudflare'));
        $this->assertSame(['al-noor.manara.hopetechapps.com'], array_column($response->json('data.domains'), 'host'));

        $row = $response->json('data.domains.0');
        $this->assertNull($row['live_url']);
        $this->assertSame('token', $row['waiting_on']);
        $this->assertCount(4, $row['manual_steps']);
        $this->assertStringContainsString('CNAME record named al-noor.manara', $row['manual_steps'][0]);
        $this->assertStringContainsString('CLOUDFLARE_STUDIO_TOKEN', $row['manual_steps'][3]);
        Http::assertNothingSent();
    }

    #[Test]
    public function with_a_token_the_list_reads_how_many_custom_domains_the_project_has(): void
    {
        $this->actAsSuper();
        $this->withStudioToken();
        $this->fakeCloudflare(['GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['total_count' => 7])]);

        $response = $this->getJson($this->url($this->makeOrg()))->assertOk();

        $this->assertTrue($response->json('data.cloudflare.configured'));
        $this->assertSame(7, $response->json('data.cloudflare.pages_domains_used'));
        $this->assertSame(['GET'], array_map(fn ($line) => strtok($line, ' '), $this->sent()));
    }

    #[Test]
    public function a_form_encoded_post_records_the_host_and_queues_its_first_step(): void
    {
        $super = $this->actAsSuper();
        $org = $this->makeOrg();

        $custom = $this->post($this->url($org), ['kind' => 'custom', 'host' => 'WWW.New-Masjid.org.', 'zone_apex' => 'new-masjid.org'], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.domain.host', 'www.new-masjid.org')
            ->assertJsonPath('data.domain.status', MasjidDomain::STATUS_PENDING)
            ->assertJsonPath('data.domain.live_url', null);

        $managed = $this->post($this->url($org), ['kind' => 'managed_subdomain', 'label' => 'New-Masjid'], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('data.domain.host', 'new-masjid.manara.hopetechapps.com')
            ->assertJsonPath('data.domain.zone_apex', 'hopetechapps.com');

        $row = MasjidDomain::findOrFail($custom->json('data.domain.id'));
        $this->assertSame($org->id, (int) $row->masjid_id);
        $this->assertSame(MasjidDomain::SOURCE_STUDIO, $row->source);
        $this->assertSame(MasjidDomain::KIND_CUSTOM, $row->kind);
        $this->assertSame($super->id, (int) $row->created_by_user_id);

        Queue::assertPushed(AttachMasjidDomain::class, 2);
        Queue::assertPushed(AttachMasjidDomain::class, fn (AttachMasjidDomain $job) => $job->masjidDomainId === $row->id && $job->afterCommit === true);
        Queue::assertPushed(AttachMasjidDomain::class, fn (AttachMasjidDomain $job) => $job->masjidDomainId === $managed->json('data.domain.id'));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_host_already_held_gets_the_legacy_422(): void
    {
        $this->actAsSuper();
        $mec = $this->makeOrg();
        $this->makeDomain($mec, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED, 'zone_apex' => 'meccharlotte.org']);
        $this->makeDomain($mec, 'taken.manara.hopetechapps.com', MasjidDomain::STATUS_PENDING, ['kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com']);
        $other = $this->makeOrg();

        $this->post($this->url($other), ['kind' => 'custom', 'host' => 'MECcharlotte.org', 'zone_apex' => 'meccharlotte.org'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('data.host.0', "meccharlotte.org is already recorded for organisation #{$mec->id}.");

        $this->post($this->url($other), ['kind' => 'managed_subdomain', 'label' => 'taken'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['status', 'data' => ['label']]);

        $this->assertSame(2, MasjidDomain::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_single_label_or_internal_suffix_host_is_refused(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();

        foreach (['intranet', 'localhost', 'app.localhost', 'printer.local', 'db.internal', 'mec-web.pages.dev', 'api.workers.dev', '127.0.0.1', 'bücher.example'] as $host) {
            $response = $this->post($this->url($org), ['kind' => 'custom', 'host' => $host, 'zone_apex' => $host], ['Accept' => 'application/json']);

            $this->assertSame(422, $response->status(), "{$host} was accepted");
            $this->assertArrayHasKey('host', $response->json('data'), "{$host} was refused for the wrong reason");
        }

        foreach (['www', 'preview', 'mec', 'two.labels'] as $label) {
            $this->post($this->url($org), ['kind' => 'managed_subdomain', 'label' => $label], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonStructure(['data' => ['label']]);
        }

        $this->assertSame(0, MasjidDomain::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function delete_answers_204_for_a_row_nothing_outside_the_table_knows_about(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $row = $this->makeDomain($org, 'www.example.org');

        $this->deleteJson($this->url($org, "/{$row->id}"))->assertNoContent();

        $this->assertNull(MasjidDomain::find($row->id));
        Http::assertNothingSent();
    }

    #[Test]
    public function delete_answers_409_with_the_removal_steps_when_cloudflare_has_something(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();

        foreach ([
            'a Pages domain' => ['cf_pages_domain_id' => 'pd-1'],
            'a DNS record' => ['cf_dns_record_id' => 'rec-1'],
            'a zone id' => ['cf_zone_id' => 'zone-1'],
            'a zone Studio created' => ['cf_zone_created' => true],
        ] as $what => $attributes) {
            $row = $this->makeDomain($org, 'x' . md5($what) . '.example.org', MasjidDomain::STATUS_PENDING, $attributes);

            $response = $this->deleteJson($this->url($org, "/{$row->id}"))->assertStatus(409);

            $response->assertJsonPath('status', 'error');
            $this->assertNotEmpty($response->json('manual_steps'), $what);
            $this->assertNotNull(MasjidDomain::find($row->id), "a row with {$what} was deleted");
        }

        $withPages = MasjidDomain::query()->where('cf_pages_domain_id', 'pd-1')->firstOrFail();
        $this->assertStringContainsString('Custom domains, and remove ' . $withPages->host, implode(' ', $withPages->removalSteps()));
        Http::assertNothingSent();
    }

    #[Test]
    public function delete_answers_409_while_the_attacher_holds_the_row_and_deletes_nothing(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $row = $this->makeDomain($org, 'al-nor.manara.hopetechapps.com', MasjidDomain::STATUS_PENDING, [
            'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com',
        ]);

        // A step is in flight: what it has made in Cloudflare is still only in
        // its memory, so the stored row reads as deletable.
        $step = DomainAttacher::lockFor($row->id);
        $this->assertTrue($step->get());
        $this->assertTrue($row->fresh()->deletableThroughStudio(), 'the premise: the stored row looks deletable');

        $this->deleteJson($this->url($org, "/{$row->id}"))
            ->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('manual_steps', []);
        $this->assertNotNull(MasjidDomain::find($row->id), 'a row was deleted while its Cloudflare records were being made');

        // The step saves what it made and lets go: the row now says why it stays.
        $row->forceFill(['cf_zone_id' => 'zone-managed', 'cf_dns_record_id' => 'rec-1'])->save();
        $step->release();

        $this->deleteJson($this->url($org, "/{$row->id}"))->assertStatus(409);
        $this->assertNotNull(MasjidDomain::find($row->id));

        // DELETE lets go of the lock whichever way it answers.
        $clean = $this->makeDomain($org, 'www.example.org');
        $this->deleteJson($this->url($org, "/{$clean->id}"))->assertNoContent();
        $this->assertTrue(DomainAttacher::lockFor($row->id)->get());
        $this->assertTrue(DomainAttacher::lockFor($clean->id)->get());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_failed_row_cloudflare_holds_records_for_is_sent_to_check_now_not_to_remove_and_add_again(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $this->makeDomain($org, 'www.held.org', MasjidDomain::STATUS_FAILED, [
            'cf_zone_id' => 'zone-1', 'cf_dns_record_id' => 'rec-1', 'cf_pages_domain_id' => 'pd-1',
            'last_error' => 'Cloudflare had not issued a certificate for www.held.org 72 hours after it was added.',
        ]);
        $this->makeDomain($org, 'www.free.org', MasjidDomain::STATUS_FAILED, [
            'last_error' => 'www.free.org already has a DNS record (A 192.0.2.10).',
        ]);

        $rows = collect($this->getJson($this->url($org))->assertOk()->json('data.domains'))->keyBy('host');

        $held = $rows['www.held.org'];
        $this->assertFalse($held['deletable']);
        $this->assertStringContainsString('press Check now', $held['manual_steps'][1]);
        $this->assertStringNotContainsString('add it again', implode(' ', $held['manual_steps']));
        $this->assertStringNotContainsString('remove this domain', strtolower(implode(' ', $held['manual_steps'])));

        // What the step points at works, and what it no longer says does not.
        $this->deleteJson($this->url($org, "/{$held['id']}"))->assertStatus(409);
        $this->postJson($this->url($org, "/{$held['id']}/refresh"))
            ->assertOk()
            ->assertJsonPath('data.domain.status', MasjidDomain::STATUS_PENDING);

        // A row Studio may still delete is offered both ways.
        $free = $rows['www.free.org'];
        $this->assertTrue($free['deletable']);
        $this->assertStringContainsString('press Check now', $free['manual_steps'][1]);
        $this->assertStringContainsString('remove this domain', $free['manual_steps'][1]);
        Http::assertNothingSent();
    }

    #[Test]
    public function check_now_on_a_row_that_failed_at_72_hours_gives_its_new_stage_its_own_pages_retry(): void
    {
        $this->actAsSuper();
        $this->withStudioToken();
        $org = $this->makeOrg();
        $host = 'al-noor.manara.hopetechapps.com';
        $row = $this->makeDomain($org, $host, MasjidDomain::STATUS_PROVISIONING, [
            'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com',
            'cf_zone_id' => 'zone-managed', 'cf_dns_record_id' => 'rec-1', 'cf_pages_domain_id' => 'pd-1',
            'waiting_on' => 'certificate', 'stage_started_at' => now(),
        ]);
        $this->fakeCloudflare([
            'GET /zones/*/dns_records?*' => $this->cfOk([$this->dnsRecord($host, 'CNAME', 'manara-renderer.pages.dev', 'rec-1')]),
            'GET /accounts/*/pages/projects/manara-renderer/domains/' . $host => $this->cfOk($this->pagesDomainBody($host, 'pending')),
            'PATCH /accounts/*/pages/projects/manara-renderer/domains/' . $host => $this->cfOk($this->pagesDomainBody($host, 'pending')),
        ]);
        $attacher = $this->app->make(DomainAttacher::class);
        $patches = fn () => count(array_filter($this->sentToCloudflare(), fn (string $line) => str_starts_with($line, 'PATCH ')));

        // The first stage: retried once after a day, failed at 73 hours.
        $this->travel(25)->hours();
        $attacher->advance($row);
        $this->assertSame(1, $patches());
        $this->travel(48)->hours();
        $attacher->advance($row);
        $this->assertSame(MasjidDomain::STATUS_FAILED, $row->fresh()->status);

        // Check now straight away starts a new stage.
        $this->postJson($this->url($org, "/{$row->id}/refresh"))
            ->assertOk()
            ->assertJsonPath('data.domain.status', MasjidDomain::STATUS_PROVISIONING);

        // Its own day passes while the first stage's retry is under four days old.
        $this->travel(25)->hours();
        $attacher->advance($row);

        $this->assertSame(2, $patches(), 'the new stage never got its one retry at 24 hours');
        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $row->fresh()->status);
        $this->assertNotContains('DELETE', array_map(fn (string $line) => strtok($line, ' '), $this->sent()));
    }

    #[Test]
    public function an_imported_row_cannot_be_deleted(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $confirmed = ['verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now()];
        $manual = $this->makeDomain($org, 'mec.manara.hopetechapps.com', MasjidDomain::STATUS_MANUAL, $confirmed + [
            'source' => MasjidDomain::SOURCE_IMPORTED, 'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com',
        ]);
        $reserved = $this->makeDomain($org, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);

        foreach ([$manual, $reserved] as $row) {
            $this->deleteJson($this->url($org, "/{$row->id}"))
                ->assertStatus(409)
                ->assertJsonPath('message', "{$row->host} was imported from the live host map and cannot be removed through Studio.");

            $this->assertNotNull(MasjidDomain::find($row->id));
        }
    }

    #[Test]
    public function check_now_resets_a_failed_row_and_advances_it_and_leaves_a_reserved_one_alone(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $failed = $this->makeDomain($org, 'www.example.org', MasjidDomain::STATUS_FAILED, ['last_error' => 'old trouble']);
        $reserved = $this->makeDomain($org, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $reservedBefore = $reserved->fresh()->getAttributes();

        $this->postJson($this->url($org, "/{$failed->id}/refresh"))
            ->assertOk()
            ->assertJsonPath('data.domain.status', MasjidDomain::STATUS_PENDING)
            ->assertJsonPath('data.domain.waiting_on', 'token')
            ->assertJsonPath('data.domain.live_url', null);
        $this->assertStringContainsString('does not resolve', (string) $failed->fresh()->last_error);
        $this->assertNotNull($failed->fresh()->last_checked_at);

        $this->postJson($this->url($org, "/{$reserved->id}/refresh"))
            ->assertOk()
            ->assertJsonPath('data.domain.status', MasjidDomain::STATUS_RESERVED);
        $this->assertSame($reservedBefore, $reserved->fresh()->getAttributes());

        Http::assertNothingSent();
    }

    #[Test]
    public function another_organisations_row_is_not_found_through_this_one(): void
    {
        $this->actAsSuper();
        $mine = $this->makeOrg();
        $theirs = $this->makeDomain($this->makeOrg(), 'www.example.org');

        $this->postJson($this->url($mine, "/{$theirs->id}/refresh"))->assertNotFound();
        $this->deleteJson($this->url($mine, "/{$theirs->id}"))->assertNotFound();
        $this->assertNotNull(MasjidDomain::find($theirs->id));
    }
}
