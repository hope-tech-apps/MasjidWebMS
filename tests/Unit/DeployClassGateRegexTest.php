<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/deploy's "every application class is loadable" gate finds the type each file declares
 * with one regex. Its old form allowed ONE modifier, so `final readonly class` (a dozen
 * files: the cart's line outcomes, the settlement result, the Studio value objects) was
 * never checked, and a broken autoload entry for any of them passed the gate.
 *
 * The regex is read OUT of bin/deploy, not copied here, so the test pins the script.
 */
class DeployClassGateRegexTest extends TestCase
{
    private function gateRegex(): string
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/deploy');

        $this->assertSame(
            1,
            preg_match('/preg_match\("(\/\^[^"]+\/m)", \$source, \$cls\)/', $script, $found),
            'the gate\'s declaration regex is where this test expects it'
        );

        return $found[1];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function declarations(): array
    {
        return [
            'plain class' => ["<?php\n\nclass Foo\n{\n}\n", 'Foo'],
            'final class' => ["<?php\n\nfinal class Foo\n{\n}\n", 'Foo'],
            'abstract class' => ["<?php\n\nabstract class Foo\n{\n}\n", 'Foo'],
            'readonly class' => ["<?php\n\nreadonly class Foo\n{\n}\n", 'Foo'],
            'final readonly class' => ["<?php\n\nfinal readonly class Foo\n{\n}\n", 'Foo'],
            'readonly final class' => ["<?php\n\nreadonly final class Foo\n{\n}\n", 'Foo'],
            'abstract readonly class' => ["<?php\n\nabstract readonly class Foo\n{\n}\n", 'Foo'],
            'interface' => ["<?php\n\ninterface Foo\n{\n}\n", 'Foo'],
            'trait' => ["<?php\n\ntrait Foo\n{\n}\n", 'Foo'],
            'backed enum' => ["<?php\n\nenum Foo: string\n{\n}\n", 'Foo'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('declarations')]
    public function every_way_of_declaring_a_type_is_found(string $source, string $name): void
    {
        $this->assertSame(1, preg_match($this->gateRegex(), $source, $cls));
        $this->assertSame($name, $cls[1]);
    }

    #[Test]
    public function a_word_that_is_only_mentioned_is_not_a_declaration(): void
    {
        $regex = $this->gateRegex();

        $this->assertSame(0, preg_match($regex, "<?php\n\n// final readonly class Foo\n/** class Foo */\n\$x = 'class Foo';\n"));
        $this->assertSame(0, preg_match($regex, "<?php\n\nreturn new class {};\n"));
    }

    #[Test]
    public function no_file_under_app_declares_a_type_the_gate_would_skip(): void
    {
        $regex = $this->gateRegex();
        $loose = '/^(?:[a-z]+\s+)*(?:class|interface|trait|enum)\s+\w+/m';
        $skipped = [];
        $seen = 0;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/^namespace\s+[^;]+;/m', $source) !== 1 || preg_match($loose, $source) !== 1) {
                continue;
            }

            $seen++;

            if (preg_match($regex, $source) !== 1) {
                $skipped[] = $file->getPathname();
            }
        }

        $this->assertGreaterThan(100, $seen, 'the walk found the application');
        $this->assertSame([], $skipped, 'files the deploy gate would never check');
    }
}
