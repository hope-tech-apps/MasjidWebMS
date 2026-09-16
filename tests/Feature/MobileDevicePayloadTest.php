<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The device endpoints publish a device's IDENTITY and nothing about the
 * person holding it.
 *
 * ## What happened
 *
 * `POST /api/mobile/user` and `PUT /api/mobile/user` returned the
 * `mobile_app_users` model whole to an UNAUTHENTICATED caller. That row carries
 * `contact_id` — which member is signed in on the handset — and
 * `onesignal_subscription_id`, that member's push identity.
 *
 * `device_id` is not a secret and is never checked as one: `store` validates it
 * as `required|string` and `update` as `exists:mobile_app_users,device_id`. And
 * because registration is idempotent, POSTing an id that ALREADY EXISTS returns
 * that existing row — so knowing or guessing one id was enough to learn who
 * owns that phone and how to find them in OneSignal. No write of one's own, no
 * account, no token. Found by review, 2026-09-15 (PF-4).
 *
 * The same class of defect took the whole `masjids` row out through
 * `GET /api/mobile/user/masjid` until `d20ad1f` (see
 * `PublicMasjidDirectoryTest`); this is the device row's turn.
 *
 * ## Why this file asserts the way it does
 *
 * Two lessons from that earlier round, both of which a name-based guard fails:
 *
 * 1. **Assert the EXACT key set, not the absence of names.** A denylist is a
 *    snapshot of what somebody thought was sensitive today; the next column
 *    added to `mobile_app_users` publishes itself. `the_wire_format_is_exactly_…`
 *    pins the whole set, and `every_mobile_app_users_column_is_deliberately_classified`
 *    makes a new column fail here before it can reach a phone.
 *
 * 2. **Assert on VALUES too.** The review that found PF-4 also found a case
 *    where a withheld column's value was republished under a different key — a
 *    key-name check passes that happily. So every withheld column is planted
 *    with a sentinel and the raw body is searched for it.
 */
class MobileDevicePayloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exactly what goes on the wire, and why each key is there.
     *
     * Established by reading the decoders in the builds that are ALREADY ON
     * PHONES, not the current app branches:
     *   - iOS `main` (App Store): `DeviceId` in
     *     Masjid/Models/ObjectModels/DeviceId.swift — `id: Int` and
     *     `deviceId: String` are NON-OPTIONAL, so a missing key is a decode
     *     failure and a device that never registers. `masjidId`, `userAgent`,
     *     `updatedAt`, `createdAt` are `String?`/`Int?`.
     *   - Android `master` (Play vc13): `DeviceRegistrationData` in
     *     data/models/DeviceRegistration.kt — `id: Int` and
     *     `device_id: String` are non-null Kotlin types; the rest are nullable.
     *
     * So `id` and `device_id` are load-bearing. The other four are optional on
     * both platforms and are kept only because both still decode them — this is
     * a privacy fix, not a payload redesign.
     *
     * @var list<string>
     */
    private const PUBLISHED = [
        'id', 'device_id', 'masjid_id', 'user_agent', 'created_at', 'updated_at',
    ];

    /**
     * Columns that must never reach an unauthenticated caller, each with the
     * sentinel planted in it so the VALUE can be searched for.
     *
     * @var array<string, string>
     */
    private const WITHHELD = [
        // Which member is signed in on this handset. The whole point of the finding.
        'contact_id' => '424242',
        // That member's push identity: enough to target them in OneSignal.
        'onesignal_subscription_id' => 'a1b2c3d4-SENTINEL-PUSH-ID',
        // When this person last opened the app. Read by nothing on either platform.
        'last_active_at' => '2019-07-04T11:22:33.000000Z',
        // Build telemetry, added by S1 (cd14175) AFTER this guard was written —
        // see the note on the census test below. What the handset says it is
        // running, for `app-telemetry:builds`. Withheld rather than published
        // for the plainest of reasons: the client SENT these, no decoder on
        // either platform reads them back on any branch, and echoing a phone its
        // own build string serves nobody while committing this payload to every
        // telemetry column added from here on. Sentinels stay inside the real
        // column widths (varchar 10/20/20), which SQLite does not enforce.
        'app_platform' => 'zzz-plat-1',
        'app_version' => 'zzz-version-SENTINEL',
        'app_build' => 'zzz-build-SENTINEL99',
    ];

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masjid = $this->makeMasjid('Home');
    }

    /**
     * THE SHAPE GUARD. Not "is `contact_id` absent" — "is the key set exactly
     * this". A column added next year fails here.
     */
    #[Test]
    public function the_wire_format_is_exactly_the_published_keys_on_registration(): void
    {
        $row = $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->masjid->id,
            'device_id' => 'install-shape-1',
        ])->assertOk()->json('data');

        $this->assertExactlyPublished($row, 'POST /api/mobile/user');
    }

    #[Test]
    public function the_wire_format_is_exactly_the_published_keys_on_update(): void
    {
        $this->registerDevice('install-shape-2');
        $elsewhere = $this->makeMasjid('Other');

        $row = $this->putJson('/api/mobile/user', [
            'masjid_id' => $elsewhere->id,
            'device_id' => 'install-shape-2',
        ])->assertOk()->json('data');

        $this->assertExactlyPublished($row, 'PUT /api/mobile/user');
    }

    /**
     * THE ATTACK, exactly as an outsider would run it: POST an id that already
     * belongs to somebody. Registration is idempotent, so the server answers
     * with THEIR row — which is how a stranger reached a member's push identity
     * without writing anything of their own.
     */
    #[Test]
    public function re_registering_somebody_elses_device_id_discloses_nothing_about_them(): void
    {
        $device = $this->registerDevice('somebody-elses-phone');
        $this->plantEverythingWithheldOn($device);

        $response = $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->masjid->id,
            'device_id' => 'somebody-elses-phone',
        ])->assertOk();

        $this->assertExactlyPublished($response->json('data'), 'the re-registration path');
        $this->assertNoSentinelAnywhereIn($response->getContent(), 'the re-registration path');
    }

    /**
     * THE VALUE GUARD, because the shape guard above is blind to a withheld
     * column republished under a name it approves of. The review that found
     * PF-4 found exactly that elsewhere, so this asks a different question:
     * does the value appear ANYWHERE in the body, under any key at all?
     */
    #[Test]
    public function no_withheld_value_reaches_the_wire_under_any_key(): void
    {
        $device = $this->registerDevice('install-values');
        $this->plantEverythingWithheldOn($device);
        $elsewhere = $this->makeMasjid('Other');

        $bodies = [
            'POST /api/mobile/user' => $this->postJson('/api/mobile/user', [
                'masjid_id' => $this->masjid->id,
                'device_id' => 'install-values',
            ])->assertOk()->getContent(),

            'PUT /api/mobile/user' => $this->putJson('/api/mobile/user', [
                'masjid_id' => $elsewhere->id,
                'device_id' => 'install-values',
            ])->assertOk()->getContent(),

            'POST /api/mobile/user/heartbeat' => $this->postJson('/api/mobile/user/heartbeat', [
                'device_id' => 'install-values',
            ])->assertOk()->getContent(),

            'GET /api/mobile/user/masjid' => $this->getJson(
                '/api/mobile/user/masjid?device_id=install-values'
            )->assertOk()->getContent(),
        ];

        foreach ($bodies as $label => $body) {
            $this->assertNoSentinelAnywhereIn($body, $label);
        }
    }

    /**
     * What the shipped builds decode must still be there, and must not be null.
     * The fix is to stop publishing a member's identity, not to break every
     * install's registration.
     */
    #[Test]
    public function what_the_shipped_apps_decode_is_still_published(): void
    {
        $row = $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->masjid->id,
            'device_id' => 'install-decodes',
        ])->assertOk()->json('data');

        // Non-optional in iOS `main` (DeviceId.id: Int) and Android `master`
        // (DeviceRegistrationData.id: Int). A null here is a decode failure on
        // a phone that is already in somebody's pocket.
        $this->assertArrayHasKey('id', $row);
        $this->assertIsInt($row['id']);

        // Non-optional in iOS `main` (deviceId: String) and Android `master`
        // (device_id: String).
        $this->assertArrayHasKey('device_id', $row);
        $this->assertSame('install-decodes', $row['device_id']);

        // Optional on both, but both decode them and the values must stay true.
        $this->assertSame($this->masjid->id, $row['masjid_id']);
        $this->assertArrayHasKey('user_agent', $row);
        $this->assertArrayHasKey('created_at', $row);
        $this->assertArrayHasKey('updated_at', $row);
    }

    /**
     * `Response<T>` in the iOS app decodes `status: String` and `data: T` as
     * NON-optional, and Android `master` declares `status: String` non-null.
     * A projection that answered `data: null` would fail to decode on both.
     */
    #[Test]
    public function the_envelope_the_apps_decode_is_unchanged(): void
    {
        $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->masjid->id,
            'device_id' => 'install-envelope',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['status', 'data' => ['id', 'device_id']]);
    }

    /**
     * The date STRINGS, byte for byte — not just that the keys are there.
     *
     * Both apps decode `created_at` / `updated_at` as strings and the payload is
     * now hand-built, so the one assumption left to break silently is that the
     * projection still serializes a Carbon the way the model always did. A cast
     * added to `MobileAppUser`, or a `serializeDate` override on a base model,
     * would change the format with no test failing anywhere near it and no
     * visible error — the apps would simply start holding a string they cannot
     * parse. `APP_TIMEZONE` is UTC, so this is the exact string the shipped
     * builds have always received.
     */
    #[Test]
    public function the_date_strings_are_byte_for_byte_what_the_apps_have_always_received(): void
    {
        $device = $this->registerDevice('install-dates');
        // `created_at` survives a re-registration; `updated_at` is rewritten by
        // it, so only the format of that one can be pinned.
        $device->forceFill(['created_at' => '2026-01-02 03:04:05'])->save();

        $row = $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->masjid->id,
            'device_id' => 'install-dates',
        ])->assertOk()->json('data');

        $this->assertSame('2026-01-02T03:04:05.000000Z', $row['created_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $row['updated_at'],
            'the `updated_at` format changed; both apps decode it as a string'
        );
    }

    /**
     * THE CENSUS. Every column is on exactly one list, on purpose.
     *
     * Without this, PUBLISHED is a snapshot of September 2026 and the next
     * column somebody adds to `mobile_app_users` — a push token, an IDFA, a
     * location — reaches an anonymous caller by default. That is precisely how
     * `contact_id` and `onesignal_subscription_id` got onto the wire: each was
     * added to the table long after the endpoint was written, and nothing asked.
     *
     * IT HAS ALREADY FIRED ONCE, hours after being written. S1 (cd14175) added
     * `app_platform`, `app_version` and `app_build` to this table while this
     * branch sat waiting to deploy. The rebase produced no conflict — the two
     * changes touch different lines of the same controller — so nothing else in
     * the process would have said a word, and three new columns would have gone
     * onto an unauthenticated payload the moment this shipped. This test failed
     * instead, and somebody had to decide. That is the entire point of it:
     * a census does not ask whether you remembered, it asks whether you decided.
     */
    #[Test]
    public function every_mobile_app_users_column_is_deliberately_classified(): void
    {
        $columns = Schema::getColumnListing('mobile_app_users');

        $classified = array_merge(self::PUBLISHED, array_keys(self::WITHHELD));
        $unclassified = array_values(array_diff($columns, $classified));

        $this->assertSame(
            [],
            $unclassified,
            "These `mobile_app_users` columns are on neither list, so a change to the payload would publish them "
            ."to an unauthenticated caller by default:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nDecide for each one: add it to MobileDevicePayloadTest::PUBLISHED if a stranger holding a "
            ."device id may see it, or to ::WITHHELD (with a sentinel value) if not, and make "
            ."MobileAppUsersController::devicePayload agree. Do not delete this test."
        );
    }

    /**
     * The heartbeat answers a fixed acknowledgement and must keep doing so — it
     * is the one device endpoint that never returned a row, and the apps decode
     * `{status, data: {updated}}`.
     */
    #[Test]
    public function the_heartbeat_still_answers_a_bare_acknowledgement(): void
    {
        $this->registerDevice('install-heartbeat');

        $this->postJson('/api/mobile/user/heartbeat', ['device_id' => 'install-heartbeat'])
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'data' => ['updated' => true]]);
    }

    // MARK: - helpers

    private function assertExactlyPublished(mixed $row, string $where): void
    {
        $this->assertIsArray($row, "{$where} answered no object at all");

        $actual = array_keys($row);
        $expected = self::PUBLISHED;
        sort($actual);
        sort($expected);

        $this->assertSame(
            $expected,
            $actual,
            "The key set on {$where} is not the agreed one.\n"
            ."  unexpected: ".(implode(', ', array_diff($actual, $expected)) ?: '(none)')."\n"
            ."  missing:    ".(implode(', ', array_diff($expected, $actual)) ?: '(none)')."\n"
            ."An unexpected key is published to anyone holding a device id. A missing one may be "
            ."non-optional in a build already on phones — check the decoders before changing PUBLISHED."
        );
    }

    private function assertNoSentinelAnywhereIn(string $body, string $where): void
    {
        foreach (self::WITHHELD as $column => $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $body,
                "the value of `{$column}` reached an unauthenticated caller on {$where} "
                ."(under `{$column}` or under some other key — this check does not care which)"
            );
        }
    }

    private function plantEverythingWithheldOn(MobileAppUser $device): void
    {
        // The contact id has to BE the sentinel for a value-shaped search to
        // mean anything, so the row is inserted AT that id rather than renumbered
        // afterwards — `mobile_app_users.contact_id` is a real foreign key.
        $contact = new Contact();
        $contact->forceFill([
            'id' => (int) self::WITHHELD['contact_id'],
            'first_name' => 'Sentinel',
            'last_name' => 'Member',
            'masjid_id' => $this->masjid->id,
        ])->save();

        // Plant EVERY withheld column from the map, rather than a hand-listed
        // few: a column added to ::WITHHELD then gets its value guard for free,
        // instead of being silently unplanted and passing a check that never
        // ran. `contact_id` is the one that has to be an int, being a real key.
        $planted = self::WITHHELD;
        $planted['contact_id'] = (int) $planted['contact_id'];

        $device->forceFill($planted)->save();
    }

    private function registerDevice(string $deviceId): MobileAppUser
    {
        $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->masjid->id,
            'device_id' => $deviceId,
        ])->assertOk();

        return MobileAppUser::where('device_id', $deviceId)->firstOrFail();
    }

    private function makeMasjid(string $label): Masjid
    {
        return Masjid::create([
            'name' => "{$label} Masjid ".uniqid(),
            'email' => strtolower($label).'-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }
}
