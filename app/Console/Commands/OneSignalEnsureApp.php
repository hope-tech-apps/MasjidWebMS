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
 * apps are on the shared app. `--bundle-id` supplies the iOS bundle id of an
 * organisation that has none on file yet, as the route's bundle_id does, so a
 * new organisation can be checked before it has one:
 * `onesignal:ensure-app 42 --platform=ios --bundle-id=com.example.app --pretend`.
 *
 * Exit code 0 for an outcome after which the organisation has (or would have)
 * its app, 1 for every refusal or failure.
 */
class OneSignalEnsureApp extends Command
{
    protected $signature = 'onesignal:ensure-app
                            {masjid_id : The organisation}
                            {--platform=* : ios and/or android (default: both)}
                            {--bundle-id= : The iOS bundle id, used when the organisation has none on file}
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

        $bundleId = $this->option('bundle-id');
        if ($bundleId !== null) {
            if (! is_string($bundleId) || trim($bundleId) === '' || mb_strlen($bundleId) > 155) {
                $this->error('--bundle-id must be 1 to 155 characters.');

                return self::FAILURE;
            }
            if ($provisioner->bundleIdIsTakenByAnother($bundleId, $org)) {
                $this->error('That bundle id belongs to another organisation.');

                return self::FAILURE;
            }
        }

        $result = $provisioner->ensureApp($org, array_values($platforms), (bool) $this->option('pretend'), $bundleId);

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
