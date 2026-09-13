<?php

namespace App\Support\Backup;

/**
 * Copy one verified set to the off-site store — BOTH HALVES OR NEITHER.
 *
 * WHY ATOMICITY IS THE WHOLE DESIGN AND NOT A REFINEMENT
 *
 * On 2026-08-17 the `media` table emptied platform-wide and all four backups on
 * the server could restore the ROWS and not one held a FILE. Restoring one of
 * them RE-CREATES 226 rows pointing at files that are gone, which is the outage
 * itself, applied by somebody who believes they are fixing it. `.claude/rules/
 * backups.md` states the conclusion in one line: a set is BOTH HALVES OR IT IS
 * NOTHING. Shipping a database and losing the media off-site would reproduce
 * that exact artefact in a bucket — and the bucket is where somebody reaches
 * during the worst hour of the platform's life, when the local disk is gone and
 * nobody is going to audit what they find there.
 *
 * An object store has no transaction, so atomicity here is the same trick
 * `backup:run` already uses locally, unchanged and for the same reason:
 *
 *   THE MANIFEST IS THE SET, AND IT IS WRITTEN LAST.
 *
 * `BackupSet::all()` skips any directory without a `manifest.json`, so a remote
 * prefix holding one half and no manifest is not a set — it is the bucket's
 * spelling of the local `*.partial`, and nothing will ever restore it. The
 * halves go up first, each is HEADed back to confirm the store really has it at
 * the byte length we sent, and only then does the manifest follow. A run that
 * dies at any point before that last PUT leaves no set behind, only litter, and
 * this class deletes its own litter on the way out.
 *
 * REPAIRING A REMOTE SET THAT IS ALREADY BROKEN. If the manifest is up there and
 * a half is not — a ship killed between the two PUTs on some earlier night, in
 * the one window that could produce it — the manifest is DELETED FIRST, before
 * anything is re-uploaded. The invariant has to hold at every instant, not just
 * at the end: a remote set must never be readable as complete while a half of it
 * is missing, not even for the four seconds it takes to upload the other half.
 *
 * WHAT IT WILL NOT DO. It will not ship a set that does not pass
 * `BackupSet::problems()` — the same function `backup:run` uses to verify what
 * it just wrote and `backup:restore` uses to decide it may proceed. There is no
 * second opinion in this file about what a valid set is, on purpose: two
 * definitions of "complete" drift, and the one that drifts is always the one
 * that gets read at 3am.
 *
 * IT NEVER THROWS AND IT NEVER FAILS THE BACKUP. By the time this runs, a
 * complete set has already been written and verified on local disk. A store
 * that is down, a credential that has been rotated, a bucket somebody deleted —
 * none of that may turn a successful backup into a failed one. It comes back as
 * a status string, `backup:run` puts it in its single log line, and
 * `backup:check` is what grades it.
 */
final class OffsiteShipper
{
    public const NOT_CONFIGURED = 'not_configured';

    public const SHIPPED = 'shipped';

    public const ALREADY = 'already';

    public const REFUSED = 'refused';

    public const FAILED = 'failed';

    public const ABSENT = 'absent';

    public const INCOMPLETE = 'incomplete';

    public const UNREACHABLE = 'unreachable';

    public const PRESENT = 'present';

    public function __construct(
        private readonly OffsiteTarget $target,
        private readonly OffsiteStore $store,
    ) {}

    public static function forConfig(array $backupConfig): self
    {
        $target = OffsiteTarget::resolve($backupConfig);

        return new self($target, new OffsiteStore($target));
    }

    public function target(): OffsiteTarget
    {
        return $this->target;
    }

    /**
     * The three files of a set and how each is described to the store.
     *
     * @return array<string, string>
     */
    private static function files(): array
    {
        return [
            BackupSet::DATABASE_FILE => 'application/gzip',
            BackupSet::MEDIA_FILE => 'application/gzip',
            BackupSet::MANIFEST_FILE => 'application/json',
        ];
    }

    /**
     * Is this set off-site, in one piece?
     *
     * Read-only, and it distinguishes three answers a caller must not collapse:
     * the set is there (`present`), the store says it is not (`absent` /
     * `incomplete`), and the store could not be asked (`unreachable`). The last
     * one is graded as a failure by `backup:check` rather than as a shrug, for
     * the reason BackupRun's disk-coverage check gives: a claim nobody could
     * check is not one to write down, and "I could not confirm there is an
     * off-site copy" is operationally the same position as not having one.
     *
     * @return array{status: string, reason: ?string, objects: array<string, mixed>}
     */
    public function verify(BackupSet $set): array
    {
        if (! $this->target->enabled) {
            return ['status' => self::NOT_CONFIGURED, 'reason' => $this->target->reason, 'objects' => []];
        }

        $objects = [];
        $missing = [];

        foreach (array_keys(self::files()) as $file) {
            $local = $set->path.'/'.$file;
            $key = $this->target->keyFor($set->id(), $file);
            $head = $this->store->head($key);

            if (! $head['ok']) {
                return ['status' => self::UNREACHABLE, 'reason' => $head['error'], 'objects' => $objects];
            }

            $localBytes = is_file($local) ? (int) filesize($local) : null;
            $matches = $head['found'] && ($head['bytes'] === null || $localBytes === null || $head['bytes'] === $localBytes);

            $objects[$file] = [
                'key' => $key,
                'found' => $head['found'],
                'remote_bytes' => $head['bytes'],
                'local_bytes' => $localBytes,
                'size_matches' => $matches,
            ];

            if (! $matches) {
                $missing[] = $file;
            }
        }

        if ($missing === []) {
            return ['status' => self::PRESENT, 'reason' => null, 'objects' => $objects];
        }

        // All three gone is "this set was never shipped". Some of them gone is
        // worse than that and gets its own word, because it is the shape the
        // 2026-08-17 dumps had: something that looks like a backup and is half
        // of one.
        $everythingMissing = count($missing) === count(self::files());

        return [
            'status' => $everythingMissing ? self::ABSENT : self::INCOMPLETE,
            'reason' => $everythingMissing
                ? sprintf('No object of set %s is in the off-site store.', $set->id())
                : sprintf(
                    'Set %s is off-site as %s of %d objects — %s missing or the wrong size. A part of a set restores nothing.',
                    $set->id(),
                    count(self::files()) - count($missing),
                    count(self::files()),
                    implode(', ', $missing),
                ),
            'objects' => $objects,
        ];
    }

    /**
     * Copy the set up, or explain why nothing was copied.
     *
     * @return array{status: string, set: string, reason: ?string, detail: list<string>, bytes: int, objects: list<string>}
     */
    public function ship(BackupSet $set): array
    {
        $result = fn (string $status, ?string $reason = null, array $detail = [], int $bytes = 0, array $objects = []) => [
            'status' => $status,
            'set' => $set->id(),
            'reason' => $reason,
            'detail' => $detail,
            'bytes' => $bytes,
            'objects' => $objects,
        ];

        if (! $this->target->enabled) {
            return $result(self::NOT_CONFIGURED, $this->target->reason);
        }

        // THE REFUSAL, and it is the same one `backup:restore` makes. A set with
        // a missing half, a half that does not match its recorded sha256, or a
        // manifest that says `complete: false` is not a thing to put in the
        // place somebody reaches for when everything else is gone.
        $problems = $set->problems();

        if ($problems !== []) {
            return $result(
                self::REFUSED,
                sprintf('Set %s did not verify locally, so nothing was shipped.', $set->id()),
                $problems,
            );
        }

        $manifest = (array) $set->manifest();

        // Reuse the hashes the manifest already recorded. Re-hashing here would
        // be a second opinion about the same bytes, and `problems()` has just
        // confirmed the files match these numbers.
        $hashes = [
            BackupSet::DATABASE_FILE => (string) ($manifest['halves']['database']['sha256'] ?? ''),
            BackupSet::MEDIA_FILE => (string) ($manifest['halves']['media']['sha256'] ?? ''),
            // The manifest cannot record its own hash. It is the only file of
            // the three hashed here, and it is hashed after `problems()` has
            // read it, so the bytes signed are the bytes checked.
            BackupSet::MANIFEST_FILE => (string) hash_file('sha256', $set->manifestPath()),
        ];

        foreach ($hashes as $file => $hash) {
            if ($hash === '') {
                return $result(self::REFUSED, sprintf(
                    'The manifest of set %s records no sha256 for %s, so the upload could not be integrity-checked by the store. Nothing was shipped.',
                    $set->id(),
                    $file,
                ));
            }
        }

        $existing = $this->verify($set);

        if ($existing['status'] === self::UNREACHABLE) {
            return $result(self::FAILED, sprintf('The off-site store could not be reached, so set %s was not shipped.', $set->id()), [(string) $existing['reason']]);
        }

        if ($existing['status'] === self::PRESENT) {
            return $result(self::ALREADY, sprintf('Set %s is already off-site, in one piece.', $set->id()));
        }

        // A remote manifest with a half missing is a remote set that reads as
        // complete and is not. Take the manifest away FIRST so the prefix stops
        // being a set for the whole of the repair, rather than only at the end.
        if ($existing['status'] === self::INCOMPLETE && (($existing['objects'][BackupSet::MANIFEST_FILE]['found'] ?? false) === true)) {
            $removal = $this->store->delete($this->target->keyFor($set->id(), BackupSet::MANIFEST_FILE));

            if (! $removal['ok']) {
                return $result(self::FAILED, sprintf(
                    'Set %s is off-site with a half missing and its manifest could not be removed, so the store still '
                    .'reads it as complete. Nothing further was uploaded — a set that claims both halves and holds one '
                    .'is the artefact this whole system exists to never produce again.',
                    $set->id(),
                ), [(string) $removal['error']]);
            }
        }

        $uploaded = [];
        $bytes = 0;

        // Halves first, manifest last. The order is the atomicity.
        foreach (self::files() as $file => $contentType) {
            $local = $set->path.'/'.$file;
            $key = $this->target->keyFor($set->id(), $file);

            $put = $this->store->put($key, $local, $hashes[$file], $contentType);

            if (! $put['ok']) {
                return $this->abandon($set, $uploaded, $result, sprintf(
                    'Uploading %s of set %s failed, so the set is NOT off-site.',
                    $file,
                    $set->id(),
                ), [(string) $put['error']]);
            }

            // Confirm from the store's side, not from our own 200. The recurring
            // defect in this codebase is a surface reporting success about
            // something it never read back — a 200 from a proxy in front of a
            // full bucket is exactly that shape.
            $head = $this->store->head($key);
            $localBytes = (int) filesize($local);

            if (! $head['ok'] || ! $head['found'] || ($head['bytes'] !== null && $head['bytes'] !== $localBytes)) {
                return $this->abandon($set, $uploaded, $result, sprintf(
                    'The store accepted %s of set %s and then did not have it at %d bytes, so the set is NOT off-site.',
                    $file,
                    $set->id(),
                    $localBytes,
                ), [$head['error'] ?? sprintf('the store reports %s bytes', $head['found'] ? (string) $head['bytes'] : 'no object')]);
            }

            $uploaded[] = $file;
            $bytes += $localBytes;
        }

        return $result(
            self::SHIPPED,
            sprintf('Set %s is off-site, both halves and the manifest that binds them.', $set->id()),
            [],
            $bytes,
            $uploaded,
        );
    }

    /**
     * Take back what this run put up, so a failed ship leaves no half-set.
     *
     * Best effort by necessity — if the store is unreachable, the DELETEs cannot
     * reach it either. That is survivable and the reason is the manifest rule:
     * the manifest is uploaded last and never reached, so whatever is left is
     * not a set, `verify()` reports it as `incomplete`, and the next ship
     * overwrites it. The cleanup is here to stop the bucket accumulating dead
     * bytes that bill monthly, not to preserve a safety property that already
     * holds without it.
     */
    private function abandon(BackupSet $set, array $uploaded, callable $result, string $reason, array $detail): array
    {
        foreach ($uploaded as $file) {
            $removal = $this->store->delete($this->target->keyFor($set->id(), $file));

            if (! $removal['ok']) {
                $detail[] = sprintf('%s was uploaded and could not be removed again: %s', $file, (string) $removal['error']);
            }
        }

        return $result(self::FAILED, $reason, $detail);
    }
}
