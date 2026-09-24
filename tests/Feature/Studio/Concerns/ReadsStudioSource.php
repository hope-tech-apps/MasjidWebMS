<?php

namespace Tests\Feature\Studio\Concerns;

/**
 * Reading the Studio SPA's source for the tests that guard it
 * (StudioSpaSourceTest, StudioFeatureStepSourceTest, StudioLayoutStepLintTest).
 * A Feature test cannot render Vue, so these pin what the files say.
 *
 * Comments are stripped before a file is checked, so a docblock may name what
 * the code must not contain.
 */
trait ReadsStudioSource
{
    /** A file under resources/vue-app, comments removed. */
    private function spaCode(string $relative): string
    {
        return $this->withoutComments($this->read('resources/vue-app/' . $relative));
    }

    /** Block, line and HTML comments out; a `//` inside a string such as https:// is kept. */
    private function withoutComments(string $source): string
    {
        $source = preg_replace('~/\*.*?\*/|<!--.*?-->~s', '', $source);

        return preg_replace('~(?<![:"\'`\\\\])//[^\n]*~', '', $source);
    }

    private function read(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /** Whether `$value` appears in `$code` as a whole quoted string: '…', "…" or `…`. */
    private function quotes(string $code, string $value): bool
    {
        return preg_match('/([\'"`])' . preg_quote($value, '/') . '\1/', $code) === 1;
    }

    /**
     * Whether `$key` is written into `$code` in any form a copied list takes:
     * a quoted string ('events'), an object key (`events: true`), or a member
     * read (`flags.events`). A catalogue read such as `entry.key` names no key.
     */
    private function namesKey(string $code, string $key): bool
    {
        $quoted = preg_quote($key, '/');

        return $this->quotes($code, $key)
            || preg_match('/(?<![\w.$-])' . $quoted . '\s*:/', $code) === 1
            || preg_match('/\.' . $quoted . '(?![\w-])/', $code) === 1;
    }

    /** Whether `$words` appears in `$code` as whole words, in any context (template text included). */
    private function saysWords(string $code, string $words): bool
    {
        return preg_match('/(?<![\w])' . preg_quote($words, '/') . '(?![\w])/u', $code) === 1;
    }
}
