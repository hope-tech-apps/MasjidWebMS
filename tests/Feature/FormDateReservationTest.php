<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormDateReservation;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\FormOptionSources;
use App\Support\FormReservations;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripePages;
use Tests\Support\MakesRamadanGivingForms;
use Tests\TestCase;

/**
 * Price levels that reserve a date from the form's list (iftar sponsorships; Ramadan
 * giving, 2026-09-25), through the public submit, "Return to payment", the signed
 * webhook and the admin board:
 *
 *  - a reserving level holds its date, and the date is no longer offered or accepted;
 *  - two payers can never hold one date: the check under the form lock refuses a date a
 *    hold still protects, and the unique index refuses one written around the lock;
 *  - an unpaid card hold lapses with its page (plus the grace) and goes to the next payer
 *    who asks, and then its own "Return to payment" is refused, while one nobody took is
 *    renewed; a payment that lands after the date went elsewhere is recorded and shown
 *    as a conflict; the office and a paid row never lapse; a cancelled row gives its
 *    date back;
 *  - each level asks for exactly what it needs (a date, a quantity), and a level that
 *    reserves nothing stores no date;
 *  - holds are per form and per organisation, the board is tenant-bound, and the model's
 *    scope hides another organisation's reservations;
 *  - the save refuses a list nothing reserves from, a level with no price, and a date
 *    question that does not draw on the list; an organisation without the school
 *    calendar can still save one.
 */
class FormDateReservationTest extends TestCase
{
    use MakesRamadanGivingForms;
    use RefreshDatabase;

    private const D1 = '2027-02-10';

    private const D2 = '2027-02-11';

    private const D3 = '2027-02-12';

    private const PAST = '2027-01-30';

    private const CONNECT_SECRET = 'whsec_ramadan_connect';

    private Masjid $org;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteAndStubStripe();
        Mail::fake();

        // Noon in Charlotte, ten days before the first listed evening.
        Carbon::setTestNow(Carbon::parse('2027-01-31 17:00:00', 'UTC'));

        $this->org = $this->makeOrg();
        $this->form = $this->makeIftarForm($this->org, [self::D1, self::D2, self::D3, self::PAST]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------- holding a date

    #[Test]
    public function a_reserving_level_holds_its_date_and_the_date_is_no_longer_offered_or_accepted(): void
    {
        $this->submitTo($this->form, ['sponsorship' => 'quarter', 'iftar_date' => self::D1])->assertOk()
            ->assertJsonPath('data.total_minor', 45000);

        $row = FormResponse::sole();
        $hold = FormDateReservation::withoutMasjidScope()->sole();
        $this->assertSame($row->id, $hold->form_response_id);
        $this->assertSame($this->org->id, $hold->masjid_id);
        $this->assertSame(self::D1, $hold->date());
        $this->assertTrue($hold->isHolding());
        $this->assertSame(now()->addMinutes(46)->toIso8601String(), $hold->held_until->toIso8601String(), 'page life + slack + grace');
        $this->assertSame('Quarter Iftar', $row->price_label);

        $offered = $this->offeredDates();
        $this->assertSame([self::D2, self::D3], $offered, 'the held date and the past date are not offered');

        $this->submitTo($this->form, ['sponsorship' => 'full', 'iftar_date' => self::D1])
            ->assertStatus(422)
            ->assertJsonPath('data.iftar_date.0', FormReservations::NO_LONGER_OPEN);

        $this->assertSame(1, FormResponse::count());
        $this->assertCount(1, FakeStripePages::$created);
    }

    #[Test]
    public function a_hold_written_around_the_lock_meets_the_unique_index_and_nothing_is_written(): void
    {
        // Another payer's registration, already committed, without a hold yet.
        $rival = $this->officeRow();

        // Between this submit's check under the lock and its own insert, the rival's hold
        // for the same evening lands: the one thing the lock cannot see. On the suite's one
        // connection the rival's insert shares this transaction and rolls back with it;
        // what is pinned is the answer and that nothing of this submission survives.
        FormResponse::creating(function () use ($rival) {
            static $done = false;

            if (! $done) {
                $done = true;
                FormReservations::hold($rival, self::D1, null);
            }
        });

        $this->submitTo($this->form, ['sponsorship' => 'half', 'iftar_date' => self::D1])
            ->assertStatus(422)
            ->assertJsonPath('data.iftar_date.0', FormReservations::TAKEN);

        $this->assertSame([$rival->id], FormResponse::pluck('id')->all());
        $this->assertSame([], FakeStripePages::$created, 'nobody is sent to pay for a date that is not theirs');
    }

    #[Test]
    public function the_check_under_the_lock_refuses_a_date_a_hold_still_protects(): void
    {
        $card = $this->holdFor($this->cardRow(), self::D1, now()->addMinutes(10));
        $this->assertFalse(FormReservations::claim($this->form, self::D1), 'an unpaid card page still open');

        $card->forceFill(['held_until' => now()->subMinute()])->save();
        $card->response->forceFill(['payment_status' => FormResponse::PAYMENT_PAID])->save();
        $this->assertFalse(FormReservations::claim($this->form, self::D1), 'paid: the payment is the reservation');

        $office = $this->holdFor($this->officeRow(), self::D2, null);
        Carbon::setTestNow(now()->addDays(3));
        $this->assertFalse(FormReservations::claim($this->form, self::D2), 'the office never lapses');

        $this->assertTrue($card->fresh()->isHolding());
        $this->assertTrue($office->fresh()->isHolding());
        $this->assertTrue(FormReservations::claim($this->form, self::D3), 'nobody holds it');
    }

    #[Test]
    public function two_forms_and_two_organisations_each_hold_the_same_evening(): void
    {
        $otherOrg = $this->makeOrg('acct_test_other_org');
        $otherForm = $this->makeIftarForm($otherOrg, [self::D1]);
        $sameOrgForm = $this->makeIftarForm($this->org, [self::D1]);

        $this->submitTo($this->form, ['sponsorship' => 'full', 'iftar_date' => self::D1])->assertOk();
        $this->submitTo($otherForm, ['sponsorship' => 'full', 'iftar_date' => self::D1])->assertOk();
        $this->submitTo($sameOrgForm, ['sponsorship' => 'full', 'iftar_date' => self::D1])->assertOk();

        $this->assertSame(3, FormDateReservation::withoutMasjidScope()->whereNotNull('holding_on')->count());

        // And a form cannot be reached through another organisation's header.
        $this->submitTo($this->form, ['sponsorship' => 'full', 'iftar_date' => self::D2], [], $otherOrg->id)->assertNotFound();
    }

    // --------------------------------------------------- when a hold is released

    #[Test]
    public function a_lapsed_card_hold_goes_to_the_next_payer_and_its_own_return_to_payment_is_refused(): void
    {
        $this->submitTo($this->form, ['sponsorship' => 'quarter', 'iftar_date' => self::D1])->assertOk();
        $first = FormResponse::sole();

        // The page expires unpaid, and the grace runs out.
        FakeStripePages::$pages['cs_ramadan_1'] = 'expired';
        Carbon::setTestNow(now()->addMinutes(47));

        $this->assertContains(self::D1, $this->offeredDates(), 'a lapsed hold is offered again');

        $this->submitTo($this->form, ['sponsorship' => 'full', 'iftar_date' => self::D1], ['client_submission_key' => (string) Str::uuid()])->assertOk();
        $second = FormResponse::whereKeyNot($first->id)->sole();

        $released = FormReservations::of($first);
        $this->assertFalse($released->isHolding());
        $this->assertSame(FormDateReservation::RELEASED_LAPSED, $released->release_reason);
        $this->assertSame(self::D1, $released->date(), 'what was asked for is kept');
        $this->assertTrue(FormReservations::of($second)->isHolding());

        $this->reopenPayment($first)
            ->assertStatus(422)
            ->assertJsonPath('message', FormReservations::LOST);

        // And its status read no longer offers "Return to payment".
        $this->getJson("/api/v1/form-responses/{$first->uuid}", ['masjid-id' => (string) $this->org->id])
            ->assertOk()
            ->assertJsonPath('data.can_pay', false);

        $this->assertCount(2, FakeStripePages::$created, 'no page for a date that went to someone else');
    }

    #[Test]
    public function return_to_payment_renews_a_lapsed_hold_nobody_took(): void
    {
        $this->submitTo($this->form, ['sponsorship' => 'quarter', 'iftar_date' => self::D1])->assertOk();
        $row = FormResponse::sole();

        FakeStripePages::$pages['cs_ramadan_1'] = 'expired';
        Carbon::setTestNow(now()->addMinutes(50));

        $this->reopenPayment($row)->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_ramadan_2');

        $hold = FormReservations::of($row);
        $this->assertTrue($hold->isHolding());
        $this->assertSame(now()->addMinutes(46)->toIso8601String(), $hold->held_until->toIso8601String());
        $this->assertNotContains(self::D1, $this->offeredDates());
    }

    #[Test]
    public function a_payment_that_lands_after_its_date_went_elsewhere_is_recorded_warned_and_shown_as_a_conflict(): void
    {
        config(['services.stripe.connect_webhook_secret' => self::CONNECT_SECRET, 'services.stripe.webhook_secret' => 'whsec_ramadan_platform']);
        Log::spy();

        $this->submitTo($this->form, ['sponsorship' => 'quarter', 'iftar_date' => self::D1])->assertOk();
        $late = FormResponse::sole();

        Carbon::setTestNow(now()->addMinutes(47));
        $this->submitTo($this->form, ['sponsorship' => 'full', 'iftar_date' => self::D1])->assertOk();
        $this->assertFalse(FormReservations::of($late)->isHolding());

        $this->postWebhook($this->completed($late))->assertOk();

        $this->assertTrue($late->fresh()->isPaid(), 'money is never refused');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'after its reserved date went to another payer')
            && ($context['form_response_id'] ?? null) === $late->id)->once();

        Sanctum::actingAs($this->makeAdminFor($this->org));
        $board = $this->getJson($this->boardUrl($this->org, $this->form))->assertOk()->json('data');

        $this->assertCount(1, $board['conflicts']);
        $this->assertSame($late->id, $board['conflicts'][0]['response_id']);
        $this->assertSame(self::D1, $board['conflicts'][0]['date']);
    }

    #[Test]
    public function a_cancelled_registration_gives_its_date_back(): void
    {
        $office = $this->makeIftarForm($this->org, [self::D1], office: true);
        $this->submitTo($office, ['sponsorship' => 'half', 'iftar_date' => self::D1], ['pay_with' => 'office'])->assertOk();
        $first = FormResponse::sole();
        $this->assertNull(FormReservations::of($first)->held_until, 'the office never lapses');

        Sanctum::actingAs($this->makeAdminFor($this->org));
        $this->putJson("/api/admin/masjids/{$this->org->id}/forms/{$office->id}/responses/{$first->id}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame('cancelled', collect($this->getJson($this->boardUrl($this->org, $office))->json('data.dates'))->firstWhere('date', self::D1)['state']);

        $this->submitTo($office, ['sponsorship' => 'full', 'iftar_date' => self::D1])->assertOk();

        $this->assertSame(FormDateReservation::RELEASED_CANCELLED, FormReservations::of($first)->release_reason);
    }

    #[Test]
    public function an_office_registration_holds_its_date_until_it_is_settled_or_cancelled(): void
    {
        $office = $this->makeIftarForm($this->org, [self::D1], office: true);
        $this->submitTo($office, ['sponsorship' => 'full', 'iftar_date' => self::D1], ['pay_with' => 'office'])->assertOk();

        Carbon::setTestNow(now()->addDays(3));

        $this->submitTo($office, ['sponsorship' => 'full', 'iftar_date' => self::D1])
            ->assertStatus(422)
            ->assertJsonPath('data.iftar_date.0', FormReservations::NONE_OPEN);
    }

    // ------------------------------------------------- what each level asks for

    #[Test]
    public function a_level_that_reserves_nothing_stores_no_date_and_holds_nothing(): void
    {
        $this->submitTo($this->form, ['sponsorship' => 'individual', 'people' => '3', 'iftar_date' => self::D1])->assertOk()
            ->assertJsonPath('data.total_minor', 5400);

        $row = FormResponse::sole();
        $this->assertArrayNotHasKey('iftar_date', $row->data);
        $this->assertSame(0, FormDateReservation::withoutMasjidScope()->count());
        $this->assertSame([1800, 3], [$row->unit_price_minor, $row->price_quantity]);
    }

    #[Test]
    public function each_level_asks_for_what_it_needs_and_nothing_is_written_without_it(): void
    {
        $this->submitTo($this->form, ['sponsorship' => 'quarter'])
            ->assertStatus(422)
            ->assertJsonPath('data.iftar_date.0', 'Choose a date for Quarter Iftar.');

        $this->submitTo($this->form, ['sponsorship' => 'individual'])
            ->assertStatus(422)
            ->assertJsonPath('data.people.0', 'Enter how many for Individual Iftar.');

        $this->submitTo($this->form, ['sponsorship' => 'full', 'iftar_date' => self::PAST])
            ->assertStatus(422)
            ->assertJsonPath('data.iftar_date.0', FormReservations::NO_LONGER_OPEN);

        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function with_no_dates_listed_a_reserving_level_is_refused_and_the_others_still_work(): void
    {
        $empty = $this->makeIftarForm($this->org, []);

        $this->submitTo($empty, ['sponsorship' => 'quarter', 'iftar_date' => self::D1])
            ->assertStatus(422)
            ->assertJsonPath('data.iftar_date.0', FormReservations::NONE_OPEN);

        $this->submitTo($empty, ['sponsorship' => 'individual', 'people' => 2])->assertOk()->assertJsonPath('data.total_minor', 3600);
    }

    // ----------------------------------------------------- admin and tenancy

    #[Test]
    public function the_board_shows_each_date_and_who_holds_it(): void
    {
        $this->submitTo($this->form, ['sponsorship' => 'quarter', 'iftar_date' => self::D1])->assertOk();
        $row = FormResponse::sole();

        Sanctum::actingAs($this->makeAdminFor($this->org));
        $data = $this->getJson($this->boardUrl($this->org, $this->form))->assertOk()->json('data');

        $this->assertTrue($data['enabled']);
        $byDate = collect($data['dates'])->keyBy('date');
        $this->assertSame([self::PAST, self::D1, self::D2, self::D3], $byDate->keys()->all());
        $this->assertSame('held', $byDate[self::D1]['state']);
        $this->assertSame($row->id, $byDate[self::D1]['reservation']['response_id']);
        $this->assertSame('Jane Giver', $byDate[self::D1]['reservation']['respondent_name']);
        $this->assertSame('Quarter Iftar', $byDate[self::D1]['reservation']['price_label']);
        $this->assertSame('open', $byDate[self::D2]['state']);
        $this->assertTrue($byDate[self::PAST]['past']);

        $this->getJson("/api/admin/masjids/{$this->org->id}/forms/{$this->form->id}/responses/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.reservation.date', self::D1)
            ->assertJsonPath('data.reservation.state', 'held');

        $row->markPaid('pi_iftar_1');
        $this->assertSame('reserved', collect($this->getJson($this->boardUrl($this->org, $this->form))->json('data.dates'))->firstWhere('date', self::D1)['state']);

        // The list tells the screen there is a board; a form with no date list shows none.
        $this->getJson("/api/admin/masjids/{$this->org->id}/forms/{$this->form->id}/responses")->assertOk()->assertJsonPath('meta.reservations', true);

        $plain = $this->makeZakatForm($this->org);
        $this->getJson($this->boardUrl($this->org, $plain))->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson("/api/admin/masjids/{$this->org->id}/forms/{$plain->id}/responses")->assertOk()->assertJsonPath('meta.reservations', false);
    }

    #[Test]
    public function another_organisations_admin_never_reaches_the_board_or_the_rows(): void
    {
        $this->submitTo($this->form, ['sponsorship' => 'quarter', 'iftar_date' => self::D1])->assertOk();

        $otherOrg = $this->makeOrg('acct_test_other_org');
        Sanctum::actingAs($this->makeAdminFor($otherOrg));

        $this->getJson($this->boardUrl($this->org, $this->form))->assertForbidden();
        $this->getJson("/api/admin/masjids/{$otherOrg->id}/forms/{$this->form->id}/responses/reservations")->assertNotFound();

        // The model's own scope, bound to the other organisation, sees none of it.
        app(TenantContext::class)->set($otherOrg->id);
        $this->assertSame(0, FormDateReservation::query()->count());
        app(TenantContext::class)->set($this->org->id);
        $this->assertSame(1, FormDateReservation::query()->count());
        app(TenantContext::class)->forgetTenant();
    }

    #[Test]
    public function the_reservation_columns_have_their_types_and_the_holding_index_is_unique(): void
    {
        $this->assertSame('date', Schema::getColumnType('form_date_reservations', 'reserved_on'));
        $this->assertSame('date', Schema::getColumnType('form_date_reservations', 'holding_on'));
        $this->assertSame('datetime', Schema::getColumnType('form_date_reservations', 'held_until'));
        $this->assertSame('datetime', Schema::getColumnType('form_date_reservations', 'released_at'));
        $this->assertSame('varchar', Schema::getColumnType('form_date_reservations', 'release_reason'));

        $index = collect(Schema::getIndexes('form_date_reservations'))->firstWhere('name', 'form_date_resv_form_holding_unique');
        $this->assertNotNull($index);
        $this->assertTrue($index['unique']);
        $this->assertSame(['form_id', 'holding_on'], $index['columns']);
        $this->assertLessThanOrEqual(64, strlen('form_date_resv_masjid_form_date_idx'));
    }

    // ----------------------------------------------------------------- the save

    #[Test]
    public function an_organisation_without_the_school_calendar_saves_a_form_that_reserves_dates(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $masjid = $this->makeOrg('acct_test_saving_org');
        $masjid->forceFill(['crm_enabled' => true, 'org_type' => 'masjid', 'capability_overrides' => ['form_editing' => true]])->save();
        $this->actingAsMemberAdmin($masjid);

        $this->postJson("/api/admin/masjids/{$masjid->id}/forms", $this->iftarDocument())->assertCreated();

        $this->assertSame([self::D1, self::D2], Form::where('masjid_id', $masjid->id)->sole()->reservation()['dates']);
    }

    #[Test]
    public function the_save_refuses_a_price_list_or_date_list_that_would_charge_or_reserve_wrongly(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());
        $url = "/api/admin/masjids/{$this->org->id}/forms";
        $count = Form::count();

        $cases = [
            'a choice with no price' => [fn (array $d) => data_set($d, 'settings.fee.byChoice.prices', array_slice($d['settings']['fee']['byChoice']['prices'], 0, 3)), 'settings.fee.byChoice.prices'],
            'a price naming no choice' => [fn (array $d) => data_set($d, 'settings.fee.byChoice.prices.3.value', 'banquet'), 'settings.fee.byChoice.prices.3.value'],
            'a price beside a flat amount' => [fn (array $d) => data_set($d, 'settings.fee.amount', 20), 'settings.fee.byChoice'],
            'a level question that is not required' => [fn (array $d) => data_set($d, 'schema.sections.0.fields.1.required', false), 'settings.fee.byChoice.field'],
            'a date level with no date list' => [function (array $d) {
                unset($d['settings']['reservation']);
                $d['schema']['sections'][0]['fields'][3]['options'] = [['value' => 'x', 'label' => 'X']];
                unset($d['schema']['sections'][0]['fields'][3]['optionsSource']);

                return $d;
            }, 'settings.fee.byChoice.prices.1.reservesDate'],
            'a date question not drawing on the list' => [function (array $d) {
                unset($d['schema']['sections'][0]['fields'][3]['optionsSource']);
                $d['schema']['sections'][0]['fields'][3]['options'] = [['value' => self::D1, 'label' => 'Feb 10']];

                return $d;
            }, 'settings.reservation.field'],
            'a list no level reserves from' => [fn (array $d) => data_set($d, 'settings.fee.byChoice.prices', array_map(fn ($p) => ['value' => $p['value'], 'amount' => $p['amount'], 'perQuantity' => $p['perQuantity'] ?? false], $d['settings']['fee']['byChoice']['prices'])), 'settings.reservation'],
            'a per-unit level with no quantity question' => [function (array $d) {
                unset($d['settings']['fee']['perQuantityOf']);

                return $d;
            }, 'settings.fee.byChoice.prices.0.perQuantity'],
            'a level under 50 cents' => [fn (array $d) => data_set($d, 'settings.fee.byChoice.prices.0.amount', 0.25), 'settings.fee.byChoice.prices.0.amount'],
        ];

        foreach ($cases as $why => [$edit, $key]) {
            $this->postJson($url, $edit($this->iftarDocument()))
                ->assertStatus(422)
                ->assertJsonValidationErrors([$key], 'data');
        }

        // One date per registration: the list never fills a choose-any question.
        $many = $this->iftarDocument();
        $many['schema']['sections'][0]['fields'][3]['type'] = 'checkboxGroup';
        $this->postJson($url, $many)->assertStatus(422)->assertJsonValidationErrors(['schema'], 'data');

        $this->assertSame($count, Form::count());
    }

    #[Test]
    public function the_builder_is_not_offered_the_date_list_as_a_source(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $sources = $this->getJson("/api/admin/masjids/{$this->org->id}/forms/field-types")->assertOk()->json('options_sources');

        $this->assertSame(['school_meeting_days'], array_column($sources, 'key'));

        // Yet a stored schema may name it: a form arrives with it through form:import.
        $this->assertArrayHasKey(FormOptionSources::RESERVABLE_DATES, FormOptionSources::SOURCES);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<int,string> the dates the public page offers for the date question */
    private function offeredDates(): array
    {
        $fields = collect($this->publicFormPayload($this->form)['schema']['sections'][0]['fields']);

        return array_column($fields->firstWhere('name', 'iftar_date')['options'], 'value');
    }

    private function boardUrl(Masjid $org, Form $form): string
    {
        return "/api/admin/masjids/{$org->id}/forms/{$form->id}/responses/reservations";
    }

    /** An unpaid card registration on the form, with no hold. */
    private function cardRow(): FormResponse
    {
        return $this->row(FormResponse::METHOD_ONLINE);
    }

    /** An unpaid office registration on the form, with no hold. */
    private function officeRow(): FormResponse
    {
        return $this->row(FormResponse::METHOD_OFFICE);
    }

    private function row(string $method): FormResponse
    {
        $row = new FormResponse([
            'form_id' => $this->form->id,
            'masjid_id' => $this->org->id,
            'data' => ['fullName' => 'Rival Sponsor', 'sponsorship' => 'full'],
            'respondent_name' => 'Rival Sponsor',
            'entry_count' => 1,
            'amount_due' => 1900,
            'status' => 'new',
            'submitted_at' => now(),
        ]);

        $row->forceFill([
            'payment_method' => $method,
            'payment_status' => FormResponse::PAYMENT_UNPAID,
            'currency' => 'usd',
            'amount_due_minor' => 190000,
            'fee_covered_minor' => 0,
            'total_minor' => 190000,
        ])->save();

        return $row;
    }

    private function holdFor(FormResponse $row, string $date, ?Carbon $until): FormDateReservation
    {
        return FormReservations::hold($row, $date, $until);
    }

    private function actingAsMemberAdmin(Masjid $masjid): void
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        Sanctum::actingAs($user->fresh());
    }

    /** What the builder or form:import sends for an iftar form reserving from two dates. */
    private function iftarDocument(): array
    {
        return [
            'slug' => 'iftar-' . uniqid(),
            'name' => 'Iftar Sponsorship',
            'is_active' => false,
            'schema' => ['sections' => [['id' => 'sponsor', 'title' => 'Sponsor', 'fields' => [
                ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'sponsorship', 'label' => 'Sponsorship', 'type' => 'radio', 'required' => true, 'options' => [
                    ['value' => 'individual', 'label' => 'Individual Iftar'],
                    ['value' => 'quarter', 'label' => 'Quarter Iftar'],
                    ['value' => 'half', 'label' => 'Half Iftar'],
                    ['value' => 'full', 'label' => 'Full Iftar'],
                ]],
                ['name' => 'people', 'label' => 'Number of people', 'type' => 'number', 'min' => 1, 'max' => 50],
                ['name' => 'iftar_date', 'label' => 'Date', 'type' => 'select', 'optionsSource' => 'reservable_dates'],
            ]]]],
            'settings' => [
                'fee' => [
                    'currency' => 'USD',
                    'perQuantityOf' => 'people',
                    'byChoice' => ['field' => 'sponsorship', 'prices' => [
                        ['value' => 'individual', 'amount' => 18, 'perQuantity' => true],
                        ['value' => 'quarter', 'amount' => 450, 'reservesDate' => true],
                        ['value' => 'half', 'amount' => 950, 'reservesDate' => true],
                        ['value' => 'full', 'amount' => 1900, 'reservesDate' => true],
                    ]],
                ],
                'reservation' => ['field' => 'iftar_date', 'dates' => [self::D1, self::D2]],
                'payment' => ['online' => true],
            ],
        ];
    }

    /** checkout.session.completed for the row's page, as FormPaymentWebhookTest builds it. */
    private function completed(FormResponse $row): array
    {
        return [
            'id' => 'evt_' . Str::random(24),
            'type' => 'checkout.session.completed',
            'account' => 'acct_test_ramadan_org',
            'data' => ['object' => [
                'id' => (string) $row->stripe_checkout_session_id,
                'object' => 'checkout.session',
                'mode' => 'payment',
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => (int) $row->total_minor,
                'payment_intent' => 'pi_ramadan_late',
                'client_reference_id' => $row->uuid,
                'metadata' => [
                    'form_response_uuid' => $row->uuid,
                    'masjid_id' => (string) $row->masjid_id,
                    'form_id' => (string) $row->form_id,
                ],
            ]],
        ];
    }

    private function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::CONNECT_SECRET);

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
