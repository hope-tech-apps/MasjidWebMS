<?php

namespace Tests\Feature;

use App\Support\Arabic\ArabicCurriculum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The letter tracker's statuses, as the teacher's screen has them written down.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * The same screen hardcoded the hifz quality list for two and a half weeks. One
 * of its four values existed nowhere in PHP, so choosing it was a 422 the
 * teacher read as a lost recording — and `repeat`, the one outcome that changes
 * what happens next for a child, was missing entirely and therefore unreachable.
 * Nothing failed loudly for eighteen days.
 *
 * `TeacherClass.vue` holds the same kind of copy for letters, in two maps: the
 * LABELS it prints, and the cycle `NEXT` that decides where a tap moves a child.
 * Both are legitimately the client's — a label is not the server's business, and
 * the cycle order is an interaction decision. What is NOT legitimate is for
 * either to describe a different set of statuses from the one
 * `MarkDrillRequest` validates against. A status missing from `NEXT` is a drill
 * that cannot be moved out of; a status invented there is a 422 on tap.
 *
 * This reads the `.vue` file, deliberately and for the same reason its sibling
 * does: a Feature test cannot render Vue, and the alternative — trusting that
 * nobody edits a literal — is precisely the assumption that produced the hifz
 * bug. A lexical check is a floor, not a ceiling. It cannot prove the screen
 * behaves; it can only prove the two lists have not drifted apart again.
 */
class TeacherLetterStatusFallbackTest extends TestCase
{
    private function source(): string
    {
        $vue = base_path('resources/vue-app/views/teacher/TeacherClass.vue');
        $this->assertFileExists($vue);

        return file_get_contents($vue);
    }

    /** @return list<string> the keys of a `const NAME: Record<string,string> = { ... }` literal */
    private function keysOf(string $source, string $name): array
    {
        $this->assertTrue(
            (bool) preg_match('/const '.preg_quote($name, '/').'[^=]*=\s*\{(?P<body>[^}]*)\}/', $source, $m),
            "could not find the `{$name}` map in TeacherClass.vue — if it was renamed, rename it here too rather than deleting this test"
        );

        preg_match_all('/([a-z_]+)\s*:/', $m['body'], $found);

        return $found[1];
    }

    #[Test]
    public function every_status_the_server_accepts_has_a_label_on_the_screen(): void
    {
        $labelled = $this->keysOf($this->source(), 'STATUS');

        sort($labelled);
        $expected = ArabicCurriculum::STATUSES;
        sort($expected);

        $this->assertSame(
            $expected,
            $labelled,
            "TeacherClass.vue's letter STATUS labels have drifted from ArabicCurriculum::STATUSES.\n"
            ."A status with no label here renders as its raw key on a teacher's screen;\n"
            ."a label for a status PHP does not accept is a badge for a state no child can be in."
        );
    }

    #[Test]
    public function the_tap_cycle_can_leave_every_status_and_lands_only_on_real_ones(): void
    {
        $source = $this->source();
        $from = $this->keysOf($source, 'NEXT');

        sort($from);
        $expected = ArabicCurriculum::STATUSES;
        sort($expected);

        $this->assertSame(
            $expected,
            $from,
            "TeacherClass.vue's NEXT cycle does not cover exactly ArabicCurriculum::STATUSES.\n"
            ."A status missing from the cycle is a drill a teacher can tap for ever without it moving."
        );

        // And every DESTINATION is a status the endpoint will accept. A typo on
        // this side of the colon is the hifz bug exactly: a value that exists
        // nowhere in PHP, sent on a tap, answered 422, read by the teacher as a
        // dead button.
        $this->assertTrue(
            (bool) preg_match('/const NEXT[^=]*=\s*\{(?P<body>[^}]*)\}/', $source, $m)
        );
        preg_match_all("/:\s*'([a-z_]+)'/", $m['body'], $found);

        foreach ($found[1] as $destination) {
            $this->assertContains(
                $destination,
                ArabicCurriculum::STATUSES,
                "the tap cycle moves a drill to '{$destination}', which MarkDrillRequest rejects"
            );
        }
    }
}
