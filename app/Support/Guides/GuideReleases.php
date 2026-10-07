<?php

namespace App\Support\Guides;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/** Local-disk immutable releases with a single atomic current/previous pointer. */
class GuideReleases
{
    public function __construct(private GuideValidator $validator) {}

    private function root(): string
    {
        $root = Storage::disk('local')->path('guides');
        if (! is_dir($root) && ! @mkdir($root, 0750, true) && ! is_dir($root)) throw new GuideValidationException('storage', 'guides');
        if (is_link($root)) throw new GuideValidationException('private-disk', 'guides');
        $real = realpath($root);
        $public = realpath(public_path());
        if ($real === false || ($public && ($real === $public || str_starts_with($real, $public.'/')))) throw new GuideValidationException('private-disk', 'guides');
        return $real;
    }

    private function locked(callable $operation): mixed
    {
        $handle = @fopen($this->root().'/.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_EX)) throw new GuideValidationException('lock', 'guides');
        try { return $operation(); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function install(string $source): string
    {
        // Checking the source first refuses symlinks before any copying follows them.
        $source = rtrim($source, '/');
        $this->validator->validate($source);
        return $this->locked(function () use ($source) {
            $root = $this->root();
            $temp = $root.'/.install-'.bin2hex(random_bytes(8));
            if (! @mkdir($temp, 0750)) throw new GuideValidationException('storage', 'release');
            try {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                    $relative = substr($entry->getPathname(), strlen($source) + 1);
                    if ($entry->isLink()) throw new GuideValidationException('symlink', $relative);
                    if ($entry->isDir()) {
                        if (! @mkdir($temp.'/'.$relative, 0750)) throw new GuideValidationException('copy', $relative);
                    } elseif (! $entry->isFile() || ! @copy($entry->getPathname(), $temp.'/'.$relative)) {
                        throw new GuideValidationException('copy', $relative);
                    } else chmod($temp.'/'.$relative, 0640);
                }
                $manifest = $this->validator->validate($temp);
                $version = $manifest['version'];
                $target = $root.'/'.$version;
                if (file_exists($target)) {
                    $existing = $this->validator->validate($target);
                    if ($existing !== $manifest) throw new GuideValidationException('immutable-version', 'manifest.json');
                } elseif (! @rename($temp, $target)) throw new GuideValidationException('move', 'release');
                $this->point($version);
                return $version;
            } finally {
                if (is_dir($temp)) File::deleteDirectory($temp);
            }
        });
    }

    public function useVersion(string $version): void
    {
        $this->locked(function () use ($version) {
            if (! preg_match(GuideValidator::VERSION, $version) || ! $this->manifest($version)) throw new GuideValidationException('missing-version', 'release');
            $manifest = $this->validator->validate($this->root().'/'.$version);
            if ($manifest['version'] !== $version) throw new GuideValidationException('manifest-version', 'manifest.json');
            $this->point($version);
        });
    }

    private function pointer(): array
    {
        $file = $this->root().'/current.json';
        if (! file_exists($file) && ! is_link($file)) return ['current' => null, 'previous' => null];
        if (! is_file($file) || is_link($file)) throw new GuideValidationException('pointer-read', 'current.json');
        $bytes = @file_get_contents($file);
        $pointer = $bytes === false ? null : json_decode($bytes, true);
        if (! is_array($pointer) || ! is_string($pointer['current'] ?? null)
            || ! preg_match(GuideValidator::VERSION, $pointer['current'])
            || ! array_key_exists('previous', $pointer)
            || ($pointer['previous'] !== null && (! is_string($pointer['previous']) || ! preg_match(GuideValidator::VERSION, $pointer['previous'])))) {
            throw new GuideValidationException('pointer-invalid', 'current.json');
        }
        return $pointer;
    }

    private function point(string $version): void
    {
        $pointer = $this->pointer();
        if ($pointer['current'] === $version) return;
        $temp = $this->root().'/.pointer-'.bin2hex(random_bytes(8));
        try {
            $bytes = json_encode(['current' => $version, 'previous' => $pointer['current']], JSON_THROW_ON_ERROR);
            if (@file_put_contents($temp, $bytes) !== strlen($bytes)) throw new GuideValidationException('pointer-write', 'current.json');
            chmod($temp, 0640);
            if (! @rename($temp, $this->root().'/current.json')) throw new GuideValidationException('pointer-move', 'current.json');
        } finally { if (is_file($temp)) unlink($temp); }
    }

    public function status(): array
    {
        $installed = array_values(array_filter(scandir($this->root()), fn ($name) => preg_match(GuideValidator::VERSION, $name) && is_dir($this->root().'/'.$name) && ! is_link($this->root().'/'.$name)));
        sort($installed);
        return [...$this->pointer(), 'installed' => $installed];
    }

    public function prune(): array
    {
        return $this->locked(function () {
            $status = $this->status();
            if ($status['installed'] !== [] && ! $status['current']) throw new GuideValidationException('missing-pointer', 'current.json');
            $removed = [];
            foreach ($status['installed'] as $version) {
                if (in_array($version, [$status['current'], $status['previous']], true)) continue;
                if (! File::deleteDirectory($this->root().'/'.$version)) throw new GuideValidationException('prune', $version);
                $removed[] = $version;
            }
            return $removed;
        });
    }

    public function current(): ?array
    {
        // Read the pointer ONCE. HTML, CSS and picture version come from this snapshot.
        try { $pointer = $this->pointer(); }
        catch (GuideValidationException) { return null; }
        return $this->manifest($pointer['current'] ?? '');
    }

    public function manifest(string $version): ?array
    {
        if (! preg_match(GuideValidator::VERSION, $version)) return null;
        $path = $this->file($version, 'manifest.json');
        if (! $path) return null;
        $bytes = @file_get_contents($path);
        $manifest = $bytes === false ? null : json_decode($bytes, true);
        return is_array($manifest) && ($manifest['version'] ?? null) === $version ? $manifest : null;
    }

    /** Only callers resolving an exact manifest key may supply the relative path. */
    public function file(string $version, string $relative): ?string
    {
        if (! preg_match(GuideValidator::VERSION, $version) || ! GuideValidator::safePath($relative)) return null;
        $release = $this->root().'/'.$version;
        $path = $release;
        foreach (explode('/', $relative) as $segment) {
            if (is_link($path)) return null;
            $path .= '/'.$segment;
        }
        if (is_link($path) || ! is_file($path)) return null;
        $real = realpath($path);
        return $real && str_starts_with($real, $release.'/') ? $real : null;
    }
}
