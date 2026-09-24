<?php

namespace Tests\Unit;

use App\Models\MasjidDomain;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A host is stored in one spelling, the one the lookup searches for. A value
 * that is not a host is a caller's bug and throws, rather than being stored as
 * something no lookup could ever match.
 */
class MasjidDomainHostTest extends TestCase
{
    #[Test]
    public function the_host_is_stored_normalised(): void
    {
        $domain = new MasjidDomain(['host' => '  WWW.Example.ORG.:443 ', 'zone_apex' => 'Example.ORG.']);

        $this->assertSame('www.example.org', $domain->host);
        $this->assertSame('example.org', $domain->zone_apex);
        $this->assertSame('www.example.org', $domain->getAttributes()['host']);
    }

    #[Test]
    public function a_value_that_is_not_a_host_throws(): void
    {
        foreach (['', '127.0.0.1', 'bücher.example', str_repeat('a', 64) . '.org', 'exa mple.org'] as $bad) {
            try {
                new MasjidDomain(['host' => $bad]);
                $this->fail('stored a host that does not normalise: ' . var_export($bad, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function an_unsaved_row_reads_its_database_defaults(): void
    {
        $domain = new MasjidDomain(['host' => 'a.example.org']);

        $this->assertSame(MasjidDomain::STATUS_PENDING, $domain->status);
        $this->assertSame(MasjidDomain::SOURCE_STUDIO, $domain->source);
        $this->assertNull($domain->liveUrl(), 'a host nobody has seen serving has no live URL');

        $domain->serving_confirmed_at = now();
        $this->assertSame('https://a.example.org', $domain->liveUrl());
    }
}
