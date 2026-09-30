<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Mail\FamilyPortalInviteMail;
use App\Mail\GroupUpdateNudgeMail;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Moving Manara's mail to its own sending domain is ONE production `.env`
 * line, MAIL_FROM_ADDRESS, because every mail reads its address from
 * `config('mail.from.address')`. This file keeps that true, and pins the
 * `mail:test-send` command used to prove a new domain before and after the
 * switch. See docs/mail-sending-domain.md.
 */
class MailSendingDomainTest extends TestCase
{
    use RefreshDatabase;

    private const SENDER = 'notifications@sender.example.test';

    /** Set by captureMailer(); keeps every message the Mailer hands it. */
    private ?TransportInterface $transport = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => self::SENDER, 'mail.from.name' => 'Manara']);
    }

    // ------------------------------------------------------ the school mails

    #[Test]
    public function the_school_mails_send_from_the_configured_address_in_the_organisations_name(): void
    {
        $mails = [
            'class nudge' => new GroupUpdateNudgeMail(
                orgName: 'Al-Razi School',
                groupLabel: 'Grade 3',
                kind: 'update',
                signInUrl: 'https://portal.example.test/parents',
                orgEmail: 'office@alrazi.example.test',
            ),
            'portal invite' => new FamilyPortalInviteMail(
                orgName: 'Al-Razi School',
                url: 'https://portal.example.test/parents/invite/abc',
                orgEmail: 'office@alrazi.example.test',
            ),
            'sign-in code' => new FamilyLoginCodeMail(
                orgName: 'Al-Razi School',
                code: '123456',
                expiresInMinutes: 10,
                orgEmail: 'office@alrazi.example.test',
            ),
        ];

        foreach ($mails as $label => $mail) {
            // assertFrom renders first, so a From set in build() counts, as it
            // does at send time; hasFrom alone reads only the envelope.
            $mail->assertFrom(self::SENDER, 'Al-Razi School');
            $this->assertTrue($mail->hasReplyTo('office@alrazi.example.test'), "{$label}: Reply-To");
        }
    }

    #[Test]
    public function no_mail_code_carries_an_address_literal(): void
    {
        // Addresses come from config or from data. One literal sender here would
        // keep sending from the old domain after the switch, and nothing would
        // fail: Resend accepts any domain the account has verified, and
        // tapcraft.tech stays verified. String tokens only, so an address in a
        // comment (there are several, as examples) is not a finding.
        $files = [...glob(app_path('Mail/*.php')), app_path('Logging/OpsAlertMailHandler.php')];
        $offenders = [];
        foreach ($files as $file) {
            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (is_array($token)
                    && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z.]{2,}/', $token[1])) {
                    $offenders[] = basename($file).':'.$token[2].' '.$token[1];
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    // ------------------------------------------------------- mail:test-send

    #[Test]
    public function it_sends_one_message_framed_as_the_organisation(): void
    {
        $this->captureMailer('capture');
        $org = $this->makeMasjid();

        $this->artisan('mail:test-send', ['to' => 'owner@example.test', '--org' => $org->id])
            ->expectsOutputToContain('Accepted by the transport')
            ->assertSuccessful();

        $this->assertCount(1, $this->captured());
        $email = $this->captured()[0];
        $this->assertSame('owner@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame(self::SENDER, $email->getFrom()[0]->getAddress());
        $this->assertSame('Al-Razi School', $email->getFrom()[0]->getName());
        $this->assertSame('office@alrazi.example.test', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('Manara sending-domain test', $email->getSubject());
    }

    #[Test]
    public function without_an_organisation_it_uses_the_platform_name_and_no_reply_to(): void
    {
        $this->captureMailer('capture');

        $this->artisan('mail:test-send', ['to' => 'owner@example.test'])->assertSuccessful();

        $this->assertSame('Manara', $this->captured()[0]->getFrom()[0]->getName());
        $this->assertSame([], $this->captured()[0]->getReplyTo());
    }

    #[Test]
    public function from_overrides_the_configured_address_for_this_one_message(): void
    {
        $this->captureMailer('capture');

        $this->artisan('mail:test-send', ['to' => 'owner@example.test', '--from' => 'notifications@new.example.test'])
            ->assertSuccessful();
        $this->artisan('mail:test-send', ['to' => 'owner@example.test', '--from' => 'not an address'])
            ->assertFailed();

        $this->assertCount(1, $this->captured());
        $this->assertSame('notifications@new.example.test', $this->captured()[0]->getFrom()[0]->getAddress());
        $this->assertSame(self::SENDER, config('mail.from.address'));
    }

    #[Test]
    public function a_mailer_that_delivers_nowhere_is_a_failure_not_a_success(): void
    {
        foreach (['log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);

            $this->artisan('mail:test-send', ['to' => 'owner@example.test'])
                ->expectsOutputToContain('NOT SENT')
                ->assertFailed();
        }
    }

    #[Test]
    public function it_refuses_anything_but_one_valid_address_and_a_real_organisation(): void
    {
        $this->captureMailer('capture');

        $this->artisan('mail:test-send', ['to' => 'a@example.test,b@example.test'])->assertFailed();
        $this->artisan('mail:test-send', ['to' => 'not-an-address'])->assertFailed();
        $this->artisan('mail:test-send', ['to' => 'owner@example.test', '--org' => 999999])->assertFailed();

        $this->assertSame([], $this->captured());
    }

    #[Test]
    public function prompt_key_sends_through_resend_with_the_typed_key_for_this_run_only(): void
    {
        // Staging: MAIL_MAILER=log and RESEND_KEY blank, as the egress rule says.
        config(['mail.default' => 'log', 'services.resend.key' => null]);
        $this->captureMailer('resend');

        $this->artisan('mail:test-send', ['to' => 'owner@example.test', '--prompt-key' => true])
            ->expectsQuestion('Resend API key (used for this one message, never stored)', 're_test_key')
            ->assertSuccessful();

        $this->assertCount(1, $this->captured());
        $this->assertSame('re_test_key', config('services.resend.key'));
    }

    #[Test]
    public function prompt_key_refuses_something_that_is_not_a_resend_key(): void
    {
        config(['mail.default' => 'log']);
        $this->captureMailer('resend');

        $this->artisan('mail:test-send', ['to' => 'owner@example.test', '--prompt-key' => true])
            ->expectsQuestion('Resend API key (used for this one message, never stored)', 'sk_live_nope')
            ->expectsOutputToContain('Nothing was sent')
            ->assertFailed();

        $this->assertSame([], $this->captured());
    }

    // ------------------------------------------------------------ helpers

    /**
     * Point a mailer at a transport that keeps what it is handed, so a test can
     * read the message the real Mailer built. For 'resend' this stands in for
     * the network; for any other name it becomes the default mailer.
     */
    private function captureMailer(string $name): void
    {
        $this->transport = new class implements TransportInterface
        {
            /** @var list<Email> */
            public array $messages = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->messages[] = $message;

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'capture';
            }
        };

        $transport = $this->transport;
        Mail::extend($name, fn () => $transport);
        Mail::purge($name);

        if ($name !== 'resend') {
            config(["mail.mailers.{$name}" => ['transport' => $name], 'mail.default' => $name]);
        }
    }

    /** @return list<Email> what the capturing transport was handed */
    private function captured(): array
    {
        return $this->transport?->messages ?? [];
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Al-Razi School',
            'email' => 'office@alrazi.example.test',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }
}
