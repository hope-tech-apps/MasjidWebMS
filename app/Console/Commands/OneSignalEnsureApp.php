<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Services\OneSignalProvisioningService;
use Illuminate\Console\Command;

/**
 * Give one organisation its own OneSignal app, or say why not (W2 S14, D9).
 *
 * `--pretend` evaluates every guard up to the first request and prints the
 * outcome, sending nothing and writing nothing. It is how the refusals are
 * verified on production: `onesignal:ensure-app 1 --platform=ios --pretend`
 * prints refused_live_org for Burlington, NAFIS (5) and MEC (13), whose live
 * apps are on the shared app.
 *
 * Exit code 0 for an outcome after which the organisation has (or would have)
 * its app, 1 for every refusal or failure.
 */
class OneSignalEnsureApp extends Command
{
    protected $signature = 'onesignal:ensure-app
                            {masjid_id : The organisation}
                            {--platform=* : ios and/or android (default: both)}
                            {--pretend : Evaluate the guards and print the outcome; send nothing}';

    protected $description = "Create an organisation's own OneSignal app (or add a platform, or mint its key), behind the live-organisation and live-audience guards.";

    public function handle(OneSignalProvisioningService $provisioner): int
    {
        $org = Masjid::find((int) $this->argument('masjid_id'));
        if (! $org) {
            $this->error('No organisation '.$this->argument('masjid_id').'.');

            return self::FAILURE;
        }

        $platforms = $this->option('platform') ?: OneSignalProvisioningService::PLATFORMS;
        $unknown = array_diff($platforms, OneSignalProvisioningService::PLATFORMS);
        if ($unknown !== [] || count($platforms) !== count(array_unique($platforms))) {
            $this->error('--platform takes ios and/or android, each once.');

            return self::FAILURE;
        }

        $result = $provisioner->ensureApp($org, array_values($platforms), (bool) $this->option('pretend'));

        $this->line(($result->pretended ? '[pretend] ' : '')."organisation {$org->id}: {$result->outcome}");
        foreach ([
            'app id' => $result->appId,
            'REST key on file' => $result->hasKey ? 'yes' : 'no',
            'platforms' => implode(', ', $result->platforms) ?: '(none)',
            'HTTP status' => $result->httpStatus,
            'note' => $result->message ?: null,
        ] as $label => $value) {
            if ($value !== null) {
                $this->line("  {$label}: {$value}");
            }
        }

        return $result->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
