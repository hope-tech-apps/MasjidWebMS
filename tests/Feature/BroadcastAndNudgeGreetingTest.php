<?php

namespace Tests\Feature;

use App\Enums\GroupNotificationEvent;
use App\Jobs\SendGroupNotificationJob;
use App\Mail\BroadcastMail;
use App\Mail\GroupUpdateNudgeMail;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Services\Broadcast\Channels\EmailChannel;
use App\Services\Groups\GroupNotificationRecipientResolver;
use App\Services\Groups\GroupPushChannel;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The greeting at the top of a broadcast email and of a class nudge email.
 *
 * Owner, 2026-09-17: "Fix them too", which read "Apply the same small check to
 * both emails, so a name that isn't a real name falls back to 'Assalamu
 * alaikum,'."
 *
 * Both mails open with a name read from `contacts`. The public registration
 * form lets a stranger store a web address as a contact's first name, and
 * imported contacts can hold pieces of an email address in their name fields.
 * A broadcast goes to every contact with an address, so either kind of value
 * would open a genuine email from the organisation with something a mail app
 * turns into a link. The nudge prints first AND last name, so a bad last name
 * matters there too.
 *
 * Every case runs through the real sender (EmailChannel for the broadcast,
 * SendGroupNotificationJob for the nudge), the real mailer, and for the queued
 * broadcast a real serialize and unserialize on the sync queue. The assertions
 * read the message the array transport received: its HTML, the words a reader
 * sees in that HTML, and the plain-text part if the mail has one (neither does
 * today).
 */
class BroadcastAndNudgeGreetingTest extends TestCase
{
    use RefreshDatabase;

    private const READER = 'reader@family.test';

    private const PLANTED_URL = 'https://evil.example/x';

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

        // The real mailer into the array transport, and the broadcast's queued
        // mail run inline. Set here as well as in phpunit.xml, because this file
        // reads what the transport received and means nothing without both.
        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        app(TenantContext::class)->forgetTenant();
    }

    /**
     * first_name, last_name, the greeting a broadcast must show, the greeting a
     * nudge must show, and pieces of the stored name that must appear nowhere.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: list<string>}>
     */
    public static function names(): array
    {
        return [
            'a web address as the first name' => [
                self::PLANTED_URL, 'Doe',
                'Assalamu alaikum,', 'Assalamu alaikum,',
                ['evil.example', 'https://evil'],
            ],
            // Synthetic. Production has imported contacts whose name fields
            // hold pieces of an email address.
            'an email address as the first name' => [
                'jane.doe@example.com', 'Doe',
                'Assalamu alaikum,', 'Assalamu alaikum,',
                ['jane.doe', '@example.com'],
            ],
            // The broadcast greets by first name only, so only the nudge has
            // anything to drop here.
            'an email address as the last name' => [
                'Jane', 'jane.doe@example.com',
                'Assalamu alaikum Jane,', 'Assalamu alaikum,',
                ['jane.doe', '@example.com'],
            ],
            'a Latin name' => [
                'Amina', 'Rahman',
                'Assalamu alaikum Amina,', 'Assalamu alaikum Amina Rahman,',
                [],
            ],
            'an Arabic name' => [
                'عائشة', 'الحربي',
                'Assalamu alaikum عائشة,', 'Assalamu alaikum عائشة الحربي,',
                [],
            ],
            'an empty name' => [
                '', '',
                'Assalamu alaikum,', 'Assalamu alaikum,',
                [],
            ],
        ];
    }

    // ------------------------------------------------------------ broadcast

    /** @param list<string> $forbidden */
    #[Test]
    #[DataProvider('names')]
    public function a_broadcast_greets_a_contact_only_by_a_name_that_is_a_name(
        string $first,
        string $last,
        string $broadcastGreeting,
        string $nudgeGreeting,
        array $forbidden,
    ): void {
        $masjid = $this->makeMasjid('Masjid An-Nur', 'mosque');

        Contact::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => self::READER,
        ]);

        $broadcast = Broadcast::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'title' => 'Snow closure',
            'body' => 'All programs are cancelled today because of the storm.',
            'audience' => 'everyone',
            'status' => Broadcast::STATUS_SENT,
        ]);

        $tenant = app(TenantContext::class);
        $tenant->set($masjid->id);

        try {
            app(EmailChannel::class)->deliver($broadcast, $masjid);
        } finally {
            $tenant->forgetTenant();
        }

        $email = $this->theOneMessageTo(self::READER);
        $this->assertSame('Snow closure', $email->getSubject());
        $this->assertGreeting($email, $broadcastGreeting, $forbidden);
        $this->assertStringContainsString('All programs are cancelled today', $this->visibleText((string) $email->getHtmlBody()));
    }

    #[Test]
    public function a_broadcast_queued_before_this_check_still_greets_safely(): void
    {
        // A BroadcastMail queued by the old code carries the raw name in its
        // payload, and unserializing it does not run the constructor. The name
        // must still be checked when the mail is rendered.
        $mail = new BroadcastMail(orgName: 'Masjid An-Nur', title: 'Snow closure', body: 'Stay home.', recipientName: 'Amina');
        $mail->recipientName = self::PLANTED_URL;
        $fromOldPayload = unserialize(serialize($mail));
        $this->assertSame(self::PLANTED_URL, $fromOldPayload->recipientName, 'The premise: the old payload holds the raw name.');

        Mail::to(self::READER)->sendNow($fromOldPayload);

        $this->assertGreeting($this->theOneMessageTo(self::READER), 'Assalamu alaikum,', ['evil.example']);
    }

    #[Test]
    public function a_queued_broadcast_does_not_store_a_planted_name(): void
    {
        // What goes into jobs.payload (and failed_jobs) for a queued broadcast.
        $mail = new BroadcastMail(orgName: 'Masjid An-Nur', title: 'Snow closure', body: 'Stay home.', recipientName: self::PLANTED_URL);
        $job = (fn () => $this->newQueuedJob())->call($mail);

        $this->assertNull($mail->recipientName);
        $this->assertStringNotContainsString('evil.example', serialize($job));

        $kept = new BroadcastMail(orgName: 'Masjid An-Nur', title: 'Snow closure', body: 'Stay home.', recipientName: '  Amina ');
        $this->assertSame('Amina', $kept->recipientName);
    }

    // ---------------------------------------------------------------- nudge

    /** @param list<string> $forbidden */
    #[Test]
    #[DataProvider('names')]
    public function a_class_nudge_greets_a_guardian_only_by_a_name_that_is_a_name(
        string $first,
        string $last,
        string $broadcastGreeting,
        string $nudgeGreeting,
        array $forbidden,
    ): void {
        $school = $this->makeMasjid('Al-Razi School', 'school');

        $tenant = app(TenantContext::class);
        $tenant->set($school->id);

        try {
            $class = Group::factory()->create([
                'masjid_id' => $school->id,
                'kind' => Group::KIND_CLASS,
                'name' => 'Grade 3',
                'slug' => 'g3',
            ]);

            $child = Contact::factory()->create([
                'masjid_id' => $school->id, 'first_name' => 'Amira', 'last_name' => 'Child', 'email' => null,
            ]);
            GroupMembership::create([
                'masjid_id' => $school->id, 'group_id' => $class->id,
                'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
            ]);

            $guardian = Contact::factory()->create([
                'masjid_id' => $school->id, 'first_name' => $first, 'last_name' => $last, 'email' => null,
            ]);
            $guardian->forceFill([
                'login_email' => self::READER, 'login_enabled_at' => now(), 'login_revoked_at' => null,
            ])->save();
            GroupMembership::create([
                'masjid_id' => $school->id, 'group_id' => $class->id,
                'contact_id' => $guardian->id, 'role' => GroupMembership::ROLE_GUARDIAN,
                'guardian_of_contact_id' => $child->id,
                'consent_granted_at' => now(),
                'consent_scope' => GroupMembership::CONSENT_FEED,
            ]);
        } finally {
            $tenant->forgetTenant();
        }

        (new SendGroupNotificationJob($school->id, $class->id, GroupNotificationEvent::CLASS_STORY))
            ->handle(app(GroupNotificationRecipientResolver::class), app(GroupPushChannel::class));

        $email = $this->theOneMessageTo(self::READER);
        $this->assertSame('You have a new update', $email->getSubject());
        $this->assertGreeting($email, $nudgeGreeting, $forbidden);
        $this->assertStringContainsString('posted a new update in Grade 3', $this->visibleText((string) $email->getHtmlBody()));
    }

    #[Test]
    public function the_nudge_cleans_the_name_whoever_builds_it(): void
    {
        $args = ['orgName' => 'Al-Razi School', 'groupLabel' => 'Grade 3', 'kind' => 'message', 'signInUrl' => 'https://manara.test/family/1/sign-in'];

        $this->assertNull((new GroupUpdateNudgeMail(...$args, recipientName: self::PLANTED_URL))->recipientName);
        $this->assertNull((new GroupUpdateNudgeMail(...$args, recipientName: 'Jane jane.doe@example.com'))->recipientName);
        $this->assertSame('Ustadh Bilal', (new GroupUpdateNudgeMail(...$args, recipientName: ' Ustadh Bilal '))->recipientName);

        // A staff name reaches the same greeting (a parent's reply nudges the teacher).
        Mail::to(self::READER)->sendNow(new GroupUpdateNudgeMail(...$args, recipientName: 'www.evil.example'));
        $email = $this->theOneMessageTo(self::READER);
        $this->assertSame('You have a new message', $email->getSubject());
        $this->assertGreeting($email, 'Assalamu alaikum,', ['evil.example']);
    }

    // -------------------------------------------------------------- helpers

    private function makeMasjid(string $name, string $orgType): Masjid
    {
        return Masjid::create([
            'name' => $name,
            'email' => 'office-' . uniqid() . '@org.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'org_type' => $orgType,
        ]);
    }

    /** The one message the array transport received for this address, as it would be sent. */
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

        $this->assertCount(1, $matches, "Expected exactly one message to {$address}; the sender or the mailer dropped or doubled it.");

        return $matches[0];
    }

    /**
     * The greeting is exactly $expected in the HTML, in the words a reader sees,
     * and in the text part if there is one, and no piece of the stored name
     * appears anywhere in them.
     *
     * @param  list<string>  $forbidden
     */
    private function assertGreeting(Email $email, string $expected, array $forbidden): void
    {
        $html = (string) $email->getHtmlBody();
        $this->assertNotSame('', $html, 'The HTML part rendered empty.');

        $parts = ['html' => $html, 'visible text' => $this->visibleText($html)];

        $textPart = $email->getTextBody();
        if ($textPart !== null && $textPart !== '') {
            $parts['text part'] = (string) $textPart;
        }

        foreach ($parts as $label => $body) {
            // Everything from "Assalamu alaikum" up to the end of its line or
            // paragraph. A printed name can hold no "<" and no comma.
            preg_match_all('/Assalamu alaikum[^<,]*,?/u', $body, $found);
            $this->assertCount(1, $found[0], "The {$label} should greet once.");

            $greeting = html_entity_decode(
                trim((string) preg_replace('/\s+/u', ' ', $found[0][0])),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );
            $this->assertSame($expected, $greeting, "The {$label} greeting.");

            foreach ($forbidden as $piece) {
                $this->assertStringNotContainsString($piece, $body, "The {$label} prints part of a stored name that is not a name.");
            }
        }
    }

    /** What a reader sees: the HTML with its head removed, tags stripped and whitespace collapsed. */
    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<head\b.*?</head>#is', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
