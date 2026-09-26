<?php

namespace Tests\Feature\Broadcasts;

use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Broadcast\Channels\EmailChannel;
use App\Support\MailGreeting;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * A broadcast with no newsletter layout is sent exactly as it was before the
 * layout existed: the same HTML to the byte, and no plain-text part.
 *
 * "The same" is pinned against the email blade as it stood at e4c7fc48 (production
 * main when the newsletter layout was started), copied verbatim into
 * tests/fixtures/broadcast-e4c7fc48. Every case renders the live mail and the
 * frozen blade from the same inputs and requires equal strings, so the
 * expectation comes from the old source and never from the branch under test.
 *
 * Why this matters: every organisation already sending single-image broadcasts
 * has congregants whose mail filters, and whose sense of what their masjid's
 * email looks like, were formed by that output. The layout is opt-in per send;
 * a send that does not opt in must not notice it exists.
 */
class BroadcastLegacyEmailUnchangedTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN_BLADE = 'tests/fixtures/broadcast-e4c7fc48/broadcast.blade.php';

    private const READER = 'reader@family.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // The real mailer into the array transport, the queued mail run inline.
        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        Storage::fake('public');
        app(TenantContext::class)->forgetTenant();
    }

    #[Test]
    public function a_single_image_broadcast_renders_the_frozen_blade_byte_for_byte(): void
    {
        $cases = [
            'everything optional present' => [
                'orgName' => 'Masjid An-Nur',
                'title' => 'Snow closure & parking',
                'body' => "All programs are cancelled today.\nStay safe <everyone>.",
                'link' => 'https://example.test/closures?day=1&x=2',
                'imageUrl' => 'https://cdn.example.test/storage/7/flyer.png',
                'recipientName' => 'Amina',
                'unsubscribeUrl' => 'https://app.example.test/unsubscribe/abc',
            ],
            'nothing optional' => [
                'orgName' => 'Al-Razi School',
                'title' => 'Picture day',
                'body' => 'Bring your smile.',
                'link' => null,
                'imageUrl' => null,
                'recipientName' => null,
                'unsubscribeUrl' => null,
            ],
        ];

        foreach ($cases as $label => $in) {
            $live = (new BroadcastMail(
                orgName: $in['orgName'],
                title: $in['title'],
                body: $in['body'],
                link: $in['link'],
                imageUrl: $in['imageUrl'],
                recipientName: $in['recipientName'],
                orgEmail: 'office@org.test',
                unsubscribeUrl: $in['unsubscribeUrl'],
                unsubscribeOneClickUrl: $in['unsubscribeUrl'] ? $in['unsubscribeUrl'] . '/one-click' : null,
            ))->render();

            $this->assertSame($this->frozen($in), $live, "The legacy broadcast email changed ({$label}).");
        }
    }

    #[Test]
    public function a_broadcast_without_blocks_goes_out_through_the_channel_unchanged(): void
    {
        $masjid = Masjid::create([
            'name' => 'Masjid An-Nur',
            'email' => 'office@org.test',
            'phone' => '+15550001111',
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        Contact::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Example',
            'email' => self::READER,
        ]);

        $broadcast = Broadcast::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'title' => 'Snow closure',
            'body' => 'All programs are cancelled today because of the storm.',
            'link' => 'https://example.test/closures',
            'audience' => 'everyone',
            'status' => Broadcast::STATUS_PENDING,
        ]);
        $broadcast->addMedia(UploadedFile::fake()->image('flyer.png', 800, 600))
            ->toMediaCollection(Broadcast::MEDIA_COLLECTION);
        $broadcast->refresh();

        $tenant = app(TenantContext::class);
        $tenant->set($masjid->id);
        try {
            app(EmailChannel::class)->deliver($broadcast, $masjid);
        } finally {
            $tenant->forgetTenant();
        }

        $email = $this->theOneMessageTo(self::READER);
        $html = (string) $email->getHtmlBody();

        // The unsubscribe URL is minted per recipient, so it is read back out of
        // the message rather than predicted; everything else is the frozen blade.
        $this->assertSame(1, preg_match('#<a href="([^"]+)" style="color:\#2f9e57; text-decoration:underline;">Unsubscribe#', $html, $m));
        $unsubscribe = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertSame($this->frozen([
            'orgName' => 'Masjid An-Nur',
            'title' => 'Snow closure',
            'body' => 'All programs are cancelled today because of the storm.',
            'link' => 'https://example.test/closures',
            'imageUrl' => $broadcast->imageUrl(),
            'recipientName' => 'Amina',
            'unsubscribeUrl' => $unsubscribe,
        ]), $html);

        $this->assertNotNull($broadcast->imageUrl(), 'The premise: this broadcast has its one image.');
        $this->assertNull($email->getTextBody(), 'A legacy broadcast gained a plain-text part.');
        $this->assertTrue($email->getHeaders()->has('List-Unsubscribe'), 'The one-click unsubscribe header went missing.');
    }

    /** @param array<string, ?string> $in */
    private function frozen(array $in): string
    {
        return view()->file(base_path(self::FROZEN_BLADE), [
            'orgName' => $in['orgName'],
            'title' => $in['title'],
            'body' => $in['body'],
            'link' => $in['link'],
            'imageUrl' => $in['imageUrl'],
            'unsubscribeUrl' => $in['unsubscribeUrl'],
            'greeting' => MailGreeting::for($in['recipientName']),
        ])->render();
    }

    private function theOneMessageTo(string $address): Email
    {
        $matches = [];

        foreach (app('mail.manager')->mailer('array')->getSymfonyTransport()->messages() as $sent) {
            $email = $sent->getOriginalMessage();
            $to = array_map(fn ($a) => strtolower($a->getAddress()), $email->getTo());

            if (in_array(strtolower($address), $to, true)) {
                $matches[] = $email;
            }
        }

        $this->assertCount(1, $matches, "Expected exactly one message to {$address}.");

        return $matches[0];
    }
}
