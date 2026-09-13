<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\S3Signer;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The signature that decides whether the off-site copy happens at all.
 *
 * WHY THE EXPECTATION IN THE FIRST TEST DID NOT COME FROM THIS CODEBASE
 *
 * A test whose expected value was produced by running the implementation proves
 * that the implementation is consistent with itself, which is exactly what a
 * subtly wrong SigV4 also is. The hex string below was produced by BOTOCORE —
 * the signer inside the AWS SDK — signing the identical request with the
 * identical key, and it is pinned here as an external oracle. If somebody
 * "tidies" the canonical-request construction, this fails.
 *
 * Getting this wrong has one visible symptom and it is a bad one: every upload
 * is rejected with a message about credentials, forever, on a schedule nobody
 * reads. `backup:check` catches that within a day — but only because it asks the
 * store rather than trusting the shipper's own report.
 */
class S3SignerTest extends TestCase
{
    /** The exact request botocore signed, so the two are comparable line for line. */
    private const ACCESS_KEY = 'DO00EXAMPLEKEYID0000';

    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private const REGION = 'nyc3';

    private const HOST = 'manara-backups.nyc3.digitaloceanspaces.com';

    private const PATH = '/manara/20260912-024000/database.sql.gz';

    /** sha256 of the 22-byte body botocore signed. */
    private const PAYLOAD = '059f01748ef82f9528b13270baf6590683eefca49912443eef35ad6c709d1eb1';

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-12 02:40:00', new DateTimeZone('UTC'));
    }

    #[Test]
    public function it_reproduces_a_signature_computed_by_the_aws_sdk(): void
    {
        $headers = S3Signer::sign(
            method: 'PUT',
            canonicalPath: self::PATH,
            headers: [
                'host' => self::HOST,
                'content-length' => '22',
                'content-type' => 'application/gzip',
                'x-amz-server-side-encryption' => 'AES256',
            ],
            payloadSha256: self::PAYLOAD,
            accessKey: self::ACCESS_KEY,
            secretKey: self::SECRET_KEY,
            region: self::REGION,
            at: $this->at(),
        );

        $this->assertSame(
            'AWS4-HMAC-SHA256 '
            .'Credential=DO00EXAMPLEKEYID0000/20260912/nyc3/s3/aws4_request, '
            .'SignedHeaders=content-length;content-type;host;x-amz-content-sha256;x-amz-date;x-amz-server-side-encryption, '
            .'Signature=e4b158adb8f0886aafa54da9e97f7012f13e331db04aa3db9cad291400a54860',
            $headers['authorization'],
            'this expectation was produced by botocore, not by this implementation',
        );
    }

    #[Test]
    public function it_signs_the_date_and_the_payload_hash_it_sends(): void
    {
        // Both headers are part of the signature AND are sent on the wire, so a
        // caller stripping either one breaks the request. This pins that the
        // signer returns them rather than expecting the caller to add them.
        $headers = S3Signer::sign(
            method: 'HEAD',
            canonicalPath: self::PATH,
            headers: ['host' => self::HOST],
            payloadSha256: S3Signer::EMPTY_PAYLOAD_SHA256,
            accessKey: self::ACCESS_KEY,
            secretKey: self::SECRET_KEY,
            region: self::REGION,
            at: $this->at(),
        );

        $this->assertSame('20260912T024000Z', $headers['x-amz-date']);
        $this->assertSame(S3Signer::EMPTY_PAYLOAD_SHA256, $headers['x-amz-content-sha256']);
        $this->assertStringContainsString('SignedHeaders=host;x-amz-content-sha256;x-amz-date', $headers['authorization']);
    }

    #[Test]
    public function the_empty_payload_constant_really_is_the_hash_of_nothing(): void
    {
        // A wrong constant here would fail every HEAD and every DELETE — which
        // means the shipper could never confirm what it uploaded, and would fall
        // back to trusting its own 200. That is the failure shape this whole
        // slice exists to remove.
        $this->assertSame(hash('sha256', ''), S3Signer::EMPTY_PAYLOAD_SHA256);
    }

    #[Test]
    public function a_different_payload_produces_a_different_signature(): void
    {
        // The content hash is signed, so the store rejects a body that changed
        // in flight rather than storing it short. If this ever stopped being
        // true, a truncated upload would be accepted silently.
        $common = [
            'canonicalPath' => self::PATH,
            'headers' => ['host' => self::HOST],
            'accessKey' => self::ACCESS_KEY,
            'secretKey' => self::SECRET_KEY,
            'region' => self::REGION,
            'at' => $this->at(),
        ];

        // Spread FIRST: PHP refuses argument unpacking after a named argument.
        $one = S3Signer::sign(...$common, method: 'PUT', payloadSha256: str_repeat('a', 64));
        $two = S3Signer::sign(...$common, method: 'PUT', payloadSha256: str_repeat('b', 64));

        $this->assertNotSame($one['authorization'], $two['authorization']);
    }
}
