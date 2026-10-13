<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * scripts/junit-expected-failures.php: the check behind the suite's second run.
 *
 * The whole suite is also run with "one login, a role at each organisation"
 * switched ON (.github/workflows/tests.yml), because once the switch is on in
 * production that run is the one that describes production. A few existing tests
 * fail in it by design, so the run cannot be judged by "zero failures"; this script
 * judges it by "exactly the listed tests failed". A gate that misreads its own
 * input fails open, so each way it can be wrong is run here against a small JUnit
 * file written for the purpose.
 */
class JunitExpectedFailuresScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/junit-expected-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function exactly_the_listed_failures_is_a_pass(): void
    {
        $junit = $this->junit(['A :: one' => 'pass', 'A :: two with data set "(\'x\')"' => 'fail', 'B :: three' => 'error', 'B :: four' => 'pass']);
        $expected = $this->expected(["# why these fail\n", "A :: two with data set \"('x')\"\n", "\n", "  B :: three  \n"]);

        [$exit, $output] = $this->run_($junit, $expected, 1);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('tests=4 assertions=4 failed=2 expected=2', $output);
        $this->assertStringContainsString('Exactly the expected tests failed, and nothing else.', $output);
    }

    #[Test]
    public function a_failure_that_is_not_listed_fails_the_run_and_is_named(): void
    {
        $junit = $this->junit(['A :: one' => 'fail', 'A :: two' => 'fail']);
        $expected = $this->expected(["A :: two\n"]);

        [$exit, $output] = $this->run_($junit, $expected, 1);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAILED, and not expected to: A :: one', $output);
        $this->assertStringNotContainsString('not expected to: A :: two', $output);
    }

    #[Test]
    public function a_listed_failure_that_passed_or_never_ran_fails_the_run_and_is_named(): void
    {
        // The list is not a licence to fail: when the thing a listed test pinned
        // moves, the list is stale and somebody has to look.
        $junit = $this->junit(['A :: one' => 'pass', 'A :: two' => 'fail']);
        $expected = $this->expected(["A :: one\n", "A :: two\n", "A :: renamed since\n"]);

        [$exit, $output] = $this->run_($junit, $expected, 1);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('PASSED, but is listed as an expected failure: A :: one', $output);
        $this->assertStringContainsString('DID NOT RUN, but is listed as an expected failure: A :: renamed since', $output);
    }

    #[Test]
    public function a_run_that_did_not_really_happen_is_never_a_pass(): void
    {
        $nothingListed = $this->expected([]);

        // No record at all.
        [$exit, $output] = $this->run_($this->dir.'/absent.xml', $nothingListed, 1);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('the suite did not run at all', $output);

        // A record cut off half-way.
        file_put_contents($this->dir.'/cut.xml', '<?xml version="1.0"?><testsuites><testsuite name="x"><testcase name="one" class="A"');
        [$exit, $output] = $this->run_($this->dir.'/cut.xml', $nothingListed, 1);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not readable XML', $output);

        // Too few tests to be the suite. "Nothing failed" here would otherwise pass.
        [$exit, $output] = $this->run_($this->junit(['A :: one' => 'pass']), $nothingListed, 2);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('1 test(s) ran, fewer than the 2', $output);

        // Plenty of tests, no assertions: it died while migrating.
        [$exit, $output] = $this->run_($this->junit(['A :: one' => 'pass', 'A :: two' => 'pass'], assertions: 0), $nothingListed, 1);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('ZERO assertions', $output);

        // No list.
        [$exit, $output] = $this->run_($this->junit(['A :: one' => 'pass']), $this->dir.'/absent.txt', 1);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no list of expected failures', $output);

        // And the default floor is the workflow's own (150), not zero.
        [$exit, $output] = $this->run_($this->junit(['A :: one' => 'pass']), $nothingListed, null);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('fewer than the 150', $output);
    }

    #[Test]
    public function the_list_the_workflow_uses_names_tests_that_exist(): void
    {
        // A line that names nothing would be reported as "did not run" only in CI,
        // twenty minutes in. Each listed class must be a test file of this suite.
        $lines = array_values(array_filter(
            array_map('trim', file(dirname(__DIR__).'/fixtures/roles-switch-on-expected-failures.txt')),
            fn (string $line): bool => $line !== '' && $line[0] !== '#'
        ));

        $this->assertCount(4, $lines, 'the four tests that pin the switched-off behaviour');

        foreach ($lines as $line) {
            [$class, $test] = explode(' :: ', $line, 2);

            $file = dirname(__DIR__, 2).'/'.lcfirst(str_replace('\\', '/', $class)).'.php';

            $this->assertFileExists($file, $line);
            $this->assertNotSame('', trim($test));
        }

        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/tests.yml');

        $this->assertStringContainsString("TENANCY_ROLES_PER_ORGANISATION: 'true'", $workflow);
        $this->assertStringContainsString('php scripts/junit-expected-failures.php junit-roles-on.xml tests/fixtures/roles-switch-on-expected-failures.txt', $workflow);
    }

    /** @param  array<string, string>  $cases  `Class :: name` => pass|fail|error */
    private function junit(array $cases, int $assertions = 1): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<testsuites><testsuite name="CLI Arguments"><testsuite name="nested">';

        foreach ($cases as $name => $outcome) {
            [$class, $test] = explode(' :: ', $name, 2);

            $xml .= sprintf('<testcase name="%s" class="%s" assertions="%d">', htmlspecialchars($test, ENT_QUOTES | ENT_XML1), htmlspecialchars($class, ENT_QUOTES | ENT_XML1), $assertions);
            $xml .= match ($outcome) {
                'fail' => '<failure type="x">no</failure>',
                'error' => '<error type="x">no</error>',
                default => '',
            };
            $xml .= '</testcase>';
        }

        $path = $this->dir.'/junit-'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($path, $xml.'</testsuite></testsuite></testsuites>');

        return $path;
    }

    /** @param  list<string>  $lines */
    private function expected(array $lines): string
    {
        $path = $this->dir.'/expected-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($path, implode('', $lines));

        return $path;
    }

    /** @return array{0: int, 1: string} the exit code, and everything it printed */
    private function run_(string $junit, string $expected, ?int $floor): array
    {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/scripts/junit-expected-failures.php')
            .' '.escapeshellarg($junit).' '.escapeshellarg($expected).($floor === null ? '' : ' '.$floor).' 2>&1';

        exec($command, $output, $exit);

        return [$exit, implode("\n", $output)];
    }
}
