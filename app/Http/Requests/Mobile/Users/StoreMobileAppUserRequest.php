<?php

namespace App\Http\Requests\Mobile\Users;

use App\Http\Requests\BaseFormRequest;

/**
 * `POST /api/mobile/user` — first-launch device registration.
 *
 * The two required rules are the two the handler cannot work without: it looks
 * the organisation up and the device row is keyed by `device_id`.
 *
 * DELIBERATELY NOT extended with AppClientHeader::rules(), and this is the same
 * call the heartbeat already makes. The three telemetry fields
 * (`app_platform`, `app_version`, `app_build`) are counted and never authorise
 * anything, and AppClientHeader::resolve() is already the floor that keeps an
 * unusable value out of the column — it DROPS a value that breaks the platform
 * list or the varchar width rather than writing it, so the MySQL 1406 that the
 * `sqlite-hides-mysql-column-limits` note exists for cannot happen down this
 * path either.
 *
 * Adding a rule buys a 422 for a well-behaved R1 client and risks refusing a
 * first launch. This is the launch-critical verb: a refused registration was
 * measured stranding a new iPhone on its splash screen (see AppServiceProvider,
 * the mobile throttle note). Nothing establishes that no shipped build — the
 * Burlington store v2.5 b44, Play vc13, the MEC TestFlight — already sends a
 * body key spelled `app_version`; it would have been ignored until now, and a
 * `string` rule would turn `"app_version": 44` into a phone that never
 * registers, with no server-side symptom because the refusal is well-formed.
 *
 * A field nobody reads must never be able to stop a phone from starting.
 * Pinned by InstalledBuildsContractTest.
 */
class StoreMobileAppUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'masjid_id' => 'required|exists:masjids,id',
            'device_id' => 'required|string',
        ];
    }
}
