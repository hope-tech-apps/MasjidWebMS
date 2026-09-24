<?php

namespace Tests\Unit;

use App\Models\ThemeSetting;
use App\Support\DesignTokens;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Studio reads DesignTokens to grade a new client's palette and deliberately
 * does not change it (docs/manara-studio-w1.md S2): every live tenant's web,
 * iOS, Android and tvOS theme is resolved through it, so a "fix" to its ink
 * threshold would re-skin all of them at once. Studio's better ink goes into a
 * NEW org's `tokens` override instead.
 *
 * This pins Burlington's resolved tree, byte for byte, to a snapshot committed
 * from the code as it stood at bb60da7f. It cannot fail because of Studio; it
 * fails the day anybody edits DesignTokens, which is the point. If that edit is
 * intended, regenerate the fixture in the same commit and say which tenants'
 * colours move.
 */
class DesignTokensUnchangedTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/design-tokens-burlington.json';

    #[Test]
    public function burlingtons_resolved_tokens_equal_the_committed_snapshot(): void
    {
        $snapshot = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);

        $resolved = DesignTokens::resolve(new ThemeSetting($snapshot['theme']));

        $this->assertSame($snapshot['tokens'], $resolved);
    }
}
