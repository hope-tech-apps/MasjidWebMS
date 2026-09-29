<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareRemover;
use App\Services\Cloudflare\CloudflareResult;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesDetachableDomains;
use Tests\Feature\Studio\Concerns\MakesRedirectDomains;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * CloudflareRemover (W2 S3), the only DELETE requests in the codebase: it
 * removes what Studio's own POST created, after a fresh read shows it
 * unchanged, and nothing else. Every request a test did not declare throws.
 */
class CloudflareRemoverTest extends TestCase
{
    use FakesCloudflare;
    use MakesDetachableDomains;
    use MakesRedirectDomains;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withStudioToken();
    }

    private function remover(): CloudflareRemover
    {
        return $this->app->make(CloudflareRemover::class);
    }

    #[Test]
    public function it_removes_an_unchanged_pages_domain_and_cname_that_studio_created(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());

        $pages = $this->remover()->removePagesDomain($row);
        $record = $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev');

        $this->assertSame(CloudflareResult::OK, $pages->outcome);
        $this->assertTrue($pages->data['removed']);
        $this->assertSame(CloudflareResult::OK, $record->outcome);
        $this->assertSame(self::RECORD, $record->data['id']);

        $calls = $this->cloudflareCalls();
        $this->assertCount(4, $calls);
        $this->assertMatchesRegularExpression('#^GET /accounts/[^/]+/pages/projects/manara-renderer/domains/www\.new-masjid\.org$#', $calls[0]);
        $this->assertMatchesRegularExpression('#^DELETE /accounts/[^/]+/pages/projects/manara-renderer/domains/www\.new-masjid\.org$#', $calls[1]);
        $this->assertSame('GET ' . $this->recordPath(), $calls[2]);
        $this->assertSame('DELETE ' . $this->recordPath(), $calls[3]);
    }

    #[Test]
    public function an_adopted_object_is_never_deleted(): void
    {
        // What W1 stores for an object it found: the same id, the flag false.
        $row = $this->attachedRow($this->makeOrg(), ['cf_dns_record_created' => false, 'cf_pages_domain_created' => false]);
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());

        $pages = $this->remover()->removePagesDomain($row);
        $record = $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev');

        foreach ([$pages, $record] as $result) {
            $this->assertSame(CloudflareResult::CONFLICT, $result->outcome);
            $this->assertSame('not_created_by_studio', $result->data['reason']);
        }
        $this->assertSame([], $this->sent(), 'refused before any request, reads included');
    }

    #[Test]
    public function a_pages_domain_on_another_project_or_host_is_not_deleted(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        Log::spy();

        // The project's domain under the row's host now answers with another
        // name, or another id: somebody replaced it.
        foreach ([
            'another host' => $this->pagesDomainBody('www.other-masjid.org', 'active'),
            'another id' => $this->pagesDomainBody(self::HOST, 'active', ['id' => 'pd-someone-else']),
        ] as $case => $body) {
            $this->fakeCloudflare(['GET ' . $this->pagesPath() => $this->cfOk($body)]);

            $result = $this->remover()->removePagesDomain($row);

            $this->assertSame(CloudflareResult::CONFLICT, $result->outcome, $case);
            $this->assertSame('changed', $result->data['reason'], $case);
        }
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'has changed since Studio created it'))->twice();

        // A domain that exists only on another project is never read or
        // deleted: the path carries this deployment's project, where the host
        // is absent.
        $this->fakeCloudflare(['GET ' . $this->pagesPath() => $this->cfError(404, 8000007, 'Domain not found.')]);
        $this->assertSame(CloudflareResult::ABSENT, $this->remover()->removePagesDomain($row)->outcome);

        $this->assertSame([], $this->deletes());
        foreach ($this->cloudflareCalls() as $call) {
            $this->assertStringContainsString('/pages/projects/manara-renderer/domains/www.new-masjid.org', $call);
        }
    }

    #[Test]
    public function a_cname_whose_content_changed_is_left_and_reported(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        Log::spy();

        foreach ([
            'repointed' => $this->dnsRecord(self::HOST, 'CNAME', 'somewhere-else.example.com', self::RECORD),
            'retyped' => $this->dnsRecord(self::HOST, 'A', '198.51.100.7', self::RECORD),
            'renamed' => $this->dnsRecord('shop.new-masjid.org', 'CNAME', 'manara-renderer.pages.dev', self::RECORD),
            'another zone' => $this->dnsRecord(self::HOST, 'CNAME', 'manara-renderer.pages.dev', self::RECORD) + ['zone_id' => 'zone-other'],
        ] as $case => $record) {
            $this->fakeCloudflare(['GET ' . $this->recordPath() => $this->cfOk($record)]);

            $result = $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev');

            $this->assertSame(CloudflareResult::CONFLICT, $result->outcome, $case);
            $this->assertSame('changed', $result->data['reason'], $case);
            $this->assertStringContainsString('left it alone', (string) $result->error, $case);
        }

        $this->assertSame([], $this->deletes());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'DNS record'))->times(4);
    }

    #[Test]
    public function the_record_is_matched_however_cloudflare_spells_it(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        $this->fakeCloudflare([
            'GET ' . $this->recordPath() => $this->cfOk($this->dnsRecord('WWW.New-Masjid.org.', 'cname', 'Manara-Renderer.pages.dev.', self::RECORD)),
            'DELETE ' . $this->recordPath() => $this->cfOk(['id' => self::RECORD]),
        ]);

        $this->assertSame(CloudflareResult::OK, $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev')->outcome);
    }

    #[Test]
    public function a_redirect_rows_a_record_is_removed_by_its_own_expected_shape(): void
    {
        // S5's redirect host is a proxied A record at the documentation
        // address; the caller names that shape and the remover holds it to it.
        $row = $this->attachedRow($this->makeOrg());
        $placeholder = $this->dnsRecord(self::HOST, 'A', '192.0.2.1', self::RECORD);

        $this->fakeCloudflare([
            'GET ' . $this->recordPath() => $this->cfOk($placeholder),
            'DELETE ' . $this->recordPath() => $this->cfOk(['id' => self::RECORD]),
        ]);
        $this->assertSame(CloudflareResult::CONFLICT, $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev')->outcome);
        $this->assertSame([], $this->deletes());

        $this->assertSame(CloudflareResult::OK, $this->remover()->removeDnsRecord($row, 'A', '192.0.2.1')->outcome);
        $this->assertSame(['DELETE ' . $this->recordPath()], $this->deletes());
    }

    #[Test]
    public function an_imported_or_adopted_row_is_refused_before_any_request(): void
    {
        $org = $this->makeOrg();
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());

        $imported = $this->attachedRow($org, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $adopted = $this->makeDomain($org, 'meccharlotte.org', MasjidDomain::STATUS_PENDING, [
            'adopted_from_import_at' => now(),
            'cf_zone_id' => self::ZONE, 'cf_dns_record_id' => 'rec-2', 'cf_dns_record_created' => true,
            'cf_pages_domain_id' => 'pd-2', 'cf_pages_domain_created' => true,
        ]);

        foreach ([$imported, $adopted] as $row) {
            foreach ([
                $this->remover()->removePagesDomain($row),
                $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev'),
            ] as $result) {
                $this->assertSame(CloudflareResult::CONFLICT, $result->outcome, $row->host);
                $this->assertSame('imported', $result->data['reason'], $row->host);
            }
        }

        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function without_a_token_nothing_is_sent(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        config(['cloudflare.studio_token' => null]);
        $this->fakeCloudflare([]);

        $this->assertSame(CloudflareResult::NOT_CONFIGURED, $this->remover()->removePagesDomain($row)->outcome);
        $this->assertSame(CloudflareResult::NOT_CONFIGURED, $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev')->outcome);
        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function an_object_already_gone_is_absent_and_a_failed_delete_is_returned(): void
    {
        $row = $this->attachedRow($this->makeOrg());

        $this->fakeCloudflare([
            'GET ' . $this->pagesPath() => $this->cfOk($this->pagesDomainBody(self::HOST, 'active')),
            'DELETE ' . $this->pagesPath() => $this->cfError(404, 8000007, 'Domain not found.'),
            'GET ' . $this->recordPath() => $this->cfError(404, 81044, 'Record does not exist.'),
        ]);
        $this->assertSame(CloudflareResult::ABSENT, $this->remover()->removePagesDomain($row)->outcome);
        $this->assertSame(CloudflareResult::ABSENT, $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev')->outcome);

        $this->fakeCloudflare([
            'GET ' . $this->pagesPath() => $this->cfOk($this->pagesDomainBody(self::HOST, 'active')),
            'DELETE ' . $this->pagesPath() => $this->cfError(403, 10000, 'Authentication error'),
            'GET ' . $this->recordPath() => fn (Request $r) => throw new ConnectException('cURL error 28: Operation timed out', $r->toPsrRequest()),
        ]);
        $this->assertSame(CloudflareResult::UNAUTHORIZED, $this->remover()->removePagesDomain($row)->outcome);
        $this->assertSame(CloudflareResult::TRANSIENT, $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev')->outcome);
    }

    #[Test]
    public function a_redirect_rule_is_deleted_by_id_only_while_it_carries_studios_ref(): void
    {
        // W2 S5. One rule goes; the client's own rule and the ruleset stay.
        $org = $this->makeOrg();
        $apex = $this->redirectRow($org, $this->canonicalRow($org), MasjidDomain::STATUS_MANUAL, [
            'cf_zone_id' => self::PAIR_ZONE, 'cf_redirect_rule_id' => 'rule-studio',
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(),
        ]);

        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->clientRule(), $this->studioRule($apex)])),
            'DELETE /zones/zone-pair/rulesets/rs-1/rules/rule-studio' => $this->cfOk($this->ruleset([$this->clientRule()])),
        ]);
        $this->assertSame(CloudflareResult::OK, $this->remover()->removeRedirectRule($apex)->outcome);
        $this->assertSame(['DELETE /zones/zone-pair/rulesets/rs-1/rules/rule-studio'], $this->deletes());

        // The id now names a rule someone else wrote: left alone.
        $foreign = ['ref' => 'someone-else'] + $this->studioRule($apex);
        $this->fakeCloudflare(['GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->clientRule(), $foreign]))]);
        Log::spy();
        $changed = $this->remover()->removeRedirectRule($apex);
        $this->assertSame(CloudflareResult::CONFLICT, $changed->outcome);
        $this->assertSame('changed', $changed->data['reason']);

        // Gone already, or no entry point at all: absent, nothing deleted.
        $this->fakeCloudflare(['GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->clientRule()]))]);
        $this->assertSame(CloudflareResult::ABSENT, $this->remover()->removeRedirectRule($apex)->outcome);
        $this->fakeCloudflare(['GET ' . self::ENTRYPOINT => $this->cfError(404, 10003, 'Not found')]);
        $this->assertSame(CloudflareResult::ABSENT, $this->remover()->removeRedirectRule($apex)->outcome);

        $this->assertCount(1, $this->deletes());
    }

    #[Test]
    public function no_method_can_delete_a_zone(): void
    {
        $public = array_map(
            fn (ReflectionMethod $method) => strtolower($method->getName()),
            (new ReflectionClass(CloudflareRemover::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['__construct', 'removepagesdomain', 'removednsrecord', 'removeredirectrule'], $public);
        foreach ($public as $name) {
            $this->assertStringNotContainsString('zone', $name);
        }

        // And no path the class can build ends at a zone: every `/zones/`
        // it writes goes on to a DNS record or (W2 S5) one rule of a ruleset.
        $source = (string) file_get_contents((new ReflectionClass(CloudflareRemover::class))->getFileName());
        preg_match_all("#'/zones/'[^;]*;#", $source, $paths);
        $this->assertCount(2, $paths[0]);
        foreach ($paths[0] as $path) {
            $this->assertTrue(
                str_contains($path, "'/dns_records/'") || (str_contains($path, "'/rulesets/'") && str_contains($path, "'/rules/'")),
                "a zone path that is neither a record nor a rule: {$path}",
            );
        }
    }

    #[Test]
    public function the_token_never_appears_in_a_log_line(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        Log::spy();
        $this->fakeCloudflare([
            'GET ' . $this->pagesPath() => $this->cfOk($this->pagesDomainBody(self::HOST, 'active')),
            'DELETE ' . $this->pagesPath() => $this->cfError(403, 10000, 'Authentication error for token ' . self::TOKEN),
            'GET ' . $this->recordPath() => fn (Request $r) => throw new ConnectException('Could not connect with Bearer ' . self::TOKEN, $r->toPsrRequest()),
        ]);

        $refused = $this->remover()->removePagesDomain($row);
        $unreachable = $this->remover()->removeDnsRecord($row, 'CNAME', 'manara-renderer.pages.dev');

        $this->assertStringContainsString('[redacted]', (string) $refused->error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $refused->error . $unreachable->error);
        Log::shouldHaveReceived('warning')->times(2);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ! str_contains($message . json_encode($context), self::TOKEN))
            ->times(2);

        // And it went only where it belongs, in the Authorization header.
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
    }
}
