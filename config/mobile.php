<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App API rate limits
    |--------------------------------------------------------------------------
    |
    | Read by the `mobile`, `mobile-checkout`, `device` and `device-activity`
    | limiters in AppServiceProvider. Config rather than literals for the same
    | reason as config/member.php: an operator raising a ceiling during an event
    | must not need a deploy, and a test must be able to state the window it
    | exercises. The provider repeats every default, so a stale config cache
    | that predates this file still gets exactly these numbers.
    |
    | "Per device" is the device id the request carries PLUS the IP. "Per IP" is
    | every phone behind one public address: a masjid's Wi-Fi, a festival
    | venue, a carrier NAT pool. See DECISIONS.md, 2026-09-15.
    |
    */

    'api' => [

        /*
        | Every /api/mobile route (`throttle:mobile`, the outermost limiter).
        |
        | What a real phone does: one iOS launch sends about ten requests. The
        | app-config gate goes first. Registration (first launch only), the
        | masjid, its features and its prayer settings are then awaited together.
        | A heartbeat follows, then the home screen's iqama settings,
        | announcements and events. The splash does not reach Home if an awaited
        | payload is refused. The apps name their device only on the device and
        | member routes; their reads carry no device id.
        |
        | - 60/minute per device: six launches' worth. Applied ONLY when the
        |   request names its device (`device_id`, or an `X-Device-Id` header no
        |   shipped build sends yet). Without one there is no per-device layer,
        |   because an IP fallback would bring back the shared bucket.
        | - 1800/minute per IP: roughly 180 phones opening the app in the same
        |   minute on one network (after Jummah, at a festival gate). That is
        |   30 requests a second from one address; it is an estimate from the
        |   apps' launch code, not a load test. The old limit was 60, about six
        |   launches.
        */
        'per_minute_per_device' => (int) env('MOBILE_API_PER_MINUTE_PER_DEVICE', 60),
        'per_minute_per_ip' => (int) env('MOBILE_API_PER_MINUTE_PER_IP', 1800),

        /*
        | Donation checkout (`throttle:mobile-checkout`). Every call writes a
        | pending donation and opens a Stripe session, so it does not inherit
        | the raised ceiling above. 60/minute per IP is exactly what it had under
        | the old group limit.
        */
        'checkouts_per_minute_per_ip' => (int) env('MOBILE_API_CHECKOUTS_PER_MINUTE_PER_IP', 60),
    ],

    'device' => [

        /*
        | Registration (POST /api/mobile/user) and update (PUT). Each NEW
        | device id inserts a `mobile_app_users` row, so this is the bucket an
        | id-inventing script would target. Repeating an id that already exists
        | inserts nothing: `device_id` is unique, and the controller answers the
        | failed insert with a 500.
        |
        | What a real phone does: both apps register ONCE per install and
        | remember the result (iOS keeps the returned device id; Android sets a
        | "registered" flag and retries on the next launch only if the call
        | failed). A legitimate phone therefore sends one or two of these an hour.
        |
        | - 10/minute per device: a retry loop is stopped within seconds, and
        |   the calls this limiter refuses never reach its network ceiling below.
        |   (`throttle:mobile` has already counted them; see its per-device
        |   layer above.)
        | - 60/hour per device: many times what a phone needs, so a reinstall,
        |   a tester or a flaky network is never refused.
        | - 600/hour per IP: room for a crowd installing at once. A festival
        |   where 300 people install on the venue Wi-Fi within the hour, each
        |   with one retry, fits. It also bounds an id-inventing script to 600
        |   new rows an hour from one address. The old limit allowed 10.
        */
        'writes_per_minute_per_device' => (int) env('MOBILE_DEVICE_WRITES_PER_MINUTE_PER_DEVICE', 10),
        'writes_per_hour_per_device' => (int) env('MOBILE_DEVICE_WRITES_PER_HOUR_PER_DEVICE', 60),
        'writes_per_hour_per_ip' => (int) env('MOBILE_DEVICE_WRITES_PER_HOUR_PER_IP', 600),

        /*
        | Heartbeat (POST /api/mobile/user/heartbeat) and the device→masjid
        | lookup (GET /api/mobile/user/masjid). Looser, because neither creates
        | a row: the heartbeat UPDATEs a timestamp and push id on an existing
        | row (a no-op for an unknown id), and the lookup only reads.
        |
        | What a real phone does: iOS sends a heartbeat 3 s after every launch
        | and again whenever its push subscription changes. Android sends one
        | when the app process starts and on every subscription change. Current
        | builds never call the lookup; it stays in the same bucket for older
        | installs. Heartbeats follow LAUNCHES, not installs, so a busy hour
        | (Jummah, Eid, a festival) sends more of these than registrations.
        |
        | - 20/minute per device: a burst guard, as above.
        | - 120/hour per device: a launch every 30 seconds for an hour.
        | - 1200/hour per IP: twice the registration ceiling, for the reason
        |   above. `throttle:mobile` (the `api` numbers) still sits in front of
        |   all of this.
        */
        'activity_per_minute_per_device' => (int) env('MOBILE_DEVICE_ACTIVITY_PER_MINUTE_PER_DEVICE', 20),
        'activity_per_hour_per_device' => (int) env('MOBILE_DEVICE_ACTIVITY_PER_HOUR_PER_DEVICE', 120),
        'activity_per_hour_per_ip' => (int) env('MOBILE_DEVICE_ACTIVITY_PER_HOUR_PER_IP', 1200),
    ],
];
