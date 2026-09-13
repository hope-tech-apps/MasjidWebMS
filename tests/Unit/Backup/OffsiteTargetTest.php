<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\OffsiteTarget;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whether the off-site copy is on, off, or misconfigured — and the difference
 * between the last two, which is the whole reason this class has a `reason`
 * field instead of a boolean.
 *
 * Production today is the first case: switched off, no credentials, one copy of
 * every backup on the same volume it is a backup of. That must be a clean no-op
 * that SAYS SO, not an error and not a silence.
 */
class OffsiteTargetTest extends TestCase
{
    private function config(array $offsite = []): array
    {
        return ['offsite' => array_merge([
            'enabled' => true,
            'bucket' => 'manara-backups',
            'region' => 'nyc3',
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'prefix' => 'manara',
            'key' => 'DO00KEY',
            'secret' => 'shh',
            'encryption' => 'AES256',
        ], $offsite)];
    }

    #[Test]
    public function an_unconfigured_offsite_is_disabled_with_a_reason_and_not_an_error(): void
    {
        $target = OffsiteTarget::resolve(['offsite' => ['enabled' => false]]);

        $this->assertFalse($target->enabled);
        $this->assertStringContainsString('only on this machine', (string) $target->reason);
        // The sentence an operator reads in `backup:check`, every run.
        $this->assertStringContainsString('NOT CONFIGURED', $target->describe());
    }

    #[Test]
    public function switched_on_with_a_missing_secret_is_broken_and_says_which_variable(): void
    {
        // Distinct from "off". A half-finished .env edit must not read as a
        // deliberate decision not to have off-site backups.
        $target = OffsiteTarget::resolve($this->config(['secret' => '']));

        $this->assertFalse($target->enabled);
        $this->assertStringContainsString('BACKUP_OFFSITE_SECRET', (string) $target->reason);
        $this->assertStringContainsString('switched ON and cannot run', (string) $target->reason);
    }

    #[Test]
    public function a_plain_http_endpoint_is_refused(): void
    {
        // These objects are an organisation's whole database including
        // children's records. They do not travel in the clear.
        $target = OffsiteTarget::resolve($this->config(['endpoint' => 'http://nyc3.digitaloceanspaces.com']));

        $this->assertFalse($target->enabled);
        $this->assertStringContainsString('must be https', (string) $target->reason);
    }

    #[Test]
    public function a_prefix_that_would_need_percent_encoding_is_refused_at_configuration_time(): void
    {
        // A SigV4 signature over an S3 path uses that path verbatim, so an
        // encoding disagreement between the URL and the signed string fails
        // every upload with an error that reads like bad credentials. Better to
        // refuse the setting than to debug that at 02:41 for a week.
        $target = OffsiteTarget::resolve($this->config(['prefix' => 'manara backups']));

        $this->assertFalse($target->enabled);
        $this->assertStringContainsString('percent-encoded', (string) $target->reason);
    }

    #[Test]
    public function the_key_layout_mirrors_the_local_set_layout(): void
    {
        // So an operator reading a bucket listing sees the same set ids they see
        // in /var/backups/manara, and a set fetched back down is one this
        // build's `backup:restore` already understands.
        $target = OffsiteTarget::resolve($this->config());

        $this->assertSame('manara/20260912-024000/database.sql.gz', $target->keyFor('20260912-024000', 'database.sql.gz'));
        $this->assertSame(
            'https://manara-backups.nyc3.digitaloceanspaces.com/manara/20260912-024000/database.sql.gz',
            $target->urlFor($target->keyFor('20260912-024000', 'database.sql.gz')),
        );
    }

    #[Test]
    public function the_signed_path_and_the_url_path_are_the_same_string(): void
    {
        // The property the whole signature depends on, in both addressing modes.
        foreach ([false, true] as $pathStyle) {
            $target = OffsiteTarget::resolve($this->config(['path_style' => $pathStyle]));
            $key = $target->keyFor('20260912-024000', 'manifest.json');

            $this->assertStringEndsWith($target->canonicalPath($key), $target->urlFor($key));
        }
    }

    #[Test]
    public function the_secret_never_appears_in_the_description_that_goes_into_a_log_line(): void
    {
        $target = OffsiteTarget::resolve($this->config(['secret' => 'super-secret-value']));

        $this->assertStringNotContainsString('super-secret-value', $target->describe());
        $this->assertStringNotContainsString('DO00KEY', $target->describe());
    }

    #[Test]
    public function the_single_put_ceiling_cannot_be_configured_above_what_s3_accepts(): void
    {
        // Past 5 GiB the correct answer is multipart, which is deliberately not
        // implemented — an abandoned multipart bills monthly and restores
        // nothing. Raising the number must not turn that refusal off.
        $target = OffsiteTarget::resolve($this->config(['max_object_bytes' => 50 * 1024 * 1024 * 1024]));

        $this->assertSame(OffsiteTarget::MAX_SINGLE_PUT_BYTES, $target->maxObjectBytes);
    }
}
