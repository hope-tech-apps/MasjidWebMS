<?php

namespace Tests\Feature\Studio;

use App\Models\DonationLink;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\MasjidSocialMediaLink;
use App\Support\Studio\StarterFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The facts a starter page may copy (docs/manara-studio-w1.md S4). The preview
 * reads them from a draft and S8's writer from the organisation the draft
 * created, so the two readings must agree, and neither may pass on a value the
 * client did not give as a usable fact.
 */
class StarterFactsTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWERS = [
        'name' => 'Al-Noor Centre',
        'description' => 'A community centre in Burlington.',
        'email' => 'office@alnoor.test',
        'phone' => '+1 (555) 010-0100',
        'address' => '1 Test St',
        'facebook_url' => 'https://facebook.example/alnoor',
        'instagram_url' => '@alnoor',
        'youtube_url' => 'ftp://files.example/alnoor',
        'whatsapp_url' => 'https://wa.example/alnoor',
        'about' => 'We are a centre.',
        'mission' => '',
        'vision' => 'A welcoming place.',
        'donation_link' => 'https://give.example/alnoor',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    #[Test]
    public function an_organisations_rows_give_the_same_facts_as_the_answers_that_created_them(): void
    {
        $org = Masjid::create([
            'name' => self::ANSWERS['name'],
            'description' => self::ANSWERS['description'],
            'email' => self::ANSWERS['email'],
            'phone' => self::ANSWERS['phone'],
            'address' => self::ANSWERS['address'],
            'country_id' => '1', 'city_id' => '1', 'latitude' => 0.0, 'longitude' => 0.0,
        ]);
        MasjidAbout::create(['masjid_id' => $org->id, 'about' => self::ANSWERS['about'], 'mission' => self::ANSWERS['mission'], 'vision' => self::ANSWERS['vision']]);
        DonationLink::create(['masjid_id' => $org->id, 'link' => self::ANSWERS['donation_link'], 'title' => 'Donation Link', 'message' => 'Donate Now']);

        // Typed as OrganisationProvisioner writes them.
        foreach (['Facebook' => 'facebook_url', 'Instagram' => 'instagram_url', 'YouTube' => 'youtube_url', 'WhatsApp_URL' => 'whatsapp_url'] as $type => $key) {
            MasjidSocialMediaLink::create(['masjid_id' => $org->id, 'type' => $type, 'value' => self::ANSWERS[$key]]);
        }

        $this->assertEquals(StarterFacts::fromArray(self::ANSWERS), StarterFacts::fromMasjid($org->fresh()));
    }

    #[Test]
    public function only_usable_links_count_as_facts_and_prose_is_held_only_as_present(): void
    {
        $facts = StarterFacts::fromArray(self::ANSWERS + ['vibe' => 'Warm and modern.']);

        $this->assertSame('https://facebook.example/alnoor', $facts->get('facebook_url'));
        // A handle, and a URL that is not a web page, are not links a button may open.
        $this->assertSame('', $facts->get('instagram_url'));
        $this->assertSame('', $facts->get('youtube_url'));

        $this->assertSame('tel:+15550100100', $facts->get('phone_tel'));
        $this->assertSame('mailto:office@alnoor.test', $facts->get('email_mailto'));
        $this->assertSame('', StarterFacts::fromArray(['phone' => 'ask at the desk'])->get('phone_tel'));

        $this->assertTrue($facts->hasAbout);
        $this->assertTrue($facts->hasMissionOrVision, 'a vision alone is a mission-or-vision');
        $this->assertTrue($facts->hasDonationLink);

        // R12 and D8: neither the vibe nor any prose is readable as a fact.
        $this->assertStringNotContainsString('Warm and modern.', serialize($facts));
        $this->assertStringNotContainsString('We are a centre.', serialize($facts));

        $this->expectException(\InvalidArgumentException::class);
        $facts->get('about');
    }
}
