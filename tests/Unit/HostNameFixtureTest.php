<?php

namespace Tests\Unit;

use App\Support\HostName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HostName against tests/fixtures/host-normalization.json, the fixture the
 * renderer's `normalizeHost` carries a byte-identical copy of (S10). Both sides
 * passing the same pairs is what keeps a host spelled the same way on both
 * ends of the by-host lookup.
 */
class HostNameFixtureTest extends TestCase
{
    #[Test]
    public function every_fixture_pair_normalises_as_recorded(): void
    {
        $pairs = json_decode(file_get_contents(__DIR__ . '/../fixtures/host-normalization.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertGreaterThan(30, count($pairs));

        foreach ($pairs as $pair) {
            $this->assertSame(
                $pair['expected'],
                HostName::normalize($pair['input']),
                'input ' . json_encode($pair['input'], JSON_UNESCAPED_UNICODE)
            );
        }
    }

    #[Test]
    public function the_fixture_covers_every_refusal_the_contract_names(): void
    {
        $inputs = array_column(json_decode(file_get_contents(__DIR__ . '/../fixtures/host-normalization.json'), true), 'expected', 'input');

        // One case for each rule in HostName::normalize's contract, so a rule
        // cannot be dropped from the fixture without this saying which.
        $this->assertSame('www.example.org', $inputs['WWW.Example.ORG.:443']);
        $this->assertSame('mec.manara.hopetechapps.com', $inputs['mec.manara.hopetechapps.com, proxy.internal']);
        $this->assertSame('xn--mgbh0fb.example', $inputs['xn--mgbh0fb.example']);
        $this->assertSame('localhost', $inputs['localhost']);
        $this->assertNull($inputs['']);
        $this->assertNull($inputs['127.0.0.1']);
        $this->assertNull($inputs['[::1]:443']);
        $this->assertNull($inputs['bücher.example']);
        $this->assertNull($inputs['-bad.example.org']);

        $long = array_filter(array_keys($inputs), fn ($input) => strlen((string) $input) === 254);
        $this->assertCount(1, $long, 'the fixture needs one 254-character host');
        $this->assertNull($inputs[array_values($long)[0]]);
    }
}
