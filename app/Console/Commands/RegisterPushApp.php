<?php

namespace App\Console\Commands;

use App\Models\PushApp;
use Illuminate\Console\Command;

/** Register an existing provider app with a hidden prompt; credentials never enter shell arguments. */
class RegisterPushApp extends Command
{
    protected $signature = 'push:register {app_id : Provider app id} {label : Operator label} {--inactive : Disable sending through this app}';

    protected $description = 'Register an existing push app with its encrypted send credential.';

    public function handle(): int
    {
        $appId = $this->argument('app_id');
        $label = $this->argument('label');
        if (!is_string($appId) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $appId)
            || !is_string($label) || trim($label) === '' || strlen($label) > 255 || preg_match('/[\x00-\x1F\x7F]/', $label)) {
            $this->error('Supply a valid provider app id and a label of at most 255 characters.');
            return self::FAILURE;
        }
        $key = $this->secret('App REST API key');
        if (!is_string($key) || trim($key) === '') {
            $this->error('A send credential is required.');
            return self::FAILURE;
        }
        try {
            PushApp::updateOrCreate(['provider_app_id' => $appId], ['label' => $label, 'rest_api_key' => $key,
                'active' => !$this->option('inactive')]);
        } catch (\Throwable $e) {
            $this->error('The push app credential could not be stored.');
            return self::FAILURE;
        }
        $this->info('Push app registered.');
        return self::SUCCESS;
    }
}
