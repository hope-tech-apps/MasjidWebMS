<?php

/*
|--------------------------------------------------------------------------
| Did a suite run fail in EXACTLY the tests it is expected to fail in?
|--------------------------------------------------------------------------
|
| usage: php scripts/junit-expected-failures.php <junit.xml> <expected.txt> [min-tests]
|
| For a run of the whole suite in a configuration where a few existing tests fail
| BY DESIGN, because they pin the other configuration's behaviour and may not be
| edited to say so. The run is a pass when the tests that failed are exactly the
| ones listed, no more and no fewer:
|
|   - a test that failed and is not listed is a real failure in this configuration;
|   - a listed test that passed (or was not run at all) means the list is stale,
|     or the thing it pinned moved, and somebody must look.
|
| Its first user is the switched-on run of "one login, a role at each
| organisation" (TENANCY_ROLES_PER_ORGANISATION=true; the list is
| tests/fixtures/roles-switch-on-expected-failures.txt). Once that switch is on in
| production, the switched-on run is the one that describes production.
|
| <expected.txt>: one test per line, written `Class :: test name` exactly as this
| script prints a failure. Blank lines and lines starting with `#` are ignored.
|
| It also refuses a run that did not really happen: no file, no test cases, fewer
| than [min-tests] (default 150), or zero assertions (the signature of a suite that
| died while migrating). A check that cannot tell "nothing failed" from "nothing
| ran" is worse than no check.
|
| Exit 0: exactly the expected failures. Exit 1: anything else, with the reason.
*/

$junit = $argv[1] ?? null;
$expectedFile = $argv[2] ?? null;
$minimumTests = (int) ($argv[3] ?? 150);

$stop = function (string $why): never {
    fwrite(STDERR, "junit-expected-failures: {$why}\n");
    exit(1);
};

if ($junit === null || $expectedFile === null) {
    $stop('usage: php scripts/junit-expected-failures.php <junit.xml> <expected.txt> [min-tests]');
}

if (! is_file($junit)) {
    $stop("no JUnit file at {$junit}: the suite did not run at all.");
}

if (! is_file($expectedFile)) {
    $stop("no list of expected failures at {$expectedFile}.");
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($junit);

if ($xml === false) {
    $stop("{$junit} is not readable XML: the suite was cut off while it wrote its record.");
}

$name = fn (SimpleXMLElement $case): string => trim((string) $case['class']).' :: '.trim((string) $case['name']);

$ran = [];
$failed = [];
$assertions = 0;

foreach ($xml->xpath('//testcase') as $case) {
    $ran[$name($case)] = true;
    $assertions += (int) $case['assertions'];

    if (isset($case->failure) || isset($case->error)) {
        $failed[$name($case)] = true;
    }
}

if (count($ran) < $minimumTests) {
    $stop(count($ran)." test(s) ran, fewer than the {$minimumTests} this run must have. The suite is probably not booting.");
}

if ($assertions === 0) {
    $stop(count($ran).' tests ran but made ZERO assertions: the suite aborted before executing anything (usually a failed migration).');
}

$expected = [];

foreach (file($expectedFile, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);

    if ($line !== '' && $line[0] !== '#') {
        $expected[$line] = true;
    }
}

$unexpected = array_keys(array_diff_key($failed, $expected));
$notFailing = array_keys(array_diff_key($expected, $failed));

echo 'tests='.count($ran)." assertions={$assertions} failed=".count($failed).' expected='.count($expected)."\n";

if ($unexpected === [] && $notFailing === []) {
    echo "Exactly the expected tests failed, and nothing else.\n";
    exit(0);
}

foreach ($unexpected as $test) {
    fwrite(STDERR, "FAILED, and not expected to: {$test}\n");
}

foreach ($notFailing as $test) {
    fwrite(STDERR, (isset($ran[$test]) ? 'PASSED' : 'DID NOT RUN').", but is listed as an expected failure: {$test}\n");
}

$stop(count($unexpected).' unexpected failure(s), '.count($notFailing).' listed failure(s) that did not fail. '
    ."If the list is what moved, change {$expectedFile} and say why in the commit.");
