<?php

namespace App\Listeners;

use App\Events\SendMasjidNotificationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SentMasjidNotificationLitener
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(SendMasjidNotificationEvent $event): void
    {
        // $notification = $event->notification;
        // if($notification) {
        //     $notification->update(['is_broadcasted' => true]);
        // }
    }
}
