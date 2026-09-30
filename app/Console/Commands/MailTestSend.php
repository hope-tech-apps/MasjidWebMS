<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Support\Environment;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends ONE message to ONE address you name, through the real mail transport,
 * to prove the sending domain works end to end: the From address is accepted
 * by Resend, and SPF, DKIM and DMARC pass at the recipient.
 *
 *   php artisan mail:test-send you@example.com --org=14
 *   php artisan mail:test-send you@example.com --org=14 --prompt-key   # staging
 *   php artisan mail:test-send you@example.com --org=14 \
 *       --from=notifications@manara.hopetechapps.com --prompt-key       # a new domain, before the switch
 *
 * The From is framed the way the school mails frame it (GroupUpdateNudgeMail,
 * FamilyPortalInviteMail, FamilyLoginCodeMail): the organisation's name at
 * `config('mail.from.address')`, replies to the organisation's email. So what
 * lands is what a parent would see in the From line, minus the content.
 *
 * `--from` proves a new domain before MAIL_FROM_ADDRESS moves to it, without
 * touching any `.env`. Resend refuses a domain the account has not verified,
 * which is the answer this is asking for.
 *
 * `--prompt-key` exists for staging. Staging's `.env` keeps RESEND_KEY blank
 * and MAIL_MAILER=log on purpose (.claude/rules/environments.md, "Staging
 * egress"), and that stays true: the key is read from a hidden prompt, used by
 * this process for this one message, and never written anywhere. The recipient
 * is the argument and nothing else; this command reads no contact.
 *
 * A mailer that cannot deliver (log, array) exits non-zero and says so. A run
 * that "succeeded" into the log file is the silent success this command exists
 * to rule out.
 */
class MailTestSend extends Command
{
    protected $signature = 'mail:test-send
        {to : The one address to send to (your own)}
        {--org= : Masjid id whose name and email frame the From and Reply-To, as the school mails do}
        {--from= : Send from this address instead of MAIL_FROM_ADDRESS (a new domain, before the switch)}
        {--prompt-key : Ask for a Resend key and send through Resend for this run only (staging)}';

    protected $description = 'Send one sending-domain test message to one address through the real transport.';

    /** Mailers that accept a message and deliver it nowhere. */
    private const NON_DELIVERING = ['log', 'array'];

    public function handle(): int
    {
        $to = trim((string) $this->argument('to'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("Not one valid email address: {$to}");

            return self::FAILURE;
        }

        $org = null;
        if ($this->option('org') !== null) {
            $org = Masjid::query()->find((int) $this->option('org'));
            if ($org === null) {
                $this->error("No masjid with id {$this->option('org')}.");

                return self::FAILURE;
            }
        }

        $fromAddress = trim((string) ($this->option('from') ?? config('mail.from.address')));
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("Not one valid From address: {$fromAddress}");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');

        if ($this->option('prompt-key')) {
            $key = trim((string) $this->secret('Resend API key (used for this one message, never stored)'));
            if (! str_starts_with($key, 're_')) {
                $this->error('That is not a Resend API key (they start with re_). Nothing was sent.');

                return self::FAILURE;
            }

            config(['services.resend.key' => $key]);
            // A resend mailer built earlier in this process would still hold
            // the old (blank) key.
            Mail::purge('resend');
            $mailer = 'resend';
        }

        if (in_array(config("mail.mailers.{$mailer}.transport"), self::NON_DELIVERING, true)) {
            $this->error("NOT SENT: the mailer is '{$mailer}', which delivers nowhere. On staging, pass --prompt-key.");

            return self::FAILURE;
        }

        $fromName = $org?->name ?: (string) config('mail.from.name');
        $replyTo = $org?->email && filter_var($org->email, FILTER_VALIDATE_EMAIL) ? $org->email : null;

        $body = implode("\n", [
            'This is a sending-domain test from Manara. No action is needed.',
            '',
            'Environment: '.Environment::name(),
            'Mailer: '.$mailer,
            "From: {$fromName} <{$fromAddress}>",
            'Reply-To: '.($replyTo ?? '(none)'),
            'Sent: '.now()->toIso8601String(),
            '',
            'To check authentication in Gmail: open the message, then More (three dots) > Show original.',
            'SPF, DKIM and DMARC should each read PASS.',
        ]);

        try {
            $sent = Mail::mailer($mailer)->raw($body, function (Message $message) use ($to, $fromAddress, $fromName, $replyTo) {
                $message->to($to)
                    ->from($fromAddress, $fromName)
                    ->subject('Manara sending-domain test');
                if ($replyTo !== null) {
                    $message->replyTo($replyTo);
                }
            });
        } catch (Throwable $e) {
            // An operator's terminal, not a log: the transport's own message
            // (for example Resend's "domain is not verified") is the useful part.
            $this->error('NOT SENT: '.$e::class.': '.$e->getMessage());

            return self::FAILURE;
        }

        $resendId = $sent?->getOriginalMessage()->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString();

        $this->line("Mailer:   {$mailer}");
        $this->line("From:     {$fromName} <{$fromAddress}>");
        $this->line('Reply-To: '.($replyTo ?? '(none)'));
        $this->line("To:       {$to}");
        $this->info('Accepted by the transport'.($resendId ? " (Resend id {$resendId})." : '.'));
        $this->line('Accepted is not delivered: confirm it arrived, and read its authentication results.');

        return self::SUCCESS;
    }
}
