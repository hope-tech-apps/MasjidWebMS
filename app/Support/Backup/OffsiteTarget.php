<?php

namespace App\Support\Backup;

/**
 * Where a verified set is copied to so that it is not only on the disk it is a
 * backup OF, and whether that destination is configured at all.
 *
 * ONE VERIFIED SET ON ONE VOLUME IS NOT A BACKUP OF THAT VOLUME. `bin/backup`
 * has said so in capitals since the day it was written: /var/backups/manara is
 * on the same 48G root filesystem as the application, so a destroyed droplet, a
 * corrupted disk or a wrong `rm` takes the backups with the thing they were
 * backing up. This class is the first half of closing that.
 *
 * IT IS OFF, AND IT IS OFF HONESTLY
 *
 * There are no credentials for this yet. Production's `.env` carries
 * AWS_ACCESS_KEY_ID and friends and every one of them is EMPTY, so anything that
 * silently reached for them would be a feature that looks wired and ships
 * nothing. So this resolves to a target that says `enabled: false` with a
 * REASON, every caller treats that as a clean no-op rather than an error, and
 * `backup:check` prints the reason on every run — an operator must not have to
 * read code to learn their backups exist in one place only.
 *
 * IT DOES NOT REUSE AWS_* , ON PURPOSE
 *
 * `config/filesystems.php`'s `s3` disk is the disk MEDIA might live on one day:
 * a bucket the application serves public images out of. The backup bucket is the
 * opposite kind of object — private, write-mostly, and holding a whole
 * organisation's database including children's records. Sharing one key pair
 * between them means the credential that serves logos can also download every
 * backup ever taken, and a leak of the web tier's configuration becomes a leak
 * of the entire history of the platform. BACKUP_OFFSITE_KEY / _SECRET are
 * therefore their own credential, expected to be scoped to the backup bucket
 * alone. It also keeps the question "is the off-site copy on?" answerable: with
 * shared keys, empty AWS_* would mean both "media is local" and "backups do not
 * leave the box", and nothing could tell the two apart.
 *
 * KEYS ARE RESTRICTED TO CHARACTERS THAT NEED NO ENCODING. A SigV4 canonical
 * request for S3 uses the URI path VERBATIM — no normalisation, no re-encoding —
 * so a key containing a space or a `+` has to be encoded identically in the URL
 * and in the string that gets signed, and a mismatch is an authentication
 * failure that looks like a credentials problem. Set ids are already sanitised
 * to `[A-Za-z0-9_.-]` by `backup:run`; the prefix comes from `.env` and is
 * validated here so a well-meant `BACKUP_OFFSITE_PREFIX="manara backups"`
 * refuses at configuration time instead of failing every upload at 02:41.
 *
 * Like MediaTarget, this is a pure function of a config array — no filesystem,
 * no network, no container — so every refusal below is unit-testable.
 */
final class OffsiteTarget
{
    /** The single-PUT ceiling S3 and every compatible store impose. */
    public const MAX_SINGLE_PUT_BYTES = 5 * 1024 * 1024 * 1024;

    private function __construct(
        public readonly bool $enabled,
        public readonly ?string $reason,
        public readonly string $bucket,
        public readonly string $region,
        public readonly string $endpoint,
        public readonly string $prefix,
        public readonly bool $pathStyle,
        public readonly ?string $encryption,
        public readonly int $maxObjectBytes,
        public readonly int $timeout,
        public readonly bool $required,
        private readonly string $key,
        private readonly string $secret,
    ) {}

    /**
     * @param  array<string, mixed>  $backup  config('backup')
     */
    public static function resolve(array $backup): self
    {
        $config = (array) ($backup['offsite'] ?? []);

        $bucket = trim((string) ($config['bucket'] ?? ''));
        $region = trim((string) ($config['region'] ?? ''));
        $endpoint = rtrim(trim((string) ($config['endpoint'] ?? '')), '/');
        $prefix = trim(trim((string) ($config['prefix'] ?? '')), '/');
        $key = (string) ($config['key'] ?? '');
        $secret = (string) ($config['secret'] ?? '');
        $encryption = trim((string) ($config['encryption'] ?? ''));
        $required = (bool) ($config['required'] ?? false);

        $maxObjectBytes = (int) ($config['max_object_bytes'] ?? self::MAX_SINGLE_PUT_BYTES);
        $maxObjectBytes = $maxObjectBytes > 0 ? min($maxObjectBytes, self::MAX_SINGLE_PUT_BYTES) : self::MAX_SINGLE_PUT_BYTES;

        $disabled = fn (string $reason) => new self(
            enabled: false,
            reason: $reason,
            bucket: $bucket,
            region: $region,
            endpoint: $endpoint,
            prefix: $prefix,
            pathStyle: (bool) ($config['path_style'] ?? false),
            encryption: $encryption === '' ? null : $encryption,
            maxObjectBytes: $maxObjectBytes,
            timeout: (int) ($config['timeout'] ?? 900),
            required: $required,
            key: '',
            secret: '',
        );

        // The master switch is explicit rather than inferred from "are the keys
        // filled in". Inferring it means a half-finished .env edit — a bucket
        // named, the secret not yet pasted — reads as "off-site is off" instead
        // of "off-site is broken", and the difference between those two is
        // whether anybody is told.
        if (($config['enabled'] ?? false) !== true) {
            return $disabled(
                'No off-site copy is configured (BACKUP_OFFSITE_ENABLED is not true), so every backup set exists '
                .'only on this machine\'s own disk. A destroyed droplet takes the backups with the thing they back up.',
            );
        }

        $missing = [];

        foreach (['BACKUP_OFFSITE_BUCKET' => $bucket, 'BACKUP_OFFSITE_REGION' => $region, 'BACKUP_OFFSITE_ENDPOINT' => $endpoint, 'BACKUP_OFFSITE_KEY' => $key, 'BACKUP_OFFSITE_SECRET' => $secret] as $name => $value) {
            if ($value === '') {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            return $disabled(sprintf(
                'The off-site copy is switched ON and cannot run: %s %s empty. Nothing is being shipped anywhere.',
                implode(', ', $missing),
                count($missing) === 1 ? 'is' : 'are',
            ));
        }

        if (! str_starts_with($endpoint, 'https://')) {
            return $disabled(sprintf(
                'BACKUP_OFFSITE_ENDPOINT is [%s]. It must be https:// — these objects are an entire organisation\'s '
                .'database and private media, including records about children, and they are not going over the wire in the clear.',
                $endpoint,
            ));
        }

        foreach (['BACKUP_OFFSITE_BUCKET' => $bucket, 'BACKUP_OFFSITE_PREFIX' => $prefix] as $name => $value) {
            if ($value !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $value) !== 1) {
                return $disabled(sprintf(
                    '%s is [%s], which contains characters that would have to be percent-encoded. A SigV4 signature '
                    .'over an S3 path uses that path verbatim, so an encoding disagreement between the URL and the '
                    .'signed string fails every upload with an error that reads like bad credentials. Use A-Z a-z 0-9 . _ - only.',
                    $name,
                    $value,
                ));
            }
        }

        return new self(
            enabled: true,
            reason: null,
            bucket: $bucket,
            region: $region,
            endpoint: $endpoint,
            prefix: $prefix,
            pathStyle: (bool) ($config['path_style'] ?? false),
            encryption: $encryption === '' ? null : $encryption,
            maxObjectBytes: $maxObjectBytes,
            timeout: (int) ($config['timeout'] ?? 900),
            required: $required,
            key: $key,
            secret: $secret,
        );
    }

    public function accessKey(): string
    {
        return $this->key;
    }

    public function secretKey(): string
    {
        return $this->secret;
    }

    /**
     * The object key for one file of one set. Mirrors the local layout exactly —
     * `<prefix>/<set id>/<file>` against `<destination>/<set id>/<file>` — so an
     * operator reading a bucket listing sees the same set ids they see in
     * /var/backups/manara, and a set fetched back down is a set this build's
     * `backup:restore` already understands.
     */
    public function keyFor(string $setId, string $file): string
    {
        $key = $setId.'/'.$file;

        return $this->prefix === '' ? $key : $this->prefix.'/'.$key;
    }

    /**
     * The path a SigV4 canonical request signs, and the path the URL carries.
     * They are the same string by construction, which is the whole reason keys
     * are restricted to characters that need no encoding.
     */
    public function canonicalPath(string $key): string
    {
        return $this->pathStyle ? '/'.$this->bucket.'/'.$key : '/'.$key;
    }

    public function host(): string
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);

        // Virtual-hosted style — `https://<bucket>.<region>.digitaloceanspaces.com/<key>`
        // — is what DigitalOcean Spaces and AWS both serve. `path_style` exists
        // for the S3-compatible stores that only do the older shape (MinIO on a
        // bare host, for one), and getting it wrong is a 404 rather than
        // anything subtle.
        if (! $this->pathStyle && $this->bucket !== '') {
            $host = $this->bucket.'.'.$host;
        }

        return $port === null ? $host : $host.':'.$port;
    }

    public function urlFor(string $key): string
    {
        return 'https://'.$this->host().$this->canonicalPath($key);
    }

    /** A description safe to print and to put in a log line: never the secret. */
    public function describe(): string
    {
        if (! $this->enabled) {
            return 'NOT CONFIGURED — '.$this->reason;
        }

        return sprintf(
            's3://%s/%s at %s (region %s, %s)',
            $this->bucket,
            $this->prefix === '' ? '' : $this->prefix.'/',
            $this->endpoint,
            $this->region,
            $this->encryption === null ? 'no SSE header sent' : 'SSE '.$this->encryption,
        );
    }
}
