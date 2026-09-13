<?php

namespace App\Console\Commands;

use App\Mail\TwoFactorResetMail;
use App\Models\TwoFactorResetEvent;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The LAST-RESORT half of the stranded-second-factor recovery path.
 *
 * The dashboard door (POST /api/admin/2fa/reset/{user}) is the one operators
 * should use: it makes the acting SuperAdmin pass their own live code, and it
 * refuses to act on the operator's own account. That refusal leaves exactly one
 * case unanswered — the LAST SuperAdmin, stranded, with nobody else holding the
 * role to help them. This command is that case, and only that case.
 *
 * It requires shell access to the production host, which is a much smaller
 * circle than "holds a SuperAdmin password"; that is the whole reason it is
 * allowed to skip the live-code step the endpoint enforces. What it does NOT
 * skip is the ledger: `--by` is REQUIRED and must name a human being, `--reason`
 * is REQUIRED, and both land on `two_factor_reset_events` exactly as the
 * endpoint's do, with the channel marked `console` so a reviewer can tell the
 * two doors apart. The affected user is emailed the same notice.
 *
 * The alternative it replaces is an UPDATE typed into the production database,
 * which clears the same columns while recording nothing and telling nobody.
 * Somebody will always be able to do that; the point of this command is that
 * there is never a REASON to, so a bare UPDATE on `two_factor_*` is by itself
 * evidence of something worth asking about.
 */
class TwoFactorReset extends Command
{
    protected $signature = 'two-factor:reset
                            {email : The address of the account whose second factor is stranded}
                            {--by= : The NAME of the human being performing this, recorded forever}
                            {--reason= : Why, in a sentence, recorded forever}
                            {--no-email : Skip the notice to the account holder (say why in --reason)}';

    protected $description = 'Clear a stranded second factor for one account, recording who did it and why. Operator last resort; the dashboard door is preferred.';

    public function handle(TwoFactorService $twoFactor): int
    {
        $email = trim((string) $this->argument('email'));
        $by = trim((string) $this->option('by'));
        $reason = trim((string) $this->option('reason'));

        // Not `required` in the signature, because an option with no value
        // passes that check ("--by" alone is an empty string) and an
        // unattributed row is the one thing this command must never write.
        if ($by === '' || mb_strlen($by) < 3) {
            $this->error('--by must name the person doing this (e.g. --by="Moneeb Sayed"). An unattributed reset is not one.');

            return self::FAILURE;
        }

        if (mb_strlen($reason) < 10) {
            $this->error('--reason must say what happened in a sentence. It is kept on the account record.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error('No account with that address.');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled() && empty($user->two_factor_secret)) {
            $this->warn('That account has no second factor set up. Nothing to clear.');

            return self::SUCCESS;
        }

        $this->line('About to clear two-step sign-in for '.$user->name.' <'.$user->email.'>.');
        $this->line('Recorded as: '.$by.' — '.$reason);

        if (! $this->confirm('Clear it?', false)) {
            $this->info('Left alone.');

            return self::SUCCESS;
        }

        // Ledger first, inside the transaction, for the same reason the
        // endpoint does it that way: if the record cannot be written, the
        // factor is not cleared.
        DB::transaction(function () use ($user, $by, $reason, $twoFactor) {
            TwoFactorResetEvent::create([
                'user_id' => $user->getKey(),
                'user_email' => $user->email,
                'performed_by_user_id' => null,
                'performed_by_label' => $by.' (console)',
                'channel' => TwoFactorResetEvent::CHANNEL_CONSOLE,
                'reason' => $reason,
                'ip_address' => null,
            ]);

            $twoFactor->forget($user);
        });

        if (! $this->option('no-email') && ! empty($user->email)) {
            try {
                Mail::to($user->email)->send(new TwoFactorResetMail(
                    $user,
                    $by,
                    $reason,
                    now()->toDayDateTimeString(),
                ));
                $this->info('Cleared, recorded, and '.$user->email.' has been told.');
            } catch (\Throwable $e) {
                Log::error('Two-factor reset notice could not be delivered', [
                    'user_id' => $user->getKey(),
                    'error' => $e->getMessage(),
                ]);
                $this->warn('Cleared and recorded, but the notice email failed: '.$e->getMessage());
                $this->warn('Tell them another way — an account whose second factor vanished silently is the failure mode this notice exists to prevent.');
            }
        } else {
            $this->info('Cleared and recorded. No email sent.');
        }

        return self::SUCCESS;
    }
}
