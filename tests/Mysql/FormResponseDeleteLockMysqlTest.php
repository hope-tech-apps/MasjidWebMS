<?php

/*
|--------------------------------------------------------------------------
| Deleting a registration, on the engine production runs
|--------------------------------------------------------------------------
|
| FormResponsesController::destroy() locks the FORM row before the registration's own
| row on a form that reserves dates (DECISIONS.md 2026-10-05), the order update() and the
| public submit take them in. The submit holds the form and then locks the registration
| holding the date it asks for (FormReservations::claim()); a delete that held the
| registration first and then wrote the form's counter would wait on the submit while
| the submit waited on it.
|
| tests/Feature/FormResponseNeverPaidDeleteTest.php pins the two statements and their
| order on SQLite, but SQLite prints no lock clause at all. Here each must be the
| locking read it claims to be: `FOR UPDATE`, by primary key, the form first.
|
| What this file cannot show: a delete and a submit asking for that registration's
| date, started together, both finishing. Every test here runs inside RefreshDatabase's
| transaction, where a second connection sees none of the fixtures
| (tests/MysqlLocks/RosterMoveLocksTest.php says why). That is shown by hand, with a
| second session, on staging.
|
| Runs only in CI's migrations-mysql job, as `pest --group=mysql`.
| NOT RUN where it was written: there is no MySQL server there.
*/

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * A cancelled registration that chose the office and never paid, on a form with the
 * given settings: the one kind a delete removes without asking Stripe anything.
 *
 * @param  array<string,mixed>  $settings
 */
function deleteLockMysqlRow(array $settings = []): FormResponse
{
    $org = Masjid::create([
        'name' => 'Delete Lock MySQL Org ' . uniqid(),
        'email' => 'delete-lock-' . uniqid() . '@example.test',
        'phone' => '+1555' . random_int(1000000, 9999999),
        'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
        'latitude' => 0.0, 'longitude' => 0.0,
    ]);

    $form = Form::create([
        'masjid_id' => $org->id,
        'slug' => 'dinner-' . uniqid(),
        'name' => 'Community Dinner',
        'schema' => ['sections' => [['id' => 'contact', 'title' => 'You', 'fields' => [
            ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text'],
            ['name' => 'visit_date', 'label' => 'Date', 'type' => 'select', 'optionsSource' => 'reservable_dates'],
        ]]]],
        'settings' => [
            'identity' => ['name' => 'fullName'],
            'fee' => ['amount' => 15, 'currency' => 'USD'],
            'payment' => ['online' => true, 'officePayment' => true, 'officeInstructions' => 'Pay at the front desk.'],
        ] + $settings,
        'is_active' => true,
    ]);

    $row = new FormResponse([
        'form_id' => $form->id,
        'masjid_id' => $org->id,
        'data' => ['fullName' => 'Test Registrant'],
        'respondent_name' => 'Test Registrant',
        'entry_count' => 1,
        'amount_due' => 15,
        'status' => 'cancelled',
        'submitted_at' => '2026-10-05 12:00:00',
    ]);

    $row->forceFill([
        'payment_method' => FormResponse::METHOD_OFFICE,
        'payment_status' => FormResponse::PAYMENT_UNPAID,
        'currency' => 'usd',
        'amount_due_minor' => 1500,
        'fee_covered_minor' => 0,
        'total_minor' => 1500,
    ])->save();

    return $row;
}

/**
 * The tables the delete reads by primary key FOR UPDATE, in the order it reads them.
 *
 * @return array<int,string>
 */
function deleteLockMysqlLocks(FormResponse $row, object $test): array
{
    Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]));

    $seen = [];
    DB::listen(function ($query) use (&$seen): void {
        if (preg_match('/^select \* from `(forms|form_responses)` where `\1`\.`id` = \?.* for update$/', $query->sql, $table) === 1) {
            $seen[] = $table[1];
        }
    });

    $test->deleteJson("/api/admin/masjids/{$row->masjid_id}/forms/{$row->form_id}/responses/{$row->id}")->assertOk();

    return $seen;
}

it('locks the form row FOR UPDATE, then the registration row, on a form that reserves dates', function () {
    $row = deleteLockMysqlRow(['reservation' => ['field' => 'visit_date', 'dates' => ['2027-02-10', '2027-02-11']]]);

    expect(deleteLockMysqlLocks($row, $this))->toBe(['forms', 'form_responses']);
    expect(FormResponse::find($row->id))->toBeNull();
});

it('locks only the registration row on a form that reserves nothing, as it always did', function () {
    $row = deleteLockMysqlRow();

    expect(deleteLockMysqlLocks($row, $this))->toBe(['form_responses']);
    expect(FormResponse::find($row->id))->toBeNull();
});
