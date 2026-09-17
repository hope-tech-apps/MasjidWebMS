<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The family portal must DRAW the teacher notes its payloads carry.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * `Family\HifzEntriesController` has served the teacher's hifz note to parents
 * since it was written, on the stated ground that "a record a parent cannot read
 * the detail of is not a record they have been given". FamilyClass.vue never
 * drew it. Every API test passed, because every API test asserted the payload,
 * and a parent was shown each recitation with everything except what the teacher
 * said about it. It was found only because someone opened the screen to see how
 * hifz notes looked, in order to copy them.
 *
 * The Arabic notes (owner decision 2026-09-17) ride the same way, so the same
 * gap is pinned for all three. A Feature test cannot render Vue; this reads the
 * file. It is a floor, not a ceiling: it proves the screen names the field, not
 * that the field is visible.
 */
class FamilyScreenDrawsTeacherNotesTest extends TestCase
{
    private function source(): string
    {
        $vue = base_path('resources/vue-app/views/family/FamilyClass.vue');
        $this->assertFileExists($vue);

        return file_get_contents($vue);
    }

    #[Test]
    public function the_portal_draws_the_hifz_note_it_is_sent(): void
    {
        $this->assertMatchesRegularExpression(
            '/v-if="h\.note"[^>]*>\s*\{\{\s*txHifzNote\(h\)\s*\}\}/',
            $this->source(),
            'FamilyClass.vue must render each recitation\'s teacher note (through the translation door)'
        );
    }

    #[Test]
    public function the_portal_draws_the_teachers_notes_on_letters(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('drillNotes(track)', $source,
            'the letters section must list the drills that carry a teacher note');
        $this->assertMatchesRegularExpression('/txDrillNote\(child,\s*track,\s*n\.drill\)/', $source);
    }

    #[Test]
    public function the_portal_fetches_and_draws_the_daily_arabic_notes(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('/arabic-notes`', $source,
            'the portal must ask for the daily Arabic notes');
        $this->assertMatchesRegularExpression('/txArabicDay\(n\)/', $source);

        // A failed request is said out loud, never shown as "no notes".
        $this->assertMatchesRegularExpression(
            '/arabicDayNotes\[child\.membership_id\]\s*===\s*null/',
            $source,
            'a failed notes request must render as unavailable, not as an empty record'
        );

        // The lesson DAY is printed by the literal-day formatter, not by
        // `when()`, which parses a bare date as UTC midnight and shows every
        // parent west of UTC the previous day.
        $this->assertMatchesRegularExpression('/\{\{\s*day\(n\.session_date\)\s*\}\}/', $source);
        $this->assertDoesNotMatchRegularExpression('/when\(n\.session_date\)/', $source);
    }
}
