<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\FormSubmissionsController;
use App\Mail\FormResponseSubmitted;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Support\FormStaffCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

/**
 * Cash at the gate, through the public form, on a staff member's own code
 * (DECISIONS.md 2026-09-11; festival brief, blocker 3 and the replay guard).
 *
 * What is pinned here, end to end through the HTTP layer a phone at the gate uses:
 *
 *  - a code, or the signed token it is exchanged for, settles a walk-up as cash its
 *    holder owes, in the same request, with no card leg and never a $0 session;
 *  - every way a credential can fail is one refusal, byte for byte, and writes
 *    nothing — junk codes cannot get round the public limit;
 *  - the failure limiter is per device and per form, never per venue: one attendee
 *    typing junk on the shared wifi cannot stop the staff phones, and a phone that
 *    has its token never meets the limiter at all;
 *  - twelve walk-ups from one connection in an hour all land; the per-code limit is
 *    keyed by the code's HMAC, never a bare hash;
 *  - a flood of junk codes from the venue wifi never stops a phone holding a verified
 *    token, nor another phone swapping its code for one; a request the flood guard
 *    turns away spends none of a holder's hourly allowance, and nor does a released
 *    phone's stale token;
 *  - the replay key is required wherever money moves, and nowhere else;
 *  - the code's plaintext is in no table, no log line, no admin list and no public
 *    answer, and the holder's name is in no public answer;
 *  - a double-tap records one entry, a changed replay is a 409, and a double-tap that
 *    races past the lookup is answered as a replay, never a 500;
 *  - the browser's own encoding (form-encoded) works.
 *
 * The anonymous path here is the Wix fallback's (card payment off): this task does
 * not open the card leg, so these tests do not lean on it.
 */
class FormStaffCodeTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'K7QM-2XWD';

    private const OTHER_CODE = 'M4PX-9TRV';

    private const PHONE = 'phone-najd-0001';

    private const OTHER_PHONE = 'phone-guest-0002';

    private const HOLDER = 'Najd Haddad';

    private const INVITE = 'AbCdEfGhIj0123456789';

    private const UNIFORM = ['status' => 'failed', 'data' => ['staff_code' => [FormStaffCodes::REFUSED]]];

    private Masjid $masjid;

    private Masjid $otherMasjid;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Mail::fake();

        $this->masjid = $this->makeMasjid();
        $this->otherMasjid = $this->makeMasjid();
        $this->form = $this->makeForm($this->masjid);
    }

    // ------------------------------------------------------------ settlement

    #[Test]
    public function a_code_records_a_walk_up_as_cash_its_holder_owes_with_no_card_leg(): void
    {
        $code = $this->issue();
        $token = $this->tokenFor();

        $response = $this->cash($token)->assertOk()
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.amount_due_minor', 3000)
            ->assertJsonPath('data.fee_covered_minor', 0)
            ->assertJsonPath('data.total_minor', 3000)
            ->assertJsonPath('data.currency', 'usd')
            ->assertJsonPath('data.whatsapp_url', 'https://chat.whatsapp.com/' . self::INVITE);

        $row = FormResponse::sole();
        $this->assertSame($row->uuid, $response->json('data.uuid'));
        $this->assertSame(FormResponse::METHOD_CASH, $row->payment_method);
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status);
        $this->assertNotNull($row->paid_at);
        $this->assertSame($code->id, $row->staff_code_id);
        $this->assertSame($row->amount_due_minor, $row->total_minor);
        $this->assertSame('30.00', (string) $row->amount_due, 'the legacy decimal stays what the schema computed');
        $this->assertSame(2, $row->entry_count);

        // No card leg of any kind — no session, no intent, no idempotency key — so
        // there is never a $0 session to open.
        $this->assertNull($row->stripe_checkout_session_id);
        $this->assertNull($row->stripe_payment_intent_id);
        $this->assertNull($row->idempotency_key);

        $code->refresh();
        $this->assertSame(1, $code->use_count);
        $this->assertNotNull($code->last_used_at);

        // The confirmation a walk-up may glimpse never names the holder.
        $this->assertStringNotContainsString(self::HOLDER, $response->getContent());
        $this->assertArrayNotHasKey('staff_code', $response->json('data'));
        $this->assertArrayNotHasKey('staff_code_id', $response->json('data'));
    }

    #[Test]
    public function the_code_itself_can_record_an_entry_and_claims_the_phone_doing_it(): void
    {
        $code = $this->issue();

        $this->submit(['staff_code' => 'k7qm 2xwd', 'device_id' => self::PHONE])->assertOk()
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertSame(self::PHONE, $code->fresh()->bound_device_id);
        $this->assertSame($code->id, FormResponse::sole()->staff_code_id);
    }

    #[Test]
    public function the_exchange_hands_back_a_signed_token_and_nothing_about_the_holder(): void
    {
        $code = $this->issue();

        $response = $this->exchange()->assertOk();
        $token = $response->json('data.staff_token');

        $this->assertMatchesRegularExpression('/^v1\.' . $this->form->id . '\.' . $code->id . '\.\d+\.[0-9a-f]{64}$/', $token);
        $this->assertSame(['expires_at', 'staff_token'], collect($response->json('data'))->keys()->sort()->values()->all());
        $this->assertStringNotContainsString(self::HOLDER, $response->getContent());

        // Twelve hours by default.
        $this->assertEqualsWithDelta(now()->addMinutes(720)->getTimestamp(), strtotime($response->json('data.expires_at')), 5);

        // The first phone now holds the code; the exchange itself records nothing.
        $this->assertSame(self::PHONE, $code->fresh()->bound_device_id);
        $this->assertSame(0, FormResponse::count());
        $this->assertSame(0, $code->fresh()->use_count);
    }

    #[Test]
    public function a_token_never_outlives_its_code(): void
    {
        $code = $this->issue(attributes: ['expires_at' => now()->addMinutes(90)]);

        $response = $this->exchange()->assertOk();
        $this->assertSame($code->fresh()->expires_at->getTimestamp(), strtotime($response->json('data.expires_at')));

        $token = $response->json('data.staff_token');
        $this->cash($token)->assertOk();

        $this->travel(91)->minutes();

        $this->assertRefused($this->cash($token), 'a token past its code\'s expiry');
        $this->assertSame(1, FormResponse::count());
    }

    #[Test]
    public function staff_entry_ignores_the_closing_date_but_not_an_inactive_or_full_form(): void
    {
        $this->issue();
        $token = $this->tokenFor();
        $this->form->update(['closes_at' => now()->subDay()]);

        // Online registration has closed…
        $this->submit()->assertStatus(422);
        // …the gate has not.
        $this->cash($token, ['client_submission_key' => 'after-close-1'])->assertOk();

        $this->form->update(['capacity' => $this->form->fresh()->response_count]);
        $this->cash($token, ['client_submission_key' => 'when-full-1'])->assertStatus(422)
            ->assertJsonPath('message', 'This form has reached capacity.');

        $this->form->update(['capacity' => null, 'is_active' => false]);
        $this->cash($token, ['client_submission_key' => 'inactive-1'])->assertStatus(422)
            ->assertJsonPath('message', 'This form is not currently accepting responses.');

        $this->assertSame(1, FormResponse::count());
    }

    // --------------------------------------------------------------- refusals

    #[Test]
    public function every_refused_code_is_refused_in_the_same_words_and_writes_nothing(): void
    {
        $otherForm = $this->makeForm($this->masjid);
        $foreignForm = $this->makeForm($this->otherMasjid);
        $switchedOff = $this->makeForm($this->masjid, [], ['payment' => ['staffCodes' => false]]);

        $revoked = FormStaffCode::generate();
        $expired = FormStaffCode::generate();
        $elsewhere = FormStaffCode::generate();
        $foreign = FormStaffCode::generate();
        $off = FormStaffCode::generate();

        $this->issue(plain: $revoked, attributes: ['revoked_at' => now()]);
        $this->issue(plain: $expired, attributes: ['expires_at' => now()->subMinute()]);
        $this->issue($otherForm, $elsewhere);
        $this->issue($foreignForm, $foreign);
        $this->issue($switchedOff, $off);
        $this->issue();
        $this->exchange()->assertOk(); // PHONE claims CODE

        $cases = [
            'unknown' => [$this->form, 'ZZZZ-ZZZZ'],
            'revoked' => [$this->form, $revoked],
            'expired' => [$this->form, $expired],
            'another form\'s' => [$this->form, $elsewhere],
            'another masjid\'s' => [$this->form, $foreign],
            'codes switched off' => [$switchedOff, $off],
            'bound to another phone' => [$this->form, self::CODE],
            'not a string' => [$this->form, [self::CODE]],
            'far too long' => [$this->form, str_repeat(self::CODE, 5)],
        ];

        $n = 0;

        foreach ($cases as $case => [$form, $code]) {
            // One device per case, never PHONE: no case trips the limiter for the next.
            $device = 'probe-' . ++$n;

            $this->assertRefused($this->exchange($code, $device, $form, $this->masjid->id), "exchange: {$case}");
            $this->assertRefused($this->submit(['staff_code' => $code, 'device_id' => $device], null, $form, $this->masjid->id), "submit: {$case}");
        }

        $this->assertSame(0, FormResponse::count());
        $this->assertSame(0, (int) FormStaffCode::withoutMasjidScope()->sum('use_count'));

        // A masjid that does not own the form is not told it exists.
        $this->exchange(masjidId: $this->otherMasjid->id)->assertNotFound();
    }

    #[Test]
    public function a_token_cannot_be_forged_moved_or_carried_to_another_phone_and_never_counts_as_a_wrong_code(): void
    {
        $this->issue();
        $otherForm = $this->makeForm($this->masjid);
        $this->issue($otherForm, self::OTHER_CODE);
        $token = $this->tokenFor();

        [$version, $formId, $codeId, $expiry, $signature] = explode('.', $token);

        $forgeries = [
            'a flipped signature' => implode('.', [$version, $formId, $codeId, $expiry, strrev($signature)]),
            'a later expiry' => implode('.', [$version, $formId, $codeId, (int) $expiry + 86400, $signature]),
            'another code' => implode('.', [$version, $formId, (int) $codeId + 1, $expiry, $signature]),
            'junk' => 'not-a-token',
        ];

        foreach ($forgeries as $case => $forged) {
            $this->assertRefused($this->cash($forged), $case);
        }

        $this->assertRefused($this->cash($token, [], null, $otherForm), 'a genuine token on another form');
        $this->assertRefused($this->cash($token, ['device_id' => self::OTHER_PHONE]), 'a genuine token on another phone');
        $this->assertRefused($this->submit(['staff_token' => $token]), 'a genuine token with no phone at all');

        $this->assertSame(0, FormResponse::count());

        // Five of those came from PHONE. Had any counted as a wrong code, PHONE would
        // now be locked out; a token never touches the failure buckets.
        $this->exchange()->assertOk();
    }

    #[Test]
    public function a_code_serves_one_phone_until_an_admin_releases_it(): void
    {
        $code = $this->issue();
        $token = $this->tokenFor();

        $this->assertRefused($this->exchange(device: self::OTHER_PHONE), 'a second phone');

        $code->fresh()->releaseDevice();

        $newToken = $this->tokenFor(device: self::OTHER_PHONE);
        $this->cash($newToken, ['device_id' => self::OTHER_PHONE])->assertOk();

        // The first phone's token went with its binding.
        $this->assertRefused($this->cash($token, ['client_submission_key' => 'old-phone-key']), 'the released phone');
        $this->assertSame(1, FormResponse::count());
    }

    #[Test]
    public function a_code_revoked_mid_session_stops_its_token_at_once(): void
    {
        $code = $this->issue();
        $token = $this->tokenFor();
        $this->cash($token, ['client_submission_key' => 'before-revoke'])->assertOk();

        $code->fresh()->revoke();

        $this->assertRefused($this->cash($token, ['client_submission_key' => 'after-revoke']), 'a revoked code\'s token');
        $this->assertSame(1, FormResponse::count());
        $this->assertSame(1, $code->fresh()->use_count);
    }

    #[Test]
    public function a_filled_honeypot_beside_a_staff_credential_is_refused_out_loud(): void
    {
        $this->issue();
        $token = $this->tokenFor();

        $this->cash($token, ['website' => 'http://spam.example'])->assertStatus(422)
            ->assertJsonPath('message', 'This entry was not recorded. Reload the page and try again.');
        $this->submit(['staff_code' => self::CODE, 'device_id' => self::PHONE, 'website' => 'x'])->assertStatus(422);

        // Without a credential it is still the silent discard it always was.
        $this->submit(['website' => 'http://spam.example'])->assertOk()->assertJsonPath('data.id', null);

        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_paying_form_that_computes_nothing_owed_is_refused_never_free(): void
    {
        // Stored directly: no admin door would save either form now (crossCheck).
        $noMinimum = $this->makeForm($this->masjid, ['schema' => $this->festivalSchema(minEntries: 0)]);
        $this->issue($noMinimum);
        $token = $this->tokenFor(form: $noMinimum);

        $empty = ['fullName' => 'Amal Yusuf', 'attendees' => []];

        $this->cash($token, [], $empty, $noMinimum)->assertStatus(422)
            ->assertJsonPath('data.attendees.0', 'Add at least one entry.');
        $this->submit([], $empty, $noMinimum)->assertStatus(422)
            ->assertJsonPath('data.attendees.0', 'Add at least one entry.');

        // A $0 price in force: an early-bird tier nobody should have saved.
        $freeTier = $this->makeForm($this->masjid, [], ['fee' => [
            'currency' => 'USD',
            'perEntryOfSection' => 'attendees',
            'tiers' => [
                ['label' => 'Early bird', 'amount' => 0, 'until' => now()->addWeek()->toDateString()],
                ['label' => 'Standard', 'amount' => 15],
            ],
        ]]);

        $this->submit([], null, $freeTier)->assertStatus(422)
            ->assertJsonPath('message', 'This form cannot take entries right now.');

        $this->assertSame(0, FormResponse::count());
    }

    // ---------------------------------------------------------------- limits

    #[Test]
    public function a_phone_that_keeps_getting_it_wrong_is_locked_out_and_its_neighbours_are_not(): void
    {
        $code = $this->issue();

        foreach (range(1, 5) as $attempt) {
            $this->assertRefused($this->exchange('ZZZZ-ZZZ' . $attempt), "wrong code {$attempt}");
        }

        // The sixth is turned away before any lookup — even with the right code, on
        // either door — and claims nothing.
        $this->exchange()->assertStatus(429)->assertHeader('Retry-After');
        $this->submit(['staff_code' => self::CODE, 'device_id' => self::PHONE])->assertStatus(429);
        $this->assertNull($code->fresh()->bound_device_id);
        $this->assertSame(0, FormResponse::count());

        // Another phone on the same wifi — same address, its own device — is not caught.
        $this->exchange(device: self::OTHER_PHONE)->assertOk();

        // And the lockout lifts by itself.
        $this->travel(16)->minutes();
        $this->assertRefused($this->exchange('ZZZZ-ZZZ9'), 'a wrong code once the window has passed');
    }

    #[Test]
    public function a_locked_out_phone_is_told_the_real_wait_on_both_doors(): void
    {
        $this->freezeTime();
        $this->issue();

        foreach (range(1, 5) as $attempt) {
            $this->assertRefused($this->exchange('ZZZZ-ZZZ' . $attempt), "wrong code {$attempt}");
        }

        // Fifteen minutes (forms.staff_code_failures.decay_minutes), in Retry-After and in
        // words, the way every other 429 says it: never "a few minutes".
        $locked = [
            'exchange' => $this->exchange(),
            'entry' => $this->submit(['staff_code' => self::CODE, 'device_id' => self::PHONE]),
        ];

        foreach ($locked as $door => $answer) {
            $answer->assertStatus(429)->assertHeader('Retry-After', '900');
            $this->assertSame('Too many staff code attempts. Please try again in 15 minutes.', $answer->json('message'), $door);
        }

        // And it counts down.
        $this->travel(11)->minutes();

        $this->exchange()->assertStatus(429)
            ->assertHeader('Retry-After', '240')
            ->assertJsonPath('message', 'Too many staff code attempts. Please try again in 4 minutes.');
    }

    #[Test]
    public function a_guesser_rotating_devices_meets_the_form_ceiling_which_an_admin_can_clear(): void
    {
        config(['forms.staff_code_failures.per_form' => 3]);
        Log::spy();

        $this->issue();
        $this->issue(plain: self::OTHER_CODE);
        $token = $this->tokenFor(); // a staff member already at work

        foreach (range(1, 3) as $n) {
            $this->assertRefused($this->exchange('ZZZZ-ZZZ' . $n, 'rotating-' . $n), "guess {$n}");
        }

        // Every phone that has not exchanged its code yet is held at the door…
        $this->exchange(self::OTHER_CODE, self::OTHER_PHONE)->assertStatus(429);

        // …while the one that has keeps recording cash.
        $this->cash($token)->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'locked out') && ($context['scope'] ?? null) === 'form')
            ->once();

        FormStaffCodes::clearLockout($this->form);

        $this->exchange(self::OTHER_CODE, self::OTHER_PHONE)->assertOk();
    }

    #[Test]
    public function twelve_cash_entries_from_one_connection_in_an_hour_all_land(): void
    {
        $this->issue();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);

        // Attendees on the venue wifi have used the public limit up…
        foreach (range(1, 8) as $n) {
            $this->submit(['device_id' => 'attendee-' . $n])->assertOk();
        }

        $this->submit(['device_id' => 'attendee-9'])->assertStatus(429);

        // …and the staff phone behind the same address records a walk-up every few minutes.
        $token = $this->tokenFor();

        foreach (range(1, 12) as $n) {
            $this->cash($token, ['client_submission_key' => 'walk-up-key-' . $n], $this->answers(1, "Walk-up {$n}"))
                ->assertOk();

            $this->travel(4)->minutes();
        }

        $this->assertSame(12, FormResponse::where('payment_method', FormResponse::METHOD_CASH)->count());
        $this->assertSame(12, FormStaffCode::sole()->use_count);
    }

    #[Test]
    public function the_public_limit_is_the_configured_one(): void
    {
        config(['forms.submit_per_hour' => 2]);

        $this->submit()->assertOk();
        $this->submit()->assertOk();
        $this->submit()->assertStatus(429);
    }

    #[Test]
    public function a_code_has_its_own_hourly_limit_keyed_by_its_hmac_however_it_is_presented(): void
    {
        config(['forms.code_per_hour' => 3]);
        $this->issue();
        $this->issue(plain: self::OTHER_CODE);

        $token = $this->tokenFor();

        foreach (range(1, 3) as $n) {
            $this->cash($token)->assertOk();
        }

        // The fourth is refused by token and by code alike: one bucket per code. The hour
        // runs from the first entry, and the answer says how much of it is left.
        $this->cash($token)->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many staff entries. Please try again in 60 minutes.');
        $this->submit(['staff_code' => self::CODE, 'device_id' => self::PHONE])->assertStatus(429);

        // Another holder's code is its own bucket.
        $other = $this->tokenFor(self::OTHER_CODE, self::OTHER_PHONE);
        $this->cash($other, ['device_id' => self::OTHER_PHONE])->assertOk();

        // Keyed on the code's HMAC digest — never a bare sha256 of it, which anyone
        // holding a copy of the cache could reverse.
        $store = Cache::store(config('cache.limiter'));
        $prefix = 'form-submit' . 'form-code:' . $this->form->id . '|';

        $this->assertTrue($store->has(md5($prefix . FormStaffCode::hashFor(self::CODE))));
        $this->assertFalse($store->has(md5($prefix . hash('sha256', FormStaffCode::normalise(self::CODE)))));
        $this->assertFalse($store->has(md5($prefix . hash('sha256', self::CODE))));
    }

    #[Test]
    public function junk_credentials_write_nothing_and_cannot_get_round_the_public_limit(): void
    {
        $code = $this->issue();
        config(['forms.submit_per_hour' => 1]);

        $this->submit()->assertOk();
        $this->submit()->assertStatus(429); // the public limit is spent

        $count = $this->form->fresh()->response_count;

        foreach (range(1, 12) as $n) {
            $status = $this->submit(['staff_code' => 'ZZZZ-ZZ' . sprintf('%02d', $n), 'device_id' => 'bot-' . $n])->status();
            $this->assertContains($status, [422, 429], "junk code {$n}");
        }

        foreach (range(1, 5) as $n) {
            $status = $this->submit(['staff_token' => 'v1.' . $this->form->id . '.1.9999999999.' . str_repeat('0', 64), 'device_id' => 'bot'])->status();
            $this->assertContains($status, [422, 429], "junk token {$n}");
        }

        $this->assertSame(1, FormResponse::count());
        $this->assertSame($count, $this->form->fresh()->response_count);
        $this->assertSame(0, $code->fresh()->use_count);
    }

    #[Test]
    public function a_flood_of_junk_codes_on_the_venue_wifi_never_stops_a_phone_holding_its_token(): void
    {
        config(['forms.code_per_hour' => 2]);
        $this->issue();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);

        $token = $this->tokenFor();

        // Someone else on the same wifi sprays made-up codes, a fresh one each time,
        // until the per-connection guard shuts…
        foreach (range(1, 30) as $n) {
            $this->assertRefused(
                $this->submit(['staff_code' => sprintf('ZZZZ-%04d', $n), 'device_id' => 'spray-' . $n]),
                "junk code {$n}"
            );
        }

        $this->submit(['staff_code' => 'ZZZZ-9999', 'device_id' => 'spray-31'])->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many staff entries. Please try again in a minute.');

        // …which turns away the holder's own code typed at the gate too, before it can
        // spend any of that code's hourly allowance…
        $this->submit(['staff_code' => self::CODE, 'device_id' => self::PHONE])->assertStatus(429);

        // …while the phone holding its token goes on recording walk-ups against that
        // allowance alone: two an hour here, and both are still there.
        $this->cash($token)->assertOk();
        $this->cash($token)->assertOk();
        $this->cash($token)->assertStatus(429);

        $this->assertSame(2, FormResponse::where('payment_method', FormResponse::METHOD_CASH)->count());
    }

    #[Test]
    public function a_flood_of_junk_codes_at_the_exchange_never_stops_another_phone_getting_its_token(): void
    {
        $this->issue();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);

        foreach (range(1, 30) as $n) {
            $this->assertRefused($this->exchange(sprintf('ZZZZ-%04d', $n), 'spray-' . $n), "junk code {$n}");
        }

        // The staff member arriving behind the same address still swaps their code.
        $this->exchange()->assertOk();

        // And each phone still has a flood guard of its own.
        foreach (range(2, 30) as $n) {
            $this->exchange()->assertOk();
        }

        $this->exchange()->assertStatus(429)->assertHeader('Retry-After');
    }

    #[Test]
    public function a_released_phones_stale_token_spends_nothing_of_its_holders_hour(): void
    {
        config(['forms.code_per_hour' => 2]);
        $code = $this->issue();
        $stale = $this->tokenFor();

        // The holder changed phones: an admin released the code, and the new phone claimed it.
        $code->fresh()->releaseDevice();
        $fresh = $this->tokenFor(device: self::OTHER_PHONE);

        // The old phone keeps retrying from a pocket somewhere.
        foreach (range(1, 3) as $n) {
            $this->assertRefused($this->cash($stale), "stale token {$n}");
        }

        // The new phone's allowance is all there.
        $this->cash($fresh, ['device_id' => self::OTHER_PHONE])->assertOk();
        $this->cash($fresh, ['device_id' => self::OTHER_PHONE])->assertOk();
        $this->cash($fresh, ['device_id' => self::OTHER_PHONE])->assertStatus(429);
    }

    #[Test]
    public function a_code_typed_on_phones_it_is_not_bound_to_spends_none_of_its_holders_hour(): void
    {
        config(['forms.code_per_hour' => 3]);
        $this->issue();
        $token = $this->tokenFor();

        // Someone who knows the code (read over a shoulder, or told it) types it on other
        // phones. Each is refused, as the binding means it to be…
        foreach (range(1, 3) as $n) {
            $this->assertRefused($this->submit(['staff_code' => self::CODE, 'device_id' => 'not-the-holder-' . $n]), "typed elsewhere {$n}");
        }

        // …and none of it came out of the holder's hour.
        foreach (range(1, 3) as $n) {
            $this->cash($token)->assertOk();
        }

        $this->cash($token)->assertStatus(429);

        // Without malice: the holder changed phones, an admin released the code, and the
        // old phone's page, asking for the code again, keeps retrying it.
        $other = $this->issue(plain: self::OTHER_CODE);
        $this->tokenFor(self::OTHER_CODE, 'old-phone');
        $other->fresh()->releaseDevice();
        $fresh = $this->tokenFor(self::OTHER_CODE, self::OTHER_PHONE);

        foreach (range(1, 3) as $n) {
            $this->assertRefused($this->submit(['staff_code' => self::OTHER_CODE, 'device_id' => 'old-phone']), "old phone {$n}");
        }

        foreach (range(1, 3) as $n) {
            $this->cash($fresh, ['device_id' => self::OTHER_PHONE])->assertOk();
        }

        $this->cash($fresh, ['device_id' => self::OTHER_PHONE])->assertStatus(429);
    }

    #[Test]
    public function a_refused_code_carries_the_same_limit_headers_whether_it_is_real_revoked_or_made_up(): void
    {
        config(['forms.code_per_hour' => 10]);
        $this->issue();
        $revoked = $this->issue(plain: self::OTHER_CODE);

        // Two holders, each five entries into their hour; then one of the codes is revoked.
        $token = $this->tokenFor();
        $otherToken = $this->tokenFor(self::OTHER_CODE, self::OTHER_PHONE);

        foreach (range(1, 5) as $n) {
            $this->cash($token)->assertOk();
            $this->cash($otherToken, ['device_id' => self::OTHER_PHONE])->assertOk();
        }

        $revoked->fresh()->revoke();

        $cases = [
            'made up' => 'ZZZZ-ZZZZ',
            'revoked' => self::OTHER_CODE,
            'bound to another phone' => self::CODE,
        ];

        $headers = [];
        $n = 0;

        foreach ($cases as $case => $code) {
            // Each from an address of its own, so the per-connection guard starts level.
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.' . ++$n]);
            $response = $this->submit(['staff_code' => $code, 'device_id' => 'probe-' . $n]);

            $this->assertRefused($response, $case);
            $headers[$case] = $this->limitHeaders($response);
        }

        $this->assertSame($headers['made up'], $headers['revoked'], 'a revoked code would be told from one that never existed');
        $this->assertSame($headers['made up'], $headers['bound to another phone'], 'a real code would be confirmed');
    }

    #[Test]
    public function while_codes_are_locked_out_the_limit_headers_say_nothing_about_the_code_typed(): void
    {
        config(['forms.code_per_hour' => 10, 'forms.staff_code_failures.per_form' => 3]);
        $code = $this->issue();
        $token = $this->tokenFor();

        foreach (range(1, 5) as $n) {
            $this->cash($token)->assertOk();
        }

        // The holder changed phones: an admin released the code, so the next phone to
        // present it claims it, and half its hour is spent.
        $code->fresh()->releaseDevice();

        // Three wrong guesses, from anywhere, lock the form's codes…
        foreach (range(1, 3) as $n) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.' . $n]);
            $this->assertRefused($this->submit(['staff_code' => sprintf('ZZZZ-%04d', $n), 'device_id' => 'guesser-' . $n]), "guess {$n}");
        }

        // …and while they are, the real code and a made-up one, each from a fresh address,
        // get the same answer down to the limit headers.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);
        $real = $this->submit(['staff_code' => self::CODE, 'device_id' => 'guesser-20'])->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21']);
        $junk = $this->submit(['staff_code' => 'ZZZZ-9999', 'device_id' => 'guesser-21'])->assertStatus(429);

        $this->assertSame($junk->json(), $real->json());
        $this->assertSame($this->limitHeaders($junk), $this->limitHeaders($real));
        $this->assertNull($code->fresh()->bound_device_id, 'nothing was claimed');
    }

    // ------------------------------------------------------------- the secret

    #[Test]
    public function the_code_is_never_stored_logged_or_published(): void
    {
        Log::spy();

        $admin = $this->makeAdmin();
        [$code, $plain] = FormStaffCode::issue($this->form, self::HOLDER, now()->addDay(), $admin);

        $normalised = FormStaffCode::normalise($plain);
        $spellings = array_values(array_unique([
            $plain,
            $normalised,
            strtolower($plain),
            strtolower($normalised),
            hash('sha256', $normalised), // a bare, reversible digest
        ]));

        $public = [];
        $exchange = $this->exchange($plain)->assertOk();
        $public['the exchange'] = $exchange->getContent();
        $public['a token entry'] = $this->cash($exchange->json('data.staff_token'))->assertOk()->getContent();
        $public['a code entry'] = $this->submit(['staff_code' => $plain, 'device_id' => self::PHONE])->assertOk()->getContent();
        $public['a second phone\'s refusal'] = $this->exchange($plain, self::OTHER_PHONE)->assertStatus(422)->getContent();
        $public['the page payload'] = $this->page($this->form)->getContent();

        foreach ($public as $where => $body) {
            $this->assertNowhereIn($body, [...$spellings, self::HOLDER], $where);
        }

        Sanctum::actingAs($admin);
        $index = $this->getJson("/api/admin/masjids/{$this->masjid->id}/forms/{$this->form->id}/responses")->assertOk();
        $this->assertNowhereIn($index->getContent(), $spellings, 'the admin responses list');

        foreach (Schema::getTables() as $table) {
            if (str_starts_with($table['name'], 'sqlite_')) {
                continue;
            }

            $rows = (string) json_encode(DB::table($table['name'])->get(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertNowhereIn($rows, $spellings, "table {$table['name']}");
        }

        // The keyed digest is what the table holds.
        $this->assertSame(FormStaffCode::hashFor($plain), DB::table('form_staff_codes')->where('id', $code->id)->value('code_hash'));

        // The second phone WAS logged — by the code's id — so the spy saw the path;
        // and no line at any level carries the code.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['staff_code_id'] ?? null) === $code->id)
            ->once();

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$args) => $this->mentionsAny($args, $spellings));
        }
    }

    // ---------------------------------------------------------- the group link

    #[Test]
    public function the_group_link_goes_only_to_a_settled_registration(): void
    {
        // Card payment off (the Wix fallback): a registration through the public form
        // owes $30 and has paid nothing here, so it gets no link — and the keys it
        // always got, no more.
        $unpaid = $this->submit()->assertOk();

        $this->assertStringNotContainsString(self::INVITE, $unpaid->getContent());
        $this->assertSame(
            ['amount_due', 'entry_count', 'id', 'success_body', 'success_next_steps', 'success_title'],
            collect($unpaid->json('data'))->keys()->sort()->values()->all()
        );

        // A form that charges nothing is settled on arrival.
        $free = $this->makeForm($this->masjid, [], ['fee' => null, 'payment' => null]);
        $this->submit([], null, $free)->assertOk()
            ->assertJsonPath('data.whatsapp_url', 'https://chat.whatsapp.com/' . self::INVITE)
            ->assertJsonMissingPath('data.uuid');
    }

    // ------------------------------------------------------------ replay guard

    #[Test]
    public function a_double_tap_records_one_entry_and_a_changed_replay_is_refused(): void
    {
        $code = $this->issue();
        $this->issue(plain: self::OTHER_CODE);
        $token = $this->tokenFor();
        $key = ['client_submission_key' => '0f8e7d6c-5b4a-4321-9876-abcdef012345'];

        $first = $this->cash($token, $key)->assertOk();
        $again = $this->cash($token, $key)->assertOk();

        $this->assertSame($first->json('data'), $again->json('data'), 'the same answer, from the same row');
        $this->assertSame(1, FormResponse::count());
        $this->assertSame(1, $code->fresh()->use_count, 'a holder never owes twice for one walk-up');
        $this->assertCount(1, Mail::queued(FormResponseSubmitted::class)->merge(Mail::sent(FormResponseSubmitted::class)));

        // Edited after a lost response: the same key with other answers.
        $this->cash($token, $key, $this->answers(3))->assertStatus(409);
        // The same answers without the holder's credential are not the same submission…
        $this->submit($key)->assertStatus(409);
        // …nor are they under another holder's code.
        $other = $this->tokenFor(self::OTHER_CODE, self::OTHER_PHONE);
        $this->cash($other, $key + ['device_id' => self::OTHER_PHONE])->assertStatus(409);

        $this->assertSame(1, FormResponse::count());
        $this->assertSame(3000, FormResponse::sole()->total_minor);

        // A key the renderer could not have minted is refused before anything runs.
        $this->submit(['client_submission_key' => 'bad key!'])->assertStatus(422)
            ->assertJsonPath('data.client_submission_key.0', 'This page is out of date. Reload it and try again.');
    }

    #[Test]
    public function a_double_tap_that_races_past_the_lookup_is_answered_as_a_replay_never_a_500(): void
    {
        // Opens the window between the replay lookup and the insert: the second tap's
        // lookup runs before the first tap's row is visible to it, so the unique index
        // is what catches it.
        $gate = new stdClass();
        $gate->misses = 0;

        $this->app->bind(FormSubmissionsController::class, fn () => new class($gate) extends FormSubmissionsController {
            public function __construct(private stdClass $gate)
            {
            }

            protected function earlierSubmission(int $formId, string $clientKey): ?FormResponse
            {
                if ($this->gate->misses > 0) {
                    $this->gate->misses--;

                    return null;
                }

                return parent::earlierSubmission($formId, $clientKey);
            }
        });

        $code = $this->issue();
        $token = $this->tokenFor();
        $key = ['client_submission_key' => 'raced-double-tap-key'];

        $first = $this->cash($token, $key)->assertOk();

        $gate->misses = 1;
        $second = $this->cash($token, $key)->assertOk();

        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertSame(1, FormResponse::count());
        $this->assertSame(1, $code->fresh()->use_count);
        $this->assertSame(1, $this->form->fresh()->response_count);

        // Raced with other answers: caught by the same index, answered 409.
        $gate->misses = 1;
        $this->cash($token, $key, $this->answers(3))->assertStatus(409);
        $this->assertSame(1, FormResponse::count());
    }

    #[Test]
    public function wherever_money_moves_the_replay_key_is_required(): void
    {
        $code = $this->issue();
        $token = $this->tokenFor();
        $noKey = ['client_submission_key' => null];
        $outOfDate = 'This page is out of date. Reload it and try again.';

        // A double-tapped cash entry from a page that sent no key: neither tap lands, so
        // the holder cannot come to owe twice for one walk-up.
        foreach ([1, 2] as $tap) {
            $this->cash($token, $noKey)->assertStatus(422)
                ->assertJsonPath('data.client_submission_key.0', $outOfDate);
        }

        // The same for the code typed at the gate, and for anyone registering on a form
        // that takes payment.
        $this->submit(['staff_code' => self::CODE, 'device_id' => self::PHONE] + $noKey)->assertStatus(422)
            ->assertJsonPath('data.client_submission_key.0', $outOfDate);
        $this->submit($noKey)->assertStatus(422)
            ->assertJsonPath('data.client_submission_key.0', $outOfDate);

        $this->assertSame(0, FormResponse::count());
        $this->assertSame(0, $code->fresh()->use_count);

        // A form that takes no payment is exactly as it always was.
        $free = $this->makeForm($this->masjid, [], ['fee' => null, 'payment' => null]);

        $this->submit($noKey, null, $free)->assertOk();
        $this->assertSame(1, FormResponse::count());
    }

    // ------------------------------------------------------------- transport

    #[Test]
    public function the_gate_works_form_encoded_as_a_browser_posts_it(): void
    {
        $code = $this->issue();
        $headers = ['masjid-id' => (string) $this->masjid->id, 'Accept' => 'application/json'];

        $token = $this->post("/api/v1/forms/{$this->form->id}/staff-session", [
            'staff_code' => 'k7qm-2xwd',
            'device_id' => self::PHONE,
        ], $headers)->assertOk()->json('data.staff_token');

        $this->post("/api/v1/forms/{$this->form->id}/responses", [
            'data' => [
                'fullName' => 'Amal Yusuf',
                'email' => '',
                'attendees' => [['attendeeName' => 'Amal'], ['attendeeName' => 'Zaid']],
            ],
            'staff_token' => $token,
            'device_id' => self::PHONE,
            'client_submission_key' => 'form-encoded-key-1',
            'website' => '',
        ], $headers)->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.total_minor', 3000);

        $row = FormResponse::sole();
        $this->assertSame($code->id, $row->staff_code_id);
        $this->assertSame(FormResponse::METHOD_CASH, $row->payment_method);

        // An attendee list emptied before posting cannot even be expressed
        // form-encoded; either way nothing is written.
        $this->post("/api/v1/forms/{$this->form->id}/responses", [
            'data' => ['fullName' => 'Amal Yusuf'],
            'staff_token' => $token,
            'device_id' => self::PHONE,
            'client_submission_key' => 'form-encoded-key-2',
        ], $headers)->assertStatus(422);

        $this->assertSame(1, FormResponse::count());
    }

    // ---------------------------------------------------------------- helpers

    private function assertRefused(TestResponse $response, string $case): void
    {
        $this->assertSame(422, $response->status(), "{$case}: " . $response->getContent());
        $this->assertSame(self::UNIFORM, $response->json(), $case);
    }

    /** @return array{0: ?string, 1: ?string} the rate-limit headers the throttle added */
    private function limitHeaders(TestResponse $response): array
    {
        return [$response->headers->get('X-RateLimit-Limit'), $response->headers->get('X-RateLimit-Remaining')];
    }

    /** @param  array<int,string>  $needles */
    private function assertNowhereIn(string $haystack, array $needles, string $where): void
    {
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $haystack, "found in {$where}");
        }
    }

    /**
     * @param  array<int,mixed>  $args
     * @param  array<int,string>  $needles
     */
    private function mentionsAny(array $args, array $needles): bool
    {
        $text = (string) json_encode($args, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function issue(?Form $form = null, string $plain = self::CODE, array $attributes = []): FormStaffCode
    {
        $form ??= $this->form;

        return FormStaffCode::factory()->withCode($plain)->create(array_merge([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'holder_name' => self::HOLDER,
            'expires_at' => now()->addDay(),
        ], $attributes));
    }

    private function exchange(mixed $code = self::CODE, string $device = self::PHONE, ?Form $form = null, ?int $masjidId = null): TestResponse
    {
        $form ??= $this->form;

        return $this->postJson("/api/v1/forms/{$form->id}/staff-session", [
            'staff_code' => $code,
            'device_id' => $device,
        ], ['masjid-id' => (string) ($masjidId ?? $form->masjid_id)]);
    }

    private function tokenFor(string $code = self::CODE, string $device = self::PHONE, ?Form $form = null): string
    {
        return $this->exchange($code, $device, $form)->assertOk()->json('data.staff_token');
    }

    /** @param  array<string,mixed>  $extra  a credential, device_id, client key, honeypot… (a fresh key per call unless one is given) */
    private function submit(array $extra = [], ?array $data = null, ?Form $form = null, ?int $masjidId = null): TestResponse
    {
        $form ??= $this->form;

        return $this->postJson("/api/v1/forms/{$form->id}/responses", array_merge([
            'data' => $data ?? $this->answers(),
            // One per render, as the renderer sends it: required wherever money moves.
            'client_submission_key' => (string) Str::uuid(),
        ], $extra), ['masjid-id' => (string) ($masjidId ?? $form->masjid_id)]);
    }

    /** A cash entry from PHONE with its token. */
    private function cash(string $token, array $extra = [], ?array $data = null, ?Form $form = null): TestResponse
    {
        return $this->submit(array_merge(['staff_token' => $token, 'device_id' => self::PHONE], $extra), $data, $form);
    }

    private function answers(int $attendees = 2, string $name = 'Amal Yusuf'): array
    {
        $rows = [];

        for ($i = 1; $i <= $attendees; $i++) {
            $rows[] = ['attendeeName' => "Guest {$i}"];
        }

        return ['fullName' => $name, 'email' => 'amal@example.com', 'attendees' => $rows];
    }

    /** The festival's shape: you, then the attendees the $15 is charged per entry of. */
    private function festivalSchema(int $minEntries = 1): array
    {
        return ['sections' => [
            ['id' => 'contact', 'title' => 'You', 'fields' => [
                ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
            ]],
            ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => $minEntries, 'maxEntries' => 10, 'fields' => [
                ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ]],
        ]];
    }

    /** $15 per attendee, staff codes on, card payment off, a group link for the settled. */
    private function makeForm(Masjid $masjid, array $overrides = [], array $settings = []): Form
    {
        return Form::create(array_merge([
            'masjid_id' => $masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => $this->festivalSchema(),
            'settings' => array_replace([
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'notifyEmails' => ['festival-office@mec.test'],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['staffCodes' => true],
                'whatsappUrl' => 'https://chat.whatsapp.com/' . self::INVITE,
                'whatsappLabel' => 'Join the festival group',
            ], $settings),
            'is_active' => true,
        ], $overrides));
    }

    /** The public page carrying one `form` section for $form, as the site reads it. */
    private function page(Form $form): TestResponse
    {
        $page = Page::create([
            'masjid_id' => $form->masjid_id,
            'slug' => 'register-' . uniqid(),
            'title' => 'Register',
            'is_active' => true,
            'order' => 1,
        ]);

        $section = Section::create([
            'masjid_id' => $form->masjid_id,
            'section_type' => 'form',
            'title' => 'Registration',
            'content' => ['form_id' => $form->id],
            'is_active' => true,
        ]);

        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        return $this->withHeader('masjid-id', (string) $form->masjid_id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->assertOk();
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }
}
