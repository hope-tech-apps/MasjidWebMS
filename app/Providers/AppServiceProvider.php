<?php

namespace App\Providers;

use App\Events\SendMasjidNotificationEvent;
use App\Listeners\SentMasjidNotificationLitener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            SendMasjidNotificationEvent::class,
            SentMasjidNotificationLitener::class
        );

        $this->responseMacro();
    }


    public function responseMacro()
    {
        Response::macro('api', function ($status = 200, $message = '', $data = [],  $headers = []) {
            $result = [
                'status' => $status === 200 ? 'success' : 'error',
                'message' => $message,
                'data' => $data
            ];
            return response()->json(array_filter($result), $status, $headers);
        });
    }
}
