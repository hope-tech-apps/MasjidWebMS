<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Words that other people will read as rules (a route comment, a docblock, a
 * config note, the decisions log) and that the class story engagement work made
 * half wrong once. These pin the corrected wording, so a later edit cannot quietly
 * put the old claim back.
 */
class StoryEngagementDocsTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    #[Test]
    public function the_message_reaction_comments_no_longer_say_reactions_never_notify(): void
    {
        $routes = $this->source('routes/family.php');
        $start = strpos($routes, "A parent's 🤲 / 👍 / 💯 / ❓ on a message");
        $this->assertNotFalse($start, 'the message-reaction route comment moved: update this test');
        $comment = substr($routes, $start, 900);

        $this->assertStringNotContainsString('no notification', $comment);
        $this->assertStringContainsString('groups:notify-reactions', $comment);

        $controller = $this->source('app/Http/Controllers/Family/GroupThreadsController.php');
        $this->assertStringNotContainsString('No notification - a reaction', $controller);
        $this->assertStringNotContainsString('No notification — a reaction', $controller);
        $this->assertStringContainsString('groups:notify-reactions', $controller);
    }

    #[Test]
    public function each_config_note_sits_directly_above_the_key_it_explains(): void
    {
        $config = $this->source('config/groups.php');

        $this->assertMatchesRegularExpression(
            "/Class story read receipts(?:(?!\\*\\/).)*\\*\\/\\s*'story_reads' => \\[/s",
            $config,
            'the read-receipts warning (do not enable before the translation review) must sit above `story_reads`'
        );
        $this->assertMatchesRegularExpression(
            "/The reaction digest(?:(?!\\*\\/).)*\\*\\/\\s*'reactions' => \\[/s",
            $config,
            'the reaction-digest note must sit above `reactions`'
        );
    }

    #[Test]
    public function the_decisions_log_says_what_actually_happened_to_the_tenant_coverage_test(): void
    {
        $decisions = $this->source('DECISIONS.md');
        $start = strpos($decisions, 'Class story engagement — reactions, read receipts, the reaction digest (W2)');
        $this->assertNotFalse($start);
        $section = substr($decisions, $start, 9000);

        $this->assertStringNotContainsString('TenantScopingCoverageTest` were updated on purpose', $section);
        $this->assertStringContainsString('TenantScopingCoverageTest` needed no edit', $section);
    }
}
