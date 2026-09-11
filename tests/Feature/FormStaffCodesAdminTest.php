<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin side of the festival's per-staff cash codes (DECISIONS.md 2026-09-11): the
 * "Staff codes" panel's API.
 *
 *  - the plaintext is in the 201 that issued it and nowhere after: not the list, not the
 *    table, and never the digest or the device a code is bound to;
 *  - every code expires at an explicit instant stated with its timezone — by default
 *    midnight after the form's named event day on the MASJID's clock (never guessed: a
 *    form with no event day issues no code without an expiry), and on America/New_York
 *    when the masjid never set one (the column default is 'UTC', whose midnight is
 *    8 PM Eastern, mid-festival);
 *  - releasing a code from its phone is recorded: who, when, and how many phones;
 *  - revoke keeps the row, which is the cash's record, and records the first press;
 *  - reset-device and clear-lockout reopen the gate, proved end to end through the public
 *    exchange a phone uses;
 *  - another masjid's form or code is a 404 on every route, another masjid in the URL a
 *    403, and no login a 401.
 */
class FormStaffCodesAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'phone-najd-0001';

    private const OTHER_PHONE = 'phone-new-0002';

    private Masjid $masjid;

    private Masjid $otherMasjid;

    private Form $form;

    private Form $otherForm;

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

        $this->masjid = $this->makeMasjid('America/Chicago');
        $this->otherMasjid = $this->makeMasjid('America/New_York');
        $this->form = $this->makeForm($this->masjid);
        $this->otherForm = $this->makeForm($this->otherMasjid);
    }

    // ------------------------------------------------------------ the secret

    #[Test]
    public function a_code_is_shown_once_in_the_answer_that_issued_it_and_never_again(): void
    {
        $admin = $this->actingAsAdmin();

        $issued = $this->postJson($this->url(), ['holder_name' => 'Najd Haddad'])
            ->assertCreated()
            ->assertJsonPath('data.holder_name', 'Najd Haddad')
            ->assertJsonPath('data.created_by.id', $admin->id)
            ->assertJsonPath('data.use_count', 0)
            ->assertJsonPath('data.device_bound', false)
            ->assertJsonPath('data.usable', true);

        $this->assertStringContainsString('no-store', (string) $issued->headers->get('Cache-Control'));

        $plain = (string) $issued->json('data.code');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $plain);
        $this->assertSame(FormStaffCode::hintFor($plain), $issued->json('data.code_hint'));

        $code = FormStaffCode::withoutMasjidScope()->sole();
        $this->assertSame(FormStaffCode::hashFor($plain), $code->code_hash);
        $this->assertSame($this->masjid->id, (int) $code->masjid_id);
        $this->assertArrayNotHasKey('code_hash', $issued->json('data'));
        $this->assertStringNotContainsString($code->code_hash, $issued->getContent());

        $normalised = FormStaffCode::normalise($plain);
        $spellings = array_values(array_unique([$plain, $normalised, strtolower($plain), strtolower($normalised)]));

        $list = $this->getJson($this->url())->assertOk()->assertJsonPath('data.0.holder_name', 'Najd Haddad');
        $this->assertArrayNotHasKey('code', $list->json('data.0'));
        $this->assertArrayNotHasKey('code_hash', $list->json('data.0'));

        foreach ([...$spellings, $code->code_hash] as $secret) {
            $this->assertStringNotContainsString($secret, $list->getContent());
        }

        // The table holds the keyed digest, and nothing a person could type.
        $table = (string) json_encode(DB::table('form_staff_codes')->get());

        foreach ($spellings as $secret) {
            $this->assertStringNotContainsString($secret, $table);
        }
    }

    // ---------------------------------------------------------------- expiry

    #[Test]
    public function by_default_a_code_expires_at_midnight_after_the_forms_event_day_on_the_masjids_clock(): void
    {
        $this->actingAsAdmin();

        // The festival is Saturday 17 October, and online sales close the night before at
        // 23:59 in Chicago. Codes are added on the Monday.
        $this->travelTo(Carbon::parse('2026-10-12 16:00:00', 'UTC'));
        $this->setEventDay('2026-10-17');
        $this->form->update(['closes_at' => Carbon::parse('2026-10-16 23:59:00', 'America/Chicago')]);

        // Online sales ending do not end the gate's codes with them.
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('meta.event_date', '2026-10-17')
            ->assertJsonPath('meta.default_expires_at', '2026-10-18T00:00:00-05:00')
            ->assertJsonPath('meta.timezone', 'America/Chicago')
            ->assertJsonPath('meta.staff_codes_enabled', true);

        $this->postJson($this->url(), ['holder_name' => 'Najd Haddad'])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', '2026-10-18T00:00:00-05:00')
            ->assertJsonPath('data.timezone', 'America/Chicago')
            ->assertJsonPath('data.timezone_assumed', false);

        // The instant stored is the one meant, in the app's own timezone.
        $this->assertSame('2026-10-18 05:00:00', (string) DB::table('form_staff_codes')->value('expires_at'));

        // Codes handed out the evening before, with no closing date at all: still the end
        // of the festival, not of "today".
        $this->form->update(['closes_at' => null]);
        $this->travelTo(Carbon::parse('2026-10-16 20:30:00', 'America/Chicago'));

        $this->postJson($this->url(), ['holder_name' => 'Amal Yusuf'])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', '2026-10-18T00:00:00-05:00');

        // A rehearsal form whose event day is today is good to the end of today.
        $this->setEventDay('2026-10-16');

        $this->postJson($this->url(), ['holder_name' => 'Rehearsal'])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', '2026-10-17T00:00:00-05:00');
    }

    #[Test]
    public function a_form_with_no_event_day_issues_no_code_without_an_explicit_expiry(): void
    {
        $this->actingAsAdmin();
        $this->travelTo(Carbon::parse('2026-10-12 16:00:00', 'UTC'));
        $this->setEventDay(null);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('meta.event_date', null)
            ->assertJsonPath('meta.default_expires_at', null);

        $this->postJson($this->url(), ['holder_name' => 'Najd Haddad'])
            ->assertStatus(422)
            ->assertJsonPath('data.expires_at.0', 'Set the event date on this form, or give this code an expiry.');

        // An event day already past is not quietly moved on to today.
        $this->setEventDay('2026-10-10');

        $this->postJson($this->url(), ['holder_name' => 'Najd Haddad'])
            ->assertStatus(422)
            ->assertJsonPath('data.expires_at.0', "This form's event date has passed. Give this code an expiry, or change the event date.");

        $this->assertSame(0, FormStaffCode::withoutMasjidScope()->count());

        // Naming the day on the code itself works as it always did.
        $this->postJson($this->url(), ['holder_name' => 'Najd Haddad', 'expires_at' => '2026-10-17'])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', '2026-10-18T00:00:00-05:00');
    }

    #[Test]
    public function a_masjid_that_never_set_its_timezone_is_read_on_new_york_time_and_the_answer_says_so(): void
    {
        $this->actingAsAdmin();

        // The column's default, i.e. never set.
        $this->masjid->forceFill(['timezone' => 'UTC'])->save();
        $this->travelTo(Carbon::parse('2026-10-12 16:00:00', 'UTC'));

        $this->postJson($this->url(), ['holder_name' => 'Najd Haddad', 'expires_at' => '2026-10-17'])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', '2026-10-18T00:00:00-04:00')
            ->assertJsonPath('data.timezone', 'America/New_York')
            ->assertJsonPath('data.timezone_assumed', true);

        // Midnight Eastern is 04:00 UTC. A UTC midnight would be 8 PM Eastern on the
        // festival night, with the gate still open.
        $this->assertSame('2026-10-18 04:00:00', (string) DB::table('form_staff_codes')->value('expires_at'));

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('meta.timezone', 'America/New_York')
            ->assertJsonPath('meta.timezone_assumed', true)
            ->assertJsonPath('data.0.timezone_assumed', true);

        // A name PHP does not know is no better than none.
        $this->masjid->forceFill(['timezone' => 'Mars/Olympus_Mons'])->save();

        $this->getJson($this->url())->assertOk()->assertJsonPath('meta.timezone', 'America/New_York');
    }

    #[Test]
    public function an_admin_may_name_the_day_or_the_instant_but_never_one_already_past(): void
    {
        $this->actingAsAdmin();
        $this->travelTo(Carbon::parse('2026-10-12 16:00:00', 'UTC'));

        // A day: good to the end of it, on the masjid's (Chicago) clock.
        $this->postJson($this->url(), ['holder_name' => 'A', 'expires_at' => '2026-10-17'])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', '2026-10-18T00:00:00-05:00');

        // A date and time with no offset: read on the masjid's clock.
        $this->postJson($this->url(), ['holder_name' => 'B', 'expires_at' => '2026-10-17 21:30'])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', '2026-10-17T21:30:00-05:00');

        // One with its own offset is taken as it stands.
        $this->postJson($this->url(), ['holder_name' => 'C', 'expires_at' => '2026-10-18T03:00:00Z'])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', '2026-10-17T22:00:00-05:00');

        $issued = FormStaffCode::withoutMasjidScope()->count();

        // Yesterday, earlier today, and something that is not a date: refused, nothing written.
        $this->postJson($this->url(), ['holder_name' => 'D', 'expires_at' => '2026-10-11'])
            ->assertStatus(422)
            ->assertJsonPath('data.expires_at.0', 'The expiry must be in the future.');

        $this->postJson($this->url(), ['holder_name' => 'E', 'expires_at' => '2026-10-12 08:00'])
            ->assertStatus(422)
            ->assertJsonPath('data.expires_at.0', 'The expiry must be in the future.');

        $junk = $this->postJson($this->url(), ['holder_name' => 'F', 'expires_at' => 'next festival'])->assertStatus(422);
        $this->assertArrayHasKey('expires_at', $junk->json('data'));

        $this->assertSame($issued, FormStaffCode::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_holder_name_is_required_and_at_most_120_characters_even_form_encoded(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url(), [])->assertStatus(422);
        $this->postJson($this->url(), ['holder_name' => '   '])->assertStatus(422);
        $this->postJson($this->url(), ['holder_name' => str_repeat('n', 121)])->assertStatus(422);

        $this->assertSame(0, FormStaffCode::withoutMasjidScope()->count());

        // The SPA posts form-encoded.
        $this->post($this->url(), ['holder_name' => str_repeat('n', 120), 'expires_at' => '2099-01-01'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.holder_name', str_repeat('n', 120));
    }

    // ------------------------------------------------------------ lifecycle

    #[Test]
    public function revoking_keeps_the_row_and_records_the_first_press_only(): void
    {
        $first = $this->actingAsAdmin();
        $id = $this->postJson($this->url(), ['holder_name' => 'Najd Haddad'])->assertCreated()->json('data.id');

        $this->deleteJson($this->url("/{$id}"))
            ->assertOk()
            ->assertJsonPath('message', 'The code has been revoked.')
            ->assertJsonPath('data.usable', false)
            ->assertJsonPath('data.revoked_by.id', $first->id);

        $this->actingAsAdmin();

        $this->deleteJson($this->url("/{$id}"))
            ->assertOk()
            ->assertJsonPath('message', 'This code was already revoked.')
            ->assertJsonPath('data.revoked_by.id', $first->id);

        $code = FormStaffCode::withoutMasjidScope()->findOrFail($id);
        $this->assertNotNull($code->revoked_at);
        $this->assertSame($first->id, $code->revoked_by_user_id);
        $this->assertSame(1, FormStaffCode::withoutMasjidScope()->count(), 'a code is revoked, never deleted');

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.usable', false);
    }

    #[Test]
    public function reset_device_lets_the_next_phone_claim_the_code_and_retires_the_old_phones_token(): void
    {
        $plain = $this->issueThroughTheApi();
        $code = FormStaffCode::withoutMasjidScope()->sole();

        // Nothing to release yet, so nothing is recorded.
        $this->postJson($this->url("/{$code->id}/reset-device"))
            ->assertOk()
            ->assertJsonPath('message', 'No phone has claimed this code yet.')
            ->assertJsonPath('data.binding_count', 0)
            ->assertJsonPath('data.binding_released_at', null)
            ->assertJsonPath('data.binding_released_by', null);

        $oldToken = $this->exchange($plain, self::PHONE)->assertOk()->json('data.staff_token');

        // The code belongs to the first phone that used it. The panel says which phone by
        // its last four characters, never by the whole id.
        $this->exchange($plain, self::OTHER_PHONE)->assertStatus(422);
        $bound = $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.device_bound', true)
            ->assertJsonPath('data.0.bound_device_hint', substr(self::PHONE, -4));
        $this->assertNotNull($bound->json('data.0.bound_at'));
        $this->assertStringNotContainsString(self::PHONE, $bound->getContent());

        // Released by another admin than the one who issued it: the release carries ITS name.
        $releaser = $this->actingAsAdmin();

        $reset = $this->postJson($this->url("/{$code->id}/reset-device"))
            ->assertOk()
            ->assertJsonPath('data.device_bound', false)
            ->assertJsonPath('data.bound_device_hint', null)
            ->assertJsonPath('data.bound_at', null)
            ->assertJsonPath('data.binding_count', 1)
            ->assertJsonPath('data.binding_released_by.id', $releaser->id);

        $this->assertNotNull($reset->json('data.binding_released_at'));

        // Whether it is bound, never which phone.
        $this->assertStringNotContainsString(self::PHONE, $reset->getContent());
        $this->assertNull($code->fresh()->bound_device_id);

        $this->exchange($plain, self::OTHER_PHONE)->assertOk();

        // The old phone's token went with its binding.
        $this->cash($oldToken, self::PHONE)->assertStatus(422);
        $this->assertSame(0, FormResponse::count());

        // Two phones have held it, and the panel says who freed it for the second.
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.binding_count', 2)
            ->assertJsonPath('data.0.binding_released_by.id', $releaser->id);
    }

    #[Test]
    public function clear_lockout_reopens_the_gate_for_every_phone_on_the_form(): void
    {
        config(['forms.staff_code_failures.per_form' => 3]);

        $plain = $this->issueThroughTheApi();

        foreach (range(1, 3) as $n) {
            $this->exchange('ZZZZ-ZZZ' . $n, 'guesser-' . $n)->assertStatus(422);
        }

        // The form-wide ceiling holds every phone at the door, the right code included.
        $this->exchange($plain, self::PHONE)->assertStatus(429);

        $this->postJson($this->url('/clear-lockout'))
            ->assertOk()
            ->assertJsonPath('message', 'Staff code lockouts on this form have been cleared.');

        $this->exchange($plain, self::PHONE)->assertOk();
    }

    #[Test]
    public function the_list_puts_each_codes_uses_and_cash_beside_it(): void
    {
        [$najd] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());
        FormStaffCode::issue($this->form, 'Amal Yusuf', now()->addDay());

        $this->cashRow($najd, 2);
        $this->cashRow($najd, 1)->forceFill(['status' => FormResponse::STATUS_CANCELLED])->save();

        $this->actingAsAdmin();
        $rows = collect($this->getJson($this->url())->assertOk()->json('data'))->keyBy('holder_name');

        // Two uses; one kept ($30.00 for two people), one taken and then cancelled.
        $this->assertSame(2, $rows['Najd Haddad']['use_count']);
        $this->assertSame(1, $rows['Najd Haddad']['submissions']);
        $this->assertSame(2, $rows['Najd Haddad']['people']);
        $this->assertSame(3000, $rows['Najd Haddad']['cash_minor']);
        $this->assertSame(1, $rows['Najd Haddad']['cancelled_submissions']);
        $this->assertSame(1500, $rows['Najd Haddad']['cancelled_cash_minor']);

        $this->assertSame(0, $rows['Amal Yusuf']['use_count']);
        $this->assertSame(0, $rows['Amal Yusuf']['cash_minor']);
    }

    // --------------------------------------------------------------- tenancy

    #[Test]
    public function another_masjids_form_or_code_is_a_404_on_every_route_and_is_left_alone(): void
    {
        // Written before any request binds a tenant.
        [$theirs] = FormStaffCode::issue($this->otherForm, 'Bilal Khan', now()->addDay());
        $theirs->bindToDevice('their-phone');
        [$ours] = FormStaffCode::issue($this->form, 'Najd Haddad', now()->addDay());

        $this->actingAsAdmin();

        // Our masjid in the path, their form.
        $foreign = "/api/admin/masjids/{$this->masjid->id}/forms/{$this->otherForm->id}/staff-codes";
        $this->getJson($foreign)->assertNotFound();
        $this->postJson($foreign, ['holder_name' => 'Planted'])->assertNotFound();
        $this->postJson("{$foreign}/clear-lockout")->assertNotFound();
        $this->postJson("{$foreign}/{$theirs->id}/reset-device")->assertNotFound();
        $this->deleteJson("{$foreign}/{$theirs->id}")->assertNotFound();

        // Our masjid and our form in the path, their code.
        $this->postJson($this->url("/{$theirs->id}/reset-device"))->assertNotFound();
        $this->deleteJson($this->url("/{$theirs->id}"))->assertNotFound();

        // Our list shows ours alone.
        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ours->id);

        $theirs = FormStaffCode::withoutMasjidScope()->findOrFail($theirs->id);
        $this->assertNull($theirs->revoked_at);
        $this->assertSame('their-phone', $theirs->bound_device_id);
        $this->assertSame(0, FormStaffCode::withoutMasjidScope()->where('holder_name', 'Planted')->count());
    }

    #[Test]
    public function another_masjids_admin_is_refused_and_nobody_signed_out_gets_in(): void
    {
        [$theirs] = FormStaffCode::issue($this->otherForm, 'Bilal Khan', now()->addDay());
        $theirPanel = "/api/admin/masjids/{$this->otherMasjid->id}/forms/{$this->otherForm->id}/staff-codes";

        $this->getJson($theirPanel)->assertUnauthorized();
        $this->postJson($theirPanel, ['holder_name' => 'Planted'])->assertUnauthorized();
        $this->deleteJson("{$theirPanel}/{$theirs->id}")->assertUnauthorized();

        Sanctum::actingAs($this->makeAdminFor($this->masjid));

        $this->getJson($theirPanel)->assertForbidden();
        $this->postJson($theirPanel, ['holder_name' => 'Planted'])->assertForbidden();
        $this->postJson("{$theirPanel}/clear-lockout")->assertForbidden();
        $this->postJson("{$theirPanel}/{$theirs->id}/reset-device")->assertForbidden();
        $this->deleteJson("{$theirPanel}/{$theirs->id}")->assertForbidden();

        $this->assertNull(FormStaffCode::withoutMasjidScope()->findOrFail($theirs->id)->revoked_at);
        $this->assertSame(1, FormStaffCode::withoutMasjidScope()->count());

        // Their own masjid's panel opens: the 403 is the tenant, not the account type.
        $this->getJson($this->url())->assertOk();
    }

    // ---------------------------------------------------------------- helpers

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/forms/{$this->form->id}/staff-codes{$suffix}";
    }

    private function issueThroughTheApi(string $holder = 'Najd Haddad'): string
    {
        $this->actingAsAdmin();

        return (string) $this->postJson($this->url(), ['holder_name' => $holder])->assertCreated()->json('data.code');
    }

    /** The public exchange a staff phone makes with its code. */
    private function exchange(string $code, string $device): TestResponse
    {
        return $this->postJson("/api/v1/forms/{$this->form->id}/staff-session", [
            'staff_code' => $code,
            'device_id' => $device,
        ], ['masjid-id' => (string) $this->masjid->id]);
    }

    /** A cash entry at the gate with a staff token. */
    private function cash(string $token, string $device): TestResponse
    {
        return $this->postJson("/api/v1/forms/{$this->form->id}/responses", [
            'data' => ['fullName' => 'Amal Yusuf', 'attendees' => [['attendeeName' => 'Guest 1']]],
            'staff_token' => $token,
            'device_id' => $device,
            'client_submission_key' => (string) Str::uuid(),
        ], ['masjid-id' => (string) $this->masjid->id]);
    }

    /** A walk-up settled as cash on $code, as the gate writes it: $15 per attendee. */
    private function cashRow(FormStaffCode $code, int $attendees): FormResponse
    {
        $row = new FormResponse([
            'form_id' => $this->form->id,
            'masjid_id' => $this->form->masjid_id,
            'data' => ['fullName' => 'Walk-up', 'attendees' => array_fill(0, $attendees, ['attendeeName' => 'Guest'])],
            'respondent_name' => 'Walk-up',
            'entry_count' => $attendees,
            'amount_due' => 15 * $attendees,
            'status' => 'new',
            'submitted_at' => now(),
        ]);
        $row->forceFill(['amount_due_minor' => 1500 * $attendees, 'currency' => 'usd'])->save();

        $this->assertTrue($row->settleCash($code));

        return $row->fresh();
    }

    /** Name the form's event day (settings.payment.eventDate), or take it away with null. */
    private function setEventDay(?string $day): void
    {
        $settings = $this->form->fresh()->settings;
        $settings['payment']['eventDate'] = $day;

        $this->form->update(['settings' => $settings]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function makeMasjid(string $timezone): Masjid
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
            'timezone' => $timezone,
        ]);
    }

    /** $15 per attendee, staff codes on, the event a week after whenever the suite runs. */
    private function makeForm(Masjid $masjid): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => ['sections' => [
                ['id' => 'contact', 'title' => 'You', 'fields' => [
                    ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                ]],
                ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => 1, 'maxEntries' => 10, 'fields' => [
                    ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ]],
            ]],
            'settings' => [
                'identity' => ['name' => 'fullName'],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['staffCodes' => true, 'eventDate' => now()->addWeek()->toDateString()],
            ],
            'is_active' => true,
        ]);
    }
}
