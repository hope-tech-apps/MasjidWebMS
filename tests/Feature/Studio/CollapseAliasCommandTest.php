<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesRedirectDomains;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * `domains:collapse-alias` (W2 S5): one host of an apex/www pair becomes a
 * redirect and gives back its Pages slot, and only once the 301 is seen.
 */
class CollapseAliasCommandTest extends TestCase
{
    use FakesCloudflare;
    use MakesRedirectDomains;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withStudioToken();
        $this->resolveTo(['93.184.216.34']);
        config(['cloudflare.redirect_zones' => [self::APEX]]);
        Sleep::fake();
    }

    /** Both hosts serving, both attached by Studio: the shape before S5. */
    private function twoServingHosts(): array
    {
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $apex = $this->canonicalRow($org, [
            'host' => self::APEX,
            'cf_dns_record_id' => 'rec-apex',
            'cf_pages_domain_id' => 'pd-' . md5(self::APEX),
        ]);

        return [$org, $www, $apex];
    }

    /** @return array<string, mixed> */
    private function collapse(MasjidDomain $row, bool $execute = false, int $expect = 0): array
    {
        $this->assertSame($expect, Artisan::call('domains:collapse-alias', array_filter([
            'domain_id' => $row->id,
            '--execute' => $execute,
            '--operator' => $execute ? 'owner' : null,
            '--reason' => $execute ? 'free a Pages slot' : null,
            '--json' => true,
        ])));

        return json_decode(Artisan::output(), true);
    }

    /** @return array<string, mixed> */
    private function cloudflareForCollapse(MasjidDomain $apex): array
    {
        return [
            'GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->clientRule()])),
            'POST /zones/zone-pair/rulesets/rs-1/rules' => $this->cfOk($this->ruleset([$this->clientRule(), $this->studioRule($apex)])),
            'GET /accounts/*/pages/projects/manara-renderer/domains/' . self::APEX => $this->cfOk($this->pagesDomainBody(self::APEX, 'active')),
            'DELETE /accounts/*/pages/projects/manara-renderer/domains/' . self::APEX => $this->cfOk([]),
        ];
    }

    #[Test]
    public function the_dry_run_writes_nothing(): void
    {
        [, $www, $apex] = $this->twoServingHosts();
        $this->fakeCloudflare([]);
        $before = $apex->fresh()->getAttributes();

        $out = $this->collapse($apex);

        $this->assertSame('would_collapse', $out['outcome']);
        $this->assertSame(self::WWW, $out['redirects_to']);
        $this->assertTrue($out['removes_pages_domain']);
        $this->assertSame([], $this->sent());
        $this->assertSame($before, $apex->fresh()->getAttributes());
    }

    #[Test]
    public function execute_removes_the_pages_domain_only_after_the_redirect_verifies(): void
    {
        [, $www, $apex] = $this->twoServingHosts();
        $this->fakeCloudflare($this->cloudflareForCollapse($apex) + $this->apexAnswers());

        $out = $this->collapse($apex, true);
        $apex->refresh();

        $this->assertSame('collapsed', $out['outcome']);
        $this->assertSame(MasjidDomain::ROLE_REDIRECT, $apex->role);
        $this->assertSame($www->id, (int) $apex->redirect_to_id);
        $this->assertSame('rule-studio', $apex->cf_redirect_rule_id);
        $this->assertNull($apex->cf_pages_domain_id);
        $this->assertSame('rec-apex', $apex->cf_dns_record_id, 'the proxied CNAME stays; the rule answers first');
        $this->assertSame(MasjidDomain::STATUS_MANUAL, $apex->status);
        $this->assertNull($apex->serving_confirmed_at);
        $this->assertFalse(MasjidDomain::query()->served()->whereKey($apex->id)->exists());
        $this->assertTrue(MasjidDomain::query()->corsAdmitted()->whereKey($www->id)->exists(), 'the canonical host is untouched');

        // In this order: the rule, the 301 seen, then the Pages domain.
        $lines = $this->sent();
        $rule = array_search('POST ' . self::API . '/zones/zone-pair/rulesets/rs-1/rules', $lines, true);
        $probe = array_search('GET https://' . self::APEX . '/', $lines, true);
        $delete = collect($lines)->search(fn (string $line) => str_starts_with($line, 'DELETE ') && str_ends_with($line, '/domains/' . self::APEX));
        $this->assertIsInt($rule);
        $this->assertIsInt($probe);
        $this->assertIsInt($delete);
        $this->assertTrue($rule < $probe && $probe < $delete);
        $this->assertCount(1, array_filter($lines, fn (string $line) => str_starts_with($line, 'DELETE ')));
    }

    #[Test]
    public function a_redirect_that_never_verifies_is_taken_out_and_nothing_else_changes(): void
    {
        [, , $apex] = $this->twoServingHosts();
        // The entry point as the rule is appended, then as the remover reads
        // it again: holding Studio's rule, which it takes back out.
        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => Http::sequence()
                ->push(['success' => true, 'errors' => [], 'result' => $this->ruleset([$this->clientRule()])])
                ->push(['success' => true, 'errors' => [], 'result' => $this->ruleset([$this->clientRule(), $this->studioRule($apex)])]),
            'DELETE /zones/zone-pair/rulesets/rs-1/rules/rule-studio' => $this->cfOk($this->ruleset([$this->clientRule()])),
        ] + $this->cloudflareForCollapse($apex) + $this->apexAnswers(200, ''));
        $before = $apex->fresh();

        $out = $this->collapse($apex, true, 1);
        $apex->refresh();

        $this->assertSame('failed', $out['outcome']);
        $this->assertStringContainsString('taken out again', $out['reason']);
        $this->assertSame(MasjidDomain::ROLE_SERVING, $apex->role);
        $this->assertNull($apex->cf_redirect_rule_id);
        $this->assertSame($before->cf_pages_domain_id, $apex->cf_pages_domain_id);
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $apex->status);
        $deletes = array_values(array_filter($this->sent(), fn (string $line) => str_starts_with($line, 'DELETE ')));
        $this->assertSame(['DELETE ' . self::API . '/zones/zone-pair/rulesets/rs-1/rules/rule-studio'], $deletes, 'only its own rule; never the Pages domain');
    }

    #[Test]
    public function an_imported_row_or_a_zone_not_allowed_is_refused_before_any_request(): void
    {
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $imported = $this->canonicalRow($org, ['host' => self::APEX, 'source' => MasjidDomain::SOURCE_IMPORTED]);
        $this->fakeCloudflare([]);

        $this->assertSame('refused', $this->collapse($imported, true, 1)['outcome']);

        // A Studio row in the same place (written past the model: since W2 S6
        // only `domains:imported` may turn an imported row into another kind).
        MasjidDomain::query()->whereKey($imported->id)->update(['source' => MasjidDomain::SOURCE_STUDIO]);
        $imported->refresh();
        config(['cloudflare.redirect_zones' => []]);
        $out = $this->collapse($imported, true, 1);
        $this->assertStringContainsString('cloudflare.redirect_zones', $out['reason']);

        $this->assertSame([], $this->sent());
        $this->assertSame(MasjidDomain::ROLE_SERVING, $imported->fresh()->role);
        $this->assertNotNull($www->fresh());
    }

    #[Test]
    public function a_host_without_a_confirmed_sibling_is_refused(): void
    {
        $org = $this->makeOrg();
        $apex = $this->canonicalRow($org, ['host' => self::APEX]);
        $this->fakeCloudflare([]);

        $this->assertStringContainsString('no apex/www sibling', $this->collapse($apex, true, 1)['reason']);
    }

    #[Test]
    public function an_adopted_rule_from_an_earlier_attempt_is_taken_out_too(): void
    {
        [, , $apex] = $this->twoServingHosts();
        // The zone already holds this row's own rule, left by an attempt whose
        // clean-up never finished: adopted now, and removed again on failure.
        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->clientRule(), $this->studioRule($apex)])),
            'DELETE /zones/zone-pair/rulesets/rs-1/rules/rule-studio' => $this->cfOk($this->ruleset([$this->clientRule()])),
        ] + $this->apexAnswers(200, ''));

        $this->assertSame('failed', $this->collapse($apex, true, 1)['outcome']);

        $this->assertNull($apex->fresh()->cf_redirect_rule_id);
        $this->assertSame(['DELETE ' . self::API . '/zones/zone-pair/rulesets/rs-1/rules/rule-studio'],
            array_values(array_filter($this->sent(), fn (string $line) => str_starts_with($line, 'DELETE '))));
    }
}
