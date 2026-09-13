<?php

namespace App\Support\Backup;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AWS Signature Version 4, for talking to an S3-compatible object store over
 * plain HTTPS.
 *
 * WHY THIS IS HAND-WRITTEN AND NOT A LIBRARY
 *
 * There are two other ways to put an object in a bucket from this application
 * and this build can do neither. `composer.lock` carries `league/flysystem-local`
 * and NOT `league/flysystem-aws-s3-v3`, so `Storage::disk('s3')` cannot move a
 * byte — config/backup.php has said so since the media half was written. And
 * there is no `aws` binary on the droplet, which is why `backup.media.strategies.s3`
 * is still unset. Either route therefore turns the off-site copy into a SECOND
 * one-time host preparation step somebody has to remember.
 *
 * That is precisely the bug this whole task exists because of. `backup:run` was
 * scheduled nightly for weeks and wrote nothing, because /var/backups/manara did
 * not exist and `sudo bin/backup --install` was never run. Adding "and also
 * install the aws CLI, and also add a composer dependency" to the list of things
 * that must be done before backups leave the machine is the same trap with a new
 * name. Signing a request is eighty lines of HMAC that this file can carry
 * itself, so the off-site copy turns on with credentials and nothing else.
 *
 * VERIFIED AGAINST AN INDEPENDENT IMPLEMENTATION. Getting this subtly wrong
 * would mean every upload is rejected — or, worse, that it looks configured and
 * never lands. So the vector in tests/Unit/Backup/S3SignerTest was not written
 * by reading this file back: it was produced by botocore (the AWS SDK's own
 * signer) over the identical request, and this implementation reproduces it byte
 * for byte. A test whose expectation came from the code it tests proves only
 * that the code is consistent with itself.
 *
 * THE PAYLOAD HASH IS NOT A FORMALITY. `x-amz-content-sha256` is signed, and the
 * store recomputes it over what it actually received: a request whose body was
 * truncated in flight is REJECTED rather than stored short. The shipper passes
 * the sha256 the manifest already recorded for that half, so the number that
 * proves a local set is intact is the same number the store checks the upload
 * against. There is no second definition of "these are the right bytes".
 *
 * SCOPE: what this deliberately does NOT do. No query-string (presigned) URLs,
 * no multipart uploads, no chunked/streaming signatures, and no anonymous or
 * session-token credentials. A set is three objects of a few megabytes each; the
 * shipper refuses anything over the single-PUT ceiling by name rather than
 * silently doing half of a multipart it cannot finish.
 */
final class S3Signer
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    /** sha256 of the empty string — the payload hash for a GET, HEAD or DELETE. */
    public const EMPTY_PAYLOAD_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * Sign one request and return the full header map to send, the caller's
     * headers included.
     *
     * `$headers` must already carry every header that is part of the request
     * (`host` included) EXCEPT `x-amz-date` and `authorization`, which this
     * method adds. Every header passed in is signed: an S3-compatible store
     * verifies the signature over exactly the set named in `SignedHeaders`, so
     * a header added afterwards by the HTTP client (`user-agent`, `expect`) is
     * simply not covered, and a header REMOVED afterwards breaks the request.
     * The shipper therefore sends this map verbatim.
     *
     * @param  array<string, string|int>  $headers
     * @param  string  $payloadSha256  hex sha256 of the body; EMPTY_PAYLOAD_SHA256 for a bodiless request
     * @return array<string, string>
     */
    public static function sign(
        string $method,
        string $canonicalPath,
        array $headers,
        string $payloadSha256,
        string $accessKey,
        string $secretKey,
        string $region,
        string $service = 's3',
        ?DateTimeInterface $at = null,
    ): array {
        $at = ($at ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $at->format('Ymd\THis\Z');
        $dateStamp = $at->format('Ymd');

        // Lower-cased names, trimmed values, sorted by name. The store rebuilds
        // this from what it received and compares; a difference of one space is
        // a SignatureDoesNotMatch and nothing else.
        $canonical = [];

        foreach ($headers as $name => $value) {
            $canonical[strtolower(trim((string) $name))] = trim((string) $value);
        }

        $canonical['x-amz-date'] = $amzDate;
        $canonical['x-amz-content-sha256'] = $payloadSha256;

        ksort($canonical, SORT_STRING);

        $canonicalHeaders = '';

        foreach ($canonical as $name => $value) {
            $canonicalHeaders .= $name.':'.$value."\n";
        }

        $signedHeaders = implode(';', array_keys($canonical));

        // No query string is ever used here, hence the empty fourth line. The
        // path is passed through UNCHANGED: S3 is the one AWS service that does
        // not normalise the canonical URI, so a caller that encoded a key must
        // present the same encoding here. OffsiteTarget restricts keys to
        // characters that need no encoding at all, so the two cannot disagree.
        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $canonicalPath,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadSha256,
        ]);

        $scope = $dateStamp.'/'.$region.'/'.$service.'/aws4_request';

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $key = 'AWS4'.$secretKey;

        foreach ([$dateStamp, $region, $service, 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }

        $signature = hash_hmac('sha256', $stringToSign, $key);

        $canonical['authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $accessKey,
            $scope,
            $signedHeaders,
            $signature,
        );

        return $canonical;
    }
}
