<?php

namespace Tests\Feature;

use App\Models\HifzEntry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teacher screen's hardcoded quality fallback must match the server's list.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * From 2026-08-29 to 2026-09-16 `TeacherClass.vue` hardcoded four `<option>`
 * tags for hifz quality, and one of them — `needs_work` — was a value that
 * exists nowhere in PHP. `StoreHifzEntryRequest` validates against
 * `HifzEntry::QUALITIES`, so a teacher choosing it got a 422 and lost the
 * recording. Worse than one dead option: `repeat`, the single outcome that
 * changes what happens next for that child, was unreachable from the teacher
 * screen entirely.
 *
 * The screen now binds the list from the `meta` block the endpoint already
 * returns, which fixes the mechanism. But it keeps a hardcoded FALLBACK for the
 * first paint before any student is selected, and that fallback is exactly the
 * thing that silently rotted last time. So it is pinned here.
 *
 * This reads the `.vue` file deliberately. A Feature test cannot render Vue, and
 * the alternative — trusting that nobody edits the fallback — is the assumption
 * that produced the original bug. A lexical check is a floor, not a ceiling: it
 * cannot prove the select renders correctly, only that the two lists have not
 * drifted apart again.
 */
class TeacherHifzQualityFallbackTest extends TestCase
{
    #[Test]
    public function the_teacher_screens_quality_fallback_matches_the_servers_list(): void
    {
        $vue = base_path('resources/vue-app/views/teacher/TeacherClass.vue');
        $this->assertFileExists($vue);

        $source = file_get_contents($vue);

        $this->assertMatchesRegularExpression(
            '/hifzMeta\.value\?\.qualities\s*\?\?/',
            $source,
            'the teacher screen must take hifz qualities from the server meta, not from a literal'
        );

        preg_match(
            '/hifzMeta\.value\?\.qualities\s*\?\?\s*\[(?P<list>[^\]]*)\]/',
            $source,
            $m
        );

        $this->assertArrayHasKey('list', $m, 'could not find the quality fallback array in TeacherClass.vue');

        preg_match_all("/'([a-z_]+)'/", $m['list'], $found);
        $fallback = $found[1];

        $this->assertSame(
            HifzEntry::QUALITIES,
            $fallback,
            "TeacherClass.vue's quality fallback has drifted from HifzEntry::QUALITIES.\n"
            ."A value here that PHP does not accept is a 422 the teacher sees as a lost recording,\n"
            ."and a value MISSING here is an outcome they cannot record at all — which is how\n"
            ."`repeat` was unreachable from that screen for two and a half weeks."
        );
    }

    #[Test]
    public function no_quality_value_exists_in_the_screen_that_php_would_reject(): void
    {
        $source = file_get_contents(base_path('resources/vue-app/views/teacher/TeacherClass.vue'));

        // Looks for the string in a VALUE position — quote-delimited, or as a
        // `value="..."` attribute — rather than anywhere in the file. The first
        // draft of this test forbade the bare word and then failed on the
        // comment above the fix, which explains the incident and necessarily
        // names it. A guard that cannot tell an option from a sentence about an
        // option makes the code harder to explain than to get wrong.
        foreach (['needs_work', 'needswork', 'needs-work'] as $ghost) {
            foreach (["'{$ghost}'", "\"{$ghost}\""] as $asValue) {
                $this->assertStringNotContainsString(
                    $asValue,
                    $source,
                    "{$asValue} is not a value HifzEntry::QUALITIES accepts; the API answers 422 and the teacher loses the entry"
                );
            }
        }
    }
}
